<?php
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

Web::requireOwner();
$engine = engine();
$store = store();
$config = $engine->config();
$outbox = new Outbox($store, new SmtpTransport());

if (Web::posted()) {
    switch (Web::post('action')) {
        case 'profile':
            $name = Web::post('owner_name');
            $tz = Web::post('timezone');
            $dow = (int) Web::post('slot_dow', '1');
            $hour = (int) Web::post('slot_hour', '9');
            if ($name === '' || !Setup::validTimezone($tz) || $dow < 1 || $dow > 7 || $hour < 0 || $hour > 23) {
                Web::flash('Enter your name, and choose a timezone, a day and an hour.', 'bad');
                break;
            }
            $store->locked(static function () use ($store, $engine, $name, $tz, $dow, $hour): void {
                $config = $store->read('config');
                $slotChanged = [$config['timezone'], $config['slot_dow'], $config['slot_hour']] !== [$tz, $dow, $hour];
                $config['owner_name'] = $name;
                $config['timezone'] = $tz;
                $config['slot_dow'] = $dow;
                $config['slot_hour'] = $hour;
                $store->write('config', $config);
                if ($slotChanged) {
                    $state = $engine->state();
                    if ($state['armed'] && $state['cycle'] === null) {
                        $state['next_due_at'] = $engine->nextSlot($config, max(Clock::now(), (int) $state['paused_until']));
                        $engine->saveState($state);
                    }
                    $store->log('settings.slot', 'The weekly check-in time was changed to ' . Schedule::DAYS[$dow] . ' at ' . hourLabel($hour) . " ({$tz}).");
                }
            });
            Web::flash('Saved.');
            break;

        case 'email_start':
            $which = Web::post('which') === 'backup_email' ? 'backup_email' : 'owner_email';
            $address = strtolower(Web::post('address'));
            $other = strtolower((string) ($config[$which === 'backup_email' ? 'owner_email' : 'backup_email'] ?? ''));
            if (!Web::validEmail($address)) {
                Web::flash('Enter a valid email address.', 'bad');
            } elseif ($address === $other) {
                Web::flash('Your two addresses have to be different, ideally with different providers.', 'bad');
            } elseif (Setup::startEmailCode($store, $outbox, $which, $address)) {
                Web::flash("A code has been emailed to {$address}. Enter it below to confirm the address.");
            } else {
                Web::flash("The code could not be sent to {$address}. Check the address and the mail settings.", 'bad');
            }
            break;

        case 'email_finish':
            $old = (string) $config['owner_email'];
            $result = Setup::finishEmailCode($store, Web::post('code'));
            Web::flash($result === true ? 'Address confirmed and saved.' : $result, $result === true ? 'ok' : 'bad');
            $now = (string) $store->read('config')['owner_email'];
            if ($result === true && $now !== $old) {
                // Tell the old address, in case the change was not made by the owner.
                $outbox->send($old, (new Email('Your Death Plan Switch now emails a different address'))
                    ->p("The main address for your Death Plan Switch was changed to {$now}. Check-in emails will no longer come to this address.")
                    ->p('If you did not make this change, sign in and change it back, then change your password.'));
            }
            break;

        case 'email_cancel':
            $config['pending_email'] = null;
            $store->write('config', $config);
            break;

        case 'backup_remove':
            $config['backup_email'] = '';
            $store->write('config', $config);
            Web::flash('Backup address removed. Reminders now go to your main address only.', 'warn');
            break;

        case 'smtp':
            [$smtp, $errors] = Setup::smtpFromPost((array) ($config['smtp'] ?? []));
            if ($errors) {
                Web::flash(implode(' ', $errors), 'bad');
                break;
            }
            // Prove the new settings work before they replace the ones the switch depends on.
            if (!$outbox->send((string) $config['owner_email'], Messages::smtpTest(), 'owner', $smtp)) {
                $error = $store->read('mail')['last_send_error']['error'] ?? 'unknown error';
                Web::flash('Not saved: a test email could not be sent with those settings. The mail server said: ' . $error, 'bad');
                break;
            }
            $config['smtp'] = $smtp;
            $store->write('config', $config);
            $store->log('settings.smtp', 'Mail settings were changed.');
            Web::flash('Saved. A test email has been sent to ' . $config['owner_email'] . '.');
            break;

        case 'baseurl':
            $url = rtrim(Web::post('base_url'), '/');
            if (!preg_match('~^https?://[^\s/]+(/[^\s]*)?$~i', $url)) {
                Web::flash('Enter the full address of this install, starting with https://', 'bad');
                break;
            }
            $config['base_url'] = $url;
            $store->write('config', $config);
            Web::flash('Saved. Links in emails will use this address.');
            break;

        case 'probe':
            $result = Setup::probe($store, $engine, (string) $config['base_url']);
            if ($result['result'] === 'protected' || $result['result'] === 'outside') {
                Web::flash('Checked: the data folder cannot be read from the web.');
            } elseif ($result['result'] === 'exposed') {
                Web::flash('Files in the data folder can be opened in a browser. See "Protecting the data folder" in the README.', 'bad');
            } else {
                Web::flash('The server could not request its own address, so it could not check. Use the browser check below instead.', 'warn');
            }
            break;

        case 'probe_browser':
            $found = Web::post('found');
            if ($found === 'protected' || $found === 'exposed') {
                Setup::recordBrowserProbe($store, $engine, $found);
                Web::flash($found === 'protected'
                    ? 'Checked from your browser: the data folder cannot be read from the web.'
                    : 'Your browser was able to open a file in the data folder. See "Protecting the data folder" in the README.', $found === 'protected' ? 'ok' : 'bad');
            } else {
                Web::flash('Your browser could not complete the check.', 'warn');
            }
            break;

        case 'rekey':
            $config['tick_key'] = bin2hex(random_bytes(20));
            $config['health_key'] = bin2hex(random_bytes(20));
            $store->write('config', $config);
            Web::flash('New addresses made. The old ones no longer work: update anything that used them.', 'warn');
            break;

        case 'password':
            $check = Web::tryPassword((string) ($_POST['current'] ?? ''));
            $new = (string) ($_POST['new'] ?? '');
            if ($check !== true) {
                Web::flash('Not changed. ' . $check, 'bad');
            } elseif (($problem = Setup::passwordProblem($new)) !== null) {
                Web::flash($problem, 'bad');
            } else {
                $config['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                $store->write('config', $config);
                $store->log('settings.password', 'The owner password was changed.');
                Web::signIn();
                Web::flash('Password changed. Any other signed-in browser has been signed out.');
            }
            break;
    }
    Web::redirect('index.php?p=settings');
}

$state = $engine->state();
$smtp = (array) ($config['smtp'] ?? []);
$pending = is_array($config['pending_email'] ?? null) && $config['pending_email']['expires_at'] > Clock::now() ? $config['pending_email'] : null;
$probe = (array) ($state['probe'] ?? []);
$probeUrl = Probe::url($store, (string) $config['base_url']);
$tickUrl = $engine->url($config, 'tick.php', ['key' => $config['tick_key'] ?? '']);
$healthUrl = $engine->url($config, 'health.php', ['key' => $config['health_key'] ?? '']);

Web::$title = 'Settings: Death Plan Switch';
?>
<h1>Settings</h1>

<h2>You, and your weekly email</h2>
<form method="post" action="index.php?p=settings">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="profile">
  <div class="field">
    <label for="owner_name">Your name</label>
    <input type="text" id="owner_name" name="owner_name" value="<?= e($config['owner_name']) ?>" required maxlength="120">
    <span class="help">As your contacts know you. It appears in every message they receive.</span>
  </div>
  <div class="field">
    <label for="timezone">Timezone</label>
    <select id="timezone" name="timezone">
<?php foreach (Setup::timezones() as $tz): ?>
      <option<?= $tz === $config['timezone'] ? ' selected' : '' ?>><?= e($tz) ?></option>
<?php endforeach ?>
    </select>
  </div>
  <div class="field">
    <span class="label" id="slot-label">Send my check-in email every</span>
    <div class="inline" role="group" aria-labelledby="slot-label">
      <select name="slot_dow" aria-label="Day">
<?php foreach (Schedule::DAYS as $n => $day): ?>
        <option value="<?= $n ?>"<?= $n === (int) $config['slot_dow'] ? ' selected' : '' ?>><?= e($day) ?></option>
<?php endforeach ?>
      </select>
      <span>at</span>
      <select name="slot_hour" aria-label="Hour">
<?php for ($h = 0; $h < 24; $h++): ?>
        <option value="<?= $h ?>"<?= $h === (int) $config['slot_hour'] ? ' selected' : '' ?>><?= e(hourLabel($h)) ?></option>
<?php endfor ?>
      </select>
    </div>
    <span class="help">Pick a time you reliably read email. The interval is fixed at one week.</span>
  </div>
  <div class="actions"><button type="submit">Save</button></div>
</form>

<h2>Your email addresses</h2>
<p>Check-in emails go to your main address. Reminders also go to the backup, so one failed mailbox cannot set the switch off. Use a different provider for the backup.</p>
<?php if ($pending): ?>
<div class="panel">
  <h3>Enter the code sent to <?= e($pending['address']) ?></h3>
  <form method="post" action="index.php?p=settings">
    <?= Web::csrfField() ?>
    <input type="hidden" name="action" value="email_finish">
    <div class="inline">
      <label for="code" class="visually-hidden">Code</label>
      <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required>
      <button type="submit">Confirm address</button>
    </div>
  </form>
  <form method="post" action="index.php?p=settings"><?= Web::csrfField() ?><input type="hidden" name="action" value="email_cancel"><button class="link" type="submit">Cancel this change</button></form>
</div>
<?php endif ?>
<dl class="facts">
  <dt>Main</dt><dd><?= e($config['owner_email']) ?></dd>
  <dt>Backup</dt><dd><?= !empty($config['backup_email']) ? e($config['backup_email']) : 'None' ?></dd>
</dl>
<details>
  <summary>Change an address</summary>
  <form method="post" action="index.php?p=settings">
    <?= Web::csrfField() ?>
    <input type="hidden" name="action" value="email_start">
    <div class="field">
      <label for="which">Which one</label>
      <select id="which" name="which">
        <option value="backup_email">Backup address</option>
        <option value="owner_email">Main address</option>
      </select>
    </div>
    <div class="field">
      <label for="address">New address</label>
      <input type="email" id="address" name="address" required maxlength="254">
      <span class="help">A code is emailed to it. The change takes effect when you enter the code here.</span>
    </div>
    <button type="submit">Send the code</button>
  </form>
<?php if (!empty($config['backup_email'])): ?>
  <form method="post" action="index.php?p=settings"><?= Web::csrfField() ?><input type="hidden" name="action" value="backup_remove"><button class="link" type="submit">Remove the backup address</button></form>
<?php endif ?>
</details>

<h2>Mail server</h2>
<form method="post" action="index.php?p=settings">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="smtp">
<?php require __DIR__ . '/_smtp_fields.php' ?>
  <div class="actions"><button type="submit">Test and save</button></div>
  <p class="small muted">The new settings are saved only if a test email to <?= e($config['owner_email']) ?> is accepted.</p>
</form>

<h2>Scheduler</h2>
<p>The switch does nothing by itself. Every 15 minutes, something has to run it. <?= $state['last_tick_at'] ? 'It last ran ' . e(relative($state['last_tick_at'])) . ', by ' . ($state['last_tick_source'] === 'cron' ? 'cron' : 'the trigger address') . '.' : 'It has never run.' ?></p>
<h3>Crontab line</h3>
<p>Goes in the crontab of the user the web pages run as. Open it with <code><?= e(Web::crontabCommand()) ?></code>.</p>
<pre class="cron"><?= e(Web::cronLine()) ?></pre>
<?php if (Web::phpCli() === null): ?>
<p class="small">The path to PHP could not be detected. Replace <code>php</code> at the start of the command with the full path your host gives you.</p>
<?php endif ?>
<h3>No cron on your hosting?</h3>
<p>Have any outside scheduler request this address every 15 minutes instead (hourly at the least). It does the same job. Treat the address as a secret.</p>
<pre class="cron"><?= e($tickUrl) ?></pre>
<h3>Be told when it stops</h3>
<p>If the scheduler stops, the switch cannot tell you. Point an uptime monitor at this address. It answers 200 when all is well, and 503 when the scheduler has stopped, mail is failing or the data folder is exposed. It reveals nothing else.</p>
<pre class="cron"><?= e($healthUrl) ?></pre>
<form method="post" action="index.php?p=settings"><?= Web::csrfField() ?><input type="hidden" name="action" value="rekey"><button class="link" type="submit" data-confirm="Make new addresses? The current ones will stop working.">Make new secret addresses</button></form>

<h2>Data folder</h2>
<p>Your contacts and their messages are stored as files in <code><?= e($store->dir()) ?></code>. They must not be readable from the web. The switch tests this every day by requesting one of its own files.</p>
<dl class="facts">
  <dt>Last result</dt>
  <dd><?php
    $label = [
        'protected' => 'Blocked from the web',
        'outside' => 'Outside the web folder',
        'exposed' => 'Readable from the web',
        'unknown' => !empty($probe['browser_ok_at']) ? 'Blocked (checked from your browser)' : 'Could not be checked by the server',
    ][$probe['result'] ?? ''] ?? 'Not checked yet';
    echo e($label);
    if (!empty($probe['checked_at'])) {
        echo ', ' . e(relative((int) $probe['checked_at']));
    }
  ?></dd>
</dl>
<div class="actions">
  <form method="post" action="index.php?p=settings"><?= Web::csrfField() ?><input type="hidden" name="action" value="probe"><button class="button-quiet" type="submit">Check again from the server</button></form>
<?php if ($probeUrl !== null): ?>
  <form method="post" action="index.php?p=settings" data-probe-url="<?= e($probeUrl) ?>" data-probe-secret="<?= e(Probe::secret($store)) ?>">
    <?= Web::csrfField() ?>
    <input type="hidden" name="action" value="probe_browser">
    <input type="hidden" name="found" value="">
    <button class="button-quiet" type="submit">Check from this browser</button>
  </form>
<?php endif ?>
</div>

<h2>Address of this install</h2>
<form method="post" action="index.php?p=settings">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="baseurl">
  <div class="field">
    <label for="base_url">Web address</label>
    <input type="url" id="base_url" name="base_url" value="<?= e($config['base_url']) ?>" required>
    <span class="help">Used to build the links in emails. Change it if you move the install.</span>
  </div>
  <div class="actions"><button type="submit">Save</button></div>
</form>

<h2>Password</h2>
<form method="post" action="index.php?p=settings">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="password">
  <div class="field">
    <label for="current">Current password</label>
    <input type="password" id="current" name="current" autocomplete="current-password" required>
  </div>
  <div class="field">
    <label for="new">New password</label>
    <input type="password" id="new" name="new" autocomplete="new-password" minlength="<?= Setup::MIN_PASSWORD ?>" required>
    <span class="help">At least <?= Setup::MIN_PASSWORD ?> characters.</span>
  </div>
  <div class="actions"><button type="submit">Change password</button></div>
</form>
