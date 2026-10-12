<?php
/**
 * Imports residents from a Google Sheets CSV export into the census.
 *
 * Handles real-world census sheet quirks:
 *  - title/label rows above the column-name row are skipped - the header
 *    row is detected by looking for known column names, not assumed to be
 *    row 1
 *  - column names are matched flexibly (aliases like "Surname" for
 *    "Last Name", "DOB" for "Birthdate", "Gender" for "Sex", etc.)
 *  - a single "Full Name" / "Complete Name" column is split into
 *    last/first (either "Regala, Michael John" or "Michael John Regala")
 *  - households are identified the way users enter them in the census:
 *    by head of household + house number + street. A head row names a
 *    household, its members join that same household, and a re-import of
 *    the same family matches on the head instead of duplicating it. Street
 *    alone creates a new household when none exists yet (city defaults to
 *    Manila, matching the paper census)
 *  - residents already on file (same household + full name + birthdate)
 *    have their details updated instead of duplicated
 *  - flag columns accept yes/no, y/n, 1/0, x, checkmarks, or the word
 *    itself (e.g. "Employed", "PWD", "Solo Parent")
 *
 * Returns a summary array: imported/updated/skipped counts plus per-row
 * warnings and hard errors (all human-readable).
 */

require_once __DIR__ . '/../../shared/functions.php';
require_once __DIR__ . '/../../shared/eligibility.php';

function import_residents_csv(string $csvPath, PDO $pdo, int $staffUserId): array {
    $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'warnings' => [], 'errors' => []];

    $handle = @fopen($csvPath, 'r');
    if ($handle === false) {
        $result['errors'][] = 'Could not open the uploaded file.';
        return $result;
    }

    $rows = [];
    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if ($row === null || $row === [false]) {
            continue;
        }
        if (empty($rows)) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($row[0] ?? ''));
        }
        $rows[] = array_map(static function ($cell) {
            $cell = trim((string) $cell);
            return mb_check_encoding($cell, 'UTF-8') ? $cell : mb_convert_encoding($cell, 'UTF-8', 'Windows-1252');
        }, $row);
    }
    fclose($handle);

    if (count($rows) < 2) {
        $result['errors'][] = 'The file has no data rows.';
        return $result;
    }

    $headerIndex = detect_csv_header_row($rows);
    if ($headerIndex === null) {
        $result['errors'][] = 'Could not find the header row. The sheet needs a row with column names like "Last Name", "First Name", "Birthdate", "Street", "Sex", etc.';
        return $result;
    }
    $columns = map_csv_columns($rows[$headerIndex]);

    $householdCache = [];
    $headNamesByAddress = [];    // first head row found for each address (pre-scan)
    $currentHeadForAddress = []; // head most recently seen while streaming rows
    $householdHasHead = [];
    $batchHeads = [];
    $hasHeadColumn = ($columns['head'] ?? null) !== null || ($columns['relationship'] ?? null) !== null;
    $hasEmploymentColumn = ($columns['employment'] ?? null) !== null;
    $affectedHouseholds = [];

    // Pre-scan: resolve each address's head row BEFORE the main pass. Real
    // sheets often list members above the head row; without this those rows
    // cannot find their family and spawn a duplicate household at the same
    // address, after which every later dedupe lookup misses too.
    foreach ($rows as $i => $row) {
        if ($i <= $headerIndex) {
            continue;
        }
        $preLine = csv_extract_line($row, $columns);
        if (implode('', array_values($preLine)) === '') {
            continue;
        }
        csv_split_names($preLine);
        if ($preLine['last_name'] === '' || $preLine['first_name'] === '') {
            continue;
        }
        csv_normalize_names($preLine);
        if (!csv_row_is_head($preLine, $hasHeadColumn)) {
            continue;
        }
        $preStreet = collapse_spaces($preLine['street'] ?? '');
        if ($preStreet === '') {
            continue;
        }
        $preAddressKey = mb_strtolower($preStreet) . '|' . mb_strtolower(collapse_spaces($preLine['house_number'] ?? ''));
        $headNamesByAddress[$preAddressKey] ??= [
            'last'  => $preLine['last_name'],
            'first' => $preLine['first_name'],
        ];
    }

    foreach ($rows as $i => $row) {
        if ($i <= $headerIndex) {
            continue;
        }
        $num = $i + 1;
        $line = csv_extract_line($row, $columns);

        if (implode('', array_values($line)) === '') {
            continue;
        }

        // ---- Name ---------------------------------------------------------
        csv_split_names($line);
        if ($line['last_name'] === '' || $line['first_name'] === '') {
            $result['skipped']++;
            $result['warnings'][] = "Row {$num}: missing first or last name - skipped.";
            continue;
        }
        csv_normalize_names($line);
        if (!empty($line['place_of_birth'])) {
            $line['place_of_birth'] = normalize_label($line['place_of_birth']);
        }
        if (!empty($line['civil_status'])) {
            $line['civil_status'] = normalize_civil_status($line['civil_status']);
        }
        $occupationIsNone = false;
        if (!empty($line['occupation'])) {
            $occupationIsNone = is_none_occupation($line['occupation']);
            $line['occupation'] = normalize_occupation($line['occupation']);
        }

        // ---- Head of household ---------------------------------------------
        $isHead = csv_row_is_head($line, $hasHeadColumn);

        // ---- Household (head + house number + street identify it) -----------
        $houseNumber = collapse_spaces($line['house_number'] ?? '');
        $street = collapse_spaces($line['street'] ?? '');
        if ($street === '') {
            $result['skipped']++;
            $result['warnings'][] = "Row {$num}: no street/address for {$line['first_name']} {$line['last_name']} - skipped.";
            continue;
        }
        // The head of household is part of what makes a household distinct:
        // a head row names the household, member rows follow their head, and
        // re-imports of the same family match on that head rather than on the
        // address alone. The pre-scan map covers members listed ABOVE their
        // head row; the streaming map preserves the original behaviour where
        // a later head row for the same address starts a second family.
        $addressKey = mb_strtolower($street) . '|' . mb_strtolower($houseNumber);
        if ($isHead) {
            $ownHead = ['last' => $line['last_name'], 'first' => $line['first_name']];
            $currentHeadForAddress[$addressKey] = $ownHead;
            $headForLookup = $ownHead;
        } else {
            $headForLookup = $currentHeadForAddress[$addressKey] ?? $headNamesByAddress[$addressKey] ?? null;
        }
        $headNameKey = $headForLookup !== null
            ? mb_strtolower($headForLookup['last']) . '|' . mb_strtolower($headForLookup['first'])
            : '';
        $cacheKey = $addressKey . '|' . $headNameKey;
        if (!isset($householdCache[$cacheKey])) {
            $hid = false;
            if ($headForLookup !== null) {
                // Re-import of the same family: find the household this head
                // person already heads at this address. The lookup must use
                // the HEAD's name - for member rows above, passing the
                // member's own name here looks for a household that member
                // heads, finds none, and splits the family.
                $stmt = $pdo->prepare(
                    'SELECT hm.household_id
                     FROM household_members hm
                     JOIN households h ON h.household_id = hm.household_id
                     WHERE h.street = ? AND h.house_number <=> ?
                       AND hm.is_household_head = TRUE AND hm.is_archived = FALSE
                       AND hm.last_name = ? AND hm.first_name = ?
                     LIMIT 1'
                );
                $stmt->execute([$street, $houseNumber !== '' ? $houseNumber : null, $headForLookup['last'], $headForLookup['first']]);
                $hid = $stmt->fetchColumn();
            }
            if ($hid === false && !$hasHeadColumn) {
                // The sheet carries no head column at all: the address alone
                // identifies the household, so join whatever family is already
                // on file there. Without this, every re-import of a headless
                // sheet created a fresh household for the same address.
                $stmt = $pdo->prepare(
                    'SELECT household_id
                     FROM households
                     WHERE street = ? AND house_number <=> ?
                     LIMIT 1'
                );
                $stmt->execute([$street, $houseNumber !== '' ? $houseNumber : null]);
                $hid = $stmt->fetchColumn();
            }
            if ($hid === false) {
                // No head to match yet: only join an existing household at this
                // address that still has no head on file - never a household
                // owned by a different head.
                $stmt = $pdo->prepare(
                    'SELECT h.household_id
                     FROM households h
                     WHERE h.street = ? AND h.house_number <=> ?
                       AND NOT EXISTS (
                           SELECT 1 FROM household_members x
                           WHERE x.household_id = h.household_id
                             AND x.is_household_head = TRUE AND x.is_archived = FALSE
                       )
                     LIMIT 1'
                );
                $stmt->execute([$street, $houseNumber !== '' ? $houseNumber : null]);
                $hid = $stmt->fetchColumn();
            }
            if ($hid === false) {
                $pdo->prepare('INSERT INTO households (house_number, street, city, no_of_members) VALUES (?, ?, ?, 0)')
                    ->execute([$houseNumber !== '' ? $houseNumber : null, $street, 'Manila']);
                $hid = (int) $pdo->lastInsertId();
            }
            $householdCache[$cacheKey] = (int) $hid;
        }
        $householdId = $householdCache[$cacheKey];

        // ---- Birthdate / sex / citizenship -------------------------------
        $birthdate = parse_csv_date($line['birthdate'] ?? '');
        $sex = normalize_csv_sex($line['sex'] ?? '');
        $citizenship = $line['citizenship'] !== '' ? normalize_label($line['citizenship']) : 'Filipino';

        if ($line['birthdate'] !== '' && $birthdate === null) {
            $result['warnings'][] = "Row {$num}: could not read birthdate \"{$line['birthdate']}\" for {$line['first_name']} {$line['last_name']} - left blank.";
        }

        // ---- Flags ---------------------------------------------------------
        // A flag is only written when the sheet actually carries a value for
        // it: an absent column or a blank cell means "no information in this
        // file", not "cleared". Blank-as-false used to reset all eight
        // eligibility flags for every matched resident on a re-import whose
        // sheet omitted those columns - silently stripping e.g. PWD status
        // and re-running eligibility against the wiped data in the same
        // request.
        $flags = [];
        $flagProvided = [];
        $flagCols = [
            'pwd'         => ['pwd', 'disability', 'disabled'],
            'solo_parent' => ['solo parent', 'single parent'],
            'ofw'         => ['ofw', 'overseas'],
            'osy'         => ['osy', 'out of school'],
            'ip'          => ['indigenous'],
            'unemployed'  => ['unemployed'],
            'employed'    => ['employed'],
            'student'     => ['student'],
        ];
        foreach ($flagCols as $field => $keywords) {
            $flagCell = $line[$field] ?? '';
            $flagProvided[$field] = ($columns[$field] ?? null) !== null && $flagCell !== '';
            $flags[$field] = csv_flag_value($flagCell, $keywords);
        }
        if ($occupationIsNone) {
            $flags['unemployed'] = true;
            $flags['employed'] = false;
            $flagProvided['unemployed'] = true;
            $flagProvided['employed'] = true;
        }
        if ($hasEmploymentColumn) {
            $emp = mb_strtolower($line['employment'] ?? '');
            if ($emp !== '') {
                $flags['unemployed'] = str_contains($emp, 'unemployed');
                $flags['employed'] = str_contains($emp, 'employed') && !str_contains($emp, 'unemployed');
                $flags['student'] = str_contains($emp, 'student');
                $flagProvided['unemployed'] = true;
                $flagProvided['employed'] = true;
                $flagProvided['student'] = true;
            }
        }

        // ---- Existing resident? Update; otherwise insert -------------------
        // Exact match first (household + name + birthdate), then a name-only
        // fallback: a blank/unreadable birthdate cell or a corrected DOB must
        // not insert a second copy of someone already on file.
        $dupStmt = $pdo->prepare(
            'SELECT member_id, is_archived FROM household_members
             WHERE household_id = ? AND last_name = ? AND first_name = ? AND birthdate <=> ?
             LIMIT 1'
        );
        $dupStmt->execute([$householdId, $line['last_name'], $line['first_name'], $birthdate]);
        $existingRow = $dupStmt->fetch();
        if ($existingRow === false) {
            $dupStmt = $pdo->prepare(
                'SELECT member_id, is_archived FROM household_members
                 WHERE household_id = ? AND last_name = ? AND first_name = ?
                 LIMIT 1'
            );
            $dupStmt->execute([$householdId, $line['last_name'], $line['first_name']]);
            $existingRow = $dupStmt->fetch();
        }

        if ($existingRow !== false) {
            if ((bool) $existingRow['is_archived']) {
                // An archived member must neither be silently updated (they
                // stay archived and invisible while being counted as
                // "updated") nor re-inserted as a fresh duplicate.
                $result['skipped']++;
                $result['warnings'][] = "Row {$num}: {$line['first_name']} {$line['last_name']} is archived on file - left untouched (restore them from the Residents page to update).";
                continue;
            }
            $existingId = (int) $existingRow['member_id'];

            // Only write the columns this row actually provides. A sheet that
            // omits (or leaves blank) a column used to NULL it out / reset it
            // for every matched resident - wiping sex, civil status and all
            // eight eligibility flags with empty cells.
            $sets = [];
            $params = [];
            $put = static function (string $column, $value) use (&$sets, &$params): void {
                if ($value !== null && $value !== '') {
                    $sets[] = $column . ' = ?';
                    $params[] = $value;
                }
            };
            $put('middle_name', $line['middle_name'] !== '' ? $line['middle_name'] : null);
            $put('suffix', $line['suffix'] !== '' ? $line['suffix'] : null);
            $put('place_of_birth', $line['place_of_birth'] !== '' ? $line['place_of_birth'] : null);
            $put('birthdate', $birthdate);
            $put('sex', $sex);
            $put('civil_status', $line['civil_status'] !== '' ? $line['civil_status'] : null);
            $put('citizenship', $line['citizenship'] !== '' ? normalize_label($line['citizenship']) : null);
            $put('occupation', $line['occupation'] !== '' ? $line['occupation'] : null);
            foreach (array_keys($flagCols) as $field) {
                if ($flagProvided[$field]) {
                    $sets[] = 'is_' . $field . ' = ?';
                    $params[] = $flags[$field] ? 1 : 0;
                }
            }
            if ($isHead) {
                // Exactly one head per household: demote whoever currently
                // holds the flag before promoting this row's member.
                $pdo->prepare(
                    'UPDATE household_members SET is_household_head = FALSE
                     WHERE household_id = ? AND member_id <> ? AND is_household_head = TRUE'
                )->execute([$householdId, $existingId]);
                $sets[] = 'is_household_head = TRUE';
            }

            if ($sets !== []) {
                $params[] = $existingId;
                $pdo->prepare('UPDATE household_members SET ' . implode(', ', $sets) . ' WHERE member_id = ?')
                    ->execute($params);
                $result['updated']++;
            } else {
                $result['skipped']++;
                $result['warnings'][] = "Row {$num}: {$line['first_name']} {$line['last_name']} matched an existing record but the row carried no values to update - skipped.";
            }
        } else {
            if (!$hasHeadColumn && empty($batchHeads[$householdId])) {
                if (!isset($householdHasHead[$householdId])) {
                    $householdHasHead[$householdId] = (bool) $pdo->query(
                        "SELECT COUNT(*) FROM household_members WHERE household_id = {$householdId} AND is_household_head = TRUE AND is_archived = FALSE"
                    )->fetchColumn();
                }
                if (!$householdHasHead[$householdId]) {
                    $isHead = true;
                    $householdHasHead[$householdId] = true;
                }
            }
            $batchHeads[$householdId] = true;

            if ($isHead) {
                // Demote any previous head first so the household never ends
                // up with two heads (downstream lookups take LIMIT 1 and
                // would pick arbitrarily).
                $pdo->prepare(
                    'UPDATE household_members SET is_household_head = FALSE
                     WHERE household_id = ? AND is_household_head = TRUE'
                )->execute([$householdId]);
            }

            $pdo->prepare(
                'INSERT INTO household_members
                    (household_id, last_name, first_name, middle_name, suffix, place_of_birth,
                     birthdate, sex, civil_status, citizenship, occupation, is_household_head,
                     is_pwd, is_solo_parent, is_ofw, is_osy, is_ip, is_unemployed, is_employed, is_student)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $householdId, $line['last_name'], $line['first_name'],
                $line['middle_name'] !== '' ? $line['middle_name'] : null,
                $line['suffix'] !== '' ? $line['suffix'] : null,
                $line['place_of_birth'] !== '' ? $line['place_of_birth'] : null,
                $birthdate, $sex, $line['civil_status'] !== '' ? $line['civil_status'] : null,
                $citizenship, $line['occupation'] !== '' ? $line['occupation'] : null,
                $isHead ? 1 : 0,
                $flags['pwd'] ? 1 : 0, $flags['solo_parent'] ? 1 : 0, $flags['ofw'] ? 1 : 0,
                $flags['osy'] ? 1 : 0, $flags['ip'] ? 1 : 0, $flags['unemployed'] ? 1 : 0,
                $flags['employed'] ? 1 : 0, $flags['student'] ? 1 : 0,
            ]);
            $result['imported']++;
        }
        $affectedHouseholds[$householdId] = true;
    }

    foreach (array_keys($affectedHouseholds) as $hid) {
        $pdo->prepare(
            'UPDATE households SET no_of_members = (SELECT COUNT(*) FROM household_members WHERE household_id = ? AND is_archived = FALSE) WHERE household_id = ?'
        )->execute([$hid, $hid]);
        try {
            evaluate_household_eligibility($pdo, $hid, $staffUserId);
        } catch (Exception $e) {
            error_log('Eligibility re-check failed after CSV import: ' . $e->getMessage());
        }
    }

    if ($result['imported'] > 0 || $result['updated'] > 0) {
        log_activity($pdo, $staffUserId, "Spreadsheet import: {$result['imported']} added, {$result['updated']} updated, {$result['skipped']} skipped", $result['imported'] > 0 ? 'create' : 'update');
    }

    return $result;
}

/** Returns the index of the first row that looks like a column-name row. */
function detect_csv_header_row(array $rows): ?int {
    $known = array_merge([], ...array_values(csv_column_alias_map()));
    foreach ($rows as $i => $row) {
        $hits = 0;
        foreach ($row as $cell) {
            $norm = normalize_header_name($cell);
            if ($norm !== '' && in_array($norm, $known, true)) {
                $hits++;
            }
        }
        if ($hits >= 2) {
            return $i;
        }
    }
    return null;
}

/** Maps column names in a header row to internal field names (null = unused). */
function map_csv_columns(array $header): array {
    $aliases = csv_column_alias_map();
    $map = [];
    foreach (array_keys($aliases) as $field) {
        $map[$field] = null;
    }
    foreach ($header as $idx => $cell) {
        $norm = normalize_header_name($cell);
        if ($norm === '') {
            continue;
        }
        foreach ($aliases as $field => $names) {
            if ($map[$field] === null && in_array($norm, $names, true)) {
                $map[$field] = $idx;
                break;
            }
        }
    }
    return $map;
}

/** Lowercases and strips punctuation so "Last Name:" and "LAST NAME" match. */
function normalize_header_name(?string $name): string {
    $clean = strtolower(trim((string) $name));
    $clean = str_replace([':', '.', '(', ')', '/', '\\'], ' ', $clean);
    return preg_replace('/\s+/', ' ', $clean);
}

function csv_column_alias_map(): array {
    return [
        'last_name'    => ['last name', 'lastname', 'surname', 'family name', 'apelyido', 'apellido'],
        'first_name'   => ['first name', 'firstname', 'given name', 'pangalan', 'nombre'],
        'middle_name'  => ['middle name', 'middlename', 'middle', 'maiden name'],
        'suffix'       => ['suffix', 'name ext', 'name extension', 'ext', 'extension'],
        'full_name'    => ['full name', 'complete name', 'whole name', 'name'],
        'place_of_birth' => ['place of birth', 'birthplace', 'birth place', 'born at'],
        'birthdate'    => ['birthdate', 'date of birth', 'dob', 'birth date', 'birthday', 'birth day', 'date born', 'born'],
        'sex'          => ['sex', 'gender'],
        'civil_status' => ['civil status', 'marital status', 'civil'],
        'citizenship'  => ['citizenship', 'nationality'],
        'occupation'   => ['occupation', 'job', 'work', 'profession'],
        'house_number' => ['house number', 'house no', 'house no.', 'housenumber', 'house #'],
        'street'       => ['street', 'street address', 'address', 'home address', 'permanent address', 'complete address', 'house address'],
        'head'         => ['head of household', 'household head', 'head', 'hoh'],
        'relationship' => ['relation to head', 'relationship to head', 'relationship'],
        'employment'   => ['employment status', 'employment'],
        'pwd'          => ['pwd', 'person with disability', 'with disability', 'disability'],
        'solo_parent'  => ['solo parent', 'single parent'],
        'ofw'          => ['ofw', 'overseas filipino worker'],
        'osy'          => ['osy', 'out of school youth', 'out-of-school youth'],
        'ip'           => ['indigenous person', 'indigenous'],
        'unemployed'   => ['unemployed'],
        'employed'     => ['employed'],
        'student'      => ['student'],
    ];
}

/** Yes/no-ish cells plus cells whose text matches the flag's own keywords. */
function csv_flag_value(?string $value, array $keywords): bool {
    $low = mb_strtolower(trim((string) $value));
    if ($low === '') {
        return false;
    }
    if (in_array($low, ['yes', 'y', 'true', '1', 'x', 't', '✓', '✔', 'check', 'checked'], true)) {
        return true;
    }
    if (in_array($low, ['no', 'n', 'false', '0', 'none', 'n/a', 'na', '-', '—'], true)) {
        return false;
    }
    foreach ($keywords as $kw) {
        if (str_contains($low, $kw)) {
            return true;
        }
    }
    return false;
}

function normalize_csv_sex(?string $value): ?string {
    $low = strtolower(trim((string) $value));
    if (in_array($low, ['male', 'm'], true)) {
        return 'Male';
    }
    if (in_array($low, ['female', 'f'], true)) {
        return 'Female';
    }
    return null;
}

function parse_csv_date(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d', 'm/d/Y', 'm-d-Y', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'j M Y', 'F j, Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $value);
        if ($d && $d->format($fmt) === $value) {
            return is_implausible_birthdate($d) ? null : $d->format('Y-m-d');
        }
    }
    // Numeric dates that failed every strict round-trip above are impossible
    // dates PHP would silently roll over (02/30/2020 -> 2020-03-01), and a
    // bare year has no day at all: reject both instead of inventing a
    // birthdate. The caller warns and leaves the cell blank, and the
    // name-only dedupe fallback keeps that from duplicating the person.
    if (preg_match('/^\d{3,4}$/', $value) || preg_match('/^\d{1,4}[\/-]\d{1,2}[\/-]\d{1,4}/', $value)) {
        return null;
    }
    $t = strtotime($value);
    if ($t !== false && $t > 0 && preg_match('/\b(?:19|20)\d{2}\b/', $value, $ym)) {
        if ((int) date('Y', $t) !== (int) $ym[0]) {
            return null;
        }
        return is_implausible_birthdate(new DateTime('@' . $t)) ? null : date('Y-m-d', $t);
    }
    return null;
}

function collapse_spaces(string $value): string {
    return preg_replace('/\s+/', ' ', trim($value));
}

/** Field extraction for one CSV row against the detected column map. */
function csv_extract_line(array $row, array $columns): array {
    $line = [];
    foreach ($columns as $field => $idx) {
        $line[$field] = ($idx !== null && isset($row[$idx])) ? trim((string) $row[$idx]) : '';
    }
    return $line;
}

/**
 * Fills last/first/middle name from either dedicated name columns or a
 * single "Full Name" / "Complete Name" cell ("Regala, Michael John" or
 * "Michael John Regala").
 */
function csv_split_names(array &$line): void {
    if ($line['last_name'] !== '' && $line['first_name'] !== '') {
        return;
    }
    $fn = trim($line['full_name'] ?? '');
    if ($fn === '') {
        return;
    }
    if (str_contains($fn, ',')) {
        [$ln, $gn] = array_pad(explode(',', $fn, 2), 2, '');
        $line['last_name'] = trim($ln);
        $line['first_name'] = trim($gn);
    } else {
        $tokens = preg_split('/\s+/', $fn);
        $line['last_name'] = (string) array_pop($tokens);
        $line['first_name'] = implode(' ', $tokens);
    }
}

/**
 * Same casing/spacing normalization as manual entry (resident-add.php /
 * resident-edit.php), so a CSV typed in ALL CAPS or lowercase ends up
 * stored the same canonical way as everything entered through the forms.
 */
function csv_normalize_names(array &$line): void {
    $line['last_name'] = normalize_person_name($line['last_name']);
    $line['first_name'] = normalize_person_name($line['first_name']);
    if (!empty($line['middle_name'])) {
        $line['middle_name'] = normalize_person_name($line['middle_name']);
    }
    if (!empty($line['suffix'])) {
        $line['suffix'] = normalize_suffix($line['suffix']);
    }
}

/** Whether this row is marked as head of household (via head or relationship column). */
function csv_row_is_head(array $line, bool $hasHeadColumn): bool {
    if (!$hasHeadColumn) {
        return false;
    }
    $headVal = mb_strtolower($line['head'] ?? '');
    $relVal = mb_strtolower($line['relationship'] ?? '');
    return csv_flag_value($headVal, ['head'])
        || ($relVal !== '' && str_contains($relVal, 'head'));
}
