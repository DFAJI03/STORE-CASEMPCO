<?php
// users.php - ADMIN ONLY: add staff accounts, reset passwords, turn accounts on or off.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$me           = current_user();
$useBootstrap = true;   // tells header.php and footer.php to load Bootstrap
$pageTitle    = 'Users';

// Only an admin may open this page.
if (($me['role'] ?? '') !== 'admin') {
    http_response_code(403);
    require __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Only an admin can manage users.</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$roles = ['admin' => 'Admin', 'cashier' => 'Cashier', 'stock_manager' => 'Stock manager'];

// Go back to this page with a message (msg = green, error = red).
function back(string $kind, string $text): never
{
    header('Location: users.php?' . $kind . '=' . urlencode($text));
    exit;
}

$errors       = [];
$formName     = '';
$formUsername = '';
$formRole     = 'cashier';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- ADD A USER ----------
    if ($action === 'add') {
        $formName     = trim($_POST['name'] ?? '');
        $formUsername = trim($_POST['username'] ?? '');
        $formRole     = $_POST['role'] ?? 'cashier';
        $password     = $_POST['password'] ?? '';
        $confirm      = $_POST['confirm'] ?? '';

        if ($formName === '') {
            $errors[] = 'Name is required.';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $formUsername)) {
            $errors[] = 'Username must be 3 to 50 characters: letters, numbers, dot, dash, or underscore.';
        }
        if (!isset($roles[$formRole])) {
            $errors[] = 'Choose a valid role.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $confirm) {
            $errors[] = 'The two passwords do not match.';
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $check->execute([$formUsername]);
            if ($check->fetch()) {
                $errors[] = 'That username is already taken.';
            }
        }

        if (!$errors) {
            // password_hash scrambles the password. The real password is never saved.
            $pdo->prepare('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$formName, $formUsername, password_hash($password, PASSWORD_DEFAULT), $formRole]);
            back('msg', 'User ' . $formUsername . ' created.');
        }
    }

    // ---------- RESET A PASSWORD ----------
    if ($action === 'reset') {
        $targetId = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';

        if (strlen($password) < 8) {
            back('error', 'The new password must be at least 8 characters.');
        }

        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
        back('msg', 'Password changed.');
    }

    // ---------- CHANGE A ROLE ----------
    if ($action === 'role') {
        $targetId = (int)($_POST['id'] ?? 0);
        $newRole  = $_POST['role'] ?? '';

        if (!isset($roles[$newRole])) {
            back('error', 'Choose a valid role.');
        }
        if ($targetId === (int)$me['id']) {
            back('error', 'You cannot change your own role.');
        }

        $stmt = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$newRole, $targetId]);
        back('msg', 'Role changed.');
    }

    // ---------- TURN AN ACCOUNT ON OR OFF ----------
    if ($action === 'toggle') {
        $targetId = (int)($_POST['id'] ?? 0);

        if ($targetId === (int)$me['id']) {
            back('error', 'You cannot turn off your own account.');
        }

        $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch();

        if (!$target) {
            back('error', 'User not found.');
        }

        // Always keep at least one active admin, or nobody could manage users.
        if ($target['role'] === 'admin' && $target['is_active']) {
            $others = $pdo->prepare(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?"
            );
            $others->execute([$targetId]);
            if ((int)$others->fetchColumn() === 0) {
                back('error', 'You need at least one active admin.');
            }
        }

        $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$targetId]);
        back('msg', 'Account updated.');
    }
}

$users = $pdo->query(
    'SELECT id, name, username, role, is_active FROM users ORDER BY name'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">Users</h1>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= e($_GET['error']) ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="row g-3">

    <!-- ADD A USER -->
    <div class="col-12 col-xl-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Add user</div>
            <div class="card-body">
                <form method="post" action="users.php">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input id="name" name="name" class="form-control" value="<?= e($formName) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input id="username" name="username" class="form-control" value="<?= e($formUsername) ?>"
                               autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label for="role" class="form-label">Role</label>
                        <select id="role" name="role" class="form-select">
                            <?php foreach ($roles as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $formRole === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password (at least 8 characters)</label>
                        <input id="password" name="password" type="password" class="form-control"
                               autocomplete="new-password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm" class="form-label">Confirm password</label>
                        <input id="confirm" name="confirm" type="password" class="form-control"
                               autocomplete="new-password" required>
                    </div>
                    <button class="btn btn-primary" type="submit">Create user</button>
                </form>
            </div>
        </div>
    </div>

    <!-- USER LIST -->
    <div class="col-12 col-xl-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">All users</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Reset password</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <?php $isMe = (int)$u['id'] === (int)$me['id']; ?>
                            <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>">
                                <td>
                                    <?= e($u['name']) ?><?= $isMe ? ' <span class="badge text-bg-info">You</span>' : '' ?>
                                    <div class="small text-muted"><?= e($u['username']) ?></div>
                                </td>
                                <td>
                                    <?php if ($isMe): ?>
                                        <?= e($roles[$u['role']] ?? $u['role']) ?>
                                    <?php else: ?>
                                        <form method="post" action="users.php">
                                            <input type="hidden" name="action" value="role">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                                                <?php foreach ($roles as $value => $label): ?>
                                                    <option value="<?= e($value) ?>" <?= $u['role'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge text-bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
                                        <?= $u['is_active'] ? 'Active' : 'Off' ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="post" action="users.php" class="input-group input-group-sm" style="min-width:220px;">
                                        <input type="hidden" name="action" value="reset">
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <input name="password" type="password" class="form-control" minlength="8"
                                               placeholder="New password" autocomplete="new-password" required>
                                        <button class="btn btn-outline-primary" type="submit">Set</button>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <?php if (!$isMe): ?>
                                        <form method="post" action="users.php"
                                              onsubmit="return confirm('<?= $u['is_active'] ? 'Turn off this account? They will not be able to log in.' : 'Turn this account back on?' ?>');">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'danger' : 'success' ?>" type="submit">
                                                <?= $u['is_active'] ? 'Turn off' : 'Turn on' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
