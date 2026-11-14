<?php
// 1. پشکنینی Session بۆ دڵنیابوون لە لۆگینکردنی بەکارهێنەر
session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/includes/db.php';

function dashboard_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$section = $_GET['section'] ?? 'dashboard';
if (!in_array($section, ['dashboard', 'categories', 'profit'], true)) {
    $section = 'dashboard';
}

if ($section === 'dashboard') {
    $tileDefinitions = [
        ['label' => 'کاشێر', 'href' => 'pos.php', 'icon' => 'fa-cash-register', 'color' => 'blue', 'tables' => ['products', 'sales', 'sale_items', 'customers']],
        ['label' => 'قازانج', 'href' => 'dashboard.php?section=profit', 'icon' => 'fa-chart-line', 'color' => 'violet', 'tables' => ['products', 'customers', 'sales', 'sale_items', 'expenses', 'exchange_rates']],
        ['label' => 'فرۆشتن', 'href' => 'reports.php', 'icon' => 'fa-cart-shopping', 'color' => 'emerald', 'tables' => ['sales', 'sale_items']],
        ['label' => 'گەڕاندنەوەی فرۆشتن', 'href' => 'returns.php', 'icon' => 'fa-arrow-rotate-left', 'color' => 'rose', 'tables' => ['returns', 'sales', 'products']],
        ['label' => 'پەیوەندییەکان', 'href' => 'contacts.php', 'icon' => 'fa-address-book', 'color' => 'violet', 'tables' => ['customers', 'suppliers']],
        ['label' => 'کاڵاکان', 'href' => 'products.php', 'icon' => 'fa-box-open', 'color' => 'amber', 'tables' => ['products', 'categories']],
        ['label' => 'کۆگا', 'href' => 'warehouse.php', 'icon' => 'fa-warehouse', 'color' => 'cyan', 'tables' => ['products', 'product_batches', 'categories']],
        ['label' => 'کڕین', 'href' => 'purchases.php', 'icon' => 'fa-truck-ramp-box', 'color' => 'indigo', 'tables' => ['purchases', 'purchase_items', 'purchase_orders', 'suppliers']],
        ['label' => 'کاڵای زیان‌پێگەیشتوو', 'href' => 'damaged_goods.php', 'icon' => 'fa-triangle-exclamation', 'color' => 'orange', 'tables' => ['products']],
        ['label' => 'قاسە', 'href' => 'cash_drawer.php', 'icon' => 'fa-vault', 'color' => 'teal', 'tables' => ['cash_shifts', 'sales', 'expenses']],
        ['label' => 'پارەدان', 'href' => 'payments.php', 'icon' => 'fa-money-bill-transfer', 'color' => 'purple', 'tables' => ['payments']],
        ['label' => 'خەرجییەکان', 'href' => 'expenses.php', 'icon' => 'fa-receipt', 'color' => 'pink', 'tables' => ['expenses']],
        ['label' => 'دراو', 'href' => 'exchange_rates.php', 'icon' => 'fa-coins', 'color' => 'yellow', 'tables' => ['exchange_rates']],
        ['label' => 'ڕاپۆرتەکان', 'href' => 'export_reports.php', 'icon' => 'fa-chart-column', 'color' => 'purple', 'tables' => ['view_daily_sales_report', 'sales']],
        ['label' => 'بەکارهێنەران', 'href' => 'users.php', 'icon' => 'fa-users-gear', 'color' => 'sky', 'tables' => ['users']],
        ['label' => 'ڕێکخستن', 'href' => 'settings.php', 'icon' => 'fa-gear', 'color' => 'slate', 'tables' => []],
        ['label' => 'فێربوون', 'href' => 'learning.php', 'icon' => 'fa-graduation-cap', 'color' => 'cyan', 'tables' => ['learning_media']],
    ];
    $dashboardTiles = [];
    $dashboardTilesError = '';

    try {
        $existingTables = array_flip($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($tileDefinitions as $tile) {
            $hasRequiredTables = true;
            foreach ($tile['tables'] as $tableName) {
                if (!isset($existingTables[$tableName])) {
                    $hasRequiredTables = false;
                    break;
                }
            }
            if ($hasRequiredTables) {
                $dashboardTiles[] = $tile;
            }
        }
    } catch (PDOException $exception) {
        error_log('Loading dashboard sections failed: ' . $exception->getMessage());
        http_response_code(500);
        $dashboardTilesError = 'هەڵەیەک لە پشکنینی بەشەکانی داتابەیسدا ڕوویدا.';
    }

}

$category_message = '';
$categories = [];

if ($section === 'categories') {
    if (empty($_SESSION['categories_csrf_token'])) {
        $_SESSION['categories_csrf_token'] = bin2hex(random_bytes(32));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf_token = $_POST['csrf_token'] ?? '';

        if (!is_string($csrf_token) || !hash_equals($_SESSION['categories_csrf_token'], $csrf_token)) {
            $category_message = 'داواکارییەکە پشتڕاست نەکرایەوە. تکایە دووبارە هەوڵ بدەرەوە.';
        } else {
            $action = $_POST['action'] ?? '';

            try {
                if ($action === 'add') {
                    $category_name_input = $_POST['category_name'] ?? '';

                    if (!is_string($category_name_input) || trim($category_name_input) === '') {
                        $category_message = 'تکایە ناوی پۆلێنەکە بنووسە.';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO categories (category_name) VALUES (:category_name)');
                        $stmt->execute([':category_name' => trim($category_name_input)]);
                        $_SESSION['categories_flash'] = 'پۆلێنەکە بە سەرکەوتوویی زیادکرا.';
                        header('Location: dashboard.php?section=categories');
                        exit;
                    }
                } elseif ($action === 'delete') {
                    $category_id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);

                    if ($category_id === false || $category_id === null || $category_id < 1) {
                        $category_message = 'ژمارەی پۆلێنەکە دروست نییە.';
                    } else {
                        $stmt = $pdo->prepare('DELETE FROM categories WHERE category_id = :category_id');
                        $stmt->execute([':category_id' => $category_id]);

                        if ($stmt->rowCount() === 0) {
                            $category_message = 'پۆلێنی داواکراو نەدۆزرایەوە.';
                        } else {
                            $_SESSION['categories_flash'] = 'پۆلێنەکە بە سەرکەوتوویی سڕایەوە.';
                            header('Location: dashboard.php?section=categories');
                            exit;
                        }
                    }
                } else {
                    $category_message = 'جۆری داواکارییەکە دروست نییە.';
                }
            } catch (PDOException $e) {
                error_log('Category operation failed: ' . $e->getMessage());
                $category_message = 'هەڵەیەک لە جێبەجێکردنی داواکارییەکەدا ڕوویدا. تکایە دووبارە هەوڵ بدەرەوە.';
            }
        }
    }

    $category_flash = $_SESSION['categories_flash'] ?? '';
    unset($_SESSION['categories_flash']);

    try {
        $categories = $pdo->query(
            'SELECT category_id, category_name FROM categories ORDER BY category_id DESC'
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('Loading categories failed: ' . $e->getMessage());
        $category_message = 'هەڵەیەک لە بارکردنی پۆلێنەکاندا ڕوویدا.';
    }
} elseif ($section === 'profit') {
    $total_products = 0;
    $total_debts = 0.0;
    $today_sales = ['USD' => 0.0, 'IQD' => 0.0];
    $today_expenses = 0.0;
    $product_profit_rows = [];
    $realized_sales_profit_usd = 0.0;
    $sales_missing_purchase_cost_quantity = 0;
    $error_message = '';
    $chart_error_message = '';
    $chart_labels = [];
    $daily_sales_usd = [];
    $daily_sales_iqd = [];
    $daily_expenses = [];
    $daily_net_profit_usd = [];
    $top_selling_products = [];
    $missing_purchase_cost_quantity = 0;
    $monthly_sales_usd = 0.0;
    $monthly_expense_total = 0.0;

    try {
        $total_products = (int) $pdo->query(
            'SELECT COUNT(*) FROM products'
        )->fetchColumn();

        $total_debts = (float) $pdo->query(
            'SELECT COALESCE(SUM(balance), 0) FROM customers'
        )->fetchColumn();

        $sales_stmt = $pdo->query(
            'SELECT currency, COALESCE(SUM(net_amount), 0) AS total
             FROM sales
             WHERE sale_date >= CURDATE()
               AND sale_date < CURDATE() + INTERVAL 1 DAY
             GROUP BY currency'
        );
        foreach ($sales_stmt->fetchAll(PDO::FETCH_ASSOC) as $saleTotal) {
            $currency = $saleTotal['currency'] === 'IQD' ? 'IQD' : 'USD';
            $today_sales[$currency] = (float) $saleTotal['total'];
        }

        $today_expenses = (float) $pdo->query(
            'SELECT COALESCE(SUM(amount), 0)
             FROM expenses
             WHERE expense_date = CURDATE()'
        )->fetchColumn();

        $product_profit_rows = $pdo->query(
            'SELECT p.product_id, p.product_name, p.stock_quantity, p.sale_price_usd,
                    p.purchase_price_usd, p.purchase_price_eur, eur_rate.exchange_rate AS eur_to_usd_rate,
                    CASE
                        WHEN p.purchase_price_usd IS NOT NULL THEN p.sale_price_usd - p.purchase_price_usd
                        WHEN p.purchase_price_eur IS NOT NULL AND eur_rate.exchange_rate IS NOT NULL
                            THEN p.sale_price_usd - (p.purchase_price_eur * eur_rate.exchange_rate)
                        ELSE NULL
                    END AS unit_profit_usd
             FROM products AS p
             LEFT JOIN exchange_rates AS eur_rate ON eur_rate.currency_code = \'EUR\'
             WHERE COALESCE(p.stock_quantity, 0) > 0
             ORDER BY p.product_name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $realized_sales_stmt = $pdo->query(
            'SELECT COALESCE(SUM(
                        CASE
                            WHEN p.purchase_price_usd IS NOT NULL
                                OR (p.purchase_price_eur IS NOT NULL AND eur_rate.exchange_rate IS NOT NULL)
                                THEN si.total_price * CASE
                                    WHEN s.total_amount > 0 THEN s.net_amount / s.total_amount
                                    ELSE 0
                                END - si.quantity * CASE
                                    WHEN p.purchase_price_usd IS NOT NULL THEN p.purchase_price_usd
                                    WHEN p.purchase_price_eur IS NOT NULL AND eur_rate.exchange_rate IS NOT NULL
                                        THEN p.purchase_price_eur * eur_rate.exchange_rate
                                    ELSE 0
                                END
                            ELSE 0
                        END
                    ), 0) AS realized_profit,
                    COALESCE(SUM(
                        CASE
                            WHEN p.purchase_price_usd IS NULL
                                 AND (p.purchase_price_eur IS NULL OR eur_rate.exchange_rate IS NULL)
                                THEN si.quantity
                            ELSE 0
                        END
                    ), 0) AS missing_cost_quantity
             FROM sales AS s
             INNER JOIN sale_items AS si ON si.sale_id = s.sale_id
             LEFT JOIN products AS p ON p.product_id = si.product_id
             LEFT JOIN exchange_rates AS eur_rate ON eur_rate.currency_code = \'EUR\'
             WHERE s.currency = \'USD\''
        )->fetch(PDO::FETCH_ASSOC);
        $realized_sales_profit_usd = (float) $realized_sales_stmt['realized_profit'];
        $sales_missing_purchase_cost_quantity = (int) $realized_sales_stmt['missing_cost_quantity'];

    } catch (PDOException $e) {
        error_log('Loading dashboard data failed: ' . $e->getMessage());
        $error_message = 'هەڵەیەک لە هێنانی داتاکانی داشبۆرددا ڕوویدا.';
    }

    $total_goods_profit_usd = $realized_sales_profit_usd;

    $chart_month_start = date('Y-m-01');
    $chart_month_end = (new DateTimeImmutable($chart_month_start))->modify('first day of next month')->format('Y-m-d');
    $chart_day = new DateTimeImmutable($chart_month_start);
    $chart_end = new DateTimeImmutable($chart_month_end);
    while ($chart_day < $chart_end) {
        $day_key = $chart_day->format('Y-m-d');
        $chart_labels[] = $chart_day->format('j');
        $daily_sales_usd[$day_key] = 0.0;
        $daily_sales_iqd[$day_key] = 0.0;
        $daily_expenses[$day_key] = 0.0;
        $daily_net_profit_usd[$day_key] = 0.0;
        $chart_day = $chart_day->modify('+1 day');
    }

    try {
        $daily_sales_stmt = $pdo->prepare(
            'SELECT DATE(sale_date) AS sale_day, currency, COALESCE(SUM(net_amount), 0) AS total
             FROM sales
             WHERE sale_date >= :month_start AND sale_date < :month_end
             GROUP BY DATE(sale_date), currency'
        );
        $daily_sales_stmt->execute([
            'month_start' => $chart_month_start . ' 00:00:00',
            'month_end' => $chart_month_end . ' 00:00:00',
        ]);
        foreach ($daily_sales_stmt->fetchAll(PDO::FETCH_ASSOC) as $daily_sale) {
            $day_key = (string) $daily_sale['sale_day'];
            if (array_key_exists($day_key, $daily_sales_usd)) {
                $currency = ($daily_sale['currency'] ?? '') === 'IQD' ? 'IQD' : 'USD';
                if ($currency === 'IQD') {
                    $daily_sales_iqd[$day_key] = (float) $daily_sale['total'];
                } else {
                    $daily_sales_usd[$day_key] = (float) $daily_sale['total'];
                }
            }
        }

        $top_products_stmt = $pdo->prepare(
            'SELECT p.product_name, COALESCE(SUM(si.quantity), 0) AS quantity_sold
             FROM sales AS s
             INNER JOIN sale_items AS si ON si.sale_id = s.sale_id
             INNER JOIN products AS p ON p.product_id = si.product_id
             WHERE s.sale_date >= :month_start AND s.sale_date < :month_end
             GROUP BY p.product_id, p.product_name
             ORDER BY quantity_sold DESC, p.product_name ASC
             LIMIT 10'
        );
        $top_products_stmt->execute([
            'month_start' => $chart_month_start . ' 00:00:00',
            'month_end' => $chart_month_end . ' 00:00:00',
        ]);
        $top_selling_products = $top_products_stmt->fetchAll(PDO::FETCH_ASSOC);

        $daily_profit_stmt = $pdo->prepare(
            'SELECT DATE(s.sale_date) AS sale_day,
                    COALESCE(SUM(
                        si.total_price * CASE
                            WHEN s.total_amount > 0 THEN s.net_amount / s.total_amount
                            ELSE 0
                        END - si.quantity * CASE
                            WHEN p.purchase_price_usd IS NOT NULL THEN p.purchase_price_usd
                            WHEN p.purchase_price_eur IS NOT NULL AND eur_rate.exchange_rate IS NOT NULL
                                THEN p.purchase_price_eur * eur_rate.exchange_rate
                            ELSE 0
                        END
                    ), 0) AS profit_before_expenses,
                    COALESCE(SUM(
                        CASE
                            WHEN p.purchase_price_usd IS NULL
                                 AND (p.purchase_price_eur IS NULL OR eur_rate.exchange_rate IS NULL)
                                THEN si.quantity
                            ELSE 0
                        END
                    ), 0) AS missing_cost_quantity
             FROM sales AS s
             INNER JOIN sale_items AS si ON si.sale_id = s.sale_id
             LEFT JOIN products AS p ON p.product_id = si.product_id
             LEFT JOIN exchange_rates AS eur_rate ON eur_rate.currency_code = \'EUR\'
             WHERE s.currency = \'USD\'
               AND s.sale_date >= :month_start AND s.sale_date < :month_end
             GROUP BY DATE(s.sale_date)'
        );
        $daily_profit_stmt->execute([
            'month_start' => $chart_month_start . ' 00:00:00',
            'month_end' => $chart_month_end . ' 00:00:00',
        ]);
        foreach ($daily_profit_stmt->fetchAll(PDO::FETCH_ASSOC) as $daily_profit) {
            $day_key = (string) $daily_profit['sale_day'];
            if (array_key_exists($day_key, $daily_net_profit_usd)) {
                $daily_net_profit_usd[$day_key] = (float) $daily_profit['profit_before_expenses'];
                $missing_purchase_cost_quantity += (int) $daily_profit['missing_cost_quantity'];
            }
        }

        $daily_expenses_stmt = $pdo->prepare(
            'SELECT expense_date AS expense_day, COALESCE(SUM(amount), 0) AS total
             FROM expenses
             WHERE expense_date >= :month_start AND expense_date < :month_end
             GROUP BY expense_date'
        );
        $daily_expenses_stmt->execute([
            'month_start' => $chart_month_start,
            'month_end' => $chart_month_end,
        ]);
        foreach ($daily_expenses_stmt->fetchAll(PDO::FETCH_ASSOC) as $daily_expense) {
            $day_key = (string) $daily_expense['expense_day'];
            if (array_key_exists($day_key, $daily_expenses)) {
                $daily_expenses[$day_key] = (float) $daily_expense['total'];
            }
        }

        foreach ($daily_net_profit_usd as $day_key => $profit_before_expenses) {
            $daily_net_profit_usd[$day_key] = $profit_before_expenses - $daily_expenses[$day_key];
        }

        $monthly_sales_usd = array_sum($daily_sales_usd);
        $monthly_expense_total = array_sum($daily_expenses);
    } catch (PDOException $e) {
        error_log('Loading dashboard chart data failed: ' . $e->getMessage());
        $chart_error_message = 'نەتوانرا داتای چارتەکان باربکرێت.';
    }

    $chart_data = [
        'labels' => $chart_labels,
        'salesUsd' => array_values($daily_sales_usd),
        'salesIqd' => array_values($daily_sales_iqd),
        'expenses' => array_values($daily_expenses),
        'netProfitUsd' => array_values($daily_net_profit_usd),
        'topProductNames' => array_column($top_selling_products, 'product_name'),
        'topProductQuantities' => array_map('intval', array_column($top_selling_products, 'quantity_sold')),
        'monthlySalesUsd' => $monthly_sales_usd,
        'monthlyExpenses' => $monthly_expense_total,
        'missingPurchaseCostQuantity' => $missing_purchase_cost_quantity,
    ];
    $chart_data_json = json_encode(
        $chart_data,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    if ($chart_data_json === false) {
        error_log('Encoding dashboard chart data failed: ' . json_last_error_msg());
        $chart_error_message = 'نەتوانرا داتای چارتەکان ئامادە بکرێت.';
        $chart_data_json = 'null';
    }

}
?>
<?php
$page_title = $section === 'categories'
    ? 'بەڕێوەبردنی پۆلێنەکان'
    : ($section === 'profit' ? 'قازانج' : 'داشبۆردی سەرەکی');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
        <main id="dashboard-main" class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
            <?php if ($section === 'categories'): ?>
                <div class="mx-auto max-w-7xl">
                    <div class="mb-6">
                        <h2 class="text-2xl font-bold text-slate-800">بەڕێوەبردنی پۆلێنەکان</h2>
                        <p class="mt-1 text-sm text-slate-500">پۆلێنەکانی کاڵاکانت زیاد بکە یان بەڕێوەیان ببە.</p>
                    </div>

                    <?php if ($category_message !== ''): ?>
                        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                            <?php echo htmlspecialchars($category_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </div>
                    <?php elseif ($category_flash !== ''): ?>
                        <div class="mb-5 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700" role="status">
                            <?php echo htmlspecialchars($category_flash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                        <section class="rounded-xl bg-white p-6 shadow-sm">
                            <h3 class="mb-5 text-lg font-semibold text-slate-800">زیادکردنی پۆلێنی نوێ</h3>
                            <form method="POST" action="dashboard.php?section=categories" class="space-y-4">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['categories_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="add">
                                <div>
                                    <label for="category_name" class="mb-2 block text-sm font-medium text-slate-700">ناوی پۆلێن</label>
                                    <input id="category_name" name="category_name" type="text" required class="w-full rounded-lg border border-slate-300 px-4 py-2.5 text-slate-800 outline-none transition focus:border-amber-500 focus:ring-2 focus:ring-amber-100" placeholder="ناوی پۆلێن بنووسە">
                                </div>
                                <button type="submit" class="w-full rounded-lg bg-amber-500 px-4 py-2.5 font-semibold text-white transition hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-300">
                                    زیادکردنی پۆلێن
                                </button>
                            </form>
                        </section>

                        <section class="overflow-hidden rounded-xl bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5">
                                <h3 class="text-lg font-semibold text-slate-800">هەموو پۆلێنەکان</h3>
                                <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-700"><?php echo count($categories); ?> پۆلێن</span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-right text-sm">
                                    <thead class="bg-slate-50 text-slate-600">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 font-semibold">#</th>
                                            <th scope="col" class="px-6 py-3 font-semibold">ناوی پۆلێن</th>
                                            <th scope="col" class="px-6 py-3 font-semibold">کردار</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php if ($categories): ?>
                                            <?php foreach ($categories as $category): ?>
                                                <tr class="hover:bg-slate-50">
                                                    <td class="whitespace-nowrap px-6 py-4 text-slate-500"><?php echo htmlspecialchars((string) $category['category_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                    <td class="px-6 py-4 font-medium text-slate-800"><?php echo htmlspecialchars($category['category_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                    <td class="whitespace-nowrap px-6 py-4">
                                                        <div class="flex items-center gap-2">
                                                            <a href="categories.php?edit=<?php echo (int) $category['category_id']; ?>" class="rounded-lg border border-amber-200 px-3 py-1.5 font-medium text-amber-700 transition hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-200">نوێکردنەوە</a>
                                                            <form method="POST" action="dashboard.php?section=categories" onsubmit="return confirm('دڵنیایت لە سڕینەوەی ئەم پۆلێنە؟');">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['categories_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="category_id" value="<?php echo htmlspecialchars((string) $category['category_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                                                <button type="submit" class="rounded-lg px-3 py-1.5 font-medium text-red-600 transition hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-200">سڕینەوە</button>
                                                            </form>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="3" class="px-6 py-10 text-center text-slate-500">هێشتا هیچ پۆلێنێک تۆمار نەکراوە.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            <?php else: ?>
            
            <?php if ($section === 'profit' && $error_message !== ''): ?>
                <div class="no-print mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-red-700" role="alert">
                    <?php echo htmlspecialchars($error_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($section === 'dashboard'): ?>
            <section class="mb-8" aria-labelledby="dashboard-sections-title">
                <div class="mb-5">
                    <h2 id="dashboard-sections-title" class="text-xl font-bold text-slate-800">بەشەکانی سیستەم</h2>
                    <p class="mt-1 text-sm text-slate-500">بەشە هەبووەکانی داتابەیس هەڵبژێرە.</p>
                </div>
                <?php if ($dashboardTilesError !== ''): ?>
                    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800" role="alert">
                        <?= dashboard_escape($dashboardTilesError) ?>
                    </div>
                <?php elseif (!$dashboardTiles): ?>
                    <div class="rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-slate-500">
                        هیچ بەشێکی پشتگیریکراو لە داتابەیس نەدۆزرایەوە.
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-3 gap-x-3 gap-y-5 sm:grid-cols-4 sm:gap-x-5 sm:gap-y-7 lg:grid-cols-5 xl:grid-cols-6">
                        <?php foreach ($dashboardTiles as $tile): ?>
                            <a href="<?= dashboard_escape($tile['href']) ?>" class="group flex flex-col items-center gap-2 rounded-2xl p-2 text-center transition hover:bg-white hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-<?= dashboard_escape($tile['color']) ?>-300">
                                <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-<?= dashboard_escape($tile['color']) ?>-100 text-2xl text-<?= dashboard_escape($tile['color']) ?>-700 transition group-hover:-translate-y-0.5 sm:h-20 sm:w-20 sm:text-3xl">
                                    <i class="fa-solid <?= dashboard_escape($tile['icon']) ?>" aria-hidden="true"></i>
                                </span>
                                <span class="max-w-full break-words text-sm font-semibold text-slate-700"><?= dashboard_escape($tile['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($section === 'profit'): ?>
            <section class="mb-8" aria-labelledby="profit-section-title">
                <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                    <div>
                    <h2 id="profit-section-title" class="text-xl font-bold text-slate-800">قازانج</h2>
                    <p class="mt-1 text-sm text-slate-500">ئامارە سەرەکییەکان، فرۆشتن و خەرجی، و ڕەوتی قازانجی مانگی ئێستا.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="exchange_rates.php" class="rounded-lg border border-amber-200 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-50">
                            نوێکردنەوەی نرخی ئاڵوگۆڕ
                        </a>
                        <a href="dashboard.php" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50">
                            گەڕانەوە بۆ داشبۆرد
                        </a>
                    </div>
                </div>

            <!-- Stat Cards (4 قەفەسی سەرەکی) -->
            <div class="dashboard-statistics grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                
                <!-- 1. کۆی کاڵاکان -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">کۆی گشتی کاڵاکان</p>
                        <h3 class="text-2xl font-bold text-gray-800 mt-1"><?php echo number_format($total_products); ?></h3>
                    </div>
                    <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center text-xl" aria-hidden="true"><i class="fa-solid fa-boxes-stacked"></i></div>
                </div>

                <!-- 2. کۆی قەرزی کڕیاران -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">کۆی قەرزی کڕیاران</p>
                        <h3 class="text-2xl font-bold text-red-600 mt-1">$<?php echo number_format($total_debts, 2); ?></h3>
                    </div>
                    <div class="w-12 h-12 bg-red-50 text-red-600 rounded-xl flex items-center justify-center text-xl" aria-hidden="true"><i class="fa-solid fa-credit-card"></i></div>
                </div>

                <!-- 3. فرۆشتنی ئەمڕۆ -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">فرۆشتنی ئەمڕۆ</p>
                        <h3 class="mt-1 text-lg font-bold text-green-600">
                            $<?php echo number_format($today_sales['USD'], 2); ?><br>
                            <?php echo number_format($today_sales['IQD']); ?> د.ع
                        </h3>
                    </div>
                    <div class="w-12 h-12 bg-green-50 text-green-600 rounded-xl flex items-center justify-center text-xl" aria-hidden="true"><i class="fa-solid fa-sack-dollar"></i></div>
                </div>

                <!-- 4. خەرجیی ئەمڕۆ -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">خەرجیی ئەمڕۆ</p>
                        <h3 class="text-2xl font-bold text-amber-600 mt-1">$<?php echo number_format($today_expenses, 2); ?></h3>
                    </div>
                    <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-xl flex items-center justify-center text-xl" aria-hidden="true"><i class="fa-solid fa-receipt"></i></div>
                </div>

            </div>

            <section class="dashboard-charts mb-8" aria-labelledby="dashboard-charts-title">
                <div class="mb-4">
                    <h2 id="dashboard-charts-title" class="text-xl font-bold text-slate-800">بەشی چارت: فرۆشتن و خەرجییەکانی مانگی ئێستا</h2>
                    <p class="mt-1 text-sm text-slate-500">دۆلار و دینار لە چارتی هێڵی جیاکراونەتەوە؛ چارتی بازنەیی تەنها دۆلار بەراورد دەکات. نرخی کڕینی EUR بە ڕێژەی EUR لە خشتەی ئاڵوگۆڕەوە دەگۆڕدرێت بۆ USD؛ فرۆشتنی دینار لە قازانجدا نییە.</p>
                </div>
                <?php if ($chart_error_message !== ''): ?>
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800" role="alert">
                        <?= dashboard_escape($chart_error_message) ?>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-base font-bold text-slate-800">ڕەوتی ڕۆژانە</h3>
                            <div class="relative h-72">
                                <canvas id="daily-sales-chart" role="img" aria-label="چارتی فرۆشتن و خەرجیی ڕۆژانە"></canvas>
                            </div>
                        </section>
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-base font-bold text-slate-800">کۆی فرۆشتن بەرامبەر خەرجی (دۆلار)</h3>
                            <div class="relative h-72">
                                <canvas id="sales-expenses-chart" role="img" aria-label="چارەی بەراوردی فرۆشتن و خەرجی بە دۆلار"></canvas>
                            </div>
                        </section>
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="mb-4 text-base font-bold text-slate-800">١٠ کاڵای زۆرترین فرۆشراو لەم مانگەدا</h3>
                            <div class="relative h-72">
                                <canvas id="top-selling-products-chart" role="img" aria-label="چارتی بڕی فرۆشتنی ١٠ کاڵای سەرەکی"></canvas>
                            </div>
                        </section>
                        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                            <h3 class="mb-2 text-base font-bold text-slate-800">قازانجی خالصی ڕۆژانە (دۆلار)</h3>
                            <p class="mb-4 text-xs text-slate-500">فرۆشتنی دۆلار − نرخی کڕینی کاڵا بە USD − خەرجی. نرخی EUR بە ڕێژەی ئێستای خشتەی ئاڵوگۆڕ دەگۆڕدرێت.</p>
                            <div class="relative h-72">
                                <canvas id="net-profit-chart" role="img" aria-label="چارتی قازانجی خالصی ڕۆژانە بە دۆلار"></canvas>
                            </div>
                            <?php if ($missing_purchase_cost_quantity > 0): ?>
                                <p class="mt-3 text-xs text-amber-700" role="note">ئاگاداری: بۆ <?php echo number_format($missing_purchase_cost_quantity); ?> دانە کاڵای فرۆشراو نرخی کڕینی USD نییە و نرخی EURی گونجاویش بەردەست نییە، بۆیە قازانجەکە خەمڵێنراوە.</p>
                            <?php endif; ?>
                        </section>
                    </div>
                <?php endif; ?>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" aria-labelledby="product-profit-title">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h3 id="product-profit-title" class="text-lg font-bold text-slate-800">قازانجی هەر دانەی کاڵاکانی کۆگا</h3>
                    <p class="mt-1 text-sm text-slate-500">قازانجی دانەیی = نرخی فرۆشتنی USD − نرخی کڕین بە USD. نرخی کڕینی EUR بە ڕێژەی EURی ئێستا لە خشتەی ئاڵوگۆڕەوە دەگۆڕدرێت؛ خەرجییە گشتییەکان لێی کەم نەکراونەتەوە.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-semibold">کاڵا</th>
                                <th scope="col" class="px-5 py-3 font-semibold">بڕی کۆگا</th>
                                <th scope="col" class="px-5 py-3 font-semibold">نرخی کڕینی دانە ($)</th>
                                <th scope="col" class="px-5 py-3 font-semibold">نرخی فرۆشتنی دانە ($)</th>
                                <th scope="col" class="px-5 py-3 font-semibold">قازانجی هەر دانە ($)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php if ($product_profit_rows): ?>
                                <?php foreach ($product_profit_rows as $productProfit): ?>
                                    <?php
                                    $unitProfit = $productProfit['unit_profit_usd'] === null
                                        ? null
                                        : (float) $productProfit['unit_profit_usd'];
                                    $profitColor = $unitProfit === null
                                        ? 'text-slate-500'
                                        : ($unitProfit < 0 ? 'text-red-600' : 'text-emerald-700');
                                    ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-3 font-medium text-slate-800"><?= dashboard_escape((string) $productProfit['product_name']) ?></td>
                                        <td class="px-5 py-3 text-slate-700"><?= number_format((int) $productProfit['stock_quantity']) ?></td>
                                        <td class="whitespace-nowrap px-5 py-3 text-slate-700">
                                            <?php if ($productProfit['purchase_price_usd'] !== null): ?>
                                                $<?= number_format((float) $productProfit['purchase_price_usd'], 2) ?>
                                            <?php elseif ($productProfit['purchase_price_eur'] !== null && $productProfit['eur_to_usd_rate'] !== null): ?>
                                                $<?= number_format((float) $productProfit['purchase_price_eur'] * (float) $productProfit['eur_to_usd_rate'], 2) ?>
                                                <span class="block text-xs text-slate-500">لە EUR بە ڕێژەی ئێستا</span>
                                            <?php elseif ($productProfit['purchase_price_eur'] !== null): ?>
                                                <span class="block text-amber-700">نرخی EUR بۆ USD نییە</span>
                                                <a href="exchange_rates.php#eurRatePanel" class="mt-1 inline-block text-xs font-semibold text-cyan-800 hover:text-cyan-950">
                                                    دانانی نرخی ئاڵوگۆڕی EUR
                                                </a>
                                            <?php else: ?>
                                                <span class="block text-amber-700">نرخی کڕینی USD نییە</span>
                                                <a href="products.php?edit=<?= (int) $productProfit['product_id'] ?>&amp;return_to=dashboard.php%3Fsection%3Dprofit%23product-profit-title" class="mt-1 inline-block text-xs font-semibold text-cyan-800 hover:text-cyan-950">
                                                    تۆمارکردنی نرخی USD
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-3 text-slate-700">
                                            <?= $productProfit['sale_price_usd'] !== null ? '$' . number_format((float) $productProfit['sale_price_usd'], 2) : 'نرخی فرۆشتن نییە' ?>
                                        </td>
                                        <td class="whitespace-nowrap px-5 py-3 font-semibold <?= $profitColor ?>">
                                            <?= $unitProfit !== null ? '$' . number_format($unitProfit, 2) : 'ناژمێردراوە' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="px-5 py-8 text-center text-slate-500">هیچ کاڵایەک بە بڕی زیاتر لە سفر لە کۆگادا نییە.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm" aria-labelledby="total-goods-profit-title">
                <h3 id="total-goods-profit-title" class="text-lg font-bold text-slate-800">کۆی گشتی قازانجی کاڵا</h3>
                <p class="mt-1 text-sm text-slate-600">تەنها قازانجی فرۆشتنە تۆمارکراوەکان دوای داشکاندن حساب دەکرێت؛ قازانجی کاڵای هێشتا لە کۆگا لەم کۆیەدا نییە. کۆیەکە بە دۆلارە و خەرجییە گشتییەکان لێی کەم نەکراونەتەوە.</p>
                <div class="mt-4 max-w-md rounded-xl bg-white p-4">
                    <p class="text-sm font-semibold text-emerald-800">کۆی قازانجی فرۆشتنی کاڵاکان</p>
                    <p class="mt-1 text-2xl font-extrabold text-emerald-700">$<?= number_format($total_goods_profit_usd, 2) ?></p>
                </div>
                <?php if ($sales_missing_purchase_cost_quantity > 0): ?>
                    <p class="mt-3 text-sm text-amber-800" role="note">
                        ئاگاداری: قازانجی <?= number_format($sales_missing_purchase_cost_quantity) ?> دانەی فرۆشراو بەهۆی نەبوونی نرخی کڕین یان نرخی گونجاوی ئاڵوگۆڕ ناتوانرێت دیاری بکرێت؛ کۆی سەرەوە تەنها بڕەکانی هەژمارکراو پیشان دەدات.
                    </p>
                <?php endif; ?>
            </section>

            <?php if ($chart_error_message === ''): ?>
                <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                <script>
                    const dashboardChartData = <?= $chart_data_json ?>;
                    const dailySalesCanvas = document.getElementById('daily-sales-chart');
                    const salesExpensesCanvas = document.getElementById('sales-expenses-chart');
                    const topSellingProductsCanvas = document.getElementById('top-selling-products-chart');
                    const netProfitCanvas = document.getElementById('net-profit-chart');

                    if (dailySalesCanvas) {
                        new Chart(dailySalesCanvas, {
                            type: 'line',
                            data: {
                                labels: dashboardChartData.labels,
                                datasets: [
                                    {
                                        label: 'فرۆشتنی دۆلار',
                                        data: dashboardChartData.salesUsd,
                                        yAxisID: 'usdAxis',
                                        borderColor: '#059669',
                                        backgroundColor: 'rgba(5, 150, 105, 0.12)',
                                        pointBackgroundColor: '#059669',
                                        tension: 0.3,
                                        fill: true
                                    },
                                    {
                                        label: 'فرۆشتنی دینار',
                                        data: dashboardChartData.salesIqd,
                                        yAxisID: 'iqdAxis',
                                        borderColor: '#d97706',
                                        backgroundColor: 'rgba(217, 119, 6, 0.08)',
                                        pointBackgroundColor: '#d97706',
                                        tension: 0.3,
                                        fill: false
                                    },
                                    {
                                        label: 'خەرجی بە دۆلار',
                                        data: dashboardChartData.expenses,
                                        yAxisID: 'usdAxis',
                                        borderColor: '#e11d48',
                                        backgroundColor: 'rgba(225, 29, 72, 0.08)',
                                        pointBackgroundColor: '#e11d48',
                                        borderDash: [6, 4],
                                        tension: 0.3,
                                        fill: false
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        rtl: true,
                                        textDirection: 'rtl',
                                        labels: { usePointStyle: true, padding: 18 }
                                    }
                                },
                                scales: {
                                    x: {
                                        title: { display: true, text: 'ڕۆژی مانگ' },
                                        ticks: { textDirection: 'rtl' }
                                    },
                                    usdAxis: {
                                        type: 'linear',
                                        position: 'left',
                                        beginAtZero: true,
                                        title: { display: true, text: 'دۆلار' },
                                        ticks: {
                                            callback: function (value) {
                                                return '$' + Number(value).toLocaleString();
                                            }
                                        }
                                    },
                                    iqdAxis: {
                                        type: 'linear',
                                        position: 'right',
                                        beginAtZero: true,
                                        title: { display: true, text: 'دینار' },
                                        grid: { drawOnChartArea: false },
                                        ticks: {
                                            callback: function (value) {
                                                return Number(value).toLocaleString() + ' د.ع';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    if (salesExpensesCanvas) {
                        new Chart(salesExpensesCanvas, {
                            type: 'doughnut',
                            data: {
                                labels: ['فرۆشتن بە دۆلار', 'خەرجی بە دۆلار'],
                                datasets: [{
                                    data: [dashboardChartData.monthlySalesUsd, dashboardChartData.monthlyExpenses],
                                    backgroundColor: ['#059669', '#e11d48'],
                                    borderColor: ['#ffffff', '#ffffff'],
                                    borderWidth: 3,
                                    hoverOffset: 8
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                cutout: '62%',
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        rtl: true,
                                        textDirection: 'rtl',
                                        labels: { usePointStyle: true, padding: 18 }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function (context) {
                                                return context.label + ': $' + Number(context.raw).toLocaleString(undefined, {
                                                    minimumFractionDigits: 2,
                                                    maximumFractionDigits: 2
                                                });
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    if (topSellingProductsCanvas) {
                        new Chart(topSellingProductsCanvas, {
                            type: 'bar',
                            data: {
                                labels: dashboardChartData.topProductNames,
                                datasets: [{
                                    label: 'بڕی فرۆشراو',
                                    data: dashboardChartData.topProductQuantities,
                                    backgroundColor: '#7c3aed',
                                    borderRadius: 6
                                }]
                            },
                            options: {
                                indexAxis: 'y',
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: function (context) {
                                                return ' ' + Number(context.raw).toLocaleString() + ' دانە';
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        beginAtZero: true,
                                        ticks: { precision: 0 },
                                        title: { display: true, text: 'بڕی فرۆشراو (دانە)' }
                                    },
                                    y: { ticks: { textDirection: 'rtl' } }
                                }
                            }
                        });
                    }

                    if (netProfitCanvas) {
                        new Chart(netProfitCanvas, {
                            type: 'line',
                            data: {
                                labels: dashboardChartData.labels,
                                datasets: [{
                                    label: 'قازانجی خالصی دۆلار',
                                    data: dashboardChartData.netProfitUsd,
                                    borderColor: '#7c3aed',
                                    backgroundColor: 'rgba(124, 58, 237, 0.12)',
                                    pointBackgroundColor: '#7c3aed',
                                    tension: 0.3,
                                    fill: true
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                interaction: { mode: 'index', intersect: false },
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        rtl: true,
                                        textDirection: 'rtl',
                                        labels: { usePointStyle: true, padding: 18 }
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function (context) {
                                                return context.dataset.label + ': $' + Number(context.raw).toLocaleString(undefined, {
                                                    minimumFractionDigits: 2,
                                                    maximumFractionDigits: 2
                                                });
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        title: { display: true, text: 'ڕۆژی مانگ' },
                                        ticks: { textDirection: 'rtl' }
                                    },
                                    y: {
                                        beginAtZero: false,
                                        title: { display: true, text: 'قازانجی خالص (دۆلار)' },
                                        ticks: {
                                            callback: function (value) {
                                                return '$' + Number(value).toLocaleString();
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }
                </script>
            <?php endif; ?>
            </section>
            <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>
</div>

</body>
</html>