<?php
/**
 * Small helpers shared by both portals. These assume a session has already
 * been started by the portal-specific bootstrap (resident/includes/session.php,
 * or its future admin/ equivalent) before any of them are called.
 */

function flash(string $key, array|string|null $message = null): array|string|null {
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (isset($_SESSION['flash'][$key])) {
        $value = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $value;
    }

    return null;
}

/** Re-populate a text field after a failed submission, safely escaped. */
function old(string $field, array $previous, string $default = ''): string {
    return htmlspecialchars($previous[$field] ?? $default, ENT_QUOTES, 'UTF-8');
}

/**
 * Builds "12 Unit 3B Mabini St., Barangay 420, Manila" from a row that has
 * house_number / street / barangay / city keys. Falls back gracefully for
 * legacy rows whose parts are missing.
 */
function format_household_address(array $row): string {
    $parts = [];
    $line = trim(($row['house_number'] ?? '') . ' ' . ($row['street'] ?? ''));
    if ($line !== '') {
        $parts[] = $line;
    }
    if (!empty($row['barangay'])) {
        $parts[] = $row['barangay'];
    }
    if (!empty($row['city'])) {
        $parts[] = $row['city'];
    }
    return implode(', ', $parts);
}

function redirect(string $path): void {
    // Header injection guard: Location can never contain a newline.
    if (preg_match('/[\r\n]/', $path)) {
        $path = '/';
    }
    http_response_code(302);
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool {
    return $token !== null
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Tells the browser (and any caching layer in between) never to store
 * this response. Pages showing live status - benefit applications,
 * beneficiary review queues - need this so navigating back to them, or a
 * host's own page cache, never shows a stale snapshot from before the
 * last update instead of the current database state.
 */
function no_cache(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

/** Shorthand escaping for anything printed into HTML. */
function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Formats a stored 'Y-m-d H:i:s' (or any parseable) datetime into a compact
 * "Aug 5, 2026 9:00 AM" label for reading, or returns null for blank/unset
 * values so callers can fall back to something else.
 */
function fmt_dt(?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts !== false ? date('M j, Y g:i A', $ts) : null;
}

/**
 * Records one activity log entry. $category must be one of
 * create / read / update / delete / validate - pass an explicit bucket at
 * every call site so the Logs page filter is accurate; anything else falls
 * back to 'read' so a typo can never throw or lose the entry.
 */
function log_activity(PDO $pdo, ?int $userId, string $action, string $category = 'read'): void {
    $category = in_array($category, ['create', 'read', 'update', 'delete', 'validate'], true) ? $category : 'read';
    // activity_logs.action is VARCHAR(255); several call sites embed
    // user-supplied text (rejection reasons, program names). Without this
    // cap a long reason threw SQLSTATE 22001 AFTER the real change had
    // already committed - the admin saw a 500 for work that had succeeded.
    $pdo->prepare('INSERT INTO activity_logs (user_id, action, category) VALUES (?, ?, ?)')
        ->execute([$userId, mb_substr($action, 0, 255), $category]);
}

/**
 * Neutralizes spreadsheet formula injection in one CSV cell (OWASP): a value
 * starting with = + - @ TAB or CR would be evaluated by Excel/Sheets when the
 * exported file is opened, so a resident-entered name such as
 * =HYPERLINK("http://evil","click") or =cmd|'/C calc'!A0 could execute on the
 * admin's machine. A leading apostrophe forces every spreadsheet to treat the
 * cell as plain text (and also keeps phone numbers like +639... from being
 * reformatted as numbers).
 */
function csv_guard(string $value): string {
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Normalizes a Philippine mobile number typed in any common format -
 * with spaces, dashes, parentheses, a +63/63 country code, and (rarely)
 * an accidental leading/trailing character from autocorrect or a paste -
 * into the plain 09XXXXXXXXX form the rest of the app stores. Returns
 * null if what's left still isn't a valid PH mobile number, so callers
 * can tell "fixed it" apart from "genuinely invalid" instead of the
 * previous plain regex rejecting anything that wasn't already in the
 * exact 09XXXXXXXXX shape - which is why residents kept seeing "Enter a
 * valid 11-digit number" for numbers that were actually fine.
 */
function normalize_ph_mobile(string $input): ?string {
    $digits = preg_replace('/[^0-9]/', '', $input);

    // +63 912 345 6789 or 63 912 345 6789 -> strip the country code and
    // put the local trunk 0 back on.
    if (preg_match('/^639\d{9}$/', $digits)) {
        $digits = '0' . substr($digits, 2);
    }

    return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
}

/**
 * Normalizes an email address for storage: trims surrounding whitespace
 * and lowercases everything, so "  User@Example.COM " and
 * "user@example.com" are treated as the same account. Lowercasing on write
 * pairs with the case-insensitive lookup in both login queries.
 */
function normalize_email(string $input): string {
    return mb_strtolower(trim($input));
}

/**
 * Collapses whitespace and title-cases a short label or address, while
 * leaving the pieces that must never be title-cased intact:
 *  - known acronyms (OFW, PWD, SK, DSWD, ...) stay in capitals
 *  - letters glued to a digit are kept uppercase ("unit 3b" -> "Unit 3B")
 *  - letters after an apostrophe or hyphen are capitalized
 *    ("o'brien" -> "O'Brien", "cruz-santos" -> "Cruz-Santos")
 *
 * Used for addresses, place of birth, occupations, official positions,
 * program types and event titles - anywhere the database should hold the
 * same canonical form no matter how the user happened to type it.
 */
function normalize_label(string $input): string {
    $trimmed = trim(preg_replace('/\s+/', ' ', $input));
    if ($trimmed === '') {
        return $trimmed;
    }
    $acronyms = ['OFW', 'OSY', 'PWD', 'IT', 'HR', 'RN', 'RT', 'LPT', 'CPA', 'BPO',
                 'DSWD', 'DOH', 'DILG', 'LGU', 'SK', 'NGA', 'PSA', 'LTO', 'PCSO', 'PAO'];
    $parts = preg_split('/\s+/', $trimmed);
    $out = [];
    foreach ($parts as $word) {
        $lettersAndDigits = preg_replace('/[^\p{L}\p{N}]/u', '', $word);
        $upper = $lettersAndDigits !== '' ? mb_strtoupper($lettersAndDigits, 'UTF-8') : '';
        if ($upper !== '' && in_array($upper, $acronyms, true)) {
            // Known acronym, re-uppercased while keeping its punctuation.
            $out[] = preg_replace_callback(
                '/\p{L}+/u',
                fn ($m) => mb_strtoupper($m[0], 'UTF-8'),
                $word
            );
            continue;
        }
        $titled = function_exists('mb_convert_case')
            ? mb_convert_case($word, MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($word));
        // "3b" -> "3B", "blk12a" -> "Blk12A": capitalize letters after a digit.
        $titled = preg_replace_callback(
            '/(?<=\d)(\p{L}+)/u',
            fn ($m) => mb_strtoupper($m[0], 'UTF-8'),
            $titled
        );
        $titled = preg_replace_callback(
            "/(['\\-])(\\p{L})/u",
            fn ($m) => $m[1] . mb_strtoupper($m[2], 'UTF-8'),
            $titled
        );
        $out[] = $titled;
    }
    return implode(' ', $out);
}

/**
 * The literal "none" markers people type in free-text fields when they have
 * no answer ("N/A", "none", "-", ...). Shared by the occupation normalizer
 * and the forms that decide whether a none entry counts as Unemployed.
 */
function occupation_none_markers(): array {
    return ['na', 'n/a', 'nona', 'none', 'nil', 'nooccupation',
            'notapplicable', 'ndeclared', 'unspecified', '-', '--', '—'];
}

/** True if the input is one of the explicit "none in occupation" markers. */
function is_none_occupation(string $input): bool {
    $trimmed = trim(preg_replace('/\s+/', ' ', $input));
    if ($trimmed === '') {
        return false;
    }
    $key = mb_strtolower(preg_replace('/[.\s]/', '', $trimmed));
    return in_array($key, occupation_none_markers(), true);
}

/**
 * Normalizes an occupation/job field. The common "none" markers that people
 * type when they have no listed occupation - "N/A", "none", "-", etc. - come
 * back as an empty string, the same as leaving the field blank. Anything with
 * real content goes through the standard label casing. This keeps "N/A"
 * distinct from an actual job title, so only a real occupation can ever
 * count as Employed.
 */
function normalize_occupation(string $input): string {
    $trimmed = trim(preg_replace('/\s+/', ' ', $input));
    if ($trimmed === '') {
        return $trimmed;
    }
    if (is_none_occupation($trimmed)) {
        return '';
    }
    return normalize_label($trimmed);
}

/**
 * Normalizes the standard Filipino name extensions ("jr.", "J.R.", "JUNIOR",
 * "II", "iii", ...) into one canonical short form. Anything outside the
 * known set is returned with whitespace collapsed but otherwise untouched.
 */
function normalize_suffix(string $input): string {
    $trimmed = trim(preg_replace('/\s+/', ' ', $input));
    if ($trimmed === '') {
        return $trimmed;
    }
    $low = mb_strtolower(preg_replace('/[\s.]/', '', $trimmed));
    $map = [
        'jr' => 'Jr.', 'junior' => 'Jr.', 'sr' => 'Sr.', 'senior' => 'Sr.',
        'i' => 'I', 'ii' => 'II', 'iii' => 'III',
        'iv' => 'IV', 'v' => 'V', 'vi' => 'VI', 'vii' => 'VII',
    ];
    return $map[$low] ?? $trimmed;
}

/**
 * Maps every common way a civil status gets typed - CSV exports most often,
 * e.g. "married", "Widow", "legally separated", "divorced" - to the canonical
 * labels the forms use. Select-driven forms already send those labels, so
 * they pass through unchanged.
 */
function normalize_civil_status(string $input): string {
    $trimmed = trim(preg_replace('/\s+/', ' ', $input));
    if ($trimmed === '') {
        return $trimmed;
    }
    $low = mb_strtolower($trimmed);
    $exact = [
        'single' => 'Single',
        'married' => 'Married',
        'widowed' => 'Widowed', 'widow' => 'Widowed', 'widower' => 'Widowed',
        'separated' => 'Separated', 'divorced' => 'Separated', 'divorce' => 'Separated',
        'separated/divorced' => 'Separated', 'legally separated' => 'Separated',
        'annulled' => 'Annulled', 'annulment' => 'Annulled',
    ];
    if (isset($exact[$low])) {
        return $exact[$low];
    }
    if (str_contains($low, 'separ')) { return 'Separated'; }
    if (str_contains($low, 'marri')) { return 'Married'; }
    if (str_contains($low, 'widow')) { return 'Widowed'; }
    if (str_contains($low, 'annul')) { return 'Annulled'; }
    return $trimmed;
}

/**
 * @deprecated Middle Name standardization: all future input uses
 * normalize_person_name() on the full middle name. Retained only so any
 * external caller does not fatal; new code must not call this. Stored
 * single-letter records are preserved exactly as they are (no rewrite).
 *
 * Normalizes a middle initial: collapses whitespace, drops a trailing period
 * and uppercases the single letter, so "m", "M." and "M" all store as "M".
 * Anything longer than one letter is treated as a name portion and goes
 * through the normal person-name title-casing.
 */
function normalize_name_initial(string $input): string {
    $trimmed = trim(preg_replace('/\s+/', ' ', $input));
    if ($trimmed === '') {
        return $trimmed;
    }
    $trimmed = rtrim($trimmed, '.');
    if (mb_strlen($trimmed, 'UTF-8') === 1) {
        return mb_strtoupper($trimmed, 'UTF-8');
    }
    return normalize_person_name($trimmed);
}


/**
 * Normalizes a person's name for storage: collapses stray whitespace and
 * converts to title case, so "JUAN DELA CRUZ", "juan dela cruz", and
 * "Juan   Dela  Cruz" all end up stored the same, consistent way instead
 * of whatever casing the resident (or a CSV import) happened to type.
 *
 * Handles the punctuation Filipino names actually contain:
 *  - apostrophes: "o'brien" -> "O'Brien" (capital on both sides)
 *  - hyphens: "cruz-santos" -> "Cruz-Santos"
 *  - periods in suffixes: "jr." -> "Jr."
 * This is intentionally simple title-casing, not a full name-parsing
 * library - it will not special-case every possible prefix (e.g. "Mc"/
 * "Mac" surnames), but it is a clear improvement over storing whatever
 * capitalization was typed, and never invents or removes any letters.
 * Leave blank/empty input as-is so optional fields stay optional.
 */
function normalize_person_name(string $input): string {
    $trimmed = trim(preg_replace('/\s+/', ' ', $input));
    if ($trimmed === '') {
        return $trimmed;
    }
    // mbstring is present on virtually every modern host (XAMPP ships it
    // enabled by default, as does cPanel/shared hosting), but fall back to
    // a plain ASCII title-case rather than fatal-erroring the whole
    // registration/resident form if it's ever missing.
    if (function_exists('mb_convert_case')) {
        $titled = mb_convert_case($trimmed, MB_CASE_TITLE, 'UTF-8');
    } else {
        $titled = ucwords(strtolower($trimmed));
    }
    // Re-capitalize the letter right after an apostrophe or hyphen, which
    // MB_CASE_TITLE does not do on its own (it only capitalizes after
    // whitespace).
    $titled = preg_replace_callback(
        "/(['\\-])(\\p{L})/u",
        function ($m) { return $m[1] . mb_strtoupper($m[2], 'UTF-8'); },
        $titled
    );
    return $titled;
}

/**
 * True if a birthdate is impossible or absurd - in the future, or implying
 * an age over 120. Used alongside (not instead of) the existing
 * future-date check, to catch data-entry slips like a typo'd year
 * ("0009-09-09") that a bare future-date check wouldn't catch.
 */
function is_implausible_birthdate(DateTime $birthdate): bool {
    $today = new DateTime('today');
    if ($birthdate > $today) {
        return true;
    }
    return $birthdate->diff($today)->y > 120;
}

/**
 * Human "time ago" label ("just now", "5 minutes ago", ...) used by the
 * admin dashboard/system views. Falls back to an absolute
 * date once the gap is older than a week. Lives here (not in system.php)
 * so every admin page that needs it gets it without depending on another
 * page being loaded first.
 */
function relative_time(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }
    $diff = time() - $ts;
    if ($diff < 60) { return 'just now'; }
    if ($diff < 3600) { $m = (int) floor($diff / 60); return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
    if ($diff < 86400) { $h = (int) floor($diff / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    if ($diff < 604800) { $d = (int) floor($diff / 86400); return $d . ' day' . ($d === 1 ? '' : 's') . ' ago'; }
    return date('M j, Y', $ts);
}

function official_initials(string $name): string {
    $skip = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'hon', 'hon.'];
    $parts = array_values(array_filter(
        preg_split('/\s+/', trim($name)),
        function ($p) use ($skip) {
            $clean = strtolower(rtrim($p, '.'));
            return !in_array($clean, $skip, true) && mb_strlen(rtrim($p, '.')) > 1;
        }
    ));
    if (empty($parts)) {
        return mb_strtoupper(mb_substr(trim($name), 0, 1));
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';
    return $first . $last;
}

/**
 * Gregorian month names keyed by number, shared by every CSV export
 * period picker so January..December always read the same everywhere.
 */
function month_names(): array {
    return [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];
}

/**
 * Option rows ([value, label]) for the export picker's Year select,
 * starting with the "All years" choice. `$years` is a plain list of
 * integer years pulled from the table being exported.
 */
function export_year_options(array $years): array {
    $options = [['', 'All years']];
    foreach ($years as $year) {
        $options[] = [(string) (int) $year, (string) (int) $year];
    }
    return $options;
}

/**
 * Option rows ([value, label]) for the export picker's Month select,
 * starting with the "All months" choice.
 */
function export_month_options(): array {
    $options = [['', 'All months']];
    foreach (month_names() as $num => $name) {
        $options[] = [(string) $num, $name];
    }
    return $options;
}

/**
 * Parses the year/month query params every CSV export picker submits,
 * then runs them through the same sanity bounds (year 2000-2100, month
 * 1-12). A month without a year quietly falls back to the current year,
 * since a month alone is meaningless. Returns [year, month] as ?int.
 */
function export_period_from_query(array $query): array {
    $year = isset($query['year']) && ctype_digit((string) $query['year']) ? (int) $query['year'] : null;
    $month = isset($query['month']) && ctype_digit((string) $query['month']) ? (int) $query['month'] : null;
    if ($month !== null && $year === null) {
        $year = (int) date('Y');
    }
    if ($year !== null && ($year < 2000 || $year > 2100)) {
        // Drop the month together with an out-of-range year. Keeping just the
        // month widened the filter to that month across ALL years while the
        // export label still read "all time" - and fed logs.php a WHERE-less
        // "AND MONTH(...)" fragment that was a syntax error.
        $year = null;
        $month = null;
    }
    if ($month !== null && ($month < 1 || $month > 12)) { $month = null; }
    return [$year, $month];
}

/**
 * Human label ("March 2025" / "2025" / "all time") plus a file-name
 * suffix ("_2025-03" / "_2025" / "_all") for a parsed export period.
 */
function export_period_label(?int $year, ?int $month): array {
    if ($year !== null) {
        $monthName = $month !== null ? (month_names()[$month] ?? (string) $month) : null;
        $label = $month !== null ? $monthName . ' ' . $year : (string) $year;
        $suffix = '_' . $year . ($month !== null ? '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) : '');
    } else {
        $label = 'all time';
        $suffix = '_all';
    }
    return [$label, $suffix];
}
