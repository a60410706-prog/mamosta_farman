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

function returns_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'گەڕاندنەوەی فرۆشتن';
$returns = [];
$errorMessage = '';
$loadError = false;
$successMessage = $_SESSION['returns_success'] ?? '';
unset($_SESSION['returns_success']);
$formOpen = false;
$formValues = [
    'sale_id' => '',
    'product_id' => '',
    'quantity' => '',
    'return_amount' => '',
    'reason' => '',
    'return_date' => date('Y-m-d'),
];

if (empty($_SESSION['returns_csrf_token'])) {
    $_SESSION['returns_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formOpen = true;
    foreach ($formValues as $key => $default) {
        $postedValue = $_POST[$key] ?? null;
        if (is_string($postedValue)) {
            $formValues[$key] = trim($postedValue);
        }
    }

    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['returns_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. تکایە دووبارە هەوڵ بدەوە.';
    } else {
        $saleId = filter_var($formValues['sale_id'], FILTER_VALIDATE_INT);
        $productId = filter_var($formValues['product_id'], FILTER_VALIDATE_INT);
        $quantity = filter_var($formValues['quantity'], FILTER_VALIDATE_INT);
        $amount = $formValues['return_amount'];
        $reason = $formValues['reason'];
        $returnDate = $formValues['return_date'];

        if ($saleId === false || $saleId < 1 || $productId === false || $productId < 1) {
            $errorMessage = 'تکایە پسووڵە و کاڵایەکی دروست هەڵبژێرە.';
        } elseif ($quantity === false || $quantity < 1) {
            $errorMessage = 'بڕی گەڕاندنەوە دەبێت لانیکەم ١ بێت.';
        } elseif (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
            $errorMessage = 'بڕی پارە دروست نییە؛ ژمارەیەک بە دوو خانەی دەیی بنووسە.';
        } elseif (($reasonLength = preg_match_all('/./us', $reason)) === false || $reasonLength > 255) {
            $errorMessage = 'هۆکارەکە نابێت لە ٢٥٥ پیت زیاتر بێت.';
        } elseif (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)
            || !checkdate((int) substr($returnDate, 5, 2), (int) substr($returnDate, 8, 2), (int) substr($returnDate, 0, 4))
        ) {
            $errorMessage = 'تکایە بەروارێکی دروست هەڵبژێرە.';
        } else {
            try {
                $pdo->beginTransaction();

                $saleStmt = $pdo->prepare(
                    'SELECT sale_id FROM sales WHERE sale_id = :sale_id FOR UPDATE'
                );
                $saleStmt->execute(['sale_id' => $saleId]);
                if ($saleStmt->fetchColumn() === false) {
                    throw new RuntimeException('پسووڵەی فرۆشتن نەدۆزرایەوە.');
                }

                $productStmt = $pdo->prepare(
                    'SELECT product_id FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $productStmt->execute(['product_id' => $productId]);
                if ($productStmt->fetchColumn() === false) {
                    throw new RuntimeException('کاڵای هەڵبژێردراو نەدۆزرایەوە.');
                }

                $soldStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(quantity), 0)
                     FROM sale_items
                     WHERE sale_id = :sale_id AND product_id = :product_id'
                );
                $soldStmt->execute(['sale_id' => $saleId, 'product_id' => $productId]);
                $soldQuantity = (int) $soldStmt->fetchColumn();
                if ($soldQuantity < 1) {
                    throw new RuntimeException('ئەم کاڵایە لەو پسووڵەیەدا نەفرۆشراوە.');
                }

                $returnedStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(quantity), 0)
                     FROM returns
                     WHERE sale_id = :sale_id
                       AND product_id = :product_id
                       AND return_type = \'customer_return\''
                );
                $returnedStmt->execute(['sale_id' => $saleId, 'product_id' => $productId]);
                $alreadyReturned = (int) $returnedStmt->fetchColumn();
                if ($quantity > $soldQuantity - $alreadyReturned) {
                    throw new RuntimeException(
                        'بڕی داواکراو لە بڕی ماوەی ئەم کاڵایە لە پسووڵەکە زیاترە. بڕی ماوە: '
                        . max(0, $soldQuantity - $alreadyReturned)
                    );
                }

                $insertStmt = $pdo->prepare(
                    'INSERT INTO returns
                        (sale_id, product_id, quantity, return_amount, return_type, reason, return_date)
                     VALUES
                        (:sale_id, :product_id, :quantity, :return_amount, \'customer_return\', :reason, :return_date)'
                );
                $insertStmt->execute([
                    'sale_id' => $saleId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'return_amount' => $amount,
                    'reason' => $reason !== '' ? $reason : null,
                    'return_date' => $returnDate . ' 00:00:00',
                ]);

                $stockStmt = $pdo->prepare(
                    'UPDATE products
                     SET stock_quantity = COALESCE(stock_quantity, 0) + :quantity
                     WHERE product_id = :product_id'
                );
                $stockStmt->execute(['quantity' => $quantity, 'product_id' => $productId]);

                $pdo->commit();
                $_SESSION['returns_success'] = 'گەڕاندنەوەکە تۆمار کرا و بڕی کاڵا بۆ کۆگا زیاد کرا.';
                header('Location: returns.php');
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
                error_log('Saving customer return failed: ' . $exception->getMessage());
                http_response_code(500);
                $errorMessage = 'گەڕاندنەوەکە تۆمار نەکرا. تکایە هەوڵ بدەوە.';
            }
        }
    }
}

$saleProducts = [];
$salesForForm = [];

try {
    $availableItems = $pdo->query(
        'SELECT s.sale_id, s.sale_date, s.currency, si.product_id, p.product_name,
                SUM(si.quantity) AS sold_quantity,
                COALESCE(r.returned_quantity, 0) AS returned_quantity
         FROM sales AS s
         INNER JOIN sale_items AS si ON si.sale_id = s.sale_id
         INNER JOIN products AS p ON p.product_id = si.product_id
         LEFT JOIN (
             SELECT sale_id, product_id, SUM(quantity) AS returned_quantity
             FROM returns
             WHERE return_type = \'customer_return\' AND sale_id IS NOT NULL
             GROUP BY sale_id, product_id
         ) AS r ON r.sale_id = s.sale_id AND r.product_id = si.product_id
         GROUP BY s.sale_id, s.sale_date, s.currency, si.product_id, p.product_name, r.returned_quantity
         HAVING SUM(si.quantity) > COALESCE(r.returned_quantity, 0)
         ORDER BY s.sale_date DESC, s.sale_id DESC, p.product_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($availableItems as $item) {
        $saleId = (int) $item['sale_id'];
        $remainingQuantity = (int) $item['sold_quantity'] - (int) $item['returned_quantity'];
        $salesForForm[$saleId] = [
            'sale_date' => (string) $item['sale_date'],
            'currency' => (string) $item['currency'],
        ];
        $saleProducts[$saleId][] = [
            'product_id' => (int) $item['product_id'],
            'product_name' => (string) $item['product_name'],
            'remaining_quantity' => $remainingQuantity,
        ];
    }

    $returns = $pdo->query(
        'SELECT r.return_id, r.sale_id, r.product_id, p.product_name,
                r.quantity, r.return_amount, s.currency, r.reason, r.return_date
         FROM returns AS r
         INNER JOIN products AS p ON p.product_id = r.product_id
         LEFT JOIN sales AS s ON s.sale_id = r.sale_id
         WHERE r.return_type = \'customer_return\'
         ORDER BY r.return_date DESC, r.return_id DESC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading customer return page data failed: ' . $exception->getMessage());
    http_response_code(500);
    $loadError = true;
    if ($errorMessage === '') {
        $errorMessage = 'هەڵەیەک لە هێنانی زانیارییەکانی گەڕاندنەوەدا ڕوویدا.';
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">گەڕاندنەوەی فرۆشتن</h2>
                <p class="mt-1 text-sm text-slate-500">تۆمارە ڕاستەقینەکانی گەڕاندنەوەی کڕیار لە داتابەیسەوە پیشان دەدرێن.</p>
            </div>
            <button id="toggleReturnForm" type="button" aria-expanded="<?= $formOpen ? 'true' : 'false' ?>" aria-controls="returnFormPanel"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                زیادکردنی گەڕاندنەوە
            </button>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800" role="status">
                <?= returns_escape((string) $successMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800" role="alert">
                <?= returns_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section id="returnFormPanel" <?= $formOpen ? '' : 'hidden' ?> class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div class="mb-5">
                <h3 class="text-lg font-bold text-slate-800">تۆمارکردنی گەڕاندنەوەی نوێ</h3>
                <p class="mt-1 text-sm text-slate-500">پسووڵە و کاڵای فرۆشراو هەڵبژێرە؛ تەنها بڕی ماوەی گەڕاندنەوە ڕێگەپێدراوە.</p>
            </div>
            <?php if (!$salesForForm): ?>
                <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800" role="status">
                    هیچ کاڵایەکی فرۆشراوی ماوە بۆ گەڕاندنەوە نییە.
                </p>
            <?php else: ?>
                <form method="post" class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <input type="hidden" name="csrf_token" value="<?= returns_escape($_SESSION['returns_csrf_token']) ?>">
                    <div>
                        <label for="returnSaleId" class="mb-1 block text-sm font-semibold text-slate-700">پسووڵەی فرۆشتن</label>
                        <select id="returnSaleId" name="sale_id" required
                                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200">
                            <option value="">پسووڵە هەڵبژێرە</option>
                            <?php foreach ($salesForForm as $saleId => $sale): ?>
                                <option value="<?= (int) $saleId ?>"
                                        data-currency="<?= returns_escape($sale['currency']) ?>"
                                        <?= (string) $saleId === $formValues['sale_id'] ? 'selected' : '' ?>>
                                    <?= 'پسووڵەی ' . (int) $saleId . ' — ' . returns_escape($sale['sale_date']) . ' (' . returns_escape($sale['currency']) . ')' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="returnProductId" class="mb-1 block text-sm font-semibold text-slate-700">کاڵا</label>
                        <select id="returnProductId" name="product_id" required
                                data-selected="<?= returns_escape($formValues['product_id']) ?>"
                                class="w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200">
                            <option value="">سەرەتا پسووڵە هەڵبژێرە</option>
                        </select>
                        <p id="returnQuantityHint" class="mt-1 text-xs text-slate-500" aria-live="polite"></p>
                    </div>
                    <div>
                        <label for="returnQuantity" class="mb-1 block text-sm font-semibold text-slate-700">بڕ</label>
                        <input id="returnQuantity" name="quantity" type="number" min="1" step="1" required
                               value="<?= returns_escape($formValues['quantity']) ?>"
                               class="w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200">
                    </div>
                    <div>
                        <label for="returnAmount" class="mb-1 block text-sm font-semibold text-slate-700">
                            بڕی پارە <span id="returnCurrency" class="font-normal text-slate-500"></span>
                        </label>
                        <input id="returnAmount" name="return_amount" type="number" min="0" max="99999999.99" step="0.01" required
                               value="<?= returns_escape($formValues['return_amount']) ?>"
                               class="w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200">
                    </div>
                    <div>
                        <label for="returnReason" class="mb-1 block text-sm font-semibold text-slate-700">هۆکار <span class="font-normal text-slate-500">(ئارەزوومەندانە)</span></label>
                        <input id="returnReason" name="reason" type="text" maxlength="255"
                               value="<?= returns_escape($formValues['reason']) ?>"
                               class="w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200">
                    </div>
                    <div>
                        <label for="returnDate" class="mb-1 block text-sm font-semibold text-slate-700">بەروار</label>
                        <input id="returnDate" name="return_date" type="date" required
                               value="<?= returns_escape($formValues['return_date']) ?>"
                               class="w-full rounded-xl border border-slate-300 px-3 py-3 text-slate-800 focus:border-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200">
                    </div>
                    <div class="flex flex-wrap gap-3 pt-1 md:col-span-2">
                        <button type="submit"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white transition hover:bg-rose-700 focus:outline-none focus:ring-2 focus:ring-rose-500 focus:ring-offset-2">
                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                            تۆمارکردنی گەڕاندنەوە
                        </button>
                        <button id="cancelReturnForm" type="button"
                                class="rounded-xl border border-slate-300 px-5 py-3 font-semibold text-slate-700 transition hover:bg-slate-50">
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
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">ژمارە</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">کاڵا</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">پسووڵە</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بڕ</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بڕی پارە</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">هۆکار</th>
                                <th scope="col" class="whitespace-nowrap px-4 py-3 font-semibold">بەروار</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($returns): ?>
                                <?php foreach ($returns as $return): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-500"><?= (int) $return['return_id'] ?></td>
                                        <td class="px-4 py-3 font-medium text-slate-800"><?= returns_escape((string) $return['product_name']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= $return['sale_id'] === null ? '—' : (int) $return['sale_id'] ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= number_format((int) $return['quantity']) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                            <?= number_format((float) $return['return_amount'], 2) ?>
                                            <?= $return['currency'] === 'IQD' ? 'د.ع' : ($return['currency'] === 'USD' ? 'USD' : '') ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-600"><?= returns_escape((string) ($return['reason'] ?? '—')) ?></td>
                                        <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= returns_escape((string) ($return['return_date'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">هیچ تۆمارێکی گەڕاندنەوەی فرۆشتن نییە.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($returns) === 200): ?>
                    <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">تەنها دوا ٢٠٠ تۆمار پیشان دراون.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<script>
(() => {
    const formPanel = document.getElementById('returnFormPanel');
    const toggleButton = document.getElementById('toggleReturnForm');
    const cancelButton = document.getElementById('cancelReturnForm');
    const saleSelect = document.getElementById('returnSaleId');
    const productSelect = document.getElementById('returnProductId');
    const quantityInput = document.getElementById('returnQuantity');
    const quantityHint = document.getElementById('returnQuantityHint');
    const currencyLabel = document.getElementById('returnCurrency');
    const saleProducts = <?= json_encode($saleProducts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

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

    const updateProducts = () => {
        if (!saleSelect || !productSelect) {
            return;
        }
        const selectedProduct = productSelect.dataset.selected || productSelect.value;
        const options = saleProducts[saleSelect.value] || [];
        productSelect.replaceChildren(new Option('کاڵا هەڵبژێرە', ''));
        options.forEach((product) => {
            const option = new Option(
                `${product.product_name} — بڕی ماوە: ${product.remaining_quantity}`,
                String(product.product_id)
            );
            option.dataset.remainingQuantity = String(product.remaining_quantity);
            productSelect.add(option);
        });
        if (options.some((product) => String(product.product_id) === selectedProduct)) {
            productSelect.value = selectedProduct;
        }
        productSelect.dataset.selected = '';
        updateQuantityLimit();
        const selectedSale = saleSelect.options[saleSelect.selectedIndex];
        currencyLabel.textContent = selectedSale && selectedSale.dataset.currency
            ? `(${selectedSale.dataset.currency})`
            : '';
    };

    const updateQuantityLimit = () => {
        if (!productSelect || !quantityInput || !quantityHint) {
            return;
        }
        const selectedProduct = productSelect.options[productSelect.selectedIndex];
        const maximum = selectedProduct ? Number(selectedProduct.dataset.remainingQuantity || 0) : 0;
        quantityInput.max = maximum > 0 ? String(maximum) : '';
        quantityHint.textContent = maximum > 0 ? `زۆرترین بڕی ماوە: ${maximum}` : '';
    };

    if (saleSelect) {
        saleSelect.addEventListener('change', updateProducts);
        productSelect.addEventListener('change', updateQuantityLimit);
        updateProducts();
    }
})();
</script>
</body>
</html>
