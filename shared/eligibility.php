<?php
/**
 * Eligibility rule engine (FR4): evaluates EVERY member of a household
 * against every active program's criteria, not just the account holder.
 * Real programs qualify individuals - a PWD grandparent or a solo-parent
 * sibling can qualify even if they are not the one who registered online.
 *
 * The engine is fronted by the data-quality gate (shared/data_quality.php):
 *
 *   Resident data -> Data cleaning -> Data validation -> Residency
 *   validation -> Duplicate detection -> rule evaluation -> explainable result
 *
 * A member that owns an ERROR flag is BLOCKED for this run - the engine
 * neither grants nor retracts them. A household-level ERROR blocks every
 * member. Warnings and possible duplicates never block; they travel with the
 * review instead. The pipeline leaves existing beneficiary rows for blocked
 * members untouched.
 *
 * Called right after census completion, and safe to call again any time a
 * household's information changes (including an admin editing a birthdate,
 * occupation, income, or any criteria-affecting field). This is a real SYNC,
 * not just an add-only pass:
 *
 *   - member matches + no row            -> INSERT 'qualified'
 *   - member matches + row 'retracted'   -> restore to 'qualified'
 *   - member matches + row 'rejected'    -> NEVER touched (an admin's
 *                                            manual decision is preserved)
 *   - member no longer matches + row is
 *     'qualified' / 'pending' / 'approved' -> 'retracted' (this is what
 *     removes an approval the moment an edited birthdate drops someone
 *     below the program's minimum age, or an income rises past the cap)
 *
 *  No-application programs (application_mode = 'no_application_required'):
 *   - Imported members stay exactly as the import wrote them - the engine
 *     NEVER retracts or restores a row whose source is
 *     'existing_beneficiary_list' (the official import diff and manual admin
 *     action own those rows).
 *   - A member who matches the program's criteria and has no row is
 *     auto-enrolled as claim_status 'existing' + source_type
 *     'eligibility_engine', recording the qualification date (qualified_at)
 *     and a one-time qualification_snapshot (see build_qualification_snapshot).
 *   - If an engine-created row stops matching, it is 'retracted' (kept, not
 *     deleted). If it matches again, the SAME row is restored to 'existing'
 *     with its original qualified_at and qualification_snapshot preserved.
 *   - 'rejected' is sacred regardless of source.
 *
 *  Importing an official list is NOT a program type - it is a separate
 *  per-program flag (programs.import_existing_list) that works under either
 *  application mode. Imported rows always carry source_type
 *  'existing_beneficiary_list' + claim_status 'existing', so under an
 *  application_required program they sit on the list without ever needing to
 *  apply, and the diff logic only ever retracts its own imported rows.
 *
 *  The whole-system daily pass (maybe_run_eligibility_sweep) calls this same
 *  function for every household, throttled by a database lock so simultaneous
 *  requests can never start two full sweeps.
 *
 *  Rules that keep the sync honest:
 *   - 'rejected' is sacred. The engine never resurrects or alters a row a
 *     admin explicitly rejected.
 *   - Every transition clears resident_seen_at back to NULL so the
 *     resident's dashboard flags the change as new and shows the reason.
 *   - Deactivated programs are not in the active set at all, so
 *     deactivating a program neither matches nor retracts anything - that
 *     "review, don't silently undo" behavior is unchanged.
 *   - Validity window: a program's state is derived from its
 *     [start_date, end_date] against the app clock (Asia/Manila) -
 *     'upcoming' before the start, 'open' inside the window, 'closed' after
 *     the end passes or when it is deactivated. Matching itself is criteria
 *     only: the window NEVER retracts filed applications. The only time-based
 *     mutation is the lazy sweep (maybe_archive_programs), which flips an
 *     ended program to 'inactive' so it stops matching new households.
 *     Legacy rows with no dates are open-ended on that side.
 *   - Income means-test: a program qualifies only when the household has a
 *     monthly_income on file. The criteria may set an upper bound
 *     (max_income, "up to") and/or a lower bound (min_income, "at least").
 *     Unknown income is treated like unknown age - it does not qualify
 *     in either direction.
 *
 * Every result carries a per-member, per-program "explain" map so the
 * resident dashboard (read-only explain_household) and the admin flag page
 * can show exactly which check passed or failed.
 */

require_once __DIR__ . '/data_quality.php';
require_once __DIR__ . '/functions.php';

const ELIGIBILITY_RETRACTION_REASON = "No longer meets this program's criteria.";

/**
 * Reconciles one household's beneficiaries with the current active-program
 * rules. $actorUserId (optional) is attached to the activity log entries
 * for whatever change the sync makes; pass null when there is no human
 * actor (e.g. a batch run).
 *
 * application_required programs only ever mark matched members as
 * 'qualified' (the Apply flow is what makes them beneficiaries);
 * no_application_required programs are auto-enrolled here (see the header
 * notes). This function IS the daily sweep's per-household step.
 *
 * Persistent on the data side: runs the full assess->validate->sync
 * pipeline, so it also opens/auto-clears validation flags and recomputes
 * the household member count. Use explain_household() for a GET-safe,
 * non-persisting read of the same decision.
 *
 * @return array{
 *   added:list<array{member_id:int,program_id:int}>,
 *   retracted:list<array{member_id:int,program_id:int}>,
 *   restored:list<array{member_id:int,program_id:int}>,
 *   blocked:bool,
 *   blocked_member_ids:list<int>,
 *   flags:list<array<string,string|int|null>>,
 *   explain:array<int,array{name:string,age:?int,blocked:bool,programs:array<int,array{name:string,match:bool,checks:list<string>,details:list<array{result:string,reason:string,basis:string,required:string,actual:string,text:string}>}>}>
 * }
 */
function evaluate_household_eligibility(PDO $pdo, int $householdId, ?int $actorUserId = null): array {
    $assessment = assess_household_data($pdo, $householdId, true, $actorUserId);

    $result = [
        'added'             => [],
        'retracted'         => [],
        'restored'          => [],
        'blocked'           => $assessment['blocked'],
        'blocked_member_ids'=> $assessment['blocked_member_ids'],
        'flags'             => $assessment['flags'],
        'explain'           => [],
    ];

    if (!$assessment['household'] || empty($assessment['members'])) {
        return $result;
    }

    $allCriteria = load_active_criteria($pdo, $actorUserId);
    $result['explain'] = build_household_explain($assessment, $allCriteria);
    if ($assessment['blocked']) {
        return $result;
    }

    $blockedIds = array_fill_keys($assessment['blocked_member_ids'], true);
    $household  = $assessment['household'];
    $members    = $assessment['members'];

    $memberIds = array_column($members, 'member_id');
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $existingStmt = $pdo->prepare(
        "SELECT beneficiary_id, member_id, program_id, claim_status, source_type
         FROM beneficiaries WHERE member_id IN ($placeholders)"
    );
    $existingStmt->execute($memberIds);
    $existingByKey = [];
    foreach ($existingStmt->fetchAll() as $row) {
        $existingByKey[(int) $row['member_id'] . ':' . (int) $row['program_id']] = $row;
    }

    // Cache per-(member,program) matches so each pair is judged once.
    // Blocked members are never granted, retracted or restored this run.
    // Both program types are judged here: application_required members are
    // merely marked 'qualified' (the Apply flow makes them beneficiaries),
    // while no_application_required members who match are auto-enrolled.
    $matches = [];
    foreach ($members as $member) {
        $memberId = (int) $member['member_id'];
        if (isset($blockedIds[$memberId])) {
            continue;
        }
        foreach ($allCriteria as $criteria) {
            if (member_criteria_result($member, $household, $criteria)['match']) {
                $matches[$memberId . ':' . (int) $criteria['program_id']] = true;
            }
        }
    }

    $insertStmt = $pdo->prepare(
        "INSERT IGNORE INTO beneficiaries (member_id, program_id, claim_status, source_type, qualification_snapshot)
         VALUES (?, ?, 'qualified', 'eligibility_engine', ?)"
    );
    $autoEnrollStmt = $pdo->prepare(
        "INSERT IGNORE INTO beneficiaries (member_id, program_id, claim_status, source_type, qualification_snapshot)
         VALUES (?, ?, 'existing', 'eligibility_engine', ?)"
    );
    // Every transition below re-checks claim_status in the WHERE clause and
    // callers only log when rowCount() > 0. The status was read earlier in
    // this same run, so without the guard a concurrent admin decision
    // (approve/reject) could land in between and be silently overwritten -
    // most importantly a 'rejected' row, which must be sacred.
    $restoreStmt = $pdo->prepare(
        "UPDATE beneficiaries
         SET claim_status = 'qualified',
             rejection_reason = NULL,
             reviewed_by = NULL,
             reviewed_at = NULL,
             applicant_full_name = NULL,
             applicant_contact_number = NULL,
             resident_seen_at = NULL
         WHERE beneficiary_id = ? AND claim_status = 'retracted'"
    );
    $autoRestoreStmt = $pdo->prepare(
        "UPDATE beneficiaries
         SET claim_status = 'existing',
             rejection_reason = NULL,
             reviewed_by = NULL,
             reviewed_at = NULL,
             applicant_full_name = NULL,
             applicant_contact_number = NULL,
             resident_seen_at = NULL
         WHERE beneficiary_id = ? AND claim_status = 'retracted'"
    );
    $retractExistingStmt = $pdo->prepare(
        "UPDATE beneficiaries
         SET claim_status = 'retracted',
             rejection_reason = ?,
             reviewed_by = NULL,
             reviewed_at = NULL,
             resident_seen_at = NULL
         WHERE beneficiary_id = ? AND claim_status = 'existing'"
    );
    $retractApplicationStmt = $pdo->prepare(
        "UPDATE beneficiaries
         SET claim_status = 'retracted',
             rejection_reason = ?,
             reviewed_by = NULL,
             reviewed_at = NULL,
             resident_seen_at = NULL
         WHERE beneficiary_id = ? AND claim_status IN ('qualified', 'pending', 'approved')"
    );

    foreach ($members as $member) {
        $memberId = (int) $member['member_id'];
        if (isset($blockedIds[$memberId])) {
            continue;
        }
        foreach ($allCriteria as $criteria) {
            $applicationMode = $criteria['application_mode'] ?? 'application_required';
            $programId       = (int) $criteria['program_id'];
            $key             = $memberId . ':' . $programId;
            $isMatch         = isset($matches[$key]);
            $existing        = $existingByKey[$key] ?? null;
            $programLabel    = $criteria['program_name'] ?: "#{$programId}";

            // ── No-application programs (no_application_required) ───────────
            // A fresh match is auto-enrolled as a system-detected beneficiary
            // ('existing' + 'eligibility_engine') with a one-time snapshot;
            // the member/program unique key prevents duplicates. Our own
            // retracted rows are restored to 'existing' WITHOUT overwriting
            // the original qualified_at / qualification_snapshot. Imported
            // rows ('existing_beneficiary_list') and 'rejected' rows are never
            // retracted or restored here - the import diff and manual admin
            // action own imported records, and 'rejected' is sacred.
            if ($applicationMode === 'no_application_required') {
                if ($isMatch) {
                    if (!$existing) {
                        $autoEnrollStmt->execute([$memberId, $programId, build_qualification_snapshot($member, $household, $criteria)]);
                        $result['added'][] = ['member_id' => $memberId, 'program_id' => $programId];
                        log_activity(
                            $pdo,
                            $actorUserId,
                            "Enrolled {$member['first_name']} {$member['last_name']} in {$programLabel} as a system-detected beneficiary (auto-eligibility)",
                            'create'
                        );
                    } elseif ($existing['claim_status'] === 'retracted'
                              && ($existing['source_type'] ?? 'eligibility_engine') === 'eligibility_engine') {
                        $autoRestoreStmt->execute([(int) $existing['beneficiary_id']]);
                        if ($autoRestoreStmt->rowCount() > 0) {
                            $result['restored'][] = ['member_id' => $memberId, 'program_id' => $programId];
                            log_activity(
                                $pdo,
                                $actorUserId,
                                "Re-enrolled {$member['first_name']} {$member['last_name']} in {$programLabel} (criteria met again)",
                                'update'
                            );
                        }
                    }
                    // already 'existing' (imported or system-detected),
                    // 'qualified' / 'pending' / 'approved' / 'rejected' left as-is.
                } else {
                    if ($existing
                        && $existing['claim_status'] === 'existing'
                        && ($existing['source_type'] ?? 'eligibility_engine') === 'eligibility_engine') {
                        $retractExistingStmt->execute([ELIGIBILITY_RETRACTION_REASON, (int) $existing['beneficiary_id']]);
                        if ($retractExistingStmt->rowCount() > 0) {
                            $result['retracted'][] = ['member_id' => $memberId, 'program_id' => $programId];
                            log_activity(
                                $pdo,
                                $actorUserId,
                                "Retracted {$member['first_name']} {$member['last_name']} from {$programLabel} (criteria no longer met)",
                                'update'
                            );
                        }
                    }
                    // imported rows and 'rejected' left as-is.
                }
                continue;
            }

            // ── Application-required programs (unchanged behavior) ──────────
            // A fresh match is 'qualified' with a one-time snapshot; the
            // deadline-gated Apply flow is the only way this becomes a
            // beneficiary. Restores preserve the original qualified_at and
            // snapshot because this UPDATE never touches those columns.
            // Rows imported from an official list (source_type
            // 'existing_beneficiary_list') are never restored or retracted
            // here either - a removed import must not come back as an
            // application, and the import owns those records.
            if ($isMatch) {
                if (!$existing) {
                    $insertStmt->execute([$memberId, $programId, build_qualification_snapshot($member, $household, $criteria)]);
                    $result['added'][] = ['member_id' => $memberId, 'program_id' => $programId];
                    log_activity(
                        $pdo,
                        $actorUserId,
                        "Qualified {$member['first_name']} {$member['last_name']} for {$programLabel} (auto-eligibility)",
                        'create'
                    );
                } elseif ($existing['claim_status'] === 'retracted'
                          && ($existing['source_type'] ?? 'eligibility_engine') === 'eligibility_engine') {
                    $restoreStmt->execute([(int) $existing['beneficiary_id']]);
                    if ($restoreStmt->rowCount() > 0) {
                        $result['restored'][] = ['member_id' => $memberId, 'program_id' => $programId];
                        log_activity(
                            $pdo,
                            $actorUserId,
                            "Re-qualified {$member['first_name']} {$member['last_name']} for {$programLabel} (criteria met again)",
                            'update'
                        );
                    }
                }
                // 'qualified' / 'pending' / 'approved' / 'rejected' left as-is.
            } else {
                if ($existing
                    && in_array($existing['claim_status'], ['qualified', 'pending', 'approved'], true)) {
                    $retractApplicationStmt->execute([ELIGIBILITY_RETRACTION_REASON, (int) $existing['beneficiary_id']]);
                    if ($retractApplicationStmt->rowCount() > 0) {
                        $result['retracted'][] = ['member_id' => $memberId, 'program_id' => $programId];
                        log_activity(
                            $pdo,
                            $actorUserId,
                            "Retracted {$member['first_name']} {$member['last_name']} from {$programLabel} (criteria no longer met)",
                            'update'
                        );
                    }
                }
                // 'rejected' / already 'retracted' left as-is.
            }
        }
    }

    return $result;
}

/**
 * GET-safe read of the same household decision: cleaning, validation and
 * matching are all performed in-memory with NO writes (no flag upserts, no
 * member-count recompute, no beneficiary changes). Safe for the resident
 * dashboard to call on every page view.
 *
 * @return array{
 *   household_id:int,
 *   explain:array<int,array{name:string,age:?int,blocked:bool,programs:array<int,array{name:string,match:bool,checks:list<string>,details:list<array{result:string,reason:string,basis:string,required:string,actual:string,text:string}>}>}>,
 *   flags:list<array<string,string|int|null>>,
 *   blocked:bool,
 *   blocked_member_ids:list<int>
 * }
 */
function explain_household(PDO $pdo, int $householdId): array {
    $assessment = assess_household_data($pdo, $householdId, false);
    $explain = build_household_explain($assessment, load_active_criteria($pdo, null, false));

    return [
        'household_id'       => $householdId,
        'explain'            => $explain,
        'flags'              => $assessment['flags'],
        'blocked'            => $assessment['blocked'],
        'blocked_member_ids' => $assessment['blocked_member_ids'],
    ];
}

/**
 * Lazily archives programs whose end date has passed by flipping their status
 * to 'inactive' (archived). There is no scheduler, so this runs on a read
 * path: whichever page or evaluation is touched after the deadline performs
 * the flip. NULL end_date (legacy rows) is open-ended and never archived.
 * The comparison uses PHP's "now" in the app timezone, not the database
 * clock. Returns how many programs were archived this call.
 */
function maybe_archive_programs(PDO $pdo, ?int $actorUserId = null): int {
    $stmt = $pdo->prepare(
        'UPDATE programs SET status = \'inactive\'
         WHERE status = \'active\' AND end_date IS NOT NULL AND end_date < ?'
    );
    $stmt->execute([date('Y-m-d H:i:s')]);
    $archived = (int) $stmt->rowCount();
    if ($archived > 0) {
        log_activity($pdo, $actorUserId, "Auto-archived {$archived} program(s) past their end date", 'update');
    }
    return $archived;
}

/**
 * One-time qualification record for a beneficiary row the sync engine is
 * about to insert. Encodes the criteria/rules that were evaluated, the
 * relevant member and household values used, and the PASS checks that made
 * the member qualify. Stored in beneficiaries.qualification_snapshot at the
 * moment of INSERT and intentionally NEVER overwritten by later passes or
 * by a retraction/restoration - so the original reason the resident was
 * detected as qualified stays on record.
 */
function build_qualification_snapshot(array $member, array $household, array $criteria): string {
    $result = member_criteria_result($member, $household, $criteria);
    $tz = defined('APP_TIMEZONE') ? new DateTimeZone(APP_TIMEZONE) : null;
    $now = $tz !== null
        ? (new DateTime('now', $tz))->format('Y-m-d H:i:s')
        : date('Y-m-d H:i:s');

    return json_encode([
        'evaluated_at' => $now,
        'match'        => $result['match'],
        'checks'       => $result['checks'],
        'details'      => $result['details'],
        'program'      => [
            'program_id'       => (int) $criteria['program_id'],
            'program_name'     => $criteria['program_name'],
            'application_mode' => $criteria['application_mode'] ?? 'application_required',
        ],
        'criteria' => [
            'min_age'             => $criteria['min_age'] ?? null,
            'max_age'             => $criteria['max_age'] ?? null,
            'requires_pwd'        => (bool) ($criteria['requires_pwd'] ?? false),
            'requires_solo_parent'=> (bool) ($criteria['requires_solo_parent'] ?? false),
            'requires_ofw'        => (bool) ($criteria['requires_ofw'] ?? false),
            'requires_ip'         => (bool) ($criteria['requires_ip'] ?? false),
            'requires_osy'        => (bool) ($criteria['requires_osy'] ?? false),
            'requires_unemployed' => (bool) ($criteria['requires_unemployed'] ?? false),
            'requires_employed'   => (bool) ($criteria['requires_employed'] ?? false),
            'requires_student'    => (bool) ($criteria['requires_student'] ?? false),
            'min_household_size'  => $criteria['min_household_size'] ?? null,
            'max_income'          => $criteria['max_income'] ?? null,
            'min_income'          => $criteria['min_income'] ?? null,
        ],
        'member' => [
            'member_id'      => (int) $member['member_id'],
            'name'           => dq_member_name($member),
            'age'            => $member['age'],
            'occupation'     => $member['occupation'],
            'is_pwd'         => (bool) $member['is_pwd'],
            'is_solo_parent' => (bool) $member['is_solo_parent'],
            'is_ofw'         => (bool) $member['is_ofw'],
            'is_ip'          => (bool) $member['is_ip'],
            'is_osy'         => (bool) $member['is_osy'],
            'is_unemployed'  => (bool) $member['is_unemployed'],
            'is_employed'    => (bool) $member['is_employed'],
            'is_student'     => (bool) $member['is_student'],
        ],
        'household' => [
            'household_id'   => (int) $household['household_id'],
            'monthly_income' => $household['monthly_income'],
            'live_count'     => $household['live_count'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Throttled full-system eligibility sweep.
 *
 * There is no scheduler, so - exactly like maybe_archive_programs() - this
 * runs on read paths (the admin layout-start and the resident dashboard,
 * NOT the resident sidebar partial). It performs a complete per-household
 * evaluation pass at most once every 24 hours, driven by
 * 'eligibility_last_completed_at' in system_meta.
 *
 * Concurrency is handled with a database lock row ('eligibility_sweep_lock')
 * rather than time alone. Acquiring the lock is one atomic UPDATE that only
 * succeeds while the lock is empty OR stale (held more than 15 minutes, e.g.
 * a request that died mid-sweep), so simultaneous requests can never start
 * duplicate full sweeps, and a crashed request cannot block the next sweep
 * for 24 hours. The completion stamp and the lock release are both written
 * only after every household has been evaluated; individual household errors
 * are logged and never abort the sweep.
 */
function maybe_run_eligibility_sweep(PDO $pdo, ?int $actorUserId = null): void {
    // Ensure the two metadata rows exist (idempotent).
    $pdo->prepare(
        "INSERT IGNORE INTO system_meta (meta_key, meta_value, updated_at)
         VALUES ('eligibility_sweep_lock', '', NULL),
                ('eligibility_last_completed_at', NULL, NULL)"
    )->execute();

    // Interval gate: skip unless the last COMPLETED sweep is >24h old.
    $completeStmt = $pdo->prepare(
        'SELECT 1 FROM system_meta
         WHERE meta_key = ? AND meta_value IS NOT NULL AND updated_at > (NOW() - INTERVAL 24 HOUR)'
    );
    $completeStmt->execute(['eligibility_last_completed_at']);
    if ($completeStmt->fetchColumn()) {
        return;
    }

    // Acquire the lock atomically: only wins when unlocked or stale (>15 min).
    // The stored value carries a per-run owner token so a slow sweep that goes
    // stale and is taken over by another request cannot later release the new
    // owner's lock (which would then let a third sweep run concurrently).
    $lockOwner = 'locked:' . bin2hex(random_bytes(8));
    $lockStmt = $pdo->prepare(
        "UPDATE system_meta
            SET meta_value = ?, updated_at = NOW()
          WHERE meta_key = 'eligibility_sweep_lock'
            AND (meta_value IS NULL OR meta_value = ''
                 OR updated_at IS NULL OR updated_at <= (NOW() - INTERVAL 15 MINUTE)
                 OR (meta_value <> 'locked' AND meta_value NOT LIKE 'locked:%'))"
    );
    $lockStmt->execute([$lockOwner]);
    if ($lockStmt->rowCount() < 1) {
        return; // another request holds a fresh lock
    }

    maybe_archive_programs($pdo, $actorUserId);

    $householdIds = $pdo->query('SELECT household_id FROM households ORDER BY household_id')->fetchAll(PDO::FETCH_COLUMN);
    $changes  = ['added' => 0, 'restored' => 0, 'retracted' => 0];
    $failures = [];
    foreach ($householdIds as $householdId) {
        try {
            $res = evaluate_household_eligibility($pdo, (int) $householdId, $actorUserId);
            $changes['added']     += count($res['added']);
            $changes['restored']  += count($res['restored']);
            $changes['retracted'] += count($res['retracted']);
        } catch (Throwable $e) {
            $failures[] = (int) $householdId;
            error_log('Eligibility sweep failed for household #' . $householdId . ': ' . $e->getMessage());
        }
    }

    // Completion stamp only after the whole pass has run.
    $pdo->prepare(
        "INSERT INTO system_meta (meta_key, meta_value, updated_at)
         VALUES ('eligibility_last_completed_at', NOW(), NOW())
         ON DUPLICATE KEY UPDATE meta_value = NOW(), updated_at = NOW()"
    )->execute();

    // Release the lock only when we still own it - after a stale takeover the
    // row holds someone else's token and this must not clear it.
    $releaseStmt = $pdo->prepare(
        "UPDATE system_meta SET meta_value = '', updated_at = NOW()
         WHERE meta_key = 'eligibility_sweep_lock' AND meta_value = ?"
    );
    $releaseStmt->execute([$lockOwner]);

    log_activity(
        $pdo,
        $actorUserId,
        'Automatic eligibility sweep: ' . count($householdIds) . ' household(s) evaluated, '
            . $changes['added'] . ' added, ' . $changes['restored'] . ' restored, '
            . $changes['retracted'] . ' retracted'
            . (!empty($failures) ? '; ' . count($failures) . ' household(s) errored (# ' . implode(', ', $failures) . ')' : ''),
        'update'
    );
}

/**
 * Derives a program's lifecycle state from its status and validity window,
 * against the app clock (Asia/Manila). This is the single decision point for
 * both the admin list badges and the resident-facing Apply gate.
 *
 *   - 'upcoming'  status active, before start
 *   - 'open'      status active, within [start, end] (either date may be
 *                 null = open-ended on that side)
 *   - 'closed'    status inactive, OR past the end date
 */
function program_state(array $p): string {
    if (($p['status'] ?? 'active') !== 'active') {
        return 'closed';
    }
    $now = new DateTime('now');
    if (!empty($p['start_date']) && strtotime($p['start_date']) > $now->getTimestamp()) {
        return 'upcoming';
    }
    if (!empty($p['end_date']) && strtotime($p['end_date']) < $now->getTimestamp()) {
        return 'closed';
    }
    return 'open';
}

/**
 * All active programs' criteria, joined with the display name + window.
 * Includes application_mode so callers can distinguish application_required
 * from no_application_required programs - the sync engine auto-enrolls matches
 * for no_application_required programs, and the explain map shows both types.
 */
function load_active_criteria(PDO $pdo, ?int $actorUserId = null, bool $archiveExpired = true): array {
    if ($archiveExpired) {
        maybe_archive_programs($pdo, $actorUserId);
    }
    return $pdo->query(
        "SELECT pc.*, p.name AS program_name, p.status AS program_status,
                p.start_date, p.end_date, p.application_mode
         FROM program_criteria pc
         JOIN programs p ON p.program_id = pc.program_id
         WHERE p.status = 'active'"
    )->fetchAll();
}

/**
 * Per-member, per-program explain map used by both the sync engine and the
 * read-only explain_household(). Blocked members are still explained - their
 * entry is just flagged so the review page makes it obvious why no program
 * decision was made for them.
 */
function build_household_explain(array $assessment, array $allCriteria): array {
    $household = $assessment['household'];
    if (!$household) {
        return [];
    }

    $explain = [];
    $blockedIds = array_fill_keys($assessment['blocked_member_ids'], true);
    $blocked = $assessment['blocked'];

    foreach ($assessment['members'] as $member) {
        $memberId = (int) $member['member_id'];
        $entry = [
            'name'     => dq_member_name($member),
            'age'      => $member['age'],
            'blocked'  => $blocked || isset($blockedIds[$memberId]),
            'programs' => [],
        ];
        foreach ($allCriteria as $criteria) {
            $res = member_criteria_result($member, $household, $criteria);
            $entry['programs'][(int) $criteria['program_id']] = [
                'name'    => $criteria['program_name'],
                'match'   => $res['match'],
                'checks'  => $res['checks'],
                'details' => $res['details'],
            ];
        }
        $explain[$memberId] = $entry;
    }

    return $explain;
}

/**
 * Judges one household member against one program's criteria and returns
 * whether it passed plus a human-readable PASS/FAIL list. Age is always
 * derived from the birthdate at evaluation time - never read from a stored
 * value - so a later admin edit to the birthdate flows through to
 * eligibility automatically.
 *
 * Alongside the display strings, every criterion that was actually judged
 * also yields one structured "detail" row built in the SAME branch, so the
 * resident-facing pages can render Reason / Basis / Required / Your
 * information without ever re-deriving a decision from string parsing:
 *
 *   result   'PASS' | 'FAIL'
 *   reason   short outcome sentence ("Below the program's minimum age")
 *   basis    criterion label ("Minimum age")
 *   required the configured value ("At least 60 years old")
 *   actual   the member/household value on file ("45 years old")
 *   text     the exact string that was pushed into checks (or would be)
 *
 * A passing boolean criterion (PWD, solo parent, ...) has always produced
 * no entry in checks - that is preserved - but it does get a PASS detail
 * row, so "what was checked and met" is complete for display. $details may
 * therefore be longer than $checks; consumers must filter on result.
 *
 * @return array{match:bool, checks:list<string>, details:list<array{result:string,reason:string,basis:string,required:string,actual:string,text:string}>}
 */
function member_criteria_result(array $member, array $household, array $criteria): array {
    $age     = $member['age'];
    $checks  = [];
    $details = [];
    $ok      = true;
    $fail = static function (string $text, string $reason, string $basis, string $required, string $actual) use (&$checks, &$details, &$ok): void {
        $ok = false;
        $checks[]  = 'FAIL ' . $text;
        $details[] = ['result' => 'FAIL', 'reason' => $reason, 'basis' => $basis, 'required' => $required, 'actual' => $actual, 'text' => 'FAIL ' . $text];
    };
    $pass = static function (string $text, string $reason, string $basis, string $required, string $actual) use (&$checks, &$details): void {
        $checks[]  = 'PASS ' . $text;
        $details[] = ['result' => 'PASS', 'reason' => $reason, 'basis' => $basis, 'required' => $required, 'actual' => $actual, 'text' => 'PASS ' . $text];
    };
    // Same row, but recorded in details only - never in checks, so legacy
    // PASS/FAIL lists (resident dashboard, admin assessment) stay identical.
    $passQuiet = static function (string $reason, string $basis, string $required, string $actual) use (&$details): void {
        $details[] = ['result' => 'PASS', 'reason' => $reason, 'basis' => $basis, 'required' => $required, 'actual' => $actual, 'text' => 'PASS ' . $basis];
    };

    $ageDesc   = $age !== null ? (string) $age : 'unknown (no valid birthdate)';
    $ageActual = $age !== null ? $age . ' years old' : 'No valid birthdate on file';

    if ($criteria['min_age'] !== null) {
        $required = 'At least ' . (int) $criteria['min_age'] . ' years old';
        if ($age === null || $age < (int) $criteria['min_age']) {
            $fail('minimum age ' . (int) $criteria['min_age'] . ' (age ' . $ageDesc . ')', 'Below the program\'s minimum age', 'Minimum age', $required, $ageActual);
        } else {
            $pass('minimum age ' . (int) $criteria['min_age'], 'Meets the program\'s minimum age', 'Minimum age', $required, $ageActual);
        }
    }
    if ($criteria['max_age'] !== null) {
        $required = 'At most ' . (int) $criteria['max_age'] . ' years old';
        if ($age === null || $age > (int) $criteria['max_age']) {
            $fail('maximum age ' . (int) $criteria['max_age'] . ' (age ' . $ageDesc . ')', 'Above the program\'s maximum age', 'Maximum age', $required, $ageActual);
        } else {
            $pass('maximum age ' . (int) $criteria['max_age'], 'Within the program\'s maximum age', 'Maximum age', $required, $ageActual);
        }
    }
    $boolCriterion = static function (bool $configured, bool $actual, string $checkText, string $reason, string $basis, string $required, string $setLabel, string $notSetLabel) use ($fail, $passQuiet): void {
        if (!$configured) {
            return;
        }
        if (!$actual) {
            $fail($checkText, $reason, $basis, $required, $notSetLabel);
        } else {
            $passQuiet($reason . ' (met)', $basis, $required, $setLabel);
        }
    };
    $boolCriterion(
        (bool) $criteria['requires_pwd'],
        (bool) $member['is_pwd'],
        'must be a person with disability',
        'Not registered as a person with disability',
        'Person with disability (PWD) status',
        'Must be registered as a PWD',
        'Listed as a PWD',
        'Not listed as a PWD'
    );
    $boolCriterion(
        (bool) $criteria['requires_solo_parent'],
        (bool) $member['is_solo_parent'],
        'must be a solo parent',
        'Not listed as a solo parent',
        'Solo parent status',
        'Must be listed as a solo parent',
        'Listed as a solo parent',
        'Not listed as a solo parent'
    );
    $boolCriterion(
        (bool) $criteria['requires_ofw'],
        (bool) $member['is_ofw'],
        'must be an OFW',
        'Not listed as an overseas Filipino worker',
        'Overseas Filipino worker (OFW) status',
        'Must be listed as an OFW',
        'Listed as an OFW',
        'Not listed as an OFW'
    );
    $boolCriterion(
        (bool) $criteria['requires_ip'],
        (bool) $member['is_ip'],
        'must be an indigenous person',
        'Not listed as an indigenous person',
        'Indigenous person status',
        'Must be listed as an indigenous person',
        'Listed as an indigenous person',
        'Not listed as an indigenous person'
    );
    $boolCriterion(
        (bool) $criteria['requires_osy'],
        (bool) $member['is_osy'],
        'must be an out-of-school youth',
        'Not listed as an out-of-school youth',
        'Out-of-school youth status',
        'Must be listed as an out-of-school youth',
        'Listed as an out-of-school youth',
        'Not listed as an out-of-school youth'
    );
    $boolCriterion(
        !empty($criteria['requires_unemployed']),
        (bool) $member['is_unemployed'],
        'must be unemployed',
        'Not listed as unemployed',
        'Employment status (unemployed)',
        'Must be listed as unemployed',
        'Listed as unemployed',
        'Not listed as unemployed'
    );
    $boolCriterion(
        !empty($criteria['requires_employed']),
        (bool) $member['is_employed'],
        'must be employed',
        'Not listed as employed',
        'Employment status (employed)',
        'Must be listed as employed',
        'Listed as employed',
        'Not listed as employed'
    );
    $boolCriterion(
        !empty($criteria['requires_student']),
        (bool) $member['is_student'],
        'must be a student',
        'Not listed as a student',
        'Student status',
        'Must be listed as a student',
        'Listed as a student',
        'Not listed as a student'
    );
    if ($criteria['min_household_size'] !== null) {
        $required = 'At least ' . (int) $criteria['min_household_size'] . ' members';
        $actual   = $household['live_count'] . ' members in the household';
        if ($household['live_count'] < (int) $criteria['min_household_size']) {
            $fail('household size minimum ' . (int) $criteria['min_household_size'] . ' (household has ' . $household['live_count'] . ')', 'Household is smaller than the program requires', 'Household size', $required, $actual);
        } else {
            $pass('household size minimum ' . (int) $criteria['min_household_size'], 'Household meets the program\'s minimum size', 'Household size', $required, $actual);
        }
    }
    // Unknown income is treated like unknown age/birthdate: it cannot
    // satisfy a means-tested criterion. The criteria may cap income
    // (max_income, "up to") and/or floor it (min_income, "at least").
    $incomeActual = $household['monthly_income'] === null
        ? 'No income on file'
        : '₱' . number_format((float) $household['monthly_income'], 2) . ' per month';
    if ($criteria['max_income'] !== null) {
        $cap      = '₱' . number_format((float) $criteria['max_income'], 2);
        $required = $cap . ' or less per month';
        if ($household['monthly_income'] === null) {
            $fail('monthly income at or below ' . $cap . ' (no income on file)', 'No household income on file to prove the income cap', 'Household monthly income (cap)', $required, $incomeActual);
        } elseif ($household['monthly_income'] > (float) $criteria['max_income']) {
            $fail('monthly income at or below ' . $cap . ' (household income ₱' . number_format($household['monthly_income'], 2) . ')', 'Household income is above the program\'s cap', 'Household monthly income (cap)', $required, $incomeActual);
        } else {
            $pass('income cap ' . $cap, 'Household income is within the program\'s cap', 'Household monthly income (cap)', $required, $incomeActual);
        }
    }
    if ($criteria['min_income'] !== null) {
        $floor    = '₱' . number_format((float) $criteria['min_income'], 2);
        $required = $floor . ' or more per month';
        if ($household['monthly_income'] === null) {
            $fail('monthly income at least ' . $floor . ' (no income on file)', 'No household income on file to prove the income floor', 'Household monthly income (floor)', $required, $incomeActual);
        } elseif ($household['monthly_income'] < (float) $criteria['min_income']) {
            $fail('monthly income at least ' . $floor . ' (household income ₱' . number_format($household['monthly_income'], 2) . ')', 'Household income is below the program\'s minimum', 'Household monthly income (floor)', $required, $incomeActual);
        } else {
            $pass('income floor ' . $floor, 'Household income meets the program\'s minimum', 'Household monthly income (floor)', $required, $incomeActual);
        }
    }

    return ['match' => $ok, 'checks' => $checks, 'details' => $details];
}