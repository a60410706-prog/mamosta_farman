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

function export_report_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function export_report_valid_date(string $date): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
        && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
}

function export_report_csv_text(string $value): string
{
    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

function export_report_money(float $amount, string $currency): string
{
    return $currency === 'IQD'
        ? number_format($amount) . ' د.ع'
        : '$' . number_format($amount, 2);
}

$page_title = 'هەناردەکردنی ڕاپۆرت';
$today = date('Y-m-d');
$fromDate = $_GET['from'] ?? date('Y-m-01');
$toDate = $_GET['to'] ?? $today;
$fromDate = is_string($fromDate) ? trim($fromDate) : '';
$toDate = is_string($toDate) ? trim($toDate) : '';
$action = $_GET['action'] ?? 'filter';
$action = is_string($action) ? $action : '';
$errorMessage = '';
$printMode = $action === 'export_pdf';
$sales = [];
$saleCount = 0;
$currencyTotals = [
    'USD' => ['gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'paid' => 0.0, 'remaining' => 0.0],
    'IQD' => ['gross' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'paid' => 0.0, 'remaining' => 0.0],
];

if (!in_array($action, ['filter', 'export_excel', 'export_pdf'], true)) {
    $errorMessage = 'کرداری داواکراو دروست نییە.';
} elseif (!export_report_valid_date($fromDate) || !export_report_valid_date($toDate)) {
    $errorMessage = 'تکایە بەرواری دەستپێک و کۆتایی بە شێوەی دروست هەڵبژێرە.';
} elseif ($fromDate > $toDate) {
    $errorMessage = 'بەرواری دەستپێک نابێت لە بەرواری کۆتایی دواتر بێت.';
} else {
    try {
        $stmt = $pdo->prepare(
            'SELECT s.sale_id, s.sale_date,
                    COALESCE(c.customer_name, \'\') AS customer_name,
                    COALESCE(u.full_name, \'\') AS cashier_name,
                    s.total_amount, s.discount, s.net_amount, s.paid_amount,
                    s.remaining_amount, s.payment_method, s.currency
             FROM sales AS s
             LEFT JOIN customers AS c ON c.customer_id = s.customer_id
             LEFT JOIN users AS u ON u.user_id = s.user_id
             WHERE s.sale_date >= :from_start
               AND s.sale_date < DATE_ADD(:to_end, INTERVAL 1 DAY)
             ORDER BY s.sale_date DESC, s.sale_id DESC'
        );
        $stmt->execute([
            'from_start' => $fromDate . ' 00:00:00',
            'to_end' => $toDate . ' 00:00:00',
        ]);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sales as $sale) {
            $currency = ($sale['currency'] ?? '') === 'IQD' ? 'IQD' : 'USD';
            $currencyTotals[$currency]['gross'] += (float) $sale['total_amount'];
            $currencyTotals[$currency]['discount'] += (float) $sale['discount'];
            $currencyTotals[$currency]['net'] += (float) $sale['net_amount'];
            $currencyTotals[$currency]['paid'] += (float) $sale['paid_amount'];
            $currencyTotals[$currency]['remaining'] += (float) $sale['remaining_amount'];
        }
        $saleCount = count($sales);
    } catch (PDOException $exception) {
        error_log('Loading export sales report failed: ' . $exception->getMessage());
        http_response_code(500);
        $errorMessage = 'هەڵەیەک لە هێنانی ڕاپۆرتی فرۆشتندا ڕوویدا.';
    }
}

if ($action === 'export_excel' && $errorMessage === '') {
    $temporaryStream = fopen('php://temp', 'w+b');
    if ($temporaryStream === false) {
        throw new RuntimeException('Unable to create temporary CSV stream.');
    }

    try {
        if (fwrite($temporaryStream, "\xEF\xBB\xBF") !== 3) {
            throw new RuntimeException('Unable to write the UTF-8 BOM to the CSV export.');
        }
        $headerWrite = fputcsv($temporaryStream, [
            'ژمارەی پسووڵە',
            'بەرواری فرۆشتن',
            'ناوی کڕیار',
            'ناوی فرۆشیار',
            'کۆی پێش داشکاندن',
            'داشکاندن',
            'کۆی خاوێن',
            'بڕی دراو',
            'ماوە',
            'شێوازی پارەدان',
            'دراو',
        ]);
        if ($headerWrite === false) {
            throw new RuntimeException('Unable to write CSV column headers.');
        }

        foreach ($sales as $sale) {
            $rowWrite = fputcsv($temporaryStream, [
                (int) $sale['sale_id'],
                (string) $sale['sale_date'],
                export_report_csv_text(($sale['customer_name'] ?? '') !== '' ? (string) $sale['customer_name'] : 'کڕیاری گشتی'),
                export_report_csv_text((string) ($sale['cashier_name'] ?? '')),
                (string) $sale['total_amount'],
                (string) $sale['discount'],
                (string) $sale['net_amount'],
                (string) $sale['paid_amount'],
                (string) $sale['remaining_amount'],
                export_report_csv_text((string) $sale['payment_method']),
                (string) $sale['currency'],
            ]);
            if ($rowWrite === false) {
                throw new RuntimeException('Unable to write a CSV sale row.');
            }
        }

        if (!rewind($temporaryStream)) {
            throw new RuntimeException('Unable to rewind the temporary CSV stream.');
        }
        $filename = 'sales-report-' . $fromDate . '-to-' . $toDate . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        fpassthru($temporaryStream);
    } finally {
        fclose($temporaryStream);
    }
    exit;
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<style>
    @media print {
        @page {
            size: landscape;
            margin: 12mm;
        }

        html,
        body {
            height: auto !important;
            overflow: visible !important;
            background: #fff !important;
        }

        body > .h-screen {
            display: block !important;
            height: auto !important;
            overflow: visible !important;
        }

        #app-sidebar,
        #app-top-header,
        .no-print {
            display: none !important;
        }

        #export-report-main {
            display: block !important;
            width: 100% !important;
            height: auto !important;
            overflow: visible !important;
            padding: 0 !important;
        }

        #print-report,
        #print-report * {
            visibility: visible !important;
        }

        #print-report {
            display: block !important;
            width: 100% !important;
            margin: 0 !important;
            color: #111827 !important;
        }

        #print-report table {
            width: 100% !important;
            border-collapse: collapse !important;
        }

        #print-report th,
        #print-report td {
            border: 1px solid #9ca3af !important;
            padding: 6px !important;
            color: #111827 !important;
            font-size: 9pt !important;
        }

        #print-report thead {
            display: table-header-group;
        }

        #print-report tr {
            break-inside: avoid;
        }

        #print-report .print-summary {
            break-inside: avoid;
        }
    }
</style>
<main id="export-report-main" class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="no-print mb-6">
            <h2 class="text-2xl font-bold text-slate-800">هەناردەکردنی ڕاپۆرتی فرۆشتن</h2>
            <p class="mt-1 text-sm text-slate-500">ماوەی بەروار دیاری بکە، پاشان CSV دابەزێنە یان ڕاپۆرتەکە چاپ بکە و وەک PDF هەڵیبگرە.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="no-print mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= export_report_escape($errorMessage) ?>
            </div>
        <?php else: ?>
            <form method="get" action="export_reports.php" class="no-print mb-6 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:flex-row lg:items-end">
                <div class="flex-1">
                    <label for="from" class="mb-2 block text-sm font-semibold text-slate-700">لە بەرواری</label>
                    <input id="from" name="from" type="date" required value="<?= export_report_escape($fromDate) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                </div>
                <div class="flex-1">
                    <label for="to" class="mb-2 block text-sm font-semibold text-slate-700">تا بەرواری</label>
                    <input id="to" name="to" type="date" required value="<?= export_report_escape($toDate) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                </div>
                <button type="submit" name="action" value="filter" class="rounded-xl bg-slate-800 px-5 py-3 font-semibold text-white transition hover:bg-slate-700">
                    پیشاندانی ڕاپۆرت
                </button>
                <button type="submit" name="action" value="export_excel" class="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white transition hover:bg-emerald-700">
                    <i class="fa-solid fa-file-csv" aria-hidden="true"></i> هەناردە بۆ Excel (CSV)
                </button>
                <button type="submit" name="action" value="export_pdf" class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-5 py-3 font-semibold text-slate-950 transition hover:bg-amber-400">
                    <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> چاپ / هەڵگرتن وەک PDF
                </button>
            </form>

            <section id="print-report" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
                <div class="mb-5 border-b border-slate-300 pb-4 text-center">
                    <h2 class="text-xl font-bold text-slate-900">ڕاپۆرتی فرۆشتن</h2>
                    <p class="mt-1 text-sm text-slate-600">ماوە: <?= export_report_escape($fromDate) ?> — <?= export_report_escape($toDate) ?></p>
                    <p class="mt-1 text-sm text-slate-600">ژمارەی پسووڵەکان: <?= number_format($saleCount) ?></p>
                </div>

                <div class="print-summary mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <?php foreach (['USD' => 'دۆلار', 'IQD' => 'دینار'] as $currency => $currencyLabel): ?>
                        <div class="rounded-lg border border-slate-300 p-4">
                            <h3 class="mb-2 font-bold text-slate-800">کۆی گشتی — <?= $currencyLabel ?></h3>
                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                <span>پێش داشکاندن:</span><strong><?= export_report_escape(export_report_money($currencyTotals[$currency]['gross'], $currency)) ?></strong>
                                <span>داشکاندن:</span><strong><?= export_report_escape(export_report_money($currencyTotals[$currency]['discount'], $currency)) ?></strong>
                                <span>کۆی خاوێن:</span><strong><?= export_report_escape(export_report_money($currencyTotals[$currency]['net'], $currency)) ?></strong>
                                <span>دراو:</span><strong><?= export_report_escape(export_report_money($currencyTotals[$currency]['paid'], $currency)) ?></strong>
                                <span>ماوە:</span><strong><?= export_report_escape(export_report_money($currencyTotals[$currency]['remaining'], $currency)) ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-right text-sm">
                        <thead>
                            <tr class="bg-slate-100 text-slate-800">
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">ژمارەی پسووڵە</th>
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">بەروار</th>
                                <th class="border border-slate-300 px-3 py-2">کڕیار</th>
                                <th class="border border-slate-300 px-3 py-2">فرۆشیار</th>
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">پێش داشکاندن</th>
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">داشکاندن</th>
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">کۆی خاوێن</th>
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">دراو</th>
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">ماوە</th>
                                <th class="whitespace-nowrap border border-slate-300 px-3 py-2">شێوازی پارەدان</th>
                                <th class="border border-slate-300 px-3 py-2">دراو</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sales === []): ?>
                                <tr><td colspan="11" class="border border-slate-300 px-4 py-8 text-center text-slate-500">هیچ فرۆشتنێک لەم ماوەیەدا نییە.</td></tr>
                            <?php else: ?>
                                <?php foreach ($sales as $sale): ?>
                                    <tr class="odd:bg-white even:bg-slate-50">
                                        <td class="whitespace-nowrap border border-slate-300 px-3 py-2">#<?= (int) $sale['sale_id'] ?></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-3 py-2"><?= export_report_escape((string) $sale['sale_date']) ?></td>
                                        <td class="border border-slate-300 px-3 py-2"><?= export_report_escape(($sale['customer_name'] ?? '') !== '' ? (string) $sale['customer_name'] : 'کڕیاری گشتی') ?></td>
                                        <td class="border border-slate-300 px-3 py-2"><?= export_report_escape((string) ($sale['cashier_name'] ?? '')) ?></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-3 py-2"><?= export_report_escape(export_report_money((float) $sale['total_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-3 py-2"><?= export_report_escape(export_report_money((float) $sale['discount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-3 py-2"><?= export_report_escape(export_report_money((float) $sale['net_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-3 py-2"><?= export_report_escape(export_report_money((float) $sale['paid_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-3 py-2"><?= export_report_escape(export_report_money((float) $sale['remaining_amount'], (string) $sale['currency'])) ?></td>
                                        <td class="border border-slate-300 px-3 py-2"><?= export_report_escape((string) $sale['payment_method']) ?></td>
                                        <td class="border border-slate-300 px-3 py-2"><?= export_report_escape((string) $sale['currency']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php if ($printMode && $errorMessage === ''): ?>
    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
<?php endif; ?>
</div>
</div>
</body>
</html>
