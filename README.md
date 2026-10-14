# Death Plan Switch

**If you disappear, the right people are told.**

This project is open source but doesn't accept pull requests. Fork it freely.

Death Plan Switch is a small, self-hosted dead man's switch for solo web professionals. Once a week it emails you a link. You open it and press a button. If you stop, it asks your next of kin whether everything is all right, and if nobody can say that it is, it emails the people who need to act: the person who would look after your clients, and anyone else you choose.

It is one PHP app with no database. It holds no documents and no passwords. It is free, under the MIT licence.

## How it works

Everything is counted from the weekly check-in email.

| When | What happens |
|---|---|
| 0 hours | You are emailed a link to the check-in page. Pressing the button there is the check-in. |
| 24 hours | A reminder, to both of your email addresses. |
| 48 hours | A last reminder. |
| 72 hours | Your next of kin is asked "Is everything all right?", with two answers: **All fine** or **Something's wrong**. You are told this has happened. |
| 120 hours | Your next of kin is reminded. |
| 144 hours (6 days) | If neither of you has confirmed you are well, every confirmed contact receives the message you wrote for them. |

The check-in email goes out at the same day and hour each week, so your contacts are told **at most 13 days** after your last check-in.

- **All fine** from your next of kin ends it there. Nobody else is contacted.
- **Something's wrong** from your next of kin sends the messages straight away.
- **You can check in at any stage**, even after the switch has fired. Everyone who was contacted is then told it was a false alarm.

## What it is not

Read this part before relying on it.

- **It is a notification tool, not a guarantee.** It sends emails. It cannot know why you stopped checking in, cannot make anyone act, and cannot work if its server, its scheduler or its mail service is down. The sections below on separate hosting and on monitoring exist because of this.
- **It stores no documents and no passwords.** Messages say where things are and who to call. Keep the documents themselves with people: a lawyer, your next of kin, your backup developer.
- **It is email only.** No SMS, no phone calls, no app.
- **It is for one person.** One install, one owner. There are no accounts and no hosted version.
- **It comes with no warranty** and best-effort support. See [Support](#support).

## Requirements

- PHP 8.1 or newer, with the `openssl` and `mbstring` extensions. (`curl` is used if present.)
- A web server. Apache works as shipped. nginx needs four lines of configuration, shown below.
- An SMTP account to send mail through: your email provider or a sending service. PHP's built-in `mail()` is not used.
- A way to run a command every 15 minutes: cron, or any outside scheduler that can request a web address.
- HTTPS.

## Install

**Put it on hosting that is separate from the sites it protects.** If the switch shares a server with your clients' sites, the outage that takes them down takes the switch down too, and nobody is told. A small, cheap server of its own is plenty.

1. **Upload** the folder to your web server, at a private address of your choosing.
2. **Make the `data` folder writable** by the user your web pages run as. On a typical VPS that is `sudo chown -R www-data data`. On most shared hosting it already is.
3. **Open the address in a browser.** The installer checks the server, and checks that the `data` folder cannot be read from the web. It will not continue until that is true, and nothing private is written before then.
4. **Tell it about you:** name, email, password, and the day and hour you want your weekly email.
5. **Enter your SMTP settings.** A test email is sent with a six-digit code. Type the code in to prove the email reaches you.
6. **Add the crontab line** the installer shows you. This is the one technical step. The installer works out the path to PHP and which user the line belongs to, and tells you when the first run arrives.

The line looks like this:

```
*/15 * * * * /usr/bin/php /var/www/switch/cron.php >/dev/null 2>&1
```

It must run as the same user as the web pages, because both write to the `data` folder. If it runs as anyone else, `cron.php` stops and says so instead of quietly breaking file permissions.

No cron on your hosting? Settings shows a secret web address that does the same job. Point any outside scheduler at it.

## Set up

1. **Add your contacts.** A next of kin and a technical contact are required. A legal contact and any others are optional. Every contact has a role label you can change.
2. **Each contact gets an email** asking them to confirm their address and their role. Until they confirm, they are never written to. They do not see their message.
3. **Write each message,** starting from the default wording. Say where things are and who to call. The starter text has a gap in square brackets where your pointer goes, and the switch will not arm while a gap is left.
4. **Tick "I have given this person what they need"** for each contact, once you actually have.
5. **Arm the switch.** The button appears when the list on the dashboard is complete.

Once a year, each contact is asked to reconfirm, and you are asked to confirm that each still has what they need.

## Protecting the data folder

Your contacts and their messages live in `data/` as JSON files. They must not be readable from the web.

- **Apache:** `data/.htaccess` blocks the folder. This needs `AllowOverride All` for the site, which most hosts already have.
- **nginx:** `.htaccess` files are ignored, so add the rules from [`docs/nginx.conf.example`](docs/nginx.conf.example). The important one is:

  ```nginx
  location ~ ^/(data|app|cli|tests|docs)/ { deny all; }
  ```

- **Or move the folder out of the web root.** Create a file called `config.local.php` beside `index.php`:

  ```php
  <?php
  define('DPS_DATA', '/home/you/private/switch-data');
  ```

The app does not take any of this on trust. It keeps a harmless probe file in the data folder and requests it over the web: during install, once a day after that, and whenever you ask in Settings. If the file is served, the switch refuses to arm, emails you, and reports a fault on its health address. If the server cannot reach its own address, your browser runs the same test.

## Knowing when it stops

The scheduler is the single point of failure. If cron stops, the switch never fires and nothing inside it can tell you. Hosts do break cron jobs, during migrations and PHP upgrades. Three things guard against this:

1. **The weekly email is the heartbeat.** It says "Your switch is armed" and lists anything that needs attention. If it stops arriving, the switch has stopped.
2. **Every page warns** when the scheduler has not run for a day.
3. **The health address.** Settings shows a secret address that answers `200` when all is well and `503` when the scheduler has stopped, mail is failing, or the data folder is exposed. Point a free uptime monitor at it and you will be told by something outside the server. This is the one worth setting up.

The scheduler works from stored timestamps, never from the moment it happens to run. A skipped run or a reboot delays things; it cannot cause a false alarm. After a long outage it does not catch up by sending every overdue step at once: steps go one at a time, at least 12 hours apart, and nobody is contacted until you have had your reminders.

## Rehearse it

Do this before you trust it. `tests/timeshift.php` makes an install believe time has passed, so you can walk through a full escalation in an afternoon:

```
php tests/timeshift.php 168 --run    # a week later: the check-in email goes out
php tests/timeshift.php 24 --run     # a day later: the first reminder
php tests/timeshift.php 24 --run     # the last reminder
php tests/timeshift.php 24 --run     # your next of kin is asked
php tests/timeshift.php 48 --run     # your next of kin is reminded
php tests/timeshift.php 24 --run     # the switch fires
```

Run them as the user the web pages run as, for example `sudo -u www-data php tests/timeshift.php 24 --run`.

Real emails go to your real contacts, so rehearse with addresses you control, or warn people first. Then check in, and watch the false-alarm notices go out.

The `tests` folder is left out of release downloads. Get it from the repository. Delete it from your server when you have finished.

## Day to day

- **Check in** from the weekly email, or by signing in and pressing **Check in now**.
- **Going away?** Pause the switch for up to 28 days. It starts again by itself, so it cannot be left off by accident.
- **After a false alarm** in which your contacts received their messages, treat any pointer in those messages as seen. If a message pointed to a document, move the document and change its password.
- **Changing a contact's email** keeps the old address in use until the new one confirms.
- **Your own logo:** save it as `assets/logo.svg` or `assets/logo.png` and it replaces the name at the top of your pages. Emails stay plain.

## Your data

- Everything is in `data/`: `config.json`, `contacts.json`, `state.json`, `history.jsonl`. Back up that folder and you have backed up the switch.
- Files are written to a temporary name and renamed into place, under a lock, so a crash cannot leave one half-written. The previous good copy of each is kept as `.bak`.
- **If a data file is damaged,** the app uses the `.bak` copy and shows a warning. If both are unreadable it stops and says so, on every page, on the health address and in cron's error output. It never treats damage as a fresh install.
- Links in emails are stored only as hashes. Someone who reads the data folder cannot use them.
- Your SMTP password and the text of your messages are stored as plain text in the data folder. That is why the folder matters, and why messages should hold pointers and never logins.

## Updating

Replace every file and folder **except `data/`** (and `config.local.php` if you made one). Sign in and check the dashboard.

## Command line

| Command | What it does |
|---|---|
| `php cron.php --verbose` | Run the scheduler once and print what it did. |
| `php cli/status.php` | Print the state of the switch and any faults. |
| `php cli/reset-password.php` | Set a new owner password. There is no "forgot password" email, on purpose. |
| `php tests/run.php` | Run the test suite. |

## Security

How it is built:

- **A link is never a check-in.** Email scanners follow links. Checking in, answering as next of kin and confirming as a contact all need a button press on the page.
- **Next of kin first.** Nobody else hears anything until your next of kin has been asked and has had 72 hours.
- **Contacts opt in,** and reconfirm once a year.
- Passwords are hashed with PHP's `password_hash`. Sign-in slows down after five wrong attempts.
- Forms carry CSRF tokens. Pages send a strict Content-Security-Policy and `Referrer-Policy: no-referrer`, and load nothing from other sites.
- There is no password reset by email. Server access is the proof.

What it does not defend against:

- **Anyone who can read your mailbox can check in for you,** which would keep the switch quiet. Protect your email account accordingly.
- Anyone with access to the server can read the data folder.
- The secret trigger and health addresses appear in your web server's access log. Settings can replace them.

**Reporting a vulnerability:** please email <!-- TODO before publishing: add the security contact address --> **[address to be added before release]** privately rather than opening a public issue.

## Support

Best effort, with no guarantee. Bug reports are welcome as GitHub issues. Pull requests are turned off: this is a security-sensitive tool, and outside code is not merged. If you want it to work differently, fork it. The MIT licence lets you.

## Licence

MIT. See [LICENSE](LICENSE). The software is provided "as is", without warranty of any kind.

Death Plan Switch bundles [PHPMailer](https://github.com/PHPMailer/PHPMailer) 7.1.1 in `app/lib/PHPMailer`, unmodified, under the LGPL 2.1. Its licence is in that folder.

Made by [Tecky](https://tecky.au).
