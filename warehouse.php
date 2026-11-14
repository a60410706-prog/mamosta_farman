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

function warehouse_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function warehouse_is_valid_expiry_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
        return false;
    }

    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $dateErrors = DateTimeImmutable::getLastErrors();

    return $parsedDate !== false
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $parsedDate->format('Y-m-d') === $date;
}

$returnToWarehouseSuccess = $_SESSION['warehouse_success'] ?? $_SESSION['product_image_success'] ?? '';
unset($_SESSION['warehouse_success'], $_SESSION['product_image_success']);

if (empty($_SESSION['warehouse_csrf_token'])) {
    $_SESSION['warehouse_csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = 'کۆگا';
$products = [];
$availableProducts = [];
$batchRecords = [];
$errorMessage = '';
$loadError = false;
$batchFormOpen = false;
$batchFormValues = [
    'product_id' => '',
    'quantity' => '',
    'purchase_price' => '',
    'expiry_date' => '',
];

$requestedProductId = filter_var($_GET['add_batch'] ?? null, FILTER_VALIDATE_INT);
if ($requestedProductId !== false && $requestedProductId !== null && $requestedProductId > 0) {
    $batchFormValues['product_id'] = (string) $requestedProductId;
    $batchFormOpen = true;
}

$requestedBatchId = filter_var($_GET['edit_batch'] ?? null, FILTER_VALIDATE_INT);
$editingBatchId = $requestedBatchId !== false && $requestedBatchId !== null && $requestedBatchId > 0
    ? $requestedBatchId
    : 0;
if ($editingBatchId > 0) {
    try {
        $editingBatchStmt = $pdo->prepare(
            'SELECT batch_id, product_id, quantity, purchase_price, expiry_date
             FROM product_batches WHERE batch_id = :batch_id'
        );
        $editingBatchStmt->execute(['batch_id' => $editingBatchId]);
        $editingBatch = $editingBatchStmt->fetch(PDO::FETCH_ASSOC);
        if ($editingBatch) {
            $batchFormValues = [
                'product_id' => (string) $editingBatch['product_id'],
                'quantity' => (string) $editingBatch['quantity'],
                'purchase_price' => (string) $editingBatch['purchase_price'],
                'expiry_date' => (string) ($editingBatch['expiry_date'] ?? ''),
            ];
            $batchFormOpen = true;
        } else {
            $errorMessage = 'باچی داواکراو نەدۆزرایەوە.';
        }
    } catch (PDOException $exception) {
        error_log('Loading warehouse batch failed: ' . $exception->getMessage());
        $errorMessage = 'هەڵەیەک لە هێنانی زانیاریی باچدا ڕوویدا.';
    }
}

$postedAction = $_POST['action'] ?? null;
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && is_string($postedAction)
    && in_array($postedAction, ['add_batch', 'update_batch'], true)
) {
    $batchFormOpen = true;
    if ($postedAction === 'update_batch') {
        $postedBatchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        $editingBatchId = $postedBatchId !== false && $postedBatchId !== null && $postedBatchId > 0
            ? $postedBatchId
            : 0;
    }
    foreach ($batchFormValues as $key => $default) {
        $postedValue = $_POST[$key] ?? null;
        if (is_string($postedValue)) {
            $batchFormValues[$key] = trim($postedValue);
        }
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['warehouse_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. تکایە دووبارە هەوڵ بدەوە.';
    } else {
        $productId = filter_var($batchFormValues['product_id'], FILTER_VALIDATE_INT);
        $quantityInput = $batchFormValues['quantity'];
        $quantity = $quantityInput === '' ? 0 : filter_var($quantityInput, FILTER_VALIDATE_INT);
        $purchasePrice = $batchFormValues['purchase_price'];
        $expiryDate = $batchFormValues['expiry_date'];

        if ($postedAction === 'update_batch' && $editingBatchId < 1) {
            $errorMessage = 'ژمارەی باچ دروست نییە.';
        } elseif ($productId === false || $productId < 1) {
            $errorMessage = 'تکایە کاڵایەکی دروست هەڵبژێرە.';
        } elseif (
            ($quantityInput !== '' && !preg_match('/^\d+$/D', $quantityInput))
            || $quantity === false
            || $quantity < 0
            || $quantity > 2147483647
        ) {
            $errorMessage = 'بڕی باچ دەبێت ژمارەیەکی دروست و سفر یان زیاتر بێت.';
        } elseif (
            !preg_match('/^\d+(?:\.\d{1,2})?$/D', $purchasePrice)
            || !is_finite((float) $purchasePrice)
            || (float) $purchasePrice > 99999999.99
        ) {
            $errorMessage = 'نرخی کڕینی یەک دانە دروست نییە.';
        } elseif ($expiryDate !== '' && !warehouse_is_valid_expiry_date($expiryDate)) {
            $errorMessage = 'بەرواری بەسەرچوون دروست نییە.';
        } else {
            try {
                $pdo->beginTransaction();

                $batch = null;
                if ($postedAction === 'update_batch') {
                    $batchStmt = $pdo->prepare(
                        'SELECT batch_id, product_id, quantity
                         FROM product_batches WHERE batch_id = :batch_id FOR UPDATE'
                    );
                    $batchStmt->execute(['batch_id' => $editingBatchId]);
                    $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$batch || (int) $batch['product_id'] !== $productId) {
                        throw new RuntimeException('باچی هەڵبژێردراو بۆ ئەم کاڵایە نییە.');
                    }
                }

                $productStmt = $pdo->prepare(
                    'SELECT product_id, stock_quantity
                     FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $productStmt->execute(['product_id' => $productId]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    throw new RuntimeException('کاڵای هەڵبژێردراو نەدۆزرایەوە.');
                }

                $stockDelta = $postedAction === 'update_batch' ? 0 : $quantity;

                if ($postedAction === 'update_batch' && $quantity === 0) {
                    $batchDeleteStmt = $pdo->prepare(
                        'DELETE FROM product_batches WHERE batch_id = :batch_id'
                    );
                    $batchDeleteStmt->execute(['batch_id' => $editingBatchId]);
                } elseif ($postedAction === 'update_batch') {
                    $batchUpdateStmt = $pdo->prepare(
                        'UPDATE product_batches
                         SET quantity = :quantity, purchase_price = :purchase_price, expiry_date = :expiry_date
                         WHERE batch_id = :batch_id'
                    );
                    $batchUpdateStmt->execute([
                        'quantity' => $quantity,
                        'purchase_price' => (float) $purchasePrice,
                        'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                        'batch_id' => $editingBatchId,
                    ]);
                } elseif ($quantity > 0) {
                    $batchInsertStmt = $pdo->prepare(
                        'INSERT INTO product_batches (product_id, quantity, purchase_price, expiry_date)
                         VALUES (:product_id, :quantity, :purchase_price, :expiry_date)'
                    );
                    $batchInsertStmt->execute([
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'purchase_price' => (float) $purchasePrice,
                        'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                    ]);
                }

                if ($stockDelta !== 0) {
                    $stockStmt = $pdo->prepare(
                        'UPDATE products
                         SET stock_quantity = COALESCE(stock_quantity, 0) + :quantity
                         WHERE product_id = :product_id'
                    );
                    $stockStmt->execute([
                        'quantity' => $stockDelta,
                        'product_id' => $productId,
                    ]);
                }

                $pdo->commit();
                if ($postedAction === 'update_batch' && $quantity === 0) {
                    $_SESSION['warehouse_success'] = 'باچی سفر بڕ سڕایەوە؛ بڕی کۆگای گشتی نەگۆڕا.';
                } elseif ($postedAction === 'update_batch') {
                    $_SESSION['warehouse_success'] = 'زانیاریی باچ نوێ کرایەوە؛ بڕی کۆگای گشتی نەگۆڕا.';
                } elseif ($quantity === 0) {
                    $_SESSION['warehouse_success'] = 'بڕی باچ سفر بوو؛ هیچ باچێک تۆمار نەکرا و کۆگا نەگۆڕا.';
                } else {
                    $_SESSION['warehouse_success'] = 'باچەکە تۆمار کرا و بڕی کاڵا بۆ کۆگا زیاد کرا.';
                }
                header('Location: warehouse.php');
                exit;
            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMessage = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Adding warehouse batch failed: ' . $exception->getMessage());
                http_response_code(500);
                $errorMessage = 'باچەکە تۆمار نەکرا. تکایە دووبارە هەوڵ بدەوە.';
            }
        }
    }
}

try {
    $availableProducts = $pdo->query(
        'SELECT product_id, product_name FROM products ORDER BY product_name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $batchRecords = $pdo->query(
        'SELECT pb.batch_id, pb.product_id, p.product_name, pb.quantity,
                pb.purchase_price, pb.expiry_date
         FROM product_batches AS pb
         INNER JOIN products AS p ON p.product_id = pb.product_id
         WHERE pb.quantity > 0
         ORDER BY p.product_name, pb.expiry_date, pb.batch_id
         LIMIT 500'
    )->fetchAll(PDO::FETCH_ASSOC);
    $batchesByProduct = [];
    foreach ($batchRecords as $batch) {
        $batchesByProduct[(int) $batch['product_id']][] = $batch;
    }
    $products = $pdo->query(
        'SELECT p.product_id, p.product_name, p.barcode, p.stock_quantity,
                p.damaged_quantity, p.alert_limit, p.expiry_date,
                c.category_name,
                COALESCE(SUM(pb.quantity), 0) AS batch_quantity,
                MIN(pb.expiry_date) AS earliest_batch_expiry
         FROM products AS p
         LEFT JOIN categories AS c ON c.category_id = p.category_id
         LEFT JOIN product_batches AS pb ON pb.product_id = p.product_id AND pb.quantity > 0
         GROUP BY p.product_id, p.product_name, p.barcode, p.stock_quantity,
                  p.damaged_quantity, p.alert_limit, p.expiry_date, c.category_name
         ORDER BY p.product_name ASC
         LIMIT 500'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading warehouse inventory failed: ' . $exception->getMessage());
    http_response_code(500);
    $loadError = true;
    if ($errorMessage === '') {
        $errorMessage = 'هەڵەیەک لە هێنانی زانیارییەکانی کۆگادا ڕوویدا.';
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">کۆگا</h2>
                <p class="mt-1 text-sm text-slate-500">بڕی کاڵا، باچ و بەرواری بەسەرچوون لە داتابەیسی ئێستا.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="products.php?add=1&amp;return_to=warehouse.php" class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-amber-400">
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                    زیادکردنی کاڵای نوێ
                </a>
                <button id="toggleBatchForm" type="button" aria-expanded="<?= $batchFormOpen ? 'true' : 'false' ?>" aria-controls="batchFormPanel"
                        class="inline-flex items-center gap-2 rounded-xl bg-cyan-700 px-4 py-2.5 font-semibold text-white transition hover:bg-cyan-800">
                    <i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i>
                    زیادکردنی باچ
                </button>
                <a href="products.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-semibold text-slate-700 transition hover:bg-slate-50">
                    بەڕێوەبردنی کاڵاکان
                </a>
            </div>
        </div>

        <?php if ($returnToWarehouseSuccess !== ''): ?>
            <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800" role="status">
                <?= warehouse_escape((string) $returnToWarehouseSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800" role="alert">
                <?= warehouse_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section id="batchFormPanel" <?= $batchFormOpen ? '' : 'hidden' ?> class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-5">
                <h3 class="text-lg font-bold text-slate-800"><?= $editingBatchId > 0 ? 'دەستکاریکردنی باچ' : 'تۆمارکردنی باچی نوێ' ?></h3>
                <p class="mt-1 text-sm text-slate-500">
                    <?= $editingBatchId > 0
                        ? 'دەستکاریکردنی ژمارەی باچ و بەروار، بڕی کۆگای گشتی ناگۆڕێت؛ بۆ زیادکردنی کاڵا بۆ کۆگا باچی نوێ تۆمار بکە.'
                        : 'بڕی باچ بۆ کۆی بڕی کۆگا زیاد دەکرێت؛ بەتاڵی بە سفر تۆمار دەکرێت. نرخی کڕین پێویستە و بەرواری بەسەرچوون ئارەزوومەندانەیە.' ?>
                </p>
            </div>
            <?php if (!$availableProducts): ?>
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">
                    پێش تۆمارکردنی باچ، سەرەتا کاڵایەک زیاد بکە.
                </p>
            <?php else: ?>
                <form method="post" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <input type="hidden" name="csrf_token" value="<?= warehouse_escape($_SESSION['warehouse_csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $editingBatchId > 0 ? 'update_batch' : 'add_batch' ?>">
                    <?php if ($editingBatchId > 0): ?>
                        <input type="hidden" name="batch_id" value="<?= (int) $editingBatchId ?>">
                    <?php endif; ?>
                    <div>
                        <label for="batchProductId" class="mb-1 block text-sm font-semibold text-slate-700">کاڵا</label>
                        <select id="batchProductId" name="product_id" required <?= $editingBatchId > 0 ? 'disabled' : '' ?> class="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-slate-800 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                            <option value="">کاڵا هەڵبژێرە</option>
                            <?php foreach ($availableProducts as $productOption): ?>
                                <option value="<?= (int) $productOption['product_id'] ?>" <?= $batchFormValues['product_id'] === (string) $productOption['product_id'] ? 'selected' : '' ?>>
                                    <?= warehouse_escape((string) $productOption['product_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editingBatchId > 0): ?>
                            <input type="hidden" name="product_id" value="<?= warehouse_escape($batchFormValues['product_id']) ?>">
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="batchQuantity" class="mb-1 block text-sm font-semibold text-slate-700">بڕی باچ <span class="font-normal text-slate-500">(ئارەزوومەندانە)</span></label>
                        <input id="batchQuantity" name="quantity" type="number" min="0" max="2147483647" step="1" value="<?= warehouse_escape($batchFormValues['quantity']) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-800 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                    </div>
                    <div>
                        <label for="batchPurchasePrice" class="mb-1 block text-sm font-semibold text-slate-700">نرخی کڕینی یەک دانە</label>
                        <input id="batchPurchasePrice" name="purchase_price" type="number" min="0" max="99999999.99" step="0.01" required value="<?= warehouse_escape($batchFormValues['purchase_price']) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-800 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                    </div>
                    <div>
                        <label for="batchExpiryDate" class="mb-1 block text-sm font-semibold text-slate-700">بەرواری بەسەرچوون <span class="font-normal text-slate-500">(ئارەزوومەندانە)</span></label>
                        <input id="batchExpiryDate" name="expiry_date" type="date" value="<?= warehouse_escape($batchFormValues['expiry_date']) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-800 focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                    </div>
                    <div class="flex flex-wrap gap-3 pt-1 md:col-span-2">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-cyan-700 px-5 py-3 font-semibold text-white transition hover:bg-cyan-800">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                            <?= $editingBatchId > 0 ? 'پاشەکەوتکردنی گۆڕانکاری' : 'تۆمارکردنی باچ' ?>
                        </button>
                        <button id="cancelBatchForm" type="button" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-50">
                            داخستن
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <?php if (!$loadError): ?>
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">کاڵا</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بارکۆد</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">پۆلێن</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بڕی کۆگا</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بڕی باچ</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">زیان‌پێگەیشتوو</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">ئاگادارکردنەوە لە</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">نزیکترین بەسەرچوون</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">کردار</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($products): ?>
                                <?php foreach ($products as $product): ?>
                                    <?php $expiryDate = $product['earliest_batch_expiry'] ?? $product['expiry_date']; ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-800"><?= warehouse_escape((string) $product['product_name']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= warehouse_escape((string) ($product['barcode'] ?? '—')) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= warehouse_escape((string) ($product['category_name'] ?? '—')) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= number_format((int) $product['stock_quantity']) ?></td>
                                        <td class="px-4 py-3 text-slate-700">
                                            <div class="space-y-2">
                                                <span class="whitespace-nowrap font-medium"><?= number_format((int) $product['batch_quantity']) ?></span>
                                                <?php $productBatches = $batchesByProduct[(int) $product['product_id']] ?? []; ?>
                                                <?php if ($productBatches): ?>
                                                    <div class="mt-2 min-w-52 space-y-2">
                                                        <?php foreach ($productBatches as $batch): ?>
                                                            <div class="rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
                                                                <p class="font-semibold text-slate-700">
                                                                    باچی <?= (int) $batch['batch_id'] ?> · بڕ: <?= number_format((int) $batch['quantity']) ?>
                                                                </p>
                                                                <p class="mt-1 text-slate-600">
                                                                    نرخی دانە: <?= number_format((float) $batch['purchase_price'], 2) ?>
                                                                    · بەسەرچوون: <?= warehouse_escape((string) ($batch['expiry_date'] ?? '—')) ?>
                                                                </p>
                                                                <a href="warehouse.php?edit_batch=<?= (int) $batch['batch_id'] ?>#batchFormPanel" class="mt-1 inline-block font-semibold text-cyan-800 hover:text-cyan-950">
                                                                    دەستکاریی باچ
                                                                </a>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= number_format((int) $product['damaged_quantity']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= number_format((int) $product['alert_limit']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= warehouse_escape((string) ($expiryDate ?? '—')) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <div class="flex items-center gap-2">
                                                <a href="products.php?edit=<?= (int) $product['product_id'] ?>&amp;return_to=warehouse.php" class="rounded-lg bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800 transition hover:bg-amber-200">
                                                    دەستکاری
                                                </a>
                                                <a href="warehouse.php?add_batch=<?= (int) $product['product_id'] ?>#batchFormPanel" class="rounded-lg bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800 transition hover:bg-cyan-100">
                                                    زیادکردنی باچ
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">هیچ کاڵایەک لە داتابەیس تۆمار نەکراوە.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($products) === 500): ?>
                    <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">تەنها ٥٠٠ کاڵای یەکەم پیشان دراون.</p>
                <?php endif; ?>
            </div>
            <?php if (count($batchRecords) === 500): ?>
                <p class="mt-2 text-xs text-slate-500">تەنها ٥٠٠ باچی یەکەم لە وردەکارییەکاندا پیشان دراون.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script>
(() => {
    const formPanel = document.getElementById('batchFormPanel');
    const toggleButton = document.getElementById('toggleBatchForm');
    const cancelButton = document.getElementById('cancelBatchForm');

    if (!formPanel || !toggleButton) {
        return;
    }

    const setFormOpen = (open) => {
        formPanel.hidden = !open;
        toggleButton.setAttribute('aria-expanded', String(open));
        if (open) {
            formPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    };

    toggleButton.addEventListener('click', () => setFormOpen(formPanel.hidden));
    if (cancelButton) {
        cancelButton.addEventListener('click', () => setFormOpen(false));
    }
})();
</script>
</body>
</html>
