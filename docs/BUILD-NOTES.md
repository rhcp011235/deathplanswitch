# Build notes for version 0.9.0

Written on Wednesday 7 October 2026, against version 3 of the product brief. For Peter, not for the public: this file is left out of release downloads.

## What was built

Everything in sections 5 to 8 of the brief, Decided and Proposed alike. None of the Proposed items was left out.

| Brief | Where it lives |
|---|---|
| Install, 5 steps | `app/pages/install.php` |
| Contacts, confirmation, role labels, messages | `app/pages/contact.php`, `app/Contacts.php`, `app/Messages.php` |
| Check-in by button, not link | `app/pages/checkin.php` |
| Timer and escalation | `app/Engine.php`, with every timing in `app/Schedule.php` |
| Next of kin's two buttons | `app/pages/respond.php` |
| All clear, pause, yearly prompts | `app/Engine.php` |
| Flat files, locking, atomic writes | `app/Store.php` |
| Data folder test | `app/Probe.php` |
| Cron, "last ran", warning on every page | `cron.php`, `app/pages/_layout.php` |
| Secret second trigger | `tick.php` |
| SMTP only, PHPMailer | `app/SmtpTransport.php`, `app/lib/PHPMailer` |
| Single-use, expiring tokens | `app/Tokens.php` |

## Calls I made where the brief was silent

Each of these is yours to overturn. They are listed roughly by how much they matter.

1. **The weekly email goes at a fixed day and hour** (default Monday 9am, your timezone), not 7 days after your last check-in. A rolling timer drifts later every week and makes "at most 13 days" exactly 13 every time. A fixed slot is a habit, and 13 days becomes the true worst case. A check-in closes the current week; it does not move the slot.

2. **The installer tests the data folder first**, before any password or SMTP detail is written. The brief had the test after the SMTP step, by which point the secrets would already be in a readable folder on a misconfigured server.

3. **Arming is a button, not automatic.** The brief says the switch is armed once the required contacts confirm. I made that the point at which the button appears, so the switch never arms while you are still editing a message. Arming also requires that no message still holds starter text and that each confirmed contact is ticked "has what they need".

4. **The scheduler runs every 15 minutes**, not hourly. Each run is two small file reads. A shorter interval means the installer can confirm the first run in minutes, your 9am email arrives near 9am, and a missed run costs minutes.

5. **Steps are never skipped or bunched.** A step counts only when the mail server accepts its email. After an outage, overdue steps go one per run and at least 12 hours apart. Without this, a server that was down for a week would come back and email your next of kin and your contacts in the same minute, with you having seen one email.

6. **Your next of kin gets one reminder**, at 120 hours. A single email that lands in spam should not be the only thing between a missed check-in and your contacts being told.

7. **A next of kin who cannot be emailed does not block the switch.** If their address is rejected but mail to you still works, the 72-hour wait runs anyway and the others are told on time. If no email at all is getting out, the escalation waits.

8. **"All fine" covers one week.** It ends that cycle. If you stay silent, your next of kin is asked again the following week. The alternative, one "All fine" silencing the switch indefinitely, seemed worse.

9. **Only confirmed contacts are ever written to.** Changing a confirmed contact's email keeps the old address live until the new one confirms. If a required contact declines, the switch disarms and emails you at both addresses, because it cannot do its job without them.

10. **The backup address is added in Settings, with a code**, not in the installer. Reminders carry check-in links, so a mistyped backup address would hand a stranger the ability to check in for you.

11. **A health address for uptime monitors** (`health.php`). The brief names cron as the single point of failure and relies on you noticing a missing email. People are poor at noticing absences. This lets something outside the server tell you.

12. **Cron must run as the web user.** On your Nanode the pages will run as `www-data`. If cron runs as you or as root, it rewrites the data files so the web pages cannot read them. `cron.php` refuses to run as the wrong user and prints the right command. The installer shows the right `crontab` command for the server it is on.

13. **No password reset by email.** Anyone who could read your mailbox could take the switch over. `php cli/reset-password.php` on the server is the only way.

14. **You are emailed at each turn:** when armed, when your next of kin is asked, when the switch fires (with a link that calls it off), and when it is disarmed, in case that was not you.

15. **PHP 8.1 is the floor**, not 8.4. Nothing in the app needs 8.4, and 8.1 widens who can install it. The tests pass on 8.1, 8.3, 8.4 and 8.5.

16. **Everything, PHPMailer included, sits inside the one folder**, with no Composer step, so a backup of the folder is a backup of the switch. PHPMailer is LGPL 2.1, which is compatible with shipping it unmodified inside an MIT project. The README says so.

## Not built

These belong to the launch, not the package:

- The demo install with sample data, reset hourly
- The download page on deathplanswitch.com, the story on tecky.au, the Hacker News post
- The donation button and the release list
- The GitHub repository itself, and its settings (pull requests off, issues on)

## Before publishing

- [ ] Add the security contact address to the README. There is a marked gap.
- [ ] Add the donation link and the release-list link to the README, once they exist.
- [ ] Drop Sultan's logo in as `assets/logo.svg` if you want it on your own pages. Emails stay plain either way.
- [ ] Move the version to 1.0.0 in `app/bootstrap.php` and `CHANGELOG.md`.
- [ ] Decide whether to delete `tests/` from your own server after rehearsing.

## How it was tested

- **`php tests/run.php`**: 45 tests, 228 checks. Each builds a fresh install, replaces the clock and the mail server, and runs the real engine hour by hour. They cover the timeline in the brief to the second, every stage at which you can check in, both next-of-kin answers, cron outages, mail outages, a rejected next-of-kin address, pause, arming rules, the contact lifecycle, damaged files and eight processes writing at once. I broke three rules on purpose to confirm the tests notice.
- **In a browser, against real servers.** The package was installed through its own installer on Apache with mod_php and on nginx with PHP-FPM, sending through a real SMTP conversation. On nginx without the deny rules the installer refused to continue, as intended. A full escalation was then walked through the real pages with `tests/timeshift.php`: check-in, both next-of-kin answers, firing, and the false-alarm notices. A further 50 browser checks covered headers, forged form posts, sign-in throttling, settings changes and the rest.
- **Not tested:** a real mail provider (deliverability, spam folders), a real cron daemon over days, HTTPS, and shared hosting. Your month of running it is the test for those.
