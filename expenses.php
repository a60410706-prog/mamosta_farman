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

if (empty($_SESSION['expenses_csrf_token'])) {
    $_SESSION['expenses_csrf_token'] = bin2hex(random_bytes(32));
}

function expense_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'بەڕێوەبردنی خەرجییەکان';
$errorMessage = '';
$successMessage = $_SESSION['expense_success'] ?? '';
unset($_SESSION['expense_success']);
$title = '';
$amount = '';
$expenseDate = date('Y-m-d');
$notes = '';
$expenses = [];
$totalExpenses = 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $titleInput = $_POST['expense_title'] ?? '';
    $amountInput = $_POST['amount'] ?? '';
    $dateInput = $_POST['expense_date'] ?? '';
    $notesInput = $_POST['notes'] ?? '';

    $title = is_string($titleInput) ? trim($titleInput) : '';
    $amount = is_scalar($amountInput) ? (string) $amountInput : '';
    $expenseDate = is_string($dateInput) ? $dateInput : '';
    $notes = is_string($notesInput) ? trim($notesInput) : '';

    if (!is_string($token) || !hash_equals($_SESSION['expenses_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif ($title === '') {
        $errorMessage = 'تکایە ناونیشانی خەرجی بنووسە.';
    } elseif (
        $amount === '' ||
        !is_numeric($amount) ||
        !is_finite((float) $amount) ||
        (float) $amount <= 0
    ) {
        $errorMessage = 'بڕی خەرجی دەبێت ژمارەیەکی دروست و لە سفر زیاتر بێت.';
    } else {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)
            ? DateTime::createFromFormat('!Y-m-d', $expenseDate)
            : false;
        $dateErrors = DateTime::getLastErrors();
        $dateValid = $date
            && $date->format('Y-m-d') === $expenseDate
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

        if (!$dateValid) {
            $errorMessage = 'بەرواری خەرجی دروست نییە.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO expenses (expense_title, amount, expense_date, user_id, notes)
                     VALUES (:expense_title, :amount, :expense_date, :user_id, :notes)'
                );
                $stmt->execute([
                    'expense_title' => $title,
                    'amount' => (float) $amount,
                    'expense_date' => $expenseDate,
                    'user_id' => (int) $_SESSION['user_id'],
                    'notes' => $notes !== '' ? $notes : null,
                ]);

                $_SESSION['expense_success'] = 'خەرجییەکە بە سەرکەوتوویی تۆمار کرا.';
                header('Location: expenses.php');
                exit;
            } catch (PDOException $exception) {
                error_log('Creating expense failed: ' . $exception->getMessage());
                $errorMessage = 'هەڵەیەک لە تۆمارکردنی خەرجی ڕوویدا.';
            }
        }
    }
}

try {
    $expenses = $pdo->query(
        'SELECT expense_id, expense_title, amount, expense_date, user_id, notes
         FROM expenses
         ORDER BY expense_date DESC, expense_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $totalExpenses = (float) $pdo->query(
        'SELECT COALESCE(SUM(amount), 0) FROM expenses'
    )->fetchColumn();
} catch (PDOException $exception) {
    error_log('Loading expenses failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی لیستی خەرجییەکاندا ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">بەڕێوەبردنی خەرجییەکان</h2>
            <p class="mt-1 text-sm text-slate-500">خەرجییەکان تۆمار بکە و کۆی گشتییان چاودێری بکە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= expense_escape($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= expense_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div dir="ltr" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <section dir="rtl" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                <h3 class="text-lg font-bold text-slate-800">تۆمارکردنی خەرجیی نوێ</h3>
                <p class="mt-1 text-sm text-slate-500">خەرجییەکە بە ناوی بەکارهێنەری ئێستا تۆمار دەکرێت.</p>

                <form method="post" action="expenses.php" class="mt-5 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= expense_escape($_SESSION['expenses_csrf_token']) ?>">
                    <div>
                        <label for="expense_title" class="mb-2 block text-sm font-semibold text-slate-700">ناونیشانی خەرجی</label>
                        <input id="expense_title" name="expense_title" type="text" required maxlength="255" value="<?= expense_escape($title) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="amount" class="mb-2 block text-sm font-semibold text-slate-700">بڕ ($)</label>
                        <input id="amount" name="amount" type="number" min="0.01" step="0.01" required value="<?= expense_escape($amount) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="expense_date" class="mb-2 block text-sm font-semibold text-slate-700">بەروار</label>
                        <input id="expense_date" name="expense_date" type="date" required value="<?= expense_escape($expenseDate) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="notes" class="mb-2 block text-sm font-semibold text-slate-700">تێبینی</label>
                        <textarea id="notes" name="notes" rows="3" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100"><?= expense_escape($notes) ?></textarea>
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-amber-500 px-5 py-3 font-bold text-slate-950 hover:bg-amber-400">
                        پاشەکەوتکردنی خەرجی
                    </button>
                </form>
            </section>

            <section dir="rtl" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">لیستی خەرجییەکان</h3>
                        <p class="mt-1 text-sm text-slate-500"><?= number_format(count($expenses)) ?> خەرجی</p>
                    </div>
                    <div class="rounded-xl bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700">
                        کۆی خەرجی: $<?= number_format($totalExpenses, 2) ?>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[700px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-4 font-semibold">ناونیشان</th>
                                <th scope="col" class="px-5 py-4 font-semibold">بڕ</th>
                                <th scope="col" class="px-5 py-4 font-semibold">بەروار</th>
                                <th scope="col" class="px-5 py-4 font-semibold">تێبینی</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if ($expenses): ?>
                                <?php foreach ($expenses as $expense): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4 font-semibold text-slate-800"><?= expense_escape((string) $expense['expense_title']) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold text-rose-700">$<?= number_format((float) $expense['amount'], 2) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4"><?= expense_escape((string) $expense['expense_date']) ?></td>
                                        <td class="max-w-xs truncate px-5 py-4 text-slate-500"><?= expense_escape((string) ($expense['notes'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="px-6 py-12 text-center text-slate-500">هێشتا هیچ خەرجییەک تۆمار نەکراوە.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</main>
</div>
</div>
</body>
</html>
