<?php
/**
 * Add or edit one contact: who they are, what they are sent, and whether
 * they have been given what they need.
 */
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

Web::requireOwner();
$engine = engine();
$book = $engine->contacts();
$state = $engine->state();

$id = Web::get('id');
$isNew = $id === '';
if ($isNew) {
    $kind = Web::get('kind', 'other');
    if (!isset(Contacts::KINDS[$kind])) {
        $kind = 'other';
    }
    if ($kind === 'nok' && $book->nok() !== null) {
        Web::flash('You already have a next of kin. Edit them, or delete them first.', 'warn');
        Web::redirect('index.php?p=contacts');
    }
    $contact = Contacts::blank($kind);
} else {
    $contact = $book->find($id);
    if ($contact === null) {
        Web::flash('That contact no longer exists.', 'warn');
        Web::redirect('index.php?p=contacts');
    }
}
$kind = $contact['kind'];
$self = 'index.php?p=contact' . ($isNew ? '&kind=' . $kind : '&id=' . $contact['id']);
$errors = [];

/** Would removing this contact leave an armed switch without a required role? */
$isLoadBearing = static function (array $contact) use ($book, $state): bool {
    if (!$state['armed'] || $contact['status'] !== 'confirmed') {
        return false;
    }
    return $contact['kind'] === 'nok'
        || ($contact['kind'] === 'technical' && count($book->confirmedOfKind('technical')) === 1);
};

if (Web::posted()) {
    $action = Web::post('action', 'save');

    if ($action === 'delete' && !$isNew) {
        if ($isLoadBearing($contact)) {
            Web::flash("{$contact['name']} cannot be deleted while the switch is armed, because the switch needs this role filled. Disarm it first, or add and confirm a replacement.", 'bad');
            Web::redirect($self);
        }
        $book->delete($contact['id']);
        store()->log('contact.deleted', "{$contact['name']} ({$contact['role_label']}) was deleted.");
        Web::flash("{$contact['name']} has been deleted and will not be contacted.");
        Web::redirect('index.php?p=contacts');
    }

    if ($action === 'send' && !$isNew) {
        if ($engine->sendConfirmation($contact['id'])) {
            Web::flash('Confirmation email sent to ' . ($contact['pending_email'] ?: $contact['email']) . '.');
        } else {
            Web::flash('The confirmation email could not be sent. Check the mail settings.', 'bad');
        }
        Web::redirect($self);
    }

    // Save.
    $name = Web::post('name');
    $email = strtolower(Web::post('email'));
    $subject = Web::post('subject');
    $body = Web::post('body');

    if ($name === '') {
        $errors[] = 'Enter their name.';
    }
    if (!Web::validEmail($email)) {
        $errors[] = 'Enter a valid email address.';
    }
    if ($subject === '' || $body === '') {
        $errors[] = 'The message needs a subject and some text.';
    }
    foreach ($book->all() as $other) {
        if ($other['id'] !== $contact['id'] && strtolower((string) $other['email']) === $email) {
            $errors[] = "{$other['name']} already uses that email address. Each contact needs their own.";
        }
    }
    $config = $engine->config();
    if (in_array($email, array_map('strtolower', $engine->ownerAddresses($config)), true)) {
        $errors[] = 'That is one of your own addresses. A contact has to be someone else.';
    }

    $contact['name'] = $name;
    $contact['phone'] = Web::post('phone');
    $contact['role_label'] = Web::post('role_label') ?: Contacts::KINDS[$kind];
    $contact['message'] = ['subject' => $subject, 'body' => $body];
    if ($kind === 'nok') {
        $contact['verify_message'] = [
            'subject' => Web::post('verify_subject') ?: Messages::verifyDefault()['subject'],
            'body' => Web::post('verify_body') ?: Messages::verifyDefault()['body'],
        ];
    }
    $ticked = isset($_POST['has_needs']);
    $contact['has_needs_at'] = $ticked ? ($contact['has_needs_at'] ?: Clock::now()) : null;

    $current = strtolower((string) $contact['email']);
    $pending = strtolower((string) ($contact['pending_email'] ?? ''));
    $sendNow = false;
    if ($errors === []) {
        if ($isNew) {
            $contact['email'] = $email;
            $sendNow = isset($_POST['send_now']);
        } elseif ($contact['status'] === 'confirmed') {
            if ($email === $current) {
                // Back to the confirmed address: drop any change that was waiting.
                if ($pending !== '') {
                    $contact['pending_email'] = null;
                    $contact['token'] = null;
                }
            } elseif ($email !== $pending) {
                // Keep writing to the address that confirmed until the new one confirms too.
                $contact['pending_email'] = $email;
                $sendNow = true;
            }
        } elseif ($email !== $current) {
            $contact['email'] = $email;
            $contact['status'] = 'unconfirmed';
            $contact['token'] = null; // a link sent to the old address must not confirm the new one
            $contact['confirm_sent_at'] = null;
        }

        $book->save($contact);
        if ($isNew) {
            store()->log('contact.added', "{$contact['name']} was added as {$contact['role_label']}.");
        }

        $notes = ['Saved.'];
        $level = 'ok';
        if ($sendNow) {
            if ($engine->sendConfirmation($contact['id'])) {
                $notes[] = 'A confirmation email has gone to ' . $email . '.';
            } else {
                $notes[] = 'The confirmation email could not be sent. Check the mail settings, then send it from this page.';
                $level = 'bad';
            }
        }
        $all = $body . ' ' . ($contact['verify_message']['body'] ?? '');
        if (Messages::hasMarker($all)) {
            $notes[] = 'The message still holds starter text in square brackets. Replace it with where things are.';
            $level = $level === 'bad' ? 'bad' : 'warn';
        }
        if (Messages::looksLikeLogin($all)) {
            $notes[] = 'The message looks like it contains a password or login. Messages should say where things are and who to call, never the login itself.';
            $level = $level === 'bad' ? 'bad' : 'warn';
        }
        Web::flash(implode(' ', $notes), $level);
        Web::redirect('index.php?p=contact&id=' . $contact['id']);
    }
    // Fall through with errors: show what was typed.
    $typedEmail = $email;
}

$previews = $isNew ? [] : $engine->preview($contact);
$needsHelp = [
    'nok' => 'For a next of kin, that usually means: they know this switch exists, they know who your other contacts are, and they know where your will and authority documents are kept.',
    'technical' => 'For a technical 2IC, that usually means: they hold the password to your handover document, they can reach your servers, and they know which clients to call first.',
    'legal' => 'For a legal contact, that usually means: they hold whatever sealed copies you agreed on, and they know who your next of kin and technical 2IC are.',
    'other' => 'Whatever this person would need in order to act on the message below.',
][$kind];

Web::$title = ($isNew ? 'Add a contact' : $contact['name']) . ': Death Plan Switch';
?>
<p class="small"><a href="index.php?p=contacts">All contacts</a></p>
<h1><?= $isNew ? 'Add ' . e(['nok' => 'your next of kin', 'technical' => 'a technical contact', 'legal' => 'a legal contact', 'other' => 'a contact'][$kind]) : e($contact['name']) ?></h1>

<?php if ($errors): ?>
<div class="notice notice-bad" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach ?></ul></div>
<?php endif ?>

<?php if (!$isNew): ?>
<div class="panel">
<?php if ($contact['status'] === 'confirmed'): ?>
  <p><span class="tag tag-ok">Confirmed</span> on <?= e(when($contact['reconfirmed_at'] ?? $contact['confirmed_at'], (string) $engine->config()['timezone'], 'j F Y')) ?>. They will be asked to reconfirm once a year.</p>
<?php if (!empty($contact['pending_email'])): ?>
  <p>Waiting for them to confirm <strong><?= e($contact['pending_email']) ?></strong>. Until they do, the switch keeps using <?= e($contact['email']) ?>.</p>
  <form method="post" action="<?= e($self) ?>"><?= Web::csrfField() ?><input type="hidden" name="action" value="send"><button class="button-quiet" type="submit">Send the confirmation email again</button></form>
<?php endif ?>
<?php elseif ($contact['status'] === 'declined'): ?>
  <p><span class="tag tag-bad">Declined.</span> <?= e($contact['name']) ?> asked not to be a contact and will not be written to. If that was a mistake, speak to them, then send a new request.</p>
  <form method="post" action="<?= e($self) ?>"><?= Web::csrfField() ?><input type="hidden" name="action" value="send"><button class="button-quiet" type="submit">Send a new confirmation email</button></form>
<?php else: ?>
  <p><span class="tag tag-warn">Not confirmed.</span> <?= empty($contact['confirm_sent_at'])
      ? e($contact['name']) . ' has not been asked yet.'
      : 'A confirmation email went to ' . e($contact['email']) . ' ' . e(relative($contact['confirm_sent_at'])) . '. The link works for 30 days.' ?> Until they confirm, they will not be written to.</p>
  <form method="post" action="<?= e($self) ?>"><?= Web::csrfField() ?><input type="hidden" name="action" value="send"><button type="submit"><?= empty($contact['confirm_sent_at']) ? 'Send the confirmation email' : 'Send it again' ?></button></form>
<?php endif ?>
</div>
<?php endif ?>

<form method="post" action="<?= e($self) ?>">
  <?= Web::csrfField() ?>
  <input type="hidden" name="action" value="save">

  <div class="field">
    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= e($contact['name']) ?>" required maxlength="120" autocomplete="off">
  </div>
  <div class="field">
    <label for="email">Email address</label>
    <input type="email" id="email" name="email" value="<?= e($typedEmail ?? ($contact['pending_email'] ?: $contact['email'])) ?>" required maxlength="254" autocomplete="off">
<?php if (!$isNew && $contact['status'] === 'confirmed'): ?>
    <span class="help">If you change this, the new address has to confirm before it replaces the old one.</span>
<?php endif ?>
  </div>
  <div class="field">
    <label for="phone">Phone <span class="muted">(optional)</span></label>
    <input type="tel" id="phone" name="phone" value="<?= e($contact['phone']) ?>" maxlength="40" autocomplete="off">
    <span class="help">Never called or texted by the switch. It is shown to your other contacts so they can reach each other.</span>
  </div>
  <div class="field">
    <label for="role_label">Role</label>
    <input type="text" id="role_label" name="role_label" value="<?= e($contact['role_label']) ?>" maxlength="60" autocomplete="off">
    <span class="help">How this person is described to them and to your other contacts. Change it to fit, for example "Business partner" or "Accountant".</span>
  </div>

<?php if ($kind === 'nok'): ?>
  <h2>The question they are asked first</h2>
  <p>Sent when you have missed a check-in and 72 hours of reminders. Two answer links, "All fine" and "Something's wrong", are added beneath your text, with the time by which they need to answer.</p>
  <div class="field field-wide">
    <label for="verify_subject">Subject</label>
    <input type="text" id="verify_subject" name="verify_subject" value="<?= e($contact['verify_message']['subject'] ?? '') ?>" maxlength="200">
  </div>
  <div class="field field-wide">
    <label for="verify_body">Message</label>
    <textarea id="verify_body" name="verify_body" rows="5"><?= e($contact['verify_message']['body'] ?? '') ?></textarea>
  </div>
<?php endif ?>

  <h2><?= $kind === 'nok' ? 'Their message if the switch fires' : 'Their message' ?></h2>
  <p><?= $kind === 'nok'
      ? 'Sent if they answer "Something\'s wrong", or if nobody answers for 72 hours. Your other contacts get theirs at the same moment.'
      : 'Sent only if neither you nor your next of kin confirms you are well.' ?> Say where things are and who to call. <strong>Never put a password or login in a message.</strong></p>
  <div class="field field-wide">
    <label for="subject">Subject</label>
    <input type="text" id="subject" name="subject" value="<?= e($contact['message']['subject']) ?>" required maxlength="200">
  </div>
  <div class="field field-wide">
    <label for="body">Message</label>
    <textarea id="body" name="body" rows="10" required><?= e($contact['message']['body']) ?></textarea>
<?php if (Messages::hasMarker($contact['message']['body'])): ?>
    <span class="help">Replace the text in [square brackets] with your own. The switch will not arm while it is there.</span>
<?php endif ?>
  </div>
  <details class="placeholders">
    <summary>Words that fill themselves in</summary>
    <dl class="facts">
<?php foreach (Messages::PLACEHOLDERS as $code => $meaning): ?>
      <dt><code><?= e($code) ?></code></dt><dd><?= e($meaning) ?></dd>
<?php endforeach ?>
    </dl>
  </details>

  <h2>What they hold</h2>
  <div class="tick">
    <input type="checkbox" id="has_needs" name="has_needs" value="1"<?= !empty($contact['has_needs_at']) ? ' checked' : '' ?>>
    <label for="has_needs">I have given <?= $contact['name'] !== '' ? e($contact['name']) : 'this person' ?> what they need</label>
    <span class="help"><?= e($needsHelp) ?> The switch holds no documents and no passwords: this tick is your own record that the handover outside the app is done.</span>
  </div>

<?php if ($isNew): ?>
  <div class="tick">
    <input type="checkbox" id="send_now" name="send_now" value="1" checked>
    <label for="send_now">Email them a confirmation request now</label>
    <span class="help">They get a short explanation and a link to confirm. Their message above is not shown to them.</span>
  </div>
<?php endif ?>

  <div class="actions">
    <button type="submit"><?= $isNew ? 'Add contact' : 'Save changes' ?></button>
    <a class="button button-quiet" href="index.php?p=contacts">Cancel</a>
  </div>
</form>

<?php if ($previews): ?>
<h2>What <?= e($contact['name']) ?> would receive</h2>
<p>As saved. Names are filled in now; the number of days and the date are worked out when the message is sent. <?= $contact['status'] !== 'confirmed' ? 'Until they confirm, they receive nothing.' : '' ?></p>
<?php foreach ($previews as $label => $email): ?>
<div class="preview">
  <header><?= e($label) ?><strong><?= e($email->subject) ?></strong></header>
  <pre><?= e($email->text()) ?></pre>
</div>
<?php endforeach ?>
<?php endif ?>

<?php if (!$isNew): ?>
<h2>Delete</h2>
<details>
  <summary>Remove <?= e($contact['name']) ?> from your switch</summary>
  <p>They will not be told, and will not be written to again.</p>
  <form method="post" action="<?= e($self) ?>">
    <?= Web::csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <button class="button-danger" type="submit">Delete this contact</button>
  </form>
</details>
<?php endif ?>
