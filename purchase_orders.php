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

if (empty($_SESSION['purchase_orders_csrf_token'])) {
    $_SESSION['purchase_orders_csrf_token'] = bin2hex(random_bytes(32));
}

function purchase_order_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'داواکارییەکانی کڕین';
$errorMessage = '';
$successMessage = $_SESSION['purchase_order_success'] ?? '';
unset($_SESSION['purchase_order_success']);
$suppliers = [];
$lowStockProducts = [];
$orders = [];
$formSupplierId = '';
$formNotes = '';
$selectedProductIds = [];
$formQuantities = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $supplierInput = $_POST['supplier_id'] ?? '';
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $notesInput = $_POST['notes'] ?? '';
    $formSupplierId = is_string($supplierInput) ? $supplierInput : '';
    $formNotes = is_string($notesInput) ? trim($notesInput) : '';
    $selectedProductIds = is_array($productIds) ? $productIds : [];
    $formQuantities = is_array($quantities) ? $quantities : [];

    if (!is_string($token) || !hash_equals($_SESSION['purchase_orders_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif (!is_array($productIds) || !is_array($quantities)) {
        $errorMessage = 'زانیاریی کاڵاکان و بڕی داواکراو دروست نییە.';
    } elseif (mb_strlen($formNotes, 'UTF-8') > 255) {
        $errorMessage = 'تێبینی نابێت لە ٢٥٥ پیت زیاتر بێت.';
    } else {
        $supplierId = filter_var($formSupplierId, FILTER_VALIDATE_INT);
        $items = [];
        $inputValid = true;
        foreach ($productIds as $index => $rawProductId) {
            $productId = filter_var($rawProductId, FILTER_VALIDATE_INT);
            $quantity = filter_var($quantities[$productId] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || $productId < 1 || !$quantity || $quantity < 1 || isset($items[$productId])) {
                $inputValid = false;
                break;
            }
            $items[$productId] = $quantity;
        }

        if (!$supplierId || $supplierId < 1) {
            $errorMessage = 'تکایە دابینکەرێک هەڵبژێرە.';
        } elseif (!$items || !$inputValid) {
            $errorMessage = 'لانیکەم یەک کاڵای دروست و بڕی داواکراو هەڵبژێرە.';
        } else {
            try {
                $pdo->beginTransaction();
                $supplierStmt = $pdo->prepare(
                    'SELECT supplier_id FROM suppliers WHERE supplier_id = :supplier_id'
                );
                $supplierStmt->execute(['supplier_id' => $supplierId]);
                if ($supplierStmt->fetchColumn() === false) {
                    throw new RuntimeException('دابینکەری هەڵبژێردراو نەدۆزرایەوە.');
                }

                $productStmt = $pdo->prepare(
                    'SELECT product_id, stock_quantity, alert_limit
                     FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $orderStmt = $pdo->prepare(
                    'INSERT INTO purchase_orders
                        (supplier_id, product_id, requested_quantity, status, notes, user_id)
                     VALUES
                        (:supplier_id, :product_id, :requested_quantity, :status, :notes, :user_id)'
                );
                foreach ($items as $productId => $quantity) {
                    $productStmt->execute(['product_id' => $productId]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$product) {
                        throw new RuntimeException('کاڵایەکی هەڵبژێردراو نەدۆزرایەوە.');
                    }
                    if ((int) $product['stock_quantity'] > (int) $product['alert_limit']) {
                        throw new RuntimeException('تەنها کاڵای کەم‌کۆگا دەتوانرێت بۆ داواکاری هەڵبژێردرێت.');
                    }

                    $orderStmt->execute([
                        'supplier_id' => $supplierId,
                        'product_id' => $productId,
                        'requested_quantity' => $quantity,
                        'status' => 'pending',
                        'notes' => $formNotes !== '' ? $formNotes : null,
                        'user_id' => (int) $_SESSION['user_id'],
                    ]);
                }

                $pdo->commit();
                $_SESSION['purchase_order_success'] = 'داواکاریی کڕین بە سەرکەوتوویی تۆمار کرا.';
                header('Location: purchase_orders.php');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof PDOException) {
                    error_log('Creating purchase orders failed: ' . $exception->getMessage());
                    $errorMessage = 'هەڵەیەک لە تۆمارکردنی داواکاریی کڕین ڕوویدا.';
                } else {
                    $errorMessage = $exception->getMessage();
                }
            }
        }
    }
}

try {
    $suppliers = $pdo->query(
        'SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $lowStockProducts = $pdo->query(
        'SELECT product_id, product_name, stock_quantity, alert_limit
         FROM products
         WHERE stock_quantity <= alert_limit
         ORDER BY stock_quantity ASC, product_name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $orders = $pdo->query(
        'SELECT
            po.order_id,
            po.requested_quantity,
            po.status,
            po.notes,
            po.order_date,
            s.supplier_name,
            p.product_name,
            u.full_name AS created_by
         FROM purchase_orders AS po
         INNER JOIN suppliers AS s ON s.supplier_id = po.supplier_id
         INNER JOIN products AS p ON p.product_id = po.product_id
         LEFT JOIN users AS u ON u.user_id = po.user_id
         ORDER BY po.order_date DESC, po.order_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading purchase orders page failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی زانیاریی داواکارییەکاندا ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">داواکارییەکانی کڕین</h2>
            <p class="mt-1 text-sm text-slate-500">بۆ کاڵا کەم‌کۆگاکان داواکاری بۆ دابینکەر دروست بکە و بەدواداچوونیان بکە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= purchase_order_escape((string) $successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= purchase_order_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-5 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h3 class="text-lg font-bold text-slate-800">دروستکردنی داواکاری</h3>
                    <p class="mt-1 text-sm text-slate-500">تەنها کاڵاکانی لە سنووری ئاگادارکردنەوە یان کەمتر نیشان دەدرێن.</p>
                </div>
                <span class="text-sm font-semibold text-amber-700"><?= number_format(count($lowStockProducts)) ?> کاڵای کەم‌کۆگا</span>
            </div>

            <form method="post" action="purchase_orders.php" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= purchase_order_escape($_SESSION['purchase_orders_csrf_token']) ?>">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="supplier_id" class="mb-2 block text-sm font-semibold text-slate-700">دابینکەر</label>
                        <select id="supplier_id" name="supplier_id" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                            <option value="">دابینکەر هەڵبژێرە</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= (int) $supplier['supplier_id'] ?>" <?= $formSupplierId === (string) $supplier['supplier_id'] ? 'selected' : '' ?>>
                                    <?= purchase_order_escape((string) $supplier['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="notes" class="mb-2 block text-sm font-semibold text-slate-700">تێبینی (ئارەزوومەندانە)</label>
                        <input id="notes" name="notes" type="text" maxlength="255" value="<?= purchase_order_escape($formNotes) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full min-w-[650px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-semibold">هەڵبژاردن</th>
                                <th scope="col" class="px-4 py-3 font-semibold">کاڵا</th>
                                <th scope="col" class="px-4 py-3 font-semibold">کۆگا</th>
                                <th scope="col" class="px-4 py-3 font-semibold">سنووری ئاگاداری</th>
                                <th scope="col" class="px-4 py-3 font-semibold">بڕی داواکراو</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($lowStockProducts as $product): ?>
                                <?php
                                $productId = (int) $product['product_id'];
                                $suggestedQuantity = max(1, (int) $product['alert_limit'] - (int) $product['stock_quantity']);
                                $wasSelected = in_array((string) $productId, $selectedProductIds, true);
                                $quantityInput = $formQuantities[$productId] ?? $suggestedQuantity;
                                $quantityValue = is_scalar($quantityInput) ? (string) $quantityInput : (string) $suggestedQuantity;
                                ?>
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-4 py-3">
                                        <input type="checkbox" name="product_id[]" value="<?= $productId ?>" <?= $wasSelected ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                    </td>
                                    <td class="px-4 py-3 font-semibold text-slate-800"><?= purchase_order_escape((string) $product['product_name']) ?></td>
                                    <td class="px-4 py-3 font-bold text-red-700"><?= number_format((int) $product['stock_quantity']) ?></td>
                                    <td class="px-4 py-3 text-slate-600"><?= number_format((int) $product['alert_limit']) ?></td>
                                    <td class="px-4 py-3">
                                        <label class="sr-only" for="quantity-<?= $productId ?>">بڕی داواکراو بۆ <?= purchase_order_escape((string) $product['product_name']) ?></label>
                                        <input id="quantity-<?= $productId ?>" name="quantity[<?= $productId ?>]" type="number" min="1" step="1" value="<?= purchase_order_escape((string) $quantityValue) ?>" class="w-28 rounded-lg border border-slate-300 px-3 py-2">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$lowStockProducts): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-10 text-center text-slate-500">هیچ کاڵایەکی کەم‌کۆگا نییە کە داواکاری بۆ دروست بکرێت.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" <?= !$lowStockProducts ? 'disabled' : '' ?> class="rounded-xl bg-amber-500 px-5 py-3 font-bold text-slate-950 transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:bg-slate-300">
                    <i class="fa-solid fa-paper-plane ml-2" aria-hidden="true"></i>
                    تۆمارکردنی داواکاری
                </button>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="font-bold text-slate-800">مێژووی داواکارییەکان</h3>
                <p class="text-sm text-slate-500">کۆی ڕیزەکان: <?= number_format(count($orders)) ?></p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-right text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th scope="col" class="px-5 py-3 font-semibold">ژمارە</th>
                            <th scope="col" class="px-5 py-3 font-semibold">بەروار</th>
                            <th scope="col" class="px-5 py-3 font-semibold">دابینکەر</th>
                            <th scope="col" class="px-5 py-3 font-semibold">کاڵا</th>
                            <th scope="col" class="px-5 py-3 font-semibold">بڕ</th>
                            <th scope="col" class="px-5 py-3 font-semibold">داواکاری لەلایەن</th>
                            <th scope="col" class="px-5 py-3 font-semibold">دۆخ</th>
                            <th scope="col" class="px-5 py-3 font-semibold">تێبینی</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $orderStatus = (string) ($order['status'] ?? '');
                            $orderStatusLabel = [
                                'pending' => 'چاوەڕوان',
                                'ordered' => 'داواکراو',
                                'received' => 'وەرگیراو',
                                'cancelled' => 'هەڵوەشێنراوە',
                            ][$orderStatus] ?? 'نادیار';
                            $orderStatusClass = match ($orderStatus) {
                                'received' => 'bg-emerald-50 text-emerald-700',
                                'cancelled' => 'bg-slate-100 text-slate-600',
                                'ordered' => 'bg-blue-50 text-blue-700',
                                default => 'bg-amber-50 text-amber-700',
                            };
                            ?>
                            <tr class="hover:bg-slate-50/70">
                                <td class="whitespace-nowrap px-5 py-4 font-bold text-slate-800">#<?= (int) $order['order_id'] ?></td>
                                <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= purchase_order_escape((string) $order['order_date']) ?></td>
                                <td class="px-5 py-4 font-medium text-slate-700"><?= purchase_order_escape((string) $order['supplier_name']) ?></td>
                                <td class="px-5 py-4 text-slate-700"><?= purchase_order_escape((string) $order['product_name']) ?></td>
                                <td class="px-5 py-4 font-semibold text-slate-800"><?= number_format((int) $order['requested_quantity']) ?></td>
                                <td class="px-5 py-4 text-slate-600"><?= purchase_order_escape((string) ($order['created_by'] ?? 'بەکارهێنەر')) ?></td>
                                <td class="px-5 py-4"><span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold <?= $orderStatusClass ?>"><?= purchase_order_escape($orderStatusLabel) ?></span></td>
                                <td class="px-5 py-4 text-slate-600"><?= purchase_order_escape((string) ($order['notes'] ?? '—') ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$orders): ?>
                            <tr><td colspan="8" class="px-5 py-12 text-center text-slate-500">هێشتا هیچ داواکارییەکی کڕین تۆمار نەکراوە.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
</body>
</html>
