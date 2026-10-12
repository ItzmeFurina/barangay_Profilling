<?php
/**
 * Encryption at rest for backup files, so a downloaded (or otherwise
 * exfiltrated) backup is unreadable to anyone without this server's key -
 * only run_restore() can make sense of it. Uses OpenSSL's AES-256-GCM
 * (authenticated encryption - confidentiality and tamper-detection in one
 * step, not a hand-rolled cipher). Chosen over libsodium specifically
 * because the openssl extension is essentially always enabled by default
 * on every PHP build (XAMPP included - it's needed for HTTPS/cURL), where
 * sodium's availability has turned out to vary more in practice.
 *
 * Trade-off, made on purpose: backup.php's own docblock used to advertise
 * being "self-contained and re-runnable by pasting into phpMyAdmin" as a
 * disaster-recovery fallback if restore.php itself ever had a bug.
 * Encrypting removes that fallback - an encrypted backup can only be read
 * by something that has the key below. That is the whole point of this
 * file (unreadable to anyone but the system), but it means restore.php
 * genuinely is now the only path back in for an encrypted backup - if it
 * is ever broken, decrypt_backup_content() below is still a small,
 * auditable amount of code you could run by hand to get the plain SQL
 * back out, given the key.
 *
 * Backward compatible on purpose: a backup made before this feature
 * existed has no magic header, so decrypt_backup_content() returns it
 * unchanged - old backups already on disk still restore correctly.
 */

const BACKUP_ENC_MAGIC = "B420ENCV2\n";
const BACKUP_ENC_CIPHER = 'aes-256-gcm';
const BACKUP_ENC_KEY_BYTES = 32; // AES-256
const BACKUP_ENC_TAG_BYTES = 16; // GCM authentication tag, always 16 bytes

/**
 * openssl is core PHP and enabled by default on virtually every install
 * (XAMPP, cPanel/shared hosting, and every distro PHP package all need it
 * for HTTPS/cURL to work at all) - but fail with a clear, actionable
 * message rather than a raw "call to undefined function"/"undefined
 * constant" crash if it, or AES-256-GCM specifically, is ever missing.
 */
function require_openssl_gcm_available(): void {
    if (!function_exists('openssl_encrypt') || !in_array(BACKUP_ENC_CIPHER, openssl_get_cipher_methods(), true)) {
        throw new RuntimeException(
            'This server\'s PHP does not have OpenSSL with AES-256-GCM support enabled, so backups cannot be '
            . 'encrypted or decrypted. On XAMPP: open php.ini and make sure the line "extension=openssl" is '
            . 'present and not commented out with a semicolon, then restart Apache. On shared/cPanel hosting: '
            . 'enable the OpenSSL extension in the PHP configuration section of your hosting control panel.'
        );
    }
}

/**
 * The symmetric key used to encrypt/decrypt every backup. Resolution
 * order:
 *   1. BARANGAY420_BACKUP_KEY environment variable (base64-encoded),
 *      for hosts where you can set one - shared/db.php's DB credentials
 *      follow the same env-var-first pattern.
 *   2. A key file inside backups/ (already denied to the web entirely by
 *      backups/.htaccess), generated automatically the first time a
 *      backup is ever created - so XAMPP and shared hosting without env
 *      var support both work with zero manual setup.
 *
 * IMPORTANT, and worth re-reading before you move servers: this key is
 * what makes a backup readable at all, on purpose - lose it and every
 * backup encrypted with it is unrecoverable, by design. If you move this
 * project to a new server (e.g. local XAMPP -> shared hosting), a backup
 * made on the old server can only be restored on the new one if you copy
 * this exact key across too (or set the same BARANGAY420_BACKUP_KEY on
 * both). Never commit backups/.backup_key to git, and never include it
 * in a zip you hand to anyone else.
 */
function get_backup_encryption_key(string $backupDir): string {
    require_openssl_gcm_available();

    $envKey = getenv('BARANGAY420_BACKUP_KEY');
    if ($envKey !== false && $envKey !== '') {
        $key = base64_decode($envKey, true);
        if ($key !== false && strlen($key) === BACKUP_ENC_KEY_BYTES) {
            return $key;
        }
        throw new RuntimeException('BARANGAY420_BACKUP_KEY is set but is not a valid base64-encoded 32-byte key.');
    }

    $keyFile = rtrim($backupDir, '/\\') . '/.backup_key';
    if (is_file($keyFile)) {
        $stored = @file_get_contents($keyFile);
        $key = $stored !== false ? base64_decode(trim($stored), true) : false;
        if ($key !== false && strlen($key) === BACKUP_ENC_KEY_BYTES) {
            return $key;
        }
        throw new RuntimeException('The backup encryption key file exists but is unreadable or corrupted - backups cannot be encrypted or decrypted without it.');
    }

    // First backup ever created on this install: generate and persist a
    // key so every future backup (and this same one) can round-trip.
    // The whole check-and-create runs under an exclusive file lock so two
    // concurrent first-ever-backup calls can't each write a different key
    // (the last writer wins, leaving every backup encrypted with the other
    // key permanently undecryptable).
    $handle = @fopen($keyFile, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Could not create the backup encryption key file. Check that the backups/ folder is writable.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock the backup encryption key file.');
        }
        // Another process may have created the key while we waited for the
        // lock - if so, use that one instead of generating a fresh key.
        $stored = stream_get_contents($handle);
        if ($stored !== '' && $stored !== false) {
            $key = base64_decode(trim($stored), true);
            if ($key === false || strlen($key) !== BACKUP_ENC_KEY_BYTES) {
                throw new RuntimeException('The backup encryption key file exists but is unreadable or corrupted - backups cannot be encrypted or decrypted without it.');
            }
            return $key;
        }
        $key = random_bytes(BACKUP_ENC_KEY_BYTES);
        rewind($handle);
        if (fwrite($handle, base64_encode($key)) === false) {
            throw new RuntimeException('Could not write the backup encryption key file. Check that the backups/ folder is writable.');
        }
        fflush($handle);
        @chmod($keyFile, 0600);
        return $key;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Encrypts a full backup's plaintext SQL for storage/download. */
function encrypt_backup_content(string $plaintext, string $backupDir): string {
    $key = get_backup_encryption_key($backupDir);
    $iv = random_bytes(openssl_cipher_iv_length(BACKUP_ENC_CIPHER));
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, BACKUP_ENC_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', BACKUP_ENC_TAG_BYTES);
    if ($ciphertext === false) {
        throw new RuntimeException('Encrypting the backup failed.');
    }
    return BACKUP_ENC_MAGIC . $iv . $tag . $ciphertext;
}

/**
 * Decrypts backup file content back to plain SQL. If the content has no
 * encryption header at all, it's an older backup made before this
 * feature existed - returned unchanged so it still restores correctly.
 */
function decrypt_backup_content(string $fileContent, string $backupDir): string {
    $magicLen = strlen(BACKUP_ENC_MAGIC);
    if (substr($fileContent, 0, $magicLen) !== BACKUP_ENC_MAGIC) {
        return $fileContent;
    }
    $key = get_backup_encryption_key($backupDir);
    $ivLen = openssl_cipher_iv_length(BACKUP_ENC_CIPHER);
    $iv = substr($fileContent, $magicLen, $ivLen);
    $tag = substr($fileContent, $magicLen + $ivLen, BACKUP_ENC_TAG_BYTES);
    $ciphertext = substr($fileContent, $magicLen + $ivLen + BACKUP_ENC_TAG_BYTES);
    $plaintext = openssl_decrypt($ciphertext, BACKUP_ENC_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new RuntimeException('This backup file could not be decrypted - it is either corrupted, was tampered with, or was encrypted with a different key than this server currently has.');
    }
    return $plaintext;
}
