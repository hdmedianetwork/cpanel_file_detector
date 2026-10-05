# SkyServer Storage Guard

Finds the large files and the archives/backups that customers leave in their
hosting space — `backup-full.zip`, `.wpress` exports, `.sql` dumps, ISOs,
4K videos — across every cPanel account on a server. Then it lets the admin
deal with them from one WHM page: remind the customer, quarantine or delete the
files, or suspend the account.

A sibling of the [SkyServer Backup Manager](https://github.com/hdmedianetwork/skyserver_cpanel_backup_module):
same design system, same installer, same in-place updater, so the two look
and behave like one product.

## What it does

```
cron 03:30 ─ bin/guard scan-all --enforce
               ├─ every account: find over its home (nice/ionice, -xdev, no symlinks)
               │    → reports/<user>.json        what was flagged, largest first
               │    → cases/<user>.json          told? when? deadline? suspended?
               │    → notices/<user>.json        what the customer's page shows
               ├─ quarantine batches past their date are deleted
               ├─ if AUTO_REMIND=1   remind accounts that are due a reminder
               ├─ if AUTO_SUSPEND=1  suspend accounts past their deadline
               └─ admin summary email (ALERT_EMAIL) when something is new or overdue

cron every minute ─ bin/guard worker   rescans accounts whose customer pressed
                                       "Check again" or deleted files
```

**Flagged** means either:

- an archive/backup (`zip rar 7z tar tar.gz tgz … sql sql.gz wpress jpa bak iso`) of at
  least `ARCHIVE_MIN_MB` (100 MB by default), or
- any file at all of at least `LARGE_FILE_MB` (500 MB by default).

`mail/` and cPanel's own folders are not scanned. Both limits, the extension
list and the excluded folders are in Settings.

## The admin page — WHM → Plugins → SkyServer Storage Guard

- **Overview**: how many accounts are flagged, how much space they hold, who
  is past their deadline, and a "Needs attention" list that puts overdue
  accounts first.
- **Accounts**: every account with its disk use against its quota, what is
  flagged, and where it stands. Each row has these actions:
  - **Files**: opens the account's full list of flagged files, with its history
    (when it was flagged, each reminder, each removal) and the files allowed to stay.
  - **Remind**: emails the account's contact address and puts a notice on its
    cPanel page. The first reminder starts the grace period (`GRACE_DAYS`, 7 days by default).
    Later reminders keep the same deadline.
  - **Suspend / Unsuspend**: through `whmapi1 suspendacct`, with a reason you can edit.
  - **Rescan** that one account.
- **Files**: the largest flagged files across the whole server. Select any of
  them, across accounts, and:
  - **Quarantine**: moves them out of the account. They stop counting against
    the customer's quota straight away, and the customer can no longer see them.
    You can put them back for `QUARANTINE_DAYS` (7 days by default); after that
    they are deleted.
  - **Delete permanently**: no undo.
  - **Allow to stay**: that file is never flagged again, for files you have
    agreed the customer may keep.
- **Quarantine**: every batch, with **Put back** and **Delete now**.
- **Settings**: thresholds, enforcement, and the email notices, with a test-email button.
- **Activity Log**: every scan, reminder, removal and suspension, and who did it.

**Nothing happens to a customer by itself unless you turn it on.** Scanning
only reads. Automatic reminders (`AUTO_REMIND`) and automatic suspension
(`AUTO_SUSPEND`) are both off after install. You start by looking, then you
press Remind yourself. You switch automation on once you trust what it finds.

### A typical case

1. The nightly scan flags `sharmaho`: `public_html/backup-full-2025.zip`, 18 GB.
2. You press **Remind**. The customer gets an email listing the files and a
   deadline 7 days out. The same notice shows in their cPanel.
3. The customer deletes the file, from the email's instructions or from the Storage
   Report page. The worker rescans within a minute, the case closes by itself,
   and the account shows as clean.
4. If they do nothing, the account shows as **overdue** after the deadline.
   You then quarantine the files, or suspend the account (or have the nightly run do it).

## The customer page — cPanel → Files → Storage Report

Each account sees only its own files:

- the flagged files, with size and date, and **Open folder** (File Manager)
  and **Delete** on each one, plus bulk delete;
- the notice and deadline once you have sent one, with your extra message
  and support contact;
- **Check again**, which asks for a fresh scan.

Deleting happens in the page itself, which runs as the account. It can
only ever delete what the customer could already delete in File Manager.

## Install

The code lives on GitHub. The only thing you publish is the small `install.sh`
from this repo, at **https://storage.gosecureserver.in/install.sh**. It
downloads the code from GitHub and runs `bin/deploy.sh`.

On any WHM/cPanel server, as root:

```bash
curl -sSL https://storage.gosecureserver.in/install.sh | bash
```

The repo is public, so no token or login is needed. The installer downloads the
`main` branch archive from github.com, checks it, swaps it into place and
runs `bin/deploy.sh`.

The installer sets up `/opt/skyserver-storage-guard`,
`/etc/skyserver-storage-guard.conf`, the cron jobs, log rotation, the cPanel page
in every theme, and the WHM plugin. Then:

1. WHM → Plugins → SkyServer Storage Guard → **Scan all accounts**.
2. Look through what it found.
3. Settings → set the From address and support contact, and **Send test email**.

**Releasing an update:** bump `VERSION` and merge to `main`. Every server then
shows the new version under **Check for Updates** in WHM, and **Install update**
downloads it from GitHub, using the saved token. Re-running the `curl … | bash`
line does the same thing. Settings, scan reports and quarantined files are
kept. The published `install.sh` only needs re-uploading if `install.sh` itself changes.

From the shell:

```bash
/opt/skyserver-storage-guard/bin/guard scan-all          # every account
/opt/skyserver-storage-guard/bin/guard scan-user <user>  # one account, prints its files
/opt/skyserver-storage-guard/bin/guard status            # flagged accounts, one per line
/opt/skyserver-storage-guard/bin/guard remind <user>
```

**Uninstall:** `/opt/skyserver-storage-guard/scripts/uninstall.sh`. It leaves the
config, the reports, and any files still in quarantine, which belong to customers.

## Safety

Root works inside directories that customers control, so every file
operation is built to not be tricked into touching anything else:

- **Only scanned files can be acted on.** The dashboard sends file *ids* from
  the latest report, never paths. A crafted request cannot name a file the
  scan did not find inside that account's home.
- **No symlink is followed.** The scan never follows one (`find -P`, `-xdev`).
  Before root removes, quarantines or restores a file, it walks into its
  folder one component at a time. Each step must be a real directory, and it
  checks that the directory it entered is the one it inspected (device and inode).
  It then works on a bare filename relative to that pinned directory. Swapping
  a folder for a symlink mid-way is refused, not followed (tested in `tests/smoke.sh`).
- **Quarantine** sits beside the homes (`/home/.skyserver-quarantine`, `0700 root`).
  It is on the same filesystem, so a 20 GB file is a rename, not a copy.
  Files are handed to root so they leave the customer's quota. A file that
  lives on a different filesystem from it cannot be quarantined; use delete instead.
- **Restore never overwrites.** If something new is at the old path, that file stays in quarantine.
- **The customer page never runs as root.** It deletes as the account itself. Its
  "check again" is a file in a sticky drop box, honoured only when the file's
  owner is the account it names.
- **Everything that changes something is POST-only** in both panels, so a
  prefetch or a bookmarked link can never delete or suspend anything.

Spool modes (`/var/spool/skyserver-storage-guard`):

| Path | Mode | Why |
| --- | --- | --- |
| `./` , `notices/` | `0751` | accounts reach their own notice (`0640 root:<user>`) but cannot list anyone else's |
| `reports/`, `cases/`, `quarantine/`, `scanning/` | `0700` | root's own records |
| `rescan-requests/` | `1733` | drop box: an account can add its own request, not touch others' |

## Development

```bash
tests/smoke.sh     # needs php and jq; run as root
```

The smoke test builds a fake server: a stub `whmapi1`, a stub `sendmail`, and
two homes full of sparse files. It then runs every path end to end: scanning,
reminders, quarantine and restore, the symlink refusal, permanent delete,
allow-to-stay, suspension, cron enforcement, and the customer's own delete
followed by the worker's rescan.

`lib/guard-lib.php` holds all the logic and is shared by `bin/guard.php` (cron)
and `whm-plugin/index.cgi` (dashboard). `ui/sky-ui.php` is the Backup Manager's
design system, with the icons and the wide dialog a file list needs.
