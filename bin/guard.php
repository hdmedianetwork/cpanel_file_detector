<?php
/**
 * SkyServer Storage Guard — command line. Run through bin/guard, which finds
 * a PHP binary and fixes cron's bare PATH first.
 *
 *   guard scan-all [--enforce]   scan every account; --enforce (cron) also
 *                                sends due reminders and, if enabled,
 *                                suspends overdue accounts
 *   guard scan-user <user>       scan one account
 *   guard worker                 pick up "check again" requests from the
 *                                cPanel page (cron, every minute)
 *   guard remind <user>          send a reminder now
 *   guard purge-quarantine       delete quarantine batches past their date
 *   guard status                 one line per flagged account
 */

require_once dirname(__DIR__) . '/lib/guard-lib.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'guard.php must run under the PHP CLI, not ' . PHP_SAPI . "\n");
    exit(1);
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && !getenv('SG_TEST_HOME_BASE')) {
    fwrite(STDERR, "guard must run as root.\n");
    exit(1);
}

$cmd = $argv[1] ?? '';
$arg = $argv[2] ?? '';
sg_ensure_spool();

function out(string $s): void {
    echo $s, "\n";
}

function need_user(string $u): string {
    if (!sg_valid_user($u) || !isset(sg_accounts()[$u])) {
        fwrite(STDERR, "Unknown account: $u\n" . (sg_accounts_error() ? 'WHM said: ' . sg_accounts_error() . "\n" : ''));
        exit(1);
    }
    return $u;
}

/**
 * One scan per account at a time. The nightly run, a rescan from WHM and a
 * "check again" from the customer can all ask for the same account.
 */
function with_user_lock(string $user, callable $fn) {
    $fh = fopen(SG_SPOOL . "/scanning/.$user.lock", 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return null;
    }
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function scan_one(string $user, array $conf): ?array {
    return with_user_lock($user, function () use ($user, $conf) {
        $r = sg_scan_and_record($user, $conf);
        $tag = $r['error'] ? ($r['flagged_count'] ? '[WARN]' : '[FAIL]') : '[OK]';
        sg_log("$tag $user: " . ($r['flagged_count']
            ? $r['flagged_count'] . ' flagged, ' . sg_human($r['flagged_bytes'])
              . ' (' . $r['archive_count'] . ' archive, ' . $r['large_count'] . ' large)'
            : 'nothing flagged') . ' in ' . $r['duration_s'] . 's' . ($r['error'] ? ' — ' . $r['error'] : ''));
        return $r;
    });
}

function write_state(array $s): void {
    $s['updated_at'] = date('c');
    sg_write_json(SG_SCAN_STATE, $s);
}

/**
 * What cron does after a scan, and only when the admin has switched it on:
 * remind accounts that are due a reminder, and suspend those past their
 * deadline. Accounts the admin already suspended are left alone.
 */
function enforce(array $conf): array {
    $did = ['reminded' => [], 'suspended' => []];
    $every = sg_int($conf, 'REMIND_EVERY_DAYS', 1) * 86400;
    foreach (sg_account_rows($conf, 0) as $r) {
        if ($r['suspended'] || $r['flagged_count'] === 0) {
            continue;
        }
        if ($r['status'] === 'overdue' && sg_on($conf, 'AUTO_SUSPEND')) {
            $res = sg_suspend($r['user'], (string) $conf['SUSPEND_REASON'], 'auto');
            if ($res['ok']) {
                $did['suspended'][] = $r['user'];
            }
            continue;
        }
        if (!sg_on($conf, 'AUTO_REMIND')) {
            continue;
        }
        $due = $r['reminders'] === 0
            || ($r['last_reminder'] && time() - strtotime($r['last_reminder']) >= $every - 3600);
        if ($due) {
            $res = sg_remind($r['user'], 'auto');
            if ($res['ok']) {
                $did['reminded'][] = $r['user'];
            }
        }
    }
    return $did;
}

/** The admin's morning summary — only sent when there is something in it. */
function digest(array $conf, array $did, array $newly): void {
    $to = trim((string) $conf['ALERT_EMAIL']);
    if ($to === '') {
        return;
    }
    $rows = sg_account_rows($conf, 3);
    $overdue = array_filter($rows, fn($r) => $r['status'] === 'overdue');
    if (!$overdue && !$newly && !$did['reminded'] && !$did['suspended']) {
        return;
    }
    $t = sg_totals($rows);
    $body = 'Storage Guard scan on ' . sg_hostname() . ' — ' . date('j F Y H:i') . "\n\n"
        . "{$t['flagged']} of {$t['accounts']} accounts have flagged files, " . sg_human($t['flagged_bytes']) . " in total.\n\n";
    if ($newly) {
        $body .= "Newly flagged:\n";
        foreach ($newly as $u) {
            foreach ($rows as $r) {
                if ($r['user'] === $u) {
                    $body .= "  $u — {$r['flagged_count']} file(s), " . sg_human($r['flagged_bytes']) . "\n";
                }
            }
        }
        $body .= "\n";
    }
    if ($overdue) {
        $body .= "Past their deadline:\n";
        foreach ($overdue as $r) {
            $body .= "  {$r['user']} — " . sg_human($r['flagged_bytes']) . ', deadline was '
                . date('j M', strtotime($r['deadline'])) . ", {$r['reminders']} reminder(s)\n";
        }
        $body .= "\n";
    }
    if ($did['reminded'])  { $body .= 'Reminded automatically: ' . implode(', ', $did['reminded']) . "\n"; }
    if ($did['suspended']) { $body .= 'Suspended automatically: ' . implode(', ', $did['suspended']) . "\n"; }
    $body .= "\nOpen WHM → Plugins → SkyServer Storage Guard to act on these.\n";

    $err = sg_send_mail($to, '[Storage Guard] ' . sg_hostname() . ': '
        . ($overdue ? count($overdue) . ' overdue, ' : '') . "{$t['flagged']} flagged", $body, $conf);
    if ($err) {
        sg_log("[WARN] admin digest to $to not sent: $err");
    }
}

switch ($cmd) {

    case 'scan-all':
        $lock = fopen(SG_SCAN_LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            sg_log('[!] A scan is already running — skipping.');
            out('A scan is already running.');
            exit(1);
        }
        $conf = sg_conf();
        $accounts = array_keys(sg_accounts(true));
        if (!$accounts) {
            $why = sg_accounts_error() ?: 'whmapi1 listaccts returned no accounts';
            sg_log("[!] Scan aborted: $why");
            write_state(['status' => 'aborted', 'error' => $why, 'started_at' => date('c'),
                         'total' => 0, 'done' => 0, 'current' => '']);
            exit(1);
        }

        $state = ['status' => 'running', 'started_at' => date('c'), 'finished_at' => null,
                  'total' => count($accounts), 'done' => 0, 'current' => '', 'flagged' => 0,
                  'flagged_bytes' => 0, 'errors' => 0, 'enforce' => in_array('--enforce', $argv, true)];
        write_state($state);
        register_shutdown_function(function () use (&$state) {
            if ($state['status'] === 'running') {
                $state['status'] = 'interrupted';
                write_state($state);
                sg_log('===== Scan interrupted: ' . date('Y-m-d H:i:s') . ' =====');
            }
        });
        sg_log('===== Scan started: ' . date('Y-m-d H:i:s') . ' — ' . count($accounts) . ' accounts =====');

        $newly = [];
        foreach ($accounts as $user) {
            $state['current'] = $user;
            write_state($state);
            $wasOpen = sg_case($user)['open'];
            $r = scan_one($user, $conf);
            if ($r === null) {
                sg_log("[*] $user: already being scanned — skipped");
            } else {
                if ($r['flagged_count']) {
                    $state['flagged']++;
                    $state['flagged_bytes'] += $r['flagged_bytes'];
                    if (!$wasOpen) {
                        $newly[] = $user;
                    }
                }
                if ($r['error']) {
                    $state['errors']++;
                }
            }
            $state['done']++;
        }

        // Reports for accounts that were removed from the server.
        $live = array_flip($accounts);
        foreach (glob(SG_SPOOL . '/reports/*.json') ?: [] as $f) {
            $u = basename($f, '.json');
            if (!isset($live[$u])) {
                @unlink($f);
                @unlink(SG_SPOOL . "/cases/$u.json");
                @unlink(SG_SPOOL . "/notices/$u.json");
            }
        }

        $purged = sg_purge_expired();
        $did = ['reminded' => [], 'suspended' => []];
        if ($state['enforce']) {
            $did = enforce($conf);
        }

        $state['status'] = 'finished';
        $state['current'] = '';
        $state['finished_at'] = date('c');
        $state['reminded'] = $did['reminded'];
        $state['suspended'] = $did['suspended'];
        write_state($state);
        sg_log('===== Scan finished: ' . date('Y-m-d H:i:s') . " — {$state['flagged']} flagged ("
            . sg_human($state['flagged_bytes']) . "), {$state['errors']} error(s)"
            . ($purged ? ", $purged quarantine batch(es) purged" : '')
            . ($did['reminded'] ? ', reminded ' . count($did['reminded']) : '')
            . ($did['suspended'] ? ', suspended ' . implode(' ', $did['suspended']) : '') . ' =====');
        if ($state['enforce']) {
            digest($conf, $did, $newly);
        }
        out("Scanned {$state['total']} accounts: {$state['flagged']} flagged, " . sg_human($state['flagged_bytes']) . '.');
        break;

    case 'scan-user':
        $user = need_user($arg);
        $r = scan_one($user, sg_conf());
        if ($r === null) {
            out("$user is already being scanned.");
            exit(1);
        }
        out("$user: {$r['flagged_count']} flagged, " . sg_human($r['flagged_bytes']) . ($r['error'] ? " — {$r['error']}" : ''));
        foreach (array_slice($r['files'], 0, 20) as $f) {
            out(sprintf('  %9s  %-7s  %s', sg_human($f['size']), $f['kind'], $f['path']));
        }
        break;

    case 'worker':
        // A "check again" from the cPanel page is a file named after the
        // account in a drop box anyone can write to. It is only honoured if
        // the account that wrote it is the account it names.
        $conf = sg_conf();
        foreach (glob(SG_SPOOL . '/rescan-requests/*') ?: [] as $f) {
            $user = basename($f);
            $st = @lstat($f);
            @unlink($f);
            if (!$st || !sg_valid_user($user) || !isset(sg_accounts()[$user])) {
                continue;
            }
            $owner = sg_owner($user);
            if (!$owner || (int) $st['uid'] !== (int) $owner[1]) {
                continue;
            }
            // Already scanned since the customer asked — nothing to add.
            $report = sg_report($user);
            if ($report && strtotime($report['scanned_at']) > (int) $st['mtime']) {
                continue;
            }
            scan_one($user, $conf);
        }
        break;

    case 'remind':
        $user = need_user($arg);
        $res = sg_remind($user, 'cli');
        out($res['message'] ?? $res['error']);
        exit($res['ok'] ? 0 : 1);

    case 'purge-quarantine':
        out(sg_purge_expired() . ' batch(es) purged.');
        break;

    case 'status':
        foreach (sg_account_rows(sg_conf(), 0) as $r) {
            if ($r['flagged_count'] || $r['status'] === 'suspended') {
                out(sprintf('%-16s %-10s %4d files %10s%s', $r['user'], $r['status'], $r['flagged_count'],
                    sg_human($r['flagged_bytes']), $r['deadline'] ? '  deadline ' . date('Y-m-d', strtotime($r['deadline'])) : ''));
            }
        }
        break;

    default:
        fwrite(STDERR, "usage: guard scan-all [--enforce] | scan-user <user> | worker | remind <user> | purge-quarantine | status\n");
        exit(1);
}
