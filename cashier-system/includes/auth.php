<?php
// includes/auth.php
// Starts the session, checks that someone is logged in, and checks that their
// ROLE is allowed to open the page. It is loaded automatically by includes/db.php,
// so EVERY page is protected by default. Public pages (login, logout, first-time
// setup) put this line at the very top, before loading db.php:
//     define('PUBLIC_PAGE', true);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,    // JavaScript cannot read the session cookie
        'samesite' => 'Lax',   // the cookie is not sent with requests from other sites
    ]);
    session_start();
}

// The logged-in user as an array (id, name, username, role), or null if nobody is logged in.
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

// ---------- WHO MAY OPEN WHICH PAGE ----------
// Admins can open everything. This list says what the OTHER roles may open.
// A page that is not listed here is for admins only (safe by default), so a
// page you add later stays private until you list it here.
const PAGE_ACCESS = [
    // Cashier: sell, look at their own sales, and handle customer utang.
    'sales.php'         => ['cashier'],
    'api/save_sale.php' => ['cashier'],
    'sales_history.php' => ['cashier'],
    'receipt.php'       => ['cashier'],
    'customers.php'     => ['cashier'],
    'customer.php'      => ['cashier'],

    // Stock manager: products and restocking.
    'products.php'      => ['stock_manager'],
    'purchases.php'     => ['stock_manager'],
];

// Can the logged-in user open this page? ("sales.php", or "api/save_sale.php" for the api folder)
function can_access(string $page): bool
{
    if (is_admin()) {
        return true;
    }
    $role = current_user()['role'] ?? '';
    return in_array($role, PAGE_ACCESS[$page] ?? [], true);
}

// The page each role lands on after logging in.
function home_page_for(string $role): string
{
    return match ($role) {
        'cashier'       => 'sales.php',
        'stock_manager' => 'products.php',
        default         => 'index.php',
    };
}

if (!defined('PUBLIC_PAGE')) {
    $isApi = strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false;
    $page  = ($isApi ? 'api/' : '') . basename($_SERVER['SCRIPT_NAME']);

    // 1. Is the logged-in account still there and switched on? (db.php has already opened $pdo.)
    //    This also picks up a changed role straight away, and logs out accounts that were turned off.
    if (current_user() && isset($pdo)) {
        $stmt = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
        $stmt->execute([current_user()['id']]);
        $row = $stmt->fetch();

        if ($row && $row['is_active']) {
            $_SESSION['user']['role'] = $row['role'];
        } else {
            unset($_SESSION['user']);
        }
    }

    // 2. Is someone logged in?
    if (!current_user()) {
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Your session ended. Please log in again.']);
            exit;
        }
        header('Location: auth/login.php');
        exit;
    }

    // 3. Is this role allowed to open this page?
    if (!can_access($page)) {
        http_response_code(403);

        if ($isApi) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Your account is not allowed to do this.']);
            exit;
        }

        $home = htmlspecialchars(home_page_for(current_user()['role'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
           . '<title>No access</title><link rel="stylesheet" href="css/bootstrap.min.css"></head>'
           . '<body class="bg-light"><div class="container py-5" style="max-width:480px;">'
           . '<div class="card shadow-sm border-0"><div class="card-body text-center">'
           . '<h1 class="h4">No access</h1>'
           . '<p class="text-muted">Your account is not allowed to open this page.</p>'
           . '<a class="btn btn-primary" href="' . $home . '">Go to my page</a> '
           . '<a class="btn btn-outline-secondary" href="auth/logout.php">Log out</a>'
           . '</div></div></div></body></html>';
        exit;
    }
}
