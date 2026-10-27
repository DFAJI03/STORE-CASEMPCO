<?php
// includes/header.php
// Top of every page: loads the stylesheet and draws the left side menu.
// Set $pageTitle before including this file.
$pageTitle   = $pageTitle ?? 'Sari Sari Store Faji';
$currentPage = basename($_SERVER['SCRIPT_NAME']);   // e.g. "products.php"
require_once __DIR__ . '/icons.php';                // the built-in icons

// The menu. Each line is:  'page file' => ['Label', 'your image file'],
// Put your images in the "img" folder and change the file names here to match.
$menu = [
    'index.php'         => ['Dashboard',     'img/home.png',      'home'],
    'sales.php'         => ['Sales',         'img/sales.png',     'cart'],
    'sales_history.php' => ['Sales History', 'img/history.png',   'clock'],
    'products.php'      => ['Products',      'img/products.png',  'box'],
    'purchases.php'     => ['Purchases',     'img/purchases.png', 'purchase'],
    'customers.php'     => ['Customer Debt', 'img/debt.png',      'wallet'],
    'users.php'         => ['Users',         'img/users.png',     'users'],     // admins only
    'daily_report.php'  => ['Sales Report',  'img/report.png',    'report'],    // admins only
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> &middot; Sari Sari Store Faji</title>
    <?php
    // Adds ?v=<time the file last changed>, so the browser always loads the newest CSS.
    $asset = function (string $file): string {
        return $file . '?v=' . (@filemtime(dirname(__DIR__) . '/' . $file) ?: 1);
    };
    ?>
    <?php if (!empty($useBootstrap)): ?>
        <!-- Pages already converted to Bootstrap: Bootstrap first, then our extra styles. -->
        <link rel="stylesheet" href="<?= e($asset('css/bootstrap.min.css')) ?>">
        <link rel="stylesheet" href="<?= e($asset('css/app.css')) ?>">
    <?php else: ?>
        <!-- Pages not converted yet still use the old stylesheet. -->
        <link rel="stylesheet" href="<?= e($asset('css/style.css')) ?>">
    <?php endif; ?>
    <!-- The side menu and page layout: one file for every page, loaded last. -->
    <link rel="stylesheet" href="<?= e($asset('css/menu.css')) ?>">
</head>
<body>
<div class="app-shell">

    <aside class="sidenav">
        <div class="sidenav__brand">
            <?php if (is_file(dirname(__DIR__) . '/img/logo.png')): ?>
                <!-- Your logo beside the name: save the image as img/logo.png -->
                <img class="sidenav__logo" src="img/logo.png" alt="">
            <?php endif; ?>
            <span>CASEMPCO</span>
        </div>

        <?php foreach ($menu as $file => [$label, $icon, $svg]): ?>
            <?php
            // Show only the buttons this role may open (see PAGE_ACCESS in includes/auth.php).
            if (!can_access($file)) {
                continue;
            }
            ?>
            <?php
            // The receipt page counts as part of Sales History.
            $isActive = $currentPage === $file
                || ($file === 'sales_history.php' && $currentPage === 'receipt.php')
                || ($file === 'customers.php' && $currentPage === 'customer.php');
            ?>
            <a href="<?= e($file) ?>" class="nav-btn <?= $isActive ? 'is-active' : '' ?>">
                <span class="nav-btn__icon">
                    <?php if (is_file(dirname(__DIR__) . '/' . $icon)): ?>
                        <img src="<?= e($icon) ?>" alt="">
                    <?php else: ?>
                        <!-- No image file yet: show a built-in icon instead. -->
                        <?= icon($svg, 26, 'nav-btn__svg') ?>
                    <?php endif; ?>
                </span>
                <span class="nav-btn__label"><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="sidenav__user">
            <div class="sidenav__user-name"><?= e(current_user()['name'] ?? '') ?></div>
            <div><?= e(ucfirst(str_replace('_', ' ', current_user()['role'] ?? ''))) ?></div>
            <a class="sidenav__logout" href="auth/logout.php"><?= icon('logout', 14) ?> Log out</a>
        </div>
    </aside>

    <main class="main-content">