<?php
$homeHref = ($_SESSION['role'] ?? '') === 'admin' ? 'index.php' : 'pos.php';
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$showHomeLink = $currentPage !== 'index.php' || ($_GET['section'] ?? 'dashboard') !== 'dashboard';
?>
<div class="flex min-w-0 flex-1 flex-col overflow-y-auto">
    <header id="app-top-header" class="flex items-center justify-between bg-white px-4 py-4 shadow-sm sm:px-8">
        <div class="flex min-w-0 items-center gap-3">
            <h1 class="truncate text-lg font-bold text-slate-800 sm:text-xl"><?php echo htmlspecialchars($page_title ?? 'بەڕێوەبردنی سیستەم', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h1>
            <?php if ($showHomeLink): ?>
                <a href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-slate-100 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-200 focus:outline-none focus:ring-2 focus:ring-amber-300" aria-label="گەڕانەوە بۆ سەرەکی">
                    <i class="fa-solid fa-house" aria-hidden="true"></i>
                    <span class="hidden sm:inline">سەرەکی</span>
                </a>
            <?php endif; ?>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <span class="hidden text-sm font-medium text-slate-600 sm:inline">بەڕێوەبەر: <?php echo htmlspecialchars((string) ($_SESSION['full_name'] ?? 'بەکارهێنەر'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-500 font-bold text-white" aria-hidden="true">
                <?php echo htmlspecialchars(isset($_SESSION['full_name']) ? mb_substr((string) $_SESSION['full_name'], 0, 1, 'UTF-8') : 'U', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
            </div>
        </div>
    </header>