<?php
/**
 * The dashboard: the state of the switch, and the few things the owner
 * does to it (check in, arm, pause, disarm).
 */
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

Web::requireOwner();
$engine = engine();

if (Web::posted()) {
    switch (Web::post('action')) {
        case 'checkin':
            $result = $engine->checkIn('the dashboard');
            Web::flash($result['told']
                ? 'Checked in. ' . implode(', ', $result['told']) . (count($result['told']) === 1 ? ' has' : ' have') . ' been told it was a false alarm.'
                : 'Checked in.');
            break;

        case 'arm':
            $errors = $engine->arm();
            if ($errors) {
                Web::flash('The switch cannot be armed yet. Still to do: ' . implode('; ', $errors) . '.', 'bad');
            } else {
                Web::flash('The switch is armed. A confirmation email is on its way to you.');
            }
            break;

        case 'pause':
            $result = $engine->pause((int) Web::post('days', '7'));
            if ($result === null) {
                Web::flash('Only an armed switch can be paused.', 'bad');
            } else {
                Web::flash('Paused. The switch starts again by itself on ' . when($result['until'], (string) $engine->config()['timezone']) . '.');
            }
            break;

        case 'resume':
            $engine->resume();
            Web::flash('The pause has ended. The switch is active again.');
            break;

        case 'disarm':
            $check = Web::tryPassword((string) ($_POST['password'] ?? ''));
            if ($check !== true) {
                Web::flash('The switch was not disarmed. ' . $check, 'bad');
            } else {
                $told = $engine->disarm();
                Web::flash('The switch is disarmed. Nobody will be contacted until you arm it again.'
                    . ($told ? ' ' . implode(', ', $told) . (count($told) === 1 ? ' has' : ' have') . ' been told it was a false alarm.' : ''), 'warn');
            }
            break;

        case 'dismiss_after_fire':
            $engine->dismissAfterFireNotice();
            break;
    }
    Web::redirect('index.php');
}

$config = $engine->config();
$tz = (string) ($config['timezone'] ?? 'UTC');
$status = $engine->status();
$state = $status['state'];
$mode = $status['mode'];
$plan = $status['plan'];
$cycle = $state['cycle'];
$contacts = $engine->contacts()->all();
$nok = $engine->contacts()->nok();
$nokName = $nok['name'] ?? 'Your next of kin';
$warnings = array_values(array_filter($engine->warnings(), static fn (array $w): bool => $w['level'] === 'warn'));
$checklist = $engine->checklist();
$canArm = array_filter($checklist, static fn (array $i): bool => !$i['ok'] && $i['blocking']) === [];
$long = 'l j F \a\t g:ia';
$short = 'D j M, g:ia';

// How far along the six-day track we are, in nominal hours (0 to 144).
$progress = 0.0;
if ($cycle !== null && $plan !== []) {
    $nominal = ['email' => 0, 'reminder1' => 24, 'reminder2' => 48, 'verify' => 72, 'nok_reminder' => 120, 'fire' => 144];
    $now = Clock::now();
    $keys = array_keys($nominal);
    $progress = 144.0;
    for ($i = 0; $i < count($keys) - 1 && $cycle['stage'] !== 'fired'; $i++) {
        $from = $plan[$keys[$i]]['at'];
        $to = $plan[$keys[$i + 1]]['at'];
        if ($now < $to) {
            $share = $to > $from ? max(0, $now - $from) / ($to - $from) : 0;
            $progress = $nominal[$keys[$i]] + $share * ($nominal[$keys[$i + 1]] - $nominal[$keys[$i]]);
            break;
        }
    }
}
$nextStep = null;
foreach ($plan as $key => $step) {
    if (!$step['done']) {
        $nextStep = $key;
        break;
    }
}

$notified = [];
foreach ($contacts as $contact) {
    if (isset($cycle['notified'][$contact['id']])) {
        $notified[] = $contact['name'];
    }
}

Web::$title = 'Death Plan Switch';
?>
<section class="state state-<?= e($mode) ?>" aria-live="polite">
<?php if ($mode === 'setup'): ?>
  <h1>Not armed</h1>
  <p>Nobody will be contacted while the switch is not armed. <?= $canArm ? 'Everything is in place: arm it when you are ready.' : 'Finish the list below, then arm it.' ?></p>

<?php elseif ($mode === 'armed'): ?>
  <h1>Armed</h1>
  <p>Your next check-in email arrives on <?= e(when($state['next_due_at'], $tz, $long)) ?>.</p>
  <div class="actions">
    <form method="post" action="index.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="checkin"><button class="button" type="submit">Check in now</button></form>
  </div>

<?php elseif ($mode === 'paused'): ?>
  <h1>Paused</h1>
  <p>Until <?= e(when($state['paused_until'], $tz, $long)) ?>. No emails are sent while the switch is paused, and nobody will be contacted. It starts again by itself.</p>
  <div class="actions">
    <form method="post" action="index.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="resume"><button class="button" type="submit">End the pause now</button></form>
  </div>

<?php elseif ($mode === 'overdue'): ?>
  <h1><?= empty($cycle['reminders']) ? 'Time to check in' : 'Check-in overdue' ?></h1>
  <p>You were emailed on <?= e(when($cycle['started_at'], $tz, $long)) ?> and have not checked in. <?= e($nokName) ?> will be asked whether you are all right on <?= e(when($plan['verify']['at'], $tz, $long)) ?>.</p>
  <div class="actions">
    <form method="post" action="index.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="checkin"><button class="button button-big" type="submit">Check in now</button></form>
  </div>

<?php elseif ($mode === 'verify'): ?>
  <h1>Your next of kin has been asked</h1>
  <p><?= e($nokName) ?> was asked whether you are all right on <?= e(when($cycle['verify']['asked_at'], $tz, $long)) ?><?= empty($cycle['verify']['reached']) ? ', but the email could not be sent' : '' ?>. Unless one of you answers, your other contacts receive their messages on <?= e(when($plan['fire']['at'], $tz, $long)) ?>.</p>
  <div class="actions">
    <form method="post" action="index.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="checkin"><button class="button button-big" type="submit">Check in and call it off</button></form>
  </div>

<?php else: ?>
  <h1>The switch has fired</h1>
  <p>On <?= e(when($cycle['fired_at'], $tz, $long)) ?>, <?= $cycle['fired_reason'] === 'nok_wrong' ? e($nokName) . ' answered that something is wrong' : 'neither you nor ' . e($nokName) . ' had confirmed you were well' ?>. <?= $notified ? 'Messages have gone to ' . e(implode(', ', $notified)) . '.' : 'No message has been delivered yet.' ?></p>
  <div class="actions">
    <form method="post" action="index.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="checkin"><button class="button button-big" type="submit">I am here: tell them it was a false alarm</button></form>
  </div>
<?php endif ?>
</section>

<?php if ($plan !== []): ?>
<div class="track">
  <progress max="144" value="<?= e(round($progress, 1)) ?>" aria-hidden="true"></progress>
  <ol aria-label="<?= $cycle === null ? 'What happens if you miss the next check-in' : 'Where this check-in stands' ?>">
<?php
    $labels = [
        'email' => ['t0', 'Check-in email'],
        'reminder1' => ['t24', 'Reminder'],
        'reminder2' => ['t48', 'Last reminder'],
        'verify' => ['t72', 'Next of kin is asked'],
        'nok_reminder' => ['t120', 'Next of kin reminded'],
        'fire' => ['t144', 'Other contacts are told'],
    ];
    foreach ($labels as $key => [$class, $label]):
        $step = $plan[$key];
?>
    <li class="<?= $class ?><?= $step['done'] ? ' done' : '' ?><?= $key === $nextStep && $cycle !== null ? ' next' : '' ?>">
      <strong><?= e($label) ?></strong>
      <?= e(when($step['at'], $tz, $short)) ?>
    </li>
<?php endforeach ?>
  </ol>
<?php if ($cycle === null): ?>
  <p class="small muted">This is what happens if you miss the next check-in. Your other contacts would be told 6 days after the email, at most 13 days after your last check-in.</p>
<?php endif ?>
</div>
<?php endif ?>

<?php if ($state['after_fire_at'] !== null): ?>
<div class="notice notice-warn">
  <p><strong>Your contacts have read their messages.</strong> Any link or pointer in those messages has now been seen. If a message pointed to a document, move the document so the old link stops working, and change its password. Then update the message.</p>
  <form method="post" action="index.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="dismiss_after_fire"><button class="link" type="submit">I have done this</button></form>
</div>
<?php endif ?>

<?php if (count($warnings) === 1): ?>
<div class="notice notice-warn"><p><?= e($warnings[0]['text']) ?></p></div>
<?php elseif ($warnings): ?>
<div class="notice notice-warn">
  <ul>
<?php foreach ($warnings as $warning): ?>
    <li><?= e($warning['text']) ?></li>
<?php endforeach ?>
  </ul>
</div>
<?php endif ?>

<?php if ($mode === 'setup'): ?>
<h2>Before you can arm it</h2>
<ul class="checklist">
<?php foreach ($checklist as $item): ?>
  <li class="<?= $item['ok'] ? 'is-ok' : ($item['blocking'] ? 'is-todo' : 'is-optional') ?>">
    <span class="mark" aria-hidden="true"><?= $item['ok'] ? '✓' : ($item['blocking'] ? '✗' : '○') ?></span>
    <span><?= e($item['label']) ?><span class="visually-hidden"><?= $item['ok'] ? ' (done)' : ($item['blocking'] ? ' (to do)' : ' (optional)') ?></span></span>
<?php if (!$item['ok'] && $item['hint'] !== ''): ?>
    <span class="hint"><?= e($item['hint']) ?></span>
<?php endif ?>
  </li>
<?php endforeach ?>
</ul>
<div class="actions">
<?php if ($canArm): ?>
  <form method="post" action="index.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="arm"><button class="button-big" type="submit">Arm the switch</button></form>
<?php else: ?>
  <a class="button" href="index.php?p=contacts">Go to contacts</a>
  <a class="button button-quiet" href="index.php?p=settings">Go to settings</a>
<?php endif ?>
</div>
<?php endif ?>

<h2>Who is told</h2>
<?php if ($contacts === []): ?>
<p>Nobody yet. <a href="index.php?p=contacts">Add your next of kin and your technical 2IC</a>.</p>
<?php else: ?>
<div class="rows">
<?php foreach ($contacts as $contact): ?>
  <div class="row">
    <div>
      <h3><a href="index.php?p=contact&amp;id=<?= e($contact['id']) ?>"><?= e($contact['name']) ?></a></h3>
      <p class="role"><?= e($contact['role_label']) ?>. <?= $contact['kind'] === 'nok'
          ? 'Asked first, after you miss a check-in and 72 hours of reminders.'
          : 'Sent their message if neither you nor your next of kin confirms you are well.' ?></p>
    </div>
    <div class="side">
<?php if ($contact['status'] === 'confirmed'): ?>
      <span class="tag tag-ok">Confirmed</span>
<?php elseif ($contact['status'] === 'declined'): ?>
      <span class="tag tag-bad">Declined</span>
<?php else: ?>
      <span class="tag tag-warn">Not confirmed</span>
<?php endif ?>
    </div>
  </div>
<?php endforeach ?>
</div>
<?php endif ?>

<h2>Details</h2>
<dl class="facts">
  <dt>Last check-in</dt>
  <dd><?= $state['last_checkin_at'] ? e(when($state['last_checkin_at'], $tz)) . ', by ' . e($state['last_checkin_via']) : 'None yet' ?></dd>
  <dt>Weekly email</dt>
  <dd><?= e(Schedule::DAYS[(int) ($config['slot_dow'] ?? 1)]) ?>s at <?= e(hourLabel((int) ($config['slot_hour'] ?? 9))) ?> (<?= e($tz) ?>)</dd>
  <dt>Scheduler last ran</dt>
  <dd><?= $state['last_tick_at'] ? e(ucfirst(relative($state['last_tick_at']))) . ', by ' . ($state['last_tick_source'] === 'cron' ? 'cron' : 'the trigger URL') : 'Never' ?></dd>
</dl>

<?php if ($state['armed']): ?>
<h2>Going away?</h2>
<p>Pause the switch for a set number of days. It starts again by itself, so it cannot be left off by accident. The longest pause is <?= Schedule::PAUSE_MAX_DAYS ?> days.</p>
<form method="post" action="index.php">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="pause">
  <div class="inline">
    <label for="days">Pause for</label>
    <input type="number" id="days" name="days" min="1" max="<?= Schedule::PAUSE_MAX_DAYS ?>" value="7" required>
    <span>days</span>
    <button class="button-quiet" type="submit"><?= $mode === 'paused' ? 'Change the pause' : 'Pause the switch' ?></button>
  </div>
</form>

<h2>Disarm</h2>
<details>
  <summary>Turn the switch off</summary>
  <p>A disarmed switch sends no check-in emails and contacts nobody. It stays off until you arm it again. If you only need a break, pause it instead.</p>
  <form method="post" action="index.php">
    <?= Web::csrfField() ?>
    <input type="hidden" name="action" value="disarm">
    <div class="field">
      <label for="disarm-password">Your password</label>
      <input type="password" id="disarm-password" name="password" autocomplete="current-password" required>
    </div>
    <button class="button-danger" type="submit">Disarm the switch</button>
  </form>
</details>
<?php endif ?>
