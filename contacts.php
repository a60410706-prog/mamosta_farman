<?php
session_start();

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$page_title = 'پەیوەندییەکان';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
    <div class="mx-auto max-w-4xl">
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-slate-800">پەیوەندییەکان</h2>
            <p class="mt-1 text-sm text-slate-500">بەڕێوەبردنی کڕیاران و دابینکەرە تۆمارکراوەکان.</p>
        </div>
        <div class="grid gap-5 sm:grid-cols-2">
            <a href="customers.php" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-blue-300">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-blue-100 text-2xl text-blue-700">
                    <i class="fa-solid fa-user-group" aria-hidden="true"></i>
                </span>
                <h3 class="mt-4 text-lg font-bold text-slate-800">کڕیاران</h3>
                <p class="mt-1 text-sm text-slate-500">بینین و بەڕێوەبردنی زانیاری کڕیاران.</p>
            </a>
            <a href="suppliers.php" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-emerald-300">
                <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-100 text-2xl text-emerald-700">
                    <i class="fa-solid fa-truck-field" aria-hidden="true"></i>
                </span>
                <h3 class="mt-4 text-lg font-bold text-slate-800">دابینکەرەکان</h3>
                <p class="mt-1 text-sm text-slate-500">بینین و بەڕێوەبردنی زانیاری دابینکەرەکان.</p>
            </a>
        </div>
    </div>
</main>
</body>
</html>
