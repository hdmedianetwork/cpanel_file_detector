# Architecture

How Storage Guard is put together: what runs where, what it stores, and why it is safe to run as
root inside customer homes.

## Components

| Component | Path on the server | Runs as | Role |
|---|---|---|---|
| Core library | `/opt/skyserver-storage-guard/lib/guard-lib.php` | — | All logic: scanning, cases, reminders, quarantine, suspension. Shared by everything below, so every entry point behaves the same. |
| CLI | `bin/guard` → `bin/guard.php` | root | `scan-all`, `scan-user`, `worker`, `remind`, `status`, `purge-quarantine`. `bin/guard` picks a real PHP CLI binary and fixes cron's bare `PATH`. |
| Cron | `/etc/cron.d/skyserver-storage-guard` | root | `scan-all --enforce` nightly at 03:30; `worker` every minute. |
| WHM dashboard | `/usr/local/cpanel/whostmgr/docroot/cgi/skyserver_storage_guard/index.cgi` | root | Single-page app plus a JSON API (`index.cgi?api=…`), registered through AppConfig. |
| cPanel page | `/usr/local/cpanel/base/frontend/<theme>/skyserver_storage/` | the account | **Storage Report**: the account's own notice, self-service delete, rescan request. |
| Design system | `ui/sky-ui.php` | — | Styles and front-end runtime shared with the SkyServer Backup Manager. Copied next to each page. |
| Installer / updater | `install.sh`, `bin/self-update.sh`, `bin/deploy.sh` | root | Download the branch archive from GitHub, swap the code in as a whole, deploy. `deploy.sh` is idempotent. |

## Flow of a case

```
scan finds files ──► case opens (status: not told yet)
                         │
             Remind ─────┤  email + cPanel notice, deadline = now + GRACE_DAYS
                         ▼
                     notified ──── customer deletes ──► worker rescans ──► case closes (clean)
                         │
               deadline passes
                         ▼
                      overdue ──► Quarantine / Delete / Suspend (or AUTO_SUSPEND)
```

A case closes by itself when a scan finds nothing over the limits. An account that fills up
again later starts a new case with a fresh grace period.

## Data on disk

| Path | Mode | Contents |
|---|---|---|
| `/etc/skyserver-storage-guard.conf` | `0600` | Settings |
| `/var/log/skyserver-storage-guard.log` | `0600` | Activity log (rotated weekly) |
| `/var/spool/skyserver-storage-guard/` | `0751` | State root: accounts can pass through it but can't list it |
| `…/reports/<user>.json` | `0600` | Latest scan of an account |
| `…/cases/<user>.json` | `0600` | Reminders, deadline, suspension, history |
| `…/notices/<user>.json` | `0640 root:<user>` | What that account's Storage Report shows |
| `…/quarantine/<batch>.json` | `0600` | Metadata for one quarantine batch |
| `…/ignores.json` | `0600` | Files allowed to stay |
| `…/rescan-requests/` | `1733` | Drop box for "check again" (sticky; owner-checked) |
| `…/scan-state.json`, `scanning/` | `0600` | Progress of the running scan |
| `<home parent>/.skyserver-quarantine/` | `0700` | Quarantined files, owned by root (e.g. `/home/.skyserver-quarantine`) |
| `~<user>/.skyserver-storage/notice.json` | `0640 root:<user>` | A copy of the notice for servers that hide `/var/spool` from accounts |

## File safety

Root acts inside directories that customers own, and customers can rename, replace or symlink
anything in them at any moment. Every operation is therefore built from the following:

1. **IDs, not paths.** The dashboard can only name a file that the latest scan found inside that
   account's home. File IDs are hashes of the relative path.
2. **Pinned traversal.** `sg_pin_dir()` `chdir`s into the home and then into each path component in
   turn. Each component must be a plain directory (`lstat`), and after every `chdir` the directory
   actually entered must match the one checked, by device and inode. Once pinned, the working
   directory is held by inode, so nothing renamed afterwards can redirect it.
3. **Bare names only.** `unlink`, `rename` and `lchown` run on a single filename relative to the
   pinned directory. None of them follow a final symlink.
4. **Attributes set in root's space.** Before a quarantined file is restored, its owner and mode
   are set while it is still inside root's `0700` quarantine. Nothing is ever `chmod`ed through a
   customer path.
5. **Exclusive creation.** The notice copy in a home is written with `O_EXCL` into a folder that
   root takes over first, then renamed into place.

The test suite (`tests/smoke.sh`) checks the symlink case directly. A folder swapped for a link to a
directory outside the home is refused, and the file outside is left untouched.

## Scanning

```
nice -n 19 ionice -c3 timeout <SCAN_TIMEOUT_MIN> \
  find . -xdev ( -path ./mail -o … ) -prune -o -type f -size +<min>k -printf '%s\t%T@\t%P\0'
```

- Runs from inside the home (`cwd`), so `%P` gives paths relative to it.
- `-xdev` keeps to one filesystem, so CageFS and virtfs bind mounts are neither double-counted nor
  followed out of the account. `find` never follows symlinks.
- Output is NUL-separated, so any filename, including ones with newlines or tabs, parses correctly.
- Sizes come from `stat` metadata only. File contents are never read.
