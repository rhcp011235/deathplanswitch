<?php
/**
 * The yearly review: the owner confirms each contact still has what they need.
 */
declare(strict_types=1);

namespace DPS;

if (!defined('DPS_ROOT')) {
    exit; // only ever loaded by Web::page()
}

Web::requireOwner();
$engine = engine();

if (Web::posted()) {
    $ids = array_values(array_filter((array) ($_POST['has'] ?? []), 'is_string'));
    $engine->recordReview($ids);
    $confirmed = $engine->contacts()->confirmed();
    $missing = array_filter($confirmed, static fn (array $c): bool => !in_array($c['id'], $ids, true));
    if ($missing) {
        Web::flash('Saved. Still unticked: ' . implode(', ', array_column($missing, 'name')) . '. The review is complete when every confirmed contact is ticked.', 'warn');
        Web::redirect('index.php?p=review');
    }
    Web::flash('Review complete. You will be asked again in a year.');
    Web::redirect('index.php?p=contacts');
}

$config = $engine->config();
$state = $engine->state();
$contacts = $engine->contacts()->all();

Web::$title = 'Yearly review: Death Plan Switch';
?>
<p class="small"><a href="index.php?p=contacts">All contacts</a></p>
<h1>Yearly review</h1>
<p class="lede">Things drift. A password gets changed, a document moves, someone gets a new phone. Check with each person, then tick them off.</p>
<?php if ($state['review_at']): ?>
<p class="muted">Last completed on <?= e(when($state['review_at'], (string) $config['timezone'], 'j F Y')) ?>.</p>
<?php endif ?>

<?php if ($contacts === []): ?>
<p>You have no contacts yet. <a href="index.php?p=contacts">Add them first</a>.</p>
<?php else: ?>
<form method="post" action="index.php?p=review">
  <?= Web::csrfField() ?>
<?php foreach ($contacts as $contact): ?>
  <div class="tick">
    <input type="checkbox" id="has-<?= e($contact['id']) ?>" name="has[]" value="<?= e($contact['id']) ?>">
    <label for="has-<?= e($contact['id']) ?>"><?= e($contact['name']) ?> still has what they need</label>
    <span class="help"><?= e($contact['role_label']) ?>, <?= e($contact['email']) ?><?= $contact['phone'] !== '' ? ', ' . e($contact['phone']) : '' ?>.
      <?= $contact['status'] !== 'confirmed' ? 'Not confirmed, so not written to.' : '' ?>
      <?= !empty($contact['has_needs_at']) ? 'Last ticked ' . e(when($contact['has_needs_at'], (string) $config['timezone'], 'j F Y')) . '.' : 'Never ticked.' ?>
      <a href="index.php?p=contact&amp;id=<?= e($contact['id']) ?>">Read their message</a></span>
  </div>
<?php endforeach ?>
  <div class="actions">
    <button type="submit">Save the review</button>
  </div>
</form>
<?php endif ?>
