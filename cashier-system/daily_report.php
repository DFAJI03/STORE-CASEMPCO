<?php
// daily_report.php (shown in the menu as "Sales Report")
// A report for ANY range of dates: from one day up to a whole year, with Excel (CSV) export and printing.
// It is not listed in PAGE_ACCESS (includes/auth.php), so only admins can open it.

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// "Today" comes from MySQL, so it matches the time saved on each sale.
$today = $pdo->query('SELECT CURDATE()')->fetchColumn();

// Accept a date only if it really exists (for example, not Feb 31). Otherwise use the fallback.
function clean_date($value, string $fallback): string
{
    if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $value;
    }
    return $fallback;
}

// Small helpers: run a query and return the rows, or one value.
function report_rows(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function report_value(PDO $pdo, string $sql, array $params)
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

// ---------- 1. THE DATE RANGE ----------
$single = $_GET['date'] ?? null;                      // older links used one date
$from   = clean_date($_GET['from'] ?? $single, $today);
$to     = clean_date($_GET['to'] ?? $single, $today);
if ($from > $to) {
    [$from, $to] = [$to, $from];                      // if From is after To, swap them
}

// A report covers at most 366 days, so the page stays fast.
$maxDays   = 366;
$capped    = false;
$daysCount = (new DateTime($from))->diff(new DateTime($to))->days + 1;
if ($daysCount > $maxDays) {
    $from      = (new DateTime($to))->modify('-' . ($maxDays - 1) . ' day')->format('Y-m-d');
    $daysCount = $maxDays;
    $capped    = true;
}
$range = [$from, $to];

// The quick buttons: Today, Yesterday, Last 7 days, This month, Last month.
$todayObj = new DateTime($today);
$fmt      = fn(DateTime $d): string => $d->format('Y-m-d');
$yesterday = $fmt((clone $todayObj)->modify('-1 day'));
$quick = [
    'Today'       => [$today, $today],
    'Yesterday'   => [$yesterday, $yesterday],
    'Last 7 days' => [$fmt((clone $todayObj)->modify('-6 day')), $today],
    'This month'  => [$fmt((clone $todayObj)->modify('first day of this month')), $today],
    'Last month'  => [$fmt((clone $todayObj)->modify('first day of last month')), $fmt((clone $todayObj)->modify('last day of last month'))],
];

// How the range is written at the top of the page and in the file name.
$rangeLabel = $from === $to
    ? date('l, M d, Y', strtotime($from))
    : date('M d, Y', strtotime($from)) . ' to ' . date('M d, Y', strtotime($to));
$fileName = $from === $to ? "sales_report_$from" : "sales_report_{$from}_to_{$to}";

// ---------- 2. LOAD THE NUMBERS (voided sales are never counted) ----------
$summary = report_rows(
    $pdo,
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(total), 0) AS total, COALESCE(SUM(discount), 0) AS discount
     FROM sales WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?",
    $range
)[0];
$salesCount    = (int)$summary['cnt'];
$salesTotal    = (float)$summary['total'];
$discountTotal = (float)$summary['discount'];

$voidCount = (int)report_value(
    $pdo,
    "SELECT COUNT(*) FROM sales WHERE status = 'void' AND DATE(created_at) BETWEEN ? AND ?",
    $range
);

// Cash, card, and utang
$paymentTypes = [
    'cash'  => ['label' => 'Cash',  'count' => 0, 'amount' => 0.0],
    'card'  => ['label' => 'Card',  'count' => 0, 'amount' => 0.0],
    'utang' => ['label' => 'Utang', 'count' => 0, 'amount' => 0.0],
];
$rows = report_rows(
    $pdo,
    "SELECT payment_method, COUNT(*) AS cnt, SUM(total) AS amount
     FROM sales WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
     GROUP BY payment_method",
    $range
);
foreach ($rows as $r) {
    if (isset($paymentTypes[$r['payment_method']])) {
        $paymentTypes[$r['payment_method']]['count']  = (int)$r['cnt'];
        $paymentTypes[$r['payment_method']]['amount'] = (float)$r['amount'];
    }
}

// Utang given in this range (what customers still owed from those sales), and payments received
$utangGiven = (float)report_value(
    $pdo,
    "SELECT COALESCE(SUM(total - amount_paid), 0) FROM sales
     WHERE payment_method = 'utang' AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?",
    $range
);
try {
    $utangPaid = (float)report_value(
        $pdo,
        'SELECT COALESCE(SUM(amount), 0) FROM debt_payments WHERE DATE(created_at) BETWEEN ? AND ?',
        $range
    );
} catch (PDOException $ex) {
    $utangPaid = null;   // the utang tables have not been added yet
}

// Each product sold: quantity, sales, cost
$hasCost = true;
try {
    $products = report_rows(
        $pdo,
        "SELECT p.name, SUM(si.qty) AS qty, SUM(si.line_total) AS amount, SUM(si.qty * si.unit_cost) AS cost
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         JOIN products p ON p.id = si.product_id
         WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
         GROUP BY p.id, p.name",
        $range
    );
} catch (PDOException $ex) {
    $hasCost  = false;   // the unit_cost column has not been added yet
    $products = report_rows(
        $pdo,
        "SELECT p.name, SUM(si.qty) AS qty, SUM(si.line_total) AS amount, 0 AS cost
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         JOIN products p ON p.id = si.product_id
         WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
         GROUP BY p.id, p.name",
        $range
    );
}
foreach ($products as &$p) {
    $p['profit'] = (float)$p['amount'] - (float)$p['cost'];
}
unset($p);

// Sort the products: the column headers are links. Name goes A to Z, the numbers go biggest first.
$sort = $_GET['sort'] ?? 'name';
if (!in_array($sort, ['name', 'qty', 'amount', 'profit'], true) || ($sort === 'profit' && !$hasCost)) {
    $sort = 'name';
}
usort($products, function ($a, $b) use ($sort) {
    if ($sort === 'name') {
        return strcasecmp($a['name'], $b['name']);
    }
    $order = (float)$b[$sort] <=> (float)$a[$sort];   // biggest first
    return $order !== 0 ? $order : strcasecmp($a['name'], $b['name']);
});

$qtyTotal    = array_sum(array_column($products, 'qty'));
$amountTotal = array_sum(array_column($products, 'amount'));   // before discounts
$costTotal   = array_sum(array_column($products, 'cost'));
$profitTotal = $hasCost ? $salesTotal - $costTotal : null;     // after discounts

// Sales for each cashier
$cashiers = report_rows(
    $pdo,
    "SELECT COALESCE(u.name, '(unknown)') AS cashier, COUNT(*) AS cnt, SUM(s.total) AS amount
     FROM sales s
     LEFT JOIN users u ON u.id = s.user_id
     WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
     GROUP BY u.id, u.name
     ORDER BY amount DESC",
    $range
);

// ---------- 3. DAY BY DAY (days with no sales are listed as 0) ----------
$salesByDay = [];
foreach (report_rows(
    $pdo,
    "SELECT DATE(created_at) AS d, COUNT(*) AS cnt, SUM(total) AS amount
     FROM sales WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
     GROUP BY DATE(created_at)",
    $range
) as $r) {
    $salesByDay[$r['d']] = ['cnt' => (int)$r['cnt'], 'amount' => (float)$r['amount']];
}

$costByDay = [];
if ($hasCost) {
    foreach (report_rows(
        $pdo,
        "SELECT DATE(s.created_at) AS d, SUM(si.qty * si.unit_cost) AS cost
         FROM sale_items si
         JOIN sales s ON s.id = si.sale_id
         WHERE s.status = 'completed' AND DATE(s.created_at) BETWEEN ? AND ?
         GROUP BY DATE(s.created_at)",
        $range
    ) as $r) {
        $costByDay[$r['d']] = (float)$r['cost'];
    }
}

$daily      = [];
$bestDay    = null;
$bestAmount = 0;
$period     = new DatePeriod(new DateTime($from), new DateInterval('P1D'), (new DateTime($to))->modify('+1 day'));
foreach ($period as $dayObj) {
    $key    = $dayObj->format('Y-m-d');
    $amount = $salesByDay[$key]['amount'] ?? 0;
    $daily[] = [
        'date'   => $key,
        'cnt'    => $salesByDay[$key]['cnt'] ?? 0,
        'amount' => $amount,
        'profit' => $hasCost ? $amount - ($costByDay[$key] ?? 0) : null,
    ];
    if ($amount > $bestAmount) {
        $bestAmount = $amount;
        $bestDay    = $key;
    }
}

// ---------- 4. EXPORT TO EXCEL (CSV file) ----------
if (($_GET['export'] ?? '') === 'csv') {
    $num = function ($value): string {
        return number_format((float)$value, 2, '.', '');   // plain numbers, so Excel can add them up
    };

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // lets Excel read the file correctly

    fputcsv($out, ['Sales report', $from, 'to', $to, $daysCount . ' day(s)']);
    fputcsv($out, []);
    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Number of sales', $salesCount]);
    fputcsv($out, ['Voided sales (not counted)', $voidCount]);
    fputcsv($out, ['Total sales', $num($salesTotal)]);
    fputcsv($out, ['Discounts given', $num($discountTotal)]);
    fputcsv($out, ['Profit', $profitTotal === null ? '' : $num($profitTotal)]);
    foreach ($paymentTypes as $type) {
        fputcsv($out, [$type['label'] . ' sales (' . $type['count'] . ')', $num($type['amount'])]);
    }
    fputcsv($out, ['Utang given', $num($utangGiven)]);
    fputcsv($out, ['Utang payments received', $utangPaid === null ? '' : $num($utangPaid)]);

    if ($daysCount > 1) {
        fputcsv($out, []);
        fputcsv($out, ['DAY BY DAY']);
        fputcsv($out, ['Date', 'Number of sales', 'Total sales', 'Profit']);
        foreach ($daily as $d) {
            fputcsv($out, [$d['date'], $d['cnt'], $num($d['amount']), $d['profit'] === null ? '' : $num($d['profit'])]);
        }
        fputcsv($out, ['Total', $salesCount, $num($salesTotal), $profitTotal === null ? '' : $num($profitTotal)]);
    }

    fputcsv($out, []);
    fputcsv($out, ['PRODUCTS SOLD']);
    fputcsv($out, ['Product', 'Qty sold', 'Sales (before discount)', 'Cost', 'Profit']);
    foreach ($products as $p) {
        fputcsv($out, [
            $p['name'], (int)$p['qty'], $num($p['amount']),
            $hasCost ? $num($p['cost']) : '', $hasCost ? $num($p['profit']) : '',
        ]);
    }
    fputcsv($out, ['Total', (int)$qtyTotal, $num($amountTotal), $hasCost ? $num($costTotal) : '', '']);

    fputcsv($out, []);
    fputcsv($out, ['CASHIERS']);
    fputcsv($out, ['Cashier', 'Number of sales', 'Total sales']);
    foreach ($cashiers as $c) {
        fputcsv($out, [$c['cashier'], (int)$c['cnt'], $num($c['amount'])]);
    }

    fclose($out);
    exit;
}

// Makes a sortable column title for the products table.
$sortLink = function (string $key, string $label) use ($from, $to, $sort): string {
    $url   = 'daily_report.php?' . http_build_query(['from' => $from, 'to' => $to, 'sort' => $key]);
    $arrow = $sort === $key ? ($key === 'name' ? ' &#9650;' : ' &#9660;') : '';
    return '<a class="text-decoration-none text-reset" href="' . e($url) . '" title="Sort by ' . e($label) . '">' . e($label) . $arrow . '</a>';
};

$useBootstrap = true;   // tells header.php and footer.php to load Bootstrap
$pageTitle    = 'Sales Report';
require __DIR__ . '/includes/header.php';
?>

<!-- When printing: hide the menu and the buttons, and print only the report. -->
<style media="print">
    .sidenav, .no-print { display: none !important; }
    .main-content { padding: 0 !important; }
    body { background: #fff !important; }
</style>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1 d-flex align-items-center gap-2"><?= icon('report', 26, 'text-primary') ?> Sales Report</h1>
        <div class="text-muted"><?= e($rangeLabel) ?><?= $daysCount > 1 ? ' &middot; ' . $daysCount . ' days' : '' ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1"
           href="daily_report.php?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>"><?= icon('export', 16) ?> Export to Excel (CSV)</a>
        <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" onclick="window.print()"><?= icon('printer', 16) ?> Print / Save as PDF</button>
    </div>
</div>

<!-- CHOOSE THE DATES -->
<div class="card shadow-sm border-0 mb-4 no-print">
    <div class="card-body">
        <form method="get" action="daily_report.php" class="row g-2 align-items-end">
            <div class="col-6 col-md-auto">
                <label class="form-label small mb-1" for="from">From</label>
                <input type="date" id="from" name="from" class="form-control form-control-sm" value="<?= e($from) ?>" required>
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small mb-1" for="to">To</label>
                <input type="date" id="to" name="to" class="form-control form-control-sm" value="<?= e($to) ?>" required>
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1" type="submit"><?= icon('search', 16) ?> Show report</button>
            </div>
        </form>

        <div class="d-flex flex-wrap gap-2 mt-3">
            <?php foreach ($quick as $label => [$qFrom, $qTo]): ?>
                <a class="btn btn-sm d-inline-flex align-items-center gap-1 <?= ($from === $qFrom && $to === $qTo) ? 'btn-secondary' : 'btn-outline-secondary' ?>"
                   href="daily_report.php?<?= e(http_build_query(['from' => $qFrom, 'to' => $qTo])) ?>"><?= icon('calendar', 15) ?> <?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="text-muted small mt-2">Pick any two dates, or use a quick button. A report can cover up to <?= $maxDays ?> days.</div>
    </div>
</div>

<?php if ($capped): ?>
    <div class="alert alert-warning">That range was longer than <?= $maxDays ?> days, so the report shows the last <?= $maxDays ?> days up to <?= e($to) ?>.</div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Total sales</div>
                    <span class="text-success"><?= icon('banknote', 22) ?></span>
                </div>
                <div class="fs-3 fw-bold"><?= money($salesTotal) ?></div>
                <div class="text-muted small"><?= $salesCount ?> <?= $salesCount === 1 ? 'sale' : 'sales' ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Profit</div>
                    <span class="text-primary"><?= icon('trending', 22) ?></span>
                </div>
                <div class="fs-3 fw-bold"><?= $profitTotal === null ? '-' : money($profitTotal) ?></div>
                <div class="text-muted small">Sales minus item cost</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-secondary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Discounts given</div>
                    <span class="text-secondary"><?= icon('check', 22) ?></span>
                </div>
                <div class="fs-3 fw-bold"><?= money($discountTotal) ?></div>
                <div class="text-muted small"><?= $voidCount ?> voided <?= $voidCount === 1 ? 'sale' : 'sales' ?> (not counted)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="text-muted small text-uppercase">Utang given</div>
                    <span class="text-warning"><?= icon('wallet', 22) ?></span>
                </div>
                <div class="fs-3 fw-bold"><?= money($utangGiven) ?></div>
                <div class="text-muted small">Payments received: <?= $utangPaid === null ? '-' : money($utangPaid) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if ($daysCount > 1): ?>
<!-- DAY BY DAY -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('chart', 18, 'text-primary') ?> Day by day</div>
    <div class="card-body">
        <?php if (is_file(__DIR__ . '/js/chart.umd.js') && $salesTotal > 0): ?>
            <canvas id="rangeChart" height="90"></canvas>
            <script src="js/chart.umd.js"></script>
            <script>
                // Wrapped in a function so its names never clash with anything else on the page.
                (function () {
                    const labels = <?= json_encode(array_map(fn($d) => date('M j', strtotime($d['date'])), $daily)) ?>;
                    const sales = <?= json_encode(array_map(fn($d) => round($d['amount'], 2), $daily)) ?>;
                    const profit = <?= json_encode(array_map(fn($d) => $d['profit'] === null ? null : round($d['profit'], 2), $daily)) ?>;
                    const showProfit = <?= $hasCost ? 'true' : 'false' ?>;

                    const datasets = [{
                        type: 'bar', label: 'Sales', data: sales,
                        backgroundColor: 'rgba(25, 135, 84, 0.65)', borderRadius: 4
                    }];
                    if (showProfit) {
                        datasets.push({
                            type: 'line', label: 'Profit', data: profit,
                            borderColor: '#0d6efd', backgroundColor: '#0d6efd',
                            tension: 0.3, pointRadius: labels.length > 31 ? 0 : 3
                        });
                    }

                    new Chart(document.getElementById('rangeChart'), {
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
                                x: { ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 15 } },
                                y: {
                                    beginAtZero: true,
                                    ticks: { callback: function (v) { return Number(v).toLocaleString('en-US'); } }
                                }
                            }
                        }
                    });
                })();
            </script>
        <?php elseif (!is_file(__DIR__ . '/js/chart.umd.js')): ?>
            <div class="alert alert-warning mb-0">The chart file is missing. Save <b>chart.umd.js</b> in the <b>js</b> folder.</div>
        <?php else: ?>
            <p class="text-muted mb-0">No sales in this range, so there is nothing to draw.</p>
        <?php endif; ?>
    </div>

    <div class="table-responsive" style="max-height: 420px; overflow: auto;">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th class="text-end">Sales</th>
                    <th class="text-end">Total sales</th>
                    <th class="text-end">Profit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daily as $d): ?>
                    <tr class="<?= $d['cnt'] === 0 ? 'text-muted' : '' ?>">
                        <td>
                            <?= e(date('D, M d, Y', strtotime($d['date']))) ?>
                            <?php if ($d['date'] === $bestDay): ?>
                                <span class="badge text-bg-success ms-1">Best day</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $d['cnt'] ?></td>
                        <td class="text-end"><?= money($d['amount']) ?></td>
                        <td class="text-end <?= $d['profit'] !== null && $d['profit'] < 0 ? 'text-danger fw-bold' : '' ?>">
                            <?= $d['profit'] === null ? '-' : money($d['profit']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td>Total</td>
                    <td class="text-end"><?= $salesCount ?></td>
                    <td class="text-end"><?= money($salesTotal) ?></td>
                    <td class="text-end"><?= $profitTotal === null ? '-' : money($profitTotal) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="card-footer bg-white text-muted small">Days with no sales are shown as 0, so slow days stand out.</div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">

    <!-- BY PAYMENT TYPE -->
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('pie', 18, 'text-primary') ?> By payment type</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Type</th><th class="text-end">Sales</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentTypes as $type): ?>
                            <tr>
                                <td><?= e($type['label']) ?></td>
                                <td class="text-end"><?= $type['count'] ?></td>
                                <td class="text-end"><?= money($type['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="fw-bold">
                            <td>Total</td>
                            <td class="text-end"><?= $salesCount ?></td>
                            <td class="text-end"><?= money($salesTotal) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white text-muted small">Utang is the full value of sales made on credit.</div>
        </div>
    </div>

    <!-- BY CASHIER -->
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('users', 18, 'text-secondary') ?> By cashier</div>
            <?php if (!$cashiers): ?>
                <div class="card-body text-muted">No sales in this range.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Cashier</th><th class="text-end">Sales</th><th class="text-end">Amount</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cashiers as $c): ?>
                                <tr>
                                    <td><?= e($c['cashier']) ?></td>
                                    <td class="text-end"><?= (int)$c['cnt'] ?></td>
                                    <td class="text-end"><?= money($c['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- PRODUCTS SOLD -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2"><?= icon('box', 18, 'text-secondary') ?> Products sold</div>
    <?php if (!$products): ?>
        <div class="card-body text-muted">No products were sold in this range.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= $sortLink('name', 'Product') ?></th>
                        <th class="text-end"><?= $sortLink('qty', 'Qty sold') ?></th>
                        <th class="text-end"><?= $sortLink('amount', 'Sales') ?></th>
                        <th class="text-end">Cost</th>
                        <th class="text-end"><?= $hasCost ? $sortLink('profit', 'Profit') : 'Profit' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <td><?= e($p['name']) ?></td>
                            <td class="text-end"><?= (int)$p['qty'] ?></td>
                            <td class="text-end"><?= money($p['amount']) ?></td>
                            <td class="text-end"><?= $hasCost ? money($p['cost']) : '-' ?></td>
                            <td class="text-end fw-bold <?= $hasCost && $p['profit'] < 0 ? 'text-danger' : '' ?>">
                                <?= $hasCost ? money($p['profit']) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td>Total</td>
                        <td class="text-end"><?= (int)$qtyTotal ?></td>
                        <td class="text-end"><?= money($amountTotal) ?></td>
                        <td class="text-end"><?= $hasCost ? money($costTotal) : '-' ?></td>
                        <td class="text-end"><?= $hasCost ? money($amountTotal - $costTotal) : '-' ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer bg-white text-muted small">
            Click a column title to sort. Product amounts are before discounts.
            Discounts given in this range: <?= money($discountTotal) ?>, so the real total sales are <?= money($salesTotal) ?>
            <?php if ($hasCost): ?> and the real profit is <?= money($profitTotal) ?><?php endif; ?>.
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>