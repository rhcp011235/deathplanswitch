<?php
/**
 * The installer. Four short steps:
 *
 *   1. Check the server, including that the data folder is blocked from
 *      the web. This happens before anything private is written.
 *   2. The owner: name, email, password, weekly time.
 *   3. The mail server, proved by a code emailed to the owner.
 *   4. The scheduler: the crontab line, and confirmation of the first run.
 *
 * It closes for good once finished. A half-finished install can only be
 * continued by someone with the owner password.
 */
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

$store = store();
$engine = engine();
$config = $store->exists('config') ? $store->read('config') : [];

if (!empty($config['installed_at'])) {
    Web::redirect('index.php');
}

Web::session();
$errors = [];
$outbox = new Outbox($store, new SmtpTransport());
$requirements = Setup::requirements();
$requirementsOk = array_filter($requirements, static fn (array $r): bool => !$r['ok']) === [];

// ---------------------------------------------------------------- which step?

if ($config === []) {
    $step = !empty($_SESSION['install_checked']) ? 2 : 1;
} elseif (!Web::signedIn()) {
    $step = 0; // half-finished install: ask for the owner password
} else {
    $step = (int) ($config['install_step'] ?? 3);
}

// JSON poll used by step 4 to notice the first scheduler run.
if (Web::get('poll') !== '') {
    header('Content-Type: application/json');
    echo json_encode(['ticked' => $step === 4 && $engine->state()['last_tick_at'] !== null]);
    exit;
}

// ---------------------------------------------------------------- actions

if (Web::posted()) {
    $action = Web::post('action');

    if ($step === 0 && $action === 'resume') {
        $result = Web::tryPassword((string) ($_POST['password'] ?? ''));
        if ($result === true) {
            Web::signIn();
            Web::redirect('install.php');
        }
        $errors[] = $result;
    }

    if ($step === 1 && $action === 'check') {
        if (!$requirementsOk) {
            $errors[] = 'Fix the items marked below, then check again.';
        } else {
            $probe = Probe::run($store, Web::guessBaseUrl());
            $found = Web::post('found');
            if ($probe['result'] === 'exposed' || $found === 'exposed') {
                $errors[] = 'Files in the data folder can be opened in a browser. Block the folder as shown below, then check again.';
            } elseif ($probe['result'] === 'unknown' && $found !== 'protected') {
                $errors[] = 'The data folder could not be tested. Turn JavaScript on so your browser can run the test, then check again.';
            } elseif (!isset($_POST['separate'])) {
                $errors[] = 'Confirm that this server is separate from the sites the switch protects.';
            } else {
                if ($probe['result'] === 'unknown') {
                    $probe['browser_ok_at'] = Clock::now();
                    $probe['detail'] = 'Checked from your browser: the request was refused.';
                }
                $_SESSION['install_checked'] = true;
                $_SESSION['install_probe'] = $probe;
                Web::redirect('install.php');
            }
        }
    }

    if ($step === 2 && $action === 'owner') {
        $name = Web::post('owner_name');
        $email = strtolower(Web::post('owner_email'));
        $password = (string) ($_POST['password'] ?? '');
        $tz = Web::post('timezone');
        $dow = (int) Web::post('slot_dow', '1');
        $hour = (int) Web::post('slot_hour', '9');
        $baseUrl = rtrim(Web::post('base_url'), '/');

        if ($name === '') {
            $errors[] = 'Enter your name.';
        }
        if (!Web::validEmail($email)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (($problem = Setup::passwordProblem($password)) !== null) {
            $errors[] = $problem;
        } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        }
        if (!Setup::validTimezone($tz)) {
            $errors[] = 'Choose your timezone.';
        }
        if (!preg_match('~^https?://[^\s/]+(/[^\s]*)?$~i', $baseUrl)) {
            $errors[] = 'Enter the full web address of this install.';
        }

        if ($errors === []) {
            $store->locked(static function () use ($store, $engine, $name, $email, $password, $tz, $dow, $hour, $baseUrl): void {
                if ($store->exists('config')) {
                    return; // someone else got here first; do not overwrite them
                }
                $now = Clock::now();
                $store->write('config', [
                    'owner_name' => $name,
                    'owner_email' => $email,
                    'backup_email' => '',
                    'timezone' => $tz,
                    'slot_dow' => min(7, max(1, $dow)),
                    'slot_hour' => min(23, max(0, $hour)),
                    'base_url' => $baseUrl,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'smtp' => [],
                    'tick_key' => bin2hex(random_bytes(20)),
                    'health_key' => bin2hex(random_bytes(20)),
                    'pending_email' => null,
                    'separate_hosting_confirmed_at' => $now,
                    'install_step' => 3,
                    'installed_at' => null,
                    'created_at' => $now,
                ]);
                $state = $engine->state();
                $state['probe'] = $_SESSION['install_probe'] ?? null;
                $engine->saveState($state);
            });
            if (password_verify($password, (string) $store->read('config')['password_hash'])) {
                Web::signIn();
            }
            Web::redirect('install.php');
        }
    }

    if ($step === 3 && $action === 'smtp') {
        [$smtp, $errors] = Setup::smtpFromPost((array) ($config['smtp'] ?? []));
        $email = strtolower(Web::post('owner_email'));
        if (!Web::validEmail($email)) {
            $errors[] = 'Enter a valid email address for yourself.';
        }
        if ($errors === []) {
            $config['smtp'] = $smtp;
            $config['owner_email'] = $email;
            $store->write('config', $config);
            if (Setup::startEmailCode($store, $outbox, 'owner_email', $email)) {
                Web::flash("A test email with a code has been sent to {$email}.");
                Web::redirect('install.php');
            }
            $reason = $store->read('mail')['last_send_error']['error'] ?? 'unknown error';
            $errors[] = 'The test email could not be sent. The mail server said: ' . $reason;
        }
        $config['smtp'] = $smtp;
    }

    if ($step === 3 && $action === 'code') {
        $result = Setup::finishEmailCode($store, Web::post('code'));
        if ($result === true) {
            $config = $store->read('config');
            $config['install_step'] = 4;
            $store->write('config', $config);
            Web::redirect('install.php');
        }
        $errors[] = $result;
        $config = $store->read('config');
    }

    if ($step === 3 && $action === 'recode') {
        $config['pending_email'] = null;
        $store->write('config', $config);
        Web::redirect('install.php');
    }

    if ($step === 4 && $action === 'finish') {
        $config['installed_at'] = Clock::now();
        unset($config['install_step']);
        $store->write('config', $config);
        $store->log('installed', 'Death Plan Switch ' . VERSION . ' was installed.');
        Web::flash('Installed. Next: add your next of kin and your technical 2IC.');
        Web::redirect('index.php?p=contacts');
    }
}

// ---------------------------------------------------------------- display

$stepNames = [1 => 'Check the server', 2 => 'About you', 3 => 'Mail', 4 => 'Scheduler'];
$probe = null;
$probeUrl = null;
if ($step === 1 && $requirementsOk) {
    $probe = Probe::run($store, Web::guessBaseUrl());
    $probeUrl = Probe::url($store, Web::guessBaseUrl());
}
$pending = is_array($config['pending_email'] ?? null) && $config['pending_email']['expires_at'] > Clock::now() ? $config['pending_email'] : null;
$ticked = $step === 4 ? $engine->state()['last_tick_at'] : null;
$smtp = (array) ($config['smtp'] ?? []);
$posted = static fn (string $key, string $default = ''): string => Web::isPost() ? Web::post($key, $default) : $default;

Web::$title = 'Install Death Plan Switch';
?>
<h1>Install Death Plan Switch</h1>

<?php if ($step > 0): ?>
<ol class="steps">
<?php foreach ($stepNames as $n => $label): ?>
  <li<?= $n === $step ? ' aria-current="step"' : '' ?>><?= e($label) ?></li>
<?php endforeach ?>
</ol>
<?php endif ?>

<?php if ($errors): ?>
<div class="notice notice-bad" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if ($step === 0): ?>
<p>This install was started but not finished. Enter the owner password to carry on.</p>
<form method="post" action="install.php">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="resume">
  <div class="field">
    <label for="password">Owner password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
  </div>
  <div class="actions"><button type="submit">Continue the install</button></div>
</form>
<p class="small muted">To start again from nothing, delete <code>config.json</code> and <code>config.json.bak</code> from the data folder.</p>

<?php elseif ($step === 1): ?>
<ul class="checklist">
<?php foreach ($requirements as $item): ?>
  <li class="<?= $item['ok'] ? 'is-ok' : 'is-todo' ?>">
    <span class="mark" aria-hidden="true"><?= $item['ok'] ? '✓' : '✗' ?></span>
    <span><?= e($item['label']) ?></span>
<?php if (!$item['ok']): ?>
    <span class="hint"><?= e($item['hint']) ?></span>
<?php endif ?>
  </li>
<?php endforeach ?>
<?php if ($probe !== null): ?>
<?php $probeOk = in_array($probe['result'], ['protected', 'outside'], true); ?>
  <li class="<?= $probeOk ? 'is-ok' : ($probe['result'] === 'exposed' ? 'is-todo' : 'is-optional') ?>" id="probe-item">
    <span class="mark" aria-hidden="true"><?= $probeOk ? '✓' : ($probe['result'] === 'exposed' ? '✗' : '○') ?></span>
    <span>The data folder cannot be read from the web</span>
<?php if ($probe['result'] === 'exposed'): ?>
    <span class="hint">A test file in the data folder was served to the web. Your contacts and their messages would be too.</span>
<?php elseif ($probe['result'] === 'unknown'): ?>
    <span class="hint" id="probe-hint">The server could not request its own address, so your browser is running the test instead.</span>
<?php endif ?>
  </li>
<?php endif ?>
  <li class="<?= Web::isHttps() ? 'is-ok' : 'is-optional' ?>">
    <span class="mark" aria-hidden="true"><?= Web::isHttps() ? '✓' : '○' ?></span>
    <span>This page is served over HTTPS</span>
<?php if (!Web::isHttps()): ?>
    <span class="hint">Without HTTPS, your password and the links in emails can be read in transit. Set up a certificate before you rely on this install.</span>
<?php endif ?>
  </li>
</ul>

<?php if ($probe !== null && ($probe['result'] === 'exposed' || Web::post('found') === 'exposed')): ?>
<div class="panel">
  <h2>Block the data folder</h2>
  <p><strong>Apache:</strong> the folder already holds a <code>.htaccess</code> file that blocks it. If you see this message, Apache is ignoring it. Set <code>AllowOverride All</code> for this site, or copy the rule into the site's own configuration.</p>
  <p><strong>nginx:</strong> add this inside the site's <code>server</code> block, then reload nginx:</p>
  <pre class="cron">location ~ ^<?= e(rtrim((string) parse_url(Web::guessBaseUrl(), PHP_URL_PATH), '/')) ?>/(data|app|cli|tests|docs)/ { deny all; }</pre>
  <p class="small">The package includes a complete example in <code>docs/nginx.conf.example</code>.</p>
  <p><strong>Or</strong> move the data folder out of the web folder altogether. The README shows how.</p>
</div>
<?php endif ?>

<form method="post" action="install.php"<?= $probeUrl !== null && $probe !== null && $probe['result'] === 'unknown' ? ' data-probe-url="' . e($probeUrl) . '" data-probe-secret="' . e(Probe::secret($store)) . '" data-probe-auto="1"' : '' ?>>
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="check">
  <input type="hidden" name="found" value="">
  <div class="tick">
    <input type="checkbox" id="separate" name="separate" value="1"<?= isset($_POST['separate']) ? ' checked' : '' ?>>
    <label for="separate">This server is separate from the sites the switch protects</label>
    <span class="help">If the switch lives on the same server as your clients' sites, the outage that takes them down takes the switch down with them, and nobody is told. Use different hosting: a small, cheap server is plenty.</span>
  </div>
  <div class="actions"><button type="submit"><?= $errors ? 'Check again' : 'Continue' ?></button></div>
</form>

<?php elseif ($step === 2): ?>
<form method="post" action="install.php">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="owner">
  <div class="field">
    <label for="owner_name">Your name</label>
    <input type="text" id="owner_name" name="owner_name" value="<?= e($posted('owner_name')) ?>" required maxlength="120" autocomplete="name">
    <span class="help">As your contacts know you. It appears in every message they receive.</span>
  </div>
  <div class="field">
    <label for="owner_email">Your email address</label>
    <input type="email" id="owner_email" name="owner_email" value="<?= e($posted('owner_email')) ?>" required maxlength="254" autocomplete="email">
    <span class="help">Where your weekly check-in is sent. Use the one you read every day.</span>
  </div>
  <div class="field">
    <label for="password">Choose a password</label>
    <input type="password" id="password" name="password" minlength="<?= Setup::MIN_PASSWORD ?>" required autocomplete="new-password">
    <span class="help">At least <?= Setup::MIN_PASSWORD ?> characters. It protects your contacts and their messages.</span>
  </div>
  <div class="field">
    <label for="password2">Type it again</label>
    <input type="password" id="password2" name="password2" required autocomplete="new-password">
  </div>
  <div class="field">
    <label for="timezone">Timezone</label>
    <select id="timezone" name="timezone"<?= Web::isPost() ? '' : ' data-timezone-guess="1"' ?>>
<?php $chosen = $posted('timezone', 'UTC'); foreach (Setup::timezones() as $tz): ?>
      <option<?= $tz === $chosen ? ' selected' : '' ?>><?= e($tz) ?></option>
<?php endforeach ?>
    </select>
  </div>
  <div class="field">
    <span class="label" id="slot-label">Send my check-in email every</span>
    <div class="inline" role="group" aria-labelledby="slot-label">
      <select name="slot_dow" aria-label="Day">
<?php foreach (Schedule::DAYS as $n => $day): ?>
        <option value="<?= $n ?>"<?= $n === (int) $posted('slot_dow', '1') ? ' selected' : '' ?>><?= e($day) ?></option>
<?php endforeach ?>
      </select>
      <span>at</span>
      <select name="slot_hour" aria-label="Hour">
<?php for ($h = 0; $h < 24; $h++): ?>
        <option value="<?= $h ?>"<?= $h === (int) $posted('slot_hour', '9') ? ' selected' : '' ?>><?= e(hourLabel($h)) ?></option>
<?php endfor ?>
      </select>
    </div>
    <span class="help">Pick a time you reliably read email. You can change it later.</span>
  </div>
  <div class="field">
    <label for="base_url">Web address of this install</label>
    <input type="url" id="base_url" name="base_url" value="<?= e($posted('base_url', Web::guessBaseUrl())) ?>" required>
    <span class="help">Used to build the links in emails. Check it is exactly what is in your address bar, without <code>install.php</code>.</span>
  </div>
  <div class="actions"><button type="submit">Continue</button></div>
</form>

<?php elseif ($step === 3 && $pending): ?>
<p class="lede">The mail server accepted a test email. Now check it arrived.</p>
<form method="post" action="install.php">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="code">
  <div class="field">
    <label for="code">The six-digit code sent to <?= e($pending['address']) ?></label>
    <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required autofocus>
    <span class="help">It can take a minute. Look in spam too: if it landed there, mark it as not spam now, so your check-in emails are not lost later.</span>
  </div>
  <div class="actions"><button type="submit">Continue</button></div>
</form>
<form method="post" action="install.php"><?= Web::csrfField() ?><input type="hidden" name="action" value="recode"><button class="link" type="submit">Nothing arrived: change the mail settings or my address</button></form>

<?php elseif ($step === 3): ?>
<p class="lede">The switch sends email through a mail server you choose. Enter its details and a test email is sent to you.</p>
<form method="post" action="install.php">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="smtp">
<?php require __DIR__ . '/_smtp_fields.php' ?>
  <div class="field">
    <label for="owner_email">Send the test to</label>
    <input type="email" id="owner_email" name="owner_email" value="<?= e($posted('owner_email', (string) $config['owner_email'])) ?>" required maxlength="254">
    <span class="help">Your own address, from the last step.</span>
  </div>
  <div class="actions"><button type="submit">Send the test email</button></div>
</form>

<?php elseif ($step === 4): ?>
<p class="lede">The switch does nothing by itself. Every 15 minutes, the server has to run it. This is the one technical step.</p>
<h2>Add this line to the server's crontab</h2>
<?php $runUser = Web::runUser(); ?>
<?php if ($runUser['differs']): ?>
<p>On this server the web pages run as the user <code><?= e($runUser['name']) ?></code>, so the scheduler must too. Open that user's crontab with <code><?= e(Web::crontabCommand()) ?></code> and paste:</p>
<?php else: ?>
<p>Run <code>crontab -e</code> as the user that owns these files, and paste:</p>
<?php endif ?>
<pre class="cron"><?= e(Web::cronLine()) ?></pre>
<?php if (Web::phpCli() === null): ?>
<div class="notice notice-warn"><p>The path to PHP could not be detected on this server. Replace <code>php</code> at the start of the command with the full path your host gives you, for example <code>/usr/bin/php</code>.</p></div>
<?php endif ?>
<p>To test it straight away, without waiting for cron, run the same command once by hand:</p>
<pre class="cron"><?= e(Web::cronCommand()) ?></pre>
<details>
  <summary>My hosting has no cron</summary>
  <p>Use any outside scheduler to request this address every 15 minutes instead. It does the same job. Treat the address as a secret.</p>
  <pre class="cron"><?= e($engine->url($config, 'tick.php', ['key' => $config['tick_key']])) ?></pre>
</details>

<?php if ($ticked): ?>
<div class="notice notice-ok" role="status"><p><strong>The first run has arrived.</strong> The scheduler ran <?= e(relative($ticked)) ?>.</p></div>
<form method="post" action="install.php">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="finish">
  <div class="actions"><button class="button-big" type="submit">Finish</button></div>
</form>
<?php else: ?>
<div class="notice" role="status" data-poll="install.php?poll=1"><p><strong>Waiting for the first run.</strong> This page updates by itself when it arrives, within 15 minutes of adding the line.</p></div>
<form method="post" action="install.php">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="finish">
  <div class="actions">
    <a class="button" href="install.php">Check now</a>
    <button class="button-quiet" type="submit">Carry on without waiting</button>
  </div>
  <p class="small muted">You can add your contacts in the meantime. The switch cannot be armed until a run has arrived.</p>
</form>
<?php endif ?>
<?php endif ?>
