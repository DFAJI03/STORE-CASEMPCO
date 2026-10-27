<?php
// products.php - list, search, add, edit, and activate/deactivate products.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$errors  = [];
$product = [
    'id' => 0, 'barcode' => '', 'name' => '', 'category_id' => '',
    'cost_price' => '', 'selling_price' => '', 'stock_qty' => 0, 'min_stock' => 5,
];

// ---------- 1. HANDLE FORM SUBMISSIONS (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Hide or show a product (we never delete, so old sales keep working).
    if ($action === 'toggle') {
        $stmt = $pdo->prepare('UPDATE products SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: products.php?msg=' . urlencode('Product updated.'));
        exit;
    }

    // Delete a product. Only allowed if it was never sold or purchased,
    // otherwise old sales and purchases would lose their product.
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $check = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM sale_items WHERE product_id = ?)
                  + (SELECT COUNT(*) FROM purchase_items WHERE product_id = ?)'
        );
        $check->execute([$id, $id]);

        if ((int)$check->fetchColumn() > 0) {
            header('Location: products.php?error=' . urlencode(
                'This product has sales or purchase history and cannot be deleted. Use Hide instead.'
            ));
            exit;
        }

        try {
            $pdo->beginTransaction();
            // Remove its stock log first, then the product itself.
            $pdo->prepare('DELETE FROM stock_movements WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            $pdo->commit();
            header('Location: products.php?msg=' . urlencode('Product deleted.'));
        } catch (PDOException $ex) {
            $pdo->rollBack();
            header('Location: products.php?error=' . urlencode('Could not delete the product.'));
        }
        exit;
    }

    // Add a new product or update an existing one.
    if ($action === 'save') {
        $product = [
            'id'            => (int)($_POST['id'] ?? 0),
            'barcode'       => trim($_POST['barcode'] ?? ''),
            'name'          => trim($_POST['name'] ?? ''),
            'category_id'   => (int)($_POST['category_id'] ?? 0),
            'cost_price'    => $_POST['cost_price'] ?? '',
            'selling_price' => $_POST['selling_price'] ?? '',
            'stock_qty'     => (int)($_POST['stock_qty'] ?? 0),
            'min_stock'     => (int)($_POST['min_stock'] ?? 0),
        ];

        // Validate on the server (never trust the browser alone).
        if ($product['barcode'] === '')                $errors[] = 'Barcode / item code is required.';
        if ($product['name'] === '')                   $errors[] = 'Product name is required.';
        if (!is_numeric($product['cost_price'])    || $product['cost_price'] < 0)    $errors[] = 'Cost price must be 0 or more.';
        if (!is_numeric($product['selling_price']) || $product['selling_price'] < 0) $errors[] = 'Selling price must be 0 or more.';
        if ($product['stock_qty'] < 0 || $product['min_stock'] < 0)                  $errors[] = 'Quantities cannot be negative.';

        if (!$errors) {
            $categoryId = $product['category_id'] ?: null;

            try {
                $pdo->beginTransaction();

                if ($product['id']) {
                    // EDIT: stock_qty is NOT changed here. Stock changes only
                    // through purchases, sales, or adjustments.
                    $stmt = $pdo->prepare(
                        'UPDATE products
                         SET barcode = ?, name = ?, category_id = ?, cost_price = ?, selling_price = ?, min_stock = ?
                         WHERE id = ?'
                    );
                    $stmt->execute([
                        $product['barcode'], $product['name'], $categoryId,
                        $product['cost_price'], $product['selling_price'],
                        $product['min_stock'], $product['id'],
                    ]);
                    $message = 'Product updated.';
                } else {
                    // ADD: save the product, and log opening stock as a movement.
                    $stmt = $pdo->prepare(
                        'INSERT INTO products (barcode, name, category_id, cost_price, selling_price, stock_qty, min_stock)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $product['barcode'], $product['name'], $categoryId,
                        $product['cost_price'], $product['selling_price'],
                        $product['stock_qty'], $product['min_stock'],
                    ]);

                    if ($product['stock_qty'] > 0) {
                        $newId = (int)$pdo->lastInsertId();
                        $move = $pdo->prepare(
                            "INSERT INTO stock_movements (product_id, type, qty_change, note)
                             VALUES (?, 'adjustment', ?, 'Opening stock')"
                        );
                        $move->execute([$newId, $product['stock_qty']]);
                    }
                    $message = 'Product added.';
                }

                $pdo->commit();
                header('Location: products.php?msg=' . urlencode($message));
                exit;
            } catch (PDOException $ex) {
                $pdo->rollBack();
                // 23000 = duplicate value in a UNIQUE column (the barcode).
                $errors[] = $ex->getCode() === '23000'
                    ? 'That barcode already exists.'
                    : 'Could not save the product.';
            }
        }
    }
}

// ---------- 2. LOAD A PRODUCT INTO THE FORM FOR EDITING (GET) ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $found = $stmt->fetch();
    if ($found) {
        $product = $found;
    }
}

// ---------- 3. LOAD DATA FOR THE PAGE ----------
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$q      = trim($_GET['q'] ?? '');
$sql    = 'SELECT p.*, c.name AS category
           FROM products p
           LEFT JOIN categories c ON c.id = p.category_id';
$params = [];
if ($q !== '') {
    $sql   .= ' WHERE p.name LIKE ? OR p.barcode LIKE ?';
    $params = ["%$q%", "%$q%"];
}
$sql .= ' ORDER BY p.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$isEditing = (int)$product['id'] > 0;
$pageTitle = 'Products';
require __DIR__ . '/includes/header.php';
?>

<h1>Products</h1>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert--ok"><?= e($_GET['msg']) ?></div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert--error"><?= e($_GET['error']) ?></div>
<?php endif; ?>

<?php foreach ($errors as $error): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
<?php endforeach; ?>

<!-- ADD / EDIT FORM -->
<div class="card">
    <h2><?= $isEditing ? 'Edit product' : 'Add product' ?></h2>
    <form method="post" action="products.php">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">

        <div class="form-grid">
            <div>
                <label for="barcode">Barcode / Item code</label>
                <input id="barcode" name="barcode" value="<?= e($product['barcode']) ?>" required>
            </div>
            <div>
                <label for="name">Product name</label>
                <input id="name" name="name" value="<?= e($product['name']) ?>" required>
            </div>
            <div>
                <label for="category_id">Category</label>
                <select id="category_id" name="category_id">
                    <option value="0">-- None --</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"
                            <?= (int)$product['category_id'] === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= e($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="cost_price">Cost price</label>
                <input id="cost_price" name="cost_price" type="number" step="0.01" min="0"
                       value="<?= e($product['cost_price']) ?>" required>
            </div>
            <div>
                <label for="selling_price">Selling price</label>
                <input id="selling_price" name="selling_price" type="number" step="0.01" min="0"
                       value="<?= e($product['selling_price']) ?>" required>
            </div>
            <div>
                <label for="stock_qty"><?= $isEditing ? 'Current stock' : 'Opening stock' ?></label>
                <input id="stock_qty" name="stock_qty" type="number" min="0"
                       value="<?= (int)$product['stock_qty'] ?>" <?= $isEditing ? 'disabled' : '' ?>>
                <?php if ($isEditing): ?>
                    <div class="hint">Stock changes through purchases and sales.</div>
                <?php endif; ?>
            </div>
            <div>
                <label for="min_stock">Low-stock alert level</label>
                <input id="min_stock" name="min_stock" type="number" min="0"
                       value="<?= (int)$product['min_stock'] ?>">
            </div>
        </div>

        <p>
            <button class="btn" type="submit"><?= $isEditing ? 'Save changes' : 'Add product' ?></button>
            <?php if ($isEditing): ?>
                <a class="btn btn--gray" href="products.php">Cancel</a>
            <?php endif; ?>
        </p>
    </form>
</div>

<!-- SEARCH + LIST -->
<form class="search-bar" method="get" action="products.php">
    <input name="q" placeholder="Search name or barcode" value="<?= e($q) ?>">
    <button class="btn" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn btn--gray" href="products.php">Clear</a><?php endif; ?>
</form>

<table>
    <thead>
        <tr>
            <th>Barcode</th>
            <th>Name</th>
            <th>Category</th>
            <th class="num">Cost</th>
            <th class="num">Price</th>
            <th class="num">Stock</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$products): ?>
            <tr><td colspan="8">No products found.</td></tr>
        <?php endif; ?>

        <?php foreach ($products as $p): ?>
            <?php $low = (int)$p['stock_qty'] <= (int)$p['min_stock']; ?>
            <tr class="<?= $p['is_active'] ? '' : 'inactive' ?>">
                <td><?= e($p['barcode']) ?></td>
                <td><?= e($p['name']) ?></td>
                <td><?= e($p['category'] ?? '-') ?></td>
                <td class="num"><?= money($p['cost_price']) ?></td>
                <td class="num"><?= money($p['selling_price']) ?></td>
                <td class="num <?= $low ? 'low-stock' : '' ?>">
                    <?= (int)$p['stock_qty'] ?><?= $low ? ' (low)' : '' ?>
                </td>
                <td><?= $p['is_active'] ? 'Active' : 'Hidden' ?></td>
                <td>
                    <div class="actions">
                        <a class="btn btn--small" href="products.php?edit=<?= (int)$p['id'] ?>">Edit</a>
                        <form method="post" action="products.php">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn--small btn--gray" type="submit">
                                <?= $p['is_active'] ? 'Hide' : 'Show' ?>
                            </button>
                        </form>
                        <form method="post" action="products.php"
                              onsubmit="return confirm('Delete this product? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <button class="btn btn--small btn--danger" type="submit">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
