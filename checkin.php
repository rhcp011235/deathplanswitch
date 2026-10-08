<?php
/** Death Plan Switch: the page the weekly check-in email links to. The page itself is app/pages/checkin.php. */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

DPS\Web::page('checkin');
