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

function payments_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$isPaymentsHome = !array_key_exists('type', $_GET);
$typeInput = $_GET['type'] ?? '';
$transactionType = is_string($typeInput) ? $typeInput : '';
$errorMessage = '';
$payments = [];

if (!$isPaymentsHome && !in_array($transactionType, ['in', 'out'], true)) {
    http_response_code(400);
    $errorMessage = 'جۆری پارەدان دروست نییە.';
    $transactionType = 'in';
}

$page_title = $isPaymentsHome
    ? 'پارەدان'
    : ($transactionType === 'in' ? 'وەرگرتنی پارە' : 'دانەوەی پارە');

if (!$isPaymentsHome && $errorMessage === '') {
    try {
        $paymentStmt = $pdo->prepare(
            'SELECT p.payment_id, p.party_type, p.party_id, p.transaction_type,
                    p.amount, p.payment_method, p.notes, p.payment_date,
                    CASE
                        WHEN p.party_type = \'customer\' THEN c.customer_name
                        WHEN p.party_type = \'supplier\' THEN COALESCE(s.supplier_name, s.company_name)
                    END AS party_name,
                    u.full_name AS user_name
             FROM payments AS p
             LEFT JOIN customers AS c
               ON p.party_type = \'customer\' AND c.customer_id = p.party_id
             LEFT JOIN suppliers AS s
               ON p.party_type = \'supplier\' AND s.supplier_id = p.party_id
             LEFT JOIN users AS u ON u.user_id = p.user_id
             WHERE p.transaction_type = :transaction_type
             ORDER BY p.payment_date DESC, p.payment_id DESC
             LIMIT 200'
        );
        $paymentStmt->execute(['transaction_type' => $transactionType]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        error_log('Loading payment records failed: ' . $exception->getMessage());
        http_response_code(500);
        $errorMessage = 'هەڵەیەک لە هێنانی تۆمارەکانی پارەداندا ڕوویدا.';
    }
}

$methodLabels = [
    'cash' => 'نەقد',
    'fastpay' => 'فاست‌پەی',
    'bank_transfer' => 'گواستنەوەی بانکی',
];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800"><?= payments_escape($page_title) ?></h2>
            <p class="mt-1 text-sm text-slate-500">
                <?= $isPaymentsHome
                    ? 'جۆری پارەدان هەڵبژێرە.'
                    : 'تۆمارە ڕاستەقینەکانی پارەدان لە خشتەی payments ـەوە پیشان دەدرێن.' ?>
            </p>
        </div>

        <?php if ($isPaymentsHome): ?>
            <div class="grid grid-cols-2 gap-3 sm:gap-5" dir="rtl">
                <a href="payments.php?type=in" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-1 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-300 sm:p-6">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-xl text-emerald-700 sm:h-14 sm:w-14 sm:text-2xl">
                        <i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i>
                    </span>
                    <h3 class="mt-4 break-words text-base font-bold text-slate-800 sm:text-lg">وەرگرتنی پارە</h3>
                    <p class="mt-1 break-words text-xs text-slate-500 sm:text-sm">بینین و پشکنینی تۆمارەکانی پارەی وەرگیراو.</p>
                </a>
                <a href="payments.php?type=out" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-1 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-rose-300 sm:p-6">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-rose-100 text-xl text-rose-700 sm:h-14 sm:w-14 sm:text-2xl">
                        <i class="fa-solid fa-money-bill-transfer" aria-hidden="true"></i>
                    </span>
                    <h3 class="mt-4 break-words text-base font-bold text-slate-800 sm:text-lg">دانەوەی پارە</h3>
                    <p class="mt-1 break-words text-xs text-slate-500 sm:text-sm">بینین و پشکنینی تۆمارەکانی پارەی دراو.</p>
                </a>
            </div>
        <?php else: ?>
            <nav class="mb-5 flex flex-wrap gap-3" aria-label="جۆری پارەدان">
                <a href="payments.php?type=in" class="rounded-xl px-4 py-2.5 font-semibold transition <?= $transactionType === 'in' ? 'bg-emerald-600 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?>">وەرگرتنی پارە</a>
                <a href="payments.php?type=out" class="rounded-xl px-4 py-2.5 font-semibold transition <?= $transactionType === 'out' ? 'bg-rose-600 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?>">دانەوەی پارە</a>
            </nav>

            <p class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                تەنها تۆمارە هەبووەکان پیشان دەدرێن؛ خشتەی payments ستوونی دراو یان پەیوەندیی فاکتووری تێدا نییە، بۆیە بڕەکان بەبێ دانانی دراوی خەمڵێنراو پیشان دەدرێن.
            </p>

            <?php if ($errorMessage !== ''): ?>
                <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800" role="alert">
                    <?= payments_escape($errorMessage) ?>
                </div>
            <?php else: ?>
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">ژمارە</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">جۆری لایەن</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">ناوی لایەن</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بڕ</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">شێوازی پارەدان</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">تۆمارکەر</th>
                                    <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بەروار</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if ($payments): ?>
                                    <?php foreach ($payments as $payment): ?>
                                        <?php
                                        $paymentMethod = $payment['payment_method'];
                                        $paymentMethodLabel = $paymentMethod === null
                                            ? 'نادیار'
                                            : ($methodLabels[$paymentMethod] ?? (string) $paymentMethod);
                                        ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-500"><?= (int) $payment['payment_id'] ?></td>
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= $payment['party_type'] === 'customer' ? 'کڕیار' : 'دابینکەر' ?></td>
                                            <td class="px-4 py-3 font-medium text-slate-800">
                                                <?= $payment['party_name'] === null
                                                    ? payments_escape(($payment['party_type'] === 'customer' ? 'کڕیاری' : 'دابینکەری') . ' #' . (string) $payment['party_id'] . ' (نەدۆزرایەوە)')
                                                    : payments_escape((string) $payment['party_name']) ?>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= number_format((float) $payment['amount'], 2) ?></td>
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= payments_escape((string) $paymentMethodLabel) ?></td>
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= payments_escape((string) ($payment['user_name'] ?? '—')) ?></td>
                                            <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= payments_escape((string) ($payment['payment_date'] ?? '—')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">هیچ تۆمارێکی ئەم جۆرە پارەدانە نییە.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($payments) === 200): ?>
                        <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">تەنها دوا ٢٠٠ تۆمار پیشان دراون.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
