<?php
// index.php - the dashboard (built with Bootstrap):
// today's numbers, low stock, best sellers, recent stock movements.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// ---------- Today's sales (voided sales are not counted) ----------
$row = $pdo->query(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total
     FROM sales
     WHERE status = 'completed' AND DATE(created_at) = CURDATE()"
)->fetch();
$salesCount = (int)$row['cnt'];
$salesTotal = (float)$row['total'];

// ---------- Today's profit = money received - what the items cost ----------
$profit = null;
try {
    $cost = (float)$pdo->query(
        "SELECT COALESCE(SUM(si.qty * si.unit_cost), 0)
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE s.status = 'completed' AND DATE(s.created_at) = CURDATE()"
    )->fetchColumn();
    $profit = $salesTotal - $cost;
} catch (PDOException $ex) {
    // The unit_cost column has not been added yet (run update_add_unit_cost.sql).
}

// ---------- Customer utang (debt) ----------
// Balance = utang from completed sales (total - paid at the time) - payments received.
$totalUtang  = null;
$debtorCount = 0;
$debtors     = [];
try {
    $debtors = $pdo->query(
        "SELECT id, name, balance FROM (
             SELECT c.id, c.name,
                    COALESCE((SELECT SUM(s.total - s.amount_paid) FROM sales s
                              WHERE s.customer_id = c.id AND s.payment_method = 'utang' AND s.status = 'completed'), 0)
                  - COALESCE((SELECT SUM(d.amount) FROM debt_payments d WHERE d.customer_id = c.id), 0) AS balance
             FROM customers c
         ) t
         WHERE balance > 0
         ORDER BY balance DESC"
    )->fetchAll();
    $debtorCount = count($debtors);
    $totalUtang  = array_sum(array_column($debtors, 'balance'));
    $debtors     = array_slice($debtors, 0, 5);   // the dashboard lists the top 5
} catch (PDOException $ex) {
    // The utang tables have not been added yet (run update_add_utang.sql).
}

// ---------- Products and low stock ----------
$totalProducts = (int)$pdo->query(
    'SELECT COUNT(*) FROM products WHERE is_active = 1'
)->fetchColumn();

$lowCount = (int)$pdo->query(
    'SELECT COUNT(*) FROM products WHERE is_active = 1 AND stock_qty <= min_stock'
)->fetchColumn();

$lowItems = $pdo->query(
    'SELECT name, stock_qty, min_stock
     FROM products
     WHERE is_active = 1 AND stock_qty <= min_stock
     ORDER BY stock_qty ASC, name
     LIMIT 10'
)->fetchAll();

// ---------- Best sellers in the last 7 days (today and the 6 days before) ----------
$bestSellers = $pdo->query(
    "SELECT p.name, SUM(si.qty) AS qty_sold, SUM(si.line_total) AS amount
     FROM sale_items si
     JOIN sales s ON s.id = si.sale_id
     JOIN products p ON p.id = si.product_id
     WHERE s.status = 'completed' AND DATE(s.created_at) >= CURDATE() - INTERVAL 6 DAY
     GROUP BY p.id, p.name
     ORDER BY qty_sold DESC
     LIMIT 5"
)->fetchAll();

// ---------- Product sales today: quantity, sales and profit for each product ----------
// Listed A to Z (the best sellers are already in the card above).
$hasProductCost = true;
try {
    $productSales = $pdo->query(
        "SELECT p.name, SUM(si.qty) AS qty, SUM(si.line_total) AS amount, SUM(si.qty * si.unit_cost) AS cost
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         JOIN products p ON p.id = si.product_id
         WHERE s.status = 'completed' AND DATE(s.created_at) = CURDATE()
         GROUP BY p.id, p.name
         ORDER BY p.name"
    )->fetchAll();
} catch (PDOException $ex) {
    $hasProductCost = false;   // the unit_cost column has not been added yet
    $productSales = $pdo->query(
        "SELECT p.name, SUM(si.qty) AS qty, SUM(si.line_total) AS amount, 0 AS cost
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         JOIN products p ON p.id = si.product_id
         WHERE s.status = 'completed' AND DATE(s.created_at) = CURDATE()
         GROUP BY p.id, p.name
         ORDER BY p.name"
    )->fetchAll();
}
$psQty    = array_sum(array_column($productSales, 'qty'));
$psAmount = array_sum(array_column($productSales, 'amount'));
$psCost   = array_sum(array_column($productSales, 'cost'));

// ---------- Sales and profit for the last 7 days (for the bar + line chart) ----------
$today       = $pdo->query('SELECT CURDATE()')->fetchColumn();   // "today" according to MySQL
$salesByDay  = [];
$costByDay   = [];
$hasCostData = true;

$rows = $pdo->query(
    "SELECT DATE(created_at) AS d, SUM(total) AS amount
     FROM sales
     WHERE status = 'completed' AND DATE(created_at) >= CURDATE() - INTERVAL 6 DAY
     GROUP BY DATE(created_at)"
)->fetchAll();
foreach ($rows as $r) {
    $salesByDay[$r['d']] = (float)$r['amount'];
}

try {
    $rows = $pdo->query(
        "SELECT DATE(s.created_at) AS d, SUM(si.qty * si.unit_cost) AS cost
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE s.status = 'completed' AND DATE(s.created_at) >= CURDATE() - INTERVAL 6 DAY
         GROUP BY DATE(s.created_at)"
    )->fetchAll();
    foreach ($rows as $r) {
        $costByDay[$r['d']] = (float)$r['cost'];
    }
} catch (PDOException $ex) {
    $hasCostData = false;   // the unit_cost column has not been added yet
}

// One entry for each of the 7 days, oldest first. Days with no sales count as 0.
$trendLabels = [];
$trendSales  = [];
$trendProfit = [];
for ($i = 6; $i >= 0; $i--) {
    $day = (new DateTime($today))->modify("-$i day");
    $key = $day->format('Y-m-d');
    $trendLabels[] = $day->format('M j');                         // for example "Oct 22"
    $trendSales[]  = round($salesByDay[$key] ?? 0, 2);
    $trendProfit[] = round(($salesByDay[$key] ?? 0) - ($costByDay[$key] ?? 0), 2);
}
$trendTotalSales  = array_sum($trendSales);
$trendTotalProfit = array_sum($trendProfit);

// ---------- Sales by payment type (for the doughnut chart) ----------
// The buttons above the chart choose how far back to look.
$rangeDays   = ['today' => 0, 'week' => 6, 'month' => 29];
$rangeLabels = ['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days'];
$range = $_GET['range'] ?? 'week';
if (!isset($rangeDays[$range])) {
    $range = 'week';
}

$paymentTypes = [
    'cash'  => ['label' => 'Cash',  'color' => '#198754', 'count' => 0, 'amount' => 0.0],
    'card'  => ['label' => 'Card',  'color' => '#0d6efd', 'count' => 0, 'amount' => 0.0],
    'utang' => ['label' => 'Utang', 'color' => '#ffc107', 'count' => 0, 'amount' => 0.0],
];
$rows = $pdo->query(
    "SELECT payment_method, COUNT(*) AS cnt, SUM(total) AS amount
     FROM sales
     WHERE status = 'completed' AND DATE(created_at) >= CURDATE() - INTERVAL " . (int)$rangeDays[$range] . " DAY
     GROUP BY payment_method"
)->fetchAll();
foreach ($rows as $r) {
    if (isset($paymentTypes[$r['payment_method']])) {
        $paymentTypes[$r['payment_method']]['count']  = (int)$r['cnt'];
        $paymentTypes[$r['payment_method']]['amount'] = (float)$r['amount'];
    }
}
$paymentTotal = array_sum(array_column($paymentTypes, 'amount'));
$paymentCount = array_sum(array_column($paymentTypes, 'count'));


$useBootstrap = true;   // tells header.php and footer.php to load Bootstrap
$pageTitle    = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>

<h1 class="h3 mb-4">Dashboard</h1>

<!-- TODAY'S NUMBERS: 4 cards. Each takes the full width on a phone, half on a tablet, a quarter on a desktop. -->
<div class="row g-3 mb-4">

    <div class="col-12 col-sm-6 col-xl">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Today's sales</div>
                    <span class="text-success"><?= icon('banknote', 22) ?></span>
                </div>
                <div class="fs-2 fw-bold"><?= money($salesTotal) ?></div>
                <div class="text-muted small"><?= $salesCount ?> <?= $salesCount === 1 ? 'sale' : 'sales' ?></div>
                <div class="mt-auto pt-3"><a class="btn btn-glass glass-success btn-sm d-inline-flex align-items-center gap-1" href="sales.php"><?= icon('cart', 16) ?> Open sales</a></div>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-primary">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Today's profit</div>
                    <span class="text-primary"><?= icon('trending', 22) ?></span>
                </div>
                <div class="fs-2 fw-bold"><?= $profit === null ? '-' : money($profit) ?></div>
                <?php if ($profit === null): ?>
                    <div class="text-muted small">Run the database update to see profit.</div>
                <?php else: ?>
                    <div class="text-muted small">Sales minus item cost</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-secondary">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Active products</div>
                    <span class="text-secondary"><?= icon('box', 22) ?></span>
                </div>
                <div class="fs-2 fw-bold"><?= $totalProducts ?></div>
                <div class="mt-auto pt-3"><a class="btn btn-glass glass-secondary btn-sm d-inline-flex align-items-center gap-1" href="products.php"><?= icon('box', 16) ?> Manage products</a></div>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl">
        <div class="card h-100 shadow-sm border-0 border-start border-4 <?= $lowCount > 0 ? 'border-danger' : 'border-success' ?>">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Low stock</div>
                    <span class="<?= $lowCount > 0 ? 'text-danger' : 'text-success' ?>"><?= icon('alert', 22) ?></span>
                </div>
                <div class="fs-2 fw-bold <?= $lowCount > 0 ? 'text-danger' : '' ?>"><?= $lowCount ?></div>
                <div class="text-muted small">Products to restock</div>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Customer utang</div>
                    <span class="text-warning"><?= icon('wallet', 22) ?></span>
                </div>
                <div class="fs-2 fw-bold"><?= $totalUtang === null ? '-' : money($totalUtang) ?></div>
                <?php if ($totalUtang === null): ?>
                    <div class="text-muted small">Run the utang database update.</div>
                <?php else: ?>
                    <div class="text-muted small"><?= $debtorCount ?> <?= $debtorCount === 1 ? 'customer owes' : 'customers owe' ?></div>
                    <div class="mt-auto pt-3"><a class="btn btn-glass glass-warning btn-sm d-inline-flex align-items-center gap-1" href="customers.php"><?= icon('users', 16) ?> View customers</a></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">

    <!-- LOW STOCK LIST -->
    <div class="col-12 col-lg-6 col-xl-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('alert', 18, 'text-danger') ?> Items to restock</div>
            <?php if (!$lowItems): ?>
                <div class="card-body text-muted">Nothing is running low.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Stock</th>
                                <th class="text-end">Alert level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowItems as $item): ?>
                                <tr>
                                    <td><?= e($item['name']) ?></td>
                                    <td class="text-end text-danger fw-bold"><?= (int)$item['stock_qty'] ?></td>
                                    <td class="text-end"><?= (int)$item['min_stock'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <span class="text-muted small">
                        <?php if ($lowCount > count($lowItems)): ?>and <?= $lowCount - count($lowItems) ?> more<?php endif; ?>
                    </span>
                    <a class="btn btn-glass glass-primary btn-sm d-inline-flex align-items-center gap-1" href="purchases.php"><?= icon('purchase', 16) ?> Record a purchase</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- BEST SELLERS -->
    <div class="col-12 col-lg-6 col-xl-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('trending', 18, 'text-success') ?> Best sellers (last 7 days)</div>
            <?php if (!$bestSellers): ?>
                <div class="card-body text-muted">No sales in the last 7 days.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Sold</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bestSellers as $b): ?>
                                <tr>
                                    <td><?= e($b['name']) ?></td>
                                    <td class="text-end"><?= (int)$b['qty_sold'] ?></td>
                                    <td class="text-end"><?= money($b['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CUSTOMERS WHO OWE -->
    <div class="col-12 col-xl-4">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('users', 18, 'text-warning') ?> Customers with utang</div>
            <?php if (!$debtors): ?>
                <div class="card-body text-muted">
                    <?= $totalUtang === null ? 'Run the utang database update.' : 'Nobody owes anything.' ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Customer</th>
                                <th class="text-end">Owes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($debtors as $d): ?>
                                <tr>
                                    <td><a href="customer.php?id=<?= (int)$d['id'] ?>"><?= e($d['name']) ?></a></td>
                                    <td class="text-end text-danger fw-bold"><?= money($d['balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white text-end">
                    <a class="btn btn-glass glass-warning btn-sm d-inline-flex align-items-center gap-1" href="customers.php"><?= icon('users', 16) ?> All customers</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (is_file(__DIR__ . '/js/chart.umd.js')): ?>
    <!-- Chart.js, loaded once for both charts below -->
    <script src="js/chart.umd.js"></script>
<?php endif; ?>

<!-- SALES BY PAYMENT TYPE (doughnut chart) -->
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-semibold d-flex align-items-center gap-2"><?= icon('pie', 18, 'text-primary') ?> Sales by payment type</span>
                <div class="btn-group btn-group-sm" role="group" aria-label="Period">
                    <?php foreach ($rangeLabels as $key => $label): ?>
                        <a class="btn btn-outline-secondary <?= $range === $key ? 'active' : '' ?>"
                           href="index.php?range=<?= e($key) ?>"><?= e($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (!is_file(__DIR__ . '/js/chart.umd.js')): ?>
                    <div class="alert alert-warning mb-0">
                        The chart file is missing. Save <b>chart.umd.js</b> in the <b>js</b> folder.
                    </div>
                <?php elseif ($paymentTotal <= 0): ?>
                    <p class="text-muted mb-0">No sales in this period.</p>
                <?php else: ?>
                    <div class="row align-items-center g-3">
                        <div class="col-12 col-md-6">
                            <div style="max-width:280px; margin:0 auto;">
                                <canvas id="paymentChart"></canvas>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Type</th>
                                        <th class="text-end">Sales</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paymentTypes as $type): ?>
                                        <tr>
                                            <td>
                                                <span class="d-inline-block rounded-circle me-1"
                                                      style="width:10px; height:10px; background:<?= e($type['color']) ?>;"></span>
                                                <?= e($type['label']) ?>
                                            </td>
                                            <td class="text-end"><?= $type['count'] ?></td>
                                            <td class="text-end"><?= money($type['amount']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="fw-bold">
                                        <td>Total</td>
                                        <td class="text-end"><?= $paymentCount ?></td>
                                        <td class="text-end"><?= money($paymentTotal) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="text-muted small mt-2 mb-0">Utang is the full value of sales made on credit.</p>
                        </div>
                    </div>

                    <!-- Chart.js draws the chart. The numbers come from the PHP above. -->
                    <script>
                        const labels = <?= json_encode(array_column($paymentTypes, 'label'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        const amounts = <?= json_encode(array_map('floatval', array_column($paymentTypes, 'amount'))) ?>;
                        const colors = <?= json_encode(array_column($paymentTypes, 'color')) ?>;
                        const grandTotal = amounts.reduce(function (a, b) { return a + b; }, 0);

                        new Chart(document.getElementById('paymentChart'), {
                            type: 'doughnut',          // change to 'pie' for a solid circle
                            data: {
                                labels: labels,
                                datasets: [{ data: amounts, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }]
                            },
                            options: {
                                cutout: '60%',         // the size of the hole (use 0% for a pie)
                                plugins: {
                                    legend: { position: 'bottom' },
                                    tooltip: {
                                        callbacks: {
                                            label: function (ctx) {
                                                const pct = grandTotal ? (ctx.raw / grandTotal * 100).toFixed(1) : 0;
                                                return ctx.label + ': ' +
                                                    Number(ctx.raw).toLocaleString('en-US', { minimumFractionDigits: 2 }) +
                                                    ' (' + pct + '%)';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SALES AND PROFIT, LAST 7 DAYS (bars + line) -->
    <div class="col-12 col-xl-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('chart', 18, 'text-primary') ?> Sales and profit (last 7 days)</div>
            <div class="card-body">
                <?php if (!is_file(__DIR__ . '/js/chart.umd.js')): ?>
                    <div class="alert alert-warning mb-0">
                        The chart file is missing. Save <b>chart.umd.js</b> in the <b>js</b> folder.
                    </div>
                <?php elseif ($trendTotalSales <= 0): ?>
                    <p class="text-muted mb-0">No sales in the last 7 days.</p>
                <?php else: ?>
                    <canvas id="trendChart" height="170"></canvas>

                    <div class="d-flex justify-content-between flex-wrap gap-2 mt-3">
                        <div>
                            <div class="text-muted small text-uppercase">Sales, 7 days</div>
                            <div class="fw-bold"><?= money($trendTotalSales) ?></div>
                        </div>
                        <div class="text-end">
                            <div class="text-muted small text-uppercase">Profit, 7 days</div>
                            <div class="fw-bold"><?= $hasCostData ? money($trendTotalProfit) : '-' ?></div>
                        </div>
                    </div>
                    <?php if (!$hasCostData): ?>
                        <p class="text-muted small mt-2 mb-0">Run the database update to see profit.</p>
                    <?php endif; ?>

                    <script>
                        // Wrapped in a function so its names never clash with the other chart's.
                        (function () {
                            const labels = <?= json_encode($trendLabels) ?>;
                            const sales = <?= json_encode($trendSales) ?>;
                            const profit = <?= json_encode($trendProfit) ?>;
                            const showProfit = <?= $hasCostData ? 'true' : 'false' ?>;

                            const datasets = [{
                                type: 'bar', label: 'Sales', data: sales,
                                backgroundColor: 'rgba(25, 135, 84, 0.65)', borderRadius: 6
                            }];
                            if (showProfit) {
                                datasets.push({
                                    type: 'line', label: 'Profit', data: profit,
                                    borderColor: '#0d6efd', backgroundColor: '#0d6efd',
                                    tension: 0.3, pointRadius: 4
                                });
                            }

                            new Chart(document.getElementById('trendChart'), {
                                type: 'bar',
                                data: { labels: labels, datasets: datasets },
                                options: {
                                    responsive: true,
                                    interaction: { mode: 'index', intersect: false },
                                    plugins: {
                                        legend: { position: 'bottom' },
                                        tooltip: {
                                            callbacks: {
                                                label: function (ctx) {
                                                    return ctx.dataset.label + ': ' +
                                                        Number(ctx.raw).toLocaleString('en-US', { minimumFractionDigits: 2 });
                                                }
                                            }
                                        }
                                    },
                                    scales: {
                                        y: {
                                            beginAtZero: true,
                                            ticks: { callback: function (v) { return Number(v).toLocaleString('en-US'); } }
                                        }
                                    }
                                }
                            });
                        })();
                    </script>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- PRODUCT SALES TODAY -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="fw-semibold d-flex align-items-center gap-2"><?= icon('box', 18, 'text-secondary') ?> Product sales today</span>
        <span class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" href="stock_movements.php"><?= icon('list', 16) ?> Stock movements</a>
            <a class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1" href="daily_report.php"><?= icon('report', 16) ?> Full sales report</a>
        </span>
    </div>
    <?php if (!$productSales): ?>
        <div class="card-body text-muted">No sales yet today.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Qty sold</th>
                        <th class="text-end">Sales</th>
                        <th class="text-end">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productSales as $p): ?>
                        <?php $profit = (float)$p['amount'] - (float)$p['cost']; ?>
                        <tr>
                            <td><?= e($p['name']) ?></td>
                            <td class="text-end"><?= (int)$p['qty'] ?></td>
                            <td class="text-end"><?= money($p['amount']) ?></td>
                            <td class="text-end fw-bold <?= $hasProductCost && $profit < 0 ? 'text-danger' : '' ?>">
                                <?= $hasProductCost ? money($profit) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end"><?= (int)$psQty ?></td>
                        <td class="text-end"><?= money($psAmount) ?></td>
                        <td class="text-end"><?= $hasProductCost ? money($psAmount - $psCost) : '-' ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer bg-white text-muted small">
            Product amounts are before discounts, so they can be a little higher than Today's sales above.
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>