<?php
// stock_movements.php - every change to your stock (sales, purchases, voids, adjustments).
// It is not listed in PAGE_ACCESS (includes/auth.php), so only admins can open it.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// The kinds of movement, and the badge color for each.
$types = [
    'sale'       => ['Sale',       'primary'],
    'purchase'   => ['Purchase',   'success'],
    'adjustment' => ['Adjustment', 'warning'],
    'void'       => ['Void',       'danger'],
];

$q    = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? '';
$date = $_GET['date'] ?? '';

// Build the filter from whatever the person chose.
$where  = [];
$params = [];
if ($q !== '') {
    $where[]  = 'p.name LIKE ?';
    $params[] = '%' . $q . '%';
}
if (isset($types[$type])) {
    $where[]  = 'm.type = ?';
    $params[] = $type;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[]  = 'DATE(m.created_at) = ?';
    $params[] = $date;
}

$sql = 'SELECT m.created_at, m.type, m.qty_change, m.note, p.name
        FROM stock_movements m
        JOIN products p ON p.id = m.product_id'
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY m.id DESC
        LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

$useBootstrap = true;   // tells header.php and footer.php to load Bootstrap
$pageTitle    = 'Stock Movements';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Stock Movements</h1>
    <a class="btn btn-sm btn-outline-secondary" href="index.php">&larr; Dashboard</a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white">
        <form class="row g-2 align-items-center" method="get" action="stock_movements.php">
            <div class="col-12 col-md">
                <input class="form-control form-control-sm" name="q" placeholder="Search product" value="<?= e($q) ?>">
            </div>
            <div class="col-6 col-md-auto">
                <select class="form-select form-select-sm" name="type">
                    <option value="">All types</option>
                    <?php foreach ($types as $value => $info): ?>
                        <option value="<?= e($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= e($info[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-auto">
                <input class="form-control form-control-sm" type="date" name="date" value="<?= e($date) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary" type="submit">Filter</button>
                <a class="btn btn-sm btn-outline-secondary" href="stock_movements.php">Clear</a>
            </div>
        </form>
    </div>

    <?php if (!$movements): ?>
        <div class="card-body text-muted">No stock movements found.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th>Type</th>
                        <th class="text-end">Change</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movements as $m): ?>
                        <?php
                        $change = (int)$m['qty_change'];
                        $info   = $types[$m['type']] ?? [ucfirst($m['type']), 'secondary'];
                        ?>
                        <tr>
                            <td><?= e(date('M d, Y h:i A', strtotime($m['created_at']))) ?></td>
                            <td><?= e($m['name']) ?></td>
                            <td><span class="badge text-bg-<?= e($info[1]) ?>"><?= e($info[0]) ?></span></td>
                            <td class="text-end fw-bold <?= $change >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $change > 0 ? '+' : '' ?><?= $change ?>
                            </td>
                            <td><?= e($m['note'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white text-muted small">
            Showing the latest <?= count($movements) ?> <?= count($movements) === 1 ? 'entry' : 'entries' ?> (up to 200). Use the filters to narrow it down.
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>