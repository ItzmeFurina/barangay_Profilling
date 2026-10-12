<?php

require_once __DIR__ . '/mail.php';

function auth_client_ip(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

function auth_rate_limit_allows(
    PDO $pdo,
    string $action,
    string $identifierType,
    string $identifier,
    int $maximum,
    int $windowSeconds
): bool {
    $identifierHash = hash('sha256', $identifier);
    $windowSeconds = max(1, min($windowSeconds, 86400));
    $statement = $pdo->prepare(
        'INSERT INTO auth_rate_limits
            (action, identifier_type, identifier_hash, window_started_at, attempts)
         VALUES (?, ?, ?, NOW(), 1)
         ON DUPLICATE KEY UPDATE
            attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND), 1, attempts + 1),
            window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL ' . $windowSeconds . ' SECOND), NOW(), window_started_at)'
    );
    $statement->execute([$action, $identifierType, $identifierHash]);

    $statement = $pdo->prepare(
        'SELECT attempts FROM auth_rate_limits
         WHERE action = ? AND identifier_type = ? AND identifier_hash = ?'
    );
    $statement->execute([$action, $identifierType, $identifierHash]);
    $attempts = (int) $statement->fetchColumn();

    if ($identifierType === 'ip' && random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM auth_rate_limits WHERE window_started_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $pdo->exec('DELETE FROM password_reset_tokens WHERE used = 1 OR expires_at < NOW()');
        $pdo->exec('DELETE FROM email_verification_tokens WHERE used = 1 OR expires_at < NOW()');
    }

    return $attempts <= $maximum;
}

function issue_email_verification_token(PDO $pdo, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ? AND (used = 1 OR expires_at < NOW())')
        ->execute([$userId]);
    $pdo->prepare('UPDATE email_verification_tokens SET used = 1 WHERE user_id = ? AND used = 0')
        ->execute([$userId]);
    $pdo->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    )->execute([$userId, hash('sha256', $token)]);
    return $token;
}

function send_email_verification_for_user(PDO $pdo, int $userId, string $portal): bool {
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT email, email_verified_at, email_verification_required FROM users WHERE user_id = ? FOR UPDATE'
        );
        $statement->execute([$userId]);
        $user = $statement->fetch();
        if (!$user || !$user['email_verification_required'] || $user['email_verified_at'] !== null) {
            $pdo->commit();
            return false;
        }

        $token = issue_email_verification_token($pdo, $userId);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return send_email_verification_email($user['email'], $token, $portal);
}

function issue_password_reset_link(PDO $pdo, string $email, string $portal): bool {
    $roleCondition = $portal === 'admin'
        ? "role IN ('admin','staff')"
        : "role = 'resident'";
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "SELECT user_id, email FROM users
             WHERE LOWER(email) = LOWER(?) AND {$roleCondition} AND account_status = 'active'
             FOR UPDATE"
        );
        $statement->execute([$email]);
        $user = $statement->fetch();
        if (!$user) {
            $pdo->commit();
            return true;
        }

        $token = bin2hex(random_bytes(32));
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND (used = 1 OR expires_at < NOW())')
            ->execute([$user['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0')
            ->execute([$user['user_id']]);
        $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
        )->execute([$user['user_id'], hash('sha256', $token)]);
        $tokenId = (int) $pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    if (send_password_reset_email($user['email'], $token, $portal)) {
        return true;
    }

    $pdo->prepare('UPDATE password_reset_tokens SET used = 1 WHERE id = ?')->execute([$tokenId]);
    return false;
}

function password_reset_token_exists(PDO $pdo, string $token, string $portal): bool {
    $roleCondition = $portal === 'admin'
        ? "u.role IN ('admin','staff')"
        : "u.role = 'resident'";
    $statement = $pdo->prepare(
        "SELECT prt.id
         FROM password_reset_tokens prt
         JOIN users u ON u.user_id = prt.user_id
         WHERE prt.token_hash = ? AND prt.used = 0
           AND prt.expires_at > NOW() AND {$roleCondition}"
    );
    $statement->execute([hash('sha256', $token)]);
    return $statement->fetchColumn() !== false;
}

function complete_password_reset(PDO $pdo, string $token, string $portal, string $passwordHash): ?array {
    $roleCondition = $portal === 'admin'
        ? "u.role IN ('admin','staff')"
        : "u.role = 'resident'";
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "SELECT prt.id, prt.user_id, u.email
             FROM password_reset_tokens prt
             JOIN users u ON u.user_id = prt.user_id
             WHERE prt.token_hash = ? AND prt.used = 0
               AND prt.expires_at > NOW() AND {$roleCondition}
             FOR UPDATE"
        );
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!$row) {
            $pdo->rollBack();
            return null;
        }

        $pdo->prepare(
            'UPDATE users
             SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL
             WHERE user_id = ?'
        )->execute([$passwordHash, $row['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used = 1 WHERE user_id = ?')
            ->execute([$row['user_id']]);
        log_activity($pdo, (int) $row['user_id'], 'Password reset via email', 'update');
        $pdo->commit();
        return ['email' => $row['email'], 'user_id' => (int) $row['user_id']];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function consume_email_verification_token(PDO $pdo, string $token, string $portal): bool {
    $roleCondition = $portal === 'admin'
        ? "u.role IN ('admin','staff')"
        : "u.role = 'resident'";
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "SELECT evt.user_id
             FROM email_verification_tokens evt
             JOIN users u ON u.user_id = evt.user_id
             WHERE evt.token_hash = ? AND evt.used = 0
               AND evt.expires_at > NOW() AND u.email_verification_required = TRUE
               AND {$roleCondition}
             FOR UPDATE"
        );
        $statement->execute([hash('sha256', $token)]);
        $verification = $statement->fetch();
        if (!$verification) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare(
            'UPDATE users SET email_verified_at = NOW()
             WHERE user_id = ? AND email_verified_at IS NULL'
        )->execute([$verification['user_id']]);
        $pdo->prepare('UPDATE email_verification_tokens SET used = 1 WHERE user_id = ?')
            ->execute([$verification['user_id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function handle_email_verification(PDO $pdo, string $portal): array {
    $roleCondition = $portal === 'admin'
        ? "u.role IN ('admin','staff')"
        : "u.role = 'resident'";
    $errors = [];
    $success = null;
    $email = '';
    $ip = auth_client_ip();
    $tokenAction = 'email-verify';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
        $token = is_string($_GET['token']) ? $_GET['token'] : '';
        $rateToken = strlen($token) === 64 ? $token : 'invalid-token';
        $ipAllowed = auth_rate_limit_allows($pdo, $tokenAction, 'ip', $ip, 20, 900);
        $tokenAllowed = auth_rate_limit_allows($pdo, $tokenAction, 'token', $rateToken, 5, 900);
        if ($ipAllowed && $tokenAllowed
            && preg_match('/^[a-f0-9]{64}$/i', $token)
            && email_verification_token_exists($pdo, $token, $portal)) {
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['pending_email_verification_token'] = $token;
            redirect('verify-email.php');
        }
        $errors[] = 'This verification link is invalid, expired, or already used.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null;
        if (!csrf_verify($csrfToken)) {
            $errors[] = 'Your session expired. Please refresh the page and try again.';
        } elseif (($_POST['action'] ?? '') === 'verify') {
            $token = is_string($_SESSION['pending_email_verification_token'] ?? null)
                ? $_SESSION['pending_email_verification_token'] : '';
            $rateToken = strlen($token) === 64 ? $token : 'invalid-token';
            $ipAllowed = auth_rate_limit_allows($pdo, $tokenAction, 'ip', $ip, 20, 900);
            $tokenAllowed = auth_rate_limit_allows($pdo, $tokenAction, 'token', $rateToken, 5, 900);
            if (!$ipAllowed || !$tokenAllowed || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
                $errors[] = 'This verification link is invalid, expired, or already used.';
            } elseif (($draft = consume_registration_draft_token($pdo, $token, $portal)) !== null) {
                unset($_SESSION['pending_email_verification_token']);
                $_SESSION['verified_registration_draft_id'] = $draft['draft_id'];
                $_SESSION['verified_registration_workflow'] = $draft['workflow'];
                flash('email_verified', 'Email verification confirmed successfully. Return to your application and submit it for review.');
                redirect('verify-email.php');
            } elseif (consume_email_verification_token($pdo, $token, $portal)) {
                unset($_SESSION['pending_email_verification_token']);
                flash('email_verified', 'Your email address is verified. You may now sign in once your account is approved.');
                redirect('verify-email.php');
            } else {
                unset($_SESSION['pending_email_verification_token']);
                $errors[] = 'This verification link is invalid, expired, or already used.';
            }
        } else {
            $submittedEmail = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
            $email = strlen($submittedEmail) <= 150 ? normalize_email($submittedEmail) : '';
            $ipAllowed = auth_rate_limit_allows($pdo, 'email-resend', 'ip', $ip, 5, 900);
            $emailAllowed = auth_rate_limit_allows($pdo, 'email-resend', 'email', $email, 3, 900);
            if ($ipAllowed && $emailAllowed && strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $statement = $pdo->prepare(
                    "SELECT u.user_id FROM users u
                     WHERE LOWER(u.email) = LOWER(?) AND u.email_verified_at IS NULL
                       AND u.email_verification_required = TRUE AND {$roleCondition}"
                );
                $statement->execute([$email]);
                $userId = $statement->fetchColumn();
                if ($userId) {
                    send_email_verification_for_user($pdo, (int) $userId, $portal);
                }
            }
            $success = 'If the address belongs to an account that still needs verification and email delivery succeeds, a verification link will be sent.';
        }
    }

    $pendingToken = is_string($_SESSION['pending_email_verification_token'] ?? null)
        ? $_SESSION['pending_email_verification_token'] : '';
    $pending = $pendingToken !== ''
        && email_verification_token_exists($pdo, $pendingToken, $portal);
    if (!$pending) {
        unset($_SESSION['pending_email_verification_token']);
    }
    $draftId = is_string($_SESSION['verified_registration_draft_id'] ?? null)
        ? $_SESSION['verified_registration_draft_id'] : '';
    $draft = $draftId !== '' ? verified_registration_draft($pdo, $draftId) : null;
    $expectedDraftWorkflows = $portal === 'admin'
        ? ['staff-create-account']
        : ['register', 'create-account'];
    if ($draft && !in_array($draft['workflow'], $expectedDraftWorkflows, true)) {
        $draft = null;
    }
    if (!$draft) {
        unset($_SESSION['verified_registration_draft_id'], $_SESSION['verified_registration_workflow']);
    }
    return [
        'errors' => $errors,
        'success' => $success,
        'email' => $email,
        'pending' => $pending,
        'verified_registration_workflow' => $draft['workflow'] ?? null,
    ];
}

function email_verification_token_exists(PDO $pdo, string $token, string $portal): bool {
    $draftWorkflows = $portal === 'admin'
        ? ['staff-create-account']
        : ['register', 'create-account'];
    $placeholders = implode(',', array_fill(0, count($draftWorkflows), '?'));
    $draft = $pdo->prepare(
        "SELECT draft_id FROM registration_drafts
         WHERE token_hash = ? AND workflow IN ({$placeholders})
           AND verified_at IS NULL AND submitted_at IS NULL AND expires_at > NOW()"
    );
    $draft->execute(array_merge([hash('sha256', $token)], $draftWorkflows));
    if ($draft->fetchColumn() !== false) {
        return true;
    }

    $roleCondition = $portal === 'admin'
        ? "u.role IN ('admin','staff')"
        : "u.role = 'resident'";
    $statement = $pdo->prepare(
        "SELECT evt.id
         FROM email_verification_tokens evt
         JOIN users u ON u.user_id = evt.user_id
         WHERE evt.token_hash = ? AND evt.used = 0
           AND evt.expires_at > NOW() AND u.email_verification_required = TRUE
           AND {$roleCondition}"
    );
    $statement->execute([hash('sha256', $token)]);
    return $statement->fetchColumn() !== false;
}

function create_registration_draft(
    PDO $pdo,
    string $workflow,
    string $email,
    array $payload,
    string $documentPath
): array {
    if (!in_array($workflow, ['register', 'create-account', 'staff-create-account'], true)
        || !preg_match('#^verification/draft_[a-f0-9]{32}\.(jpg|png|pdf)$#', $documentPath)) {
        throw new InvalidArgumentException('Registration draft data is invalid.');
    }

    $draftId = bin2hex(random_bytes(32));
    $token = bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try {
        $expired = $pdo->query(
            'SELECT document_path FROM registration_drafts WHERE expires_at <= NOW()'
        )->fetchAll(PDO::FETCH_COLUMN);
        $old = $pdo->prepare(
            'SELECT document_path FROM registration_drafts WHERE email = ? AND workflow = ?'
        );
        $old->execute([$email, $workflow]);
        $replaced = $old->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare(
            'DELETE FROM registration_drafts WHERE expires_at <= NOW() OR (email = ? AND workflow = ?)'
        )->execute([$email, $workflow]);
        $statement = $pdo->prepare(
            'INSERT INTO registration_drafts
                (draft_id, workflow, email, payload, document_path, token_hash, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
        );
        $statement->execute([
            $draftId,
            $workflow,
            $email,
            json_encode($payload, JSON_THROW_ON_ERROR),
            $documentPath,
            hash('sha256', $token),
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    foreach (array_unique(array_merge($expired, $replaced)) as $oldPath) {
        if (preg_match('#^verification/draft_[a-f0-9]{32}\.(jpg|png|pdf)$#', $oldPath)) {
            $absolutePath = __DIR__ . '/../uploads/' . $oldPath;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    return ['draft_id' => $draftId, 'token' => $token];
}

function verified_registration_draft(PDO $pdo, string $draftId): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $draftId)) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT draft_id, workflow, email, payload, document_path
         FROM registration_drafts
         WHERE draft_id = ? AND verified_at IS NOT NULL
           AND submitted_at IS NULL AND expires_at > NOW()'
    );
    $statement->execute([$draftId]);
    $draft = $statement->fetch();
    if (!$draft) {
        return null;
    }
    $draft['payload'] = json_decode($draft['payload'], true, 512, JSON_THROW_ON_ERROR);
    return $draft;
}

function submit_verified_resident_account_draft(PDO $pdo, string $draftId): void {
    if (!preg_match('/^[a-f0-9]{64}$/', $draftId)) {
        throw new RuntimeException('The verified account request is no longer available.');
    }

    $pdo->beginTransaction();
    try {
        $draftStmt = $pdo->prepare(
            "SELECT draft_id, email, payload, document_path
             FROM registration_drafts
             WHERE draft_id = ? AND workflow = 'create-account'
               AND verified_at IS NOT NULL AND submitted_at IS NULL AND expires_at > NOW()
             FOR UPDATE"
        );
        $draftStmt->execute([$draftId]);
        $draft = $draftStmt->fetch();
        if (!$draft) {
            throw new RuntimeException('The verified account request is no longer available.');
        }
        $payload = json_decode($draft['payload'], true, 512, JSON_THROW_ON_ERROR);

        $memberStmt = $pdo->prepare(
            'SELECT member_id FROM household_members
             WHERE member_id = ? AND is_archived = FALSE AND linked_user_id IS NULL
             FOR UPDATE'
        );
        $memberStmt->execute([$payload['member_id']]);
        if (!$memberStmt->fetchColumn()) {
            throw new RuntimeException('The resident record is no longer available.');
        }

        $pendingMemberStmt = $pdo->prepare(
            'SELECT user_id FROM residents WHERE household_member_id = ? LIMIT 1'
        );
        $pendingMemberStmt->execute([$payload['member_id']]);
        if ($pendingMemberStmt->fetchColumn()) {
            throw new RuntimeException('An account request already exists for this resident.');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, email_verified_at, contact_number, password_hash, role, account_status)
             VALUES (?, ?, NOW(), ?, ?, 'resident', ?)"
        );
        $stmt->execute([
            $payload['full_name'],
            $draft['email'],
            $payload['contact_number'],
            $payload['password_hash'],
            SKIP_RESIDENT_VERIFICATION ? 'active' : 'pending',
        ]);
        $userId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO residents
                (user_id, house_number, street, barangay, city, household_id, household_member_id, census_completed)
             VALUES (?, ?, ?, ?, ?, ?, ?, FALSE)'
        );
        $stmt->execute([
            $userId,
            $payload['house_number'] !== '' ? $payload['house_number'] : null,
            $payload['street'],
            $payload['barangay'],
            $payload['city'],
            $payload['household_id'],
            $payload['member_id'],
        ]);
        $stmt = $pdo->prepare(
            "INSERT INTO verification_documents (user_id, document_type, file_path)
             VALUES (?, 'Barangay ID', ?)"
        );
        $stmt->execute([$userId, $draft['document_path']]);
        log_activity($pdo, $userId, 'Submitted Create Account for review', 'create');
        log_activity($pdo, $userId, 'Verification document uploaded', 'create');
        $pdo->prepare('DELETE FROM registration_drafts WHERE draft_id = ?')->execute([$draftId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function submit_verified_resident_registration_draft(PDO $pdo, string $draftId): void {
    if (!preg_match('/^[a-f0-9]{64}$/', $draftId)) {
        throw new RuntimeException('The verified resident registration is no longer available.');
    }

    $pdo->beginTransaction();
    try {
        $draftStmt = $pdo->prepare(
            "SELECT draft_id, email, payload, document_path
             FROM registration_drafts
             WHERE draft_id = ? AND workflow = 'register'
               AND verified_at IS NOT NULL AND submitted_at IS NULL AND expires_at > NOW()
             FOR UPDATE"
        );
        $draftStmt->execute([$draftId]);
        $draft = $draftStmt->fetch();
        if (!$draft) {
            throw new RuntimeException('The verified resident registration is no longer available.');
        }
        $payload = json_decode($draft['payload'], true, 512, JSON_THROW_ON_ERROR);

        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, email_verified_at, email_verification_required, contact_number, password_hash, role, account_status)
             VALUES (?, ?, NOW(), TRUE, ?, ?, 'resident', ?)"
        );
        $stmt->execute([
            $payload['full_name'],
            $draft['email'],
            $payload['contact_number'],
            $payload['password_hash'],
            SKIP_RESIDENT_VERIFICATION ? 'active' : 'pending',
        ]);
        $userId = (int) $pdo->lastInsertId();
        $saved = census_save($pdo, $payload['household'], $payload['members'], $userId);

        $stmt = $pdo->prepare(
            'INSERT INTO residents
                (user_id, house_number, street, barangay, city, household_id, household_member_id, census_completed, census_completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, NOW())'
        );
        $stmt->execute([
            $userId,
            $payload['household']['house_number'] !== '' ? $payload['household']['house_number'] : null,
            $payload['household']['street'],
            $payload['household']['barangay'],
            $payload['household']['city'],
            $saved['household_id'],
            $saved['member_id'],
        ]);
        $stmt = $pdo->prepare(
            'INSERT INTO verification_documents (user_id, document_type, file_path)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $payload['document_type'], $draft['document_path']]);
        log_activity(
            $pdo,
            $userId,
            'Submitted residency registration for review (' . count($payload['members']) . ' household member(s))',
            'create'
        );
        log_activity($pdo, $userId, 'Proof of residency uploaded (' . $payload['document_type'] . ')', 'create');
        $pdo->prepare('DELETE FROM registration_drafts WHERE draft_id = ?')->execute([$draftId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function submit_verified_staff_application_draft(PDO $pdo, string $draftId): void {
    if (!preg_match('/^[a-f0-9]{64}$/', $draftId)) {
        throw new RuntimeException('The verified staff application is no longer available.');
    }

    $pdo->beginTransaction();
    try {
        $draftStmt = $pdo->prepare(
            "SELECT draft_id, payload, document_path
             FROM registration_drafts
             WHERE draft_id = ? AND workflow = 'staff-create-account'
               AND verified_at IS NOT NULL AND submitted_at IS NULL AND expires_at > NOW()
             FOR UPDATE"
        );
        $draftStmt->execute([$draftId]);
        $draft = $draftStmt->fetch();
        if (!$draft) {
            throw new RuntimeException('The verified staff application is no longer available.');
        }
        $payload = json_decode($draft['payload'], true, 512, JSON_THROW_ON_ERROR);

        $existing = $pdo->prepare(
            'SELECT user_id, role, account_status FROM users WHERE email = ? FOR UPDATE'
        );
        $existing->execute([$payload['email']]);
        $existingAccount = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingAccount) {
            if ($existingAccount['role'] !== 'staff' || $existingAccount['account_status'] !== 'rejected') {
                throw new RuntimeException('An account already uses this email address.');
            }
            $pdo->prepare(
                "UPDATE users
                 SET email = CONCAT('rejected-', user_id, '@invalid.local'),
                     email_verified_at = NULL,
                     email_verification_required = FALSE
                 WHERE user_id = ?"
            )->execute([$existingAccount['user_id']]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')
                ->execute([$existingAccount['user_id']]);
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')
                ->execute([$existingAccount['user_id']]);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO users (full_name, email, email_verified_at, email_verification_required, contact_number, password_hash, role, account_status)
             VALUES (?, ?, NOW(), TRUE, ?, ?, 'staff', 'pending')"
        );
        $stmt->execute([
            $payload['full_name'],
            $payload['email'],
            $payload['contact_number'],
            $payload['password_hash'],
        ]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO barangay_staff (user_id) VALUES (?)')->execute([$userId]);
        $stmt = $pdo->prepare(
            "INSERT INTO verification_documents (user_id, document_type, file_path)
             VALUES (?, 'Staff ID', ?)"
        );
        $stmt->execute([$userId, $draft['document_path']]);
        log_activity($pdo, $userId, 'Submitted staff registration for review', 'create');
        log_activity($pdo, $userId, 'Staff email verified before application submission', 'update');
        $pdo->prepare('DELETE FROM registration_drafts WHERE draft_id = ?')->execute([$draftId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function consume_registration_draft_token(PDO $pdo, string $token, string $portal): ?array {
    $draftWorkflows = $portal === 'admin'
        ? ['staff-create-account']
        : ['register', 'create-account'];
    $placeholders = implode(',', array_fill(0, count($draftWorkflows), '?'));
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            "SELECT draft_id, workflow
             FROM registration_drafts
             WHERE token_hash = ? AND workflow IN ({$placeholders})
               AND verified_at IS NULL AND submitted_at IS NULL AND expires_at > NOW()
             FOR UPDATE"
        );
        $statement->execute(array_merge([hash('sha256', $token)], $draftWorkflows));
        $draft = $statement->fetch();
        if (!$draft) {
            $pdo->rollBack();
            return null;
        }

        $pdo->prepare(
            'UPDATE registration_drafts SET verified_at = NOW()
             WHERE draft_id = ? AND verified_at IS NULL'
        )->execute([$draft['draft_id']]);
        $pdo->commit();
        return $draft;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
