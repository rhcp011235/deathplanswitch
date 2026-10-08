<?php
/**
 * Where a contact confirms (or declines) their role, once at setup and
 * once a year after that. Their message is never shown here.
 */
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

$engine = engine();
$token = Web::isPost() ? Web::post('t') : Web::get('t');
$config = $engine->config();
$owner = (string) ($config['owner_name'] ?? '');
$result = null;
$contact = null;

if (Web::isPost()) {
    $result = $engine->contactAnswer($token, Web::post('answer') === 'yes');
} else {
    $contact = $engine->contacts()->forToken($token);
}
$purpose = (string) ($contact['token']['purpose'] ?? 'confirm');

Web::$title = 'Death Plan Switch';
?>
<?php if ($result !== null && $result['accepted']): ?>
<h1>Confirmed</h1>
<p>Thank you, <?= e($result['contact']['name']) ?>. <?= e($owner) ?> has been told. There is nothing else to do.</p>
<p>If <?= e($owner) ?> ever stops checking in, you will hear from the same email address that sent you this request. Add it to your contacts so it is not mistaken for spam.</p>

<?php elseif ($result !== null): ?>
<h1>Done</h1>
<p>You will not be contacted by <?= e($owner) ?>'s Death Plan Switch. <?= e($owner) ?> has been told.</p>

<?php elseif ($contact !== null): ?>
<h1><?= $purpose === 'reconfirm' ? 'Still happy to be ' . e($owner) . "'s " . e(Contacts::role($contact)) . '?' : e($owner) . ' has listed you as their ' . e(Contacts::role($contact)) ?></h1>
<p><?= e($owner) ?> uses Death Plan Switch, a small app on their own server. Every week it asks <?= e($owner) ?> to check in. If they stop, it emails the people they have chosen.</p>
<?php if ($contact['kind'] === 'nok'): ?>
<p>As next of kin you would be asked first. If <?= e($owner) ?> misses a check-in and three days of reminders, you would get one email asking whether everything is all right, with two buttons to answer.</p>
<?php else: ?>
<p>If <?= e($owner) ?> stops checking in and their next of kin cannot confirm they are well, you would get one email telling you what <?= e($owner) ?> needs you to do.</p>
<?php endif ?>
<p>Confirming tells <?= e($owner) ?> that <strong><?= e($purpose === 'email_change' ? (string) $contact['pending_email'] : (string) $contact['email']) ?></strong> reaches you, and that you are willing.</p>
<form method="post" action="confirm.php">
  <input type="hidden" name="t" value="<?= e($token) ?>">
  <div class="actions">
    <button class="button-big" type="submit" name="answer" value="yes">Yes, I confirm</button>
    <button class="button-quiet" type="submit" name="answer" value="no">No, leave me out</button>
  </div>
</form>

<?php else: ?>
<h1>This link is no longer active</h1>
<p>It has either been used already or has expired. If you meant to confirm, ask the person who listed you to send a new request.</p>
<?php endif ?>
