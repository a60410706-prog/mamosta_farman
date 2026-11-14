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

function damaged_goods_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'کاڵای زیان‌پێگەیشتوو';
$products = [];
$errorMessage = '';

try {
    $products = $pdo->query(
        'SELECT product_id, product_name, barcode, stock_quantity, damaged_quantity
         FROM products
         WHERE damaged_quantity > 0
         ORDER BY damaged_quantity DESC, product_name ASC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading damaged goods failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە هێنانی زانیارییەکانی کاڵای زیان‌پێگەیشتوودا ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-6xl">
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">کاڵای زیان‌پێگەیشتوو</h2>
                <p class="mt-1 text-sm text-slate-500">کاڵاکانی بڕی زیانیان لە داتابەیس تۆمار کراوە.</p>
            </div>
            <a href="products.php" class="inline-flex items-center gap-2 rounded-xl bg-orange-600 px-4 py-2.5 font-semibold text-white transition hover:bg-orange-700">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                تۆمارکردنی زیانی نوێ
            </a>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800" role="alert">
                <?= damaged_goods_escape($errorMessage) ?>
            </div>
        <?php else: ?>
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">کاڵا</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بارکۆد</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بڕی کۆگا</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بڕی زیان‌پێگەیشتوو</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($products): ?>
                                <?php foreach ($products as $product): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800"><?= damaged_goods_escape((string) $product['product_name']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= damaged_goods_escape((string) ($product['barcode'] ?? '—')) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= number_format((int) $product['stock_quantity']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 font-semibold text-orange-700"><?= number_format((int) $product['damaged_quantity']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">هیچ کاڵای زیان‌پێگەیشتوویەک تۆمار نەکراوە.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($products) === 200): ?>
                    <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">تەنها دوا ٢٠٠ تۆمار پیشان دراون.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
