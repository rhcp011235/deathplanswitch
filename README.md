# Death Plan Switch

**If you disappear, the right people are told.**

This project is open source but doesn't accept pull requests. Fork it freely.

Death Plan Switch is a small, self-hosted dead man's switch for solo web professionals. Once a week it emails you a link. You open it and press a button. If you stop, it asks your next of kin whether everything is all right, and if nobody can say that it is, it emails the people who need to act: the person who would look after your clients, and anyone else you choose.

It is one PHP app with no database. It holds no documents and no passwords. It is free, under the MIT licence.

## This fork

This is a personal fork, set up for one person who lives alone and whose first concern is **false alarms**: family or lawyers being told by mistake. The original only uses email. This fork adds a second channel for the owner, and makes the switch prove it has reached the owner before anyone else hears. It also lets the switch phone and text family, so they are not relying on email alone.

### What changed from the original

- **Telegram for the owner.** The weekly check-in, both reminders and every notice to the owner also arrive in Telegram, with a check-in button. One press on either the email or the Telegram button checks in. See [Telegram](#telegram).
- **Two channels before family is asked.** The next of kin is not asked until a reminder has actually been delivered to the owner by both email and Telegram. One email lost to spam, or one dead channel, cannot start the escalation on its own.
- **A failing channel is chased.** If either channel is failing at the 72-hour mark, the switch holds and retries that channel every 6 hours. Once a chase gets through, the owner has 6 hours to check in before the next of kin is asked.
- **The hold has a limit.** A week past the usual 72 hours, the next of kin is asked anyway, so a broken bot cannot stop the switch from ever working. Being asked is not firing: the next of kin can still answer **All fine**. The limit is `CHANNEL_HOLD` in `app/Schedule.php`.
- **Faults are loud.** A failed Telegram message or a held escalation shows on every page and turns the health address to `503`.
- **Calls and texts to contacts.** Any contact can also be phoned (Twilio) and texted (Textbelt) whenever they are emailed. The call and the text only tell them to read their email, never the message itself. See [Calls and texts](#calls-and-texts).
- **No wrong numbers.** A contact is only ever phoned at a number they confirmed themselves, on the same page where they confirm their email.
- **No night calls, no ghost calls.** Calls go out between 8am and 9pm owner time. A call still waiting when the owner checks in is cancelled. Anyone who was called or texted gets the "false alarm" message the same way.

### How this install is meant to be set up

1. **Mail through a big provider, not your own mail server.** Unreliable email mostly comes from running your own mail server, and the switch never does that. Either of these works in the installer's SMTP step:
   - **Gmail:** `smtp.gmail.com`, port 587, TLS. Turn on 2-step verification, then make an **App password** to use as the SMTP password.
   - **iCloud:** `smtp.mail.me.com`, port 587, TLS. Make an **app-specific password** at account.apple.com. The From address must be your iCloud address.
2. **Add a backup email in Settings, at a different provider** (for example Gmail as the main address, iCloud as the backup). Reminders go to both.
3. **Link Telegram in Settings** (about 2 minutes, see [Telegram](#telegram)), then press **Send a test message**.
4. **Set up calls and texts in Settings:** a Twilio account and number for calls, a Textbelt key for texts. Press **Call me** and **Text me** to hear and see what family will get.
5. **Tick "Phone them" and "Text them" for family on their contact pages.** Send the confirmation again, so they confirm their number.
6. **Point a free uptime monitor at the health address.** The health address is how you are told the switch itself is broken.
7. **Rehearse a full escalation** with `tests/timeshift.php`, using addresses you control, before trusting it.

### What it costs

| Service | For | Cost |
|---|---|---|
| Gmail or iCloud | Email | Free |
| Telegram | You | Free |
| Twilio | Calls to family | About $1.15 a month for the number, plus a cent or two a minute per call |
| Textbelt | Texts to family | A one-off pack of texts, no monthly fee. The free key allows one text a day, so it is only good for testing. |

## How it works

Everything is counted from the weekly check-in email.

| When | What happens |
|---|---|
| 0 hours | You are emailed a link to the check-in page. Pressing the button there is the check-in. |
| 24 hours | A reminder, to both of your email addresses. |
| 48 hours | A last reminder. |
| 72 hours | Your next of kin is asked "Is everything all right?", with two answers: **All fine** or **Something's wrong**. You are told this has happened. With Telegram set up, this waits until a reminder has reached you by both email and Telegram (see below). |
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
- **It is email first.** Telegram for you, and calls and texts for contacts, are extras on top. Your messages to contacts only ever go by email. There is no app.
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

Real emails, calls and texts go to your real contacts, so rehearse with addresses and numbers you control, or warn people first. Then check in, and watch the false-alarm notices go out.

The `tests` folder is left out of release downloads. Get it from the repository. Delete it from your server when you have finished.

## Telegram

Optional, free, and recommended. Email can fail quietly: a filter, a full mailbox, a spam folder. Telegram gives the switch a second way to reach you.

- **Set up:** in Telegram, ask **@BotFather** for a new bot (`/newbot`) and paste its token into Settings. Settings then gives you a link. Open it, press **Start**, and press **I have pressed Start**. A test message arrives. Make a new bot for this; do not reuse one that does other jobs.
- **What changes:** your weekly check-in, the reminders and every notice to you also arrive on Telegram, with a check-in button. A press on either the email or the Telegram button checks you in.
- **Two ways before your family hears.** Your next of kin is not asked until a reminder has been delivered to you both by email and by Telegram. If one of them is failing at the 72-hour mark, the switch holds and chases you on that channel every 6 hours. Once a chase gets through, you have 6 hours to check in before your next of kin is asked.
- **It cannot hold for ever.** If a channel is still failing a week after the usual 72 hours, your next of kin is asked anyway, so a dead channel cannot stop the switch from ever working. Being asked is not the switch firing: they can still answer **All fine**.
- While it holds, or whenever a Telegram message fails, every page shows a warning and the health address answers `503`.

The bot only sends. Nothing has to reach your server from Telegram, so it works behind any firewall. The bot token is stored in the data folder, like your mail password.

## Calls and texts

Optional. Email is easy to miss, and a phone ringing is not. When the switch emails a contact, it can also phone them and text them.

**When.** Each contact who has calls or texts turned on is phoned:
- **Next of kin:** when they are asked if you are all right, and when they are reminded. The text carries the same **All fine** and **Something's wrong** links as the email.
- **Every contact:** when the switch fires.
- **False alarms:** anyone who was called or texted, if you then check in. A call that has not been made yet is simply cancelled.

**What they hear.** A short text, or a computer voice that reads its message twice, telling them to read their email. If voicemail answers, the call waits for the beep so the whole message is left. **Your message itself only ever goes by email.** It is never left on a lock screen or read out to a voicemail.

**Safeguards.**
- **Confirmed numbers only.** A contact must confirm their number before it is ever used, on the same page where they confirm their email. If you change a number, the new one has to be confirmed again. There is a button for that on the contact's page.
- **Calls only between 8am and 9pm, your time.** A call that falls due at night waits for the morning. Texts go at any hour.
- **Retries.** A call that is not answered (busy, no answer, failed) is tried 3 times, half an hour apart. A text that fails is retried the same way.
- **Links fall back.** If the text service refuses a text because it contains links, the text is resent without them, pointing the reader to their email instead.
- **Never instead of email.** Calls and texts never hold up the escalation and never replace an email.
- **Faults are loud.** A call or text that does not get through shows on every page and turns the health address to `503`. You are warned when fewer than 10 texts are left.

**Set up (Settings → Calls and texts).**
- **Calls, Twilio:** sign up at twilio.com and buy a phone number with voice. Copy your Account SID and Auth token into Settings. A trial account can only call numbers you have verified with Twilio, and plays a trial notice first, so add a little credit before you rely on it. US calls do not need the carrier registration that business texts need.
- **Texts, Textbelt:** buy a key at textbelt.com and paste it in. There is no phone number to rent and no carrier registration.
- Then press **Call me** and **Text me** to try both on your own phone.

Numbers can be typed as ten digits for the US and Canada. Any other country needs `+` and the country code. Nothing has to reach your server from Twilio or Textbelt.

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
- Your SMTP password, your Telegram bot token, your Twilio auth token, your Textbelt key and the text of your messages are stored as plain text in the data folder. That is why the folder matters, and why messages should hold pointers and never logins.

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

**Reporting a vulnerability:** please email Peter **info@tecky.au** privately rather than opening a public issue.

## Support

Best effort, with no guarantee. Bug reports are welcome as GitHub issues. Pull requests are turned off: this is a security-sensitive tool, and outside code is not merged. If you want it to work differently, fork it. The MIT licence lets you.

## Licence

MIT. See [LICENSE](LICENSE). The software is provided "as is", without warranty of any kind.

Death Plan Switch bundles [PHPMailer](https://github.com/PHPMailer/PHPMailer) 7.1.1 in `app/lib/PHPMailer`, unmodified, under the LGPL 2.1. Its licence is in that folder.

Made by [Tecky](https://tecky.au).
