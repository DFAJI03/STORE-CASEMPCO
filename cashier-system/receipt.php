<?php
// receipt.php - show one sale as a receipt. You can print it or void it.
// Voiding cancels the sale and puts the items back into stock.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// ---------- 1. VOID A SALE (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    // Only an admin may void a sale.
    if (!is_admin()) {
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Only an admin can void a sale.'));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lock the sale so it cannot be voided twice at the same moment.
        $find = $pdo->prepare('SELECT id, invoice_no, status FROM sales WHERE id = ? FOR UPDATE');
        $find->execute([$id]);
        $sale = $find->fetch();

        if (!$sale || $sale['status'] !== 'completed') {
            $pdo->rollBack();
            header('Location: receipt.php?id=' . $id . '&error=' . urlencode('This sale cannot be voided.'));
            exit;
        }

        $itemsStmt = $pdo->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ?');
        $itemsStmt->execute([$id]);

        $pdo->prepare("UPDATE sales SET status = 'void' WHERE id = ?")->execute([$id]);

        $giveBack = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');
        $addMove  = $pdo->prepare(
            "INSERT INTO stock_movements (product_id, type, qty_change, reference_id, note)
             VALUES (?, 'void', ?, ?, ?)"
        );

        foreach ($itemsStmt->fetchAll() as $item) {
            $giveBack->execute([$item['qty'], $item['product_id']]);
            $addMove->execute([$item['product_id'], $item['qty'], $id, 'Void ' . $sale['invoice_no']]);
        }

        $pdo->commit();
        header('Location: receipt.php?id=' . $id . '&msg=' . urlencode('Sale voided. Stock restored.'));
        exit;
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Could not void the sale.'));
        exit;
    }
}

// ---------- 2. LOAD THE SALE ----------
$stmt = $pdo->prepare(
    'SELECT s.*, u.name AS cashier, cu.name AS customer
     FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN customers cu ON cu.id = s.customer_id
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$sale = $stmt->fetch();

// A cashier may only open receipts of sales they made themselves.
if ($sale && !is_admin() && (int)$sale['user_id'] !== (int)current_user()['id']) {
    $sale = false;   // shown below as "Sale not found."
}

$pageTitle = 'Receipt';
require __DIR__ . '/includes/header.php';

if (!$sale) {
    echo '<h1>Receipt</h1><div class="alert alert--error">Sale not found.</div>';
    echo '<a class="btn" href="sales_history.php">Back to sales history</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT si.qty, si.unit_price, si.line_total, p.name
     FROM sale_items si
     JOIN products p ON p.id = si.product_id
     WHERE si.sale_id = ?
     ORDER BY si.id'
);
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$isVoid  = $sale['status'] === 'void';
$saleDay = date('Y-m-d', strtotime($sale['created_at']));
?>

<h1 class="no-print">Receipt</h1>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert--ok no-print"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert--error no-print"><?= e($_GET['error']) ?></div>
<?php endif; ?>

<div class="card receipt">
    <h2>CASEMPCO</h2>
    <p class="receipt__center">
        Invoice <?= e($sale['invoice_no']) ?><br>
        <?= e(date('M d, Y h:i A', strtotime($sale['created_at']))) ?>
        <?php if (!empty($sale['cashier'])): ?><br>Cashier: <?= e($sale['cashier']) ?><?php endif; ?>
        <?php if ($isVoid): ?><br><span class="badge badge--void">VOID</span><?php endif; ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?></td>
                    <td class="num"><?= (int)$item['qty'] ?></td>
                    <td class="num"><?= money($item['unit_price']) ?></td>
                    <td class="num"><?= money($item['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span><?= money($sale['subtotal']) ?></span></div>
        <?php if ((float)$sale['discount'] > 0): ?>
            <div><span>Discount</span><span>-<?= money($sale['discount']) ?></span></div>
        <?php endif; ?>
        <div class="totals__grand"><span>Total</span><span><?= money($sale['total']) ?></span></div>
        <?php if ($sale['payment_method'] === 'utang'): ?>
            <div><span>Paid now</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Utang: <?= e($sale['customer'] ?? '') ?></span><span><?= money($sale['total'] - $sale['amount_paid']) ?></span></div>
        <?php else: ?>
            <div><span><?= e(ucfirst($sale['payment_method'])) ?> received</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Change</span><span><?= money($sale['change_amount']) ?></span></div>
        <?php endif; ?>
    </div>

    <p class="receipt__center">Thank you!</p>
</div>

<p class="no-print receipt-actions">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <a class="btn btn--gray" href="sales_history.php?date=<?= e($saleDay) ?>">Back to sales history</a>
</p>

<?php if (!$isVoid && is_admin()): ?>
    <form class="no-print" method="post" action="receipt.php"
          onsubmit="return confirm('Void this sale? The items will be put back into stock.');">
        <input type="hidden" name="action" value="void">
        <input type="hidden" name="id" value="<?= (int)$sale['id'] ?>">
        <button type="submit" class="btn btn--danger">Void this sale</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
// receipt.php - show one sale as a receipt. You can print it or void it.
// Voiding cancels the sale and puts the items back into stock.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// ---------- 1. VOID A SALE (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    // Only an admin may void a sale.
    if (!is_admin()) {
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Only an admin can void a sale.'));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lock the sale so it cannot be voided twice at the same moment.
        $find = $pdo->prepare('SELECT id, invoice_no, status FROM sales WHERE id = ? FOR UPDATE');
        $find->execute([$id]);
        $sale = $find->fetch();

        if (!$sale || $sale['status'] !== 'completed') {
            $pdo->rollBack();
            header('Location: receipt.php?id=' . $id . '&error=' . urlencode('This sale cannot be voided.'));
            exit;
        }

        $itemsStmt = $pdo->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ?');
        $itemsStmt->execute([$id]);

        $pdo->prepare("UPDATE sales SET status = 'void' WHERE id = ?")->execute([$id]);

        $giveBack = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');
        $addMove  = $pdo->prepare(
            "INSERT INTO stock_movements (product_id, type, qty_change, reference_id, note)
             VALUES (?, 'void', ?, ?, ?)"
        );

        foreach ($itemsStmt->fetchAll() as $item) {
            $giveBack->execute([$item['qty'], $item['product_id']]);
            $addMove->execute([$item['product_id'], $item['qty'], $id, 'Void ' . $sale['invoice_no']]);
        }

        $pdo->commit();
        header('Location: receipt.php?id=' . $id . '&msg=' . urlencode('Sale voided. Stock restored.'));
        exit;
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Could not void the sale.'));
        exit;
    }
}

// ---------- 2. LOAD THE SALE ----------
$stmt = $pdo->prepare(
    'SELECT s.*, u.name AS cashier, cu.name AS customer
     FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN customers cu ON cu.id = s.customer_id
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$sale = $stmt->fetch();

// A cashier may only open receipts of sales they made themselves.
if ($sale && !is_admin() && (int)$sale['user_id'] !== (int)current_user()['id']) {
    $sale = false;   // shown below as "Sale not found."
}

$pageTitle = 'Receipt';
require __DIR__ . '/includes/header.php';

if (!$sale) {
    echo '<h1>Receipt</h1><div class="alert alert--error">Sale not found.</div>';
    echo '<a class="btn" href="sales_history.php">Back to sales history</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT si.qty, si.unit_price, si.line_total, p.name
     FROM sale_items si
     JOIN products p ON p.id = si.product_id
     WHERE si.sale_id = ?
     ORDER BY si.id'
);
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$isVoid  = $sale['status'] === 'void';
$saleDay = date('Y-m-d', strtotime($sale['created_at']));
?>

<h1 class="no-print">Receipt</h1>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert--ok no-print"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert--error no-print"><?= e($_GET['error']) ?></div>
<?php endif; ?>

<div class="card receipt">
    <h2>CASEMPCO</h2>
    <p class="receipt__center">
        Invoice <?= e($sale['invoice_no']) ?><br>
        <?= e(date('M d, Y h:i A', strtotime($sale['created_at']))) ?>
        <?php if (!empty($sale['cashier'])): ?><br>Cashier: <?= e($sale['cashier']) ?><?php endif; ?>
        <?php if ($isVoid): ?><br><span class="badge badge--void">VOID</span><?php endif; ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?></td>
                    <td class="num"><?= (int)$item['qty'] ?></td>
                    <td class="num"><?= money($item['unit_price']) ?></td>
                    <td class="num"><?= money($item['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span><?= money($sale['subtotal']) ?></span></div>
        <?php if ((float)$sale['discount'] > 0): ?>
            <div><span>Discount</span><span>-<?= money($sale['discount']) ?></span></div>
        <?php endif; ?>
        <div class="totals__grand"><span>Total</span><span><?= money($sale['total']) ?></span></div>
        <?php if ($sale['payment_method'] === 'utang'): ?>
            <div><span>Paid now</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Utang: <?= e($sale['customer'] ?? '') ?></span><span><?= money($sale['total'] - $sale['amount_paid']) ?></span></div>
        <?php else: ?>
            <div><span><?= e(ucfirst($sale['payment_method'])) ?> received</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Change</span><span><?= money($sale['change_amount']) ?></span></div>
        <?php endif; ?>
    </div>

    <p class="receipt__center">Thank you!</p>
</div>

<p class="no-print receipt-actions">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <a class="btn btn--gray" href="sales_history.php?date=<?= e($saleDay) ?>">Back to sales history</a>
</p>

<?php if (!$isVoid && is_admin()): ?>
    <form class="no-print" method="post" action="receipt.php"
          onsubmit="return confirm('Void this sale? The items will be put back into stock.');">
        <input type="hidden" name="action" value="void">
        <input type="hidden" name="id" value="<?= (int)$sale['id'] ?>">
        <button type="submit" class="btn btn--danger">Void this sale</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
// receipt.php - show one sale as a receipt. You can print it or void it.
// Voiding cancels the sale and puts the items back into stock.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// ---------- 1. VOID A SALE (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    // Only an admin may void a sale.
    if (!is_admin()) {
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Only an admin can void a sale.'));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lock the sale so it cannot be voided twice at the same moment.
        $find = $pdo->prepare('SELECT id, invoice_no, status FROM sales WHERE id = ? FOR UPDATE');
        $find->execute([$id]);
        $sale = $find->fetch();

        if (!$sale || $sale['status'] !== 'completed') {
            $pdo->rollBack();
            header('Location: receipt.php?id=' . $id . '&error=' . urlencode('This sale cannot be voided.'));
            exit;
        }

        $itemsStmt = $pdo->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ?');
        $itemsStmt->execute([$id]);

        $pdo->prepare("UPDATE sales SET status = 'void' WHERE id = ?")->execute([$id]);

        $giveBack = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');
        $addMove  = $pdo->prepare(
            "INSERT INTO stock_movements (product_id, type, qty_change, reference_id, note)
             VALUES (?, 'void', ?, ?, ?)"
        );

        foreach ($itemsStmt->fetchAll() as $item) {
            $giveBack->execute([$item['qty'], $item['product_id']]);
            $addMove->execute([$item['product_id'], $item['qty'], $id, 'Void ' . $sale['invoice_no']]);
        }

        $pdo->commit();
        header('Location: receipt.php?id=' . $id . '&msg=' . urlencode('Sale voided. Stock restored.'));
        exit;
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Could not void the sale.'));
        exit;
    }
}

// ---------- 2. LOAD THE SALE ----------
$stmt = $pdo->prepare(
    'SELECT s.*, u.name AS cashier, cu.name AS customer
     FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN customers cu ON cu.id = s.customer_id
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$sale = $stmt->fetch();

// A cashier may only open receipts of sales they made themselves.
if ($sale && !is_admin() && (int)$sale['user_id'] !== (int)current_user()['id']) {
    $sale = false;   // shown below as "Sale not found."
}

$pageTitle = 'Receipt';
require __DIR__ . '/includes/header.php';

if (!$sale) {
    echo '<h1>Receipt</h1><div class="alert alert--error">Sale not found.</div>';
    echo '<a class="btn" href="sales_history.php">Back to sales history</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT si.qty, si.unit_price, si.line_total, p.name
     FROM sale_items si
     JOIN products p ON p.id = si.product_id
     WHERE si.sale_id = ?
     ORDER BY si.id'
);
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$isVoid  = $sale['status'] === 'void';
$saleDay = date('Y-m-d', strtotime($sale['created_at']));
?>

<h1 class="no-print">Receipt</h1>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert--ok no-print"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert--error no-print"><?= e($_GET['error']) ?></div>
<?php endif; ?>

<div class="card receipt">
    <h2>CASEMPCO</h2>
    <p class="receipt__center">
        Invoice <?= e($sale['invoice_no']) ?><br>
        <?= e(date('M d, Y h:i A', strtotime($sale['created_at']))) ?>
        <?php if (!empty($sale['cashier'])): ?><br>Cashier: <?= e($sale['cashier']) ?><?php endif; ?>
        <?php if ($isVoid): ?><br><span class="badge badge--void">VOID</span><?php endif; ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?></td>
                    <td class="num"><?= (int)$item['qty'] ?></td>
                    <td class="num"><?= money($item['unit_price']) ?></td>
                    <td class="num"><?= money($item['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span><?= money($sale['subtotal']) ?></span></div>
        <?php if ((float)$sale['discount'] > 0): ?>
            <div><span>Discount</span><span>-<?= money($sale['discount']) ?></span></div>
        <?php endif; ?>
        <div class="totals__grand"><span>Total</span><span><?= money($sale['total']) ?></span></div>
        <?php if ($sale['payment_method'] === 'utang'): ?>
            <div><span>Paid now</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Utang: <?= e($sale['customer'] ?? '') ?></span><span><?= money($sale['total'] - $sale['amount_paid']) ?></span></div>
        <?php else: ?>
            <div><span><?= e(ucfirst($sale['payment_method'])) ?> received</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Change</span><span><?= money($sale['change_amount']) ?></span></div>
        <?php endif; ?>
    </div>

    <p class="receipt__center">Thank you!</p>
</div>

<p class="no-print receipt-actions">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <a class="btn btn--gray" href="sales_history.php?date=<?= e($saleDay) ?>">Back to sales history</a>
</p>

<?php if (!$isVoid && is_admin()): ?>
    <form class="no-print" method="post" action="receipt.php"
          onsubmit="return confirm('Void this sale? The items will be put back into stock.');">
        <input type="hidden" name="action" value="void">
        <input type="hidden" name="id" value="<?= (int)$sale['id'] ?>">
        <button type="submit" class="btn btn--danger">Void this sale</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php
// receipt.php - show one sale as a receipt. You can print it or void it.
// Voiding cancels the sale and puts the items back into stock.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// ---------- 1. VOID A SALE (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    // Only an admin may void a sale.
    if (!is_admin()) {
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Only an admin can void a sale.'));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Lock the sale so it cannot be voided twice at the same moment.
        $find = $pdo->prepare('SELECT id, invoice_no, status FROM sales WHERE id = ? FOR UPDATE');
        $find->execute([$id]);
        $sale = $find->fetch();

        if (!$sale || $sale['status'] !== 'completed') {
            $pdo->rollBack();
            header('Location: receipt.php?id=' . $id . '&error=' . urlencode('This sale cannot be voided.'));
            exit;
        }

        $itemsStmt = $pdo->prepare('SELECT product_id, qty FROM sale_items WHERE sale_id = ?');
        $itemsStmt->execute([$id]);

        $pdo->prepare("UPDATE sales SET status = 'void' WHERE id = ?")->execute([$id]);

        $giveBack = $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?');
        $addMove  = $pdo->prepare(
            "INSERT INTO stock_movements (product_id, type, qty_change, reference_id, note)
             VALUES (?, 'void', ?, ?, ?)"
        );

        foreach ($itemsStmt->fetchAll() as $item) {
            $giveBack->execute([$item['qty'], $item['product_id']]);
            $addMove->execute([$item['product_id'], $item['qty'], $id, 'Void ' . $sale['invoice_no']]);
        }

        $pdo->commit();
        header('Location: receipt.php?id=' . $id . '&msg=' . urlencode('Sale voided. Stock restored.'));
        exit;
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: receipt.php?id=' . $id . '&error=' . urlencode('Could not void the sale.'));
        exit;
    }
}

// ---------- 2. LOAD THE SALE ----------
$stmt = $pdo->prepare(
    'SELECT s.*, u.name AS cashier, cu.name AS customer
     FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN customers cu ON cu.id = s.customer_id
     WHERE s.id = ?'
);
$stmt->execute([$id]);
$sale = $stmt->fetch();

// A cashier may only open receipts of sales they made themselves.
if ($sale && !is_admin() && (int)$sale['user_id'] !== (int)current_user()['id']) {
    $sale = false;   // shown below as "Sale not found."
}

$pageTitle = 'Receipt';
require __DIR__ . '/includes/header.php';

if (!$sale) {
    echo '<h1>Receipt</h1><div class="alert alert--error">Sale not found.</div>';
    echo '<a class="btn" href="sales_history.php">Back to sales history</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = $pdo->prepare(
    'SELECT si.qty, si.unit_price, si.line_total, p.name
     FROM sale_items si
     JOIN products p ON p.id = si.product_id
     WHERE si.sale_id = ?
     ORDER BY si.id'
);
$stmt->execute([$id]);
$items = $stmt->fetchAll();

$isVoid  = $sale['status'] === 'void';
$saleDay = date('Y-m-d', strtotime($sale['created_at']));
?>

<h1 class="no-print">Receipt</h1>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert--ok no-print"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert--error no-print"><?= e($_GET['error']) ?></div>
<?php endif; ?>

<div class="card receipt">
    <h2>CASEMPCO</h2>
    <p class="receipt__center">
        Invoice <?= e($sale['invoice_no']) ?><br>
        <?= e(date('M d, Y h:i A', strtotime($sale['created_at']))) ?>
        <?php if (!empty($sale['cashier'])): ?><br>Cashier: <?= e($sale['cashier']) ?><?php endif; ?>
        <?php if ($isVoid): ?><br><span class="badge badge--void">VOID</span><?php endif; ?>
    </p>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?= e($item['name']) ?></td>
                    <td class="num"><?= (int)$item['qty'] ?></td>
                    <td class="num"><?= money($item['unit_price']) ?></td>
                    <td class="num"><?= money($item['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div><span>Subtotal</span><span><?= money($sale['subtotal']) ?></span></div>
        <?php if ((float)$sale['discount'] > 0): ?>
            <div><span>Discount</span><span>-<?= money($sale['discount']) ?></span></div>
        <?php endif; ?>
        <div class="totals__grand"><span>Total</span><span><?= money($sale['total']) ?></span></div>
        <?php if ($sale['payment_method'] === 'utang'): ?>
            <div><span>Paid now</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Utang: <?= e($sale['customer'] ?? '') ?></span><span><?= money($sale['total'] - $sale['amount_paid']) ?></span></div>
        <?php else: ?>
            <div><span><?= e(ucfirst($sale['payment_method'])) ?> received</span><span><?= money($sale['amount_paid']) ?></span></div>
            <div><span>Change</span><span><?= money($sale['change_amount']) ?></span></div>
        <?php endif; ?>
    </div>

    <p class="receipt__center">Thank you!</p>
</div>

<p class="no-print receipt-actions">
    <button type="button" class="btn" onclick="window.print()">Print</button>
    <a class="btn btn--gray" href="sales_history.php?date=<?= e($saleDay) ?>">Back to sales history</a>
</p>

<?php if (!$isVoid && is_admin()): ?>
    <form class="no-print" method="post" action="receipt.php"
          onsubmit="return confirm('Void this sale? The items will be put back into stock.');">
        <input type="hidden" name="action" value="void">
        <input type="hidden" name="id" value="<?= (int)$sale['id'] ?>">
        <button type="submit" class="btn btn--danger">Void this sale</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
