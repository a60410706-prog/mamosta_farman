<?php
session_start();

$returnToInput = $_GET['return_to'] ?? $_POST['return_to'] ?? '';
$returnTo = is_string($returnToInput) && in_array($returnToInput, ['warehouse.php', 'index.php?section=profit#product-profit-title'], true)
    ? $returnToInput
    : 'products.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';

function products_is_valid_expiry_date(string $date): bool
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

if (empty($_SESSION['products_csrf_token'])) {
    $_SESSION['products_csrf_token'] = bin2hex(random_bytes(32));
}
$productDamagedInputs = isset($_SESSION['product_damaged_inputs']) && is_array($_SESSION['product_damaged_inputs'])
    ? $_SESSION['product_damaged_inputs']
    : [];

$page_title = 'بەڕێوەبردنی کاڵاکان';
$products = [];
$categories = [];
$editingProduct = null;
$addingProduct = ($_SESSION['role'] ?? '') === 'admin' && ($_GET['add'] ?? '') === '1';
$newProduct = [
    'product_name' => '',
    'barcode' => '',
    'purchase_price_usd' => '',
    'purchase_price_eur' => '',
    'selling_price' => '',
    'stock_quantity' => '0',
    'damaged_quantity' => '0',
    'alert_limit' => '5',
    'category_id' => '',
    'expiry_date' => '',
];
$products_load_failed = false;
$error_message = '';
$success_message = $_SESSION['product_image_success'] ?? '';
unset($_SESSION['product_image_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_product') {
    $addingProduct = true;
    foreach ($newProduct as $field => $default) {
        $input = $_POST[$field] ?? $default;
        $newProduct[$field] = is_string($input) ? trim($input) : $default;
    }

    $token = $_POST['csrf_token'] ?? '';
    $productName = $newProduct['product_name'];
    $barcode = $newProduct['barcode'];
    $purchasePriceUsdInput = $newProduct['purchase_price_usd'];
    $purchasePriceEurInput = $newProduct['purchase_price_eur'];
    $sellingPriceInput = $newProduct['selling_price'];
    $stockInput = $newProduct['stock_quantity'] === '' ? '0' : $newProduct['stock_quantity'];
    $stockQuantity = filter_var($stockInput, FILTER_VALIDATE_INT);
    $damagedInput = $newProduct['damaged_quantity'] === '' ? '0' : $newProduct['damaged_quantity'];
    $damagedQuantity = filter_var($damagedInput, FILTER_VALIDATE_INT);
    $alertLimitInput = $newProduct['alert_limit'] === '' ? '5' : $newProduct['alert_limit'];
    $alertLimit = filter_var($alertLimitInput, FILTER_VALIDATE_INT);
    $categoryInput = $newProduct['category_id'];
    $categoryId = $categoryInput === '' ? null : filter_var($categoryInput, FILTER_VALIDATE_INT);

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        $error_message = 'تەنها بەڕێوەبەر دەتوانێت کاڵای نوێ زیاد بکات.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['products_csrf_token'], $token)) {
        $error_message = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە.';
    } elseif (
        $productName === ''
        || mb_strlen($productName, 'UTF-8') > 200
        || $barcode === ''
        || mb_strlen($barcode, 'UTF-8') > 100
        || ($purchasePriceUsdInput !== '' && (
            !preg_match('/^\d+(?:\.\d{1,2})?$/D', $purchasePriceUsdInput)
            || !is_finite((float) $purchasePriceUsdInput)
            || (float) $purchasePriceUsdInput > 99999999.99
        ))
        || ($purchasePriceEurInput !== '' && (
            !preg_match('/^\d+(?:\.\d{1,2})?$/D', $purchasePriceEurInput)
            || !is_finite((float) $purchasePriceEurInput)
            || (float) $purchasePriceEurInput > 99999999.99
        ))
        || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $sellingPriceInput)
        || !is_finite((float) $sellingPriceInput)
        || (float) $sellingPriceInput > 99999999.99
        || !preg_match('/^\d+$/D', $stockInput)
        || $stockQuantity === false
        || $stockQuantity < 0
        || $stockQuantity > 2147483647
        || !preg_match('/^\d+$/D', $damagedInput)
        || $damagedQuantity === false
        || $damagedQuantity < 0
        || !preg_match('/^\d+$/D', $alertLimitInput)
        || $alertLimit === false
        || $alertLimit < 0
        || $alertLimit > 2147483647
        || ($categoryInput !== '' && ($categoryId === false || $categoryId < 1))
        || ($newProduct['expiry_date'] !== '' && !products_is_valid_expiry_date($newProduct['expiry_date']))
    ) {
        $error_message = 'ناوی کاڵا، بارکۆد، نرخەکان، بڕی کۆگا، بەرواری بەسەرچوون یان براند بپشکنە.';
    } else {
        try {
            if ($categoryId !== null) {
                $categoryStmt = $pdo->prepare(
                    'SELECT category_id FROM categories WHERE category_id = :category_id'
                );
                $categoryStmt->execute(['category_id' => $categoryId]);
                if ($categoryStmt->fetchColumn() === false) {
                    throw new RuntimeException('براندی هەڵبژێردراو نەدۆزرایەوە.');
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (product_name, barcode, purchase_price_usd, purchase_price_eur, sale_price_usd,
                     stock_quantity, damaged_quantity, alert_limit, category_id, expiry_date)
                 VALUES
                    (:product_name, :barcode, :purchase_price_usd, :purchase_price_eur, :selling_price,
                     :stock_quantity, :damaged_quantity, :alert_limit, :category_id, :expiry_date)'
            );
            $stmt->execute([
                'product_name' => $productName,
                'barcode' => $barcode,
                'purchase_price_usd' => $purchasePriceUsdInput === '' ? null : (float) $purchasePriceUsdInput,
                'purchase_price_eur' => $purchasePriceEurInput === '' ? null : (float) $purchasePriceEurInput,
                'selling_price' => (float) $sellingPriceInput,
                'stock_quantity' => $stockQuantity,
                'damaged_quantity' => $damagedQuantity,
                'alert_limit' => $alertLimit,
                'category_id' => $categoryId,
                'expiry_date' => $newProduct['expiry_date'] !== '' ? $newProduct['expiry_date'] : null,
            ]);
            $_SESSION['product_image_success'] = 'کاڵای نوێ بە سەرکەوتوویی زیاد کرا.';
            header('Location: ' . $returnTo);
            exit;
        } catch (PDOException $exception) {
            error_log('Adding product failed: ' . $exception->getMessage());
            $error_message = ($exception->errorInfo[0] ?? '') === '23000'
                ? 'ئەم بارکۆدە پێشتر بۆ کاڵایەکی تر تۆمار کراوە.'
                : 'هەڵەیەک لە زیادکردنی کاڵای نوێدا ڕوویدا.';
        } catch (RuntimeException $exception) {
            $error_message = $exception->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['update_product', 'delete_product', 'mark_damaged'], true)) {
    $token = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        $error_message = 'تەنها بەڕێوەبەر دەتوانێت زانیاریی کاڵا بگۆڕێت یان بیسڕێتەوە.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['products_csrf_token'], $token)) {
        $error_message = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە.';
    } elseif ($productId === false || $productId === null || $productId < 1) {
        $error_message = 'کاڵای داواکراو دروست نییە.';
    } elseif ($action === 'mark_damaged') {
        $quantityInput = $_POST['quantity'] ?? null;
        $quantity = is_string($quantityInput) ? filter_var($quantityInput, FILTER_VALIDATE_INT) : false;

        if ($quantity === false || $quantity < 1) {
            $error_message = 'بڕی زیان‌پێگەیشتوو دەبێت لانیکەم یەک بێت.';
        } else {
            try {
                $pdo->beginTransaction();
                $productStmt = $pdo->prepare(
                    'SELECT product_name
                     FROM products WHERE product_id = :product_id FOR UPDATE'
                );
                $productStmt->execute(['product_id' => $productId]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new RuntimeException('کاڵاکە نەدۆزرایەوە.');
                }

                $updateStmt = $pdo->prepare(
                    'UPDATE products SET damaged_quantity = damaged_quantity + :quantity
                     WHERE product_id = :product_id'
                );
                $updateStmt->execute([
                    'quantity' => $quantity,
                    'product_id' => $productId,
                ]);
                $pdo->commit();

                if (!isset($_SESSION['product_damaged_inputs']) || !is_array($_SESSION['product_damaged_inputs'])) {
                    $_SESSION['product_damaged_inputs'] = [];
                }
                $_SESSION['product_damaged_inputs'][$productId] = $quantity;
                $_SESSION['product_image_success'] = 'بڕی زیان‌پێگەیشتووی ' . $product['product_name'] . ' تۆمار کرا؛ بڕی کۆگا کەم نەکراوەتەوە.';
                header('Location: products.php');
                exit;
            } catch (RuntimeException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error_message = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Recording damaged product stock failed: ' . $exception->getMessage());
                $error_message = 'هەڵەیەک لە تۆمارکردنی کاڵای زیان‌پێگەیشتوودا ڕوویدا.';
            }
        }
    } elseif ($action === 'update_product') {
        $productNameInput = $_POST['product_name'] ?? null;
        $barcodeInput = $_POST['barcode'] ?? null;
        $purchasePriceUsdInput = $_POST['purchase_price_usd'] ?? null;
        $purchasePriceEurInput = $_POST['purchase_price_eur'] ?? null;
        $sellingPriceInput = $_POST['selling_price'] ?? null;
        $stockInput = $_POST['stock_quantity'] ?? null;
        $damagedInput = $_POST['damaged_quantity'] ?? null;
        $alertLimitInput = $_POST['alert_limit'] ?? null;
        $expiryDateInput = $_POST['expiry_date'] ?? null;
        $stockQuantity = is_string($stockInput) ? filter_var($stockInput, FILTER_VALIDATE_INT) : false;
        $damagedQuantity = is_string($damagedInput) ? filter_var($damagedInput, FILTER_VALIDATE_INT) : false;
        $alertLimit = is_string($alertLimitInput) ? filter_var($alertLimitInput, FILTER_VALIDATE_INT) : false;
        $categoryInput = $_POST['category_id'] ?? '';
        $categoryId = $categoryInput === '' ? null : filter_var($categoryInput, FILTER_VALIDATE_INT);

        if (
            !is_string($productNameInput)
            || trim($productNameInput) === ''
            || mb_strlen(trim($productNameInput), 'UTF-8') > 200
            || !is_string($barcodeInput)
            || trim($barcodeInput) === ''
            || mb_strlen(trim($barcodeInput), 'UTF-8') > 100
            || !is_string($purchasePriceUsdInput)
            || ($purchasePriceUsdInput !== '' && (
                !preg_match('/^\d+(?:\.\d{1,2})?$/D', $purchasePriceUsdInput)
                || !is_finite((float) $purchasePriceUsdInput)
                || (float) $purchasePriceUsdInput > 99999999.99
            ))
            || !is_string($purchasePriceEurInput)
            || ($purchasePriceEurInput !== '' && (
                !preg_match('/^\d+(?:\.\d{1,2})?$/D', $purchasePriceEurInput)
                || !is_finite((float) $purchasePriceEurInput)
                || (float) $purchasePriceEurInput > 99999999.99
            ))
            || !is_string($sellingPriceInput)
            || !preg_match('/^\d+(?:\.\d{1,2})?$/D', $sellingPriceInput)
            || !is_finite((float) $sellingPriceInput)
            || (float) $sellingPriceInput > 99999999.99
            || !is_string($stockInput)
            || !preg_match('/^\d+$/D', $stockInput)
            || $stockQuantity === false
            || $stockQuantity < 0
            || $stockQuantity > 2147483647
            || !is_string($damagedInput)
            || !preg_match('/^\d+$/D', $damagedInput)
            || $damagedQuantity === false
            || $damagedQuantity < 0
            || !is_string($alertLimitInput)
            || !preg_match('/^\d+$/D', $alertLimitInput)
            || $alertLimit === false
            || $alertLimit < 0
            || $alertLimit > 2147483647
            || !is_string($expiryDateInput)
            || ($expiryDateInput !== '' && !products_is_valid_expiry_date($expiryDateInput))
            || ($categoryInput !== '' && ($categoryId === false || $categoryId < 1))
        ) {
            $error_message = 'ناوی کاڵا، بارکۆد، نرخەکان، بڕی کۆگا، بڕی زیان، ئاگادارکردنەوە و بەروار بپشکنە.';
        } else {
            try {
                if ($categoryId !== null) {
                    $categoryStmt = $pdo->prepare(
                        'SELECT category_id FROM categories WHERE category_id = :category_id'
                    );
                    $categoryStmt->execute(['category_id' => $categoryId]);
                    if ($categoryStmt->fetchColumn() === false) {
                        throw new RuntimeException('براندی هەڵبژێردراو نەدۆزرایەوە.');
                    }
                }

                $stmt = $pdo->prepare(
                    'UPDATE products
                     SET product_name = :product_name,
                         barcode = :barcode,
                         purchase_price_usd = :purchase_price_usd,
                         purchase_price_eur = :purchase_price_eur,
                         sale_price_usd = :selling_price,
                         stock_quantity = :stock_quantity,
                         damaged_quantity = :damaged_quantity,
                         alert_limit = :alert_limit,
                         category_id = :category_id,
                         expiry_date = :expiry_date
                     WHERE product_id = :product_id'
                );
                $stmt->execute([
                    'product_name' => trim($productNameInput),
                    'barcode' => trim($barcodeInput),
                    'purchase_price_usd' => $purchasePriceUsdInput === '' ? null : (float) $purchasePriceUsdInput,
                    'purchase_price_eur' => $purchasePriceEurInput === '' ? null : (float) $purchasePriceEurInput,
                    'selling_price' => (float) $sellingPriceInput,
                    'stock_quantity' => $stockQuantity,
                    'damaged_quantity' => $damagedQuantity,
                    'alert_limit' => $alertLimit,
                    'category_id' => $categoryId,
                    'expiry_date' => $expiryDateInput !== '' ? $expiryDateInput : null,
                    'product_id' => $productId,
                ]);
                $_SESSION['product_image_success'] = 'زانیاریی کاڵاکە نوێ کرایەوە.';
                header('Location: ' . $returnTo);
                exit;
            } catch (PDOException $exception) {
                error_log('Updating product failed: ' . $exception->getMessage());
                $error_message = ($exception->errorInfo[0] ?? '') === '23000'
                    ? 'ئەم بارکۆدە پێشتر بۆ کاڵایەکی تر تۆمار کراوە.'
                    : 'هەڵەیەک لە نوێکردنەوەی زانیاریی کاڵادا ڕوویدا.';
            } catch (RuntimeException $exception) {
                $error_message = $exception->getMessage();
            }
        }
    } else {
        try {
            $pdo->beginTransaction();

            $productStmt = $pdo->prepare(
                'SELECT product_id FROM products WHERE product_id = :product_id FOR UPDATE'
            );
            $productStmt->execute(['product_id' => $productId]);

            if ($productStmt->fetchColumn() === false) {
                $pdo->rollBack();
                $error_message = 'کاڵاکە نەدۆزرایەوە.';
            } else {
                $saleIdsStmt = $pdo->prepare(
                    'SELECT DISTINCT sale_id FROM sale_items WHERE product_id = :product_id'
                );
                $saleIdsStmt->execute(['product_id' => $productId]);
                $saleIds = $saleIdsStmt->fetchAll(PDO::FETCH_COLUMN);

                $purchaseIdsStmt = $pdo->prepare(
                    'SELECT DISTINCT purchase_id FROM purchase_items WHERE product_id = :product_id'
                );
                $purchaseIdsStmt->execute(['product_id' => $productId]);
                $purchaseIds = $purchaseIdsStmt->fetchAll(PDO::FETCH_COLUMN);

                $deleteReturnsStmt = $pdo->prepare(
                    'DELETE FROM returns WHERE product_id = :product_id'
                );
                $deleteReturnsStmt->execute(['product_id' => $productId]);

                $deleteSaleItemsStmt = $pdo->prepare(
                    'DELETE FROM sale_items WHERE product_id = :product_id'
                );
                $deleteSaleItemsStmt->execute(['product_id' => $productId]);

                foreach ($saleIds as $saleId) {
                    $saleStmt = $pdo->prepare(
                        'SELECT total_amount, discount, paid_amount
                         FROM sales WHERE sale_id = :sale_id FOR UPDATE'
                    );
                    $saleStmt->execute(['sale_id' => $saleId]);
                    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$sale) {
                        continue;
                    }

                    $remainingSaleItemsStmt = $pdo->prepare(
                        'SELECT COALESCE(SUM(total_price), 0)
                         FROM sale_items WHERE sale_id = :sale_id'
                    );
                    $remainingSaleItemsStmt->execute(['sale_id' => $saleId]);
                    $newTotal = round((float) $remainingSaleItemsStmt->fetchColumn(), 2);

                    if ($newTotal <= 0) {
                        $deleteSaleStmt = $pdo->prepare(
                            'DELETE FROM sales WHERE sale_id = :sale_id'
                        );
                        $deleteSaleStmt->execute(['sale_id' => $saleId]);
                        continue;
                    }

                    $oldTotal = (float) $sale['total_amount'];
                    $newDiscount = $oldTotal > 0
                        ? round((float) $sale['discount'] * $newTotal / $oldTotal, 2)
                        : 0.0;
                    $newDiscount = min(max($newDiscount, 0), $newTotal);
                    $newNetAmount = round($newTotal - $newDiscount, 2);
                    $newRemainingAmount = max(0, round($newNetAmount - (float) $sale['paid_amount'], 2));
                    $updateSaleStmt = $pdo->prepare(
                        'UPDATE sales
                         SET total_amount = :total_amount,
                             discount = :discount,
                             net_amount = :net_amount,
                             remaining_amount = :remaining_amount
                         WHERE sale_id = :sale_id'
                    );
                    $updateSaleStmt->execute([
                        'total_amount' => $newTotal,
                        'discount' => $newDiscount,
                        'net_amount' => $newNetAmount,
                        'remaining_amount' => $newRemainingAmount,
                        'sale_id' => $saleId,
                    ]);
                }

                $deletePurchaseItemsStmt = $pdo->prepare(
                    'DELETE FROM purchase_items WHERE product_id = :product_id'
                );
                $deletePurchaseItemsStmt->execute(['product_id' => $productId]);

                foreach ($purchaseIds as $purchaseId) {
                    $purchaseStmt = $pdo->prepare(
                        'SELECT total_amount, discount, paid_amount
                         FROM purchases WHERE purchase_id = :purchase_id FOR UPDATE'
                    );
                    $purchaseStmt->execute(['purchase_id' => $purchaseId]);
                    $purchase = $purchaseStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$purchase) {
                        continue;
                    }

                    $remainingPurchaseItemsStmt = $pdo->prepare(
                        'SELECT COALESCE(SUM(total_price), 0)
                         FROM purchase_items WHERE purchase_id = :purchase_id'
                    );
                    $remainingPurchaseItemsStmt->execute(['purchase_id' => $purchaseId]);
                    $newTotal = round((float) $remainingPurchaseItemsStmt->fetchColumn(), 2);

                    if ($newTotal <= 0) {
                        $deletePurchaseStmt = $pdo->prepare(
                            'DELETE FROM purchases WHERE purchase_id = :purchase_id'
                        );
                        $deletePurchaseStmt->execute(['purchase_id' => $purchaseId]);
                        continue;
                    }

                    $oldTotal = (float) $purchase['total_amount'];
                    $newDiscount = $oldTotal > 0
                        ? round((float) $purchase['discount'] * $newTotal / $oldTotal, 2)
                        : 0.0;
                    $newDiscount = min(max($newDiscount, 0), $newTotal);
                    $newNetAmount = round($newTotal - $newDiscount, 2);
                    $newRemainingAmount = max(0, round($newNetAmount - (float) $purchase['paid_amount'], 2));
                    $paymentStatus = $newRemainingAmount <= 0
                        ? 'paid'
                        : ((float) $purchase['paid_amount'] > 0 ? 'partial' : 'unpaid');
                    $updatePurchaseStmt = $pdo->prepare(
                        'UPDATE purchases
                         SET total_amount = :total_amount,
                             discount = :discount,
                             net_amount = :net_amount,
                             remaining_amount = :remaining_amount,
                             payment_status = :payment_status
                         WHERE purchase_id = :purchase_id'
                    );
                    $updatePurchaseStmt->execute([
                        'total_amount' => $newTotal,
                        'discount' => $newDiscount,
                        'net_amount' => $newNetAmount,
                        'remaining_amount' => $newRemainingAmount,
                        'payment_status' => $paymentStatus,
                        'purchase_id' => $purchaseId,
                    ]);
                }

                foreach (['purchase_orders', 'product_batches'] as $relatedTable) {
                    $deleteRelatedStmt = $pdo->prepare(
                        "DELETE FROM {$relatedTable} WHERE product_id = :product_id"
                    );
                    $deleteRelatedStmt->execute(['product_id' => $productId]);
                }

                $deleteProductStmt = $pdo->prepare(
                    'DELETE FROM products WHERE product_id = :product_id'
                );
                $deleteProductStmt->execute(['product_id' => $productId]);
                $pdo->commit();

                foreach (['jpg', 'png', 'webp'] as $imageExtension) {
                    $imagePath = __DIR__ . '/uploads/products/' . $productId . '.' . $imageExtension;
                    if (is_file($imagePath) && !unlink($imagePath)) {
                        error_log('Removing deleted product image failed: ' . $imagePath);
                    }
                }

                $_SESSION['product_image_success'] = 'کاڵاکە و مێژووی پەیوەندیداری بە سەرکەوتوویی سڕایەوە.';
                header('Location: products.php');
                exit;
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Deleting product failed: ' . $exception->getMessage());
            $error_message = 'هەڵەیەک لە سڕینەوەی کاڵادا ڕوویدا.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_image') {
    $token = $_POST['csrf_token'] ?? '';
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        $error_message = 'تەنها بەڕێوەبەر دەتوانێت وێنەی کاڵا بسڕێتەوە.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['products_csrf_token'], $token)) {
        $error_message = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە.';
    } elseif ($productId === false || $productId === null || $productId < 1) {
        $error_message = 'کاڵای داواکراو دروست نییە.';
    } else {
        $imageDirectory = __DIR__ . '/uploads/products';
        $foundImage = false;
        $deleteFailed = false;

        foreach (['jpg', 'png', 'webp'] as $imageExtension) {
            $imagePath = $imageDirectory . '/' . $productId . '.' . $imageExtension;
            if (is_file($imagePath)) {
                $foundImage = true;
                if (!unlink($imagePath)) {
                    $deleteFailed = true;
                    error_log('Deleting product image failed: ' . $imagePath);
                }
            }
        }

        if ($deleteFailed) {
            $error_message = 'سڕینەوەی وێنە سەرکەوتوو نەبوو.';
        } elseif (!$foundImage) {
            $error_message = 'وێنەیەک بۆ ئەم کاڵایە نەدۆزرایەوە.';
        } else {
            $_SESSION['product_image_success'] = 'وێنەی کاڵاکە سڕایەوە.';
            header('Location: products.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_image') {
    $token = $_POST['csrf_token'] ?? '';
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
    $image = $_FILES['product_image'] ?? null;

    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        $error_message = 'تەنها بەڕێوەبەر دەتوانێت وێنەی کاڵا بار بکات.';
    } elseif (!is_string($token) || !hash_equals($_SESSION['products_csrf_token'], $token)) {
        $error_message = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە.';
    } elseif ($productId === false || $productId === null || $productId < 1) {
        $error_message = 'کاڵای داواکراو دروست نییە.';
    } elseif (!is_array($image) || !isset($image['error'], $image['size'], $image['tmp_name']) || !is_int($image['error']) || !is_int($image['size']) || !is_string($image['tmp_name'])) {
        $error_message = 'فایلی وێنە دروست نییە.';
    } elseif ($image['error'] !== UPLOAD_ERR_OK) {
        $error_message = $image['error'] === UPLOAD_ERR_NO_FILE
            ? 'تکایە وێنەیەک هەڵبژێرە.'
            : 'بارکردنی وێنە سەرکەوتوو نەبوو.';
    } elseif ($image['size'] < 1 || $image['size'] > 5 * 1024 * 1024 || !is_uploaded_file($image['tmp_name'])) {
        $error_message = 'وێنەکە دروست نییە یان قەبارەکەی لە ٥ مێگابایت زیاترە.';
    } else {
        $imageInfo = getimagesize($image['tmp_name']);
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo !== false ? finfo_file($fileInfo, $image['tmp_name']) : false;
        if ($fileInfo !== false) {
            finfo_close($fileInfo);
        }

        $extensionsByMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if ($imageInfo === false || !is_string($mimeType) || !isset($extensionsByMime[$mimeType])) {
            $error_message = 'تەنها وێنەی JPG، PNG یان WebP ڕێگەپێدراوە.';
        } else {
            $imageDirectory = __DIR__ . '/uploads/products';
            try {
                $productStmt = $pdo->prepare('SELECT product_id FROM products WHERE product_id = :product_id');
                $productStmt->execute(['product_id' => $productId]);

                if ($productStmt->fetchColumn() === false) {
                    $error_message = 'کاڵاکە نەدۆزرایەوە.';
                } elseif (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0755, true) && !is_dir($imageDirectory)) {
                    throw new RuntimeException('فۆڵدەری وێنەکانی کاڵا دروست نەکرا.');
                } else {
                    $extension = $extensionsByMime[$mimeType];
                    $imagePath = $imageDirectory . '/' . $productId . '.' . $extension;
                    if (!move_uploaded_file($image['tmp_name'], $imagePath)) {
                        throw new RuntimeException('پاشەکەوتکردنی وێنە سەرکەوتوو نەبوو.');
                    }

                    foreach (['jpg', 'png', 'webp'] as $oldExtension) {
                        if ($oldExtension !== $extension) {
                            $oldImagePath = $imageDirectory . '/' . $productId . '.' . $oldExtension;
                            if (is_file($oldImagePath) && !unlink($oldImagePath)) {
                                error_log('Removing replaced product image failed: ' . $oldImagePath);
                            }
                        }
                    }

                    $_SESSION['product_image_success'] = 'وێنەی کاڵاکە بە سەرکەوتوویی بارکرا.';
                    header('Location: products.php');
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('Saving product image failed: ' . $exception->getMessage());
                $error_message = 'هەڵەیەک لە پاشەکەوتکردنی وێنەدا ڕوویدا.';
            } catch (RuntimeException $exception) {
                error_log('Saving product image failed: ' . $exception->getMessage());
                $error_message = 'پاشەکەوتکردنی وێنە سەرکەوتوو نەبوو.';
            }
        }
    }
}

try {
    $stmt = $pdo->query(
        'SELECT
            p.product_id,
            p.barcode,
            p.product_name,
            p.category_id,
            p.purchase_price_usd,
            p.purchase_price_eur,
            p.sale_price_usd AS selling_price,
            p.stock_quantity,
            p.damaged_quantity,
            p.alert_limit,
            p.expiry_date,
            c.category_name
         FROM products AS p
         LEFT JOIN categories AS c ON c.category_id = p.category_id
         ORDER BY p.product_id DESC'
    );
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $categories = $pdo->query(
        'SELECT category_id, category_name
         FROM categories
         ORDER BY category_name'
    )->fetchAll(PDO::FETCH_ASSOC);

    $editProductInput = $_GET['edit'] ?? (
        $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_product'
            ? ($_POST['product_id'] ?? null)
            : null
    );
    if (($_SESSION['role'] ?? '') === 'admin' && $editProductInput !== null) {
        $editProductId = filter_var($editProductInput, FILTER_VALIDATE_INT);
        if ($editProductId !== false && $editProductId > 0) {
            foreach ($products as $product) {
                if ((int) $product['product_id'] === $editProductId) {
                    $editingProduct = $product;
                    break;
                }
            }
            if ($editingProduct === null) {
                $error_message = 'کاڵای داواکراو بۆ نوێکردنەوە نەدۆزرایەوە.';
            } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $error_message !== '') {
                foreach (['product_name' => 'product_name', 'barcode' => 'barcode', 'purchase_price_usd' => 'purchase_price_usd', 'purchase_price_eur' => 'purchase_price_eur', 'selling_price' => 'selling_price', 'stock_quantity' => 'stock_quantity', 'damaged_quantity' => 'damaged_quantity', 'alert_limit' => 'alert_limit', 'category_id' => 'category_id', 'expiry_date' => 'expiry_date'] as $field => $inputName) {
                    if (isset($_POST[$inputName]) && is_scalar($_POST[$inputName])) {
                        $editingProduct[$field] = (string) $_POST[$inputName];
                    }
                }
            }
        }
    }
} catch (PDOException $exception) {
    error_log('Loading products failed: ' . $exception->getMessage());
    http_response_code(500);
    $products_load_failed = true;
    $error_message = 'هەڵەیەک لە بارکردنی کاڵاکاندا ڕوویدا. تکایە دواتر هەوڵ بدەرەوە.';
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
    <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-7xl">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-slate-800">کاڵاکان و کۆگا</h2>
                    <p class="mt-1 text-sm text-slate-500">لیستی هەموو کاڵا تۆمارکراوەکان و زانیاریی کۆگایان.</p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                        <a href="products.php?add=1" class="inline-flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 font-semibold text-slate-950 hover:bg-amber-400">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            زیادکردن
                        </a>
                    <?php endif; ?>
                    <span class="rounded-full bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800">
                        <?= number_format(count($products)) ?> کاڵا
                    </span>
                </div>
            </div>

            <?php if ($error_message !== ''): ?>
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                    <?= htmlspecialchars($error_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if ($success_message !== ''): ?>
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                    <?= htmlspecialchars($success_message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </div>
            <?php endif; ?>
            <?php if ($addingProduct && ($_SESSION['role'] ?? '') === 'admin'): ?>
                <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-lg font-bold text-slate-800">زیادکردنی کاڵای نوێ</h3>
                        <a href="<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="text-sm font-semibold text-slate-500 hover:text-slate-800">داخستن</a>
                    </div>
                    <form method="post" action="products.php?add=1&amp;return_to=<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['products_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="add_product">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div>
                            <label for="new-product-name" class="mb-1 block text-sm font-medium text-slate-700">ناوی کاڵا</label>
                            <input id="new-product-name" name="product_name" type="text" maxlength="200" required value="<?= htmlspecialchars($newProduct['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-barcode" class="mb-1 block text-sm font-medium text-slate-700">بارکۆد</label>
                            <input id="new-product-barcode" name="barcode" type="text" maxlength="100" required value="<?= htmlspecialchars($newProduct['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-purchase-price-eur" class="mb-1 block text-sm font-medium text-slate-700">نرخی کڕین (€) — ئارەزوومەندانە</label>
                            <input id="new-product-purchase-price-eur" name="purchase_price_eur" type="number" min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars($newProduct['purchase_price_eur'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-purchase-price-usd" class="mb-1 block text-sm font-medium text-slate-700">نرخی کڕین ($) — ئارەزوومەندانە</label>
                            <input id="new-product-purchase-price-usd" name="purchase_price_usd" type="number" min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars($newProduct['purchase_price_usd'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-selling-price" class="mb-1 block text-sm font-medium text-slate-700">نرخی فرۆشتن ($)</label>
                            <input id="new-product-selling-price" name="selling_price" type="number" min="0" max="99999999.99" step="0.01" required value="<?= htmlspecialchars($newProduct['selling_price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-stock" class="mb-1 block text-sm font-medium text-slate-700">بڕی کۆگا — ئارەزوومەندانە</label>
                            <input id="new-product-stock" name="stock_quantity" type="number" min="0" max="2147483647" step="1" value="<?= htmlspecialchars($newProduct['stock_quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-damaged" class="mb-1 block text-sm font-medium text-slate-700">بڕی زیان‌پێگەیشتوو — ئارەزوومەندانە</label>
                            <input id="new-product-damaged" name="damaged_quantity" type="number" min="0" step="1" value="<?= htmlspecialchars($newProduct['damaged_quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-alert-limit" class="mb-1 block text-sm font-medium text-slate-700">ئاگادارکردنەوە لە — ئارەزوومەندانە</label>
                            <input id="new-product-alert-limit" name="alert_limit" type="number" min="0" max="2147483647" step="1" value="<?= htmlspecialchars($newProduct['alert_limit'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="new-product-category" class="mb-1 block text-sm font-medium text-slate-700">براند</label>
                            <select id="new-product-category" name="category_id" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                                <option value="">براند دیاری نەکراوە</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['category_id'] ?>" <?= $newProduct['category_id'] === (string) $category['category_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $category['category_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="new-product-expiry-date" class="mb-1 block text-sm font-medium text-slate-700">بەرواری بەسەرچوون</label>
                            <input id="new-product-expiry-date" name="expiry_date" type="date" value="<?= htmlspecialchars($newProduct['expiry_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div class="flex items-end sm:col-span-2 lg:col-span-4">
                            <button type="submit" class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-slate-950 hover:bg-amber-400">پاشەکەوتکردن</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
            <?php if ($editingProduct !== null && ($_SESSION['role'] ?? '') === 'admin'): ?>
                <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <h3 class="text-lg font-bold text-slate-800">نوێکردنەوەی زانیاریی کاڵا</h3>
                        <a href="<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="text-sm font-semibold text-slate-500 hover:text-slate-800">داخستن</a>
                    </div>
                    <form method="post" action="products.php?edit=<?= (int) $editingProduct['product_id'] ?>&amp;return_to=<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['products_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update_product">
                        <input type="hidden" name="product_id" value="<?= (int) $editingProduct['product_id'] ?>">
                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <div>
                            <label for="edit-product-name" class="mb-1 block text-sm font-medium text-slate-700">ناوی کاڵا</label>
                            <input id="edit-product-name" name="product_name" type="text" maxlength="200" required value="<?= htmlspecialchars((string) $editingProduct['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-product-barcode" class="mb-1 block text-sm font-medium text-slate-700">بارکۆد</label>
                            <input id="edit-product-barcode" name="barcode" type="text" maxlength="100" required value="<?= htmlspecialchars((string) $editingProduct['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-purchase-price-eur" class="mb-1 block text-sm font-medium text-slate-700">نرخی کڕین (€) — ئارەزوومەندانە</label>
                            <input id="edit-purchase-price-eur" name="purchase_price_eur" type="number" min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars((string) ($editingProduct['purchase_price_eur'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-purchase-price-usd" class="mb-1 block text-sm font-medium text-slate-700">نرخی کڕین ($) — ئارەزوومەندانە</label>
                            <input id="edit-purchase-price-usd" name="purchase_price_usd" type="number" min="0" max="99999999.99" step="0.01" value="<?= htmlspecialchars((string) ($editingProduct['purchase_price_usd'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-selling-price" class="mb-1 block text-sm font-medium text-slate-700">نرخی فرۆشتن ($)</label>
                            <input id="edit-selling-price" name="selling_price" type="number" min="0" step="0.01" required value="<?= htmlspecialchars((string) $editingProduct['selling_price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-stock-quantity" class="mb-1 block text-sm font-medium text-slate-700">بڕی کۆگا</label>
                            <input id="edit-stock-quantity" name="stock_quantity" type="number" min="0" max="2147483647" step="1" required value="<?= htmlspecialchars((string) ($editingProduct['stock_quantity'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-damaged-quantity" class="mb-1 block text-sm font-medium text-slate-700">بڕی زیان‌پێگەیشتوو</label>
                            <input id="edit-damaged-quantity" name="damaged_quantity" type="number" min="0" step="1" required value="<?= htmlspecialchars((string) $editingProduct['damaged_quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-alert-limit" class="mb-1 block text-sm font-medium text-slate-700">ئاگادارکردنەوە لە</label>
                            <input id="edit-alert-limit" name="alert_limit" type="number" min="0" max="2147483647" step="1" required value="<?= htmlspecialchars((string) ($editingProduct['alert_limit'] ?? 5), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label for="edit-category-id" class="mb-1 block text-sm font-medium text-slate-700">براند</label>
                            <select id="edit-category-id" name="category_id" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                                <option value="">براند دیاری نەکراوە</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int) $category['category_id'] ?>" <?= (string) $editingProduct['category_id'] === (string) $category['category_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $category['category_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="edit-expiry-date" class="mb-1 block text-sm font-medium text-slate-700">بەرواری بەسەرچوون</label>
                            <input id="edit-expiry-date" name="expiry_date" type="date" value="<?= htmlspecialchars((string) ($editingProduct['expiry_date'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div class="sm:col-span-2 lg:col-span-4">
                            <button type="submit" class="rounded-lg bg-amber-500 px-5 py-2.5 font-semibold text-slate-950 hover:bg-amber-400">پاشەکەوتکردن</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
            <?php if (!$products_load_failed): ?>
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-6 py-5">
                        <h3 class="text-lg font-bold text-slate-800">هەموو کاڵاکان</h3>
                        <label class="sr-only" for="products-search">گەڕان بە ناوی کاڵا، بارکۆد یان براند</label>
                        <input
                            id="products-search"
                            type="search"
                            autocomplete="off"
                            placeholder="گەڕان بە ناوی کاڵا، بارکۆد یان براند..."
                            class="mt-4 w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-600 focus:outline-none focus:ring-2 focus:ring-amber-200"
                        >
                        <p id="products-search-count" class="mt-2 text-sm text-slate-500" aria-live="polite">
                            <?= number_format(count($products)) ?> کاڵا
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1080px] text-right text-sm">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th scope="col" class="px-6 py-4 font-semibold">بارکۆد</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">بەرواری بەسەرچوون</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">ناوی کاڵا</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">ناوی براند</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">نرخی کڕین (€)</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">نرخی کڕین ($)</th>
                                    <th scope="col" class="px-6 py-4 font-semibold">نرخی فرۆشتن ($)</th>
                                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                        <th scope="col" class="px-6 py-4 font-semibold">کردار</th>
                                    <?php endif; ?>
                                    <th scope="col" class="px-6 py-4 font-semibold">بڕی کۆگا</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php if ($products): ?>
                                    <?php foreach ($products as $product): ?>
                                        <tr class="transition hover:bg-slate-50" data-product-search="<?= htmlspecialchars((string) $product['product_name'] . ' ' . (string) ($product['barcode'] ?? '') . ' ' . (string) ($product['category_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                            <td class="whitespace-nowrap px-6 py-4 font-mono text-slate-500">
                                                <?= htmlspecialchars((string) ($product['barcode'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4">
                                                <?= !empty($product['expiry_date']) ? htmlspecialchars((string) $product['expiry_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?>
                                            </td>
                                            <td class="px-6 py-4 font-semibold text-slate-800">
                                                <?= htmlspecialchars((string) $product['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </td>
                                            <td class="px-6 py-4">
                                                <?= htmlspecialchars((string) ($product['category_name'] ?? 'بەش دیاری نەکراوە'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 font-medium">
                                                <?= $product['purchase_price_eur'] !== null ? '€' . number_format((float) $product['purchase_price_eur'], 2) : '—' ?>
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 font-medium">
                                                <?= $product['purchase_price_usd'] !== null ? '$' . number_format((float) $product['purchase_price_usd'], 2) : '—' ?>
                                            </td>
                                            <td class="whitespace-nowrap px-6 py-4 font-medium">
                                                $<?= number_format((float) $product['selling_price'], 2) ?>
                                            </td>
                                            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                                <td class="whitespace-nowrap px-6 py-4">
                                                    <div class="flex items-center gap-2">
                                                        <a href="products.php?edit=<?= (int) $product['product_id'] ?>" class="rounded-lg bg-amber-100 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-200">نوێکردنەوە</a>
                                                        <form method="post" action="products.php" onsubmit="return confirm('دڵنیایت؟ کاڵاکە و مێژووی پەیوەندیداری فرۆشتن و کڕینی بە هەمیشەیی دەسڕدرێتەوە.');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['products_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="delete_product">
                                                            <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                                                            <button type="submit" class="rounded-lg bg-red-100 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-200">سڕینەوە</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
                                            <td class="whitespace-nowrap px-6 py-4">
                                                <div class="flex items-center gap-2">
                                                    <span class="rounded-full px-3 py-1 font-semibold <?= (float) $product['stock_quantity'] > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>">
                                                        <?= number_format((float) $product['stock_quantity']) ?>
                                                    </span>
                                                    <span class="text-xs text-slate-500" title="کۆی بڕی زیانی تۆمارکراو">
                                                        زیان: <?= number_format((int) $product['damaged_quantity']) ?>
                                                    </span>
                                                    <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                                                        <form method="post" action="products.php" class="flex items-center gap-1">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['products_csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="mark_damaged">
                                                            <input type="hidden" name="product_id" value="<?= (int) $product['product_id'] ?>">
                                                            <label class="sr-only" for="damaged-<?= (int) $product['product_id'] ?>">بڕی زیان‌پێگەیشتوو</label>
                                                            <input id="damaged-<?= (int) $product['product_id'] ?>" name="quantity" type="number" min="1" value="<?= (int) ($productDamagedInputs[(int) $product['product_id']] ?? 1) ?>" required class="w-16 rounded-lg border border-slate-300 px-2 py-1 text-center text-sm" aria-label="بڕی زیان‌پێگەیشتوو">
                                                            <button type="submit" class="whitespace-nowrap rounded-lg border border-red-200 px-2 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">زیان پێگەشت</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="products-search-empty" hidden>
                                        <td colspan="<?= ($_SESSION['role'] ?? '') === 'admin' ? 9 : 8 ?>" class="px-6 py-12 text-center text-slate-500">
                                            هیچ کاڵایەک بەو گەڕانە نەدۆزرایەوە.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-12 text-center text-slate-500">
                                            هێشتا هیچ کاڵایەک تۆمار نەکراوە.
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
</div>
</div>
<script>
    const productsSearch = document.getElementById('products-search');
    const productRows = Array.from(document.querySelectorAll('tbody [data-product-search]'));
    const productsSearchEmpty = document.getElementById('products-search-empty');
    const productsSearchCount = document.getElementById('products-search-count');

    productsSearch?.addEventListener('input', () => {
        const query = productsSearch.value.trim().toLocaleLowerCase();
        let visibleCount = 0;

        productRows.forEach((row) => {
            const matches = row.dataset.productSearch.toLocaleLowerCase().includes(query);
            row.hidden = !matches;
            row.classList.toggle('hidden', !matches);
            if (matches) {
                visibleCount++;
            }
        });

        productsSearchEmpty.hidden = productRows.length === 0 || visibleCount > 0;
        productsSearchEmpty.classList.toggle('hidden', productRows.length === 0 || visibleCount > 0);
        productsSearchCount.textContent = `${visibleCount.toLocaleString()} کاڵا`;
    });
</script>
</body>
</html>
