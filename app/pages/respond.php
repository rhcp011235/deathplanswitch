<?php
/**
 * Where the next of kin answers "All fine" or "Something's wrong".
 *
 * As with the check-in, following the emailed link changes nothing. The
 * answer is only recorded when a button on this page is pressed.
 */
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

$engine = engine();
$token = Web::isPost() ? Web::post('t') : Web::get('t');
$lean = Web::get('a') === 'wrong' ? 'wrong' : 'fine';
$config = $engine->config();
$owner = (string) ($config['owner_name'] ?? '');
$answer = null;
$live = false;
$others = [];

if (Web::isPost()) {
    $answer = $engine->nokAnswer($token, Web::post('answer'));
} else {
    $live = $engine->verifyTokenIsLive($token);
}
if ($live || $answer === 'wrong') {
    foreach ($engine->contacts()->confirmed() as $contact) {
        if ($contact['kind'] !== 'nok') {
            $others[] = $contact['name'] . ' (' . $contact['role_label'] . ')';
        }
    }
}

$fine = '<form method="post" action="respond.php"><input type="hidden" name="t" value="' . e($token) . '"><input type="hidden" name="answer" value="fine">'
    . '<h2>All fine</h2><p>You have seen or spoken to ' . e($owner) . ' and they are all right. Nobody else is contacted, and ' . e($owner) . ' is told you answered.</p>'
    . '<div class="actions"><button class="button-big' . ($lean === 'fine' ? '' : ' button-quiet') . '" type="submit">All fine</button></div></form>';
$wrong = '<form method="post" action="respond.php"><input type="hidden" name="t" value="' . e($token) . '"><input type="hidden" name="answer" value="wrong">'
    . '<h2>Something\'s wrong</h2><p>' . e($owner) . ' cannot run their business at the moment. '
    . ($others ? 'These people are sent their instructions straight away: ' . e(implode(', ', $others)) . '.' : 'The people they chose are sent their instructions straight away.')
    . ' You are sent yours too.</p>'
    . '<div class="actions"><button class="button-big' . ($lean === 'wrong' ? '' : ' button-quiet') . '" type="submit">Something\'s wrong</button></div></form>';

Web::$title = 'Death Plan Switch';
?>
<?php if ($answer === 'fine'): ?>
<h1>Thank you</h1>
<p>Your answer has been recorded. Nobody else has been contacted, and <?= e($owner) ?> has been told that you answered.</p>

<?php elseif ($answer === 'wrong'): ?>
<h1>Thank you</h1>
<p>Your answer has been recorded. <?= $others ? 'These people have been sent their instructions: ' . e(implode(', ', $others)) . '.' : 'The people ' . e($owner) . ' chose have been sent their instructions.' ?></p>
<p>You have been emailed a message from <?= e($owner) ?> as well, with what to do next.</p>

<?php elseif ($live): ?>
<h1>Is <?= e($owner) ?> all right?</h1>
<p><?= e($owner) ?> has not answered their weekly check-in or its reminders. You are their next of kin, so you are asked first. Nothing has been sent to anyone else.</p>
<?= $lean === 'wrong' ? $wrong . '<hr>' . $fine : $fine . '<hr>' . $wrong ?>
<hr>
<p class="small muted">Not sure yet? You can come back to this page. If neither you nor <?= e($owner) ?> answers by the time given in the email, the others are sent their instructions.</p>

<?php else: ?>
<h1>This link is no longer active</h1>
<p>It has either been used already, or the check it belonged to has been settled. You do not need to do anything.</p>
<?php endif ?>
