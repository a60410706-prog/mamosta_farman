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

if (empty($_SESSION['customers_csrf_token'])) {
    $_SESSION['customers_csrf_token'] = bin2hex(random_bytes(32));
}

function customer_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'کڕیاران و قەرزەکان';
$errorMessage = '';
$successMessage = $_SESSION['customer_success'] ?? '';
unset($_SESSION['customer_success']);
$customerName = '';
$phone = '';
$address = '';
$creditLimit = '0';
$customers = [];
$totalBalance = 0.0;
$editingCustomerId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $actionInput = $_POST['action'] ?? 'create';
    $action = is_string($actionInput) ? $actionInput : '';
    $customerId = filter_var($_POST['customer_id'] ?? null, FILTER_VALIDATE_INT);
    $nameInput = $_POST['customer_name'] ?? '';
    $phoneInput = $_POST['phone'] ?? '';
    $addressInput = $_POST['address'] ?? '';
    $creditLimitInput = $_POST['credit_limit'] ?? '0';

    $customerName = is_string($nameInput) ? trim($nameInput) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $address = is_string($addressInput) ? trim($addressInput) : '';
    $creditLimit = is_scalar($creditLimitInput) ? (string) $creditLimitInput : '';

    if (!is_string($token) || !hash_equals($_SESSION['customers_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif ($action === 'delete') {
        if ($customerId === false || $customerId < 1) {
            $errorMessage = 'کڕیاری داواکراو دروست نییە.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT balance FROM customers WHERE customer_id = :customer_id'
                );
                $stmt->execute(['customer_id' => $customerId]);
                $balance = $stmt->fetchColumn();

                if ($balance === false) {
                    $errorMessage = 'کڕیارەکە نەدۆزرایەوە.';
                } elseif ((float) $balance > 0) {
                    $errorMessage = 'کڕیاری قەرزدار ناتوانرێت بسڕدرێتەوە. سەرەتا قەرزەکەی پاک بکەرەوە.';
                } else {
                    $saleStmt = $pdo->prepare(
                        'SELECT COUNT(*) FROM sales WHERE customer_id = :customer_id'
                    );
                    $saleStmt->execute(['customer_id' => $customerId]);

                    if ((int) $saleStmt->fetchColumn() > 0) {
                        $errorMessage = 'ئەم کڕیارە مێژووی فرۆشتنی هەیە و ناتوانرێت بسڕدرێتەوە.';
                    } else {
                        $deleteStmt = $pdo->prepare(
                            'DELETE FROM customers WHERE customer_id = :customer_id'
                        );
                        $deleteStmt->execute(['customer_id' => $customerId]);
                        $_SESSION['customer_success'] = 'کڕیارەکە بە سەرکەوتوویی سڕایەوە.';
                        header('Location: customers.php');
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                error_log('Deleting customer failed: ' . $exception->getMessage());
                $errorMessage = 'هەڵەیەک لە سڕینەوەی کڕیاردا ڕوویدا.';
            }
        }
    } elseif (!in_array($action, ['create', 'update'], true)) {
        $errorMessage = 'کرداری داواکراو ناسراو نییە.';
    } elseif ($action === 'update' && ($customerId === false || $customerId < 1)) {
        $errorMessage = 'کڕیاری داواکراو دروست نییە.';
    } elseif ($action === 'update') {
        $editingCustomerId = $customerId;
        if ($customerName === '') {
            $errorMessage = 'تکایە ناوی کڕیار بنووسە.';
        } elseif ($creditLimit === '' || !is_numeric($creditLimit) || (float) $creditLimit < 0) {
            $errorMessage = 'سنووری قەرز دەبێت ژمارەیەکی دروست و سفر یان زیاتر بێت.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'SELECT balance FROM customers WHERE customer_id = :customer_id'
                );
                $stmt->execute(['customer_id' => $customerId]);
                $balance = $stmt->fetchColumn();

                if ($balance === false) {
                    $editingCustomerId = null;
                    $errorMessage = 'کڕیارەکە نەدۆزرایەوە.';
                } elseif ((float) $creditLimit < (float) $balance) {
                    $errorMessage = 'سنووری قەرز نابێت لە باڵانسی ئێستای قەرز کەمتر بێت.';
                } else {
                    $updateStmt = $pdo->prepare(
                        'UPDATE customers
                         SET customer_name = :customer_name,
                             phone = :phone,
                             address = :address,
                             credit_limit = :credit_limit
                         WHERE customer_id = :customer_id'
                    );
                    $updateStmt->execute([
                        'customer_name' => $customerName,
                        'phone' => $phone !== '' ? $phone : null,
                        'address' => $address !== '' ? $address : null,
                        'credit_limit' => (float) $creditLimit,
                        'customer_id' => $customerId,
                    ]);

                    $_SESSION['customer_success'] = 'زانیاریی کڕیارەکە نوێ کرایەوە.';
                    header('Location: customers.php');
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('Updating customer failed: ' . $exception->getMessage());
                $errorMessage = 'هەڵەیەک لە نوێکردنەوەی زانیاریی کڕیاردا ڕوویدا.';
            }
        }
    } elseif ($customerName === '') {
        $errorMessage = 'تکایە ناوی کڕیار بنووسە.';
    } elseif ($creditLimit === '' || !is_numeric($creditLimit) || (float) $creditLimit < 0) {
        $errorMessage = 'سنووری قەرز دەبێت ژمارەیەکی دروست و سفر یان زیاتر بێت.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO customers (customer_name, phone, address, balance, credit_limit)
                 VALUES (:customer_name, :phone, :address, 0, :credit_limit)'
            );
            $stmt->execute([
                'customer_name' => $customerName,
                'phone' => $phone !== '' ? $phone : null,
                'address' => $address !== '' ? $address : null,
                'credit_limit' => (float) $creditLimit,
            ]);

            $_SESSION['customer_success'] = 'کڕیارەکە بە سەرکەوتوویی زیاد کرا.';
            header('Location: customers.php');
            exit;
        } catch (PDOException $exception) {
            error_log('Creating customer failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە تۆمارکردنی کڕیاردا ڕوویدا.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId === false || $editId < 1) {
        $errorMessage = 'کڕیاری داواکراو دروست نییە.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT customer_id, customer_name, phone, address, credit_limit
                 FROM customers WHERE customer_id = :customer_id'
            );
            $stmt->execute(['customer_id' => $editId]);
            $customerToEdit = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customerToEdit) {
                $editingCustomerId = (int) $customerToEdit['customer_id'];
                $customerName = (string) $customerToEdit['customer_name'];
                $phone = (string) ($customerToEdit['phone'] ?? '');
                $address = (string) ($customerToEdit['address'] ?? '');
                $creditLimit = (string) $customerToEdit['credit_limit'];
            } else {
                $errorMessage = 'کڕیارەکە نەدۆزرایەوە.';
            }
        } catch (PDOException $exception) {
            error_log('Loading customer for editing failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە بارکردنی زانیاریی کڕیاردا ڕوویدا.';
        }
    }
}

try {
    $customers = $pdo->query(
        'SELECT customer_id, customer_name, phone, address, balance, credit_limit
         FROM customers
         ORDER BY customer_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $totalBalance = (float) $pdo->query(
        'SELECT COALESCE(SUM(balance), 0) FROM customers'
    )->fetchColumn();
} catch (PDOException $exception) {
    error_log('Loading customers failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی زانیاریی کڕیاراندا ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">کڕیاران و قەرزەکان</h2>
            <p class="mt-1 text-sm text-slate-500">کڕیار زیاد بکە و باڵانسی قەرزیان بەڕێوەببە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= customer_escape($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= customer_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div dir="ltr" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <section dir="rtl" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                <h3 class="text-lg font-bold text-slate-800"><?= $editingCustomerId !== null ? 'نوێکردنەوەی زانیاریی کڕیار' : 'زیادکردنی کڕیاری نوێ' ?></h3>
                <p class="mt-1 text-sm text-slate-500">زانیارییەکانی کڕیار پڕ بکەرەوە.</p>

                <form method="post" action="customers.php" class="mt-5 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= customer_escape($_SESSION['customers_csrf_token']) ?>">
                    <input type="hidden" name="action" value="<?= $editingCustomerId !== null ? 'update' : 'create' ?>">
                    <?php if ($editingCustomerId !== null): ?>
                        <input type="hidden" name="customer_id" value="<?= $editingCustomerId ?>">
                    <?php endif; ?>
                    <div>
                        <label for="customer_name" class="mb-2 block text-sm font-semibold text-slate-700">ناوی کڕیار</label>
                        <input id="customer_name" name="customer_name" type="text" required maxlength="255" value="<?= customer_escape($customerName) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="phone" class="mb-2 block text-sm font-semibold text-slate-700">ژمارەی مۆبایل</label>
                        <input id="phone" name="phone" type="tel" maxlength="50" value="<?= customer_escape($phone) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="address" class="mb-2 block text-sm font-semibold text-slate-700">ناونیشان</label>
                        <textarea id="address" name="address" rows="3" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100"><?= customer_escape($address) ?></textarea>
                    </div>
                    <div>
                        <label for="credit_limit" class="mb-2 block text-sm font-semibold text-slate-700">سنووری قەرز ($)</label>
                        <input id="credit_limit" name="credit_limit" type="number" min="0" step="0.01" required value="<?= customer_escape($creditLimit) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-amber-500 px-5 py-3 font-bold text-slate-950 hover:bg-amber-400">
                        <?= $editingCustomerId !== null ? 'نوێکردنەوەی زانیاری' : 'تۆمارکردنی کڕیار' ?>
                    </button>
                    <?php if ($editingCustomerId !== null): ?>
                        <a href="customers.php" class="block text-center text-sm font-medium text-slate-600 hover:text-slate-900">پاشگەزبوونەوە</a>
                    <?php endif; ?>
                </form>
            </section>

            <section dir="rtl" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">لیستی کڕیاران</h3>
                        <p class="mt-1 text-sm text-slate-500"><?= number_format(count($customers)) ?> کڕیار</p>
                    </div>
                    <div class="rounded-xl bg-red-50 px-4 py-2 text-sm font-semibold text-red-700">
                        کۆی قەرز: $<?= number_format($totalBalance, 2) ?>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-4 font-semibold">کڕیار</th>
                                <th scope="col" class="px-5 py-4 font-semibold">پەیوەندی</th>
                                <th scope="col" class="px-5 py-4 font-semibold">باڵانسی قەرز</th>
                                <th scope="col" class="px-5 py-4 font-semibold">سنووری قەرز</th>
                                <th scope="col" class="px-5 py-4 font-semibold">کردار</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if ($customers): ?>
                                <?php foreach ($customers as $customer): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-semibold text-slate-800"><?= customer_escape((string) $customer['customer_name']) ?></p>
                                            <?php if (!empty($customer['address'])): ?>
                                                <p class="mt-1 max-w-xs truncate text-xs text-slate-500"><?= customer_escape((string) $customer['address']) ?></p>
                                            <?php endif; ?>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4"><?= customer_escape((string) ($customer['phone'] ?: '—')) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold <?= (float) $customer['balance'] > 0 ? 'text-red-700' : 'text-emerald-700' ?>">
                                            $<?= number_format((float) $customer['balance'], 2) ?>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-4">$<?= number_format((float) $customer['credit_limit'], 2) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4">
                                            <div class="flex items-center gap-2">
                                                <a href="customers.php?edit=<?= (int) $customer['customer_id'] ?>" class="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700">نوێکردنەوە</a>
                                                <form method="post" action="customers.php" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم کڕیارە؟');">
                                                    <input type="hidden" name="csrf_token" value="<?= customer_escape($_SESSION['customers_csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="customer_id" value="<?= (int) $customer['customer_id'] ?>">
                                                    <button type="submit" class="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700">سڕینەوە</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">هێشتا هیچ کڕیارێک تۆمار نەکراوە.</td></tr>
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
