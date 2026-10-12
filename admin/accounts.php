<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/functions.php';
require_once __DIR__ . '/../shared/eligibility.php';

require_admin();
$activePage = 'accounts';

$pdo = get_db_connection();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $action = $_POST['action'] ?? '';
        $adminId = (int) $_SESSION['staff_user_id'];

        if (in_array($action, ['approve', 'reject', 'archive'], true) && $targetUserId > 0) {
            // Both approve and reject only make sense for an account that is
            // actually waiting for review. Without this precondition a
            // hand-crafted POST could resurrect an archived/rejected account
            // (approve runs census linking + an eligibility sweep as side
            // effects) or flip a status the UI never offered.
            $targetStmt = $pdo->prepare('SELECT account_status FROM users WHERE user_id = ?');
            $targetStmt->execute([$targetUserId]);
            $targetStatus = $targetStmt->fetchColumn();

            if ($action === 'approve') {
                if ($targetStatus !== 'pending') {
                    $errors[] = 'Only accounts waiting for approval can be approved.';
                } else {
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("UPDATE users SET account_status = 'active' WHERE user_id = ? AND account_status = 'pending'")
                        ->execute([$targetUserId]);
                    $pdo->prepare(
                        "UPDATE verification_documents
                         SET verified_status = 'approved', verified_by = ?, verified_at = NOW()
                         WHERE user_id = ?"
                    )->execute([$adminId, $targetUserId]);

                    // Link the resident's account to their matched census record
                    // and complete their census so they can use the full site.
                    $rstmt = $pdo->prepare(
                        'SELECT resident_id, household_id, household_member_id, census_completed
                         FROM residents WHERE user_id = ?'
                    );
                    $rstmt->execute([$targetUserId]);
                    $resident = $rstmt->fetch(PDO::FETCH_ASSOC);

                    if ($resident) {
                        $householdId = $resident['household_id'];
                        if (!empty($resident['household_member_id'])) {
                            $pdo->prepare(
                                'UPDATE household_members
                                 SET linked_user_id = ?
                                 WHERE member_id = ? AND (linked_user_id IS NULL OR linked_user_id = ?)'
                            )->execute([$targetUserId, $resident['household_member_id'], $targetUserId]);

                            if (!$householdId) {
                                $hstmt = $pdo->prepare(
                                    'SELECT household_id FROM household_members WHERE member_id = ?'
                                );
                                $hstmt->execute([$resident['household_member_id']]);
                                $householdId = (int) $hstmt->fetchColumn();
                            }
                        }

                        if ($householdId) {
                            $pdo->prepare(
                                "UPDATE residents
                                 SET census_completed = TRUE,
                                     census_completed_at = COALESCE(census_completed_at, NOW()),
                                     household_id = COALESCE(household_id, ?)
                                 WHERE resident_id = ?"
                            )->execute([$householdId, $resident['resident_id']]);

                            evaluate_household_eligibility($pdo, $householdId, $adminId);
                        }
                    }

                    log_activity($pdo, $adminId, "Approved account #{$targetUserId}", 'update');

                    $pdo->commit();
                    flash('success', 'Account approved.');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('Account approval failed: ' . $e->getMessage());
                    $errors[] = 'Something went wrong while approving the account. Please try again.';
                }
                }
            } elseif ($action === 'reject') {
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') {
                    $errors[] = 'A rejection reason is required so the applicant can fix their submission.';
                } elseif (mb_strlen($reason) > 200) {
                    // verification_documents.rejection_reason is VARCHAR(255)
                    // and the audit line below embeds the reason too - an
                    // uncapped value used to throw SQLSTATE 22001 AFTER the
                    // status had already flipped, leaving a half-done reject
                    // behind a 500 page.
                    $errors[] = 'Keep the rejection reason to 200 characters or fewer.';
                } elseif ($targetUserId === $adminId) {
                    $errors[] = 'You cannot reject the account you are currently signed in with.';
                } elseif ($targetStatus !== 'pending') {
                    $errors[] = 'Only accounts waiting for approval can be rejected.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $lockStmt = $pdo->prepare(
                            'SELECT role, account_status FROM users WHERE user_id = ? FOR UPDATE'
                        );
                        $lockStmt->execute([$targetUserId]);
                        $lockedTarget = $lockStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$lockedTarget || $lockedTarget['account_status'] !== 'pending') {
                            throw new RuntimeException('Account is no longer pending.');
                        }

                        $rejectedStmt = $pdo->prepare(
                            "UPDATE users SET account_status = 'rejected' WHERE user_id = ? AND account_status = 'pending'"
                        );
                        $rejectedStmt->execute([$targetUserId]);
                        if ($rejectedStmt->rowCount() === 0) {
                            throw new RuntimeException('Account is no longer pending.');
                        }
                        if (in_array($lockedTarget['role'], ['resident', 'staff'], true)) {
                            $pdo->prepare(
                                "UPDATE users
                                 SET email = CONCAT('rejected-', user_id, '@invalid.local'),
                                     email_verified_at = NULL,
                                     email_verification_required = FALSE
                                 WHERE user_id = ?"
                            )->execute([$targetUserId]);
                            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')
                                ->execute([$targetUserId]);
                            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id = ?')
                                ->execute([$targetUserId]);
                        }
                        if ($lockedTarget['role'] === 'resident') {
                            $pdo->prepare(
                                'UPDATE household_members SET linked_user_id = NULL WHERE linked_user_id = ?'
                            )->execute([$targetUserId]);
                            $pdo->prepare(
                                'UPDATE residents SET household_member_id = NULL WHERE user_id = ?'
                            )->execute([$targetUserId]);
                        }
                        $pdo->prepare(
                            "UPDATE verification_documents
                             SET verified_status = 'rejected', verified_by = ?, verified_at = NOW(), rejection_reason = ?
                             WHERE user_id = ?"
                        )->execute([$adminId, $reason, $targetUserId]);
                        log_activity($pdo, $adminId, "Rejected account #{$targetUserId}: {$reason}", 'update');

                        $pdo->commit();
                        flash(
                            'success',
                            $lockedTarget['role'] === 'resident'
                                ? 'Resident application rejected. The email address has been released. Use Create Account to submit a new request.'
                                : ($lockedTarget['role'] === 'staff'
                                    ? 'Staff application rejected. The email address has been released for a new registration.'
                                    : 'Account rejected.')
                        );
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        error_log('Account rejection failed: ' . $e->getMessage());
                        $errors[] = 'Something went wrong while rejecting the account. No changes were saved.';
                    }
                }
            } elseif ($action === 'archive') {
                if ($targetUserId === $adminId) {
                    $errors[] = 'You cannot archive the account you are currently signed in with.';
                } elseif ($targetStatus === false) {
                    // fetchColumn() returns false for a nonexistent id; the
                    // old flow fell through that as "not an admin", skipped
                    // the last-admin guard and flashed "Account archived."
                    // for an account that was never there.
                    $errors[] = 'That account does not exist.';
                } elseif ($targetStatus === 'archived') {
                    $errors[] = 'That account is already archived.';
                } else {
                    $blockedReason = null;
                    try {
                        $pdo->beginTransaction();

                        // Lock the target row so the last-admin guard and the
                        // status change cannot interleave with a concurrent
                        // archive of the other admin.
                        $lockStmt = $pdo->prepare(
                            'SELECT role, account_status FROM users WHERE user_id = ? FOR UPDATE'
                        );
                        $lockStmt->execute([$targetUserId]);
                        $target = $lockStmt->fetch(PDO::FETCH_ASSOC);
                        if (!$target) {
                            throw new RuntimeException('Account not found.');
                        }

                        if ($target['role'] === 'admin' && $target['account_status'] === 'active') {
                            $activeAdmins = (int) $pdo->query(
                                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'active'"
                            )->fetchColumn();
                            if ($activeAdmins <= 1) {
                                $blockedReason = 'You cannot archive the only remaining active Admin account.';
                                throw new RuntimeException('Last active admin.');
                            }
                        }

                        $archivedStmt = $pdo->prepare(
                            "UPDATE users SET account_status = 'archived' WHERE user_id = ? AND account_status <> 'archived'"
                        );
                        $archivedStmt->execute([$targetUserId]);
                        if ($archivedStmt->rowCount() === 0) {
                            throw new RuntimeException('Account already archived.');
                        }
                        log_activity($pdo, $adminId, "Archived account #{$targetUserId}", 'delete');

                        $pdo->commit();
                        flash('success', 'Account archived.');
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        if ($blockedReason !== null) {
                            $errors[] = $blockedReason;
                        } elseif ($e->getMessage() === 'Account already archived.') {
                            $errors[] = 'That account is already archived.';
                        } else {
                            error_log('Account archive failed: ' . $e->getMessage());
                            $errors[] = 'Something went wrong while archiving the account. No changes were saved.';
                        }
                    }
                }
            }
            if (empty($errors)) {
                redirect('accounts.php');
            }
        }
    }
}

$pending = $pdo->query(
    "SELECT u.user_id, u.full_name, u.email, u.role, u.created_at,
            (SELECT vd.document_id FROM verification_documents vd
             WHERE vd.user_id = u.user_id ORDER BY vd.document_id DESC LIMIT 1) AS document_id,
            r.resident_id
     FROM users u
     LEFT JOIN residents r ON r.user_id = u.user_id
     WHERE u.account_status = 'pending'
     ORDER BY u.created_at ASC"
)->fetchAll();

$allAccounts = $pdo->query(
    "SELECT user_id, full_name, email, role, account_status, created_at
     FROM users
     ORDER BY FIELD(account_status, 'pending','active','rejected','archived'), created_at DESC"
)->fetchAll();

$statusBadge = [
    'pending'  => 'badge-pending',
    'active'   => 'badge-active',
    'rejected' => 'badge-rejected',
    'archived' => 'badge-archived',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Accounts - Barangay 420 Admin</title>
<?php include __DIR__ . '/partials/head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/layout-start.php'; ?>

  <h1>Accounts</h1>

  <?php if ($msg = flash('success')): ?>
    <div class="alert alert-success"><?= e($msg) ?></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="admin-section">
    <h2>Pending approval (<?= count($pending) ?>)</h2>

    <?php if (empty($pending)): ?>
      <p class="muted-note">Nothing waiting right now.</p>
    <?php else: ?>
      <div class="table-scroll">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Email</th><th>Role</th><th>Submitted</th><th>ID</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $p): ?>
            <tr>
              <td><?= e($p['full_name']) ?></td>
              <td><?= e($p['email']) ?></td>
              <td><?= e(ucfirst($p['role'])) ?></td>
              <td><?= e(date('M j, Y', strtotime($p['created_at']))) ?></td>
              <td>
                <?php if ($p['document_id']): ?>
                  <a href="view-document.php?id=<?= (int) $p['document_id'] ?>" target="_blank">View</a>
                <?php else: ?>
                  <span class="muted-note">none</span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" class="inline-form">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                  <input type="text" name="reason" class="reason-input" maxlength="200" placeholder="Reason required when rejecting">
                  <?php if ($p['resident_id']): ?>
                    <a href="view-registration.php?id=<?= (int) $p['user_id'] ?>" class="btn-approve btn-review">Review</a>
                  <?php endif; ?>
                  <button type="submit" name="action" value="approve" class="btn-approve">Approve</button>
                  <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="admin-section">
    <h2>All accounts (<span id="accountCount"><?= count($allAccounts) ?></span>)</h2>

    <div class="filter-bar">
      <div class="filter-search">
        <label for="account-search">Search</label>
        <div class="search-field">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="7"></circle>
            <line x1="21" y1="21" x2="16.5" y2="16.5"></line>
          </svg>
          <input type="text" id="account-search" placeholder="Search by name or email..." autocomplete="off">
        </div>
      </div>
      <div>
        <label for="account-role">Role</label>
        <select id="account-role">
          <option value="">All Roles</option>
          <option value="resident">Resident</option>
          <option value="staff">Staff</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div>
        <label for="account-status">Status</label>
        <select id="account-status">
          <option value="">All Statuses</option>
          <option value="active">Active</option>
          <option value="pending">Pending Approval</option>
          <option value="rejected">Rejected</option>
          <option value="archived">Archived</option>
        </select>
      </div>
      <div>
        <label for="account-sort">Sort</label>
        <select id="account-sort">
          <option value="name-az">Name (A&ndash;Z)</option>
          <option value="name-za">Name (Z&ndash;A)</option>
          <option value="email-az">Email (A&ndash;Z)</option>
          <option value="email-za">Email (Z&ndash;A)</option>
          <option value="newest">Newest First</option>
          <option value="oldest">Oldest First</option>
        </select>
      </div>
    </div>

    <div class="table-scroll">
    <table class="admin-table">
      <thead>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Action</th></tr>
      </thead>
      <tbody id="accountsBody">
        <?php foreach ($allAccounts as $a): ?>
          <?php
          $registrationEmailReleased = in_array($a['role'], ['resident', 'staff'], true)
              && $a['account_status'] === 'rejected'
              && $a['email'] === 'rejected-' . $a['user_id'] . '@invalid.local';
          $emailLabel = $registrationEmailReleased ? 'Released after rejection' : $a['email'];
          ?>
          <tr data-name="<?= e(strtolower($a['full_name'])) ?>" data-email="<?= e(strtolower($emailLabel)) ?>" data-role="<?= e($a['role']) ?>" data-status="<?= e($a['account_status']) ?>" data-ts="<?= (int) strtotime($a['created_at']) ?>">
            <td><?= e($a['full_name']) ?></td>
            <td><?= e($emailLabel) ?></td>
            <td><?= e(ucfirst($a['role'])) ?></td>
            <td><span class="badge <?= $statusBadge[$a['account_status']] ?? '' ?>"><?= e(ucfirst($a['account_status'])) ?></span></td>
            <td>
              <?php if ($a['account_status'] === 'active'): ?>
                <form method="POST" class="inline-form" data-confirm data-confirm-title="Archive this account?" data-confirm-message="The account is deactivated and moved out of the active list. Its history is kept intact." data-confirm-label="Archive account">
                  <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                  <input type="hidden" name="user_id" value="<?= (int) $a['user_id'] ?>">
                  <button type="submit" name="action" value="archive" class="btn-archive">Archive</button>
                </form>
              <?php else: ?>
                <span class="muted-note">&mdash;</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<script>
(function () {
  document.querySelectorAll('form.inline-form input[name="reason"]').forEach(function (reason) {
    var form = reason.closest('form');
    var rejectBtn = form && form.querySelector('button[value="reject"]');
    if (!form || !rejectBtn) return;
    form.addEventListener('submit', function (e) {
      if (e.submitter && e.submitter.value === 'reject' && !reason.value.trim()) {
        e.preventDefault();
        alert('A rejection reason is required so the applicant can fix their submission.');
        reason.focus();
      }
    });
  });
})();
</script>
<script>
(function () {
  var body = document.getElementById('accountsBody');
  var searchInput = document.getElementById('account-search');
  var roleSelect = document.getElementById('account-role');
  var statusSelect = document.getElementById('account-status');
  var sortSelect = document.getElementById('account-sort');
  var countEl = document.getElementById('accountCount');
  if (!body || !searchInput || !roleSelect || !statusSelect || !sortSelect || !countEl) return;

  var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-name]'));
  var total = rows.length;
  var emptyRow = null;

  function compareStrings(a, b) {
    return a < b ? -1 : (a > b ? 1 : 0);
  }

  function applyFilters() {
    var query = searchInput.value.trim().toLowerCase();
    var role = roleSelect.value;
    var status = statusSelect.value;
    var sort = sortSelect.value;

    var matched = rows.filter(function (r) {
      if (query && r.dataset.name.indexOf(query) === -1 && r.dataset.email.indexOf(query) === -1) return false;
      if (role && r.dataset.role !== role) return false;
      if (status && r.dataset.status !== status) return false;
      return true;
    });

    matched.sort(function (a, b) {
      switch (sort) {
        case 'name-za':  return compareStrings(b.dataset.name, a.dataset.name);
        case 'email-az': return compareStrings(a.dataset.email, b.dataset.email);
        case 'email-za': return compareStrings(b.dataset.email, a.dataset.email);
        case 'newest':   return b.dataset.ts - a.dataset.ts;
        case 'oldest':   return a.dataset.ts - b.dataset.ts;
        default:         return compareStrings(a.dataset.name, b.dataset.name);
      }
    });

    while (body.firstChild) body.removeChild(body.firstChild);
    for (var i = 0; i < matched.length; i++) body.appendChild(matched[i]);

    var filtered = !!(query || role || status || sort !== 'name-az');
    countEl.textContent = filtered ? matched.length + ' of ' + total : total;

    if (matched.length === 0) {
      if (!emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.id = 'accountsEmpty';
        emptyRow.innerHTML = '<td colspan="5" class="table-empty">No accounts match your search or filter criteria.</td>';
      }
      body.appendChild(emptyRow);
    } else if (emptyRow) {
      emptyRow.remove();
      emptyRow = null;
    }
  }

  searchInput.addEventListener('input', applyFilters);
  roleSelect.addEventListener('change', applyFilters);
  statusSelect.addEventListener('change', applyFilters);
  sortSelect.addEventListener('change', applyFilters);
})();
</script>
<?php include __DIR__ . '/partials/layout-end.php'; ?>
</body>
</html>
