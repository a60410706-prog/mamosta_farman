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

if (empty($_SESSION['suppliers_csrf_token'])) {
    $_SESSION['suppliers_csrf_token'] = bin2hex(random_bytes(32));
}

function supplier_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'بەڕێوەبردنی دابینکەرەکان';
$errorMessage = '';
$successMessage = $_SESSION['supplier_success'] ?? '';
unset($_SESSION['supplier_success']);
$supplierName = '';
$companyName = '';
$phone = '';
$address = '';
$balance = '0';
$currency = '$';
$suppliers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $supplierNameInput = $_POST['supplier_name'] ?? '';
    $companyNameInput = $_POST['company_name'] ?? '';
    $phoneInput = $_POST['phone'] ?? '';
    $addressInput = $_POST['address'] ?? '';
    $balanceInput = $_POST['balance'] ?? '0';

    $supplierName = is_string($supplierNameInput) ? trim($supplierNameInput) : '';
    $companyName = is_string($companyNameInput) ? trim($companyNameInput) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $address = is_string($addressInput) ? trim($addressInput) : '';
    $balance = is_scalar($balanceInput) ? (string) $balanceInput : '';
    $currency = '$';

    if (!is_string($token) || !hash_equals($_SESSION['suppliers_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif ($supplierName === '') {
        $errorMessage = 'تکایە ناوی دابینکەر بنووسە.';
    } elseif ($balance === '' || !is_numeric($balance) || !is_finite((float) $balance)) {
        $errorMessage = 'باڵانسی دابینکەر دەبێت ژمارەیەکی دروست بێت.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO suppliers
                    (supplier_name, company_name, phone, address, balance, currency)
                 VALUES
                    (:supplier_name, :company_name, :phone, :address, :balance, :currency)'
            );
            $stmt->execute([
                'supplier_name' => $supplierName,
                'company_name' => $companyName !== '' ? $companyName : null,
                'phone' => $phone !== '' ? $phone : null,
                'address' => $address !== '' ? $address : null,
                'balance' => (float) $balance,
                'currency' => $currency,
            ]);

            $_SESSION['supplier_success'] = 'دابینکەرەکە بە سەرکەوتوویی زیاد کرا.';
            header('Location: suppliers.php');
            exit;
        } catch (PDOException $exception) {
            error_log('Creating supplier failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە تۆمارکردنی دابینکەر ڕوویدا.';
        }
    }
}

try {
    $suppliers = $pdo->query(
        'SELECT supplier_id, supplier_name, company_name, phone, address, balance, currency
         FROM suppliers
         ORDER BY supplier_id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading suppliers failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی لیستی دابینکەرەکاندا ڕوویدا.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">بەڕێوەبردنی دابینکەرەکان</h2>
            <p class="mt-1 text-sm text-slate-500">دابینکەری نوێ زیاد بکە و زانیارییەکانی دابینکەرەکان ببینە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= supplier_escape($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= supplier_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div dir="ltr" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <section dir="rtl" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-1">
                <h3 class="text-lg font-bold text-slate-800">زیادکردنی دابینکەری نوێ</h3>
                <form method="post" action="suppliers.php" class="mt-5 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= supplier_escape($_SESSION['suppliers_csrf_token']) ?>">
                    <div>
                        <label for="supplier_name" class="mb-2 block text-sm font-semibold text-slate-700">ناوی دابینکەر</label>
                        <input id="supplier_name" name="supplier_name" type="text" required maxlength="255" value="<?= supplier_escape($supplierName) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="company_name" class="mb-2 block text-sm font-semibold text-slate-700">ناوی کۆمپانیا</label>
                        <input id="company_name" name="company_name" type="text" maxlength="255" value="<?= supplier_escape($companyName) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="phone" class="mb-2 block text-sm font-semibold text-slate-700">ژمارەی مۆبایل</label>
                        <input id="phone" name="phone" type="tel" maxlength="50" value="<?= supplier_escape($phone) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <div>
                        <label for="address" class="mb-2 block text-sm font-semibold text-slate-700">ناونیشان</label>
                        <textarea id="address" name="address" rows="2" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100"><?= supplier_escape($address) ?></textarea>
                    </div>
                    <div>
                        <label for="balance" class="mb-2 block text-sm font-semibold text-slate-700">باڵانس ($)</label>
                        <input id="balance" name="balance" type="number" step="0.01" required value="<?= supplier_escape($balance) ?>" class="w-full rounded-xl border border-slate-300 px-3 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                    </div>
                    <button type="submit" class="w-full rounded-xl bg-amber-500 px-5 py-3 font-bold text-slate-950 hover:bg-amber-400">
                        تۆمارکردنی دابینکەر
                    </button>
                </form>
            </section>

            <section dir="rtl" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
                <div class="border-b border-slate-100 px-6 py-5">
                    <h3 class="text-lg font-bold text-slate-800">لیستی دابینکەرەکان</h3>
                    <p class="mt-1 text-sm text-slate-500"><?= number_format(count($suppliers)) ?> دابینکەر</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[750px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-4 font-semibold">دابینکەر</th>
                                <th scope="col" class="px-5 py-4 font-semibold">کۆمپانیا</th>
                                <th scope="col" class="px-5 py-4 font-semibold">پەیوەندی</th>
                                <th scope="col" class="px-5 py-4 font-semibold">باڵانس ($)</th>
                                <th scope="col" class="px-5 py-4 font-semibold">ناونیشان</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php if ($suppliers): ?>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4 font-semibold text-slate-800"><?= supplier_escape((string) $supplier['supplier_name']) ?></td>
                                        <td class="px-5 py-4"><?= supplier_escape((string) ($supplier['company_name'] ?: '—')) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4"><?= supplier_escape((string) ($supplier['phone'] ?: '—')) ?></td>
                                        <td class="whitespace-nowrap px-5 py-4 font-semibold">$<?= number_format((float) $supplier['balance'], 2) ?></td>
                                        <td class="max-w-xs truncate px-5 py-4 text-slate-500"><?= supplier_escape((string) ($supplier['address'] ?: '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">هێشتا هیچ دابینکەرێک تۆمار نەکراوە.</td></tr>
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
