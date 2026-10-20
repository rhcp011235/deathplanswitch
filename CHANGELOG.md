# Changelog

## Unreleased

- Telegram, as a second way to reach the owner. Check-ins, reminders and notices go there too, with a check-in button.
- With Telegram set up, the next of kin is not asked until a reminder has reached the owner by both email and Telegram. A failing channel is chased every 6 hours, for up to a week past the usual 72 hours.
- Telegram failures, and a held escalation, show on every page and on the health address.
- Calls (Twilio) and texts (Textbelt) to contacts, alongside their emails: to the next of kin when asked and reminded (the text carries the answer links), to every contact when the switch fires, and a false-alarm call or text to anyone reached that way if the owner checks in.
- Contacts are only phoned at a number they confirmed, on the page where they confirm their email. Calls only go out between 8am and 9pm owner time, are retried 3 times if missed, and are cancelled if the owner checks in first. The owner's message itself never goes by phone.
- Settings: Twilio and Textbelt setup, with a test call and test text to the owner. A warning when texts are running low.

## 1.0.0, October 2026

First public release.

## 0.9.0, October 2026

First version, for testing ahead of public release.

- Installer: server checks, owner details, SMTP proved by an emailed code, crontab line with the detected PHP path and user, confirmation of the first run.
- Weekly check-in by email. A button press is the check-in; opening the link is not.
- Escalation: reminders at 24 and 48 hours, next of kin asked at 72 hours and reminded at 120, other contacts told at 144.
- Next of kin answers "All fine" or "Something's wrong".
- False-alarm notices to anyone already contacted when the owner checks in.
- Contacts confirm by email, reconfirm yearly, and carry editable role labels and messages.
- Pause for up to 28 days, ending automatically.
- Yearly prompt to review what each contact holds.
- Flat-file storage with locking, atomic writes and a backup copy of each file.
- Daily test that the data folder cannot be read from the web.
- Secret trigger address for hosting without cron, and a health address for uptime monitors.
