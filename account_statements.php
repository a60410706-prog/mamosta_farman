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

function account_statement_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function account_statement_money(float $amount, string $currency): string
{
    return $currency === 'IQD'
        ? number_format($amount) . ' د.ع'
        : '$' . number_format($amount, 2);
}

$page_title = 'کەشف‌حیسابی یەکگرتوو';
$accountTypes = [
    'customer' => [
        'table' => 'customers',
        'id_column' => 'customer_id',
        'name_column' => 'customer_name',
        'label' => 'کڕیار',
        'plural' => 'کڕیاران',
    ],
    'supplier' => [
        'table' => 'suppliers',
        'id_column' => 'supplier_id',
        'name_column' => 'supplier_name',
        'label' => 'دابینکەر',
        'plural' => 'دابینکەرەکان',
    ],
    'general' => [
        'label' => 'کڕیاری گشتی',
        'plural' => 'کڕیاری گشتی',
    ],
];

$selectedTypeInput = $_GET['type'] ?? 'customer';
$selectedType = is_string($selectedTypeInput) ? $selectedTypeInput : '';
$selectedIdInput = $_GET['id'] ?? '';
$selectedId = is_string($selectedIdInput) ? $selectedIdInput : '';
if ($selectedType === 'customer' && $selectedId === 'general') {
    $selectedType = 'general';
}
$isGeneralCustomer = $selectedType === 'general';
$validatedId = filter_var($selectedId, FILTER_VALIDATE_INT);
$hasSelectedId = array_key_exists('id', $_GET);
$fromDateInput = $_GET['from'] ?? '';
$toDateInput = $_GET['to'] ?? '';
$fromDate = is_string($fromDateInput) ? $fromDateInput : '';
$toDate = is_string($toDateInput) ? $toDateInput : '';
$errorMessage = '';
$customers = [];
$suppliers = [];
$account = null;
$transactions = [];

foreach (['دەستپێک' => $fromDate, 'کۆتایی' => $toDate] as $dateLabel => $dateValue) {
    if ($dateValue !== '' && (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)
        || !checkdate((int) substr($dateValue, 5, 2), (int) substr($dateValue, 8, 2), (int) substr($dateValue, 0, 4))
    )) {
        $errorMessage = 'بەرواری ' . $dateLabel . ' دروست نییە.';
        break;
    }
}
if ($errorMessage === '' && $fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
    $errorMessage = 'بەرواری دەستپێک نابێت لە بەرواری کۆتایی دواتر بێت.';
}

if (!isset($accountTypes[$selectedType])) {
    $errorMessage = 'جۆری هەژمار دروست نییە.';
} else {
    try {
        $customers = $pdo->query(
            'SELECT customer_id AS account_id, customer_name AS account_name
             FROM customers
             ORDER BY customer_name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $suppliers = $pdo->query(
            'SELECT supplier_id AS account_id, supplier_name AS account_name
             FROM suppliers
             ORDER BY supplier_name'
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($hasSelectedId && $errorMessage === '') {
            if (!$isGeneralCustomer && ($validatedId === false || $validatedId < 1)) {
                $errorMessage = 'هەژماری هەڵبژێردراو دروست نییە.';
            } else {
                if ($isGeneralCustomer) {
                    $balances = ['USD' => 0.0, 'IQD' => 0.0];
                    $balanceStmt = $pdo->query(
                        'SELECT currency, COALESCE(SUM(remaining_amount), 0) AS balance
                         FROM sales
                         WHERE customer_id IS NULL
                         GROUP BY currency'
                    );
                    foreach ($balanceStmt->fetchAll(PDO::FETCH_ASSOC) as $currencyBalance) {
                        $currency = $currencyBalance['currency'] === 'IQD' ? 'IQD' : 'USD';
                        $balances[$currency] = (float) $currencyBalance['balance'];
                    }
                    $account = [
                        'account_name' => 'کڕیاری گشتی',
                        'phone' => null,
                        'address' => null,
                        'balances' => $balances,
                    ];
                } else {
                    $accountType = $accountTypes[$selectedType];
                    $accountStmt = $pdo->prepare(
                        'SELECT ' . $accountType['id_column'] . ' AS account_id,
                                ' . $accountType['name_column'] . ' AS account_name,
                                phone,
                                address,
                                balance
                         FROM ' . $accountType['table'] . '
                         WHERE ' . $accountType['id_column'] . ' = :account_id
                         LIMIT 1'
                    );
                    $accountStmt->execute(['account_id' => $validatedId]);
                    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
                    if ($account && $selectedType === 'customer') {
                        $balances = ['USD' => 0.0, 'IQD' => 0.0];
                        $balanceStmt = $pdo->prepare(
                            'SELECT currency, COALESCE(SUM(remaining_amount), 0) AS balance
                             FROM sales
                             WHERE customer_id = :customer_id
                             GROUP BY currency'
                        );
                        $balanceStmt->execute(['customer_id' => $validatedId]);
                        foreach ($balanceStmt->fetchAll(PDO::FETCH_ASSOC) as $currencyBalance) {
                            $currency = $currencyBalance['currency'] === 'IQD' ? 'IQD' : 'USD';
                            $balances[$currency] = (float) $currencyBalance['balance'];
                        }
                        $account['balances'] = $balances;
                    }
                }

                if (!$account) {
                    $errorMessage = 'هەژماری هەڵبژێردراو نەدۆزرایەوە.';
                } elseif ($isGeneralCustomer || $selectedType === 'customer') {
                    $conditions = $isGeneralCustomer ? 'customer_id IS NULL' : 'customer_id = :account_id';
                    $parameters = $isGeneralCustomer ? [] : ['account_id' => $validatedId];
                    if ($fromDate !== '') {
                        $conditions .= ' AND sale_date >= :from_start';
                        $parameters['from_start'] = $fromDate . ' 00:00:00';
                    }
                    if ($toDate !== '') {
                        $conditions .= ' AND sale_date < DATE_ADD(:to_end, INTERVAL 1 DAY)';
                        $parameters['to_end'] = $toDate . ' 00:00:00';
                    }
                    $transactionStmt = $pdo->prepare(
                        "SELECT
                            sale_id AS transaction_id,
                            sale_date AS transaction_date,
                            net_amount AS amount,
                            currency,
                            paid_amount,
                            remaining_amount,
                            payment_method AS payment_detail,
                            CASE
                                WHEN remaining_amount <= 0 THEN 'paid'
                                WHEN paid_amount > 0 THEN 'partial'
                                ELSE 'unpaid'
                            END AS payment_status
                         FROM sales
                         WHERE " . $conditions . "
                         ORDER BY sale_date DESC, sale_id DESC"
                    );
                    $transactionStmt->execute($parameters);
                    $transactions = $transactionStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $conditions = ['supplier_id = :account_id'];
                    $parameters = ['account_id' => $validatedId];
                    if ($fromDate !== '') {
                        $conditions[] = 'purchase_date >= :from_start';
                        $parameters['from_start'] = $fromDate . ' 00:00:00';
                    }
                    if ($toDate !== '') {
                        $conditions[] = 'purchase_date < DATE_ADD(:to_end, INTERVAL 1 DAY)';
                        $parameters['to_end'] = $toDate . ' 00:00:00';
                    }
                    $transactionStmt = $pdo->prepare(
                        'SELECT
                            purchase_id AS transaction_id,
                            purchase_date AS transaction_date,
                            net_amount AS amount,
                            paid_amount,
                            remaining_amount,
                            payment_status
                         FROM purchases
                         WHERE ' . implode(' AND ', $conditions) . '
                         ORDER BY purchase_date DESC, purchase_id DESC'
                    );
                    $transactionStmt->execute($parameters);
                    $transactions = $transactionStmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    } catch (PDOException $exception) {
        error_log('Loading account statement failed: ' . $exception->getMessage());
        http_response_code(500);
        $errorMessage = 'هەڵەیەک لە هێنانی زانیاریی هەژمار ڕوویدا.';
    }
}

$generalCustomer = [['account_id' => 'general', 'account_name' => 'کڕیاری گشتی']];
$accountList = match ($selectedType) {
    'supplier' => $suppliers,
    'general' => $generalCustomer,
    default => $customers,
};

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">کەشف‌حیسابی یەکگرتوو</h2>
            <p class="mt-1 text-sm text-slate-500">هەژمار و مێژووی مامەڵەکانی کڕیار یان دابینکەر ببینە.</p>
        </div>

        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= account_statement_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <form method="get" action="account_statements.php" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div>
                    <label for="type" class="mb-2 block text-sm font-semibold text-slate-700">جۆری هەژمار</label>
                    <select id="type" name="type" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                        <?php foreach ($accountTypes as $typeValue => $typeDetails): ?>
                            <option value="<?= account_statement_escape($typeValue) ?>" <?= $selectedType === $typeValue ? 'selected' : '' ?>>
                                <?= account_statement_escape($typeDetails['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="account_id" class="mb-2 block text-sm font-semibold text-slate-700">ناوی هەژمار</label>
                    <select id="account_id" name="id" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                        <option value="">هەژمارێک هەڵبژێرە</option>
                        <?php foreach ($accountList as $listedAccount): ?>
                            <option value="<?= account_statement_escape((string) $listedAccount['account_id']) ?>" <?= $selectedId === (string) $listedAccount['account_id'] ? 'selected' : '' ?>>
                                <?= account_statement_escape((string) $listedAccount['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="from" class="mb-2 block text-sm font-semibold text-slate-700">لە بەرواری</label>
                    <input id="from" name="from" type="date" value="<?= account_statement_escape($fromDate) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                </div>
                <div>
                    <label for="to" class="mb-2 block text-sm font-semibold text-slate-700">تا بەرواری</label>
                    <input id="to" name="to" type="date" value="<?= account_statement_escape($toDate) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                </div>
                <button type="submit" class="rounded-xl bg-amber-500 px-5 py-3 font-semibold text-slate-950 transition hover:bg-amber-400">
                    <i class="fa-solid fa-magnifying-glass ml-2" aria-hidden="true"></i>
                    پیشاندانی کەشف‌حیساب
                </button>
            </form>
        </section>

        <?php if ($account): ?>
            <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-4">
                        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-700">
                            <i class="fa-solid <?= $selectedType !== 'supplier' ? 'fa-user' : 'fa-truck-field' ?>" aria-hidden="true"></i>
                        </span>
                        <div>
                            <p class="text-sm font-medium text-slate-500"><?= account_statement_escape($accountTypes[$selectedType]['label']) ?></p>
                            <h3 class="mt-1 text-xl font-bold text-slate-900"><?= account_statement_escape((string) $account['account_name']) ?></h3>
                        </div>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-5 py-3 lg:min-w-48">
                        <p class="text-sm font-medium text-slate-500">باڵانسی ئێستا</p>
                        <?php if (isset($account['balances'])): ?>
                            <p class="mt-1 text-sm font-bold text-slate-900">
                                دۆلار: <?= account_statement_escape(account_statement_money($account['balances']['USD'], 'USD')) ?><br>
                                دینار: <?= account_statement_escape(account_statement_money($account['balances']['IQD'], 'IQD')) ?>
                            </p>
                        <?php else: ?>
                            <p class="mt-1 text-2xl font-extrabold text-slate-900">$<?= number_format((float) $account['balance'], 2) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <dl class="mt-5 grid grid-cols-1 gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold text-slate-500">مۆبایل</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-700"><?= account_statement_escape((string) ($account['phone'] ?? '—') ?: '—') ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold text-slate-500">ناونیشان</dt>
                        <dd class="mt-1 text-sm font-medium text-slate-700"><?= account_statement_escape((string) ($account['address'] ?? '—') ?: '—') ?></dd>
                    </div>
                </dl>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-col gap-1 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="font-bold text-slate-800">مێژووی <?= account_statement_escape($selectedType !== 'supplier' ? 'فرۆشتن' : 'کڕین') ?></h3>
                    <p class="text-sm text-slate-500">ژمارەی مامەڵەکان: <?= number_format(count($transactions)) ?></p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[780px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold">ژمارەی <?= $selectedType !== 'supplier' ? 'پسووڵە' : 'فاکتوور' ?></th>
                                <th scope="col" class="px-5 py-3 font-semibold">بەروار</th>
                                <th scope="col" class="px-5 py-3 font-semibold">کۆی مامەڵە</th>
                                <th scope="col" class="px-5 py-3 font-semibold">پارەی دراو</th>
                                <th scope="col" class="px-5 py-3 font-semibold">ماوە</th>
                                <?php if ($selectedType !== 'supplier'): ?>
                                    <th scope="col" class="px-5 py-3 font-semibold">شێوازی پارەدان</th>
                                <?php endif; ?>
                                <th scope="col" class="px-5 py-3 font-semibold">دۆخ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($transactions as $transaction): ?>
                                <?php
                                $status = (string) ($transaction['payment_status'] ?? '');
                                $statusLabel = [
                                    'paid' => 'پارەدراو',
                                    'partial' => 'بەشێک پارەدراو',
                                    'unpaid' => 'نەپارەدراو',
                                ][$status] ?? 'نادیار';
                                $statusClass = match ($status) {
                                    'paid' => 'bg-emerald-50 text-emerald-700',
                                    'partial' => 'bg-amber-50 text-amber-700',
                                    'unpaid' => 'bg-red-50 text-red-700',
                                    default => 'bg-slate-100 text-slate-600',
                                };
                                ?>
                                <tr class="hover:bg-slate-50/70">
                                    <td class="whitespace-nowrap px-5 py-4 font-bold text-slate-800">#<?= (int) $transaction['transaction_id'] ?></td>
                                    <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= account_statement_escape((string) $transaction['transaction_date']) ?></td>
                                    <td class="whitespace-nowrap px-5 py-4 font-semibold text-slate-800"><?= account_statement_escape(account_statement_money((float) $transaction['amount'], (string) ($transaction['currency'] ?? 'USD'))) ?></td>
                                    <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= account_statement_escape(account_statement_money((float) $transaction['paid_amount'], (string) ($transaction['currency'] ?? 'USD'))) ?></td>
                                    <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= account_statement_escape(account_statement_money((float) $transaction['remaining_amount'], (string) ($transaction['currency'] ?? 'USD'))) ?></td>
                                    <?php if ($selectedType !== 'supplier'): ?>
                                        <td class="whitespace-nowrap px-5 py-4 text-slate-600"><?= account_statement_escape((string) ($transaction['payment_detail'] ?? '—') ?: '—') ?></td>
                                    <?php endif; ?>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass ?>">
                                            <?= account_statement_escape($statusLabel) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$transactions): ?>
                                <tr>
                                    <td colspan="<?= $selectedType !== 'supplier' ? '7' : '6' ?>" class="px-5 py-12 text-center text-slate-500">
                                        هیچ مامەڵەیەک بۆ ئەم هەژمارە تۆمار نەکراوە.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
<script>
    const accountLists = <?= json_encode(
        ['customer' => $customers, 'supplier' => $suppliers, 'general' => $generalCustomer],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    ) ?>;
    const typeSelect = document.getElementById('type');
    const accountSelect = document.getElementById('account_id');
    const selectedAccountId = <?= json_encode($selectedId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    typeSelect.addEventListener('change', () => {
        const accounts = accountLists[typeSelect.value] || [];
        accountSelect.replaceChildren(new Option('هەژمارێک هەڵبژێرە', ''));

        accounts.forEach((account) => {
            accountSelect.add(new Option(account.account_name, account.account_id));
        });
    });

    if (selectedAccountId !== '') {
        accountSelect.value = selectedAccountId;
    }
</script>
</body>
</html>
