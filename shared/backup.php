<?php
/**
 * Backup/restore engine.
 *
 * Backups are encrypted at rest (see shared/backup_encryption.php) - the
 * exported file is no longer plain, human-readable SQL. It can only be
 * read back in through run_restore() below, which has the server's key.
 * Backups made before this feature existed are still plain SQL on disk
 * and still restore correctly (decrypt_backup_content() passes them
 * through unchanged when it finds no encryption header).
 *
 * Table order matches schema.sql's foreign key dependencies. Foreign key
 * checks are disabled for the duration of a restore so tables can be
 * cleared and reloaded regardless of that order, then re-enabled after.
 */

require_once __DIR__ . '/backup_encryption.php';
require_once __DIR__ . '/functions.php';

const BACKUP_TABLE_ORDER = [
    'users', 'barangay_info', 'barangay_officials', 'households', 'household_members', 'residents',
    'barangay_staff', 'verification_documents', 'programs', 'program_criteria',
    'program_documents', 'beneficiaries', 'benefit_documents', 'validation_flags', 'events', 'event_photos',
    'beneficiary_imports', 'beneficiary_import_rows', 'password_reset_tokens',
    'email_verification_tokens', 'auth_rate_limits', 'registration_drafts', 'system_meta',
    'activity_logs', 'backup_logs',
];

/**
 * First line every backup this system writes starts with. Restore refuses
 * any file that doesn't carry it - a hand-made .sql file is exactly what
 * a malicious upload would look like, so restore only trusts files that
 * were actually produced by run_backup() (old backups already carry it).
 */
const BACKUP_FILE_MARKER = '-- Barangay 420 backup - generated ';

/**
 * Validates that a file is a backup produced by this system, before a
 * single statement of it is ever executed (or decrypted). Checks for
 * either this system's encryption header (current backups) or the old
 * plaintext marker (backups made before encryption was added) - either
 * one proves the file actually came from run_backup(), not a hand-made
 * upload pretending to be one.
 */
function validate_backup_file(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The backup file could not be read.');
    }
    $handle = fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException('The backup file could not be opened.');
    }
    $head = fread($handle, 512);
    fclose($handle);
    $isEncrypted = $head !== false && strpos($head, BACKUP_ENC_MAGIC) === 0;
    $isLegacyPlain = $head !== false && strpos($head, BACKUP_FILE_MARKER) === 0;
    if (!$isEncrypted && !$isLegacyPlain) {
        throw new RuntimeException(
            'This file is not a backup made by this system (it does not start with the expected marker). Refusing to restore.'
        );
    }
}

/**
 * Writes a full backup to $outputPath, encrypted (see
 * shared/backup_encryption.php - $backupDir is where this install's
 * encryption key lives/gets created). $onTableDone(table, tableCount,
 * recordsSoFar, totalRecords) is called after each table, letting the
 * caller stream progress to the browser as it actually happens.
 */
function run_backup(PDO $pdo, string $outputPath, string $backupDir, ?callable $onTableDone = null): int {
    $counts = [];
    $totalRecords = 0;
    foreach (BACKUP_TABLE_ORDER as $table) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $counts[$table] = $count;
        $totalRecords += $count;
    }

    // Built up as plain SQL first (in a temp file, never at the final
    // path), then encrypted as a whole once complete. The plaintext
    // temp file lives in backups/, which backups/.htaccess already
    // denies to the web entirely. A random suffix keeps its name
    // unguessable, 0600 keeps it owner-only, and the finally further
    // down deletes it on every path - including a failed write, which
    // previously stranded a full plaintext dump on disk forever.
    $tempPath = $outputPath . '.plain.' . bin2hex(random_bytes(6)) . '.tmp';
    $handle = fopen($tempPath, 'w');
    if ($handle === false) {
        throw new RuntimeException('Could not create the backup file.');
    }
    @chmod($tempPath, 0600);

    // A failed write (full disk, permission change mid-run) must abort the
    // backup rather than leave a silently truncated file that looks valid.
    $write = static function ($handle, string $data): void {
        if (fwrite($handle, $data) === false) {
            throw new RuntimeException('Could not write the backup file - it may be out of disk space.');
        }
    };

    try {
        $write($handle, "-- Barangay 420 backup - generated " . date('Y-m-d H:i:s') . "\n");
        $write($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        foreach (BACKUP_TABLE_ORDER as $table) {
            $write($handle, "TRUNCATE TABLE `{$table}`;\n");
        }
        $write($handle, "\n");

        $recordsSoFar = 0;
        foreach (BACKUP_TABLE_ORDER as $table) {
            $write($handle, "-- Table: {$table}\n");
            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_keys($row);
                $values = array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string) $v);
                }, array_values($row));
                $write(
                    $handle,
                    "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . "`) VALUES (" . implode(',', $values) . ");\n"
                );
            }
            $write($handle, "\n");
            $recordsSoFar += $counts[$table];

            if ($onTableDone !== null) {
                $onTableDone($table, $counts[$table], $recordsSoFar, $totalRecords);
            }
        }

        $write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");

        // Close before reading the finished file back for encryption.
        fclose($handle);
        $handle = null;

        $plaintext = file_get_contents($tempPath);
        if ($plaintext === false) {
            throw new RuntimeException('Could not read back the backup while encrypting it.');
        }
        $encrypted = encrypt_backup_content($plaintext, $backupDir);
        unset($plaintext);
        if (file_put_contents($outputPath, $encrypted) === false) {
            throw new RuntimeException('Could not write the encrypted backup file - it may be out of disk space.');
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        // Runs on success and on every failure path above, so a partial
        // write can never leave a plaintext dump sitting in backups/.
        @unlink($tempPath);
    }

    return $totalRecords;
}

/**
 * Executes a previously exported backup file. Statements are split on
 * top-level semicolons (quotes and backticks respected), so this works
 * both for this system's own one-statement-per-line exports and for
 * multi-line SQL like a phpMyAdmin export. Comment lines (starting with
 * "--") and block comments are stripped first so they can never confuse
 * the splitter.
 *
 * Security: restore is trusted to run DDL by nature (a restore must be
 * able to TRUNCATE tables), so the file itself is treated as code - the
 * defense is in what the file is ALLOWED to be, not in escaping. Call
 * validate_backup_file() first, and beyond that every statement must
 * start with one of the three statements a real backup ever contains
 * (SET, TRUNCATE TABLE, INSERT INTO). Anything else - DROP, DELETE,
 * UPDATE, ALTER, CREATE, SELECT ... INTO OUTFILE, LOAD_FILE and friends -
 * aborts the whole restore before it touches the database.
 */
function run_restore(PDO $pdo, string $filePath, string $backupDir): int {
    validate_backup_file($filePath);

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        throw new RuntimeException('Could not read the backup file.');
    }
    $sql = decrypt_backup_content($raw, $backupDir);

    $sql = strip_sql_comments($sql);

    // Validate EVERY statement before executing ANY of them. A lazy
    // per-statement check inside the execution loop would run the leading
    // TRUNCATEs/INSERTs first and only then discover a disallowed
    // statement - at which point the data is already gone while the error
    // message still claims "nothing has been changed".
    $statements = [];
    $hasUserInserts = false;
    $userInsertsIncludeVerificationState = false;
    foreach (split_sql_statements($sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        if (!preg_match('/^\s*(SET\b|TRUNCATE TABLE\b|INSERT INTO\b)/i', $statement)) {
            $head = substr($statement, 0, 40);
            throw new RuntimeException(
                "The backup contains a statement restore is not allowed to run (" . trim($head) . "...). Refusing to continue - nothing has been changed."
            );
        }
        if (preg_match('/^\s*INSERT\s+INTO\s+`?users`?\s*\(([^)]+)\)/i', $statement, $matches)) {
            $hasUserInserts = true;
            if (preg_match('/(?:^|,)\s*`?email_verified_at`?\s*(?:,|$)/i', $matches[1])) {
                $userInsertsIncludeVerificationState = true;
            }
        }
        $statements[] = $statement;
    }

    $executed = 0;
    foreach ($statements as $statement) {
        $pdo->exec($statement);
        $executed++;
    }

    if ($hasUserInserts && !$userInsertsIncludeVerificationState) {
        $pdo->exec(
            'UPDATE users
             SET email_verified_at = COALESCE(created_at, NOW())
             WHERE email_verified_at IS NULL'
        );
        $executed++;
    }

    return $executed;
}

/**
 * Logs an activity_logs entry for an action taken by $userId, but never
 * lets that logging fail the caller if $userId no longer exists.
 *
 * Specifically for the moment right after a restore: run_restore()
 * truncates and reloads the `users` table from whatever the backup file
 * contains, which may be a snapshot from before the currently logged-in
 * admin's account even existed. Logging "Restored from backup #N" with
 * that now-nonexistent user_id would fail on activity_logs' own foreign
 * key - and since that log call happens AFTER the restore itself already
 * fully succeeded, letting it throw would incorrectly report a
 * successful restore as "did not finish cleanly". Falls back to a NULL
 * user_id (activity_logs.user_id is nullable for exactly this kind of
 * case) with the real id noted in the action text instead.
 */
function log_activity_surviving_restore(PDO $pdo, int $userId, string $action): void {
    $exists = $pdo->prepare('SELECT 1 FROM users WHERE user_id = ?');
    $exists->execute([$userId]);
    if ($exists->fetchColumn()) {
        log_activity($pdo, $userId, $action, 'create');
    } else {
        log_activity($pdo, null, "{$action} (performed by user_id {$userId}, no longer present after this restore)", 'create');
    }
}

/**
 * Strips SQL comments without ever touching content inside quoted string
 * literals, so a note field containing comment-like text (for example a
 * value that mentions a block-comment sequence) survives a restore intact.
 * Handles '#' line comments (MySQL's own) and
 * '--' line comments (only when followed by whitespace/end-of-line, so a
 * real subtraction operator is never eaten) plus C-style block comments.
 * Single/double/backtick quoting and backslash escapes are
 * tracked throughout.
 */
function strip_sql_comments(string $sql): string {
    $out = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $escaped = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($escaped) {
            $out .= $ch;
            $escaped = false;
            continue;
        }
        if ($ch === '\\') {
            $out .= $ch;
            $escaped = true;
            continue;
        }

        if ($inSingle || $inDouble || $inBacktick) {
            $out .= $ch;
            if ($ch === "'" && !$inDouble && !$inBacktick) {
                $inSingle = false;
            } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
                $inDouble = false;
            } elseif ($ch === '`' && !$inSingle && !$inDouble) {
                $inBacktick = false;
            }
            continue;
        }

        // Line comment: '#' always; '--' only when the third character is
        // whitespace or the line ends, matching MySQL's own rule.
        if ($ch === '#'
            || ($ch === '-' && ($sql[$i + 1] ?? '') === '-'
                && (($sql[$i + 2] ?? '') === '' || trim($sql[$i + 2]) === ''))) {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            continue;
        }

        // Block comment: '/* ... */' outside any quoted string.
        if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            continue;
        }

        if ($ch === "'") {
            $inSingle = true;
        } elseif ($ch === '"') {
            $inDouble = true;
        } elseif ($ch === '`') {
            $inBacktick = true;
        }

        $out .= $ch;
    }

    return $out;
}

/**
 * Splits a chunk of SQL into individual statements on semicolons that
 * sit outside quoted strings (single quotes, double quotes, backticks).
 * Handles backslash escapes the way MySQL does by default.
 */
function split_sql_statements(string $sql): array {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $escaped = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($escaped) {
            $current .= $ch;
            $escaped = false;
            continue;
        }
        if ($ch === '\\') {
            $current .= $ch;
            $escaped = true;
            continue;
        }
        if ($ch === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statements[] = $current;
            $current = '';
            continue;
        }
        $current .= $ch;
    }

    if (trim($current) !== '') {
        $statements[] = $current;
    }
    return $statements;
}
