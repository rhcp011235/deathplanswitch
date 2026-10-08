<?php
/**
 * The mail server fields, shared by the installer and Settings.
 * Expects $smtp (saved settings, possibly empty).
 */
declare(strict_types=1);

namespace DPS;

if (!isset($smtp)) {
    exit;
}
$secure = (string) ($smtp['secure'] ?? 'tls');
?>
  <div class="field">
    <label for="smtp_host">Mail server host</label>
    <input type="text" id="smtp_host" name="smtp_host" value="<?= e($smtp['host'] ?? '') ?>" placeholder="smtp.example.com" required autocomplete="off">
    <span class="help">From your email provider or a sending service. PHP's built-in mail is not used, because it fails silently on too many hosts.</span>
  </div>
  <div class="field">
    <span class="label" id="smtp-port-label">Port and encryption</span>
    <div class="inline" role="group" aria-labelledby="smtp-port-label">
      <input type="number" name="smtp_port" value="<?= e((string) ($smtp['port'] ?? 587)) ?>" min="1" max="65535" required aria-label="Port">
      <select name="smtp_secure" aria-label="Encryption">
        <option value="tls"<?= $secure === 'tls' ? ' selected' : '' ?>>STARTTLS (usual with 587)</option>
        <option value="ssl"<?= $secure === 'ssl' ? ' selected' : '' ?>>SSL/TLS (usual with 465)</option>
        <option value="none"<?= $secure === 'none' ? ' selected' : '' ?>>None (not recommended)</option>
      </select>
    </div>
  </div>
  <div class="field">
    <label for="smtp_user">Username</label>
    <input type="text" id="smtp_user" name="smtp_user" value="<?= e($smtp['user'] ?? '') ?>" autocomplete="off">
  </div>
  <div class="field">
    <label for="smtp_pass">Password</label>
    <input type="password" id="smtp_pass" name="smtp_pass" value="" autocomplete="new-password">
<?php if (!empty($smtp['pass'])): ?>
    <span class="help">Leave blank to keep the saved password.</span>
<?php endif ?>
  </div>
  <div class="field">
    <label for="smtp_from">Send from</label>
    <input type="email" id="smtp_from" name="smtp_from" value="<?= e($smtp['from_email'] ?? '') ?>" required autocomplete="off">
    <span class="help">An address your mail service lets you send from. Your contacts will see it, so use one they would recognise.</span>
  </div>
