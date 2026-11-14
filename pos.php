<?php
session_start();

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'cashier'], true)) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

if (!$pdo instanceof PDO) {
    throw new RuntimeException('پەیوەندی PDO لە includes/db.php بەردەست نییە.');
}

if (empty($_SESSION['pos_csrf_token'])) {
    $_SESSION['pos_csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['pos_cart']) || !is_array($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
}
if (!isset($_SESSION['pos_cart_prices']) || !is_array($_SESSION['pos_cart_prices'])) {
    $_SESSION['pos_cart_prices'] = [];
}
$posCurrency = $_SESSION['pos_currency'] ?? 'USD';
if (!in_array($posCurrency, ['USD', 'IQD'], true)) {
    $posCurrency = 'USD';
    $_SESSION['pos_currency'] = $posCurrency;
}
$isIqdSale = $posCurrency === 'IQD';
$moneyPattern = $isIqdSale ? '/^\d+$/D' : '/^\d+(?:\.\d{1,2})?$/D';
$moneyStep = $isIqdSale ? '1' : '0.01';
$printSaleId = filter_var($_SESSION['pos_print_sale_id'] ?? null, FILTER_VALIDATE_INT);
unset($_SESSION['pos_print_sale_id']);
if ($printSaleId === false || $printSaleId < 1) {
    $printSaleId = null;
}

function pos_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pos_money(float $amount, string $currency): string
{
    return $currency === 'IQD'
        ? number_format($amount, 0) . ' د.ع'
        : '$' . number_format($amount, 2);
}

$errorMessage = '';
$successMessage = $_SESSION['pos_success'] ?? '';
$checkoutCustomerId = '';
$checkoutCustomerName = '';
$checkoutCustomerAddress = '';
$checkoutCustomerPhone = '';
$checkoutDiscountPercent = '0';
$checkoutNetAmount = '';
$checkoutRemainingAmount = $isIqdSale ? '0' : '0.00';
$checkoutExchangeRate = is_string($_SESSION['pos_usd_iqd_rate'] ?? null)
    ? $_SESSION['pos_usd_iqd_rate']
    : '';
unset($_SESSION['pos_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['pos_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif ($action === 'set_currency') {
        $requestedCurrency = $_POST['currency'] ?? '';
        if (!is_string($requestedCurrency) || !in_array($requestedCurrency, ['USD', 'IQD'], true)) {
            $errorMessage = 'دراوی فرۆشتن دروست نییە.';
        } elseif ($_SESSION['pos_cart'] && $requestedCurrency !== $posCurrency) {
            $errorMessage = 'بۆ گۆڕینی دراو، سەرەتا پسووڵەکە تەواو بکە یان پاکی بکەرەوە.';
        } else {
            $_SESSION['pos_currency'] = $requestedCurrency;
            header('Location: pos.php');
            exit;
        }
    } elseif ($action === 'add') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $barcodeInput = $_POST['barcode'] ?? '';

        try {
            if ($productId && $productId > 0) {
                $stmt = $pdo->prepare(
                    'SELECT product_id, product_name, stock_quantity
                     FROM products WHERE product_id = :product_id LIMIT 1'
                );
                $stmt->execute(['product_id' => $productId]);
            } elseif (is_string($barcodeInput) && trim($barcodeInput) !== '') {
                $stmt = $pdo->prepare(
                    'SELECT product_id, product_name, stock_quantity
                     FROM products WHERE barcode = :barcode LIMIT 1'
                );
                $stmt->execute(['barcode' => trim($barcodeInput)]);
            } else {
                $stmt = null;
                $errorMessage = 'بارکۆد یان کاڵایەک هەڵبژێرە.';
            }

            if ($stmt !== null) {
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    $errorMessage = 'کاڵاکە بەو بارکۆدە نەدۆزرایەوە.';
                } elseif ((int) $product['stock_quantity'] < 1) {
                    $errorMessage = 'کاڵاکە لە کۆگادا نەماوە.';
                } else {
                    $id = (int) $product['product_id'];
                    $currentQuantity = (int) ($_SESSION['pos_cart'][$id] ?? 0);

                    if ($currentQuantity + 1 > (int) $product['stock_quantity']) {
                        $errorMessage = 'بڕی داواکراو لە بڕی بەردەستی کۆگا زیاترە.';
                    } else {
                        $_SESSION['pos_cart'][$id] = $currentQuantity + 1;
                        header('Location: pos.php');
                        exit;
                    }
                }
            }
        } catch (PDOException $exception) {
            error_log('Adding POS item failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە زیادکردنی کاڵاکەدا ڕوویدا.';
        }
    } elseif ($action === 'remove') {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        if ($productId && $productId > 0) {
            unset($_SESSION['pos_cart'][$productId]);
            unset($_SESSION['pos_cart_prices'][$productId]);
        }
        header('Location: pos.php');
        exit;
    } elseif (in_array($action, ['change_quantity', 'add_dozen', 'add_half_dozen'], true)) {
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantityIncrement = $action === 'add_dozen' ? 12 : 6;
        $isAddingBundle = in_array($action, ['add_dozen', 'add_half_dozen'], true);
        $quantity = $isAddingBundle ? null : filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $unitInput = $_POST['unit'] ?? 'piece';
        $halfDozenInput = $_POST['half_dozen'] ?? '';
        $validQuantityUnit = is_string($unitInput) && in_array($unitInput, ['piece', 'dozen'], true);
        $validHalfDozenInput = is_string($halfDozenInput) && in_array($halfDozenInput, ['', '1'], true);
        $validQuantityForUnit = false;
        if ($quantity !== false && $validQuantityUnit && $validHalfDozenInput) {
            if ($unitInput === 'piece') {
                $validQuantityForUnit = $quantity >= 1 && $halfDozenInput === '';
            } else {
                $validQuantityForUnit = (
                    $quantity === 0 && $halfDozenInput === '1'
                ) || (
                    $quantity >= 12 && $quantity % 12 === 0
                );
            }
        }

        if (!$productId || $productId < 1 || !array_key_exists($productId, $_SESSION['pos_cart'])) {
            $errorMessage = 'کاڵای داواکراو لە پسوولەدا نییە.';
        } elseif (
            !$isAddingBundle
            && (
                !$validQuantityUnit
                || !$validHalfDozenInput
                || !$validQuantityForUnit
            )
        ) {
            $errorMessage = 'بڕی دانە یان دەرزەن دروست نییە.';
        } else {
            try {
                $stmt = $pdo->prepare('SELECT stock_quantity FROM products WHERE product_id = :product_id');
                $stmt->execute(['product_id' => $productId]);
                $stock = $stmt->fetchColumn();

                if ($stock === false) {
                    unset($_SESSION['pos_cart'][$productId]);
                    $errorMessage = 'کاڵاکە نەدۆزرایەوە و لە پسوولە لابرا.';
                } else {
                    if ($isAddingBundle) {
                        $currentQuantity = (int) $_SESSION['pos_cart'][$productId];
                        $quantity = (intdiv($currentQuantity, $quantityIncrement) + 1) * $quantityIncrement;
                    } else {
                        if ($unitInput === 'dozen' && $halfDozenInput === '1') {
                            $quantity += 6;
                        }
                        if ($quantity > (int) $stock) {
                            $errorMessage = 'بڕی داواکراو لە بڕی بەردەستی کۆگا زیاترە.';
                        }
                    }

                    if ($errorMessage === '') {
                        if ($quantity > (int) $stock) {
                            $errorMessage = 'بڕی داواکراو لە بڕی بەردەستی کۆگا زیاترە.';
                        } else {
                            $_SESSION['pos_cart'][$productId] = $quantity;
                            header('Location: pos.php');
                            exit;
                        }
                    }
                }
            } catch (PDOException $exception) {
                error_log('Changing POS quantity failed: ' . $exception->getMessage());
                $errorMessage = 'هەڵەیەک لە گۆڕینی بڕی کاڵاکەدا ڕوویدا.';
            }
        }
    } elseif ($action === 'change_prices') {
        unset($_SESSION['pos_print_sale_id']);
        $printSaleId = null;
        $priceInputs = $_POST['unit_prices'] ?? null;

        if (!$isIqdSale) {
            $errorMessage = 'گۆڕینی یەکجاری نرخ تەنها لە فرۆشتنی دیناردا بەردەستە.';
        } elseif (!$_SESSION['pos_cart']) {
            $errorMessage = 'پسوولەکە بەتاڵە.';
        } elseif (!is_array($priceInputs) || count($priceInputs) !== count($_SESSION['pos_cart'])) {
            $errorMessage = 'نرخی هەموو کاڵاکانی پسووڵە بنووسە.';
        } else {
            $validatedPrices = [];
            foreach ($_SESSION['pos_cart'] as $cartProductId => $_quantity) {
                if (!array_key_exists($cartProductId, $priceInputs)) {
                    $errorMessage = 'نرخی هەموو کاڵاکانی پسووڵە بنووسە.';
                    break;
                }

                $priceInput = $priceInputs[$cartProductId];
                if (
                    !is_string($priceInput)
                    || !preg_match($moneyPattern, $priceInput)
                    || !is_finite((float) $priceInput)
                ) {
                    $errorMessage = 'هەموو نرخەکان دەبێت ژمارەی تەواوی دیناری دروست بن.';
                    break;
                }
                $validatedPrices[$cartProductId] = (float) $priceInput;
            }

            if ($errorMessage === '') {
                $_SESSION['pos_cart_prices'] = array_replace(
                    $_SESSION['pos_cart_prices'],
                    $validatedPrices
                );
                header('Location: pos.php#checkout-section');
                exit;
            }
        }
    } elseif ($action === 'change_price') {
        unset($_SESSION['pos_print_sale_id']);
        $printSaleId = null;
        $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
        $priceInput = $_POST['unit_price'] ?? null;

        if ($isIqdSale) {
            $errorMessage = 'تکایە دوگمەی گۆڕینی هەموو نرخەکانی دینار بەکاربهێنە.';
        } elseif (!$productId || $productId < 1 || !array_key_exists($productId, $_SESSION['pos_cart'])) {
            $errorMessage = 'کاڵای داواکراو لە پسوولەدا نییە.';
        } elseif (
            !is_string($priceInput)
            || !preg_match($moneyPattern, $priceInput)
            || !is_finite((float) $priceInput)
        ) {
            $errorMessage = 'نرخ دەبێت ژمارەیەکی دروست بێت و زیاتر لە دوو خانەی دەیی نەبێت.';
        } else {
            $_SESSION['pos_cart_prices'][$productId] = (float) $priceInput;
            header('Location: pos.php#checkout-section');
            exit;
        }
    } elseif ($action === 'clear') {
        $_SESSION['pos_cart'] = [];
        $_SESSION['pos_cart_prices'] = [];
        header('Location: pos.php');
        exit;
    } elseif ($action === 'switch_user') {
        $_SESSION['pos_cart'] = [];
        $_SESSION['pos_cart_prices'] = [];
        unset(
            $_SESSION['user_id'],
            $_SESSION['full_name'],
            $_SESSION['role'],
            $_SESSION['pos_csrf_token'],
            $_SESSION['pos_print_sale_id']
        );
        session_regenerate_id(true);
        header('Location: login.php');
        exit;
    } elseif ($action === 'checkout') {
        $cart = $_SESSION['pos_cart'];
        $checkoutCurrencyInput = $_POST['currency'] ?? '';
        $customerIdInput = $_POST['customer_id'] ?? '';
        $customerNameInput = $_POST['customer_name'] ?? '';
        $customerAddressInput = $_POST['customer_address'] ?? '';
        $customerPhoneInput = $_POST['customer_phone'] ?? '';
        $discountPercentInput = $_POST['discount_percent'] ?? '0';
        $netAmountInput = $_POST['net_amount'] ?? '';
        $remainingAmountInput = $_POST['remaining_amount'] ?? null;
        $exchangeRateInput = $_POST['usd_iqd_rate'] ?? '';
        $checkoutCustomerId = is_string($customerIdInput) ? $customerIdInput : '';
        $checkoutCustomerName = is_string($customerNameInput) ? trim($customerNameInput) : '';
        $checkoutCustomerAddress = is_string($customerAddressInput) ? trim($customerAddressInput) : '';
        $checkoutCustomerPhone = is_string($customerPhoneInput) ? trim($customerPhoneInput) : '';
        $checkoutDiscountPercent = is_scalar($discountPercentInput) ? (string) $discountPercentInput : '';
        $checkoutNetAmount = is_string($netAmountInput) ? trim($netAmountInput) : '';
        $checkoutRemainingAmount = is_string($remainingAmountInput) ? trim($remainingAmountInput) : '';
        $checkoutExchangeRate = is_string($exchangeRateInput) ? trim($exchangeRateInput) : '';
        $customerId = null;
        $discountPercent = is_numeric($checkoutDiscountPercent) ? (float) $checkoutDiscountPercent : -1;
        $requestedNetAmount = is_numeric($checkoutNetAmount) ? (float) $checkoutNetAmount : -1;
        $requestedRemainingAmount = is_numeric($checkoutRemainingAmount) ? (float) $checkoutRemainingAmount : -1;
        $exchangeRate = is_numeric($checkoutExchangeRate) ? (float) $checkoutExchangeRate : 0;
        if (!$cart) {
            $errorMessage = 'پسوولەکە بەتاڵە.';
        } elseif (!is_string($checkoutCurrencyInput) || $checkoutCurrencyInput !== $posCurrency) {
            $errorMessage = 'دراوی پسووڵە گۆڕاوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
        } elseif (
            !is_string($customerIdInput)
            || !is_string($customerNameInput)
            || !is_string($customerAddressInput)
            || !is_string($customerPhoneInput)
            || !is_string($netAmountInput)
            || !is_string($remainingAmountInput)
            || !is_string($exchangeRateInput)
            || !is_scalar($discountPercentInput)
        ) {
            $errorMessage = 'زانیاریی کڕیار یان داشکاندن دروست نییە.';
        } elseif (
            !$isIqdSale
            && $checkoutExchangeRate !== ''
            && (
                !preg_match('/^\d+(?:\.\d{1,4})?$/D', $checkoutExchangeRate)
                || !is_finite($exchangeRate)
                || $exchangeRate <= 0
            )
        ) {
            $errorMessage = 'نرخی دۆلار بە دینار دەبێت ژمارەیەکی دروست و گەورەتر لە سفر بێت.';
        } elseif (
            !preg_match($moneyPattern, $checkoutNetAmount)
            || !is_finite($requestedNetAmount)
            || $requestedNetAmount < 0
        ) {
            $errorMessage = 'کۆی دوای داشکاندن دەبێت ژمارەیەکی دروست و نەنێگەتیڤ بێت.';
        } elseif (
            !preg_match($moneyPattern, $checkoutRemainingAmount)
            || !is_finite($requestedRemainingAmount)
            || $requestedRemainingAmount < 0
        ) {
            $errorMessage = 'بڕی ماوە دەبێت ژمارەیەکی دروست و نەنێگەتیڤ بێت.';
        } elseif ($requestedRemainingAmount > $requestedNetAmount) {
            $errorMessage = 'بڕی ماوە نابێت لە کۆی دوای داشکاندن زیاتر بێت.';
        } elseif (!is_finite($discountPercent) || $discountPercent < 0 || $discountPercent > 100) {
            $errorMessage = 'ڕێژەی داشکاندن دەبێت لە نێوان ٠ و ١٠٠ بێت.';
        } elseif ($checkoutCustomerName === '' && ($checkoutCustomerAddress !== '' || $checkoutCustomerPhone !== '')) {
            $errorMessage = 'بۆ تۆمارکردنی ناونیشان یان ژمارەی مۆبایل، ناوی کڕیاری نوێ بنووسە.';
        } elseif ($checkoutCustomerName !== '' && $checkoutCustomerId !== '') {
            $errorMessage = 'کڕیارێک هەڵبژێرە یان ناوی کڕیاری نوێ بنووسە، نەک هەردووکیان.';
        } elseif (mb_strlen($checkoutCustomerName, 'UTF-8') > 255) {
            $errorMessage = 'ناوی کڕیار نابێت لە ٢٥٥ پیت زیاتر بێت.';
        } elseif (mb_strlen($checkoutCustomerPhone, 'UTF-8') > 50) {
            $errorMessage = 'ژمارەی مۆبایل نابێت لە ٥٠ پیت زیاتر بێت.';
        } elseif ($checkoutCustomerId !== '' && $checkoutCustomerPhone !== '') {
            $errorMessage = 'ژمارەی مۆبایل بۆ کڕیاری نوێ بنووسە؛ کڕیاری هەڵبژێردراو ژمارەی خۆی هەیە.';
        } elseif ($checkoutCustomerId !== '' && (
            filter_var($checkoutCustomerId, FILTER_VALIDATE_INT) === false
            || (int) $checkoutCustomerId < 1
        )) {
            $errorMessage = 'کڕیاری هەڵبژێردراو دروست نییە.';
        } else {
            if ($isIqdSale || $checkoutExchangeRate === '') {
                unset($_SESSION['pos_usd_iqd_rate']);
            } else {
                $_SESSION['pos_usd_iqd_rate'] = $checkoutExchangeRate;
            }
            if ($checkoutCustomerId !== '') {
                $customerId = (int) $checkoutCustomerId;
            }
            try {
                $pdo->beginTransaction();

                $productStmt = $pdo->prepare(
                    'SELECT product_id, product_name, sale_price_usd AS selling_price, stock_quantity
                     FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $validatedItems = [];
                $total = 0.0;

                foreach ($cart as $id => $quantity) {
                    $id = filter_var($id, FILTER_VALIDATE_INT);
                    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
                    if (!$id || $id < 1 || !$quantity || $quantity < 1) {
                        throw new RuntimeException('زانیاریی کاڵایەک لە پسوولەکەدا دروست نییە.');
                    }

                    $productStmt->execute(['product_id' => $id]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$product) {
                        throw new RuntimeException('کاڵایەک لە پسوولەکەدا لە بنکەدراوەدا نەدۆزرایەوە.');
                    }
                    if ($quantity > (int) $product['stock_quantity']) {
                        throw new RuntimeException(
                            'بڕی ' . $product['product_name'] . ' لە کۆگادا بەشی ئەم فرۆشتنە ناکات.'
                        );
                    }

                    if ($isIqdSale && !array_key_exists($id, $_SESSION['pos_cart_prices'])) {
                        throw new RuntimeException('تکایە نرخی دیناری هەموو کاڵاکان بنووسە.');
                    }
                    $unitPrice = (float) ($_SESSION['pos_cart_prices'][$id] ?? $product['selling_price']);
                    if (!is_finite($unitPrice) || $unitPrice < 0) {
                        throw new RuntimeException('نرخی کاڵایەک لە پسوولەکەدا دروست نییە.');
                    }
                    $subtotal = $unitPrice * $quantity;
                    $total += $subtotal;
                    $validatedItems[] = [
                        'product_id' => (int) $product['product_id'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                    ];
                }

                if ($customerId !== null) {
                    $customerStmt = $pdo->prepare(
                        'SELECT customer_id FROM customers WHERE customer_id = :customer_id FOR UPDATE'
                    );
                    $customerStmt->execute(['customer_id' => $customerId]);
                    if ($customerStmt->fetchColumn() === false) {
                        throw new RuntimeException('کڕیاری هەڵبژێردراو نەدۆزرایەوە.');
                    }
                } elseif ($checkoutCustomerName !== '') {
                    $customerStmt = $pdo->prepare(
                        'INSERT INTO customers (customer_name, phone, address, balance, credit_limit)
                         VALUES (:customer_name, :phone, :address, 0, 0)'
                    );
                    $customerStmt->execute([
                        'customer_name' => $checkoutCustomerName,
                        'phone' => $checkoutCustomerPhone !== '' ? $checkoutCustomerPhone : null,
                        'address' => $checkoutCustomerAddress !== '' ? $checkoutCustomerAddress : null,
                    ]);
                    $customerId = (int) $pdo->lastInsertId();
                }

                $precision = $isIqdSale ? 0 : 2;
                $total = round($total, $precision);
                $netAmount = round($requestedNetAmount, $precision);
                $remainingAmount = round($requestedRemainingAmount, $precision);
                if ($netAmount > $total) {
                    throw new RuntimeException('کۆی دوای داشکاندن نابێت لە کۆی پێش داشکاندن زیاتر بێت.');
                }
                if ($remainingAmount > $netAmount) {
                    throw new RuntimeException('بڕی ماوە نابێت لە کۆی دوای داشکاندن زیاتر بێت.');
                }
                $discountAmount = round($total - $netAmount, $precision);
                $saleStmt = $pdo->prepare(
                    'INSERT INTO sales
                        (customer_id, user_id, total_amount, discount, net_amount, currency,
                         paid_amount, remaining_amount, payment_method, sale_date)
                     VALUES
                        (:customer_id, :user_id, :total_amount, :discount, :net_amount, :currency,
                         :paid_amount, :remaining_amount, :payment_method, NOW())'
                );
                $saleStmt->execute([
                    'customer_id' => $customerId,
                    'user_id' => (int) $_SESSION['user_id'],
                    'total_amount' => $total,
                    'discount' => $discountAmount,
                    'net_amount' => $netAmount,
                    'currency' => $posCurrency,
                    'paid_amount' => round($netAmount - $remainingAmount, $precision),
                    'remaining_amount' => $remainingAmount,
                    'payment_method' => $remainingAmount > 0 ? 'debt' : 'cash',
                ]);
                $saleId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare(
                    'INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, total_price)
                     VALUES (:sale_id, :product_id, :quantity, :unit_price, :total_price)'
                );
                foreach ($validatedItems as $item) {
                    $itemStmt->execute([
                        'sale_id' => $saleId,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['subtotal'],
                    ]);
                }

                $pdo->commit();
                if ($isIqdSale || $checkoutExchangeRate === '') {
                    if (isset($_SESSION['pos_usd_iqd_rates']) && is_array($_SESSION['pos_usd_iqd_rates'])) {
                        unset($_SESSION['pos_usd_iqd_rates'][$saleId]);
                    }
                } else {
                    if (!isset($_SESSION['pos_usd_iqd_rates']) || !is_array($_SESSION['pos_usd_iqd_rates'])) {
                        $_SESSION['pos_usd_iqd_rates'] = [];
                    }
                    $_SESSION['pos_usd_iqd_rates'][$saleId] = $exchangeRate;
                }
                $_SESSION['pos_cart'] = [];
                $_SESSION['pos_cart_prices'] = [];
                $_SESSION['pos_print_sale_id'] = $saleId;
                header('Location: pos.php');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($exception instanceof PDOException) {
                    error_log('POS checkout failed: ' . $exception->getMessage());
                    $errorMessage = 'فرۆشتن پاشەکەوت نەکرا. تکایە پێکهاتەی خشتەکانی sales و sale_items بپشکنە.';
                } else {
                    $errorMessage = $exception->getMessage();
                }
            }
        }
    } else {
        $errorMessage = 'کرداری داواکراو ناسراو نییە.';
    }
}

$cartItems = [];
$cartTotal = 0.0;
$hasUnpricedItems = false;
$products = [];
$customers = [];

try {
    $customers = $pdo->query(
        'SELECT customer_id, customer_name, phone, balance
         FROM customers
         ORDER BY customer_name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $products = $pdo->query(
        'SELECT product_id, barcode, product_name, sale_price_usd AS selling_price, stock_quantity
         FROM products
         ORDER BY product_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($_SESSION['pos_cart']) {
        $cartIds = array_values(array_filter(
            array_map('intval', array_keys($_SESSION['pos_cart'])),
            static function (int $id): bool {
                return $id > 0;
            }
        ));

        if ($cartIds) {
            $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT product_id, barcode, product_name, sale_price_usd AS selling_price, stock_quantity
                 FROM products WHERE product_id IN ($placeholders)"
            );
            $stmt->execute($cartIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
                $id = (int) $product['product_id'];
                $quantity = (int) ($_SESSION['pos_cart'][$id] ?? 0);
                $hasCustomPrice = array_key_exists($id, $_SESSION['pos_cart_prices']);
                $product['price_set'] = !$isIqdSale || $hasCustomPrice;
                $product['selling_price'] = $hasCustomPrice
                    ? (float) $_SESSION['pos_cart_prices'][$id]
                    : ($isIqdSale ? null : (float) $product['selling_price']);
                $product['quantity'] = $quantity;
                $product['subtotal'] = $product['price_set'] ? (float) $product['selling_price'] * $quantity : 0;
                if (!$product['price_set']) {
                    $hasUnpricedItems = true;
                }
                $cartTotal += $product['subtotal'];
                $cartItems[] = $product;
            }
        }
    }
} catch (PDOException $exception) {
    error_log('Loading POS data failed: ' . $exception->getMessage());
    http_response_code(500);
    $errorMessage = 'هەڵەیەک لە بارکردنی کاڵاکاندا ڕوویدا.';
}

$displayDiscountPercent = is_numeric($checkoutDiscountPercent)
    ? min(100, max(0, (float) $checkoutDiscountPercent))
    : 0;
$calculatedNetAmount = max(0, round($cartTotal * (1 - $displayDiscountPercent / 100), $isIqdSale ? 0 : 2));
$displayNetAmount = $checkoutNetAmount !== ''
    ? $checkoutNetAmount
    : number_format($calculatedNetAmount, $isIqdSale ? 0 : 2, '.', '');

$page_title = 'فرۆشتن و کاشێر';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">فرۆشتن و کاشێر</h2>
            <p class="mt-1 text-sm text-slate-500">بارکۆد بخوێنەوە یان کاڵا هەڵبژێرە بۆ زیادکردنی بۆ پسوولە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= pos_escape($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= pos_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section dir="rtl" class="sticky top-0 z-30 mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <button id="sale-mode-toggle" type="button" aria-expanded="false" aria-controls="sale-mode-options" class="rounded-xl bg-slate-900 px-5 py-3 font-semibold text-white hover:bg-slate-700">
                    فرۆشتن
                </button>
                <span class="text-sm font-semibold text-slate-600">دراوی ئێستا: <?= $isIqdSale ? 'دینار' : 'دۆلار' ?></span>
            </div>
            <div id="sale-mode-options" class="mb-4 hidden flex-wrap gap-3">
                <?php foreach (['IQD' => 'فرۆشتن بە نرخی دینار', 'USD' => 'فرۆشتن بە نرخی دۆلار'] as $currency => $label): ?>
                    <form method="post" action="pos.php">
                        <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                        <input type="hidden" name="action" value="set_currency">
                        <input type="hidden" name="currency" value="<?= pos_escape($currency) ?>">
                        <button type="submit" <?= $_SESSION['pos_cart'] && $currency !== $posCurrency ? 'disabled title="پسووڵەکە پاک بکەرەوە بۆ گۆڕینی دراو"' : '' ?> class="rounded-xl px-5 py-3 font-semibold <?= $currency === $posCurrency ? 'bg-amber-400 text-slate-950' : 'bg-slate-100 text-slate-800 hover:bg-slate-200' ?> disabled:cursor-not-allowed disabled:opacity-50">
                            <?= pos_escape($label) ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>

        <div dir="rtl" class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
            <section dir="rtl" class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-2">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">پسوولەی ئێستا</h3>
                        <p class="mt-1 text-xs text-slate-500"><?= count($cartItems) ?> جۆری کاڵا</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <?php if ($cartItems): ?>
                            <form method="post" action="pos.php" onsubmit="return confirm('دڵنیایت لە پاککردنەوەی پسوولە؟');">
                                <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                                <input type="hidden" name="action" value="clear">
                                <button type="submit" class="text-sm font-medium text-red-600 hover:text-red-700">پاککردنەوە</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="pos.php" onsubmit="return confirm('بەکارهێنەر بگۆڕیت؟ پسووڵەی ئێستا پاک دەبێتەوە.');">
                            <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                            <input type="hidden" name="action" value="switch_user">
                            <button type="submit" class="text-sm font-medium text-amber-700 hover:text-amber-800">گۆڕینی بەکارهێنەر</button>
                        </form>
                    </div>
                </div>

                <div class="max-h-[30rem] divide-y divide-slate-100 overflow-y-auto px-5">
                    <?php foreach ($cartItems as $item): ?>
                        <?php
                        $itemQuantity = (int) $item['quantity'];
                        $dozenCount = intdiv($itemQuantity, 12);
                        $remainingAfterDozens = $itemQuantity % 12;
                        $halfDozenCount = intdiv($remainingAfterDozens, 6);
                        $remainingUnits = $remainingAfterDozens % 6;
                        $displayDozens = $dozenCount + ($halfDozenCount * 0.5);
                        ?>
                        <div class="py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="break-words text-center text-lg font-bold leading-7 text-slate-900"><?= pos_escape((string) $item['product_name']) ?></p>
                                    <p class="mt-1 text-sm text-slate-600">
                                        <?php if ($displayDozens > 0): ?>
                                            <?= number_format($displayDozens, $halfDozenCount > 0 ? 1 : 0, '.', '') ?> دەرزەن
                                        <?php endif; ?>
                                        <?php if ($remainingUnits > 0): ?>
                                            <?= $displayDozens > 0 ? ' و ' : '' ?><?= $remainingUnits ?> دانە
                                        <?php elseif ($displayDozens === 0.0): ?>
                                            <?= $itemQuantity ?> دانە
                                        <?php endif; ?>
                                        <?php if ($item['price_set']): ?>
                                            · <?= pos_escape(number_format((float) $item['selling_price'], $isIqdSale ? 0 : 2)) ?> بۆ هەر دانە
                                        <?php else: ?>
                                            · نرخی دینار دیاری نەکراوە
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <p class="shrink-0 font-bold text-slate-800"><?= pos_escape(number_format((float) $item['subtotal'], $isIqdSale ? 0 : 2)) ?></p>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-3">
                                    <?php if ($isIqdSale): ?>
                                        <label class="text-xs text-slate-600" for="price-<?= (int) $item['product_id'] ?>">نرخی دینار</label>
                                        <input
                                            id="price-<?= (int) $item['product_id'] ?>"
                                            name="unit_prices[<?= (int) $item['product_id'] ?>]"
                                            type="number"
                                            min="0"
                                            step="1"
                                            value="<?= $item['price_set'] ? number_format((float) $item['selling_price'], 0, '.', '') : '' ?>"
                                            placeholder="نرخی دینار"
                                            form="bulk-price-form"
                                            required
                                            class="w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-center"
                                        >
                                    <?php else: ?>
                                        <form method="post" action="pos.php" class="flex items-center gap-2">
                                            <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                                            <input type="hidden" name="action" value="change_price">
                                            <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                                            <label class="text-xs text-slate-600" for="price-<?= (int) $item['product_id'] ?>">نرخ</label>
                                            <input id="price-<?= (int) $item['product_id'] ?>" name="unit_price" type="number" min="0" step="0.01" value="<?= number_format((float) $item['selling_price'], 2, '.', '') ?>" required class="w-24 rounded-lg border border-slate-300 px-2 py-1.5 text-center">
                                            <button type="submit" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50">گۆڕینی نرخ</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                    <form method="post" action="pos.php" class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2">
                                        <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                                        <input type="hidden" name="action" value="change_quantity">
                                        <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                                        <label class="text-xs font-semibold text-slate-600" for="quantity-<?= (int) $item['product_id'] ?>">بڕ:</label>
                                        <input
                                            id="quantity-<?= (int) $item['product_id'] ?>"
                                            name="quantity"
                                            type="number"
                                            min="1"
                                            max="<?= (int) $item['stock_quantity'] ?>"
                                            value="<?= (int) $item['quantity'] ?>"
                                            data-piece-quantity
                                            class="w-20 rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-center"
                                        >
                                        <label class="sr-only" for="quantity-unit-<?= (int) $item['product_id'] ?>">یەکەی بڕ</label>
                                        <select
                                            id="quantity-unit-<?= (int) $item['product_id'] ?>"
                                            name="unit"
                                            data-quantity-unit
                                            class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm"
                                        >
                                            <option value="piece">دانە</option>
                                            <option value="dozen">دەرزەن</option>
                                        </select>
                                        <label class="sr-only" for="dozen-quantity-<?= (int) $item['product_id'] ?>">ژمارەی دەرزەن</label>
                                        <select
                                            id="dozen-quantity-<?= (int) $item['product_id'] ?>"
                                            name="quantity"
                                            data-dozen-quantity
                                            hidden
                                            disabled
                                            required
                                            class="rounded-lg border border-slate-300 bg-white px-2 py-1.5 text-sm"
                                        >
                                            <option value="" selected disabled>ژمارەی دەرزەن هەڵبژێرە</option>
                                            <option value="0">بێ دەرزەن</option>
                                            <?php for ($dozenOption = 1; $dozenOption <= 1000; $dozenOption++): ?>
                                                <option value="<?= $dozenOption * 12 ?>"><?= $dozenOption ?> دەرزەن</option>
                                            <?php endfor; ?>
                                        </select>
                                        <label data-half-dozen-option hidden class="flex items-center gap-1 text-sm text-slate-700">
                                            <input type="checkbox" name="half_dozen" value="1" data-half-dozen-checkbox disabled class="rounded border-slate-300">
                                            نیو دەرزەن زیاد بکە
                                        </label>
                                        <button type="submit" class="rounded-lg bg-slate-800 px-3 py-1.5 text-sm font-semibold text-white hover:bg-slate-700">پاشەکەوتی بڕ</button>
                                    </form>
                                    <form method="post" action="pos.php">
                                        <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="product_id" value="<?= (int) $item['product_id'] ?>">
                                        <button type="submit" class="text-xs font-medium text-red-600 hover:text-red-700">لابردن</button>
                                    </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$cartItems): ?>
                        <div class="py-12 text-center">
                            <p class="font-semibold text-slate-700">پسوولەکە بەتاڵە</p>
                            <p class="mt-1 text-sm text-slate-500">کاڵایەک زیاد بکە بۆ دەستپێکردنی فرۆشتن.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($isIqdSale && $cartItems): ?>
                    <form id="bulk-price-form" method="post" action="pos.php" class="border-t border-slate-100 bg-white px-5 py-4">
                        <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                        <input type="hidden" name="action" value="change_prices">
                        <p class="mb-3 text-sm text-slate-600">نرخی هەموو کاڵاکان بنووسە، پاشان جارێک دوگمەکە دابگرە.</p>
                        <button type="submit" class="w-full rounded-xl bg-amber-400 px-5 py-3 font-bold text-slate-950 hover:bg-amber-300">
                            گۆڕینی هەموو نرخەکان
                        </button>
                    </form>
                <?php endif; ?>

                <div id="checkout-section" class="border-t border-slate-100 bg-slate-50 p-5">
                    <form id="checkout-form" method="post" action="pos.php">
                        <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                        <input type="hidden" name="action" value="checkout">
                        <input type="hidden" name="currency" value="<?= pos_escape($posCurrency) ?>">
                    <div class="mb-4 space-y-2">
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">کۆی پێش داشکاندن</span>
                            <span class="font-semibold text-slate-800">
                                <?= pos_escape(pos_money($cartTotal, $posCurrency)) ?>
                                <?= $hasUnpricedItems ? ' · نرخی هەندێک کاڵا دیاری نەکراوە' : '' ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-slate-600">بڕی داشکاندن</span>
                            <span id="discount-amount" class="font-semibold text-amber-700"><?= pos_escape(pos_money(0, $posCurrency)) ?></span>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2">
                            <label for="net-total" class="font-semibold text-slate-700">کۆی دوای داشکاندن (<?= $isIqdSale ? 'د.ع' : '$' ?>)</label>
                            <input id="net-total" name="net_amount" type="number" min="0" max="<?= number_format($cartTotal, $isIqdSale ? 0 : 2, '.', '') ?>" step="<?= $moneyStep ?>" required value="<?= pos_escape($displayNetAmount) ?>" class="w-44 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-2xl font-extrabold text-slate-900">
                        </div>
                        <div class="flex items-center justify-between">
                            <label for="remaining-amount" class="font-semibold text-slate-700">بڕی ماوە / قەرز (<?= $isIqdSale ? 'د.ع' : '$' ?>)</label>
                            <input id="remaining-amount" name="remaining_amount" type="number" min="0" max="<?= pos_escape($displayNetAmount) ?>" step="<?= $moneyStep ?>" required value="<?= pos_escape($checkoutRemainingAmount) ?>" class="w-44 rounded-lg border border-slate-300 bg-white px-3 py-2 text-left text-lg font-semibold text-slate-900">
                        </div>
                    </div>
                        <?php if (!$isIqdSale): ?>
                        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <label for="usd_iqd_rate" class="mb-1 block text-sm font-semibold text-slate-700">نرخی ١٠٠ دۆلار بەرامبەر بە دیناری عێراقی (ئارەزوومەندانە)</label>
                            <input id="usd_iqd_rate" name="usd_iqd_rate" type="number" min="0.0001" step="0.0001" value="<?= pos_escape($checkoutExchangeRate) ?>" placeholder="بۆ نموونە: 165؛ بەتاڵی بهێڵە بۆ دۆلار تەنها" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                            <p class="mt-1 text-xs text-slate-600">نرخەکە بە هەزار دینار بنووسە؛ ١٦٥ واتە ١٦٥,٠٠٠ دینار بۆ ١٠٠ دۆلار.</p>
                            <p class="mt-2 text-sm text-slate-600">کۆی پسووڵە بە دینار: <strong id="iqd-total" class="text-lg text-slate-900">—</strong></p>
                        </div>
                        <?php endif; ?>
                        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="customer_id" class="mb-1 block text-sm font-medium text-slate-700">کڕیار (ئارەزوومەندانە)</label>
                                <select id="customer_id" name="customer_id" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                                    <option value="">کڕیاری گشتی / بێ هەڵبژاردن</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= (int) $customer['customer_id'] ?>" <?= $checkoutCustomerId === (string) $customer['customer_id'] ? 'selected' : '' ?>>
                                            <?= pos_escape((string) $customer['customer_name']) ?><?= !empty($customer['phone']) ? ' · ' . pos_escape((string) $customer['phone']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="customer_name" class="mb-1 block text-sm font-medium text-slate-700">یان ناوی کڕیاری نوێ</label>
                                <input id="customer_name" name="customer_name" type="text" maxlength="255" autocomplete="name" value="<?= pos_escape($checkoutCustomerName) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                            </div>
                            <div>
                                <label for="customer_address" class="mb-1 block text-sm font-medium text-slate-700">ناونیشان (ئارەزوومەندانە)</label>
                                <input id="customer_address" name="customer_address" type="text" autocomplete="street-address" value="<?= pos_escape($checkoutCustomerAddress) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-base focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                            </div>
                            <div>
                                <label for="customer_phone" class="mb-1 block text-sm font-medium text-slate-700">ژمارەی مۆبایل (ئارەزوومەندانە)</label>
                                <input id="customer_phone" name="customer_phone" type="tel" maxlength="50" autocomplete="tel" value="<?= pos_escape($checkoutCustomerPhone) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-lg font-semibold focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                            </div>
                            <div>
                                <label for="discount_percent" class="mb-1 block text-sm font-medium text-slate-700">داشکاندنی کڕیار (%)</label>
                                <input id="discount_percent" name="discount_percent" type="number" min="0" max="100" step="0.01" required value="<?= pos_escape($checkoutDiscountPercent) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-3 text-lg font-semibold focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200">
                            </div>
                        </div>
                        <p class="mb-4 text-xs text-slate-500">کۆی دوای داشکاندن دەتوانیت دەستکاری بکەیت؛ بڕی داشکاندن بە پێی ئەو کۆیە ڕێکدەخرێت.</p>
                        <button type="submit" <?= !$cartItems || $hasUnpricedItems ? 'disabled' : '' ?> class="w-full rounded-xl bg-emerald-600 px-5 py-4 text-lg font-bold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                            تەواوکردنی فرۆشتن
                        </button>
                    </form>
                </div>
            </section>

            <section dir="rtl" class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-lg font-bold text-slate-800">سکانی بارکۆد یان لە لیستی کاڵاکان هەڵیبژێرە</h3>
                <form method="post" action="pos.php" class="mt-4 flex flex-col gap-3 sm:flex-row" data-add-product-form>
                    <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <label class="sr-only" for="barcode">بارکۆدی کاڵا</label>
                    <input
                        id="barcode"
                        name="barcode"
                        type="text"
                        autocomplete="off"
                        placeholder="بارکۆد بخوێنەوە یان بنووسە"
                        class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-amber-50/40 px-4 py-4 text-lg font-semibold outline-none focus:border-amber-600 focus:ring-4 focus:ring-amber-200"
                    >
                    <button type="submit" class="rounded-xl bg-amber-400 px-5 py-4 font-bold text-slate-950 hover:bg-amber-300">
                        زیادکردن
                    </button>
                </form>
                <h4 class="mb-3 mt-6 font-semibold text-slate-700">هەڵبژاردن لە لیستی کاڵاکان</h4>
                <div>
                    <label class="sr-only" for="pos-product-search">گەڕان بە ناوی کاڵا یان بارکۆد</label>
                    <input
                        id="pos-product-search"
                        type="search"
                        autocomplete="off"
                        placeholder="گەڕان بە ناوی کاڵا یان بارکۆد..."
                        class="mb-3 w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200"
                    >
                    <div id="pos-product-list" class="max-h-[34rem] space-y-2 overflow-y-auto">
                        <?php foreach ($products as $product): ?>
                            <form method="post" action="pos.php" class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3 transition hover:border-amber-300 hover:bg-amber-50/50" data-add-product-form data-product-search="<?= pos_escape((string) $product['product_name'] . ' ' . (string) ($product['barcode'] ?? '')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= pos_escape($_SESSION['pos_csrf_token']) ?>">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                                <div class="min-w-0 text-right">
                                    <p class="break-words text-base font-bold leading-7 text-slate-900"><?= pos_escape((string) $product['product_name']) ?></p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                                        <span dir="ltr" class="whitespace-nowrap rounded-md bg-slate-100 px-2 py-1 font-medium">
                                            <?= pos_escape((string) preg_replace('/\s+/u', '', (string) ($product['barcode'] ?? 'بێ بارکۆد'))) ?>
                                        </span>
                                        <span class="rounded-md bg-slate-100 px-2 py-1">
                                            کۆگا: <?= number_format((int) $product['stock_quantity']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <?php if ($isIqdSale): ?>
                                        <span class="whitespace-nowrap text-sm font-bold text-slate-700">نرخ بە دینار</span>
                                    <?php else: ?>
                                        <span class="text-sm font-bold text-slate-700">$<?= number_format((float) $product['selling_price'], 2) ?></span>
                                    <?php endif; ?>
                                    <button
                                        type="submit"
                                        <?= (int) $product['stock_quantity'] < 1 ? 'disabled' : '' ?>
                                        class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                    >زیادکردن</button>
                                </div>
                            </form>
                        <?php endforeach; ?>
                        <?php if (!$products): ?>
                            <p class="rounded-xl bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">هیچ کاڵایەک بۆ فرۆشتن بەردەست نییە.</p>
                        <?php endif; ?>
                    </div>
                    <p id="pos-product-search-empty" hidden class="mt-3 rounded-xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                        هیچ کاڵایەک بەو گەڕانە نەدۆزرایەوە.
                    </p>
                </div>
            </section>

        </div>
    </div>
</main>
</div>
</div>
<script>
    const addProductForms = document.querySelectorAll('[data-add-product-form]');
    const scrollContainers = Array.from(document.querySelectorAll('main.overflow-y-auto, .overflow-y-auto'));
    const addProductScrollKey = 'pos-add-product-scroll';
    const savedAddScroll = sessionStorage.getItem(addProductScrollKey);

    if (savedAddScroll !== null) {
        sessionStorage.removeItem(addProductScrollKey);
        const scrollPositions = JSON.parse(savedAddScroll);
        scrollContainers.forEach((container, index) => {
            if (Number.isFinite(scrollPositions[index])) {
                container.scrollTop = scrollPositions[index];
            }
        });
    }

    addProductForms.forEach((form) => {
        form.addEventListener('submit', () => {
            sessionStorage.setItem(
                addProductScrollKey,
                JSON.stringify(scrollContainers.map((container) => container.scrollTop))
            );
        });
    });

    const posProductSearch = document.getElementById('pos-product-search');
    const posProductSearchItems = Array.from(document.querySelectorAll('#pos-product-list [data-product-search]'));
    const posProductSearchEmpty = document.getElementById('pos-product-search-empty');
    posProductSearch?.addEventListener('input', () => {
        const query = posProductSearch.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        posProductSearchItems.forEach((item) => {
            const matches = item.dataset.productSearch.toLocaleLowerCase().includes(query);
            item.hidden = !matches;
            item.classList.toggle('hidden', !matches);
            if (matches) {
                visibleCount++;
            }
        });

        posProductSearchEmpty.hidden = posProductSearchItems.length === 0 || visibleCount > 0;
        posProductSearchEmpty.classList.toggle('hidden', posProductSearchItems.length === 0 || visibleCount > 0);
    });

    document.getElementById('barcode')?.focus({ preventScroll: true });

    const discountPercentInput = document.getElementById('discount_percent');
    const discountAmountOutput = document.getElementById('discount-amount');
    const netTotalOutput = document.getElementById('net-total');
    const remainingAmountInput = document.getElementById('remaining-amount');
    const exchangeRateInput = document.getElementById('usd_iqd_rate');
    const iqdTotalOutput = document.getElementById('iqd-total');
    const cartSubtotal = <?= json_encode($cartTotal, JSON_PRESERVE_ZERO_FRACTION) ?>;
    const isIqdSale = <?= json_encode($isIqdSale) ?>;
    const moneySymbol = <?= json_encode($isIqdSale ? ' د.ع' : '$') ?>;
    const moneyPrecision = isIqdSale ? 0 : 2;

    document.getElementById('sale-mode-toggle')?.addEventListener('click', (event) => {
        const options = document.getElementById('sale-mode-options');
        const isExpanded = event.currentTarget.getAttribute('aria-expanded') === 'true';
        options.classList.toggle('hidden', isExpanded);
        options.classList.toggle('flex', !isExpanded);
        event.currentTarget.setAttribute('aria-expanded', String(!isExpanded));
    });

    function updateIqdTotal(netTotal) {
        if (!exchangeRateInput || !iqdTotalOutput) {
            return;
        }
        const exchangeRate = Number(exchangeRateInput.value);
        iqdTotalOutput.textContent = Number.isFinite(exchangeRate) && exchangeRate > 0
            ? (Math.round((netTotal * exchangeRate * 10) / 250) * 250).toLocaleString('en-US') + ' د.ع'
            : '—';
    }

    function updateCheckoutTotals() {
        const percent = Number(discountPercentInput.value);
        const validPercent = Number.isFinite(percent) ? Math.min(100, Math.max(0, percent)) : 0;
        const scale = 10 ** moneyPrecision;
        const discountAmount = Math.round(cartSubtotal * validPercent / 100 * scale) / scale;
        const netTotal = Math.max(0, Math.round((cartSubtotal - discountAmount) * scale) / scale);
        discountAmountOutput.textContent = isIqdSale
            ? discountAmount.toLocaleString('en-US') + moneySymbol
            : moneySymbol + discountAmount.toFixed(moneyPrecision);
        netTotalOutput.value = netTotal.toFixed(moneyPrecision);
        remainingAmountInput.max = netTotal.toFixed(moneyPrecision);
        updateIqdTotal(netTotal);
    }

    function updateTotalsFromNetAmount() {
        const netTotal = Number(netTotalOutput.value);
        if (!Number.isFinite(netTotal) || netTotal < 0 || netTotal > cartSubtotal) {
            return;
        }

        const scale = 10 ** moneyPrecision;
        const roundedNetTotal = Math.round(netTotal * scale) / scale;
        remainingAmountInput.max = roundedNetTotal.toFixed(moneyPrecision);
        const discountAmount = Math.round((cartSubtotal - roundedNetTotal) * scale) / scale;
        const impliedDiscountPercent = cartSubtotal > 0 ? (discountAmount / cartSubtotal) * 100 : 0;
        discountPercentInput.value = impliedDiscountPercent.toFixed(2);
        discountAmountOutput.textContent = isIqdSale
            ? discountAmount.toLocaleString('en-US') + moneySymbol
            : moneySymbol + discountAmount.toFixed(moneyPrecision);
        updateIqdTotal(roundedNetTotal);
    }

    discountPercentInput.addEventListener('input', updateCheckoutTotals);
    netTotalOutput.addEventListener('input', updateTotalsFromNetAmount);
    exchangeRateInput?.addEventListener('input', () => updateIqdTotal(Number(netTotalOutput.value)));

    document.querySelectorAll('[data-quantity-unit]').forEach((unitInput) => {
        const quantityForm = unitInput.form;
        const pieceQuantity = quantityForm.querySelector('[data-piece-quantity]');
        const dozenQuantity = quantityForm.querySelector('[data-dozen-quantity]');
        const halfDozenOption = quantityForm.querySelector('[data-half-dozen-option]');
        const halfDozenCheckbox = quantityForm.querySelector('[data-half-dozen-checkbox]');
        const updateQuantityInput = () => {
            const isDozen = unitInput.value === 'dozen';
            pieceQuantity.hidden = isDozen;
            pieceQuantity.disabled = isDozen;
            pieceQuantity.required = !isDozen;
            dozenQuantity.hidden = !isDozen;
            dozenQuantity.disabled = !isDozen;
            const canAddHalfDozen = isDozen && dozenQuantity.value !== '6';
            halfDozenOption.hidden = !canAddHalfDozen;
            halfDozenCheckbox.disabled = !canAddHalfDozen;
            if (!canAddHalfDozen) {
                halfDozenCheckbox.checked = false;
            }
        };

        unitInput.addEventListener('change', updateQuantityInput);
        dozenQuantity.addEventListener('change', updateQuantityInput);
        updateQuantityInput();
    });

    updateTotalsFromNetAmount();
</script>
<?php if ($printSaleId !== null): ?>
    <iframe
        id="sale-receipt-frame"
        src="receipt.php?sale_id=<?= (int) $printSaleId ?>&amp;print=1"
        title="پسووڵەی فرۆشتن"
        class="fixed h-px w-px border-0"
        aria-hidden="true"
    ></iframe>
    <script>
        window.addEventListener('message', (event) => {
            if (event.origin !== window.location.origin || event.data !== 'sale-receipt-printed') {
                return;
            }
            document.getElementById('sale-receipt-frame')?.remove();
        });
    </script>
<?php endif; ?>
</body>
</html>
