<?php
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['backup_csrf_token'])) {
    $_SESSION['backup_csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/includes/store_settings.php';

function settings_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function settings_backup_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function settings_backup_write($stream, string $content): void
{
    $length = strlen($content);
    $written = 0;
    while ($written < $length) {
        $result = fwrite($stream, substr($content, $written));
        if ($result === false || $result === 0) {
            throw new RuntimeException('Unable to write database backup data.');
        }
        $written += $result;
    }
}

function settings_download_database_backup(PDO $pdo): void
{
    $temporaryStream = fopen('php://temp/maxmemory:5242880', 'w+b');
    if ($temporaryStream === false) {
        throw new RuntimeException('Unable to create temporary database backup stream.');
    }

    try {
        settings_backup_write(
            $temporaryStream,
            "-- Database backup generated on " . gmdate('Y-m-d H:i:s') . " UTC\n"
            . "SET NAMES utf8mb4;\n"
            . "SET FOREIGN_KEY_CHECKS=0;\n\n"
        );

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $tableName) {
            if (!is_string($tableName)) {
                throw new RuntimeException('Unexpected table name returned by MySQL.');
            }

            $quotedTable = settings_backup_quote_identifier($tableName);
            $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
            if (!is_array($createRow) || !isset($createRow[1]) || !is_string($createRow[1])) {
                throw new RuntimeException('Unable to read table definition for ' . $tableName . '.');
            }

            $isView = preg_match('/\ACREATE\s+VIEW\b/i', ltrim($createRow[1])) === 1;
            settings_backup_write($temporaryStream, '-- Structure for ' . $quotedTable . "\n");
            settings_backup_write(
                $temporaryStream,
                ($isView ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $quotedTable . ";\n"
            );
            settings_backup_write($temporaryStream, $createRow[1] . ";\n\n");

            if ($isView) {
                continue;
            }

            $rows = $pdo->query('SELECT * FROM ' . $quotedTable);
            while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns = array_map('settings_backup_quote_identifier', array_keys($row));
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                        continue;
                    }

                    $quotedValue = $pdo->quote((string) $value, PDO::PARAM_STR);
                    if ($quotedValue === false) {
                        throw new RuntimeException('Unable to encode a value from table ' . $tableName . '.');
                    }
                    $values[] = $quotedValue;
                }

                settings_backup_write(
                    $temporaryStream,
                    'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', $values) . ");\n"
                );
            }
            settings_backup_write($temporaryStream, "\n");
        }

        settings_backup_write($temporaryStream, "SET FOREIGN_KEY_CHECKS=1;\n");
        if (!rewind($temporaryStream)) {
            throw new RuntimeException('Unable to rewind temporary database backup stream.');
        }

        $filename = 'supermarket-backup-' . date('Y-m-d-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        fpassthru($temporaryStream);
    } finally {
        fclose($temporaryStream);
    }
}

function settings_save_logo_upload(array $file): ?string
{
    if (!isset($file['error']) || !is_int($file['error'])) {
        throw new InvalidArgumentException('زانیاریی فایلەکە دروست نییە.');
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('بارکردنی لۆگۆ سەرکەوتوو نەبوو. تکایە وێنەیەکی بچووکتر هەڵبژێرە.');
    }
    if (!isset($file['tmp_name'], $file['size']) || !is_string($file['tmp_name']) || !is_int($file['size'])) {
        throw new InvalidArgumentException('فایلی لۆگۆ دروست نییە.');
    }
    if ($file['size'] < 1 || $file['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('قەبارەی لۆگۆ دەبێت لە ١ بایت تا ٥ مێگابایت بێت.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('فایلی لۆگۆ لە شوێنی بارکردنی پەسەندکراوەوە نییە.');
    }

    $imageInfo = getimagesize($file['tmp_name']);
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($file['tmp_name']);
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (
        $imageInfo === false
        || !isset($allowedTypes[$mimeType])
        || ($imageInfo['mime'] ?? '') !== $mimeType
        || $imageInfo[0] < 1
        || $imageInfo[1] < 1
        || $imageInfo[0] > 6000
        || $imageInfo[1] > 6000
    ) {
        throw new InvalidArgumentException('تەنها وێنەی JPG، PNG یان WEBP بۆ لۆگۆ ڕێگەپێدراوە.');
    }

    $directory = __DIR__ . '/uploads/store-logo';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create store logo directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Unable to store uploaded store logo.');
    }

    return 'uploads/store-logo/' . $filename;
}

$logoDirectory = __DIR__ . '/uploads/store-logo';
function settings_delete_old_logo(string $logoPath, string $logoDirectory): void
{
    if (
        preg_match('~\Auploads/store-logo/[a-f0-9]{32}\.(?:jpg|png|webp)\z~D', $logoPath)
        && is_file($logoDirectory . DIRECTORY_SEPARATOR . basename($logoPath))
        && !unlink($logoDirectory . DIRECTORY_SEPARATOR . basename($logoPath))
    ) {
        error_log('Unable to remove old store logo.');
    }
}

$page_title = 'ڕێکخستنەکانی فرۆشگا';
$errorMessage = '';
$successMessage = $_SESSION['settings_success'] ?? '';
unset($_SESSION['settings_success']);

try {
    $settings = load_store_settings();
} catch (RuntimeException $exception) {
    error_log('Loading store settings page failed: ' . $exception->getMessage());
    $settings = store_settings_defaults();
    $errorMessage = 'نەتوانرا ڕێکخستنەکان بخوێندرێنەوە. تکایە شوێنی پاشەکەوتکردن بپشکنە.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['settings_action'] ?? '') === 'download_backup'
) {
    $backupToken = $_POST['backup_csrf_token'] ?? '';
    if (!is_string($backupToken) || !hash_equals($_SESSION['backup_csrf_token'], $backupToken)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } else {
        try {
            require_once __DIR__ . '/includes/db.php';
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('PDO connection is unavailable.');
            }

            settings_download_database_backup($pdo);
            exit;
        } catch (Throwable $exception) {
            error_log('Database backup failed: ' . $exception->getMessage());
            http_response_code(500);
            $errorMessage = 'دروستکردنی باکئەپ سەرکەوتوو نەبوو. تکایە پەیوەندی داتابەیس بپشکنە و دووبارە هەوڵ بدەرەوە.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $storeName = $_POST['store_name'] ?? '';
    $storeAddress = $_POST['store_address'] ?? '';
    $storePhone = $_POST['store_phone'] ?? '';
    $storeOwner = $_POST['store_owner'] ?? '';

    $storeName = is_string($storeName) ? trim($storeName) : '';
    $storeAddress = is_string($storeAddress) ? trim($storeAddress) : '';
    $storePhone = is_string($storePhone) ? trim($storePhone) : '';
    $storeOwner = is_string($storeOwner) ? trim($storeOwner) : '';
    $storeLogo = $settings['store_logo'];
    $removeLogo = ($_POST['remove_logo'] ?? '') === '1';

    if (empty($_SESSION['settings_csrf_token'])) {
        $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
    }

    if (!is_string($token) || !hash_equals($_SESSION['settings_csrf_token'], $token)) {
        $errorMessage = 'داواکارییەکە پشتڕاست نەکرایەوە. پەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
        $settings = [
            'store_name' => $storeName,
            'store_address' => $storeAddress,
            'store_phone' => $storePhone,
            'store_owner' => $storeOwner,
            'store_logo' => $storeLogo,
        ];
    } elseif ($storeName === '') {
        $errorMessage = 'ناوی مارکێت پێویستە.';
        $settings = [
            'store_name' => $storeName,
            'store_address' => $storeAddress,
            'store_phone' => $storePhone,
            'store_owner' => $storeOwner,
            'store_logo' => $storeLogo,
        ];
    } elseif (
        mb_strlen($storeName, 'UTF-8') > 255 ||
        mb_strlen($storeAddress, 'UTF-8') > 1000 ||
        mb_strlen($storePhone, 'UTF-8') > 500 ||
        mb_strlen($storeOwner, 'UTF-8') > 255
    ) {
        $errorMessage = 'زانیارییەکان لە سنووری درێژی پێویست زیاترە.';
        $settings = [
            'store_name' => $storeName,
            'store_address' => $storeAddress,
            'store_phone' => $storePhone,
            'store_owner' => $storeOwner,
            'store_logo' => $storeLogo,
        ];
    } else {
        $newLogoPath = null;
        $settingsSaveAttempted = false;
        try {
            $dataDirectory = __DIR__ . '/data';
            if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0750, true) && !is_dir($dataDirectory)) {
                throw new RuntimeException('Unable to create store settings directory.');
            }

            $upload = $_FILES['store_logo'] ?? null;
            if ($upload !== null) {
                if (!is_array($upload)) {
                    throw new InvalidArgumentException('فایلی لۆگۆ دروست نییە.');
                }
                $newLogoPath = settings_save_logo_upload($upload);
            }
            if ($newLogoPath !== null) {
                $storeLogo = $newLogoPath;
            } elseif ($removeLogo) {
                $storeLogo = '';
            }

            $settingsSaveAttempted = true;
            save_store_settings([
                'store_name' => $storeName,
                'store_address' => $storeAddress,
                'store_phone' => $storePhone,
                'store_owner' => $storeOwner,
                'store_logo' => $storeLogo,
            ]);

            if ($storeLogo !== ($settings['store_logo'] ?? '')) {
                settings_delete_old_logo($settings['store_logo'] ?? '', $logoDirectory);
            }
            $_SESSION['settings_success'] = 'ڕێکخستنەکانی فرۆشگا پاشەکەوت کران.';
            header('Location: settings.php');
            exit;
        } catch (InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
            if ($newLogoPath !== null && !$settingsSaveAttempted) {
                settings_delete_old_logo($newLogoPath, $logoDirectory);
            }
            $settings = [
                'store_name' => $storeName,
                'store_address' => $storeAddress,
                'store_phone' => $storePhone,
                'store_owner' => $storeOwner,
                'store_logo' => $settings['store_logo'] ?? '',
            ];
        } catch (RuntimeException $exception) {
            error_log('Saving store settings page failed: ' . $exception->getMessage());
            $errorMessage = 'نەتوانرا ڕێکخستنەکان پاشەکەوت بکرێن. مۆڵەتی نووسین لە فۆڵدەری data و uploads/store-logo بپشکنە.';
            if ($newLogoPath !== null && !$settingsSaveAttempted) {
                settings_delete_old_logo($newLogoPath, $logoDirectory);
            }
            $settings = [
                'store_name' => $storeName,
                'store_address' => $storeAddress,
                'store_phone' => $storePhone,
                'store_owner' => $storeOwner,
                'store_logo' => $settings['store_logo'] ?? '',
            ];
        }
    }
}

if (empty($_SESSION['settings_csrf_token'])) {
    $_SESSION['settings_csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">ڕێکخستنەکانی فرۆشگا</h2>
            <p class="mt-1 text-sm text-slate-500">ناو، ناونیشان و ژمارەی مۆبایلی مارکێت لێرە ڕێک بخە.</p>
        </div>

        <?php if ($successMessage !== ''): ?>
            <div role="status" class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                <?= settings_escape($successMessage) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== ''): ?>
            <div role="alert" class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800">
                <?= settings_escape($errorMessage) ?>
            </div>
        <?php endif; ?>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5">
                <h3 class="text-lg font-bold text-slate-800">زانیاریی گشتی</h3>
                <p class="mt-1 text-sm text-slate-500">ڕێکخستنەکان لە فایلێکی JSONی ناوخۆیی پاشەکەوت دەکرێن و ناوی سایدبار نوێ دەکەنەوە.</p>
            </div>
            <form method="post" action="settings.php" enctype="multipart/form-data" class="space-y-5 p-6">
                <input type="hidden" name="csrf_token" value="<?= settings_escape($_SESSION['settings_csrf_token']) ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
                <div>
                    <label for="store_name" class="mb-2 block text-sm font-semibold text-slate-700">ناوی مارکێت</label>
                    <input id="store_name" name="store_name" type="text" required maxlength="255" value="<?= settings_escape($settings['store_name']) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                </div>
                <div>
                    <label for="store_address" class="mb-2 block text-sm font-semibold text-slate-700">ناونیشان</label>
                    <textarea id="store_address" name="store_address" rows="3" maxlength="1000" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100"><?= settings_escape($settings['store_address']) ?></textarea>
                </div>
                <div>
                    <label for="store_phone" class="mb-2 block text-sm font-semibold text-slate-700">ژمارەی مۆبایل</label>
                    <textarea id="store_phone" name="store_phone" rows="3" maxlength="500" dir="ltr" class="w-full rounded-xl border border-slate-300 px-4 py-3 text-left focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100"><?= settings_escape($settings['store_phone']) ?></textarea>
                    <p class="mt-1 text-xs text-slate-500">هەر ژمارەیەک لە ڕیزێکی جیا بنووسە.</p>
                </div>
                <div>
                    <label for="store_owner" class="mb-2 block text-sm font-semibold text-slate-700">ناوی خاوەنی فرۆشگا</label>
                    <input id="store_owner" name="store_owner" type="text" maxlength="255" value="<?= settings_escape($settings['store_owner']) ?>" class="w-full rounded-xl border border-slate-300 px-4 py-3 focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100">
                </div>
                <div>
                    <label for="store_logo" class="mb-2 block text-sm font-semibold text-slate-700">لۆگۆی فرۆشگا</label>
                    <input id="store_logo" name="store_logo" type="file" accept="image/jpeg,image/png,image/webp" class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 file:ml-3 file:rounded-lg file:border-0 file:bg-amber-100 file:px-3 file:py-2 file:font-semibold file:text-amber-900">
                    <p class="mt-1 text-xs text-slate-500">JPG، PNG یان WEBP؛ زۆرترین قەبارە ٥ مێگابایت.</p>
                    <?php
                    $logoPath = $settings['store_logo'] ?? '';
                    $hasLogo = is_string($logoPath)
                        && preg_match('~\Auploads/store-logo/[a-f0-9]{32}\.(?:jpg|png|webp)\z~D', $logoPath)
                        && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoPath));
                    ?>
                    <?php if ($hasLogo): ?>
                        <div class="mt-4 flex items-center gap-4">
                            <img src="<?= settings_escape($logoPath) ?>" alt="لۆگۆی فرۆشگا" class="h-20 w-20 rounded-lg border border-slate-200 bg-white object-contain p-2">
                            <label class="flex items-center gap-2 text-sm text-red-700">
                                <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300">
                                لابردنی لۆگۆی ئێستا
                            </label>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="flex justify-end border-t border-slate-100 pt-5">
                    <button type="submit" class="rounded-xl bg-amber-500 px-6 py-3 font-bold text-slate-950 hover:bg-amber-400">
                        پاشەکەوتکردنی ڕێکخستنەکان
                    </button>
                </div>
            </form>
        </section>

        <section id="database-backup" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 bg-gradient-to-l from-amber-50 to-white px-6 py-6">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-xl text-amber-700">
                        <i class="fa-solid fa-database" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-slate-800">باکئەپکردنی داتابەیس</h3>
                        <p class="mt-1 text-sm leading-6 text-slate-600">
                            پێناسەی خشتەکان و هەموو ڕیزەکانیان بە فۆرماتی SQL و کۆدپەیجی UTF-8 دابەزێنە.
                        </p>
                    </div>
                </div>
            </div>
            <div class="space-y-5 p-6">
                <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm leading-6 text-sky-900">
                    <h4 class="mb-1 font-bold"><i class="fa-solid fa-circle-info ml-2" aria-hidden="true"></i>تێبینیی گرنگ</h4>
                    <p>فایلی باکئەپ زانیارییە گرنگەکانی فرۆشگا و بەکارهێنەران لەخۆدەگرێت؛ لە شوێنێکی پارێزراو هەڵیبگرە و لەگەڵ کەسی تر هاوبەشی مەکە.</p>
                </div>
                <ul class="space-y-2 text-sm text-slate-600">
                    <li class="flex items-start gap-2"><i class="fa-solid fa-check mt-1 text-emerald-600" aria-hidden="true"></i><span>فایلەکە ڕاستەوخۆ بۆ کۆمپیوتەرەکەت دادەبەزێت و لە سێرڤەر هەڵناگیرێت.</span></li>
                    <li class="flex items-start gap-2"><i class="fa-solid fa-check mt-1 text-emerald-600" aria-hidden="true"></i><span>بەردەوام باکئەپ وەربگرە و لە شوێنێکی جیاواز بە نهێنی هەڵیبگرە.</span></li>
                </ul>
                <form method="post" action="settings.php#database-backup" class="border-t border-slate-100 pt-5">
                    <input type="hidden" name="settings_action" value="download_backup">
                    <input type="hidden" name="backup_csrf_token" value="<?= settings_escape($_SESSION['backup_csrf_token']) ?>">
                    <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-amber-500 px-6 py-3 font-bold text-slate-950 transition hover:bg-amber-400 focus:outline-none focus:ring-4 focus:ring-amber-200 sm:w-auto">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>
                        دروستکردن و داگرتنی باکئەپ
                    </button>
                </form>
            </div>
        </section>
    </div>
</main>
</div>
</div>
</body>
</html>
