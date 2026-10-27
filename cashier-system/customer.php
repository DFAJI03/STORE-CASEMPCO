<?php
// customer.php - one customer: what they owe, their utang sales, and their payments.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

// What the customer owes now: utang from completed sales, minus payments received.
function balance_of(PDO $pdo, int $customerId): float
{
    $taken = $pdo->prepare(
        "SELECT COALESCE(SUM(total - amount_paid), 0) FROM sales
         WHERE customer_id = ? AND payment_method = 'utang' AND status = 'completed'"
    );
    $taken->execute([$customerId]);

    $paid = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM debt_payments WHERE customer_id = ?');
    $paid->execute([$customerId]);

    return round((float)$taken->fetchColumn() - (float)$paid->fetchColumn(), 2);
}

$stmt = $pdo->prepare('SELECT id, name, phone FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();

$useBootstrap = true;   // tells header.php and footer.php to load Bootstrap
$pageTitle    = 'Customer';

if (!$customer) {
    require __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Customer not found.</div>';
    echo '<a class="btn btn-secondary" href="customers.php">Back to customers</a>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$errors = [];

// ---------- 1. RECORD A PAYMENT (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $note   = trim($_POST['note'] ?? '');

    try {
        $pdo->beginTransaction();

        // Lock this customer so two payments cannot be recorded at the same moment.
        $lock = $pdo->prepare('SELECT id FROM customers WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $lock->fetch();

        $balance = balance_of($pdo, $id);

        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount above 0.');
        }
        if ($amount > $balance) {
            throw new RuntimeException('That is more than the customer owes (' . money($balance) . ').');
        }

        $pdo->prepare('INSERT INTO debt_payments (customer_id, amount, note, user_id) VALUES (?, ?, ?, ?)')
            ->execute([$id, $amount, $note !== '' ? $note : null, current_user()['id']]);

        $pdo->commit();
        header('Location: customer.php?id=' . $id . '&msg=' . urlencode('Payment of ' . money($amount) . ' recorded.'));
        exit;
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $ex->getMessage();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Could not record the payment.';
    }
}

// ---------- 2. LOAD THE PAGE DATA ----------
$balance = balance_of($pdo, $id);

$stmt = $pdo->prepare(
    "SELECT id, invoice_no, created_at, total, amount_paid, status
     FROM sales
     WHERE customer_id = ? AND payment_method = 'utang'
     ORDER BY id DESC"
);
$stmt->execute([$id]);
$utangSales = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT d.amount, d.note, d.created_at, u.name AS recorded_by
     FROM debt_payments d
     LEFT JOIN users u ON u.id = d.user_id
     WHERE d.customer_id = ?
     ORDER BY d.id DESC'
);
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<p><a class="btn btn-outline-secondary btn-sm" href="customers.php">&larr; All customers</a></p>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success"><?= e($_GET['msg']) ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div>
<?php endforeach; ?>

<!-- CUSTOMER AND BALANCE -->
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="h3 mb-1"><?= e($customer['name']) ?></h1>
            <div class="text-muted"><?= e($customer['phone'] ?? 'No phone number') ?></div>
        </div>
        <div class="text-end">
            <div class="text-muted small text-uppercase">Balance (utang)</div>
            <?php if ($balance > 0): ?>
                <div class="fs-2 fw-bold text-danger"><?= money($balance) ?></div>
            <?php elseif ($balance < 0): ?>
                <div class="fs-2 fw-bold text-success"><?= money(-$balance) ?> credit</div>
            <?php else: ?>
                <div class="fs-2 fw-bold text-success">Paid up</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($balance > 0): ?>
        <div class="card-footer bg-white">
            <form method="post" action="customer.php?id=<?= (int)$id ?>" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="pay">
                <input type="hidden" name="id" value="<?= (int)$id ?>">

                <div class="col-12 col-md-4">
                    <label for="amount" class="form-label">Payment amount</label>
                    <div class="input-group">
                        <input id="amount" name="amount" type="number" step="0.01" min="0.01"
                               max="<?= number_format($balance, 2, '.', '') ?>" class="form-control" required>
                        <button type="button" class="btn btn-outline-secondary"
                                data-amount="<?= number_format($balance, 2, '.', '') ?>"
                                onclick="document.getElementById('amount').value = this.dataset.amount">Full balance</button>
                    </div>
                </div>
                <div class="col-12 col-md-5">
                    <label for="note" class="form-label">Note (optional)</label>
                    <input id="note" name="note" class="form-control" placeholder="e.g. paid on payday">
                </div>
                <div class="col-12 col-md-3">
                    <button class="btn btn-success w-100" type="submit">Record payment</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3">

    <!-- UTANG SALES -->
    <div class="col-12 col-xl-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Utang sales</div>
            <?php if (!$utangSales): ?>
                <div class="card-body text-muted">No utang sales yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Paid now</th>
                                <th class="text-end">Utang</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($utangSales as $s): ?>
                                <?php $isVoid = $s['status'] === 'void'; ?>
                                <tr class="<?= $isVoid ? 'text-muted' : '' ?>">
                                    <td><?= e(date('M d, Y h:i A', strtotime($s['created_at']))) ?></td>
                                    <td><a href="receipt.php?id=<?= (int)$s['id'] ?>"><?= e($s['invoice_no']) ?></a></td>
                                    <td class="text-end"><?= money($s['total']) ?></td>
                                    <td class="text-end"><?= money($s['amount_paid']) ?></td>
                                    <td class="text-end"><?= money($s['total'] - $s['amount_paid']) ?></td>
                                    <td>
                                        <span class="badge text-bg-<?= $isVoid ? 'danger' : 'secondary' ?>">
                                            <?= $isVoid ? 'Void' : 'Counted' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- PAYMENTS -->
    <div class="col-12 col-xl-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Payments received</div>
            <?php if (!$payments): ?>
                <div class="card-body text-muted">No payments yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Amount</th>
                                <th>Note</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><?= e(date('M d, Y h:i A', strtotime($p['created_at']))) ?></td>
                                    <td class="text-end text-success fw-bold"><?= money($p['amount']) ?></td>
                                    <td><?= e($p['note'] ?? '') ?></td>
                                    <td><?= e($p['recorded_by'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
