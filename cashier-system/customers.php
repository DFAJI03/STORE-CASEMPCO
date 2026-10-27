<?php
// customers.php - customers and how much utang (debt) each one owes.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$errors = [];
$name   = '';
$phone  = '';

// ---------- 1. ADD A CUSTOMER (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $errors[] = 'Customer name is required.';
    } else {
        $check = $pdo->prepare('SELECT id FROM customers WHERE name = ?');
        $check->execute([$name]);
        if ($check->fetch()) {
            $errors[] = 'A customer with that name already exists.';
        }
    }

    if (!$errors) {
        $pdo->prepare('INSERT INTO customers (name, phone) VALUES (?, ?)')
            ->execute([$name, $phone !== '' ? $phone : null]);
        header('Location: customers.php?msg=' . urlencode('Customer added.'));
        exit;
    }
}

// ---------- 2. LOAD CUSTOMERS WITH THEIR BALANCES ----------
// Balance = utang from completed sales (total - paid at the time) - payments received.
$q         = trim($_GET['q'] ?? '');
$onlyOwing = !empty($_GET['owing']);

$stmt = $pdo->prepare(
    "SELECT c.id, c.name, c.phone,
            COALESCE((SELECT SUM(s.total - s.amount_paid) FROM sales s
                      WHERE s.customer_id = c.id AND s.payment_method = 'utang' AND s.status = 'completed'), 0) AS taken,
            COALESCE((SELECT SUM(d.amount) FROM debt_payments d WHERE d.customer_id = c.id), 0) AS paid
     FROM customers c
     WHERE c.name LIKE ?
     ORDER BY c.name"
);
$stmt->execute(['%' . $q . '%']);

$customers = [];
$totalOwed = 0;
foreach ($stmt->fetchAll() as $row) {
    $row['balance'] = round((float)$row['taken'] - (float)$row['paid'], 2);

    if ($row['balance'] > 0) {
        $totalOwed += $row['balance'];
    }
    if ($onlyOwing && $row['balance'] <= 0) {
        continue;
    }
    $customers[] = $row;
}

// Customers who owe the most come first, then by name.
usort($customers, function ($a, $b) {
    return ($b['balance'] <=> $a['balance']) ?: strcasecmp($a['name'], $b['name']);
});

$useBootstrap = true;   // tells header.php and footer.php to load Bootstrap
$pageTitle    = 'Customer Debt';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Customer Debt (Utang)</h1>
    <div class="text-end">
        <div class="text-muted small text-uppercase">Total owed to you</div>
        <div class="fs-3 fw-bold text-danger"><?= money($totalOwed) ?></div>
    </div>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="row g-3">

    <!-- ADD A CUSTOMER -->
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Add customer</div>
            <div class="card-body">
                <form method="post" action="customers.php">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label for="name" class="form-label">Customer name</label>
                        <input id="name" name="name" class="form-control" value="<?= e($name) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone (optional)</label>
                        <input id="phone" name="phone" class="form-control" value="<?= e($phone) ?>">
                    </div>
                    <button class="btn btn-primary" type="submit">Add customer</button>
                </form>
                <p class="text-muted small mt-3 mb-0">
                    You can also add a customer while selling: choose <b>Utang</b> on the Sales page and type a new name.
                </p>
            </div>
        </div>
    </div>

    <!-- CUSTOMER LIST -->
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white">
                <form class="row g-2 align-items-center" method="get" action="customers.php">
                    <div class="col-12 col-md">
                        <input class="form-control" name="q" placeholder="Search customer name" value="<?= e($q) ?>">
                    </div>
                    <div class="col-auto">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="owing" value="1" id="owing"
                                   <?= $onlyOwing ? 'checked' : '' ?>>
                            <label class="form-check-label" for="owing">Only customers who owe</label>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-outline-primary" type="submit">Search</button>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th class="text-end">Utang taken</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$customers): ?>
                            <tr><td colspan="6" class="text-muted">No customers found.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td><?= e($c['name']) ?></td>
                                <td><?= e($c['phone'] ?? '-') ?></td>
                                <td class="text-end"><?= money($c['taken']) ?></td>
                                <td class="text-end"><?= money($c['paid']) ?></td>
                                <td class="text-end">
                                    <?php if ($c['balance'] > 0): ?>
                                        <span class="text-danger fw-bold"><?= money($c['balance']) ?></span>
                                    <?php elseif ($c['balance'] < 0): ?>
                                        <span class="text-success"><?= money(-$c['balance']) ?> credit</span>
                                    <?php else: ?>
                                        <span class="text-muted">0.00</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-outline-secondary btn-sm" href="customer.php?id=<?= (int)$c['id'] ?>">View</a>
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
