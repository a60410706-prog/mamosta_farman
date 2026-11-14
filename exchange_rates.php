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

if (empty($_SESSION['exchange_rates_csrf_token'])) {
    $_SESSION['exchange_rates_csrf_token'] = bin2hex(random_bytes(32));
}

function exchange_rates_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$page_title = 'بەڕێوەبردنی نرخی دراوەکان';
$errorMessage = '';
$successMessage = $_SESSION['exchange_rates_success'] ?? '';
$submittedRates = [];
$submittedEurRate = '';
unset($_SESSION['exchange_rates_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_rate') {
    $token = $_POST['csrf_token'] ?? '';
    $idInput = $_POST['id'] ?? null;
    $rateInput = $_POST['exchange_rate'] ?? null;
    $id = is_string($idInput) ? filter_var($idInput, FILTER_VALIDATE_INT) : false;

    if ($id !== false && $id > 0 && is_string($rateInput)) {
        $submittedRates[$id] = $rateInput;
    }

    if (!is_string($token) || !hash_equals($_SESSION['exchange_rates_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif ($id === false || $id < 1) {
        $errorMessage = 'دراوی داواکراو دروست نییە.';
    } elseif (
        !is_string($rateInput)
        || !preg_match('/^\d+(?:\.\d{1,6})?$/D', $rateInput)
        || !is_finite((float) $rateInput)
        || (float) $rateInput <= 0
        || (float) $rateInput > 999999999999.999999
    ) {
        $errorMessage = 'نرخی ئاڵوگۆڕ دەبێت ژمارەیەکی گەورەتر لە سفر و تا ٦ خانەی دەیی بێت.';
    } else {
        try {
            $existsStmt = $pdo->prepare(
                'SELECT id FROM exchange_rates WHERE id = :id LIMIT 1'
            );
            $existsStmt->execute(['id' => $id]);

            if ($existsStmt->fetchColumn() === false) {
                $errorMessage = 'دراوی داواکراو نەدۆزرایەوە.';
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE exchange_rates
                     SET exchange_rate = :exchange_rate,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $updateStmt->bindValue(':exchange_rate', $rateInput, PDO::PARAM_STR);
                $updateStmt->bindValue(':id', $id, PDO::PARAM_INT);
                $updateStmt->execute();

                $_SESSION['exchange_rates_success'] = 'نرخی ئاڵوگۆڕ بە سەرکەوتوویی نوێ کرایەوە.';
                header('Location: exchange_rates.php');
                exit;
            }
        } catch (PDOException $exception) {
            error_log('Updating exchange rate failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە نوێکردنەوەی نرخی ئاڵوگۆڕدا ڕوویدا.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_eur_rate') {
    $token = $_POST['csrf_token'] ?? '';
    $rateInput = $_POST['exchange_rate'] ?? null;
    $submittedEurRate = is_string($rateInput) ? $rateInput : '';

    if (!is_string($token) || !hash_equals($_SESSION['exchange_rates_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } elseif (
        !is_string($rateInput)
        || !preg_match('/^\d+(?:\.\d{1,4})?$/D', $rateInput)
        || !is_finite((float) $rateInput)
        || (float) $rateInput <= 0
        || (float) $rateInput > 99999999999.9999
    ) {
        $errorMessage = 'نرخی EUR دەبێت USD ـێکی گەورەتر لە سفر بۆ ١ EUR بێت، تا ٤ خانەی دەیی.';
    } else {
        try {
            $eurExists = $pdo->query(
                'SELECT id FROM exchange_rates WHERE currency_code = \'EUR\' LIMIT 1'
            )->fetchColumn();

            if ($eurExists !== false) {
                $errorMessage = 'نرخی EUR پێشتر زیادکراوە؛ تکایە لە خشتەکە نوێی بکەرەوە.';
            } else {
                $insertEurRate = $pdo->prepare(
                    'INSERT INTO exchange_rates (currency_code, currency_name, exchange_rate)
                     VALUES (\'EUR\', :currency_name, :exchange_rate)'
                );
                $insertEurRate->execute([
                    'currency_name' => 'یۆرۆ',
                    'exchange_rate' => $rateInput,
                ]);
                $_SESSION['exchange_rates_success'] = 'نرخی EUR بە سەرکەوتوویی زیادکرا.';
                header('Location: exchange_rates.php');
                exit;
            }
        } catch (PDOException $exception) {
            error_log('Adding EUR exchange rate failed: ' . $exception->getMessage());
            $errorMessage = 'هەڵەیەک لە زیادکردنی نرخی EUR دا ڕوویدا.';
        }
    }
}

$exchangeRates = [];
$ratesLoadFailed = false;
try {
    $exchangeRates = $pdo->query(
        'SELECT id, currency_code, currency_name, exchange_rate, updated_at
         FROM exchange_rates
         ORDER BY currency_code'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Loading exchange rates failed: ' . $exception->getMessage());
    http_response_code(500);
    $ratesLoadFailed = true;
    $errorMessage = 'هەڵەیەک لە خوێندنەوەی نرخی دراوەکاندا ڕوویدا. تکایە بوونی خشتەی exchange_rates بپشکنە.';
}
$eurRateExists = false;
foreach ($exchangeRates as $rate) {
    if ($rate['currency_code'] === 'EUR') {
        $eurRateExists = true;
        break;
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-7xl" dir="rtl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">نرخی ئاڵوگۆڕی دراوەکان</h2>
            <p class="mt-1 text-sm text-slate-500">نرخی هەر دراو بەرامبەر USD بنووسە؛ بۆ قازانج، نرخەکەی ١ EUR بە USD لێرە نوێ بکەرەوە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= exchange_rates_escape((string) $successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= exchange_rates_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if (!$ratesLoadFailed): ?>
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h3 class="text-lg font-bold text-slate-800">لیستی دراوەکان</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-right text-sm">
                        <thead class="bg-slate-50 text-slate-600">
                            <tr>
                                <th scope="col" class="px-5 py-4 font-semibold">کۆد</th>
                                <th scope="col" class="px-5 py-4 font-semibold">ناوی دراو</th>
                                <th scope="col" class="px-5 py-4 font-semibold">USD بۆ ١ دانەی ئەم دراوە</th>
                                <th scope="col" class="px-5 py-4 font-semibold">دوایین نوێکردنەوە</th>
                                <th scope="col" class="px-5 py-4 font-semibold">کردار</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            <?php foreach ($exchangeRates as $rate): ?>
                                <?php
                                $rateId = (int) $rate['id'];
                                $displayRate = $submittedRates[$rateId] ?? number_format((float) $rate['exchange_rate'], 6, '.', '');
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="whitespace-nowrap px-5 py-4 font-mono font-semibold">
                                        <?= exchange_rates_escape((string) $rate['currency_code']) ?>
                                    </td>
                                    <td class="px-5 py-4 font-medium">
                                        <?= exchange_rates_escape((string) $rate['currency_name']) ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <form id="exchange-rate-form-<?= $rateId ?>" method="post" action="exchange_rates.php" class="flex flex-wrap items-center gap-2">
                                            <input type="hidden" name="csrf_token" value="<?= exchange_rates_escape($_SESSION['exchange_rates_csrf_token']) ?>">
                                            <input type="hidden" name="action" value="update_rate">
                                            <input type="hidden" name="id" value="<?= $rateId ?>">
                                            <label class="sr-only" for="exchange-rate-<?= $rateId ?>">
                                                نرخی <?= exchange_rates_escape((string) $rate['currency_name']) ?>
                                            </label>
                                            <input
                                                id="exchange-rate-<?= $rateId ?>"
                                                name="exchange_rate"
                                                type="number"
                                                min="0.000001"
                                                max="999999999999.999999"
                                                step="0.000001"
                                                inputmode="decimal"
                                                required
                                                value="<?= exchange_rates_escape($displayRate) ?>"
                                                class="w-44 rounded-lg border border-slate-300 px-3 py-2 text-left focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200"
                                            >
                                        </form>
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-4 text-slate-500">
                                        <?= exchange_rates_escape((string) $rate['updated_at']) ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <button type="submit" form="exchange-rate-form-<?= $rateId ?>" class="whitespace-nowrap rounded-lg bg-amber-400 px-4 py-2 font-semibold text-slate-950 hover:bg-amber-300">
                                            پاشەکەوتکردن
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$exchangeRates): ?>
                                <tr>
                                    <td colspan="5" class="px-5 py-12 text-center text-slate-500">
                                        هیچ دراوێک لە خشتەی exchange_rates تۆمار نەکراوە.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php if (!$eurRateExists): ?>
                <section id="eurRatePanel" class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="eur-rate-title">
                    <h3 id="eur-rate-title" class="text-lg font-bold text-slate-800">زیادکردنی نرخی EUR</h3>
                    <p class="mt-1 text-sm text-slate-500">نرخی USD بۆ ١ EUR بنووسە. ئەم ڕێژەیە لە هەژمارکردنی قازانجی کاڵاکاندا بەکاردێت و هەر کاتێک بگۆڕێت دەتوانیت لە خشتەکە نوێی بکەیتەوە.</p>
                    <form method="post" action="exchange_rates.php#eurRatePanel" class="mt-4 flex flex-wrap items-end gap-3">
                        <input type="hidden" name="csrf_token" value="<?= exchange_rates_escape($_SESSION['exchange_rates_csrf_token']) ?>">
                        <input type="hidden" name="action" value="add_eur_rate">
                        <div>
                            <label for="new-eur-rate" class="mb-1 block text-sm font-medium text-slate-700">USD بۆ ١ EUR</label>
                            <input
                                id="new-eur-rate"
                                name="exchange_rate"
                                type="number"
                                min="0.0001"
                                max="99999999999.9999"
                                step="0.0001"
                                inputmode="decimal"
                                required
                                value="<?= exchange_rates_escape($submittedEurRate) ?>"
                                class="w-48 rounded-lg border border-slate-300 px-3 py-2 text-left focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-200"
                            >
                        </div>
                        <button type="submit" class="rounded-lg bg-amber-400 px-4 py-2 font-semibold text-slate-950 hover:bg-amber-300">
                            زیادکردن و پاشەکەوت
                        </button>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</div>
</div>
</body>
</html>
