<?php
/**
 * Data-quality gate that runs BEFORE eligibility evaluation.
 *
 * Pipeline (per household, in this exact order):
 *   Resident data -> Data cleaning -> Data validation -> Residency
 *   validation -> Duplicate detection -> [eligibility -> explainable result]
 *
 * The module never deletes or rewrites resident records. Everything it
 * finds wrong goes into a flag with a severity the caller can act on:
 *
 *   ERROR             - critical data is invalid; automatic eligibility must
 *                       NOT proceed for the entity that owns the bad data.
 *   WARNING           - suspicious but not disqualifying; eligibility may
 *                       proceed and the caveat travels with the result.
 *   POSSIBLE DUPLICATE- two records look like the same person; review.
 *
 * Flags are self-resolving upserts keyed on (check_code, entity): they open
 * when a check fires, auto-close once the underlying record is fixed, and
 * re-open if the issue comes back. Every transition is written to the audit
 * trail under the 'validate' category, so there is a complete history while
 * the validation_flags table always reflects the *current* state.
 *
 * The eligibility engine (shared/eligibility.php) is the only consumer that
 * mutates beneficiaries; data_quality.php stays a read-side deep module and
 * never includes eligibility.php, so the two can be required from either
 * direction without a cycle.
 */

require_once __DIR__ . '/functions.php';

const DQ_SEVERITY_ERROR     = 'error';
const DQ_SEVERITY_WARNING   = 'warning';
const DQ_SEVERITY_DUPLICATE = 'possible_duplicate';

/** Civil statuses that are impossible for anyone under the legal marriage age. */
const DQ_NON_SINGLE_STATUSES = ['Married', 'Widowed', 'Separated', 'Annulled'];

/** Human label for the three flag severities - shared by admin/data-quality.php. */
function dq_severity_label(string $severity): string {
    return match ($severity) {
        DQ_SEVERITY_ERROR     => 'Error',
        DQ_SEVERITY_WARNING   => 'Warning',
        DQ_SEVERITY_DUPLICATE => 'Possible duplicate',
        default               => $severity,
    };
}

/** "First Last" (or "Member #N" if the name is unusable) for messages. */
function dq_member_name(array $m): string {
    $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
    return $name !== '' ? $name : 'Member #' . (int) ($m['member_id'] ?? 0);
}

/**
 * Full assessment of one household: cleaning, validation, residency checks
 * and duplicate detection, in that order.
 *
 * @param bool $persist   When true, the two writes this pipeline is allowed
 *                        are executed: (1) households.no_of_members is
 *                        recomputed when it drifted (a counter refresh, never
 *                        a resident edit), and (2) validation flags are
 *                        upserted/auto-cleared with audit entries. Pass false
 *                        for pure read-side explanations (resident dashboard)
 *                        so a GET never writes.
 *
 * @return array{
 *   household_id:int,
 *   household:array,          cleaned household row (evaluation context)
 *   members:list<array>,      cleaned, non-archived member rows
 *   flags:list<array<string,string|int|null>>,
 *   blocked_member_ids:list<int>,   members owning an ERROR flag
 *   blocked:bool                    household-level ERROR present (blocks everyone)
 * }
 */
function assess_household_data(PDO $pdo, int $householdId, bool $persist = true, ?int $actorUserId = null): array {
    $empty = [
        'household_id'          => $householdId,
        'household'             => null,
        'members'               => [],
        'flags'                 => [],
        'blocked_member_ids'    => [],
        'blocked'               => false,
    ];

    $houseStmt = $pdo->prepare('SELECT * FROM households WHERE household_id = ?');
    $houseStmt->execute([$householdId]);
    $rawHousehold = $houseStmt->fetch();
    if (!$rawHousehold) {
        return $empty;
    }

    $memStmt = $pdo->prepare('SELECT * FROM household_members WHERE household_id = ?');
    $memStmt->execute([$householdId]);
    $rawMembers = $memStmt->fetchAll();

    $linksStmt = $pdo->prepare('SELECT resident_id, user_id, household_id, household_member_id, census_completed FROM residents WHERE household_id = ?');
    $linksStmt->execute([$householdId]);
    $residentLinks = $linksStmt->fetchAll();

    $flags = [];
    $push = static function (array &$into, string $severity, string $code, string $message, string $scope, int $householdId, ?int $memberId = null): void {
        $into[] = [
            'severity'     => $severity,
            'check_code'   => $code,
            'message'      => $message,
            'scope'        => $scope,
            'household_id' => $householdId,
            'member_id'    => $memberId,
        ];
    };

    // ---- Data cleaning (in-memory; the only allowed DB write is the
    //      no_of_members counter refresh, applied later under $persist).
    $activeRaw = array_values(array_filter($rawMembers, static fn($m) => !(bool) $m['is_archived']));
    $archivedRaw = array_values(array_filter($rawMembers, static fn($m) => (bool) $m['is_archived']));

    $liveCount = count($activeRaw);
    $storedCount = $rawHousehold['no_of_members'] !== null ? (int) $rawHousehold['no_of_members'] : null;

    $household = dq_clean_household_fields($rawHousehold, $liveCount);
    $members = [];
    foreach ($activeRaw as $m) {
        $clean = dq_clean_member_fields($m);
        $members[$clean['member_id']] = $clean;
    }

    // ---- 1) Data validation ------------------------------------------------
    // House-level address/income/count checks.
    if ($household['street'] === '') {
        $push($flags, DQ_SEVERITY_ERROR, 'H_NO_STREET', 'Household #' . $householdId . ': missing street address.', 'household', $householdId);
    }
    if ($household['city'] === '') {
        $push($flags, DQ_SEVERITY_ERROR, 'H_NO_CITY', 'Household #' . $householdId . ': missing city.', 'household', $householdId);
    }
    if ($household['house_number'] === '' && $household['street'] !== '' && $household['city'] !== '') {
        $push($flags, DQ_SEVERITY_WARNING, 'H_ADDRESS_INCOMPLETE', 'Household #' . $householdId . ': no house/building/unit number on file.', 'household', $householdId);
    }
    if ($household['barangay'] !== '' && mb_strtolower($household['barangay']) !== mb_strtolower('Barangay 420')) {
        $push($flags, DQ_SEVERITY_WARNING, 'H_WRONG_BARANGAY', 'Household #' . $householdId . ': barangay on file is "' . $household['barangay'] . '"; this system records Barangay 420 residents.', 'household', $householdId);
    }
    if ($household['monthly_income_raw'] !== null && !is_numeric($household['monthly_income_raw'])) {
        $push($flags, DQ_SEVERITY_ERROR, 'H_INCOME_INVALID', 'Household #' . $householdId . ': monthly income "' . $household['monthly_income_raw'] . '" is not a number.', 'household', $householdId);
    }
    if ($household['monthly_income'] !== null && $household['monthly_income'] < 0) {
        $push($flags, DQ_SEVERITY_ERROR, 'H_INCOME_NEGATIVE', 'Household #' . $householdId . ': monthly income cannot be negative (₱' . number_format($household['monthly_income'], 2) . ').', 'household', $householdId);
    }
    if ($storedCount !== $liveCount) {
        $push($flags, DQ_SEVERITY_WARNING, 'H_MEMBER_COUNT_MISMATCH', 'Household #' . $householdId . ': member count on file (' . ($storedCount ?? 'unknown') . ') does not match the ' . $liveCount . ' live record(s).', 'household', $householdId);
    }

    $heads = array_values(array_filter($members, static fn($m) => $m['is_household_head']));
    if (count($heads) === 0) {
        $push($flags, DQ_SEVERITY_WARNING, 'H_NO_HEAD', 'Household #' . $householdId . ': no head of household on file.', 'household', $householdId);
    } elseif (count($heads) > 1) {
        $names = implode(', ', array_map('dq_member_name', $heads));
        $push($flags, DQ_SEVERITY_ERROR, 'H_MULTIPLE_HEADS', 'Household #' . $householdId . ': ' . count($heads) . ' heads of household on file (' . $names . ').', 'household', $householdId);
    }
    if (!empty($residentLinks) && !empty(array_filter($residentLinks, static fn($r) => !(bool) $r['census_completed']))) {
        $push($flags, DQ_SEVERITY_WARNING, 'H_CENSUS_INCOMPLETE', 'Household #' . $householdId . ': census is not marked complete for one or more linked resident accounts.', 'household', $householdId);
    }

    $today = new DateTime('today');
    foreach ($members as $m) {
        $mid = (int) $m['member_id'];
        $name = dq_member_name($m);

        if ($m['last_name'] === '' || $m['first_name'] === '') {
            $push($flags, DQ_SEVERITY_ERROR, 'M_NO_NAME', $name . ': missing last or first name.', 'member', $householdId, $mid);
        }

        $rawBd = (string) ($m['_raw']['birthdate'] ?? '');
        $bdInvalid = $rawBd !== '' && $m['birthdate'] === null;
        if ($bdInvalid) {
            $push($flags, DQ_SEVERITY_ERROR, 'M_BAD_BIRTHDATE', $name . ': birthdate "' . $rawBd . '" is not a valid date.', 'member', $householdId, $mid);
        } elseif ($rawBd === '') {
            $push($flags, DQ_SEVERITY_WARNING, 'M_NO_BIRTHDATE', $name . ': no date of birth on file - age-based criteria cannot be judged.', 'member', $householdId, $mid);
        }
        if ($m['birthdate'] !== null) {
            $bd = new DateTime($m['birthdate']);
            if ($bd > $today) {
                $push($flags, DQ_SEVERITY_ERROR, 'M_FUTURE_BIRTHDATE', $name . ': birthdate ' . $m['birthdate'] . ' is in the future.', 'member', $householdId, $mid);
            } elseif ($m['age'] !== null && $m['age'] > 120) {
                $push($flags, DQ_SEVERITY_ERROR, 'M_IMPLAUSIBLE_AGE', $name . ': birthdate ' . $m['birthdate'] . ' implies an age of ' . $m['age'] . '.', 'member', $householdId, $mid);
            }
        }

        $age = $m['age'];
        if ($age !== null && $age < 18 && $m['civil_status'] !== null && in_array($m['civil_status'], DQ_NON_SINGLE_STATUSES, true)) {
            $push($flags, DQ_SEVERITY_ERROR, 'M_ILLEGAL_MARRIAGE', $name . ': civil status "' . $m['civil_status'] . '" is not legal under 18 (age ' . $age . ').', 'member', $householdId, $mid);
        }

        if ($m['is_employed'] && $m['is_unemployed']) {
            $push($flags, DQ_SEVERITY_ERROR, 'M_EMPLOYED_UNEMPLOYED', $name . ': marked both Employed and Unemployed.', 'member', $householdId, $mid);
        }
        if ($m['occupation'] !== null && $m['is_unemployed']) {
            $push($flags, DQ_SEVERITY_ERROR, 'M_OCCUPATION_UNEMPLOYED', $name . ': occupation "' . $m['occupation'] . '" is on file, so they cannot be marked Unemployed.', 'member', $householdId, $mid);
        }
        if ($m['is_employed'] && $m['occupation'] === null) {
            $push($flags, DQ_SEVERITY_ERROR, 'M_EMPLOYED_NO_OCCUPATION', $name . ': marked Employed but no occupation is on file.', 'member', $householdId, $mid);
        }

        if ($m['is_solo_parent'] && $m['civil_status'] === 'Married') {
            $push($flags, DQ_SEVERITY_WARNING, 'M_SOLOPARENT_MARRIED', $name . ': marked as a solo parent but civil status is Married.', 'member', $householdId, $mid);
        }
        if ($m['is_solo_parent'] && $age !== null && $age < 18) {
            $push($flags, DQ_SEVERITY_WARNING, 'M_SOLOPARENT_MINOR', $name . ': marked as a solo parent but only ' . $age . ' years old.', 'member', $householdId, $mid);
        }
        if ($m['is_ofw'] && $age !== null && $age < 18) {
            $push($flags, DQ_SEVERITY_WARNING, 'M_OFW_MINOR', $name . ': marked as an OFW but only ' . $age . ' years old.', 'member', $householdId, $mid);
        }
        if ($m['is_employed'] && $age !== null && $age < 15) {
            $push($flags, DQ_SEVERITY_WARNING, 'M_EMPLOYED_MINOR', $name . ': marked Employed but only ' . $age . ' years old.', 'member', $householdId, $mid);
        }
        if ($m['is_student'] && $age !== null && $age >= 60) {
            $push($flags, DQ_SEVERITY_WARNING, 'M_STUDENT_SENIOR', $name . ': marked Student at age ' . $age . '.', 'member', $householdId, $mid);
        }

        $missing = [];
        if ($m['sex'] === null) { $missing[] = 'sex'; }
        if ($m['civil_status'] === null) { $missing[] = 'civil status'; }
        if ($m['citizenship'] === '') { $missing[] = 'citizenship'; }
        if ($m['place_of_birth'] === '') { $missing[] = 'place of birth'; }
        if ($m['occupation'] === null && !$m['is_unemployed']) { $missing[] = 'occupation'; }
        if (!empty($missing)) {
            $push($flags, DQ_SEVERITY_WARNING, 'M_MISSING_FIELDS', $name . ': missing ' . implode(', ', $missing) . '.', 'member', $householdId, $mid);
        }
    }

    // ---- 2) Archived-member cautions (never evaluated, but worth flagging).
    $archivedIds = array_column($archivedRaw, 'member_id');
    if (!empty($archivedIds)) {
        $benefitStmt = $pdo->prepare(
            "SELECT beneficiary_id, member_id, claim_status FROM beneficiaries WHERE member_id IN (" . implode(',', array_fill(0, count($archivedIds), '?')) . ")"
        );
        $benefitStmt->execute($archivedIds);
        foreach ($benefitStmt->fetchAll() as $b) {
            if (in_array($b['claim_status'], ['qualified', 'pending', 'approved', 'existing'], true)) {
                $am = null;
                foreach ($archivedRaw as $amRow) {
                    if ((int) $amRow['member_id'] === (int) $b['member_id']) { $am = $amRow; break; }
                }
                $push($flags, DQ_SEVERITY_WARNING, 'M_ARCHIVED_OPEN_BENEFIT', dq_member_name($am ?? []) . ': archived, but still holds a ' . $b['claim_status'] . ' benefit.', 'member', $householdId, (int) $b['member_id']);
            }
        }
    }

    // ---- 3) Residency validation -------------------------------------------
    $memberById = [];
    foreach ($rawMembers as $rm) {
        $memberById[(int) $rm['member_id']] = $rm;
    }
    foreach ($residentLinks as $link) {
        $linkMid = $link['household_member_id'] !== null ? (int) $link['household_member_id'] : null;
        if ($linkMid !== null) {
            $target = $memberById[$linkMid] ?? null;
            if (!$target || (int) $target['household_id'] !== $householdId) {
                $push($flags, DQ_SEVERITY_ERROR, 'R_HOUSEHOLD_MISMATCH', 'A resident account is linked to member #' . $linkMid . ', who is not a member of household #' . $householdId . '.', 'member', $householdId, $linkMid);
            }
        } else {
            $push($flags, DQ_SEVERITY_ERROR, 'R_NO_HOUSEHOLD', 'A resident account (resident #' . (int) $link['resident_id'] . ') is linked to this household but has no household member record.', 'household', $householdId);
        }
    }

    // ---- 4) Duplicate detection --------------------------------------------
    $cands = $pdo->query(
        'SELECT hm.member_id, hm.household_id, hm.last_name, hm.first_name, hm.middle_name, hm.suffix, hm.sex, hm.birthdate,
                h.house_number, h.street, h.city
         FROM household_members hm
         JOIN households h ON h.household_id = hm.household_id
         WHERE hm.is_archived = FALSE'
    )->fetchAll();
    // Same-household: bucket by identity key.
    $buckets = [];
    foreach ($members as $m) {
        $key = dq_identity_key($m);
        $buckets[$key][] = $m;
    }
    foreach ($buckets as $group) {
        if (count($group) < 2) { continue; }
        foreach ($group as $i => $m) {
            if ($i === 0) { continue; }
            $push($flags, DQ_SEVERITY_DUPLICATE, 'D_SAME_HOUSEHOLD_DUP',
                dq_member_name($m) . ': possible duplicate of ' . dq_member_name($group[0]) . ' in the same household (same name, birthdate and sex).',
                'member', $householdId, (int) $m['member_id']);
        }
    }
    // Cross-household: index every live member of OTHER households once by
    // cleaned identity key (O(n) build + O(k) lookups) instead of nesting
    // each assessed member against the whole table in a quadratic scan.
    $cross = [];
    foreach ($cands as $c) {
        if ((int) $c['household_id'] === $householdId) {
            continue;
        }
        $cross[dq_identity_key([
            'last_name'   => normalize_person_name((string) $c['last_name']),
            'first_name'  => normalize_person_name((string) $c['first_name']),
            'middle_name' => normalize_person_name((string) $c['middle_name']),
            'suffix'      => normalize_suffix((string) $c['suffix']),
        ])][] = $c;
    }
    foreach ($members as $m) {
        $mid = (int) $m['member_id'];
        foreach (($cross[dq_identity_key($m)] ?? []) as $c) {
            if ((string) $c['sex'] !== $m['sex']) { continue; }
            if (!dq_dates_equal($c['birthdate'], $m['birthdate'])) { continue; }
            $address = trim(($c['house_number'] ?? '') . ' ' . ($c['street'] ?? '') . ', ' . ($c['city'] ?? ''));
            $push($flags, DQ_SEVERITY_DUPLICATE, 'D_CROSS_HOUSEHOLD_DUP',
                dq_member_name($m) . ': possible duplicate of a record in another household (#' . (int) $c['household_id'] . ', ' . ($address !== '' ? $address : 'no address') . ') with the same name, birthdate and sex.',
                'member', $householdId, $mid);
        }
    }

    // ---- Blocked entities ---------------------------------------------------
    $blockedMemberIds = [];
    $householdBlocked = false;
    foreach ($flags as $f) {
        if ($f['severity'] !== DQ_SEVERITY_ERROR) { continue; }
        if ($f['scope'] === 'household') {
            $householdBlocked = true;
        } elseif ($f['member_id'] !== null) {
            $blockedMemberIds[] = (int) $f['member_id'];
        }
    }
    $blockedMemberIds = array_values(array_unique($blockedMemberIds));

    if ($persist) {
        dq_persist_flags($pdo, $householdId, $flags, $actorUserId);
        if ($storedCount !== $liveCount) {
            $pdo->prepare('UPDATE households SET no_of_members = ? WHERE household_id = ?')->execute([$liveCount, $householdId]);
            log_activity($pdo, $actorUserId,
                'Data cleaning: recomputed household #' . $householdId . ' member count ' . ($storedCount ?? 'NULL') . ' -> ' . $liveCount . '.',
                'validate');
        }
    }

    return [
        'household_id'       => $householdId,
        'household'          => $household,
        'members'            => array_values($members),
        'flags'              => $flags,
        'blocked_member_ids' => $blockedMemberIds,
        'blocked'            => $householdBlocked,
    ];
}

/**
 * Normalized identity key for duplicate detection: lowercased
 * last|first|middle|suffix. Suffix matters (father vs son) - a record with
 * "Jr." and one without are treated as different people.
 */
function dq_identity_key(array $m): string {
    return mb_strtolower(trim(
        ((string) ($m['last_name'] ?? '')) . '|'
        . ((string) ($m['first_name'] ?? '')) . '|'
        . ((string) ($m['middle_name'] ?? '')) . '|'
        . ((string) ($m['suffix'] ?? ''))
    ));
}

/** True when two birthdate values are both NULL or both the same date. */
function dq_dates_equal($a, $b): bool {
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return (string) $a === (string) $b;
}

/** Cleans one households row into the evaluation context. */
function dq_clean_household_fields(array $h, int $liveCount): array {
    $rawIncome = ($h['monthly_income'] ?? null);
    return [
        'household_id'       => (int) $h['household_id'],
        'house_number'       => normalize_label((string) ($h['house_number'] ?? '')),
        'street'             => normalize_label((string) ($h['street'] ?? '')),
        'barangay'           => normalize_label((string) ($h['barangay'] ?? '')),
        'city'               => normalize_label((string) ($h['city'] ?? '')),
        'old_address'        => normalize_label((string) ($h['old_address'] ?? '')),
        'monthly_income'     => ($rawIncome !== null && is_numeric($rawIncome)) ? (float) $rawIncome : null,
        'monthly_income_raw' => $rawIncome !== null ? (string) $rawIncome : null,
        'stored_count'       => $h['no_of_members'] !== null ? (int) $h['no_of_members'] : null,
        'live_count'         => $liveCount,
    ];
}

/** Cleans one household_members row into the evaluation context. */
function dq_clean_member_fields(array $m): array {
    $occupation = normalize_occupation((string) ($m['occupation'] ?? ''));
    $civilRaw = trim((string) ($m['civil_status'] ?? ''));
    $sexRaw = trim((string) ($m['sex'] ?? ''));
    $rawBd = trim((string) ($m['birthdate'] ?? ''));

    $birthdate = null;
    $age = null;
    if ($rawBd !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $rawBd);
        if ($d && $d->format('Y-m-d') === $rawBd) {
            $birthdate = $rawBd;
            $today = new DateTime('today');
            $bd = new DateTime($rawBd);
            if ($bd <= $today) {
                $age = (int) $today->diff($bd)->y;
            }
        }
    }

    return [
        'member_id'          => (int) $m['member_id'],
        'household_id'       => (int) $m['household_id'],
        'last_name'          => normalize_person_name((string) ($m['last_name'] ?? '')),
        'first_name'         => normalize_person_name((string) ($m['first_name'] ?? '')),
        'middle_name'        => normalize_person_name((string) ($m['middle_name'] ?? '')),
        'suffix'             => normalize_suffix((string) ($m['suffix'] ?? '')),
        'place_of_birth'     => normalize_label((string) ($m['place_of_birth'] ?? '')),
        'citizenship'        => normalize_label((string) ($m['citizenship'] ?? '')),
        'occupation'         => $occupation !== '' ? $occupation : null,
        'civil_status'       => $civilRaw !== '' ? normalize_civil_status($civilRaw) : null,
        'sex'                => in_array($sexRaw, ['Male', 'Female'], true) ? $sexRaw : null,
        'birthdate'          => $birthdate,
        'age'                => $age,
        'is_pwd'             => (bool) $m['is_pwd'],
        'is_solo_parent'     => (bool) $m['is_solo_parent'],
        'is_ofw'             => (bool) $m['is_ofw'],
        'is_osy'             => (bool) $m['is_osy'],
        'is_ip'              => (bool) $m['is_ip'],
        'is_unemployed'      => (bool) $m['is_unemployed'],
        'is_employed'        => (bool) $m['is_employed'],
        'is_student'         => (bool) $m['is_student'],
        'is_household_head'  => (bool) $m['is_household_head'],
        'is_archived'        => (bool) $m['is_archived'],
        'linked_user_id'     => $m['linked_user_id'] !== null ? (int) $m['linked_user_id'] : null,
        '_raw'               => $m,
    ];
}

/**
 * Self-resolving upsert of this household's flags. Keys (check_code, entity)
 * guarantee at most one live flag per issue; every open/clear/re-open is
 * written to the audit trail as a 'validate' entry.
 */
function dq_persist_flags(PDO $pdo, int $householdId, array $flags, ?int $actorUserId): void {
    $existingStmt = $pdo->prepare(
        'SELECT flag_id, check_code, severity, message, status, scope, household_id, member_id
         FROM validation_flags WHERE household_id = ?'
    );
    $existingStmt->execute([$householdId]);
    $existingRows = $existingStmt->fetchAll();

    $existingByKey = [];
    foreach ($existingRows as $r) {
        $existingByKey[dq_flag_key($r['check_code'], $r['member_id'])] = $r;
    }

    // One live flag per (check_code, entity). Callers legitimately emit the
    // same key several times in one batch - e.g. one push per open benefit
    // for the same member, or one per cross-household duplicate candidate -
    // and without de-duping here the second plain INSERT would violate
    // uk_validation_check and abort the transaction this runs inside
    // (resident profile save, eligibility sweep). First occurrence wins.
    $dedupedFlags = [];
    foreach ($flags as $f) {
        $flagKey = dq_flag_key($f['check_code'], $f['member_id']);
        if (!isset($dedupedFlags[$flagKey])) {
            $dedupedFlags[$flagKey] = $f;
        }
    }

    $currentKeys = [];
    $insertStmt = $pdo->prepare(
        'INSERT INTO validation_flags (check_code, severity, message, scope, household_id, member_id, status)
         VALUES (?, ?, ?, ?, ?, ?, \'open\')'
    );
    $softUpdateStmt = $pdo->prepare(
        'UPDATE validation_flags SET severity = ?, message = ? WHERE flag_id = ?'
    );

    foreach ($dedupedFlags as $f) {
        $key = dq_flag_key($f['check_code'], $f['member_id']);
        $currentKeys[] = $key;
        $memberId = $f['member_id'] !== null ? (int) $f['member_id'] : null;

        if (!isset($existingByKey[$key])) {
            $insertStmt->execute([$f['check_code'], $f['severity'], $f['message'], $f['scope'], $householdId, $memberId]);
            log_activity($pdo, $actorUserId,
                'Data quality: flagged ' . strtoupper($f['severity']) . ' [' . $f['check_code'] . '] - ' . $f['message'],
                'validate');
            continue;
        }

        $row = $existingByKey[$key];
        if ($row['status'] !== 'open') {
            // An admin manually resolved this flag (status != 'open' with
            // a resolution note). Respect that decision: refresh severity and
            // message so the review page shows the current condition, but do
            // NOT force it back to 'open' or wipe the resolution note.
            $softUpdateStmt->execute([$f['severity'], $f['message'], (int) $row['flag_id']]);
        } elseif ($row['severity'] !== $f['severity'] || $row['message'] !== $f['message']) {
            $softUpdateStmt->execute([$f['severity'], $f['message'], (int) $row['flag_id']]);
        }
    }

    $resolveStmt = $pdo->prepare(
        "UPDATE validation_flags
         SET status = 'resolved', resolved_by = NULL, resolved_at = NOW(),
             resolved_note = 'auto-cleared: condition no longer present'
         WHERE flag_id = ?"
    );
    foreach ($existingRows as $row) {
        $key = dq_flag_key($row['check_code'], $row['member_id']);
        if ($row['status'] !== 'open' || in_array($key, $currentKeys, true)) {
            continue;
        }
        $resolveStmt->execute([(int) $row['flag_id']]);
        log_activity($pdo, $actorUserId,
            'Data quality: auto-cleared [' . $row['check_code'] . '] - ' . $row['message'],
            'validate');
    }
}

/** Canonical (check_code, entity) key for the flag upsert. */
function dq_flag_key(string $checkCode, $memberId): string {
    return $checkCode . ':' . ($memberId !== null ? 'm' . (int) $memberId : 'h');
}