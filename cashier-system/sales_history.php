<?php
// sales_history.php - list the sales made on one day, with totals.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// "Today" comes from MySQL, so it always matches the time saved on each sale.
$today = $pdo->query('SELECT CURDATE()')->fetchColumn();

$date = $_GET['date'] ?? $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today;
}

// Admins see every sale. Other roles (cashiers) see only the sales they made.
$where  = 'DATE(s.created_at) = ?';
$params = [$date];
if (!is_admin()) {
    $where   .= ' AND s.user_id = ?';
    $params[] = (int)current_user()['id'];
}

$stmt = $pdo->prepare(
    "SELECT s.id, s.invoice_no, s.created_at, s.total, s.payment_method, s.status,
            (SELECT COALESCE(SUM(qty), 0) FROM sale_items WHERE sale_id = s.id) AS item_count
     FROM sales s
     WHERE $where
     ORDER BY s.id DESC"
);
$stmt->execute($params);
$sales = $stmt->fetchAll();

// Voided sales are listed, but they are not counted in the totals.
$salesCount = 0;
$salesTotal = 0;
foreach ($sales as $s) {
    if ($s['status'] === 'completed') {
        $salesCount++;
        $salesTotal += $s['total'];
    }
}

$pageTitle = 'Sales History';
require __DIR__ . '/includes/header.php';
?>

<h1>Sales History</h1>

<form class="search-bar" method="get" action="sales_history.php">
    <input type="date" name="date" value="<?= e($date) ?>">
    <button class="btn" type="submit">Show</button>
    <?php if ($date !== $today): ?>
        <a class="btn btn--gray" href="sales_history.php">Today</a>
    <?php endif; ?>
</form>

<div class="form-grid">
    <div class="card">
        <h2><?= $salesCount ?></h2>
        <p>Sales on <?= e(date('M d, Y', strtotime($date))) ?></p>
    </div>
    <div class="card">
        <h2><?= money($salesTotal) ?></h2>
        <p>Total sales</p>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Invoice</th>
            <th>Time</th>
            <th class="num">Items</th>
            <th>Payment</th>
            <th class="num">Total</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$sales): ?>
            <tr><td colspan="7">No sales on this day.</td></tr>
        <?php endif; ?>

        <?php foreach ($sales as $s): ?>
            <tr class="<?= $s['status'] === 'void' ? 'inactive' : '' ?>">
                <td><?= e($s['invoice_no']) ?></td>
                <td><?= e(date('h:i A', strtotime($s['created_at']))) ?></td>
                <td class="num"><?= (int)$s['item_count'] ?></td>
                <td><?= e(ucfirst($s['payment_method'])) ?></td>
                <td class="num"><?= money($s['total']) ?></td>
                <td>
                    <span class="badge <?= $s['status'] === 'void' ? 'badge--void' : 'badge--ok' ?>">
                        <?= $s['status'] === 'void' ? 'Void' : 'Completed' ?>
                    </span>
                </td>
                <td><a class="btn btn--small" href="receipt.php?id=<?= (int)$s['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
