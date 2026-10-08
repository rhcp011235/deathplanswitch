<?php
/**
 * Death Plan Switch: the owner's pages.
 *
 *   index.php              the dashboard
 *   index.php?p=contacts   and so on
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use DPS\Web;

$page = Web::get('p', 'switch');

if ($page === 'logout') {
    Web::signOut();
    Web::redirect('index.php?p=login');
}

$pages = ['switch', 'login', 'contacts', 'contact', 'review', 'settings', 'history'];
Web::page(in_array($page, $pages, true) ? $page : 'switch');
