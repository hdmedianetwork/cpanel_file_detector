<?php
/**
 * SkyServer Storage Guard — the shared core.
 *
 * Required by the CLI (bin/guard.php, which cron runs) and by the WHM
 * dashboard (whm-plugin/index.cgi), so a scan started from either one, a
 * reminder sent from either one and a file removed from either one go
 * through exactly the same code and leave exactly the same records.
 *
 * Nothing in here prints. The dashboard is a CGI whose headers are written
 * by hand, and one stray byte before them breaks the page.
 *
 * State on disk, all under SG_SPOOL:
 *
 *   reports/<user>.json     latest scan of that account            0600 root
 *   cases/<user>.json       notice / deadline / suspension record  0600 root
 *   notices/<user>.json     what the account's own page shows      0640 root:<user>
 *   quarantine/<id>.json    one batch of files moved aside          0600 root
 *   ignores.json            files the admin has allowed to stay     0600 root
 *   rescan-requests/<user>  drop box the cPanel page writes into    1733
 *   scan-state.json         progress of the current or last run     0600 root
 *   scanning/<user>         marker while that account is scanned    0600 root
 */

// PHP falls back to UTC when php.ini names no timezone, which would put the
// log, the deadlines and the reminder emails hours away from the server's
// own clock. Use the system's zone instead.
if (!ini_get('date.timezone')) {
    $tz = @readlink('/etc/localtime');
    if ($tz && preg_match('#zoneinfo/(.+)$#', $tz, $m) && @date_default_timezone_set($m[1])) {
        // set
    } elseif (is_readable('/etc/timezone')) {
        @date_default_timezone_set(trim((string) file_get_contents('/etc/timezone')));
    }
    unset($tz, $m);
}

define('SG_CONF_FILE', getenv('SG_CONF') ?: '/etc/skyserver-storage-guard.conf');
define('SG_SPOOL', rtrim(getenv('SG_SPOOL') ?: '/var/spool/skyserver-storage-guard', '/'));
define('SG_LOG_FILE', getenv('SG_LOG') ?: '/var/log/skyserver-storage-guard.log');
define('SG_INSTALL_DIR', dirname(__DIR__));
define('SG_SCAN_LOCK', SG_SPOOL . '/scan.lock');
define('SG_SCAN_STATE', SG_SPOOL . '/scan-state.json');

/** Every setting, with the value used when the config file does not say. */
const SG_DEFAULTS = [
    'LARGE_FILE_MB'         => '500',
    'ARCHIVE_MIN_MB'        => '100',
    'ARCHIVE_EXTENSIONS'    => 'zip rar 7z tar tar.gz tgz tar.bz2 tbz2 tar.xz txz gz bz2 xz zst sql sql.gz wpress jpa daf bak iso',
    'EXCLUDE_PATHS'         => 'mail .cpanel .cagefs .cl.selector .spamassassin .skyserver-storage etc ssl',
    'MAX_FILES_PER_ACCOUNT' => '200',
    'SCAN_TIMEOUT_MIN'      => '30',
    'GRACE_DAYS'            => '7',
    'AUTO_REMIND'           => '0',
    'REMIND_EVERY_DAYS'     => '3',
    'AUTO_SUSPEND'          => '0',
    'QUARANTINE_DAYS'       => '7',
    'NOTICE_FROM'           => '',
    'NOTICE_SUBJECT'        => 'Action needed: large files in your hosting account',
    'NOTICE_MESSAGE'        => '',
    'SUPPORT_CONTACT'       => '',
    'SUSPEND_REASON'        => 'Storage policy: large or backup files were not removed after notice',
    'ALERT_EMAIL'           => '',
];

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

function sg_conf(): array {
    $conf = SG_DEFAULTS;
    if (is_readable(SG_CONF_FILE)) {
        foreach (file(SG_CONF_FILE) as $line) {
            if (preg_match('/^([A-Z0-9_]+)="?(.*?)"?$/', trim($line), $m)) {
                $conf[$m[1]] = stripcslashes($m[2]);
            }
        }
    }
    return $conf;
}

/** Rewrites only the keys given, keeping comments and every other line. */
function sg_write_conf(array $updates): void {
    $lines = is_readable(SG_CONF_FILE) ? file(SG_CONF_FILE, FILE_IGNORE_NEW_LINES) : [];
    $seen = [];
    foreach ($lines as &$line) {
        if (preg_match('/^([A-Z0-9_]+)=/', trim($line), $m) && array_key_exists($m[1], $updates)) {
            $line = $m[1] . '="' . sg_conf_escape($updates[$m[1]]) . '"';
            $seen[$m[1]] = true;
        }
    }
    unset($line);
    foreach ($updates as $key => $val) {
        if (empty($seen[$key])) {
            $lines[] = $key . '="' . sg_conf_escape($val) . '"';
        }
    }
    sg_write_file(SG_CONF_FILE, implode("\n", $lines) . "\n", 0600);
}

/** One line, no quote that could end the value early. */
function sg_conf_escape(string $v): string {
    return str_replace(["\r\n", "\r", "\n"], '\n', addcslashes($v, '"\\'));
}

function sg_int(array $conf, string $key, int $min = 0): int {
    return max($min, (int) ($conf[$key] ?? SG_DEFAULTS[$key] ?? 0));
}

function sg_on(array $conf, string $key): bool {
    return ($conf[$key] ?? '0') === '1';
}

/** "zip tar.gz .SQL" → ['tar.gz', 'zip', 'sql'], longest first so tar.gz wins over gz. */
function sg_extensions(array $conf): array {
    $out = [];
    foreach (preg_split('/[\s,]+/', strtolower((string) $conf['ARCHIVE_EXTENSIONS'])) as $e) {
        $e = trim($e, ". \t");
        if ($e !== '' && preg_match('/^[a-z0-9.]+$/', $e)) {
            $out[$e] = true;
        }
    }
    $out = array_keys($out);
    usort($out, fn($a, $b) => strlen($b) <=> strlen($a));
    return $out;
}

/** Paths relative to an account's home that the scan never enters. */
function sg_excludes(array $conf): array {
    $out = [];
    foreach (preg_split('/[\s,]+/', (string) $conf['EXCLUDE_PATHS']) as $p) {
        $p = trim($p, "/ \t");
        if ($p !== '' && sg_rel_ok($p)) {
            $out[] = $p;
        }
    }
    // Never worth scanning, whatever the admin typed.
    $out[] = '.skyserver-storage';
    return array_values(array_unique($out));
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

function sg_log(string $line): void {
    @file_put_contents(SG_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

function sg_valid_user(string $user): bool {
    return (bool) preg_match('/^[a-z0-9][a-z0-9_]{0,31}$/i', $user);
}

/** A path relative to a home: no absolute, no .., no empty components. */
function sg_rel_ok(string $rel): bool {
    if ($rel === '' || $rel[0] === '/' || strpos($rel, "\0") !== false) {
        return false;
    }
    foreach (explode('/', $rel) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
    }
    return true;
}

function sg_file_id(string $rel): string {
    return substr(sha1($rel), 0, 16);
}

function sg_human(float $bytes): string {
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 || $bytes >= 10 ? (string) round($bytes) : number_format($bytes, 1)) . ' ' . $u[$i];
}

/** cPanel sizes: "1563M", "2.5G", "unlimited", "" → bytes, or null for no limit. */
function sg_parse_size($v): ?int {
    $v = trim((string) $v);
    if ($v === '' || stripos($v, 'unlimited') !== false || $v === '0') {
        return null;
    }
    if (!preg_match('/^([\d.]+)\s*([KMGT]?)/i', $v, $m)) {
        return null;
    }
    $mult = ['' => 1048576, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, 'T' => 1099511627776];
    return (int) round((float) $m[1] * $mult[strtoupper($m[2])]);
}

function sg_read_json(string $path): ?array {
    if (!is_readable($path)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** Written beside the target and renamed over it, so a reader never sees half a file. */
function sg_write_file(string $path, string $content, int $mode): bool {
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $content) === false) {
        return false;
    }
    @chmod($tmp, $mode);
    return @rename($tmp, $path);
}

function sg_write_json(string $path, array $data, int $mode = 0600): bool {
    return sg_write_file($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), $mode);
}

function sg_ensure_spool(): void {
    foreach (['', '/reports', '/cases', '/quarantine', '/scanning'] as $d) {
        if (!is_dir(SG_SPOOL . $d)) {
            @mkdir(SG_SPOOL . $d, 0700, true);
        }
    }
    foreach (['/notices', '/rescan-requests'] as $d) {
        if (!is_dir(SG_SPOOL . $d)) {
            @mkdir(SG_SPOOL . $d, 0751, true);
        }
    }
}

/** Runs a command without a shell. Returns [exit code, stdout, stderr]. */
function sg_run(array $cmd, ?string $stdin = null, ?string $cwd = null): array {
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $spec, $pipes, $cwd);
    if (!is_resource($proc)) {
        return [127, '', 'could not start ' . $cmd[0]];
    }
    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $out, (string) $err];
}

function sg_which(string $name): ?string {
    foreach (explode(':', (string) getenv('PATH') . ':/usr/local/cpanel/bin:/usr/sbin:/usr/bin:/sbin:/bin') as $dir) {
        if ($dir !== '' && is_executable("$dir/$name")) {
            return "$dir/$name";
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// WHM
// ---------------------------------------------------------------------------

/**
 * whmapi1 exits 0 even when the call failed — the verdict is in
 * metadata.result — so an error reads exactly like an empty answer unless
 * that is checked. Returns ['ok' => bool, 'data' => array, 'error' => string].
 */
function sg_whmapi(string $fn, array $args = []): array {
    $bin = getenv('SG_WHMAPI') ?: (sg_which('whmapi1') ?? '/usr/local/cpanel/bin/whmapi1');
    $cmd = [$bin, '--output=json', $fn];
    foreach ($args as $k => $v) {
        $cmd[] = $k . '=' . $v;
    }
    [$code, $out, $err] = sg_run($cmd);
    $json = json_decode($out, true);
    if (!is_array($json)) {
        return ['ok' => false, 'data' => [], 'error' => trim($err) ?: "whmapi1 $fn returned nothing it could read (exit $code)"];
    }
    $ok = (int) ($json['metadata']['result'] ?? 0) === 1;
    return [
        'ok'    => $ok,
        'data'  => is_array($json['data'] ?? null) ? $json['data'] : [],
        'error' => $ok ? '' : (string) ($json['metadata']['reason'] ?? "whmapi1 $fn failed"),
    ];
}

/** Every cPanel account, keyed by user. Cached for the life of the request. */
function sg_accounts(bool $fresh = false): array {
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $res = sg_whmapi('listaccts', ['want' => 'user,domain,email,suspended,suspendreason,diskused,disklimit,plan,owner']);
    $out = [];
    foreach (($res['data']['acct'] ?? []) as $a) {
        $user = (string) ($a['user'] ?? '');
        if (!sg_valid_user($user)) {
            continue;
        }
        $email = trim((string) ($a['email'] ?? ''));
        $out[$user] = [
            'user'          => $user,
            'domain'        => (string) ($a['domain'] ?? ''),
            'email'         => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'suspended'     => !empty($a['suspended']) && (string) $a['suspended'] !== '0',
            'suspendreason' => (string) ($a['suspendreason'] ?? ''),
            'plan'          => (string) ($a['plan'] ?? ''),
            'owner'         => (string) ($a['owner'] ?? ''),
            'disk_used'     => sg_parse_size($a['diskused'] ?? '') ?? 0,
            'disk_limit'    => sg_parse_size($a['disklimit'] ?? ''),
        ];
    }
    ksort($out);
    $cache = $out;
    $GLOBALS['SG_ACCOUNTS_ERROR'] = $res['ok'] ? '' : $res['error'];
    return $out;
}

function sg_accounts_error(): string {
    return (string) ($GLOBALS['SG_ACCOUNTS_ERROR'] ?? '');
}

/** [home, uid, gid] for an account, or null when it has no usable home. */
function sg_owner(string $user): ?array {
    $base = getenv('SG_TEST_HOME_BASE');
    if ($base) {
        $home = rtrim($base, '/') . '/' . $user;
        $st = @stat($home);
        return $st ? [$home, $st['uid'], $st['gid']] : null;
    }
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        if (is_array($pw) && !empty($pw['dir'])) {
            return [rtrim($pw['dir'], '/'), (int) $pw['uid'], (int) $pw['gid']];
        }
    }
    [$code, $out] = sg_run(['getent', 'passwd', $user]);
    $f = explode(':', trim($out));
    if ($code === 0 && count($f) >= 6 && $f[5] !== '') {
        return [rtrim($f[5], '/'), (int) $f[2], (int) $f[3]];
    }
    return null;
}

// ---------------------------------------------------------------------------
// Scanning
// ---------------------------------------------------------------------------

function sg_ignores(): array {
    return sg_read_json(SG_SPOOL . '/ignores.json') ?? [];
}

function sg_kind(string $rel, int $size, array $exts, int $archiveMin, int $largeMin): ?string {
    $lower = strtolower($rel);
    if ($size >= $archiveMin) {
        foreach ($exts as $e) {
            if (str_ends_with($lower, '.' . $e)) {
                return 'archive';
            }
        }
    }
    return $size >= $largeMin ? 'large' : null;
}

/** Escapes a literal path for find's -path, which reads it as a glob. */
function sg_find_glob(string $s): string {
    return preg_replace('/([*?\[\]\\\\])/', '\\\\$1', $s);
}

/**
 * Walks one account's home and returns its report. Runs `find` rather than
 * walking in PHP: it is several times faster on a tree of a million small
 * files, and it runs under nice/ionice so a scan never competes with the
 * sites it is scanning.
 *
 * The walk starts from inside the home (`cwd`, then ".") and never crosses
 * a mount (-xdev), so a virtfs or CageFS bind mount cannot make the same
 * file count twice or lead the scan out of the account.
 */
function sg_scan_user(string $user, ?array $conf = null): array {
    $conf = $conf ?? sg_conf();
    $started = microtime(true);
    $acct = sg_accounts()[$user] ?? null;

    $report = [
        'user'          => $user,
        'home'          => null,
        'scanned_at'    => date('c'),
        'duration_s'    => 0,
        'files'         => [],
        'flagged_count' => 0,
        'flagged_bytes' => 0,
        'archive_count' => 0,
        'archive_bytes' => 0,
        'large_count'   => 0,
        'large_bytes'   => 0,
        'truncated'     => false,
        'error'         => null,
        'disk_used'     => $acct['disk_used'] ?? 0,
        'disk_limit'    => $acct['disk_limit'] ?? null,
        'policy'        => ['large_mb' => sg_int($conf, 'LARGE_FILE_MB', 1),
                            'archive_mb' => sg_int($conf, 'ARCHIVE_MIN_MB', 1)],
    ];

    $owner = sg_owner($user);
    if (!$owner || !is_dir($owner[0])) {
        $report['error'] = 'home directory not found';
        return $report;
    }
    [$home] = $owner;
    $report['home'] = $home;

    $largeMin   = sg_int($conf, 'LARGE_FILE_MB', 1) * 1048576;
    $archiveMin = sg_int($conf, 'ARCHIVE_MIN_MB', 1) * 1048576;
    $minBytes   = min($largeMin, $archiveMin);
    $exts       = sg_extensions($conf);
    $ignored    = array_flip(sg_ignores()[$user] ?? []);
    $timeout    = sg_int($conf, 'SCAN_TIMEOUT_MIN', 1) * 60;

    $cmd = [];
    if (sg_which('nice'))   { array_push($cmd, 'nice', '-n', '19'); }
    if (sg_which('ionice')) { array_push($cmd, 'ionice', '-c3'); }
    if (sg_which('timeout')) { array_push($cmd, 'timeout', (string) $timeout); }
    array_push($cmd, 'find', '.', '-xdev');

    $excl = sg_excludes($conf);
    if ($excl) {
        $cmd[] = '(';
        foreach ($excl as $i => $p) {
            if ($i) { $cmd[] = '-o'; }
            array_push($cmd, '-path', './' . sg_find_glob($p));
        }
        array_push($cmd, ')', '-prune', '-o');
    }
    // find rounds -size up to whole units, so ask for a little more than
    // needed and apply the exact threshold below.
    array_push($cmd, '-type', 'f', '-size', '+' . max(0, intdiv($minBytes, 1024) - 1) . 'k',
               '-printf', '%s\t%T@\t%P\0');

    // stderr goes to a file, not a pipe: a find that complains a lot (an
    // odd mount, a tree changing under it) would otherwise fill the pipe and
    // stall while this loop waits on stdout — until the timeout killed it.
    $errFile = tempnam(sys_get_temp_dir(), 'sg-find-');
    $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']];
    $proc = @proc_open($cmd, $spec, $pipes, $home);
    if (!is_resource($proc)) {
        @unlink($errFile);
        $report['error'] = 'could not start find';
        return $report;
    }

    $flagged = [];
    $buf = '';
    $errText = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false) {
            break;
        }
        $buf .= $chunk;
        while (($pos = strpos($buf, "\0")) !== false) {
            $rec = substr($buf, 0, $pos);
            $buf = substr($buf, $pos + 1);
            $parts = explode("\t", $rec, 3);
            if (count($parts) !== 3 || !sg_rel_ok($parts[2]) || isset($ignored[$parts[2]])) {
                continue;
            }
            $size = (int) $parts[0];
            $kind = sg_kind($parts[2], $size, $exts, $archiveMin, $largeMin);
            if ($kind === null) {
                continue;
            }
            $flagged[] = [
                'id'    => sg_file_id($parts[2]),
                'path'  => $parts[2],
                'size'  => $size,
                'mtime' => (int) $parts[1],
                'kind'  => $kind,
            ];
        }
    }
    fclose($pipes[1]);
    $exit = proc_close($proc);
    $errText = (string) @file_get_contents($errFile, false, null, 0, 4096);
    @unlink($errFile);

    if ($exit === 124) {
        $report['error'] = 'scan stopped after ' . ($timeout / 60) . ' min (SCAN_TIMEOUT_MIN) — results are partial';
    } elseif ($exit !== 0 && $exit !== 1) {
        $report['error'] = 'find exited ' . $exit . ': ' . trim(strtok($errText, "\n") ?: '');
    }
    // Exit 1 is find reporting an unreadable directory somewhere. The rest
    // of the tree was still walked, so the results stand.

    usort($flagged, fn($a, $b) => $b['size'] <=> $a['size']);
    foreach ($flagged as $f) {
        $report['flagged_count']++;
        $report['flagged_bytes'] += $f['size'];
        $report[$f['kind'] . '_count']++;
        $report[$f['kind'] . '_bytes'] += $f['size'];
    }
    $max = sg_int($conf, 'MAX_FILES_PER_ACCOUNT', 10);
    $report['truncated'] = count($flagged) > $max;
    $report['files'] = array_slice($flagged, 0, $max);
    $report['duration_s'] = round(microtime(true) - $started, 1);
    return $report;
}

function sg_report(string $user): ?array {
    return sg_read_json(SG_SPOOL . "/reports/$user.json");
}

function sg_save_report(array $report): void {
    sg_write_json(SG_SPOOL . '/reports/' . $report['user'] . '.json', $report);
}

/** Takes files out of a report after they were removed or allowed to stay. */
function sg_report_drop(array $report, array $ids): array {
    $ids = array_flip($ids);
    $keep = [];
    foreach ($report['files'] as $f) {
        if (isset($ids[$f['id']])) {
            $report['flagged_count']--;
            $report['flagged_bytes'] -= $f['size'];
            $report[$f['kind'] . '_count']--;
            $report[$f['kind'] . '_bytes'] -= $f['size'];
        } else {
            $keep[] = $f;
        }
    }
    foreach (['flagged', 'archive', 'large'] as $k) {
        $report["{$k}_count"] = max(0, $report["{$k}_count"]);
        $report["{$k}_bytes"] = max(0, $report["{$k}_bytes"]);
    }
    $report['files'] = $keep;
    return $report;
}

/** Puts files back into a report — a quarantine restore. */
function sg_report_add(array $report, array $files): array {
    $have = array_flip(array_column($report['files'], 'id'));
    foreach ($files as $f) {
        if (isset($have[$f['id']])) {
            continue;
        }
        $report['files'][] = $f;
        $report['flagged_count']++;
        $report['flagged_bytes'] += $f['size'];
        $report[$f['kind'] . '_count']++;
        $report[$f['kind'] . '_bytes'] += $f['size'];
    }
    usort($report['files'], fn($a, $b) => $b['size'] <=> $a['size']);
    return $report;
}

/**
 * Scans one account and records the result: report, case, the notice its
 * own page reads. Holds a marker while it works so the dashboard can show
 * the account as being scanned.
 */
function sg_scan_and_record(string $user, ?array $conf = null): array {
    sg_ensure_spool();
    $marker = SG_SPOOL . "/scanning/$user";
    @file_put_contents($marker, (string) getmypid());
    try {
        $report = sg_scan_user($user, $conf);
        sg_save_report($report);
        $case = sg_sync_case($user, $report);
        sg_publish_notice($user, $report, $case);
        return $report;
    } finally {
        @unlink($marker);
    }
}

/** Accounts being scanned right now (a marker younger than the timeout). */
function sg_scanning_users(array $conf): array {
    $out = [];
    $limit = sg_int($conf, 'SCAN_TIMEOUT_MIN', 1) * 60 + 300;
    foreach (glob(SG_SPOOL . '/scanning/*') ?: [] as $f) {
        // Written by the dashboard before the scan starts; the scan replaces
        // it with its pid. One still "queued" after two minutes never started.
        $age = time() - (int) @filemtime($f);
        if (@file_get_contents($f) === 'queued' && $age > 120) {
            @unlink($f);
        } elseif ($age < $limit) {
            $out[] = basename($f);
        } else {
            @unlink($f);
        }
    }
    return $out;
}

/** True while a full scan holds the run lock. */
function sg_scan_running(): bool {
    $fh = @fopen(SG_SCAN_LOCK, 'c');
    if (!$fh) {
        return false;
    }
    $free = flock($fh, LOCK_EX | LOCK_NB);
    if ($free) {
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return !$free;
}

// ---------------------------------------------------------------------------
// Cases: what has been said to an account, and what is due
// ---------------------------------------------------------------------------

function sg_case(string $user): array {
    $c = sg_read_json(SG_SPOOL . "/cases/$user.json") ?? [];
    return $c + [
        'user'               => $user,
        'open'               => false,
        'opened_at'          => null,
        'deadline'           => null,
        'reminders'          => [],
        'suspended_by_guard' => false,
        'suspended_at'       => null,
        'history'            => [],
    ];
}

function sg_save_case(array $case): void {
    // The history is for reading, not an archive — the log has everything.
    $case['history'] = array_slice($case['history'], -60);
    sg_write_json(SG_SPOOL . '/cases/' . $case['user'] . '.json', $case);
}

function sg_case_event(array &$case, string $action, string $by, string $detail = ''): void {
    $case['history'][] = ['at' => date('c'), 'action' => $action, 'by' => $by, 'detail' => $detail];
}

/**
 * Opens a case the first time an account has something flagged, and closes
 * it the moment a scan finds nothing — so an account that cleaned up after
 * one reminder is not chased by the next one, and one that fills up again
 * months later starts over with a fresh grace period.
 */
function sg_sync_case(string $user, array $report): array {
    $case = sg_case($user);
    $flagged = (int) $report['flagged_count'] > 0;
    if ($flagged && !$case['open']) {
        $case['open'] = true;
        $case['opened_at'] = date('c');
        $case['deadline'] = null;
        $case['reminders'] = [];
        sg_case_event($case, 'flagged', 'scan',
            $report['flagged_count'] . ' file(s), ' . sg_human($report['flagged_bytes']));
        sg_save_case($case);
    } elseif (!$flagged && $case['open']) {
        $n = count($case['reminders']);
        $case['open'] = false;
        $case['deadline'] = null;
        $case['reminders'] = [];
        sg_case_event($case, 'resolved', 'scan', $n ? "cleared after $n reminder(s)" : 'nothing flagged any more');
        sg_save_case($case);
    } elseif (!is_file(SG_SPOOL . "/cases/$user.json")) {
        sg_save_case($case);
    }
    return $case;
}

/**
 * clean | flagged | notified | overdue | suspended | unscanned | error
 *
 * "suspended" wins over everything: whatever else is true, that account's
 * sites are down, and that is what the admin needs to see first.
 */
function sg_status(?array $report, array $case, ?array $acct): string {
    if ($acct && $acct['suspended']) {
        return 'suspended';
    }
    if (!$report) {
        return 'unscanned';
    }
    if ((int) $report['flagged_count'] === 0) {
        return $report['error'] ? 'error' : 'clean';
    }
    if (!$case['reminders']) {
        return 'flagged';
    }
    if ($case['deadline'] && time() > strtotime($case['deadline'])) {
        return 'overdue';
    }
    return 'notified';
}

// ---------------------------------------------------------------------------
// What the account itself sees
// ---------------------------------------------------------------------------

/**
 * Hands the account its own view: the flagged files, and — once it has been
 * told — the deadline. The cPanel page runs as the account, not as root, so
 * this is written where that account (and only that account) can read it:
 * the spool copy is 0640 root:<user> in a directory nobody can list, and a
 * second copy sits in its home for servers that hide /var/spool from it.
 */
function sg_publish_notice(string $user, ?array $report = null, ?array $case = null): void {
    $conf   = sg_conf();
    $report = $report ?? sg_report($user);
    $case   = $case ?? sg_case($user);
    $acct   = sg_accounts()[$user] ?? null;
    if (!$report) {
        return;
    }
    $notified = (bool) $case['reminders'];
    $last = $notified ? end($case['reminders']) : null;

    $notice = [
        'user'          => $user,
        'generated_at'  => date('c'),
        'scanned_at'    => $report['scanned_at'],
        'status'        => sg_status($report, $case, $acct),
        'notified'      => $notified,
        'deadline'      => $notified ? $case['deadline'] : null,
        'reminders'     => count($case['reminders']),
        'last_reminder' => $last['at'] ?? null,
        'files'         => $report['files'],
        'flagged_count' => $report['flagged_count'],
        'flagged_bytes' => $report['flagged_bytes'],
        'truncated'     => $report['truncated'],
        'disk_used'     => $report['disk_used'],
        'disk_limit'    => $report['disk_limit'],
        'policy'        => $report['policy'],
        'message'       => (string) $conf['NOTICE_MESSAGE'],
        'support'       => (string) $conf['SUPPORT_CONTACT'],
    ];
    $json = json_encode($notice, JSON_UNESCAPED_SLASHES);

    $owner = sg_owner($user);
    $gid = $owner[2] ?? null;

    $spool = SG_SPOOL . "/notices/$user.json";
    if (sg_write_file($spool, $json, 0640) && $gid !== null) {
        @chgrp($spool, $gid);
    }

    if ($owner && is_dir($owner[0])) {
        sg_write_home_copy($owner[0], $gid, $json);
    }
}

/**
 * The copy inside the account's home. Root writing into a directory the
 * account controls is the classic way to be tricked into writing somewhere
 * else, so the directory is reached by sg_pin_dir() (which refuses a
 * symlink anywhere on the way), owned by root so the account cannot add to
 * it, and the file is created fresh and renamed into place relative to that
 * pinned directory.
 */
function sg_write_home_copy(string $home, ?int $gid, string $json): void {
    $prev = getcwd();
    try {
        if (sg_pin_dir($home, ['.skyserver-storage'], true, 0, $gid ?? 0) !== null) {
            return;
        }
        // The folder may have been made by the account itself. Take it over
        // before writing anything: while the account can still add or rename
        // entries in it, it could swap the file below for a link between our
        // creating it and our chgrp(), and have root change a system file.
        @chown('.', 0);
        @chgrp('.', $gid ?? 0);
        @chmod('.', 0750);
        clearstatcache();
        $here = @stat('.');
        if (!$here || $here['uid'] !== 0 || ($here['mode'] & 0022)) {
            return;
        }
        $tmp = '.notice.' . bin2hex(random_bytes(6));
        $fh = @fopen($tmp, 'x');
        if (!$fh) {
            return;
        }
        fwrite($fh, $json);
        fclose($fh);
        @chmod($tmp, 0640);
        if ($gid !== null) {
            @chgrp($tmp, $gid);
        }
        if (!@rename($tmp, 'notice.json')) {
            @unlink($tmp);
        }
    } finally {
        if ($prev) {
            @chdir($prev);
        }
    }
}

// ---------------------------------------------------------------------------
// Walking into an account's home safely
// ---------------------------------------------------------------------------

/**
 * chdir()s into $home and then into each of $parts, refusing any component
 * that is not a plain directory. After each step the directory actually
 * entered is compared with the one that was checked (device and inode), so
 * swapping a folder for a symlink in between is caught rather than followed.
 *
 * Every file operation root performs inside a customer's home goes through
 * this, and then works on a bare name relative to the pinned directory. The
 * current directory is held by inode, not by path, so nothing the account
 * renames afterwards can redirect it.
 *
 * Returns null on success, or the reason it refused.
 */
function sg_pin_dir(string $home, array $parts, bool $create = false, int $uid = 0, int $gid = 0): ?string {
    if (!@chdir($home)) {
        return 'cannot enter the home directory';
    }
    foreach ($parts as $p) {
        if ($p === '' || $p === '.') {
            continue;
        }
        if ($p === '..' || strpos($p, '/') !== false) {
            return 'invalid path';
        }
        clearstatcache();
        $st = @lstat($p);
        if (!$st && $create) {
            if (!@mkdir($p, 0755)) {
                return "cannot create folder $p";
            }
            @lchown($p, $uid);
            @lchgrp($p, $gid);
            clearstatcache();
            $st = @lstat($p);
        }
        if (!$st) {
            return "folder $p no longer exists";
        }
        if (($st['mode'] & 0170000) !== 0040000) {
            return "$p is not a plain folder (a symlink or a file)";
        }
        if (!@chdir($p)) {
            return "cannot enter $p";
        }
        clearstatcache();
        $here = @stat('.');
        if (!$here || $here['dev'] !== $st['dev'] || $here['ino'] !== $st['ino']) {
            return "$p changed while it was being opened";
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Admin actions
// ---------------------------------------------------------------------------

function sg_quarantine_root(string $home): string {
    // Beside the homes, not inside one: on the same filesystem, so moving a
    // 20 GB file in is a rename rather than a copy, and out of reach of the
    // account it came from.
    return dirname($home) . '/.skyserver-quarantine';
}

/**
 * Quarantines or deletes flagged files. Only files in the account's latest
 * report can be named (by id), so a crafted request cannot point this at
 * anything the scan did not find inside that home.
 *
 * Quarantine moves the file out of the account and hands it to root — it no
 * longer counts against the customer's quota and they cannot see it, but it
 * can be put back until QUARANTINE_DAYS have passed.
 */
function sg_remove_files(string $user, array $ids, string $mode, string $by): array {
    $conf = sg_conf();
    $report = sg_report($user);
    $owner = sg_owner($user);
    if (!$report || !$owner) {
        return ['ok' => false, 'error' => 'No scan of this account to work from — scan it first.'];
    }
    [$home, $uid, $gid] = $owner;
    $byId = [];
    foreach ($report['files'] as $f) {
        $byId[$f['id']] = $f;
    }

    $batch = null;
    if ($mode === 'quarantine') {
        $qroot = sg_quarantine_root($home);
        if (!is_dir($qroot) && !@mkdir($qroot, 0700, true)) {
            return ['ok' => false, 'error' => "Could not create the quarantine folder $qroot."];
        }
        @chown($qroot, 0);
        @chmod($qroot, 0700);
        $bid = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $batch = [
            'id'          => $bid,
            'user'        => $user,
            'dir'         => "$qroot/$user/$bid",
            'created_at'  => date('c'),
            'by'          => $by,
            'purge_after' => date('c', time() + sg_int($conf, 'QUARANTINE_DAYS', 1) * 86400),
            'files'       => [],
        ];
    }

    $done = [];
    $bytes = 0;
    $failed = [];
    $prev = getcwd();
    foreach (array_unique($ids) as $id) {
        $f = $byId[$id] ?? null;
        if (!$f) {
            $failed[] = ['path' => $id, 'error' => 'not in the latest scan — rescan and try again'];
            continue;
        }
        $rel = $f['path'];
        if (!sg_rel_ok($rel)) {
            $failed[] = ['path' => $rel, 'error' => 'invalid path'];
            continue;
        }
        $dir = dirname($rel);
        $base = basename($rel);
        $err = sg_pin_dir($home, $dir === '.' ? [] : explode('/', $dir));
        if ($err !== null) {
            $failed[] = ['path' => $rel, 'error' => $err];
            continue;
        }
        clearstatcache();
        $st = @lstat($base);
        if (!$st) {
            // Already gone — the customer got there first. Count it as done.
            $done[] = $id;
            continue;
        }
        if (($st['mode'] & 0170000) !== 0100000) {
            $failed[] = ['path' => $rel, 'error' => 'no longer a regular file'];
            continue;
        }

        if ($mode === 'delete') {
            if (!@unlink($base)) {
                $failed[] = ['path' => $rel, 'error' => sg_last_error('could not delete')];
                continue;
            }
        } else {
            $qpath = $batch['dir'] . '/files/' . $rel;
            if (!is_dir(dirname($qpath)) && !@mkdir(dirname($qpath), 0700, true)) {
                $failed[] = ['path' => $rel, 'error' => 'could not create the quarantine folder'];
                continue;
            }
            if (!@rename($base, $qpath)) {
                $e = sg_last_error('could not move');
                if (stripos($e, 'cross-device') !== false) {
                    $e = 'on a different filesystem from ' . sg_quarantine_root($home) . ' — use "Delete permanently" instead';
                }
                $failed[] = ['path' => $rel, 'error' => $e];
                continue;
            }
            @lchown($qpath, 0);
            @lchgrp($qpath, 0);
            $batch['files'][] = [
                'entry'    => $f,
                'qpath'    => $qpath,
                'uid'      => $st['uid'],
                'gid'      => $st['gid'],
                'mode'     => $st['mode'] & 07777,
                'restored' => false,
            ];
        }
        $done[] = $id;
        $bytes += $f['size'];
    }
    if ($prev) {
        @chdir($prev);
    }

    if ($batch && $batch['files']) {
        sg_write_json(SG_SPOOL . "/quarantine/{$batch['id']}.json", $batch);
    }

    $report = sg_report_drop($report, $done);
    sg_save_report($report);
    $case = sg_case($user);
    $verb = $mode === 'delete' ? 'deleted' : 'quarantined';
    if ($done) {
        sg_case_event($case, $verb, $by, count($done) . ' file(s), ' . sg_human($bytes)
            . ($batch && $batch['files'] ? " (batch {$batch['id']})" : ''));
        sg_save_case($case);
    }
    $case = sg_sync_case($user, $report);
    sg_publish_notice($user, $report, $case);

    sg_log(($failed ? '[WARN]' : '[OK]') . " $user: $by $verb " . count($done) . ' file(s), ' . sg_human($bytes)
        . ($failed ? ', ' . count($failed) . ' failed: ' . $failed[0]['path'] . ' — ' . $failed[0]['error'] : ''));

    return [
        'ok'      => (bool) $done || !$failed,
        'removed' => count($done),
        'bytes'   => $bytes,
        'failed'  => $failed,
        'batch'   => $batch && $batch['files'] ? $batch['id'] : null,
        'error'   => !$done && $failed ? $failed[0]['path'] . ': ' . $failed[0]['error'] : null,
        'message' => count($done) . ' file(s) ' . $verb . ', ' . sg_human($bytes) . ' freed'
            . ($mode === 'quarantine' ? ' from the account' : '')
            . ($failed ? ' — ' . count($failed) . ' could not be: ' . $failed[0]['error'] : '') . '.',
    ];
}

function sg_last_error(string $fallback): string {
    $e = error_get_last();
    if (!$e) {
        return $fallback;
    }
    $msg = preg_replace('/^[a-z_]+\([^)]*\):\s*/i', '', $e['message']);
    return $msg ?: $fallback;
}

/** Allows files to stay: never flagged again until un-ignored. */
function sg_ignore_files(string $user, array $ids, string $by): array {
    $report = sg_report($user);
    if (!$report) {
        return ['ok' => false, 'error' => 'No scan of this account yet.'];
    }
    $ignores = sg_ignores();
    $list = $ignores[$user] ?? [];
    $done = [];
    foreach ($report['files'] as $f) {
        if (in_array($f['id'], $ids, true)) {
            $list[] = $f['path'];
            $done[] = $f['id'];
        }
    }
    if (!$done) {
        return ['ok' => false, 'error' => 'None of those files are in the latest scan.'];
    }
    $ignores[$user] = array_values(array_unique($list));
    sg_write_json(SG_SPOOL . '/ignores.json', $ignores);

    $report = sg_report_drop($report, $done);
    sg_save_report($report);
    $case = sg_case($user);
    sg_case_event($case, 'ignored', $by, count($done) . ' file(s) allowed to stay');
    sg_save_case($case);
    $case = sg_sync_case($user, $report);
    sg_publish_notice($user, $report, $case);
    sg_log("[OK] $user: $by allowed " . count($done) . ' file(s) to stay');
    return ['ok' => true, 'message' => count($done) . ' file(s) will no longer be flagged.'];
}

function sg_unignore(string $user, string $path, string $by): array {
    $ignores = sg_ignores();
    $list = $ignores[$user] ?? [];
    $next = array_values(array_filter($list, fn($p) => $p !== $path));
    if (count($next) === count($list)) {
        return ['ok' => false, 'error' => 'That file is not on the allowed list.'];
    }
    if ($next) {
        $ignores[$user] = $next;
    } else {
        unset($ignores[$user]);
    }
    sg_write_json(SG_SPOOL . '/ignores.json', $ignores);
    sg_log("[OK] $user: $by removed $path from the allowed list");
    return ['ok' => true, 'message' => 'Removed from the allowed list — the next scan of this account will check it again.'];
}

// ---------------------------------------------------------------------------
// Reminders
// ---------------------------------------------------------------------------

function sg_mail_header(string $s): string {
    $s = trim(str_replace(["\r", "\n"], ' ', $s));
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function sg_hostname(): string {
    return trim((string) (gethostname() ?: 'localhost'));
}

/** Sends one plain-text message through the local MTA. Returns null or the error. */
function sg_send_mail(string $to, string $subject, string $body, array $conf): ?string {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no valid email address';
    }
    $from = trim((string) $conf['NOTICE_FROM']);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'noreply@' . sg_hostname();
    }
    $msg = 'From: ' . sg_mail_header($from) . "\n"
         . 'To: ' . sg_mail_header($to) . "\n"
         . 'Subject: ' . sg_mail_header($subject) . "\n"
         . 'Date: ' . date('r') . "\n"
         . 'Message-ID: <' . bin2hex(random_bytes(8)) . '.storage-guard@' . sg_hostname() . ">\n"
         . "MIME-Version: 1.0\n"
         . "Content-Type: text/plain; charset=UTF-8\n"
         . "Content-Transfer-Encoding: 8bit\n"
         . "Auto-Submitted: auto-generated\n"
         . "X-Mailer: SkyServer Storage Guard\n\n"
         . $body;

    $sendmail = getenv('SG_SENDMAIL') ?: (is_executable('/usr/sbin/sendmail') ? '/usr/sbin/sendmail' : sg_which('sendmail'));
    if (!$sendmail) {
        return 'no sendmail on this server';
    }
    [$code, , $err] = sg_run([$sendmail, '-t', '-i', '-f', $from], $msg);
    return $code === 0 ? null : ('sendmail exited ' . $code . ($err ? ': ' . trim($err) : ''));
}

function sg_reminder_text(string $user, array $report, array $case, ?array $acct, array $conf): array {
    $n = count($case['reminders']) + 1;
    $deadline = $case['deadline'] ? date('j F Y', strtotime($case['deadline'])) : '';
    $subject = ($n > 1 ? "Reminder $n: " : '') . $conf['NOTICE_SUBJECT'];

    $lines = [];
    foreach (array_slice($report['files'], 0, 25) as $f) {
        $lines[] = str_pad(sg_human($f['size']), 9, ' ', STR_PAD_LEFT) . '  ' . $f['path'];
    }
    $more = $report['flagged_count'] - count($lines);

    $body = "Hello,\n\n"
        . 'This is ' . ($n > 1 ? "reminder $n" : 'a notice') . ' about the storage used by your hosting account "'
        . $user . '"' . (!empty($acct['domain']) ? ' (' . $acct['domain'] . ')' : '') . ".\n\n"
        . 'Our scan on ' . date('j F Y', strtotime($report['scanned_at'])) . ' found '
        . $report['flagged_count'] . ' large or archive/backup file(s) taking up '
        . sg_human($report['flagged_bytes']) . ". Hosting space is meant for your website and email, not for storing "
        . "backups, archives or other large files — please download anything you want to keep, then delete these from the server.\n\n"
        . implode("\n", $lines) . "\n"
        . ($more > 0 ? "  … and $more more.\n" : '')
        . "\nThe paths are relative to your home directory.\n\n"
        . ($deadline ? "Please remove them by $deadline. After that date our team may remove the files for you, "
                       . "or suspend the account until they are removed.\n\n" : '')
        . "You can see and delete these files in cPanel → Files → Storage Report, or with File Manager.\n"
        . (trim((string) $conf['NOTICE_MESSAGE']) !== '' ? "\n" . trim((string) $conf['NOTICE_MESSAGE']) . "\n" : '')
        . (trim((string) $conf['SUPPORT_CONTACT']) !== '' ? "\nQuestions? Contact us: " . trim((string) $conf['SUPPORT_CONTACT']) . "\n" : '')
        . "\nThank you.\n";

    return [$subject, $body];
}

/**
 * Tells an account about its flagged files: email to its contact address,
 * and the notice on its own cPanel page. The first reminder starts the
 * grace period; later ones repeat the same deadline rather than moving it.
 */
function sg_remind(string $user, string $by): array {
    $conf = sg_conf();
    $report = sg_report($user);
    if (!$report || (int) $report['flagged_count'] === 0) {
        return ['ok' => false, 'error' => 'Nothing is flagged on this account.'];
    }
    $acct = sg_accounts()[$user] ?? null;
    $case = sg_sync_case($user, $report);
    if (!$case['deadline']) {
        $case['deadline'] = date('c', time() + sg_int($conf, 'GRACE_DAYS', 0) * 86400);
    }

    [$subject, $body] = sg_reminder_text($user, $report, $case, $acct, $conf);
    $to = $acct['email'] ?? '';
    $mailErr = $to !== '' ? sg_send_mail($to, $subject, $body, $conf) : 'the account has no contact email';

    $case['reminders'][] = [
        'at'         => date('c'),
        'by'         => $by,
        'email'      => $to ?: null,
        'email_ok'   => $mailErr === null,
        'email_error'=> $mailErr,
    ];
    sg_case_event($case, 'reminder', $by, $mailErr === null
        ? "emailed $to" : "shown in cPanel only — email not sent: $mailErr");
    sg_save_case($case);
    sg_publish_notice($user, $report, $case);

    $n = count($case['reminders']);
    sg_log(($mailErr ? '[WARN]' : '[OK]') . " $user: reminder $n by $by"
        . ($mailErr ? " — email not sent: $mailErr" : " — emailed $to"));

    return [
        'ok'       => true,
        'email_ok' => $mailErr === null,
        'message'  => $mailErr === null
            ? "Reminder $n sent to $to — deadline " . date('j M Y', strtotime($case['deadline'])) . '.'
            : "Reminder $n is on the account's cPanel page, but no email went out: $mailErr.",
    ];
}

// ---------------------------------------------------------------------------
// Suspension
// ---------------------------------------------------------------------------

function sg_suspend(string $user, string $reason, string $by): array {
    $reason = trim($reason) !== '' ? trim($reason) : (string) sg_conf()['SUSPEND_REASON'];
    $res = sg_whmapi('suspendacct', ['user' => $user, 'reason' => $reason]);
    if (!$res['ok']) {
        sg_log("[FAIL] $user: suspend by $by failed — {$res['error']}");
        return ['ok' => false, 'error' => 'WHM refused to suspend the account: ' . $res['error']];
    }
    $case = sg_case($user);
    $case['suspended_by_guard'] = true;
    $case['suspended_at'] = date('c');
    sg_case_event($case, 'suspended', $by, $reason);
    sg_save_case($case);
    sg_accounts(true);
    sg_publish_notice($user, null, $case);
    sg_log("[OK] $user: suspended by $by — $reason");
    return ['ok' => true, 'message' => "$user is suspended."];
}

function sg_unsuspend(string $user, string $by): array {
    $res = sg_whmapi('unsuspendacct', ['user' => $user]);
    if (!$res['ok']) {
        sg_log("[FAIL] $user: unsuspend by $by failed — {$res['error']}");
        return ['ok' => false, 'error' => 'WHM refused to unsuspend the account: ' . $res['error']];
    }
    $case = sg_case($user);
    $case['suspended_by_guard'] = false;
    $case['suspended_at'] = null;
    sg_case_event($case, 'unsuspended', $by);
    sg_save_case($case);
    sg_accounts(true);
    sg_publish_notice($user, null, $case);
    sg_log("[OK] $user: unsuspended by $by");
    return ['ok' => true, 'message' => "$user is active again."];
}

// ---------------------------------------------------------------------------
// Quarantine
// ---------------------------------------------------------------------------

function sg_quarantine_batches(): array {
    $out = [];
    foreach (glob(SG_SPOOL . '/quarantine/*.json') ?: [] as $f) {
        $b = sg_read_json($f);
        if (!$b) {
            continue;
        }
        $left = array_values(array_filter($b['files'], fn($x) => !$x['restored']));
        $out[] = [
            'id'          => $b['id'],
            'user'        => $b['user'],
            'created_at'  => $b['created_at'],
            'by'          => $b['by'],
            'purge_after' => $b['purge_after'],
            'count'       => count($left),
            'bytes'       => array_sum(array_map(fn($x) => $x['entry']['size'], $left)),
            'files'       => array_map(fn($x) => ['path' => $x['entry']['path'], 'size' => $x['entry']['size']], $left),
        ];
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $out;
}

/**
 * Puts a batch back where it came from, owned by the account again. A file
 * whose old path is now taken is left in quarantine rather than overwriting
 * whatever the customer has put there since.
 */
function sg_quarantine_restore(string $id, string $by): array {
    $path = SG_SPOOL . "/quarantine/$id.json";
    $b = sg_read_json($path);
    if (!$b) {
        return ['ok' => false, 'error' => 'That quarantine batch no longer exists.'];
    }
    $owner = sg_owner($b['user']);
    if (!$owner) {
        return ['ok' => false, 'error' => "The account {$b['user']} no longer exists."];
    }
    [$home, $uid, $gid] = $owner;
    $prev = getcwd();
    $back = [];
    $failed = [];
    foreach ($b['files'] as &$f) {
        if ($f['restored']) {
            continue;
        }
        $rel = $f['entry']['path'];
        if (!is_file($f['qpath'])) {
            $failed[] = "$rel: missing from quarantine";
            continue;
        }
        $dir = dirname($rel);
        $err = sg_pin_dir($home, $dir === '.' ? [] : explode('/', $dir), true, $uid, $gid);
        if ($err !== null) {
            $failed[] = "$rel: $err";
            continue;
        }
        clearstatcache();
        if (@lstat(basename($rel))) {
            $failed[] = "$rel: something else is at that path now";
            continue;
        }
        // Ownership and mode are set while the file is still inside root's
        // own folder, so nothing is ever chmod'ed through a customer path.
        @chown($f['qpath'], (int) $f['uid']);
        @chgrp($f['qpath'], (int) $f['gid']);
        @chmod($f['qpath'], (int) $f['mode']);
        if (!@rename($f['qpath'], basename($rel))) {
            $failed[] = "$rel: " . sg_last_error('could not move back');
            continue;
        }
        $f['restored'] = true;
        $back[] = $f['entry'];
    }
    unset($f);
    if ($prev) {
        @chdir($prev);
    }

    $left = array_filter($b['files'], fn($x) => !$x['restored']);
    if ($left) {
        sg_write_json($path, $b);
    } else {
        sg_rmtree($b['dir']);
        @unlink($path);
        @rmdir(dirname($b['dir']));
    }

    if ($back) {
        $report = sg_report($b['user']);
        if ($report) {
            $report = sg_report_add($report, $back);
            sg_save_report($report);
            $case = sg_case($b['user']);
            sg_case_event($case, 'restored', $by, count($back) . " file(s) from batch $id");
            sg_save_case($case);
            $case = sg_sync_case($b['user'], $report);
            sg_publish_notice($b['user'], $report, $case);
        }
    }
    sg_log(($failed ? '[WARN]' : '[OK]') . " {$b['user']}: $by restored " . count($back) . " file(s) from quarantine $id"
        . ($failed ? '; ' . implode('; ', $failed) : ''));
    return [
        'ok'      => (bool) $back || !$failed,
        'error'   => !$back && $failed ? $failed[0] : null,
        'message' => count($back) . ' file(s) put back' . ($failed ? ' — ' . count($failed) . ' left in quarantine: ' . $failed[0] : '') . '.',
    ];
}

function sg_quarantine_purge(string $id, string $by): array {
    $path = SG_SPOOL . "/quarantine/$id.json";
    $b = sg_read_json($path);
    if (!$b) {
        return ['ok' => false, 'error' => 'That quarantine batch no longer exists.'];
    }
    $bytes = array_sum(array_map(fn($x) => $x['restored'] ? 0 : $x['entry']['size'], $b['files']));
    sg_rmtree($b['dir']);
    @rmdir(dirname($b['dir']));
    @unlink($path);
    sg_log("[OK] {$b['user']}: $by purged quarantine $id (" . sg_human($bytes) . ')');
    return ['ok' => true, 'message' => 'Quarantine batch deleted for good — ' . sg_human($bytes) . ' freed on disk.'];
}

function sg_purge_expired(): int {
    $n = 0;
    foreach (sg_quarantine_batches() as $b) {
        if (strtotime($b['purge_after']) < time()) {
            sg_quarantine_purge($b['id'], 'retention');
            $n++;
        }
    }
    return $n;
}

/** Deletes a tree that lives in root's own quarantine folder. Never follows a link. */
function sg_rmtree(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = "$dir/$e";
        if (is_dir($p) && !is_link($p)) {
            sg_rmtree($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

// ---------------------------------------------------------------------------
// The overview both the dashboard and the admin digest are built from
// ---------------------------------------------------------------------------

function sg_account_rows(array $conf, int $topFiles = 40): array {
    $rows = [];
    foreach (sg_accounts() as $user => $acct) {
        $report = sg_report($user);
        $case = sg_case($user);
        $status = sg_status($report, $case, $acct);
        $last = $case['reminders'] ? end($case['reminders']) : null;
        $rows[] = [
            'user'           => $user,
            'domain'         => $acct['domain'],
            'email'          => $acct['email'],
            'owner'          => $acct['owner'],
            'plan'           => $acct['plan'],
            'suspended'      => $acct['suspended'],
            'suspendreason'  => $acct['suspendreason'],
            'by_guard'       => (bool) $case['suspended_by_guard'],
            'disk_used'      => $acct['disk_used'],
            'disk_limit'     => $acct['disk_limit'],
            'status'         => $status,
            'scanned_at'     => $report['scanned_at'] ?? null,
            'scan_error'     => $report['error'] ?? null,
            'flagged_count'  => (int) ($report['flagged_count'] ?? 0),
            'flagged_bytes'  => (int) ($report['flagged_bytes'] ?? 0),
            'archive_count'  => (int) ($report['archive_count'] ?? 0),
            'large_count'    => (int) ($report['large_count'] ?? 0),
            'reminders'      => count($case['reminders']),
            'last_reminder'  => $last['at'] ?? null,
            'last_email_ok'  => $last['email_ok'] ?? null,
            'deadline'       => $case['deadline'],
            'opened_at'      => $case['opened_at'],
            'top'            => array_slice($report['files'] ?? [], 0, $topFiles),
        ];
    }
    return $rows;
}

function sg_totals(array $rows): array {
    $t = ['accounts' => count($rows), 'scanned' => 0, 'flagged' => 0, 'flagged_bytes' => 0,
          'flagged_files' => 0, 'notified' => 0, 'overdue' => 0, 'suspended' => 0, 'awaiting' => 0];
    foreach ($rows as $r) {
        if ($r['scanned_at']) { $t['scanned']++; }
        if ($r['flagged_count'] > 0) {
            $t['flagged']++;
            $t['flagged_bytes'] += $r['flagged_bytes'];
            $t['flagged_files'] += $r['flagged_count'];
        }
        if ($r['status'] === 'notified')  { $t['notified']++; }
        if ($r['status'] === 'overdue')   { $t['overdue']++; }
        if ($r['status'] === 'flagged')   { $t['awaiting']++; }
        if ($r['suspended'] && $r['by_guard']) { $t['suspended']++; }
    }
    return $t;
}

function sg_scan_state(): ?array {
    return sg_read_json(SG_SCAN_STATE);
}
