<?php
/**
 * The check-in page the weekly email links to.
 *
 * Opening this page does nothing. Email scanners follow links, so only
 * pressing the button, which sends a POST, counts as a check-in.
 */
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

$engine = engine();
$token = Web::isPost() ? Web::post('t') : Web::get('t');
$config = $engine->config();
$tz = (string) ($config['timezone'] ?? 'UTC');
$result = null;
$live = false;

if (Web::isPost()) {
    $result = $engine->checkInByToken($token);
} else {
    $live = $engine->checkinTokenIsLive($token);
}

Web::$title = 'Check in: Death Plan Switch';
?>
<?php if ($result !== null): ?>
<h1>Checked in</h1>
<?php if ($result['stage'] === 'fired'): ?>
<p>Your switch had fired. Everyone who was contacted has now been told it was a false alarm<?= $result['told'] ? ': ' . e(implode(', ', $result['told'])) : '' ?>.</p>
<p>Your contacts have read their messages, so any pointer in them has been seen. <a href="index.php">Sign in</a> to review what needs changing.</p>
<?php elseif ($result['told']): ?>
<p><?= e(implode(', ', $result['told'])) ?> had been asked whether you are all right, and <?= count($result['told']) === 1 ? 'has' : 'have' ?> now been told it was a false alarm. Nobody else was contacted.</p>
<?php else: ?>
<p>Thank you. Nobody was contacted.</p>
<?php endif ?>
<?php if ($result['paused_until']): ?>
<p>Your switch is paused until <?= e(when($result['paused_until'], $tz, 'l j F')) ?>.</p>
<?php elseif ($result['next_due_at']): ?>
<p>Your next check-in email arrives on <?= e(when($result['next_due_at'], $tz, 'l j F \a\t g:ia')) ?>.</p>
<?php endif ?>

<?php elseif ($live): ?>
<h1>Are you there, <?= e(strtok((string) ($config['owner_name'] ?? ''), ' ') ?: 'owner') ?>?</h1>
<p>Press the button to check in. Opening this page is not enough.</p>
<form method="post" action="checkin.php">
  <input type="hidden" name="t" value="<?= e($token) ?>">
  <div class="actions"><button class="button-big" type="submit">I am here: check in</button></div>
</form>

<?php else: ?>
<h1>This link is no longer active</h1>
<p>Each check-in link works once, and for 14 days. If you have a newer email from Death Plan Switch, use the link in that one.</p>
<p>You can also <a href="index.php">sign in</a> and check in from the dashboard.</p>
<?php endif ?>
