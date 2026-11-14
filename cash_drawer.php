c<?php
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

if (!$pdo instanceof PDO) {
    throw new RuntimeException('پەیوەندی PDO لە includes/db.php بەردەست نییە.');
}

function cash_drawer_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'داخستنی قاسەی ڕۆژانە';
$dateInput = $_GET['date'] ?? date('Y-m-d');
$selectedDate = is_string($dateInput) ? $dateInput : '';
$errorMessage = '';
$cashSales = 0.0;
$cashSalesIqd = 0.0;
$expenses = 0.0;
$cashSaleCount = 0;
$expenseCount = 0;

$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)
    ? DateTime::createFromFormat('!Y-m-d', $selectedDate)
    : false;
$dateErrors = DateTime::getLastErrors();
$dateValid = $date
    && $date->format('Y-m-d') === $selectedDate
    && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

if (!$dateValid) {
    $errorMessage = 'تکایە بەروارێکی دروست هەڵبژێرە.';
} else {
    try {
        $salesStmt = $pdo->prepare(
            'SELECT currency, COALESCE(SUM(paid_amount), 0) AS cash_total, COUNT(*) AS sale_count
             FROM sales
             WHERE payment_method = :payment_method
               AND sale_date >= :day_start
               AND sale_date < DATE_ADD(:day_end, INTERVAL 1 DAY)
             GROUP BY currency'
        );
        $salesStmt->execute([
            'payment_method' => 'cash',
            'day_start' => $selectedDate . ' 00:00:00',
            'day_end' => $selectedDate . ' 00:00:00',
        ]);
        foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $salesSummary) {
            if ($salesSummary['currency'] === 'IQD') {
                $cashSalesIqd = (float) $salesSummary['cash_total'];
            } else {
                $cashSales = (float) $salesSummary['cash_total'];
            }
            $cashSaleCount += (int) $salesSummary['sale_count'];
        }

        $expensesStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS expense_total, COUNT(*) AS expense_count
             FROM expenses
             WHERE expense_date >= :day_start
               AND expense_date < DATE_ADD(:day_end, INTERVAL 1 DAY)'
        );
        $expensesStmt->execute([
            'day_start' => $selectedDate . ' 00:00:00',
            'day_end' => $selectedDate . ' 00:00:00',
        ]);
        $expenseSummary = $expensesStmt->fetch(PDO::FETCH_ASSOC);
        $expenses = (float) $expenseSummary['expense_total'];
        $expenseCount = (int) $expenseSummary['expense_count'];
    } catch (PDOException $exception) {
        error_log('Loading cash drawer summary failed: ' . $exception->getMessage());
        http_response_code(500);
        $errorMessage = 'هەڵەیەک لە هەژمارکردنی قاسەی ئەم ڕۆژەدا ڕوویدا.';
    }
}

$netCash = $cashSales - $expenses;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">داخستنی قاسەی ڕۆژانە</h2>
            <p class="mt-1 text-sm text-slate-500">فرۆشتنی نەقد، خەرجی و پارەی خاوێنی قاسە بۆ ڕۆژێک ببینە.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= cash_drawer_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <form method="get" action="cash_drawer.php" class="mb-6 flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="date" class="mb-2 block text-sm font-semibold text-slate-700">بەرواری داخستنی قاسە</label>
                <input id="date" name="date" type="date" required value="<?= cash_drawer_escape($selectedDate) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
            </div>
            <button type="submit" class="rounded-xl bg-slate-900 px-6 py-3 font-semibold text-white transition hover:bg-slate-800">
                <i class="fa-solid fa-magnifying-glass ml-2" aria-hidden="true"></i>
                هەژمارکردن
            </button>
        </form>

        <?php if ($dateValid): ?>
            <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-emerald-800">کۆی فرۆشتنی نەقد</p>
                            <p class="mt-2 text-2xl font-extrabold text-emerald-950">$<?= number_format($cashSales, 2) ?></p>
                            <p class="mt-1 text-lg font-bold text-emerald-900"><?= number_format($cashSalesIqd) ?> د.ع</p>
                            <p class="mt-2 text-xs text-emerald-700"><?= number_format($cashSaleCount) ?> پسووڵە</p>
                        </div>
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                            <i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i>
                        </span>
                    </div>
                </section>
                <section class="rounded-2xl border border-red-200 bg-red-50 p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-red-800">کۆی خەرجییەکان</p>
                            <p class="mt-2 text-3xl font-extrabold text-red-950">$<?= number_format($expenses, 2) ?></p>
                            <p class="mt-2 text-xs text-red-700"><?= number_format($expenseCount) ?> خەرجی</p>
                        </div>
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-100 text-red-700">
                            <i class="fa-solid fa-receipt" aria-hidden="true"></i>
                        </span>
                    </div>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-slate-600">پارەی خاوێنی قاسە</p>
                            <p class="mt-2 text-3xl font-extrabold <?= $netCash < 0 ? 'text-red-700' : 'text-slate-950' ?>">$<?= number_format($netCash, 2) ?></p>
                            <p class="mt-2 text-xs text-slate-500">دۆلار: فرۆشتنی نەقد − خەرجییەکان</p>
                        </div>
                        <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                            <i class="fa-solid fa-vault" aria-hidden="true"></i>
                        </span>
                    </div>
                </section>
            </div>
            <div class="rounded-xl border border-slate-200 bg-white px-5 py-4 text-sm text-slate-600">
                کەشفی قاسە بۆ بەرواری <span class="font-bold text-slate-800" dir="ltr"><?= cash_drawer_escape($selectedDate) ?></span>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
