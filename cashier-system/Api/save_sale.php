<?php
// api/save_sale.php - saves one sale sent from the cashier screen.
// Receives JSON and replies with JSON.
// Prices ALWAYS come from the database, never from the browser.

require __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Send an error reply and stop.
function fail(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Invalid request.', 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    fail('Invalid data.');
}

$method   = in_array($data['payment_method'] ?? '', ['cash', 'card', 'utang'], true) ? $data['payment_method'] : 'cash';
$customerId  = (int)($data['customer_id'] ?? 0);
$newCustomer = trim((string)($data['new_customer'] ?? ''));
$discount = max(0, round((float)($data['discount'] ?? 0), 2));
$paid     = round((float)($data['amount_paid'] ?? 0), 2);

// Combine repeated products and check the quantities.
$wanted = [];
foreach (($data['items'] ?? []) as $row) {
    $productId = (int)($row['product_id'] ?? 0);
    $qty       = (int)($row['qty'] ?? 0);
    if ($productId <= 0 || $qty <= 0) {
        fail('Invalid item in the cart.');
    }
    $wanted[$productId] = ($wanted[$productId] ?? 0) + $qty;
}
if (!$wanted) {
    fail('The cart is empty.');
}

try {
    // One transaction: the sale, its items, the stock deduction, and the stock
    // log are saved together, or not at all.
    $pdo->beginTransaction();

    // FOR UPDATE locks each product row so two sales cannot use the same stock.
    $get = $pdo->prepare(
        'SELECT id, name, selling_price, cost_price, stock_qty, is_active FROM products WHERE id = ? FOR UPDATE'
    );

    $lines    = [];
    $subtotal = 0;

    foreach ($wanted as $productId => $qty) {
        $get->execute([$productId]);
        $product = $get->fetch();

        if (!$product || !$product['is_active']) {
            throw new RuntimeException('An item in the cart is no longer available.');
        }
        if ((int)$product['stock_qty'] < $qty) {
            throw new RuntimeException(
                'Not enough stock for ' . $product['name'] . ' (only ' . (int)$product['stock_qty'] . ' left).'
            );
        }

        $price     = (float)$product['selling_price'];
        $lineTotal = round($price * $qty, 2);
        $subtotal += $lineTotal;

        // The cost is saved with the sale so profit stays correct if costs change later.
        $lines[] = [
            'product_id' => $productId,
            'qty'        => $qty,
            'price'      => $price,
            'cost'       => (float)$product['cost_price'],
            'line_total' => $lineTotal,
        ];
    }

    $subtotal = round($subtotal, 2);
    $discount = min($discount, $subtotal);
    $total    = round($subtotal - $discount, 2);

    if ($method === 'card') {
        $paid = $total;
    }

    $customer = null;   // only used for utang

    if ($method === 'utang') {
        // Utang: the customer takes the items now and pays later (or part of it now).
        // Stock is still deducted below, exactly like any other sale.
        if ($paid < 0 || $paid >= $total) {
            throw new RuntimeException('For utang, the amount paid now must be less than the total.');
        }

        if ($newCustomer !== '') {
            // A typed name: reuse the customer if the name already exists, otherwise create one.
            $find = $pdo->prepare('SELECT id, name FROM customers WHERE name = ?');
            $find->execute([$newCustomer]);
            $customer = $find->fetch();
            if (!$customer) {
                $pdo->prepare('INSERT INTO customers (name) VALUES (?)')->execute([$newCustomer]);
                $customer = ['id' => (int)$pdo->lastInsertId(), 'name' => $newCustomer];
            }
        } else {
            $find = $pdo->prepare('SELECT id, name FROM customers WHERE id = ?');
            $find->execute([$customerId]);
            $customer = $find->fetch();
            if (!$customer) {
                throw new RuntimeException('Please choose a customer for utang.');
            }
        }
        $change = 0;
    } else {
        if ($paid < $total) {
            throw new RuntimeException('The amount received is less than the total.');
        }
        $change = round($paid - $total, 2);
    }

    // How much the customer still owes from this sale (0 for cash and card).
    $utang = $method === 'utang' ? round($total - $paid, 2) : 0;

    // Save the sale with a temporary invoice number, then set the real one from its id.
    $pdo->prepare(
        "INSERT INTO sales (invoice_no, user_id, customer_id, subtotal, discount, tax, total, payment_method, amount_paid, change_amount, status)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'completed')"
    )->execute([
        'TMP' . bin2hex(random_bytes(6)), current_user()['id'], $customer ? (int)$customer['id'] : null,
        $subtotal, $discount, $total, $method, $paid, $change,
    ]);

    $saleId  = (int)$pdo->lastInsertId();
    $invoice = 'INV-' . str_pad((string)$saleId, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE sales SET invoice_no = ? WHERE id = ?')->execute([$invoice, $saleId]);

    $addItem  = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, product_id, qty, unit_price, unit_cost, discount, line_total)
         VALUES (?, ?, ?, ?, ?, 0, ?)'
    );
    $takeStock = $pdo->prepare('UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?');
    $addMove   = $pdo->prepare(
        "INSERT INTO stock_movements (product_id, type, qty_change, reference_id, note)
         VALUES (?, 'sale', ?, ?, ?)"
    );

    foreach ($lines as $line) {
        $addItem->execute([$saleId, $line['product_id'], $line['qty'], $line['price'], $line['cost'], $line['line_total']]);
        $takeStock->execute([$line['qty'], $line['product_id']]);
        $addMove->execute([$line['product_id'], -$line['qty'], $saleId, $invoice]);
    }

    $pdo->commit();

    echo json_encode([
        'success'    => true,
        'invoice_no' => $invoice,
        'total'      => $total,
        'change'     => $change,
        'utang'      => $utang,
        'customer'   => $customer ? ['id' => (int)$customer['id'], 'name' => $customer['name']] : null,
    ]);
} catch (RuntimeException $ex) {
    // A rule was broken (not enough stock, not enough money, and so on).
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail($ex->getMessage());
} catch (PDOException $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('Could not save the sale.', 500);
}
