<?php
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

if (!$pdo instanceof PDO) {
    throw new RuntimeException('پەیوەندی PDO لە includes/db.php بەردەست نییە.');
}

if (empty($_SESSION['reports_csrf_token'])) {
    $_SESSION['reports_csrf_token'] = bin2hex(random_bytes(32));
}

function report_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function report_money(float $amount, string $currency): string
{
    return $currency === 'IQD'
        ? number_format($amount) . ' د.ع'
        : '$' . number_format($amount, 2);
}

$page_title = 'ڕاپۆرتی فرۆشتن';
$today = date('Y-m-d');
$firstOfMonth = date('Y-m-01');
$errorMessage = '';
$successMessage = $_SESSION['reports_success'] ?? '';
$filterOptions = [
    'all' => 'دۆخی ئاسایی بوون',
    'today' => 'ئەمڕۆ',
    'yesterday' => 'دوێنێ',
    'week' => 'هەفتەیەک لەمەوبەر',
    'month' => 'مانگێک لەمەوبەر',
    'year' => 'ساڵێک لەمەوبەر',
];
$reportFilter = $_GET['filter'] ?? '';
$invalidReportFilter = !is_string($reportFilter)
    || ($reportFilter !== '' && !array_key_exists($reportFilter, $filterOptions));
if ($invalidReportFilter) {
    $reportFilter = '';
    $errorMessage = 'فلتەری داواکراو دروست نییە.';
}
unset($_SESSION['reports_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $saleId = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($token) || !hash_equals($_SESSION['reports_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif ($action !== 'delete_sale') {
        $errorMessage = 'کرداری داواکراو ناسراو نییە.';
    } elseif ($saleId === false || $saleId === null || $saleId < 1) {
        $errorMessage = 'ژمارەی پسووڵەکە دروست نییە.';
    } else {
        try {
            $pdo->beginTransaction();

            $saleStmt = $pdo->prepare('SELECT sale_id FROM sales WHERE sale_id = :sale_id FOR UPDATE');
            $saleStmt->execute(['sale_id' => $saleId]);
            if ($saleStmt->fetchColumn() === false) {
                $pdo->rollBack();
                $errorMessage = 'پسووڵەکە نەدۆزرایەوە.';
            } else {
                $itemsStmt = $pdo->prepare('DELETE FROM sale_items WHERE sale_id = :sale_id');
                $itemsStmt->execute(['sale_id' => $saleId]);

                $deleteStmt = $pdo->prepare('DELETE FROM sales WHERE sale_id = :sale_id');
                $deleteStmt->execute(['sale_id' => $saleId]);
                $pdo->commit();

                $_SESSION['reports_success'] = 'پسووڵەکە بە سەرکەوتوویی سڕایەوە.';
                $returnFrom = $_POST['from'] ?? '';
                $returnTo = $_POST['to'] ?? '';
                if (!is_string($returnFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnFrom)
                    || !checkdate((int) substr($returnFrom, 5, 2), (int) substr($returnFrom, 8, 2), (int) substr($returnFrom, 0, 4))) {
                    $returnFrom = $firstOfMonth;
                }
                if (!is_string($returnTo) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnTo)
                    || !checkdate((int) substr($returnTo, 5, 2), (int) substr($returnTo, 8, 2), (int) substr($returnTo, 0, 4))) {
                    $returnTo = $today;
                }

                $returnFilter = $_POST['filter'] ?? '';
                $filterQuery = is_string($returnFilter) && array_key_exists($returnFilter, $filterOptions)
                    ? '&filter=' . rawurlencode($returnFilter)
                    : '';
                header('Location: reports.php?from=' . rawurlencode($returnFrom) . '&to=' . rawurlencode($returnTo) . $filterQuery);
                exit;
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Deleting reported sale failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە سڕینەوەی پسووڵەکەدا ڕوویدا.';
        }
    }
}

$fromDate = $_GET['from'] ?? $firstOfMonth;
$toDate = $_GET['to'] ?? $today;
if ($reportFilter !== '') {
    if ($reportFilter === 'all') {
        $fromDate = '';
        $toDate = '';
    } else {
        $filterEnd = new DateTimeImmutable($today);
        $filterStarts = [
            'today' => $filterEnd,
            'yesterday' => $filterEnd->modify('-1 day'),
            'week' => $filterEnd->modify('-6 days'),
            'month' => $filterEnd->modify('-1 month'),
            'year' => $filterEnd->modify('-1 year'),
        ];
        $fromDate = $filterStarts[$reportFilter]->format('Y-m-d');
        $toDate = $reportFilter === 'yesterday'
            ? $filterEnd->modify('-1 day')->format('Y-m-d')
            : $today;
    }
}
$sales = [];
$saleCount = 0;
$currencyTotals = [
    'USD' => ['revenue' => 0.0, 'gross' => 0.0, 'discount' => 0.0],
    'IQD' => ['revenue' => 0.0, 'gross' => 0.0, 'discount' => 0.0],
];

$validFrom = is_string($fromDate)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)
    && checkdate((int) substr($fromDate, 5, 2), (int) substr($fromDate, 8, 2), (int) substr($fromDate, 0, 4));
$validTo = is_string($toDate)
    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)
    && checkdate((int) substr($toDate, 5, 2), (int) substr($toDate, 8, 2), (int) substr($toDate, 0, 4));

if (!$invalidReportFilter && $reportFilter !== 'all' && (!$validFrom || !$validTo)) {
    $errorMessage = 'تکایە بەرواری دەستپێک و کۆتایی بە شێوەی دروست هەڵبژێرە.';
} elseif (!$invalidReportFilter && $reportFilter !== 'all' && $fromDate > $toDate) {
    $errorMessage = 'بەرواری دەستپێک نابێت لە بەرواری کۆتایی دواتر بێت.';
} elseif (!$invalidReportFilter) {
    try {
        $dateCondition = $reportFilter === 'all'
            ? ''
            : 'WHERE sale_date >= :from_start
                 AND sale_date < DATE_ADD(:to_end, INTERVAL 1 DAY)';
        $dateParams = $reportFilter === 'all'
            ? []
            : [
                'from_start' => $fromDate . ' 00:00:00',
                'to_end' => $toDate . ' 00:00:00',
            ];
        $stmt = $pdo->prepare(
            'SELECT sale_id, customer_id, user_id, total_amount, discount,
                    net_amount, paid_amount, remaining_amount, payment_method, currency, sale_date
             FROM sales
             ' . $dateCondition . '
             ORDER BY sale_date DESC, sale_id DESC'
        );
        $stmt->execute($dateParams);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $summaryStmt = $pdo->prepare(
            'SELECT currency,
                COALESCE(SUM(net_amount), 0) AS total_revenue,
                COALESCE(SUM(total_amount), 0) AS total_gross,
                COALESCE(SUM(discount), 0) AS total_discount,
                COUNT(*) AS sale_count
             FROM sales
             ' . $dateCondition . '
             GROUP BY currency'
        );
        $summaryStmt->execute($dateParams);
        foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $summary) {
            $currency = $summary['currency'] === 'IQD' ? 'IQD' : 'USD';
            $currencyTotals[$currency] = [
                'revenue' => (float) $summary['total_revenue'],
                'gross' => (float) $summary['total_gross'],
                'discount' => (float) $summary['total_discount'],
            ];
            $saleCount += (int) $summary['sale_count'];
        }
    } catch (PDOException $exception) {
        error_log('Loading sales report failed: ' . $exception->getMessage());
        http_response_code(500);
        $errorMessage = 'هەڵەیەک لە هێنانی ڕاپۆرتی فرۆشتندا ڕوویدا.';
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">ڕاپۆرتی فرۆشتن</h2>
            <p class="mt-1 text-sm text-slate-500">داهات و پسوولە فرۆشراوەکان بەپێی ماوەی کاتی ببینە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= report_escape((string) $successMessage) ?>
            </div>
        <?php endif; ?>
        <form id="sales-report-filter-form" method="get" action="reports.php" class="mb-6 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="filter" class="mb-2 block text-sm font-semibold text-slate-700">فلتەر</label>
                <select id="filter" name="filter" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    <option value="">هەڵبژاردنی فلتەر</option>
                    <?php foreach ($filterOptions as $filterValue => $filterLabel): ?>
                        <option value="<?= report_escape($filterValue) ?>" <?= $reportFilter === $filterValue ? 'selected' : '' ?>>
                            <?= report_escape($filterLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex-1">
                <label for="from" class="mb-2 block text-sm font-semibold text-slate-700">لە بەرواری</label>
                <input id="from" name="from" type="date" <?= $reportFilter !== 'all' ? 'required' : 'disabled' ?> value="<?= report_escape(is_string($fromDate) ? $fromDate : '') ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
            </div>
            <div class="flex-1">
                <label for="to" class="mb-2 block text-sm font-semibold text-slate-700">تا بەرواری</label>
                <input id="to" name="to" type="date" <?= $reportFilter !== 'all' ? 'required' : 'disabled' ?> value="<?= report_escape(is_string($toDate) ? $toDate : '') ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
            </div>
            <button type="submit" class="rounded-xl bg-slate-900 px-6 py-3 font-semibold text-white hover:bg-slate-700">پیشاندانی ڕاپۆرت</button>
        </form>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= report_escape($errorMessage) ?>
            </div>
        <?php else: ?>
            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <section class="rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-emerald-800">کۆی داهاتی خاوێن</p>
                    <p class="mt-2 text-lg font-extrabold text-emerald-700">
                        دۆلار: <?= report_escape(report_money($currencyTotals['USD']['revenue'], 'USD')) ?><br>
                        دینار: <?= report_escape(report_money($currencyTotals['IQD']['revenue'], 'IQD')) ?>
                    </p>
                </section>
                <section class="rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 to-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-blue-800">فرۆشتنی پێش داشکاندن</p>
                    <p class="mt-2 text-lg font-extrabold text-blue-700">
                        دۆلار: <?= report_escape(report_money($currencyTotals['USD']['gross'], 'USD')) ?><br>
                        دینار: <?= report_escape(report_money($currencyTotals['IQD']['gross'], 'IQD')) ?>
                    </p>
                </section>
                <section class="rounded-2xl border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-amber-800">کۆی داشکاندن</p>
                    <p class="mt-2 text-lg font-extrabold text-amber-700">
                        دۆلار: <?= report_escape(report_money($currencyTotals['USD']['discount'], 'USD')) ?><br>
                        دینار: <?= report_escape(report_money($currencyTotals['IQD']['discount'], 'IQD')) ?>
                    </p>
                </section>
                <section class="rounded-2xl border border-violet-100 bg-gradient-to-br from-violet-50 to-white p-5 shadow-sm">
                    <p class="text-sm font-medium text-violet-800">ژمارەی پسوولەکان</p>
                    <p class="mt-2 text-2xl font-extrabold text-violet-700"><?= number_format($saleCount) ?></p>
                </section>
            </div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h3 class="text-lg font-bold text-slate-800">پسوولە فرۆشراوەکان</h3>
                    <p class="mt-1 text-sm text-slate-500"><?= $reportFilter === 'all' ? 'هەموو بەروارەکان' : report_escape($fromDate) . ' — ' . report_escape($toDate) ?></p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[950px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-4 font-semibold">ژمارەی پسوولە</th>
                                <th scope="col" class="px-4 py-4 font-semibold">کڕیار</th>
                                <th scope="col" class="px-4 py-4 font-semibold">کاشێر</th>
                                <th scope="col" class="px-4 py-4 font-semibold">کۆی فرۆشتن</th>
                                <th scope="col" class="px-4 py-4 font-semibold">داشکاندن</th>
                                <th scope="col" class="px-4 py-4 font-semibold">داهاتی خاوێن</th>
                                <th scope="col" class="px-4 py-4 font-semibold">دراوی وەرگیراو</th>
                                <th scope="col" class="px-4 py-4 font-semibold">ماوە</th>
                                <th scope="col" class="px-4 py-4 font-semibold">شێوازی پارەدان</th>
                                <th scope="col" class="px-4 py-4 font-semibold">پسووڵە</th>
                                <th scope="col" class="px-4 py-4 font-semibold">بەروار</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if ($sales): ?>
                                <?php foreach ($sales as $sale): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-800">#<?= (int) $sale['sale_id'] ?></td>
                                        <td class="px-4 py-4"><?= $sale['customer_id'] === null ? 'کڕیاری گشتی' : '#' . (int) $sale['customer_id'] ?></td>
                                        <td class="px-4 py-4">#<?= (int) $sale['user_id'] ?></td>
                                        <td class="whitespace-nowrap px-4 py-4"><?= report_escape(report_money((float) $sale['total_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap px-4 py-4"><?= report_escape(report_money((float) $sale['discount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap px-4 py-4 font-semibold text-emerald-700"><?= report_escape(report_money((float) $sale['net_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap px-4 py-4"><?= report_escape(report_money((float) $sale['paid_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap px-4 py-4"><?= report_escape(report_money((float) $sale['remaining_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="px-4 py-4"><?= report_escape((string) $sale['payment_method']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-4">
                                            <div class="flex items-center gap-2">
                                                <a href="receipt.php?sale_id=<?= (int) $sale['sale_id'] ?>" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg bg-amber-500 px-3 py-2 text-xs font-semibold text-slate-950 hover:bg-amber-400">
                                                    بینینی پسووڵە
                                                </a>
                                                <form method="post" action="reports.php" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم پسووڵەیە؟');">
                                                    <input type="hidden" name="csrf_token" value="<?= report_escape($_SESSION['reports_csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete_sale">
                                                    <input type="hidden" name="sale_id" value="<?= (int) $sale['sale_id'] ?>">
                                                    <input type="hidden" name="from" value="<?= report_escape((string) $fromDate) ?>">
                                                    <input type="hidden" name="to" value="<?= report_escape((string) $toDate) ?>">
                                                    <input type="hidden" name="filter" value="<?= report_escape($reportFilter) ?>">
                                                    <button type="submit" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700">
                                                        سڕینەوە
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-4"><?= report_escape((string) $sale['sale_date']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="11" class="px-6 py-12 text-center text-slate-500">لەو ماوەیەدا هیچ فرۆشتنێک تۆمار نەکراوە.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
</div>
</div>
<script>
    const reportFilterForm = document.getElementById('sales-report-filter-form');
    const reportFilterSelect = document.getElementById('filter');
    const reportDateInputs = reportFilterForm.querySelectorAll('input[type="date"]');

    function updateReportDateInputs() {
        const showDateInputs = reportFilterSelect.value !== 'all';
        reportDateInputs.forEach((dateInput) => {
            dateInput.disabled = !showDateInputs;
            dateInput.required = showDateInputs;
        });
    }

    reportFilterSelect.addEventListener('change', () => {
        updateReportDateInputs();
        reportFilterForm.requestSubmit();
    });
    reportDateInputs.forEach((dateInput) => {
        dateInput.addEventListener('change', () => {
            reportFilterSelect.value = '';
            updateReportDateInputs();
        });
    });
</script>
</body>
</html>
