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

function purchase_history_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'مێژووی کڕینەکان';
$purchases = [];
$errorMessage = '';

try {
    $purchases = $pdo->query(
        'SELECT
            p.purchase_id,
            p.purchase_date,
            p.total_amount,
            p.paid_amount,
            p.remaining_amount,
            p.payment_status,
            s.supplier_name,
            COUNT(pi.p_item_id) AS item_count
         FROM purchases AS p
         LEFT JOIN suppliers AS s ON s.supplier_id = p.supplier_id
         LEFT JOIN purchase_items AS pi ON pi.purchase_id = p.purchase_id
         GROUP BY
            p.purchase_id,
            p.purchase_date,
            p.total_amount,
            p.paid_amount,
            p.remaining_amount,
            p.payment_status,
            s.supplier_name
         ORDER BY p.purchase_date DESC, p.purchase_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading purchase history failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی مێژووی کڕینەکان ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">مێژووی کڕینەکان</h2>
                <p class="mt-1 text-sm text-slate-500">فاکتوورەکانی پێشوو و دۆخی پارەدانیان ببینە.</p>
            </div>
            <a href="purchases.php" class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-amber-400">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                تۆمارکردنی کڕینی نوێ
            </a>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= purchase_history_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="font-bold text-slate-800">فاکتوورەکان</h3>
                <p class="text-sm text-slate-500">کۆی فاکتوورەکان: <?= number_format(count($purchases)) ?></p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-right text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">ژمارەی فاکتوور</th>
                            <th scope="col" class="px-5 py-3 font-semibold">بەروار</th>
                            <th scope="col" class="px-5 py-3 font-semibold">دابینکەر</th>
                            <th scope="col" class="px-5 py-3 font-semibold">ژمارەی کاڵا</th>
                            <th scope="col" class="px-5 py-3 font-semibold">کۆی گشتی</th>
                            <th scope="col" class="px-5 py-3 font-semibold">پارەدراو</th>
                            <th scope="col" class="px-5 py-3 font-semibold">ماوە</th>
                            <th scope="col" class="px-5 py-3 font-semibold">دۆخی پارەدان</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($purchases as $purchase): ?>
                            <?php
                            $paymentStatus = (string) ($purchase['payment_status'] ?? '');
                            $statusLabel = [
                                'paid' => 'پارەدراو',
                                'partial' => 'بەشێک پارەدراو',
                                'unpaid' => 'نەپارەدراو',
                            ][$paymentStatus] ?? 'نادیار';
                            $statusClass = match ($paymentStatus) {
                                'paid' => 'bg-emerald-50 text-emerald-700',
                                'partial' => 'bg-amber-50 text-amber-700',
                                'unpaid' => 'bg-red-50 text-red-700',
                                default => 'bg-slate-100 text-slate-600',
                            };
                            ?>
                            <tr class="hover:bg-slate-50/70">
                                <td class="whitespace-nowrap px-5 py-4 font-bold text-slate-800">#<?= (int) $purchase['purchase_id'] ?></td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= purchase_history_escape((string) $purchase['purchase_date']) ?></td>
                                <td class="px-5 py-4 font-medium text-slate-700"><?= purchase_history_escape((string) ($purchase['supplier_name'] ?? 'دابینکەری سڕاوە')) ?></td>
                                <td class="px-5 py-4 text-slate-600"><?= number_format((int) $purchase['item_count']) ?></td>
                                <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-800">$<?= number_format((float) $purchase['total_amount'], 2) ?></td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600">$<?= number_format((float) $purchase['paid_amount'], 2) ?></td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600">$<?= number_format((float) $purchase['remaining_amount'], 2) ?></td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass ?>">
                                        <?= purchase_history_escape($statusLabel) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$purchases && $errorMessage === ''): ?>
                            <tr>
                                <td colspan="8" class="px-5 py-14 text-center">
                                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                                        <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                                    </div>
                                    <p class="mt-3 font-semibold text-slate-700">هێشتا هیچ فاکتوورێکی کڕین تۆمار نەکراوە.</p>
                                    <p class="mt-1 text-sm text-slate-500">لە پەڕەی فاکتوورەکانەوە یەکەم کڕین تۆمار بکە.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
</body>
</html>
