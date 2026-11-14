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

if (empty($_SESSION['purchases_csrf_token'])) {
    $_SESSION['purchases_csrf_token'] = bin2hex(random_bytes(32));
}

function purchase_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'فاکتوورەکانی کڕین';
$errorMessage = '';
$successMessage = $_SESSION['purchase_success'] ?? '';
unset($_SESSION['purchase_success']);
$suppliers = [];
$products = [];
$purchases = [];
$formSupplierId = '';
$formPurchaseDate = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $formSupplierId = $_POST['supplier_id'] ?? '';
    $formPurchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
    $productIds = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unitCosts = $_POST['unit_cost'] ?? [];

    if (!is_string($token) || !hash_equals($_SESSION['purchases_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif (!is_array($productIds) || !is_array($quantities) || !is_array($unitCosts)) {
        $errorMessage = 'زانیاریی کاڵاکانی فاکتوور دروست نییە.';
    } else {
        $supplierId = filter_var($formSupplierId, FILTER_VALIDATE_INT);
        $date = DateTime::createFromFormat('Y-m-d', is_string($formPurchaseDate) ? $formPurchaseDate : '');
        $dateValid = $date && $date->format('Y-m-d') === $formPurchaseDate;

        if (!$supplierId || $supplierId < 1) {
            $errorMessage = 'تکایە دابینکەرێک هەڵبژێرە.';
        } elseif (!$dateValid) {
            $errorMessage = 'بەرواری فاکتوور دروست نییە.';
        } elseif (!$productIds) {
            $errorMessage = 'لانیکەم یەک کاڵا بۆ فاکتوور زیاد بکە.';
        } else {
            $items = [];
            $inputValid = true;

            foreach ($productIds as $index => $rawProductId) {
                if ($rawProductId === '' || $rawProductId === null) {
                    continue;
                }

                $productId = filter_var($rawProductId, FILTER_VALIDATE_INT);
                $quantity = filter_var($quantities[$index] ?? null, FILTER_VALIDATE_INT);
                $costInput = $unitCosts[$index] ?? null;

                if (
                    !$productId || $productId < 1 ||
                    !$quantity || $quantity < 1 ||
                    !is_scalar($costInput) || !is_numeric((string) $costInput) ||
                    (float) $costInput < 0
                ) {
                    $inputValid = false;
                    break;
                }

                $items[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => (float) $costInput,
                ];
            }

            if (!$inputValid || !$items) {
                $errorMessage = 'زانیاریی کاڵا، بڕ و نرخی کڕین بپشکنە.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $supplierStmt = $pdo->prepare(
                        'SELECT supplier_id FROM suppliers WHERE supplier_id = :supplier_id'
                    );
                    $supplierStmt->execute(['supplier_id' => $supplierId]);
                    if (!$supplierStmt->fetchColumn()) {
                        throw new RuntimeException('دابینکەری هەڵبژێردراو نەدۆزرایەوە.');
                    }

                    $checkedItems = [];
                    $total = 0.0;
                    $productStmt = $pdo->prepare(
                        'SELECT product_id, product_name
                         FROM products WHERE product_id = :product_id FOR UPDATE'
                    );

                    foreach ($items as $item) {
                        $productStmt->execute(['product_id' => $item['product_id']]);
                        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                        if (!$product) {
                            throw new RuntimeException('کاڵایەک لە فاکتووردا نەدۆزرایەوە.');
                        }

                        $subtotal = $item['quantity'] * $item['unit_cost'];
                        $total += $subtotal;
                        $checkedItems[] = $item + [
                            'product_name' => $product['product_name'],
                            'subtotal' => $subtotal,
                        ];
                    }

                    $purchaseStmt = $pdo->prepare(
                        'INSERT INTO purchases
                            (supplier_id, user_id, total_amount, discount, net_amount,
                             paid_amount, remaining_amount, payment_status, purchase_date)
                         VALUES
                            (:supplier_id, :user_id, :total_amount, 0, :net_amount,
                             0, :remaining_amount, :payment_status, :purchase_date)'
                    );
                    $purchaseStmt->execute([
                        'supplier_id' => $supplierId,
                        'user_id' => (int) $_SESSION['user_id'],
                        'total_amount' => $total,
                        'net_amount' => $total,
                        'remaining_amount' => $total,
                        'payment_status' => 'unpaid',
                        'purchase_date' => $formPurchaseDate,
                    ]);
                    $purchaseId = (int) $pdo->lastInsertId();

                    $itemStmt = $pdo->prepare(
                        'INSERT INTO purchase_items
                            (purchase_id, product_id, quantity, purchase_price, total_price)
                         VALUES
                            (:purchase_id, :product_id, :quantity, :purchase_price, :total_price)'
                    );
                    foreach ($checkedItems as $item) {
                        $itemStmt->execute([
                            'purchase_id' => $purchaseId,
                            'product_id' => $item['product_id'],
                            'quantity' => $item['quantity'],
                            'purchase_price' => $item['unit_cost'],
                            'total_price' => $item['subtotal'],
                        ]);
                    }

                    $pdo->commit();
                    $_SESSION['purchase_success'] = 'فاکتووری کڕین بە سەرکەوتوویی تۆمار کرا.';
                    header('Location: purchases.php');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($exception instanceof PDOException) {
                        error_log('Saving purchase failed: ' . $exception->getMessage());
                        $errorMessage = 'فاکتوورەکە پاشەکەوت نەکرا. پێکهاتەی خشتەکانی purchases و purchase_items بپشکنە.';
                    } else {
                        $errorMessage = $exception->getMessage();
                    }
                }
            }
        }
    }
}

try {
    $suppliers = $pdo->query(
        'SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $products = $pdo->query(
        'SELECT product_id, product_name, stock_quantity FROM products ORDER BY product_name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $purchases = $pdo->query(
        'SELECT
            p.purchase_id,
            p.purchase_date,
            p.total_amount,
            s.supplier_name,
            COUNT(pi.p_item_id) AS item_count
         FROM purchases AS p
         LEFT JOIN suppliers AS s ON s.supplier_id = p.supplier_id
         LEFT JOIN purchase_items AS pi ON pi.purchase_id = p.purchase_id
         GROUP BY p.purchase_id, p.purchase_date, p.total_amount, s.supplier_name
         ORDER BY p.purchase_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading purchases page data failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی داتاکانی فاکتوور ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">فاکتوورەکانی کڕین</h2>
            <p class="mt-1 text-sm text-slate-500">فاکتووری نوێ تۆمار بکە و فاکتوورەکانی پێشوو ببینە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= purchase_escape($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= purchase_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h3 class="text-lg font-bold text-slate-800">تۆمارکردنی فاکتووری نوێ</h3>
            <form method="post" action="purchases.php" class="mt-5 space-y-6">
                <input type="hidden" name="csrf_token" value="<?= purchase_escape($_SESSION['purchases_csrf_token']) ?>">

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label for="supplier_id" class="mb-2 block text-sm font-semibold text-slate-700">دابینکەر</label>
                        <select id="supplier_id" name="supplier_id" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                            <option value="">دابینکەر هەڵبژێرە</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= (int) $supplier['supplier_id'] ?>" <?= (string) $formSupplierId === (string) $supplier['supplier_id'] ? 'selected' : '' ?>>
                                    <?= purchase_escape((string) $supplier['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="purchase_date" class="mb-2 block text-sm font-semibold text-slate-700">بەرواری کڕین</label>
                        <input id="purchase_date" name="purchase_date" type="date" required value="<?= purchase_escape(is_string($formPurchaseDate) ? $formPurchaseDate : '') ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                </div>

                <div>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h4 class="font-semibold text-slate-800">کاڵاکانی فاکتوور</h4>
                        <button id="add-purchase-row" type="button" class="rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">
                            + زیادکردنی کاڵا
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[700px] text-right text-sm">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">کاڵا</th>
                                    <th class="px-4 py-3 font-semibold">بڕ</th>
                                    <th class="px-4 py-3 font-semibold">نرخی یەکە ($)</th>
                                    <th class="px-4 py-3 font-semibold">کۆ ($)</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody id="purchase-items" class="divide-y divide-slate-100">
                                <tr class="purchase-row">
                                    <td class="px-4 py-3">
                                        <select name="product_id[]" required class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                                            <option value="">کاڵا هەڵبژێرە</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?= (int) $product['product_id'] ?>">
                                                    <?= purchase_escape((string) $product['product_name']) ?> (کۆگا: <?= (int) $product['stock_quantity'] ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3"><input name="quantity[]" type="number" min="1" step="1" required value="1" class="w-24 rounded-lg border border-slate-300 px-3 py-2"></td>
                                    <td class="px-4 py-3"><input name="unit_cost[]" type="number" min="0" step="0.01" required value="0" class="w-32 rounded-lg border border-slate-300 px-3 py-2"></td>
                                    <td class="px-4 py-3"><span class="row-total font-semibold text-slate-700">$0.00</span></td>
                                    <td class="px-4 py-3"><button type="button" class="remove-row text-red-600 hover:text-red-800" aria-label="لابردنی کاڵا">لابردن</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-col items-stretch justify-between gap-4 border-t border-slate-100 pt-5 sm:flex-row sm:items-center">
                    <p class="text-lg font-bold text-slate-800">کۆی فاکتوور: <span id="invoice-total" class="text-amber-700">$0.00</span></p>
                    <button type="submit" class="rounded-xl bg-amber-500 px-6 py-3 font-bold text-slate-950 hover:bg-amber-400">
                        پاشەکەوتکردنی فاکتوور
                    </button>
                </div>
            </form>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5">
                <h3 class="text-lg font-bold text-slate-800">فاکتوورە کۆنەکان</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[650px] text-right text-sm">
                    <thead class="bg-slate-50 text-slate-600">
                        <tr>
                            <th class="px-6 py-4 font-semibold">ژمارە</th>
                            <th class="px-6 py-4 font-semibold">دابینکەر</th>
                            <th class="px-6 py-4 font-semibold">بەروار</th>
                            <th class="px-6 py-4 font-semibold">ژمارەی کاڵا</th>
                            <th class="px-6 py-4 font-semibold">کۆی گشتی</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <?php if ($purchases): ?>
                            <?php foreach ($purchases as $purchase): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-6 py-4 font-medium">#<?= (int) $purchase['purchase_id'] ?></td>
                                    <td class="px-6 py-4"><?= purchase_escape((string) ($purchase['supplier_name'] ?? 'دابینکەری سڕاوە')) ?></td>
                                    <td class="whitespace-nowrap px-6 py-4"><?= purchase_escape((string) $purchase['purchase_date']) ?></td>
                                    <td class="px-6 py-4"><?= number_format((int) $purchase['item_count']) ?></td>
                                    <td class="whitespace-nowrap px-6 py-4 font-semibold">$<?= number_format((float) $purchase['total_amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">هێشتا هیچ فاکتوورێکی کڕین تۆمار نەکراوە.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</main>
</div>
</div>
<script>
    const itemsBody = document.getElementById('purchase-items');
    const addRowButton = document.getElementById('add-purchase-row');
    const invoiceTotal = document.getElementById('invoice-total');

    function updateTotals() {
        let total = 0;
        itemsBody.querySelectorAll('.purchase-row').forEach((row) => {
            const quantity = Number(row.querySelector('[name="quantity[]"]').value) || 0;
            const cost = Number(row.querySelector('[name="unit_cost[]"]').value) || 0;
            const subtotal = quantity * cost;
            row.querySelector('.row-total').textContent = '$' + subtotal.toFixed(2);
            total += subtotal;
        });
        invoiceTotal.textContent = '$' + total.toFixed(2);
    }

    addRowButton.addEventListener('click', () => {
        const row = itemsBody.querySelector('.purchase-row').cloneNode(true);
        row.querySelector('select').selectedIndex = 0;
        row.querySelector('[name="quantity[]"]').value = '1';
        row.querySelector('[name="unit_cost[]"]').value = '0';
        row.querySelector('.row-total').textContent = '$0.00';
        itemsBody.appendChild(row);
    });

    itemsBody.addEventListener('input', updateTotals);
    itemsBody.addEventListener('click', (event) => {
        if (event.target.classList.contains('remove-row')) {
            const rows = itemsBody.querySelectorAll('.purchase-row');
            if (rows.length > 1) {
                event.target.closest('.purchase-row').remove();
                updateTotals();
            }
        }
    });

    updateTotals();
</script>
</body>
</html>
