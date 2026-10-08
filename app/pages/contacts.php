<?php
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

Web::requireOwner();
$engine = engine();
$contacts = $engine->contacts()->all();
$state = $engine->state();
$hasNok = $engine->contacts()->nok() !== null;
$hasTechnical = array_filter($contacts, static fn (array $c): bool => $c['kind'] === 'technical') !== [];
$reviewDue = $state['armed'] && $state['review_at'] !== null && Clock::now() >= $state['review_at'] + Schedule::YEAR;

Web::$title = 'Contacts: Death Plan Switch';
?>
<h1>Contacts</h1>
<p class="lede">The people your switch writes to. Each one confirms by email before they count, and only confirmed contacts are ever written to.</p>

<?php if ($reviewDue): ?>
<div class="notice notice-warn"><p>Your yearly review is due. <a href="index.php?p=review">Confirm each contact still has what they need</a>.</p></div>
<?php endif ?>

<?php if ($contacts === []): ?>
<div class="panel">
  <h2>Start with two people</h2>
  <p>Your <strong>next of kin</strong> is asked first, privately, whether everything is all right. Your <strong>technical 2IC</strong> is the person who would look after your clients. Ask them both before you add them.</p>
</div>
<?php else: ?>
<div class="rows">
<?php foreach ($contacts as $contact): ?>
  <div class="row">
    <div>
      <h3><a href="index.php?p=contact&amp;id=<?= e($contact['id']) ?>"><?= e($contact['name']) ?></a></h3>
      <p class="role"><?= e($contact['role_label']) ?></p>
      <p><?= e($contact['email']) ?><?= $contact['phone'] !== '' ? ', ' . e($contact['phone']) : '' ?></p>
<?php if (!empty($contact['pending_email'])): ?>
      <p class="small muted">Changing to <?= e($contact['pending_email']) ?> once they confirm it.</p>
<?php endif ?>
    </div>
    <div class="side">
<?php if ($contact['status'] === 'confirmed'): ?>
      <span class="tag tag-ok">Confirmed</span>
<?php elseif ($contact['status'] === 'declined'): ?>
      <span class="tag tag-bad">Declined</span>
<?php elseif (!empty($contact['confirm_sent_at'])): ?>
      <span class="tag tag-warn">Asked <?= e(relative($contact['confirm_sent_at'])) ?></span>
<?php else: ?>
      <span class="tag tag-warn">Not asked yet</span>
<?php endif ?>
      <br>
<?php if (Messages::hasMarker(($contact['message']['body'] ?? '') . ($contact['verify_message']['body'] ?? ''))): ?>
      <span class="tag tag-bad">Message not finished</span>
<?php elseif (empty($contact['has_needs_at'])): ?>
      <span class="tag tag-warn">Not yet given what they need</span>
<?php else: ?>
      <span class="small muted">Has what they need</span>
<?php endif ?>
    </div>
  </div>
<?php endforeach ?>
</div>
<?php endif ?>

<div class="actions">
<?php if (!$hasNok): ?>
  <a class="button" href="index.php?p=contact&amp;kind=nok">Add your next of kin</a>
<?php endif ?>
  <a class="button<?= $hasNok && !$hasTechnical ? '' : ' button-quiet' ?>" href="index.php?p=contact&amp;kind=technical">Add a technical contact</a>
  <a class="button button-quiet" href="index.php?p=contact&amp;kind=legal">Add a legal contact</a>
  <a class="button button-quiet" href="index.php?p=contact&amp;kind=other">Add someone else</a>
</div>

<?php if ($contacts !== []): ?>
<h2>Yearly review</h2>
<p>Once a year you are asked to confirm each contact still has what they need. You can do it any time. <a href="index.php?p=review">Review now</a>.</p>
<?php endif ?>
