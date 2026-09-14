# Nexus Edge CRM

A self-hosted dialler CRM for appointment setters. Plain PHP 8.1+ and MySQL/MariaDB,
no Composer, no Node, no build step. Upload the folder, edit one config file, done.

## What it does

- **Dial queue** – one lead at a time, outcome buttons with keyboard shortcuts (1–8, Enter), notes, callback picker, save and next.
- **Callbacks** – overdue / today / upcoming with a "Dial now" button.
- **Leads** – filterable, paginated list; lead detail with full call history and an edit form; bulk assign / unassign / do-not-dial / delete.
- **Import** – CSV upload (comma, semicolon or tab; BOM stripped; up to 20MB), column mapping with auto-guess, UK phone normalisation, dedupe on phone, chain-agent blocklist, chunked so 15,000-row files do not time out.
- **Users** – create, deactivate, reset password, change role; lead count and today's dial count per user.
- **Dashboard** – today / this week / this month per setter: dials, connects, connect rate, demos, booking rate, callbacks due today; dials per day for the last 14 days.
- **Export** – the current filtered lead view as CSV.

Roles: `admin` sees everything. `setter` can only ever read or write leads assigned to them; this is enforced in the SQL, not the view.

## Requirements

- PHP 8.1 or newer with the `pdo_mysql` and `mbstring` extensions (standard on Bluehost).
- MySQL 5.7+ or MariaDB 10.3+.
- Apache with `.htaccess` support (`mod_rewrite` is used only to block `inc/` and `storage/`; the app does not depend on it).

## Deploy to Bluehost (cPanel)

1. **Create the database.** In cPanel open *MySQL Databases*.
   - Under *Create New Database* enter a name such as `crm` and click *Create Database*. cPanel prefixes it, so the real name is something like `yourcpaneluser_crm`.
   - Under *MySQL Users > Add New User* create a user (for example `crmuser`) with a strong password. The real user name is `yourcpaneluser_crmuser`.
   - Under *Add User To Database* pick that user and that database, click *Add*, tick **ALL PRIVILEGES**, and click *Make Changes*.
2. **Upload the app.** In cPanel open *File Manager*, go to `public_html`, create a folder called `crm`, and upload the contents of this folder into it (or upload a zip and extract it). Do not upload the `.git` folder if you cloned the repository. You should end up with `public_html/crm/index.php`, `public_html/crm/inc/`, and so on. Make sure the hidden `.htaccess` files were uploaded too (File Manager > *Settings* > *Show Hidden Files*).
3. **Configure.** In `public_html/crm/`, copy `config.example.php` to `config.php` and edit it:
   - `db.name`, `db.user`, `db.pass` – the values from step 1. `db.host` stays `localhost`.
   - `blocklist` – the chain agents to exclude on import. Edit freely.
   - `timezone` – defaults to `Europe/London`.
4. **Check PHP version.** In cPanel open *MultiPHP Manager* and make sure the domain runs PHP 8.1 or newer. While there, in *MultiPHP INI Editor* set `upload_max_filesize` and `post_max_size` to at least `20M` if you plan to import large CSVs.
5. **Install.** Visit `https://your-domain/crm/install.php`. It creates the tables and asks for the first admin's name, email and password. It then writes `storage/install.lock` and refuses to run again.
6. **Delete `install.php`** from the server.
7. **Log in** at `https://your-domain/crm/` and go to *Users* to add your setters.

`storage/` must be writable by PHP (it is used for the install lock and for CSV files while an import is in progress). On Bluehost this is the default. If install.php complains, set the folder to `755` in File Manager.

## Try it with fake data first

`seed.php` creates 2 test setters and 200 fake UK estate agent leads (Ofcom's reserved drama phone ranges, so nobody real gets called).

- Browser: log in as admin and visit `https://your-domain/crm/seed.php`.
- Or on the command line: `php seed.php`.

It refuses to run if any leads exist. Logins it creates:

| Email | Password |
| --- | --- |
| setter1@example.com | setter-one-123 |
| setter2@example.com | setter-two-123 |

**Delete `seed.php` and the demo users/leads before importing real data** (Leads > select all > Delete, Users > Deactivate).

## Importing leads

Go to *Import*, upload a CSV, map the columns, pick a batch name, tier and optional setter, and run it. `leads-template.csv` shows the headers the importer guesses automatically, but any headers work; you map them on step 2.

Phone handling on import:

- `07123456789`, `447123456789`, `00447123456789`, `+447123456789`, `447123456789.0` and `4.47123456789E+11` all become `+447123456789`.
- Spaces, brackets, dashes and dots are ignored. `+44 (0)7...` is handled.
- Anything that does not resolve to `+44` followed by 9 or 10 digits is still imported, with status **Invalid**, so nothing disappears silently. Filter the batch by status Invalid to review them.
- A number that already exists in the CRM is skipped and counted as a duplicate. Existing leads are never overwritten.
- Companies whose name contains a blocklist entry from `config.php` are skipped and counted as excluded.

## Queue rules

A setter's queue serves, in this order:

1. Leads assigned to them with do-not-dial off.
2. Callbacks that are due (`callback_at` in the past), oldest first.
3. `New` leads, oldest created first.
4. `No-Answer` leads last dialled more than 24 hours ago, fewest attempts first.

`Demo Booked`, `Cancelled`, `DQ` and `Invalid` are never served, and any lead dialled in the last 4 hours is skipped. That 4-hour rule also applies to due callbacks, so a callback set for less than 4 hours after the call will not appear in the queue until then; the *Callbacks* page always shows it and its "Dial now" button works regardless.

## Security notes

- Passwords are bcrypt hashes. Sessions use an httponly, SameSite=Lax cookie, marked secure on HTTPS.
- Every POST carries a CSRF token that is verified server side.
- Login is limited to 5 failed attempts per 15 minutes per IP.
- Every query is parameterised; all output is escaped.
- `.htaccess` blocks direct web access to `config.php`, `inc/` and `storage/`.
- Nothing in the app calls out to any third-party service.

## Files

```
crm/
  config.php            your settings (not in git)
  config.example.php    template for config.php
  install.php           one-time installer; delete after use
  seed.php              demo data; delete before real use
  index.php             redirects to dial or login
  login.php  logout.php
  dial.php              the dial queue
  callbacks.php
  leads.php  lead.php   list and detail
  import.php            CSV import
  users.php             admin
  dashboard.php         admin
  export.php            admin
  leads-template.csv
  inc/                  db, auth, csrf, phone, queue, layout, helpers
  assets/               style.css, app.js
  storage/              install lock and in-progress imports (not web accessible)
  .htaccess
```
