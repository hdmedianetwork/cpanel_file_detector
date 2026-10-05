# Changelog

All notable changes to SkyServer Storage Guard are listed here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [0.1.2] - 2026-10-05

### Fixed
- **Scan again** showed "Scanning " with no account name, and a PHP warning.
- A scan could stall until its time limit when `find` wrote a lot of errors (for example on an
  unusual mount).
- The Activity Log no longer gets untimestamped file listings after a single-account scan.
- Times in the log, deadlines and emails now follow the server's timezone when `php.ini` doesn't
  set one.
- An account whose scan never started no longer shows as *scanning* for 35 minutes.

## [0.1.1] - 2026-10-05

### Fixed
- **Scan all accounts** could fail silently and leave the Activity Log empty. Scans now start in a
  clean, detached environment and always use the PHP command-line binary. If a scan can't start,
  the button and the log say why.

## [0.1.0] - 2026-10-05

### Added
- Nightly scan of every cPanel account for archives/backups and oversized files.
- WHM dashboard: overview, accounts, server-wide file list, quarantine, settings and activity log.
- Reminders by email and in the customer's cPanel, with a deadline and a numbered history.
- Quarantine with restore, permanent delete, an allow-list, and suspend / unsuspend.
- Customer **Storage Report** page in cPanel with self-service delete and rescan.
- Optional automatic reminders, automatic suspension and an admin summary email.
- One-line installer and one-click updates from GitHub.

