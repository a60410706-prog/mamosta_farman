<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'], $_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'cashier'], true)) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/store_settings.php';

try {
    $store_settings = load_store_settings();
} catch (RuntimeException $e) {
    error_log('Loading store settings for receipt failed: ' . $e->getMessage());
    $store_settings = store_settings_defaults();
}

$sale_id = filter_input(INPUT_GET, 'sale_id', FILTER_VALIDATE_INT);
if ($sale_id === false || $sale_id === null || $sale_id < 1) {
    http_response_code(400);
    exit('ژمارەی پسوولە دروست نییە.');
}

try {
    $sale_sql = 'SELECT s.sale_id, s.user_id, s.total_amount, s.discount, s.net_amount, s.currency,
                        s.paid_amount, s.remaining_amount, s.payment_method, s.sale_date,
                        u.full_name AS cashier_name, c.customer_name, c.address AS customer_address,
                        c.phone AS customer_phone
                 FROM sales AS s
                 INNER JOIN users AS u ON u.user_id = s.user_id
                 LEFT JOIN customers AS c ON c.customer_id = s.customer_id
                 WHERE s.sale_id = :sale_id';
    if ($_SESSION['role'] !== 'admin') {
        $sale_sql .= ' AND s.user_id = :user_id';
    }

    $sale_stmt = $pdo->prepare($sale_sql);
    $sale_parameters = [':sale_id' => $sale_id];
    if ($_SESSION['role'] !== 'admin') {
        $sale_parameters[':user_id'] = (int) $_SESSION['user_id'];
    }
    $sale_stmt->execute($sale_parameters);
    $sale = $sale_stmt->fetch();

    if (!$sale) {
        http_response_code(404);
        exit('پسوولەکە نەدۆزرایەوە.');
    }

    $items_stmt = $pdo->prepare(
        'SELECT si.quantity, si.unit_price, si.total_price, p.product_name, p.barcode
         FROM sale_items AS si
         INNER JOIN products AS p ON p.product_id = si.product_id
         WHERE si.sale_id = :sale_id
         ORDER BY si.item_id ASC'
    );
    $items_stmt->execute([':sale_id' => $sale_id]);
    $items = $items_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Loading receipt failed: ' . $e->getMessage());
    http_response_code(500);
    exit('هەڵەیەک لە بارکردنی پسوولەکەدا ڕوویدا.');
}

$payment_methods = [
    'cash' => 'نەقد',
    'fastpay' => 'فاست‌پەی',
    'card' => 'کارت',
    'debt' => 'قەرز',
];
$payment_label = $payment_methods[$sale['payment_method'] ?? ''] ?? 'نادیار';
$receiptDate = new DateTimeImmutable('today');
$auto_print = ($_GET['print'] ?? '') === '1';
$savedExchangeRate = $_SESSION['pos_usd_iqd_rates'][$sale_id] ?? null;
$receiptExchangeRate = is_numeric($savedExchangeRate) && is_finite((float) $savedExchangeRate) && (float) $savedExchangeRate > 0
    ? (float) $savedExchangeRate
    : null;
$receiptStoreAddress = $store_settings['store_address'];
$receiptStorePhoneLines = preg_split('/\R/u', trim($store_settings['store_phone'])) ?: [];
$receiptStoreOwner = trim($store_settings['store_owner']);
if ($receiptStoreOwner === '' && isset($receiptStorePhoneLines[0])
    && preg_match('/^\s*خاوەن\s*[:：]\s*(.+)$/u', $receiptStorePhoneLines[0], $ownerMatch)
) {
    $receiptStoreOwner = trim($ownerMatch[1]);
    array_shift($receiptStorePhoneLines);
}
$receiptStorePhone = implode("\n", array_map('trim', $receiptStorePhoneLines));
$page_title = 'پسووڵەی فرۆشتن #' . $sale_id;

function receipt_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function receipt_iqd(float $usdAmount, float $exchangeRate): string
{
    return number_format(round(($usdAmount * $exchangeRate * 10) / 250) * 250) . ' د.ع';
}

$saleCurrency = ($sale['currency'] ?? 'USD') === 'IQD' ? 'IQD' : 'USD';
function receipt_money(float $amount, string $currency): string
{
    return $currency === 'IQD'
        ? number_format($amount) . ' د.ع'
        : '$' . number_format($amount, 2);
}

?>
<!DOCTYPE html>
<html lang="ckb" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo receipt_escape($page_title); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Naskh+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 12px;
            background: #f1f5f9;
            color: #111827;
            font-family: 'Noto Naskh Arabic', 'Vazirmatn', Tahoma, sans-serif;
        }
        .receipt {
            width: 210mm;
            max-width: 100%;
            margin: 0 auto;
            min-height: 297mm;
            padding: 18mm;
            background: #fff;
            box-shadow: 0 4px 20px rgb(15 23 42 / 10%);
            font-size: 14px;
            line-height: 1.7;
        }
        .center { text-align: center; }
        .store-header { display: grid; grid-template-columns: minmax(0, 1fr) 24mm minmax(0, 1fr); align-items: center; gap: 10px; direction: rtl; }
        .store-logo-block { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; min-width: 0; }
        .store-logo-title { font-size: 13px; font-weight: 700; line-height: 1.3; text-align: center; white-space: nowrap; }
        .store-header-side { min-width: 0; margin: 0; color: #374151; font-size: 12px; line-height: 1.5; white-space: pre-line; overflow-wrap: anywhere; }
        .store-header-right { text-align: right; font-size: 16px; font-weight: 700; }
        .store-header-left { text-align: left; }
        .store-logo { display: block; width: 22mm; height: 22mm; margin: 0 auto; object-fit: contain; }
        .store-owner { margin: 6px 0 0; font-size: 18px; font-weight: 700; white-space: pre-line; overflow-wrap: anywhere; }
        .store-phone { direction: ltr; font-size: 16px; font-weight: 700; white-space: pre-line; overflow-wrap: anywhere; }
        .muted { color: #4b5563; }
        .rule { margin: 8px 0; border: 0; border-top: 1px dashed #6b7280; }
        .header-rule { border-top: 2mm solid #000; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; direction: ltr; }
        .meta-column { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; direction: rtl; }
        .meta-column:first-child { grid-column: 1; display: flex; flex-direction: column; align-items: flex-start; direction: ltr; }
        .meta-column:last-child { grid-column: 2; grid-template-columns: 1fr; }
        .meta-column:last-child p { flex-direction: row; justify-content: flex-start; gap: 5px; }
        .meta p { display: flex; flex-direction: column; justify-content: flex-start; gap: 2px; margin: 3px 0; }
        .meta-column:first-child p { align-items: flex-start; text-align: left; }
        .meta-receipt-number { flex-direction: row !important; gap: 5px !important; direction: rtl; }
        .meta-column:first-child p { flex-direction: row; gap: 4px; white-space: nowrap; }
        .meta-phone { direction: ltr; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 4px 5px; text-align: right; vertical-align: top; overflow-wrap: anywhere; }
        th:not(:last-child), td:not(:last-child) { border-left: 1px solid #6b7280; }
        thead { border-bottom: 1px dashed #6b7280; }
        tbody tr + tr { border-top: 1px solid #6b7280; }
        th { font-weight: 600; }
        .item-code { width: 15%; text-align: center; direction: ltr; white-space: nowrap; }
        .item-name { width: 25%; text-align: center; }
        .item-qty { width: 28%; text-align: center; }
        .item-price { width: 16%; text-align: center; }
        .item-total { width: 16%; text-align: center; }
        .summary p { display: flex; justify-content: space-between; gap: 8px; margin: 4px 0; }
        .grand-total { font-size: 14px; font-weight: 700; }
        .receipt-footer-details { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-top: 24px; direction: ltr; }
        .receipt-signature, .receipt-signature-note { width: 45%; min-width: 55mm; margin: 0; }
        .receipt-signature { text-align: left; }
        .receipt-signature-note { text-align: right; direction: rtl; }
        .actions { display: flex; justify-content: center; gap: 8px; margin: 18px auto 0; }
        .actions a, .actions button {
            padding: 9px 14px;
            border: 0;
            border-radius: 7px;
            background: #0f172a;
            color: #fff;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
        }
        .actions a { background: #d97706; }
        @page { size: A4 portrait; margin: 12mm; }
        @media print {
            html, body { width: auto; min-height: 0; margin: 0; padding: 0; background: #fff; }
            .receipt {
                width: auto;
                min-height: 0;
                max-width: none;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
            .actions { display: none !important; }
        }
        @media screen and (max-width: 560px) {
            .store-header { grid-template-columns: minmax(0, 1fr) 18mm minmax(0, 1fr); gap: 5px; }
            .store-header-side { font-size: 10px; }
            .store-logo { width: 17mm; height: 17mm; }
            .receipt-signature, .receipt-signature-note { width: 50%; min-width: 0; }
        }
    </style>
</head>
<body>
    <main>
        <article class="receipt">
            <header class="store-header">
                <?php
                $storeLogoPath = $store_settings['store_logo'] ?? '';
                $hasStoreLogo = is_string($storeLogoPath)
                    && preg_match('~\Auploads/store-logo/[a-f0-9]{32}\.(?:jpg|png|webp)\z~D', $storeLogoPath)
                    && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storeLogoPath));
                ?>
                <div class="store-header-side store-header-right"><?= receipt_escape($receiptStoreAddress) ?></div>
                <div class="store-logo-block">
                    <span class="store-logo-title">MAMOSTA FARMAN</span>
                    <?php if ($hasStoreLogo): ?>
                        <img class="store-logo" src="<?php echo receipt_escape($storeLogoPath); ?>" alt="<?php echo receipt_escape($store_settings['store_name']); ?>">
                    <?php else: ?>
                        <span class="store-logo" aria-hidden="true"></span>
                    <?php endif; ?>
                </div>
                <div class="store-header-side store-header-left" dir="rtl">
                    <?php if ($receiptStoreOwner !== ''): ?>
                        <div class="store-owner"><?= receipt_escape($receiptStoreOwner) ?></div>
                    <?php endif; ?>
                    <?php if ($receiptStorePhone !== ''): ?>
                        <div class="store-phone" dir="ltr"><?= receipt_escape($receiptStorePhone) ?></div>
                    <?php endif; ?>
                </div>
            </header>
            <hr class="rule header-rule">
            <section class="meta">
                <div class="meta-column">
                    <p class="meta-receipt-number"><span>بەروار</span><span><?php echo receipt_escape($receiptDate->format('d/m/Y')); ?></span></p>
                    <p class="meta-receipt-number"><span>ژ.پسوڵە</span><strong>#<?php echo (int) $sale['sale_id']; ?></strong></p>
                </div>
                <div class="meta-column">
                    <p><span>بەڕێز:</span><span><?php echo !empty($sale['customer_name']) ? receipt_escape($sale['customer_name']) : '—'; ?></span></p>
                    <p><span>ناونیشان:</span><span><?php echo !empty($sale['customer_address']) ? receipt_escape($sale['customer_address']) : '—'; ?></span></p>
                    <p><span>ژ.مۆبایل:</span><span class="meta-phone" dir="ltr"><?php echo !empty($sale['customer_phone']) ? receipt_escape($sale['customer_phone']) : '—'; ?></span></p>
                </div>
            </section>

            <hr class="rule">
            <table>
                <thead>
                    <tr>
                        <th class="item-code">کۆد</th>
                        <th class="item-name">کاڵا</th>
                        <th class="item-qty">دانە/دەرزەن</th>
                        <th class="item-price">نرخ</th>
                        <th class="item-total">کۆی نرخ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $itemQuantity = (int) $item['quantity'];
                        $itemDozens = intdiv($itemQuantity, 12);
                        $itemRemainingAfterDozens = $itemQuantity % 12;
                        $itemHalfDozens = intdiv($itemRemainingAfterDozens, 6);
                        $itemRemainingUnits = $itemRemainingAfterDozens % 6;
                        $displayDozens = $itemDozens + ($itemHalfDozens * 0.5);
                        ?>
                        <tr>
                            <td class="item-code"><?php echo !empty($item['barcode']) ? receipt_escape((string) preg_replace('/\s+/u', '', (string) $item['barcode'])) : '—'; ?></td>
                            <td class="item-name"><?php echo receipt_escape($item['product_name']); ?></td>
                            <td class="item-qty">
                                <?php if ($displayDozens > 0): ?>
                                    <?php echo number_format($displayDozens, $itemHalfDozens > 0 ? 1 : 0, '.', ''); ?> دەرزەن
                                <?php endif; ?>
                                <?php if ($itemRemainingUnits > 0 && $displayDozens > 0): ?>
                                    و <?php echo number_format($itemRemainingUnits); ?> دانە
                                <?php elseif ($displayDozens === 0.0): ?>
                                    <?php echo number_format($itemQuantity); ?> دانە
                                <?php endif; ?>
                            </td>
                            <td class="item-price"><?php echo receipt_escape(receipt_money((float) $item['unit_price'], $saleCurrency)); ?></td>
                            <td class="item-total"><?php echo receipt_escape(receipt_money((float) $item['total_price'], $saleCurrency)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <section class="summary">
                <?php if ($saleCurrency === 'USD' && $receiptExchangeRate !== null): ?>
                    <p>
                        <span>نرخی گۆڕین</span>
                        <span>$100 = <?php echo number_format($receiptExchangeRate * 1000); ?> د.ع</span>
                    </p>
                <?php endif; ?>
                <p>
                    <span>کۆی کاڵاکان</span>
                    <span>
                        <?php echo receipt_escape(receipt_money((float) $sale['total_amount'], $saleCurrency)); ?>
                        <?php if ($saleCurrency === 'USD' && $receiptExchangeRate !== null): ?>
                            <br><span class="muted"><?php echo receipt_iqd((float) $sale['total_amount'], $receiptExchangeRate); ?></span>
                        <?php endif; ?>
                    </span>
                </p>
                <?php if ((float) $sale['discount'] > 0): ?>
                    <p>
                        <span>داشکاندن</span>
                        <span>
                            <?php echo receipt_escape(receipt_money((float) $sale['discount'], $saleCurrency)); ?>
                            <?php if ($saleCurrency === 'USD' && $receiptExchangeRate !== null): ?>
                                <br><span class="muted"><?php echo receipt_iqd((float) $sale['discount'], $receiptExchangeRate); ?></span>
                            <?php endif; ?>
                        </span>
                    </p>
                <?php endif; ?>
                <p class="grand-total">
                    <span>کۆی گشتی</span>
                    <span>
                        <?php echo receipt_escape(receipt_money((float) $sale['net_amount'], $saleCurrency)); ?>
                        <?php if ($saleCurrency === 'USD' && $receiptExchangeRate !== null): ?>
                            <br><span class="muted"><?php echo receipt_iqd((float) $sale['net_amount'], $receiptExchangeRate); ?></span>
                        <?php endif; ?>
                    </span>
                </p>
                <p><span>شێوازی پارەدان</span><span><?php echo receipt_escape($payment_label); ?></span></p>
                <p>
                    <span>پارەی دراو</span>
                    <span>
                        <?php echo receipt_escape(receipt_money((float) $sale['paid_amount'], $saleCurrency)); ?>
                        <?php if ($saleCurrency === 'USD' && $receiptExchangeRate !== null): ?>
                            <br><span class="muted"><?php echo receipt_iqd((float) $sale['paid_amount'], $receiptExchangeRate); ?></span>
                        <?php endif; ?>
                    </span>
                </p>
                <?php if ((float) $sale['remaining_amount'] > 0): ?>
                    <p>
                        <span>ماوە</span>
                        <span>
                            <?php echo receipt_escape(receipt_money((float) $sale['remaining_amount'], $saleCurrency)); ?>
                            <?php if ($saleCurrency === 'USD' && $receiptExchangeRate !== null): ?>
                                <br><span class="muted"><?php echo receipt_iqd((float) $sale['remaining_amount'], $receiptExchangeRate); ?></span>
                            <?php endif; ?>
                        </span>
                    </p>
                <?php endif; ?>
            </section>

            <hr class="rule">
            <footer class="center">
                <strong>سوپاس بۆ متمانەتان</strong>
                <div class="receipt-footer-details">
                    <div class="receipt-signature">واژۆ</div>
                    <div class="muted receipt-signature-note">هەڵە دەگەرێتەوە بۆ هەردوولا</div>
                </div>
            </footer>
        </article>
        <nav class="actions" aria-label="کردارەکانی پسوولە">
            <button type="button" onclick="window.print()">چاپکردنی پسوولە</button>
            <a href="pos.php">فرۆشتنی نوێ</a>
        </nav>
    </main>
    <?php if ($auto_print): ?>
        <script>
            window.addEventListener('load', function () {
                window.setTimeout(function () {
                    window.addEventListener('afterprint', function () {
                        if (window.parent !== window) {
                            window.parent.postMessage('sale-receipt-printed', window.location.origin);
                        }
                    }, { once: true });
                    window.print();
                }, 300);
            });
        </script>
    <?php endif; ?>
</body>
</html>
