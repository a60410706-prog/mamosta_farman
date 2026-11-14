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

function stock_alert_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'ئاگادارکردنەوەکانی کۆگا';
$alerts = [];
$errorMessage = '';

try {
    $alerts = $pdo->query(
        'SELECT product_id, product_name, barcode, category_name, stock_quantity, alert_limit
         FROM view_low_stock_alerts
         ORDER BY stock_quantity ASC, product_name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading stock alerts failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی ئاگادارکردنەوەکانی کۆگا ڕوویدا.';
}

$outOfStockCount = 0;
foreach ($alerts as $alert) {
    if ((float) $alert['stock_quantity'] <= 0) {
        $outOfStockCount++;
    }
}
$lowStockCount = count($alerts) - $outOfStockCount;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">ئاگادارکردنەوەکانی کۆگا</h2>
            <p class="mt-1 text-sm text-slate-500">کاڵاکانی نزیک لە تەواوبوون یان تەواوبووی کۆگا لێرە نیشان دەدرێن.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= stock_alert_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-amber-800">کاڵای کەم‌کۆگا</p>
                        <p class="mt-2 text-3xl font-extrabold text-amber-950"><?= number_format($lowStockCount) ?></p>
                    </div>
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                        <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                    </span>
                </div>
            </section>
            <section class="rounded-2xl border border-red-200 bg-red-50 p-5">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-red-800">کاڵای تەواوبوو</p>
                        <p class="mt-2 text-3xl font-extrabold text-red-950"><?= number_format($outOfStockCount) ?></p>
                    </div>
                    <span class="flex h-12 w-12 items-center justify-center rounded-xl bg-red-100 text-red-700">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    </span>
                </div>
            </section>
        </div>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="font-bold text-slate-800">کاڵا پێویستە پشکنین بکرێت</h3>
                <p class="text-sm text-slate-500">کۆی ئاگادارکردنەوەکان: <?= number_format(count($alerts)) ?></p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-right text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">کاڵا</th>
                            <th scope="col" class="px-5 py-3 font-semibold">بارکۆد</th>
                            <th scope="col" class="px-5 py-3 font-semibold">پۆل</th>
                            <th scope="col" class="px-5 py-3 font-semibold">بڕی کۆگا</th>
                            <th scope="col" class="px-5 py-3 font-semibold">سنووری ئاگاداری</th>
                            <th scope="col" class="px-5 py-3 font-semibold">دۆخ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($alerts as $alert): ?>
                            <?php $isOutOfStock = (float) $alert['stock_quantity'] <= 0; ?>
                            <tr class="hover:bg-slate-50/70">
                                <td class="px-5 py-4 font-semibold text-slate-800"><?= stock_alert_escape((string) $alert['product_name']) ?></td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= stock_alert_escape((string) ($alert['barcode'] ?? '—')) ?></td>
                                <td class="px-5 py-4 text-slate-600"><?= stock_alert_escape((string) ($alert['category_name'] ?? 'بێ پۆل')) ?></td>
                                <td class="px-5 py-4 font-bold <?= $isOutOfStock ? 'text-red-700' : 'text-amber-700' ?>">
                                    <?= number_format((float) $alert['stock_quantity']) ?>
                                </td>
                                <td class="px-5 py-4 text-slate-600"><?= number_format((float) $alert['alert_limit']) ?></td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold <?= $isOutOfStock ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700' ?>">
                                        <?= $isOutOfStock ? 'کۆگا تەواو بووە' : 'کۆگا کەمە' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$alerts && $errorMessage === ''): ?>
                            <tr>
                                <td colspan="6" class="px-5 py-14 text-center">
                                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    </div>
                                    <p class="mt-3 font-semibold text-slate-700">هیچ ئاگادارکردنەوەیەکی کۆگا نییە.</p>
                                    <p class="mt-1 text-sm text-slate-500">هەموو کاڵاکان لە سنووری کۆگای دیاریکراودان.</p>
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
