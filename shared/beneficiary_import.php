<?php
/**
 * Beneficiary import engine: handles parsing, normalization, matching,
 * and execution of existing beneficiary list imports.
 *
 * Two-stage matching:
 *   1. Exact match: normalized full name + birthdate (+ optional address)
 *   2. Fuzzy match: Levenshtein distance on name, with birthdate as
 *      secondary validator. Fuzzy matches ALWAYS go to manual review.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/xlsx_reader.php';

// ── Column alias map ──────────────────────────────────────────────────
// Maps common header variations to internal field names.
function import_column_aliases(): array
{
    return [
        'last_name'     => ['last name', 'surname', 'family name', 'apelyido', 'apellido', 'last_name', 'ln'],
        'first_name'    => ['first name', 'given name', 'pangalan', 'first_name', 'fn'],
        'middle_name'   => ['middle name', 'middle_name', 'mn', 'middle'],
        'suffix'        => ['suffix', 'ext', 'extension', 'hulihan'],
        'birthdate'     => ['birthdate', 'birth date', 'birthday', 'dob', 'date of birth'],
        'birth_month'   => ['birth month', 'birth_month', 'month', 'bulan'],
        'birth_day'     => ['birth day', 'birth_day', 'day', 'araw'],
        'birth_year'    => ['birth year', 'birth_year', 'year', 'taon'],
        'age'           => ['age', 'edad', 'age_ref', 'age_reference'],
        'sex'           => ['sex', 'gender', 'kasarian'],
        'zone'          => ['zone', 'purok', 'barangay zone', 'zone_no', 'zone number'],
        'address'       => ['address', 'street', 'full address', 'complete address', 'tirahan'],
        'house_number'  => ['house number', 'house_number', 'house no', 'house_no', 'building', 'unit'],
        'civil_status'  => ['civil status', 'civil_status', 'status', 'civil'],
        'occupation'    => ['occupation', 'work', 'job', 'trabaho'],
        'original_status' => ['status', 'list status', 'beneficiary status', 'original status'],
    ];
}

// ── Parse uploaded file ───────────────────────────────────────────────
function import_parse_file(string $tmpPath, string $fileType): array
{
    if ($fileType === 'xlsx') {
        $reader = new XlsxReader();
        $rows = $reader->read($tmpPath);
    } else {
        // CSV
        $rows = [];
        $handle = fopen($tmpPath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Could not open CSV file for reading.');
        }
        // Detect BOM
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
    }

    if (empty($rows)) {
        throw new RuntimeException('The uploaded file appears to be empty.');
    }

    return $rows;
}

// ── Detect header row and build column map ─────────────────────────────
function import_detect_headers(array $rows): array
{
    $aliases = import_column_aliases();
    $aliasFlat = [];
    foreach ($aliases as $field => $names) {
        foreach ($names as $n) {
            $aliasFlat[strtolower(trim($n))] = $field;
        }
    }

    // Scan first 10 rows looking for one with 2+ header matches
    $headerRowIndex = null;
    $columnMap = []; // col_index => field_name
    $headerLabels = []; // col_index => original header text

    for ($i = 0; $i < min(10, count($rows)); $i++) {
        $row = $rows[$i];
        $matches = [];
        foreach ($row as $colIdx => $cell) {
            $normalized = strtolower(trim((string) $cell));
            $normalized = preg_replace('/[\s_]+/', ' ', $normalized);
            if (isset($aliasFlat[$normalized])) {
                $matches[$colIdx] = $aliasFlat[$normalized];
            }
        }
        if (count($matches) >= 2) {
            $headerRowIndex = $i;
            $columnMap = $matches;
            $headerLabels = array_map(fn($c) => trim((string) $rows[$i][$c] ?? ''), array_keys($matches));
            break;
        }
    }

    return [
        'header_row_index' => $headerRowIndex,
        'column_map'       => $columnMap,
        'header_labels'    => $headerLabels,
        'data_start_index' => $headerRowIndex !== null ? $headerRowIndex + 1 : 0,
    ];
}

// ── Normalize a single imported row ───────────────────────────────────
function import_normalize_row(array $rawRow, array $columnMap): array
{
    $get = function (string $field) use ($rawRow, $columnMap) {
        foreach ($columnMap as $colIdx => $f) {
            if ($f === $field && isset($rawRow[$colIdx])) {
                return trim((string) $rawRow[$colIdx]);
            }
        }
        return '';
    };

    $lastName  = normalize_person_name($get('last_name'));
    $firstName = normalize_person_name($get('first_name'));
    // Middle Name terminology: collect and normalize the full middle name
    // for all future input. Stored single-letter records are preserved
    // exactly as they are (no rewrite); tolerance for initial-vs-full is
    // handled at match time as a weak signal only (see
    // middle_names_compatible()).
    $middleName = $get('middle_name') !== '' ? normalize_person_name($get('middle_name')) : null;
    $suffix = $get('suffix') !== '' ? normalize_suffix($get('suffix')) : null;

    // Build full name for display and matching
    $fullNameParts = array_filter([$firstName, $middleName, $lastName, $suffix]);
    $fullName = implode(' ', $fullNameParts);

    // Construct birthdate from individual fields or combined field
    $birthdate = null;
    $birthdateStr = $get('birthdate');
    if ($birthdateStr !== '') {
        $birthdate = import_parse_date($birthdateStr);
    }
    if ($birthdate === null) {
        $month = (int) ($get('birth_month') ?: 0);
        $day   = (int) ($get('birth_day') ?: 0);
        $year  = (int) ($get('birth_year') ?: 0);
        if ($month > 0 && $day > 0 && $year > 0 && $year > 1900) {
            $candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $dt = DateTime::createFromFormat('Y-m-d', $candidate);
            if ($dt !== false && $dt->format('Y-m-d') === $candidate) {
                $birthdate = $candidate;
            }
        }
    }

    // Address / zone
    $zone = $get('zone');
    $address = $get('address');
    $houseNumber = $get('house_number');
    $fullAddress = trim(implode(', ', array_filter([$houseNumber, $address])));

    // Age from spreadsheet (reference only)
    $ageRef = $get('age');
    $ageRef = is_numeric($ageRef) ? (int) $ageRef : null;

    // Original status from the imported list
    $originalStatus = $get('original_status') ?: null;

    return [
        'last_name'        => $lastName,
        'first_name'       => $firstName,
        'middle_name'      => $middleName,
        'suffix'           => $suffix,
        'full_name'        => $fullName,
        'birthdate'        => $birthdate,
        'age_ref'          => $ageRef,
        'zone'             => $zone,
        'address'          => $fullAddress,
        'house_number'     => $houseNumber,
        'original_status'  => $originalStatus,
    ];
}

// ── Parse various date formats ────────────────────────────────────────
function import_parse_date(string $input): ?string
{
    $input = trim($input);
    if ($input === '') return null;

    $formats = [
        'Y-m-d', 'Y/m/d', 'm/d/Y', 'm/d/y', 'd/m/Y', 'd/m/y',
        'M d, Y', 'F d, Y', 'd M Y', 'd F Y',
        'Y-m-d H:i:s', 'Y/m/d H:i:s',
    ];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $input);
        if ($dt !== false) {
            $check = $dt->format($fmt);
            if ($check === $input || $check === substr($input, 0, strlen($check))) {
                return $dt->format('Y-m-d');
            }
        }
    }

    // Numeric dates that failed every strict round-trip above are impossible
    // dates PHP would silently roll over (02/30/2020 -> 2020-03-01), and a
    // bare year has no day at all: reject both instead of inventing a
    // birthdate that later matches - or fails to match - the wrong resident.
    if (preg_match('/^\d{3,4}$/', $input) || preg_match('/^\d{1,4}[\/-]\d{1,2}[\/-]\d{1,4}/', $input)) {
        return null;
    }

    // Try strtotime as last resort - only when the input actually carries a
    // four-digit year that survives parsing unchanged.
    $ts = strtotime($input);
    if ($ts !== false && $ts > 0 && preg_match('/\b(?:19|20)\d{2}\b/', $input, $ym)) {
        if ((int) date('Y', $ts) !== (int) $ym[0]) {
            return null;
        }
        return date('Y-m-d', $ts);
    }

    return null;
}

// ── Two-stage resident matching ───────────────────────────────────────

/**
 * Middle-name compatibility for import matching.
 *
 * The middle name alone must never establish or reject a match: this is a
 * weak compatibility signal only. First name, last name, birthdate, and
 * the existing safeguards determine whether records match or go to manual
 * review.
 *
 * Compatible when: either side is empty (unknown), both full names agree
 * (case-insensitive), or one side is a single initial matching the first
 * letter of the other side (M <-> Maria). A shared initial with a
 * different full middle name (M <-> Maria vs Miguel) is neither a match
 * nor a rejection on middle alone - the other determinants decide.
 * Incompatible only when both sides hold full (multi-letter) values that
 * differ (Maria <-> Miguel).
 */
function middle_names_compatible(?string $stored, ?string $incoming): bool {
    $norm = static function (?string $v): string {
        $v = trim((string) $v);
        $v = rtrim($v, '.');
        $v = preg_replace('/\s+/', ' ', $v);
        return $v !== null ? $v : '';
    };
    $a = $norm($stored);
    $b = $norm($incoming);
    if ($a === '' || $b === '') {
        return true;
    }
    if (strcasecmp($a, $b) === 0) {
        return true;
    }
    $aInitial = mb_strlen($a, 'UTF-8') === 1;
    $bInitial = mb_strlen($b, 'UTF-8') === 1;
    if ($aInitial || $bInitial) {
        return strcasecmp(mb_substr($a, 0, 1, 'UTF-8'), mb_substr($b, 0, 1, 'UTF-8')) === 0;
    }
    return false;
}

/**
 * Exact match: normalized full name + birthdate + optional address.
 * Returns the member row if exactly one match is found, null otherwise.
 */
function import_exact_match(PDO $pdo, array $normalized): ?array
{
    $lastName  = $normalized['last_name'];
    $firstName = $normalized['first_name'];
    $birthdate = $normalized['birthdate'];
    $middleName = $normalized['middle_name'];

    if ($lastName === '' || $firstName === '') {
        return null;
    }

    // Build query: match by name + birthdate
    $sql = "SELECT hm.member_id, hm.household_id, hm.first_name, hm.last_name,
                   hm.middle_name, hm.suffix, hm.birthdate, hm.sex,
                   h.street, h.house_number, h.barangay, h.city
            FROM household_members hm
            JOIN households h ON h.household_id = hm.household_id
            WHERE hm.is_archived = FALSE
              AND hm.last_name = ?
              AND hm.first_name = ?";

    $params = [$lastName, $firstName];

    // Birthdate match (NULL-safe)
    if ($birthdate !== null) {
        $sql .= " AND hm.birthdate = ?";
        $params[] = $birthdate;
    } else {
        $sql .= " AND hm.birthdate IS NULL";
    }

    $sql .= " LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll();

    // Middle-name gate (weak signal only): keep candidates whose stored
    // middle is compatible with the incoming value. This can only narrow
    // on genuine full-vs-full mismatches; an initial alone neither
    // establishes nor rejects - birthdate (in the query above) plus the
    // single-candidate / address-tiebreak rules below still decide.
    $results = array_values(array_filter(
        $candidates,
        static fn (array $r): bool => middle_names_compatible($r['middle_name'] ?? null, $middleName)
    ));

    if (count($results) === 1) {
        return $results[0];
    }

    // Multiple matches: try address tiebreaker if available
    if (count($results) > 1 && $normalized['address'] !== '') {
        $normAddr = strtolower(preg_replace('/\s+/', ' ', $normalized['address']));
        $best = null;
        $bestScore = 0;
        foreach ($results as $r) {
            $memberAddr = strtolower(preg_replace('/\s+/', ' ', format_household_address($r)));
            similar_text($normAddr, $memberAddr, $sim);
            if ($sim > $bestScore) {
                $bestScore = $sim;
                $best = $r;
            }
        }
        if ($best !== null && $bestScore > 60) {
            return $best;
        }
    }

    return null; // 0 or 2+ matches = no exact match
}

/**
 * Fuzzy match: Levenshtein distance on full name, with birthdate as
 * secondary validator. Returns possible matches with confidence scores.
 * These ALWAYS go to manual review.
 *
 * @return list<array{member_id:int, confidence:float, ...}>
 */
function import_fuzzy_match(PDO $pdo, array $normalized): array
{
    $lastName  = $normalized['last_name'];
    $firstName = $normalized['first_name'];

    if ($lastName === '' || $firstName === '') {
        return [];
    }

    // Use LIKE for first letter matching to narrow the search
    $sql = "SELECT hm.member_id, hm.household_id, hm.first_name, hm.last_name,
                   hm.middle_name, hm.suffix, hm.birthdate, hm.sex,
                   h.street, h.house_number, h.barangay, h.city
            FROM household_members hm
            JOIN households h ON h.household_id = hm.household_id
            WHERE hm.is_archived = FALSE
              AND (
                hm.last_name LIKE ? OR hm.last_name LIKE ?
                OR hm.first_name LIKE ? OR hm.first_name LIKE ?
              )
            LIMIT 50";

    $prefix = mb_substr($lastName, 0, 1) . '%';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$lastName . '%', $prefix, $firstName . '%', mb_substr($firstName, 0, 1) . '%']);
    $candidates = $stmt->fetchAll();

    $results = [];
    $fullName = strtolower($normalized['full_name']);

    foreach ($candidates as $c) {
        $candidateName = strtolower(trim($c['first_name'] . ' ' . ($c['middle_name'] ?? '') . ' ' . $c['last_name'] . ($c['suffix'] ? ', ' . $c['suffix'] : '')));

        // Levenshtein distance on full names
        $lev = levenshtein($fullName, $candidateName);
        $maxLen = max(mb_strlen($fullName), mb_strlen($candidateName));
        if ($maxLen === 0) continue;

        $nameScore = max(0, 100 - ($lev / $maxLen) * 100);

        // Birthdate bonus/penalty
        $birthdateScore = 0;
        if ($normalized['birthdate'] !== null && $c['birthdate'] !== null) {
            if ($normalized['birthdate'] === $c['birthdate']) {
                $birthdateScore = 20;
            } else {
                $birthdateScore = -30;
            }
        }

        // Middle name bonus (weak signal only, never negative): exact
        // full agreement scores highest; an initial compatible with the
        // other side's first letter scores a small bonus; anything else
        // scores nothing and defers to name/birthdate safeguards.
        $middleScore = 0;
        $normMid = trim((string) ($normalized['middle_name'] ?? ''));
        $candMid = trim((string) ($c['middle_name'] ?? ''));
        if ($normMid !== '' && $candMid !== '') {
            if (strcasecmp($normMid, $candMid) === 0) {
                $middleScore = 10;
            } elseif (middle_names_compatible($candMid, $normMid)) {
                $middleScore = 3;
            }
        }

        $totalScore = min(100, max(0, $nameScore + $birthdateScore + $middleScore));

        if ($totalScore >= 50) {
            $results[] = [
                'member_id'  => (int) $c['member_id'],
                'confidence' => round($totalScore, 2),
                'first_name' => $c['first_name'],
                'last_name'  => $c['last_name'],
                'birthdate'  => $c['birthdate'],
                'household_id' => (int) $c['household_id'],
            ];
        }
    }

    // Sort by confidence descending
    usort($results, fn($a, $b) => $b['confidence'] <=> $a['confidence']);

    return array_slice($results, 0, 5);
}

// ── Check duplicate rows within the file ──────────────────────────────
function import_check_file_duplicates(array &$rows): void
{
    $seen = [];
    foreach ($rows as &$row) {
        $key = strtolower(trim(
            $row['last_name'] . '|' . $row['first_name'] . '|' . ($row['birthdate'] ?? '')
        ));
        if (isset($seen[$key])) {
            $row['_duplicate_in_file'] = true;
        } else {
            $seen[$key] = true;
            $row['_duplicate_in_file'] = false;
        }
    }
    unset($row);
}

// ── Check if member is already a beneficiary ──────────────────────────
function import_check_existing_beneficiaries(PDO $pdo, int $programId, array &$rows): void
{
    if (empty($rows)) return;

    $memberIds = array_filter(array_column($rows, '_matched_member_id'));
    if (empty($memberIds)) return;

    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT member_id FROM beneficiaries
         WHERE program_id = ? AND member_id IN ($placeholders)
           AND claim_status != 'retracted'"
    );
    $params = array_merge([$programId], array_values($memberIds));
    $stmt->execute($params);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as &$row) {
        if (isset($row['_matched_member_id']) && in_array($row['_matched_member_id'], $existing)) {
            $row['_already_beneficiary'] = true;
        }
    }
    unset($row);
}

// ── Generate diff for updated lists ───────────────────────────────────
function import_generate_diff(PDO $pdo, int $programId, array $newMemberIds, int $unmatchedRows = 0): array
{
    // Get current beneficiaries for this program from the most recent import
    $currentStmt = $pdo->prepare(
        "SELECT b.member_id, hm.first_name, hm.last_name, hm.birthdate,
                h.street, h.house_number
         FROM beneficiaries b
         JOIN household_members hm ON hm.member_id = b.member_id
         JOIN households h ON h.household_id = hm.household_id
         WHERE b.program_id = ? AND b.claim_status = 'existing'
           AND b.source_type = 'existing_beneficiary_list'"
    );
    $currentStmt->execute([$programId]);
    $currentMembers = $currentStmt->fetchAll();

    $currentIds = array_column($currentMembers, 'member_id');
    $newIds = array_filter($newMemberIds);

    $added   = array_values(array_diff($newIds, $currentIds));
    $removed = array_values(array_diff($currentIds, $newIds));
    $unchanged = array_values(array_intersect($currentIds, $newIds));

    // $unmatchedRows counts file rows that resolved to no resident at all
    // (not_found/error). One of them may simply be a current beneficiary
    // whose name spelling changed beyond the matcher's reach - retracting
    // "everyone absent from the file" would then silently drop someone who
    // is still on the list. When that ambiguity exists, retract nobody and
    // let the caller report it; removals only run on a fully-resolved file.
    $removalsSkipped = false;
    if ($unmatchedRows > 0 && !empty($removed)) {
        $removed = [];
        $removalsSkipped = true;
    }

    // Get details for removed members
    $removedDetails = [];
    if (!empty($removed)) {
        $ph = implode(',', array_fill(0, count($removed), '?'));
        $dStmt = $pdo->prepare(
            "SELECT hm.member_id, hm.first_name, hm.last_name, hm.birthdate
             FROM household_members hm WHERE hm.member_id IN ($ph)"
        );
        $dStmt->execute($removed);
        $removedDetails = $dStmt->fetchAll();
    }

    return [
        'added'       => $added,
        'removed'     => $removed,
        'unchanged'   => $unchanged,
        'removed_details' => $removedDetails,
        'current_count'   => count($currentIds),
        'new_count'       => count($newIds),
        'removals_skipped' => $removalsSkipped,
        'unmatched_rows'   => $unmatchedRows,
    ];
}

// ── Execute the import ────────────────────────────────────────────────
function import_execute(PDO $pdo, int $importId, int $programId, array $normalizedRows, int $userId): array
{
    $stats = [
        'imported'   => 0,
        'skipped'    => 0,
        'removed'    => 0,
        'errors'     => 0,
        // Per-column counters for beneficiary_imports. Kept separate from
        // the import/skip/error totals above because the four summary
        // columns mean four different things: rows that matched a resident
        // vs rows that didn't, rows already on this program's list, and
        // rows that genuinely threw. Conflating them wrote the same number
        // into two columns and called skipped rows "unmatched".
        'matched'    => 0,
        'unmatched'  => 0,
        'already'    => 0,
        // Re-added people: rows this program already had from an earlier
        // import but that had since been marked 'retracted' (removed from
        // the official list). Not persisted to beneficiary_imports - only
        // reported back to the admin in the completion message.
        'restored'   => 0,
    ];

    // ── Restore re-added members first ──────────────────────────────────
    // A previously removed person still has their row, just with
    // claim_status 'retracted' - the INSERT IGNORE below would skip it
    // forever, making "take someone off the list, then put them back"
    // impossible. Restore those rows in one guarded statement. The WHERE
    // clause keeps this strictly scoped: only imported rows
    // (source_type 'existing_beneficiary_list') that are currently
    // retracted, so live, approved, rejected and engine-detected records
    // are never overwritten.
    $restoreMemberIds = [];
    foreach ($normalizedRows as $row) {
        if (($row['_match_status'] ?? '') === 'matched' && !empty($row['_matched_member_id'])) {
            $restoreMemberIds[(int) $row['_matched_member_id']] = true;
        }
    }
    if ($restoreMemberIds) {
        $restoreIds = array_keys($restoreMemberIds);
        $ph = implode(',', array_fill(0, count($restoreIds), '?'));
        $restoreStmt = $pdo->prepare(
            "UPDATE beneficiaries
                SET claim_status = 'existing',
                    rejection_reason = NULL,
                    reviewed_by = NULL,
                    reviewed_at = NULL,
                    resident_seen_at = NULL
              WHERE program_id = ?
                AND source_type = 'existing_beneficiary_list'
                AND claim_status = 'retracted'
                AND member_id IN ($ph)"
        );
        $restoreStmt->execute(array_merge([$programId], $restoreIds));
        $stats['restored'] = $restoreStmt->rowCount();
    }

    $insertBeneficiary = $pdo->prepare(
        "INSERT IGNORE INTO beneficiaries
         (member_id, program_id, claim_status, source_type, import_batch_id, original_status, qualified_at)
         VALUES (?, ?, 'existing', 'existing_beneficiary_list', ?, ?, NOW())"
    );

    foreach ($normalizedRows as $row) {
        $memberId = $row['_matched_member_id'] ?? null;
        $matchStatus = $row['_match_status'] ?? 'error';

        if ($matchStatus !== 'matched' || $memberId === null) {
            $stats['unmatched']++;
            $stats['skipped']++;
            continue;
        }

        $stats['matched']++;
        try {
            $insertBeneficiary->execute([
                $memberId,
                $programId,
                $importId,
                $row['original_status'] ?? null,
            ]);
            if ($insertBeneficiary->rowCount() > 0) {
                $stats['imported']++;
            } else {
                $stats['already']++;
                $stats['skipped']++; // already exists
            }
        } catch (Exception $e) {
            $stats['errors']++;
            error_log("Import row error: " . $e->getMessage());
        }
    }

    // Update import record
    $pdo->prepare(
        "UPDATE beneficiary_imports
         SET status = 'completed', imported_by = ?, imported_at = NOW(),
             matched_rows = ?, unmatched_rows = ?, duplicate_rows = ?, error_rows = ?
         WHERE import_id = ?"
    )->execute([
        $userId,
        $stats['matched'],
        $stats['unmatched'],
        $stats['already'],
        $stats['errors'],
        $importId,
    ]);

    return $stats;
}

// ── Mark removed beneficiaries ────────────────────────────────────────
function import_mark_removed(PDO $pdo, int $programId, array $removedMemberIds, int $importId, int $userId): int
{
    if (empty($removedMemberIds)) return 0;

    $placeholders = implode(',', array_fill(0, count($removedMemberIds), '?'));
    $stmt = $pdo->prepare(
        "UPDATE beneficiaries
         SET claim_status = 'retracted',
             rejection_reason = 'Removed from current beneficiary list',
             reviewed_by = ?,
             reviewed_at = NOW(),
             resident_seen_at = NULL
         WHERE program_id = ?
           AND member_id IN ($placeholders)
           AND claim_status = 'existing'
           AND source_type = 'existing_beneficiary_list'"
    );
    $params = array_merge([$userId, $programId], $removedMemberIds);
    $stmt->execute($params);

    $removed = $stmt->rowCount();

    // Update import record with removed count
    if ($removed > 0) {
        $pdo->prepare(
            "UPDATE beneficiary_imports SET removed_rows = ? WHERE import_id = ?"
        )->execute([$removed, $importId]);
    }

    return $removed;
}
