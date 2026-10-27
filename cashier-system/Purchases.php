<?php
// purchases.php - record goods bought from a wholesaler and add them to stock.
 
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
 
$errors = [];
 
// ---------- 1. SAVE A PURCHASE (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $supplierId  = (int)($_POST['supplier_id'] ?? 0);
    $newSupplier = trim($_POST['new_supplier'] ?? '');
    $referenceNo = trim($_POST['reference_no'] ?? '');
 
    $productIds = $_POST['product_id'] ?? [];
    $qtys       = $_POST['qty'] ?? [];
    $costs      = $_POST['unit_cost'] ?? [];
 
    // Keep only complete rows. Fully blank rows are ignored.
    $items = [];
    foreach ($productIds as $i => $pid) {
        $pid  = (int)$pid;
        $qty  = (int)($qtys[$i] ?? 0);
        $cost = $costs[$i] ?? '';
 
        if ($pid <= 0 && $qty === 0 && $cost === '') {
            continue;
        }
        if ($pid <= 0 || $qty <= 0 || !is_numeric($cost) || $cost < 0) {
            $errors[] = 'Each row needs a product, a quantity above 0, and a cost.';
            break;
        }
        $items[] = ['product_id' => $pid, 'qty' => $qty, 'cost' => (float)$cost];
    }
 
    if (!$items && !$errors) {
        $errors[] = 'Add at least one item.';
    }
    if ($supplierId <= 0 && $newSupplier === '') {
        $errors[] = 'Choose a wholesaler or type a new one.';
    }
 
    if (!$errors) {
        try {
            // One transaction: the purchase, the stock increase, and the stock log
            // all succeed together, or none of them are saved.
            $pdo->beginTransaction();
 
            // If a new wholesaler name was typed, use it (reuse it if it already exists).
            if ($newSupplier !== '') {
                $find = $pdo->prepare('SELECT id FROM suppliers WHERE name = ?');
                $find->execute([$newSupplier]);
                $supplierId = (int)$find->fetchColumn();
 
                if (!$supplierId) {
                    $pdo->prepare('INSERT INTO suppliers (name) VALUES (?)')->execute([$newSupplier]);
                    $supplierId = (int)$pdo->lastInsertId();
                }
            }
 
            $total = 0;
            foreach ($items as $it) {
                $total += $it['qty'] * $it['cost'];
            }
 
            $pdo->prepare(
                "INSERT INTO purchases (supplier_id, user_id, reference_no, total_cost, status, received_at)
                 VALUES (?, ?, ?, ?, 'received', NOW())"
            )->execute([$supplierId, current_user()['id'], $referenceNo !== '' ? $referenceNo : null, $total]);
            $purchaseId = (int)$pdo->lastInsertId();
 
            $addItem  = $pdo->prepare(
                'INSERT INTO purchase_items (purchase_id, product_id, qty, unit_cost) VALUES (?, ?, ?, ?)'
            );
            // Adds to stock and keeps the product's cost price at the latest cost paid.
            $addStock = $pdo->prepare(
                'UPDATE products SET stock_qty = stock_qty + ?, cost_price = ? WHERE id = ?'
            );
            $addMove  = $pdo->prepare(
                "INSERT INTO stock_movements (product_id, type, qty_change, reference_id, note)
                 VALUES (?, 'purchase', ?, ?, ?)"
            );
 
            foreach ($items as $it) {
                $addItem->execute([$purchaseId, $it['product_id'], $it['qty'], $it['cost']]);
                $addStock->execute([$it['qty'], $it['cost'], $it['product_id']]);
                $addMove->execute([$it['product_id'], $it['qty'], $purchaseId, 'Purchase #' . $purchaseId]);
            }
 
            $pdo->commit();
            header('Location: purchases.php?msg=' . urlencode('Purchase saved. Stock updated.'));
            exit;
        } catch (PDOException $ex) {
            $pdo->rollBack();
            $errors[] = 'Could not save the purchase.';
        }
    }
}
 
// ---------- 2. LOAD DATA FOR THE PAGE ----------
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$products  = $pdo->query('SELECT id, barcode, name FROM products WHERE is_active = 1 ORDER BY name')->fetchAll();
 
$history = $pdo->query(
    "SELECT pu.id, pu.created_at, pu.reference_no, pu.total_cost, s.name AS supplier,
            GROUP_CONCAT(CONCAT(p.name, ' x', pi.qty) SEPARATOR ', ') AS items
     FROM purchases pu
     LEFT JOIN suppliers s ON s.id = pu.supplier_id
     JOIN purchase_items pi ON pi.purchase_id = pu.id
     JOIN products p ON p.id = pi.product_id
     GROUP BY pu.id, s.name
     ORDER BY pu.id DESC
     LIMIT 20"
)->fetchAll();
 
$pageTitle = 'Purchases';
require __DIR__ . '/includes/header.php';
?>
 
<h1>Purchases</h1>
 
<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert--ok"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
 
<?php foreach ($errors as $error): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
<?php endforeach; ?>
 
<!-- NEW PURCHASE FORM -->
<div class="card">
    <h2>Record a purchase from a wholesaler</h2>
 
    <?php if (!$products): ?>
        <p>You have no active products yet. <a href="products.php">Add products first.</a></p>
    <?php else: ?>
    <form method="post" action="purchases.php">
        <input type="hidden" name="action" value="save">
 
        <div class="form-grid">
            <div>
                <label for="supplier_id">Wholesaler</label>
                <select id="supplier_id" name="supplier_id">
                    <option value="0">-- Choose wholesaler --</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="new_supplier">Or type a new wholesaler</label>
                <input id="new_supplier" name="new_supplier" placeholder="e.g. Puregold, Metro Wholesale">
            </div>
            <div>
                <label for="reference_no">Receipt / reference no. (optional)</label>
                <input id="reference_no" name="reference_no">
            </div>
        </div>
 
        <h2 style="margin-top:20px;">Items bought</h2>
        <p class="hint">Enter the quantity in the pieces you sell (for example, bottles), and the cost for one piece.</p>
 
        <table class="line-items">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Cost per piece</th>
                    <th class="num">Line total</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="rows"></tbody>
        </table>
 
        <p><button type="button" class="btn btn--gray btn--small" id="add-row">+ Add another item</button></p>
        <div class="grand-total">Total: <span id="grand">0.00</span></div>
 
        <button class="btn" type="submit">Save purchase</button>
    </form>
    <?php endif; ?>
</div>
 
<!-- Blueprint for one item row. The JavaScript copies this each time you add a row. -->
<template id="row-template">
    <tr>
        <td>
            <select name="product_id[]">
                <option value="0">-- Choose product --</option>
                <?php foreach ($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['barcode']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input class="qty" name="qty[]" type="number" min="1" step="1"></td>
        <td><input class="cost" name="unit_cost[]" type="number" min="0" step="0.01"></td>
        <td class="num line">0.00</td>
        <td><button type="button" class="btn btn--small btn--danger remove-row">X</button></td>
    </tr>
</template>
 
<!-- PURCHASE HISTORY -->
<h2>Recent purchases</h2>
<table>
    <thead>
        <tr>
            <th>Date</th>
            <th>Wholesaler</th>
            <th>Reference</th>
            <th>Items</th>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$history): ?>
            <tr><td colspan="5">No purchases yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= e(date('M d, Y h:i A', strtotime($h['created_at']))) ?></td>
                <td><?= e($h['supplier'] ?? '-') ?></td>
                <td><?= e($h['reference_no'] ?? '-') ?></td>
                <td><?= e($h['items']) ?></td>
                <td class="num"><?= money($h['total_cost']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
 
<script>
    // Small helper script: add/remove item rows and show the totals.
    const rows     = document.getElementById('rows');
    const template = document.getElementById('row-template');
 
    function addRow() {
        if (rows) rows.appendChild(template.content.cloneNode(true));
    }
 
    function recalculate() {
        let total = 0;
        rows.querySelectorAll('tr').forEach(function (tr) {
            const qty  = parseFloat(tr.querySelector('.qty').value)  || 0;
            const cost = parseFloat(tr.querySelector('.cost').value) || 0;
            const line = qty * cost;
            tr.querySelector('.line').textContent = line.toFixed(2);
            total += line;
        });
        document.getElementById('grand').textContent = total.toFixed(2);
    }
 
    if (rows) {
        document.getElementById('add-row').addEventListener('click', addRow);
        rows.addEventListener('input', recalculate);
        rows.addEventListener('click', function (event) {
            if (event.target.classList.contains('remove-row')) {
                event.target.closest('tr').remove();
                recalculate();
            }
        });
        addRow();   // start with one empty row
    }
</script>
 
<?php require __DIR__ . '/includes/footer.php'; ?>
 