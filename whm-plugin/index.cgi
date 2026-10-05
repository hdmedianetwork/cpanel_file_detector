#!__PHP_BIN__
<?php
/**
 * SkyServer Storage Guard — WHM admin dashboard (root only, served by
 * WHM/cpsrvd as a CGI script via AppConfig).
 *
 * Same shape as the Backup Manager's dashboard: PHP renders the chrome once
 * with a snapshot of the state, and every button after that goes through
 * the JSON API in this same file (`index.cgi?api=<action>`). Nothing reloads
 * the page.
 *
 * All the real work — scanning, reminders, quarantine, suspension — lives in
 * lib/guard-lib.php, which the nightly cron job uses too, so the dashboard
 * and cron cannot disagree about what an action does.
 *
 * The shebang above is rewritten by bin/deploy.sh to the real PHP binary.
 */

// A PHP notice printed into a JSON reply makes it unparseable. The CLI
// binary prints them to stdout by default — send them to the error log.
ini_set('display_errors', 'stderr');

// cpsrvd runs this through the PHP *CLI* binary, which neither prints
// headers nor fills $_GET/$_POST. Both are done by hand, and the request is
// parsed before the header block because the content type depends on it.
if (PHP_SAPI === 'cli') {
    parse_str((string) ($_SERVER['QUERY_STRING'] ?? getenv('QUERY_STRING') ?: ''), $_GET);
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? getenv('REQUEST_METHOD') ?: 'GET';

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? getenv('CONTENT_TYPE')), 'application/x-www-form-urlencoded') !== false) {
        // Bounded by CONTENT_LENGTH: reading stdin to EOF can block until the
        // client closes the connection.
        $len  = (int) ($_SERVER['CONTENT_LENGTH'] ?? getenv('CONTENT_LENGTH'));
        $body = '';
        while (strlen($body) < $len && !feof(STDIN)) {
            $chunk = fread(STDIN, $len - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        parse_str(trim($body), $_POST);
    }
    $_REQUEST = $_POST + $_GET;
}

$apiAction   = (string) ($_GET['api'] ?? '');
$isApi       = $apiAction !== '';
$contentType = $isApi ? 'application/json; charset=utf-8' : 'text/html; charset=utf-8';

if (PHP_SAPI === 'cli') {
    echo "Content-type: $contentType\r\n\r\n";
} elseif (!headers_sent()) {
    header("Content-Type: $contentType");
}

require_once getenv('SG_GUARD_LIB') ?: '/opt/skyserver-storage-guard/lib/guard-lib.php';
// bin/deploy.sh puts the design system next to this file; in the repo it is in ui/.
require_once is_file(__DIR__ . '/sky-ui.php') ? __DIR__ . '/sky-ui.php' : dirname(__DIR__) . '/ui/sky-ui.php';

const SG_LOGO_TITLE = 'SkyServer Storage Guard';

/** Who did it, for the log and the account's history. */
function actor(): string {
    $u = (string) (getenv('REMOTE_USER') ?: 'root');
    return 'whm:' . preg_replace('/[^a-z0-9_]/i', '', $u);
}

/** The whole config is safe to show — it holds no secrets. */
function public_conf(array $conf): array {
    $out = [];
    foreach (array_keys(SG_DEFAULTS) as $k) {
        $out[$k] = (string) ($conf[$k] ?? '');
    }
    return $out;
}

function log_tail(int $lines = 250): string {
    if (!is_readable(SG_LOG_FILE)) {
        return '(no log yet — it starts with the first scan)';
    }
    $out = shell_exec('tail -n ' . (int) $lines . ' ' . escapeshellarg(SG_LOG_FILE) . ' 2>/dev/null');
    return $out !== null && $out !== '' ? $out : '(log is empty)';
}

function dashboard_state(): array {
    $conf = sg_conf();
    $rows = sg_account_rows($conf, 40);
    $ignores = sg_ignores();
    return [
        'version'        => trim((string) @file_get_contents(SG_INSTALL_DIR . '/VERSION')) ?: 'unknown',
        'scanning'       => sg_scan_running(),
        'scan'           => sg_scan_state(),
        'scanning_users' => sg_scanning_users($conf),
        'accounts'       => $rows,
        'accounts_error' => sg_accounts_error(),
        'totals'         => sg_totals($rows),
        'quarantine'     => sg_quarantine_batches(),
        'ignored'        => array_sum(array_map('count', $ignores)),
        'config'         => public_conf($conf),
        'mail_ready'     => (bool) (getenv('SG_SENDMAIL') ?: is_executable('/usr/sbin/sendmail') ?: sg_which('sendmail')),
        'server_time'    => date('c'),
    ];
}

/** The CLI binary cannot set a status (cpsrvd reads ours from the body). */
function status(int $code): void {
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
    }
}

function json_out(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function posted_user(): string {
    $u = (string) ($_POST['user'] ?? $_GET['user'] ?? '');
    if (!sg_valid_user($u) || !isset(sg_accounts()[$u])) {
        json_out(['ok' => false, 'error' => 'Unknown account.']);
    }
    return $u;
}

function posted_ids(): array {
    $ids = array_filter(explode(',', (string) ($_POST['ids'] ?? '')), fn($i) => preg_match('/^[0-9a-f]{16}$/', $i));
    if (!$ids) {
        json_out(['ok' => false, 'error' => 'No files selected.']);
    }
    return array_values($ids);
}

function launch(array $args): void {
    $cmd = 'nohup ' . escapeshellarg(SG_INSTALL_DIR . '/bin/guard');
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    shell_exec($cmd . ' > /dev/null 2>&1 &');
}

// ---------------------------------------------------------------------------
// JSON API. Every button on the page lands here; nothing reloads.
// ---------------------------------------------------------------------------
if ($isApi) {
    sg_ensure_spool();
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

    // Anything that changes the server is POST-only, so a prefetch or a
    // bookmarked URL can never delete a file or suspend an account.
    $readOnly = ['state', 'log', 'account', 'update_check'];
    if (!in_array($apiAction, $readOnly, true) && !$isPost) {
        status(405);
        json_out(['ok' => false, 'error' => 'This action requires POST.']);
    }

    switch ($apiAction) {

        case 'state':
            json_out(['ok' => true, 'state' => dashboard_state()]);

        case 'log':
            json_out(['ok' => true, 'log' => log_tail(), 'scanning' => sg_scan_running()]);

        case 'account':
            $user = posted_user();
            $report = sg_report($user);
            $case = sg_case($user);
            $acct = sg_accounts()[$user];
            json_out(['ok' => true, 'account' => [
                'user'      => $user,
                'acct'      => $acct,
                'status'    => sg_status($report, $case, $acct),
                'report'    => $report,
                'case'      => $case,
                'ignored'   => sg_ignores()[$user] ?? [],
                'scanning'  => in_array($user, sg_scanning_users(sg_conf()), true),
                'quarantine'=> array_values(array_filter(sg_quarantine_batches(), fn($b) => $b['user'] === $user)),
            ]]);

        case 'scan_all':
            if (sg_scan_running()) {
                json_out(['ok' => false, 'error' => 'A scan is already running.']);
            }
            launch(['scan-all']);
            json_out(['ok' => true, 'message' => 'Scan started — this page will follow it.']);

        case 'scan_user':
            $user = posted_user();
            launch(['scan-user', $user]);
            // Marked here as well as by the scan itself, so the very next
            // poll already shows the account as being scanned.
            @file_put_contents(SG_SPOOL . "/scanning/$user", 'queued');
            json_out(['ok' => true, 'message' => "Scanning $user…"]);

        case 'remind':
            json_out(sg_remind(posted_user(), actor()));

        case 'remind_all':
            // Accounts that have never been told. Ones already notified keep
            // their own schedule — the button is for the first notice.
            $sent = 0;
            $noMail = 0;
            foreach (sg_account_rows(sg_conf(), 0) as $r) {
                if ($r['status'] === 'flagged') {
                    $res = sg_remind($r['user'], actor());
                    if ($res['ok']) {
                        $sent++;
                        if (empty($res['email_ok'])) {
                            $noMail++;
                        }
                    }
                }
            }
            json_out(['ok' => true, 'message' => $sent
                ? "$sent account(s) notified" . ($noMail ? " — $noMail of them only in cPanel, the email could not be sent" : '') . '.'
                : 'No account was waiting for a first notice.', 'state' => dashboard_state()]);

        case 'remove_files':
            $mode = ($_POST['mode'] ?? '') === 'delete' ? 'delete' : 'quarantine';
            json_out(sg_remove_files(posted_user(), posted_ids(), $mode, actor()));

        case 'ignore_files':
            json_out(sg_ignore_files(posted_user(), posted_ids(), actor()));

        case 'unignore':
            json_out(sg_unignore(posted_user(), (string) ($_POST['path'] ?? ''), actor()));

        case 'suspend':
            json_out(sg_suspend(posted_user(), (string) ($_POST['reason'] ?? ''), actor()));

        case 'unsuspend':
            json_out(sg_unsuspend(posted_user(), actor()));

        case 'quarantine_restore':
        case 'quarantine_purge':
            $id = (string) ($_POST['id'] ?? '');
            if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id)) {
                json_out(['ok' => false, 'error' => 'Unknown quarantine batch.']);
            }
            json_out($apiAction === 'quarantine_restore'
                ? sg_quarantine_restore($id, actor())
                : sg_quarantine_purge($id, actor()));

        case 'save_config':
            $num = fn($k, $min, $max) => (string) max($min, min($max, (int) ($_POST[$k] ?? SG_DEFAULTS[$k])));
            $txt = fn($k) => trim((string) ($_POST[$k] ?? ''));
            $updates = [
                'LARGE_FILE_MB'         => $num('LARGE_FILE_MB', 1, 1048576),
                'ARCHIVE_MIN_MB'        => $num('ARCHIVE_MIN_MB', 1, 1048576),
                'ARCHIVE_EXTENSIONS'    => preg_replace('/[^a-z0-9. ]/', '', strtolower(preg_replace('/[\s,]+/', ' ', $txt('ARCHIVE_EXTENSIONS')))),
                'EXCLUDE_PATHS'         => implode(' ', array_filter(preg_split('/[\s,]+/', $txt('EXCLUDE_PATHS')), 'sg_rel_ok')),
                'MAX_FILES_PER_ACCOUNT' => $num('MAX_FILES_PER_ACCOUNT', 10, 5000),
                'SCAN_TIMEOUT_MIN'      => $num('SCAN_TIMEOUT_MIN', 1, 1440),
                'GRACE_DAYS'            => $num('GRACE_DAYS', 0, 365),
                'AUTO_REMIND'           => ($_POST['AUTO_REMIND'] ?? '0') === '1' ? '1' : '0',
                'REMIND_EVERY_DAYS'     => $num('REMIND_EVERY_DAYS', 1, 365),
                'AUTO_SUSPEND'          => ($_POST['AUTO_SUSPEND'] ?? '0') === '1' ? '1' : '0',
                'QUARANTINE_DAYS'       => $num('QUARANTINE_DAYS', 1, 365),
                'NOTICE_FROM'           => filter_var($txt('NOTICE_FROM'), FILTER_VALIDATE_EMAIL) ? $txt('NOTICE_FROM') : '',
                'NOTICE_SUBJECT'        => $txt('NOTICE_SUBJECT') !== '' ? $txt('NOTICE_SUBJECT') : SG_DEFAULTS['NOTICE_SUBJECT'],
                'NOTICE_MESSAGE'        => $txt('NOTICE_MESSAGE'),
                'SUPPORT_CONTACT'       => $txt('SUPPORT_CONTACT'),
                'SUSPEND_REASON'        => $txt('SUSPEND_REASON') !== '' ? $txt('SUSPEND_REASON') : SG_DEFAULTS['SUSPEND_REASON'],
                'ALERT_EMAIL'           => filter_var($txt('ALERT_EMAIL'), FILTER_VALIDATE_EMAIL) ? $txt('ALERT_EMAIL') : '',
            ];
            if ($updates['ARCHIVE_EXTENSIONS'] === '') {
                $updates['ARCHIVE_EXTENSIONS'] = SG_DEFAULTS['ARCHIVE_EXTENSIONS'];
            }
            sg_write_conf($updates);
            sg_log('[*] Settings saved by ' . actor());
            json_out(['ok' => true, 'message' => 'Settings saved. Thresholds apply from the next scan.',
                      'state' => dashboard_state()]);

        case 'test_email':
            $to = trim((string) ($_POST['to'] ?? ''));
            $err = sg_send_mail($to, '[Storage Guard] Test message from ' . sg_hostname(),
                "This is a test from SkyServer Storage Guard on " . sg_hostname() . ".\n\n"
                . "If you can read it, reminders to your customers will be delivered the same way.\n", sg_conf());
            json_out($err === null
                ? ['ok' => true, 'message' => "Test email handed to the mail server for $to — check that inbox (and spam)."]
                : ['ok' => false, 'error' => "Could not send: $err"]);

        case 'update_check':
            $out = trim((string) shell_exec(escapeshellarg(SG_INSTALL_DIR . '/bin/self-update.sh') . ' check 2>&1'));
            if (preg_match('/local (\S+) remote (\S+) (update|current)$/', $out, $m)) {
                json_out(['ok' => true, 'available' => $m[3] === 'update', 'local' => $m[1], 'remote' => $m[2],
                          'message' => $m[3] === 'update' ? "Version {$m[2]} is available — you are on {$m[1]}."
                                                          : "You are up to date (v{$m[1]})."]);
            }
            json_out(['ok' => false, 'error' => 'Could not reach GitHub to check for updates.']);

        case 'update_apply':
            $raw = (string) shell_exec(escapeshellarg(SG_INSTALL_DIR . '/bin/self-update.sh') . ' apply 2>&1');
            $ok = (bool) preg_match('/^Updated to version (\S+?)\.?$/m', $raw, $m);
            json_out(['ok' => $ok, 'raw' => trim($raw),
                      'message' => $ok ? "Updated to v{$m[1]}." : 'Update failed — see the output.',
                      'state' => $ok ? dashboard_state() : null]);
    }

    status(404);
    json_out(['ok' => false, 'error' => 'Unknown action.']);
}

// ---------------------------------------------------------------------------
// The page: chrome plus one snapshot of the state, so the first paint has
// real data in it and the JS renders from a single code path.
// ---------------------------------------------------------------------------
sg_ensure_spool();
$initialState = dashboard_state();
$initialLog   = log_tail();
$SKY_STYLES   = sky_styles();
$JSON_FLAGS   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

ob_start();
?>
<style>
.sky .mast .logo { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center;
  background:linear-gradient(135deg, #2563eb, #0ea5e9); color:#fff; box-shadow:0 6px 16px -6px rgba(37,99,235,.6); }
.sky .mast .logo svg { width:22px; height:22px; }
.sky .due { font-size:11.5px; margin-top:3px; color:var(--ink-3); white-space:nowrap; }
.sky .due.bad { color:var(--bad); font-weight:600; }
.sky .due.warn { color:var(--warn); }
.sky .kchip { display:inline-block; font-size:11px; font-weight:600; padding:1px 7px; border-radius:6px; margin-right:4px;
  background:var(--line-soft); color:var(--ink-2); border:1px solid var(--line); }
.sky .kchip.archive { background:var(--warn-soft); color:var(--warn); border-color:var(--warn-line); }
.sky .kchip.large { background:var(--accent-soft); color:var(--accent-ink); border-color:var(--accent-line); }
.sky .hist { list-style:none; margin:0; padding:0; font-size:12.5px; }
.sky .hist li, .sky-modal .hist li { display:flex; gap:10px; padding:6px 0; border-bottom:1px solid var(--line-soft); }
.sky-modal .hist { list-style:none; margin:0; padding:0; font-size:12.5px; }
.sky-modal .hist .when { color:var(--ink-3); white-space:nowrap; min-width:110px; font-family:var(--mono); font-size:11.5px; }
.sky-modal h4 { margin:18px 0 6px; font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-3); }
.sky-modal .acts { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
.sky-modal .sel-note { font-size:12.5px; color:var(--ink-2); flex:1 1 auto; align-self:center; }
.sky td.check, .sky th.check { width:34px; padding-right:0; }
</style>
<div class="wrap">

  <div class="mast">
    <div class="logo" data-icon="pie"></div>
    <div class="titles">
      <h1>Storage Guard</h1>
      <div class="sub">
        Large and archive files across every cPanel account on this server
        <span class="vpill" id="sky-version">v<?= htmlspecialchars($initialState['version']) ?></span>
      </div>
    </div>
    <div class="spacer"></div>
    <div class="tools">
      <button class="btn btn-icon" id="sky-theme" title="Switch between light and dark" aria-label="Switch theme"></button>
      <button class="btn" id="sky-refresh" data-icon="refresh">Refresh</button>
      <button class="btn" id="sky-update-check" data-icon="download">Check for Updates</button>
    </div>
  </div>

  <div id="sky-banner"></div>
  <div class="tiles" id="sky-tiles"></div>

  <div class="tabs" role="tablist" id="sky-tabs">
    <button class="tab" role="tab" data-tab="overview" data-icon="grid" aria-selected="true">Overview</button>
    <button class="tab" role="tab" data-tab="accounts" data-icon="users" aria-selected="false">Accounts <span class="count" id="c-accounts">0</span></button>
    <button class="tab" role="tab" data-tab="files" data-icon="file" aria-selected="false">Files <span class="count" id="c-files">0</span></button>
    <button class="tab" role="tab" data-tab="quarantine" data-icon="archive" aria-selected="false">Quarantine <span class="count" id="c-quarantine">0</span></button>
    <button class="tab" role="tab" data-tab="settings" data-icon="sliders" aria-selected="false">Settings</button>
    <button class="tab" role="tab" data-tab="logs" data-icon="terminal" aria-selected="false">Activity Log</button>
  </div>

  <div class="panel" id="p-overview"></div>
  <div class="panel" id="p-accounts" hidden></div>
  <div class="panel" id="p-files" hidden></div>
  <div class="panel" id="p-quarantine" hidden></div>
  <div class="panel" id="p-settings" hidden></div>
  <div class="panel" id="p-logs" hidden></div>

  <noscript>
    <div class="note note-warn" style="margin-top:16px">
      This dashboard needs JavaScript. WHM itself requires it too, so enabling it for this
      browser will bring both back.
    </div>
  </noscript>
</div>

<div class="sky-toasts" id="sky-toasts"></div>

<?= sky_runtime_js() ?>
<script>
(function () {
  "use strict";

  var STATE = <?= json_encode($initialState, $JSON_FLAGS) ?>;
  var LOG   = <?= json_encode($initialLog, $JSON_FLAGS) ?>;

  var UI = window.SkyUI;
  var svg = UI.svg, esc = UI.esc, bytes = UI.bytes, ago = UI.ago, initials = UI.initials,
      toast = UI.toast, modal = UI.modal, withBusy = UI.withBusy, paintIcons = UI.paintIcons,
      emptyState = UI.emptyState;
  var $ = function (id) { return document.getElementById(id); };

  var pollTimer = null;
  var acctFilter = '', acctView = 'all';
  var fileFilter = '', fileKind = 'all';
  var selFiles = {};          // "user|id" → true, on the Files tab
  var updateInfo = null;

  function api(action, data) {
    return UI.api('index.cgi?api=' + encodeURIComponent(action), data);
  }

  // ------------------------------------------------------------ helpers
  var STATUS = {
    clean:     ['pill-ok',   'clean'],
    flagged:   ['pill-warn', 'not told yet'],
    notified:  ['pill-info', 'notified'],
    overdue:   ['pill-bad',  'overdue'],
    suspended: ['pill-bad',  'suspended'],
    unscanned: ['pill-none', 'not scanned'],
    error:     ['pill-bad',  'scan error']
  };
  var PRIORITY = { overdue: 0, flagged: 1, notified: 2, suspended: 3, error: 4, unscanned: 5, clean: 6 };

  function statusPill(st) {
    var s = STATUS[st] || ['pill-none', st];
    return '<span class="pill ' + s[0] + '">' + esc(s[1]) + '</span>';
  }

  function daysUntil(iso) {
    if (!iso) return null;
    var t = Date.parse(iso);
    return isNaN(t) ? null : Math.ceil((t - Date.now()) / 86400000);
  }

  function dueLine(r) {
    if (r.status === 'suspended') {
      return '<div class="due">' + esc(r.by_guard ? 'by Storage Guard' : (r.suspendreason || 'suspended in WHM')) + '</div>';
    }
    if (!r.deadline || !r.flagged_count) return '';
    var d = daysUntil(r.deadline);
    var txt = d < 0 ? Math.abs(d) + 'd past deadline' : d === 0 ? 'due today' : 'due in ' + d + 'd';
    return '<div class="due ' + (d < 0 ? 'bad' : d <= 2 ? 'warn' : '') + '">' + txt + ' · ' +
           r.reminders + ' reminder' + (r.reminders === 1 ? '' : 's') +
           (r.last_email_ok === false ? ' · <span style="color:var(--bad)">email failed</span>' : '') + '</div>';
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function fmtWhen(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  }

  function pathHtml(p) {
    var i = p.lastIndexOf('/');
    return '<span class="path">' + (i >= 0 ? '<span class="dir">' + esc(p.slice(0, i + 1)) + '</span>' : '') +
           esc(p.slice(i + 1)) + '</span>';
  }
  function kindChip(k) { return '<span class="kchip ' + esc(k) + '">' + (k === 'archive' ? 'archive' : 'large') + '</span>'; }

  function diskCell(r) {
    if (!r.disk_used && !r.disk_limit) return '<span class="dim">—</span>';
    var pct = r.disk_limit ? Math.min(100, Math.round(r.disk_used / r.disk_limit * 100)) : 0;
    return '<div class="mono nowrap" style="font-size:12.5px">' + bytes(r.disk_used) +
           (r.disk_limit ? '<span class="dim"> / ' + bytes(r.disk_limit) + '</span>' : '<span class="dim"> / ∞</span>') + '</div>' +
           (r.disk_limit ? '<span class="meter"><i style="width:' + pct + '%;background:' +
             (pct >= 90 ? 'var(--bad)' : pct >= 75 ? 'var(--warn)' : 'var(--accent)') + '"></i></span>' : '');
  }

  function rowFor(user) {
    return STATE.accounts.filter(function (r) { return r.user === user; })[0] || null;
  }
  function isScanning(user) { return STATE.scanning_users.indexOf(user) !== -1; }

  // ------------------------------------------------------------ renders
  function tile(cls, icon, key, value, note) {
    return '<div class="tile ' + cls + '"><div class="ico">' + svg(icon) + '</div>' +
           '<div><div class="k">' + esc(key) + '</div><div class="v">' + value + '</div>' +
           (note ? '<div class="meta">' + note + '</div>' : '') + '</div></div>';
  }

  function renderTiles() {
    var t = STATE.totals;
    var small = function (s) { return '<span class="dim" style="font-size:15px;font-weight:500">' + s + '</span>'; };
    $('sky-tiles').innerHTML =
      tile(t.scanned < t.accounts ? 'warn' : 'ok', 'search', 'Accounts scanned',
           t.scanned + small(' / ' + t.accounts),
           STATE.scan && STATE.scan.finished_at ? 'last full scan ' + esc(ago(STATE.scan.finished_at)) : 'no full scan yet') +
      tile(t.flagged ? 'warn' : 'ok', 'alert', 'Accounts flagged', String(t.flagged),
           t.flagged_files + ' file' + (t.flagged_files === 1 ? '' : 's') + ' over the limits') +
      tile('', 'drive', 'Reclaimable', bytes(t.flagged_bytes) === '—' ? '0' : bytes(t.flagged_bytes),
           'if every flagged file went') +
      tile(t.overdue ? 'bad' : '', 'clock', 'Past deadline', String(t.overdue),
           t.notified + ' more notified, ' + t.awaiting + ' not told yet') +
      tile(t.suspended ? 'bad' : '', 'ban', 'Suspended', String(t.suspended), 'by Storage Guard');

    $('c-accounts').textContent = t.accounts;
    $('c-files').textContent = t.flagged_files;
    $('c-quarantine').textContent = STATE.quarantine.length;
  }

  function renderBanner() {
    var out = '', s = STATE.scan;
    if (STATE.scanning && s) {
      var pct = s.total ? Math.round(s.done / s.total * 100) : 0;
      out += '<div class="note note-ok" style="margin-bottom:14px">' + svg('zap') +
             '<div style="flex:1 1 auto"><b>Scanning.</b> <b>' + s.done + ' of ' + s.total + '</b> accounts done' +
             (s.current ? ', now on <b>' + esc(s.current) + '</b>' : '') + ' — this page is following it.' +
             '<div class="bar"><i style="width:' + pct + '%"></i></div></div></div>';
    } else if (s && s.status === 'interrupted') {
      out += '<div class="note note-warn" style="margin-bottom:14px">' + svg('alert') +
             '<div style="flex:1 1 auto"><b>The last scan did not finish</b> — it stopped after ' + s.done + ' of ' + s.total +
             ' accounts' + (s.current ? ', on <b>' + esc(s.current) + '</b>' : '') + '. Accounts it did not reach show their older results.</div>' +
             '<button class="btn btn-primary btn-sm" data-act="scan-all" style="margin-left:auto">Scan again</button></div>';
    }
    if (STATE.accounts_error) {
      out += '<div class="note note-bad" style="margin-bottom:14px">' + svg('alert') +
             '<div><b>WHM did not return the account list.</b> ' + esc(STATE.accounts_error) + '</div></div>';
    } else if (!STATE.scanning && STATE.totals.scanned === 0 && STATE.totals.accounts) {
      out += '<div class="note" style="margin-bottom:14px">' + svg('info') +
             '<div style="flex:1 1 auto"><b>No account has been scanned yet.</b> The nightly scan runs at 03:30, or start one now. ' +
             'Scanning only reads — nothing is sent to customers and nothing is deleted until you choose to.</div>' +
             '<button class="btn btn-primary btn-sm" data-act="scan-all" style="margin-left:auto">Scan all accounts</button></div>';
    }
    if (!STATE.mail_ready) {
      out += '<div class="note note-warn" style="margin-bottom:14px">' + svg('mail') +
             '<div><b>No sendmail on this server.</b> Reminders will appear on the customer\'s cPanel page, but no email can be sent.</div></div>';
    }
    if (updateInfo && updateInfo.available) {
      out += '<div class="note note-ok" style="margin-bottom:14px">' + svg('download') +
             '<div><b>Version ' + esc(updateInfo.remote) + ' is available.</b> This server runs ' + esc(updateInfo.local) +
             '. Settings, reports and quarantined files are not touched.</div>' +
             '<button class="btn btn-primary btn-sm" id="sky-update-apply" style="margin-left:auto">Install update</button></div>';
    }
    $('sky-banner').innerHTML = out;
  }

  function attentionRows() {
    return STATE.accounts.filter(function (r) {
      return r.flagged_count > 0 || r.status === 'error' || (r.status === 'suspended' && r.by_guard);
    }).sort(function (a, b) {
      return (PRIORITY[a.status] - PRIORITY[b.status]) || (b.flagged_bytes - a.flagged_bytes);
    });
  }

  function actionButtons(r, compact) {
    var b = '<button class="btn btn-sm" data-act="open" data-user="' + esc(r.user) + '" data-icon="folder">Files</button> ';
    if (r.flagged_count && r.status !== 'suspended') {
      b += '<button class="btn btn-sm' + (r.status === 'flagged' ? ' btn-primary' : '') + '" data-act="remind" data-user="' +
           esc(r.user) + '" data-icon="mail">' + (r.reminders ? 'Remind again' : 'Remind') + '</button> ';
    }
    if (r.suspended) {
      b += '<button class="btn btn-sm" data-act="unsuspend" data-user="' + esc(r.user) + '" data-icon="unlock">Unsuspend</button> ';
    } else if (r.flagged_count) {
      b += '<button class="btn btn-sm' + (r.status === 'overdue' ? ' btn-danger' : '') + '" data-act="suspend" data-user="' +
           esc(r.user) + '" data-icon="ban">Suspend</button> ';
    }
    if (!compact) {
      b += '<button class="btn btn-sm btn-icon" data-act="scan-user" data-user="' + esc(r.user) + '" title="Scan this account again"' +
           (isScanning(r.user) ? ' disabled' : '') + ' data-icon="refresh"></button>';
    }
    return b;
  }

  function renderOverview() {
    var s = STATE.scan || {}, c = STATE.config;
    var att = attentionRows().slice(0, 8);
    var waiting = STATE.accounts.filter(function (r) { return r.status === 'flagged'; }).length;
    var on = function (k) { return c[k] === '1'; };

    $('p-overview').innerHTML =
      '<div class="card">' +
        '<header><div class="grow"><h2>Scans</h2>' +
          '<div class="hint">Every account, nightly at 03:30 by cron — or on demand from here. A scan only reads.</div></div>' +
          (STATE.scanning ? '<span class="pill pill-info pill-live">scanning now</span>'
            : '<button class="btn btn-primary" data-act="scan-all" data-icon="search">Scan all accounts</button>') +
        '</header>' +
        '<div class="body"><dl class="kv">' +
          '<dt>Last started</dt><dd>' + esc(s.started_at ? fmtWhen(s.started_at) + ' (' + ago(s.started_at) + ')' : 'never') + '</dd>' +
          '<dt>Finished</dt><dd>' + (s.status === 'finished' ? esc(fmtWhen(s.finished_at))
              : s.status === 'running' ? 'still running — ' + s.done + ' of ' + s.total
              : s.status ? '<span class="pill pill-warn">' + esc(s.status) + '</span>' : '—') + '</dd>' +
          (s.total ? '<dt>Result</dt><dd><span class="pill ' + (s.flagged ? 'pill-warn' : 'pill-ok') + '">' +
            s.flagged + ' flagged</span> <span class="dim">of ' + s.total + ' accounts</span>' +
            (s.errors ? ' <span class="pill pill-bad">' + s.errors + ' error' + (s.errors === 1 ? '' : 's') + '</span>' : '') +
            '</dd>' : '') +
          ((s.reminded && s.reminded.length) || (s.suspended && s.suspended.length)
            ? '<dt>Automatic</dt><dd>' +
              (s.reminded && s.reminded.length ? 'reminded ' + esc(s.reminded.join(', ')) : '') +
              (s.suspended && s.suspended.length ? (s.reminded && s.reminded.length ? ' · ' : '') + 'suspended ' + esc(s.suspended.join(', ')) : '') +
              '</dd>' : '') +
        '</dl></div>' +
      '</div>' +

      '<div class="card">' +
        '<header><div class="grow"><h2>Needs attention</h2>' +
          '<div class="hint">Overdue first, then accounts not told yet, then the rest — biggest first.</div></div>' +
          (waiting ? '<button class="btn btn-sm btn-primary" data-act="remind-all" data-icon="mail">Notify all not told yet (' + waiting + ')</button>' : '') +
          '<button class="btn btn-sm" data-goto="accounts">All accounts</button>' +
        '</header>' +
        (att.length ? '<div class="tbl-scroll"><table><thead><tr><th>Account</th><th>Flagged</th><th>Status</th>' +
            '<th class="right">Actions</th></tr></thead><tbody>' +
            att.map(function (r) {
              return '<tr><td><div class="who"><span class="avatar">' + esc(initials(r.user)) + '</span><div><b>' + esc(r.user) +
                     '</b><div class="dim" style="font-size:12px">' + esc(r.domain) + '</div></div></div></td>' +
                     '<td class="nowrap"><b class="mono">' + bytes(r.flagged_bytes) + '</b> <span class="dim">· ' + r.flagged_count +
                     ' file' + (r.flagged_count === 1 ? '' : 's') + '</span></td>' +
                     '<td>' + statusPill(r.status) + dueLine(r) + '</td>' +
                     '<td class="right nowrap">' + actionButtons(r, true) + '</td></tr>';
            }).join('') + '</tbody></table></div>'
          : emptyState('checkc', STATE.totals.scanned ? 'Nothing needs attention' : 'Nothing scanned yet',
              STATE.totals.scanned ? 'No account has files over the limits.' : 'Run a scan to see which accounts are storing large files.')) +
      '</div>' +

      '<div class="card">' +
        '<header><div class="grow"><h2>Policy</h2></div><button class="btn btn-sm" data-goto="settings">Change</button></header>' +
        '<div class="body"><dl class="kv">' +
          '<dt>Flagged</dt><dd>archives over <b>' + esc(c.ARCHIVE_MIN_MB) + ' MB</b> (' + esc(c.ARCHIVE_EXTENSIONS.split(' ').slice(0, 8).join(', ')) +
            '…), any file over <b>' + esc(c.LARGE_FILE_MB) + ' MB</b></dd>' +
          '<dt>Grace period</dt><dd>' + esc(c.GRACE_DAYS) + ' days from the first reminder</dd>' +
          '<dt>Reminders</dt><dd>' + (on('AUTO_REMIND') ? '<span class="pill pill-info">automatic</span> every ' + esc(c.REMIND_EVERY_DAYS) + ' days'
              : '<span class="pill pill-none">manual</span> — sent only when you press Remind') + '</dd>' +
          '<dt>Overdue accounts</dt><dd>' + (on('AUTO_SUSPEND') ? '<span class="pill pill-bad">suspended automatically</span>'
              : '<span class="pill pill-none">left for you to decide</span>') + '</dd>' +
          '<dt>Quarantine</dt><dd>kept ' + esc(c.QUARANTINE_DAYS) + ' days, then deleted for good</dd>' +
          (STATE.ignored ? '<dt>Allowed to stay</dt><dd>' + STATE.ignored + ' file' + (STATE.ignored === 1 ? '' : 's') + '</dd>' : '') +
        '</dl></div>' +
      '</div>';
  }

  function renderAccounts() {
    var views = [
      ['all', 'All'], ['action', 'Needs action'], ['overdue', 'Overdue'], ['suspended', 'Suspended'], ['clean', 'Clean']
    ];
    var count = function (v) { return STATE.accounts.filter(function (r) { return inView(r, v); }).length; };
    function inView(r, v) {
      if (v === 'action')    return r.status === 'flagged' || r.status === 'overdue' || r.status === 'notified';
      if (v === 'overdue')   return r.status === 'overdue';
      if (v === 'suspended') return r.status === 'suspended';
      if (v === 'clean')     return r.status === 'clean';
      return true;
    }

    var rows = STATE.accounts.filter(function (r) {
      if (!inView(r, acctView)) return false;
      if (!acctFilter) return true;
      return (r.user + ' ' + r.domain + ' ' + r.email + ' ' + r.owner).toLowerCase().indexOf(acctFilter) !== -1;
    }).sort(function (a, b) {
      return (b.flagged_bytes - a.flagged_bytes) || (PRIORITY[a.status] - PRIORITY[b.status]) || a.user.localeCompare(b.user);
    });

    var body = rows.length ? '<div class="tbl-scroll"><table><thead><tr>' +
        '<th>Account</th><th>Disk</th><th>Flagged</th><th>Status</th><th>Last scan</th><th class="right">Actions</th>' +
      '</tr></thead><tbody>' +
      rows.map(function (r) {
        return '<tr>' +
          '<td><div class="who"><span class="avatar">' + esc(initials(r.user)) + '</span><div><b>' + esc(r.user) + '</b>' +
            '<div class="dim" style="font-size:12px">' + esc(r.domain) + (r.owner && r.owner !== 'root' ? ' · ' + esc(r.owner) : '') + '</div></div></div></td>' +
          '<td>' + diskCell(r) + '</td>' +
          '<td class="nowrap">' + (r.flagged_count
              ? '<b class="mono">' + bytes(r.flagged_bytes) + '</b><div style="margin-top:3px">' +
                (r.archive_count ? '<span class="kchip archive">' + r.archive_count + ' archive</span>' : '') +
                (r.large_count ? '<span class="kchip large">' + r.large_count + ' large</span>' : '') + '</div>'
              : '<span class="dim">—</span>') + '</td>' +
          '<td>' + (isScanning(r.user) ? '<span class="pill pill-info pill-live">scanning</span>' : statusPill(r.status)) + dueLine(r) +
            (r.scan_error ? '<div class="due bad" style="white-space:normal;max-width:260px">' + esc(r.scan_error) + '</div>' : '') + '</td>' +
          '<td class="nowrap dim">' + esc(r.scanned_at ? ago(r.scanned_at) : 'never') + '</td>' +
          '<td class="right nowrap">' + actionButtons(r, false) + '</td>' +
        '</tr>';
      }).join('') + '</tbody></table></div>'
      : emptyState('users', acctFilter ? 'No account matches "' + acctFilter + '"' : 'No accounts in this view',
          acctFilter ? 'Clear the search to see them all.' : 'Pick another filter above.');

    $('p-accounts').innerHTML =
      '<div class="card">' +
        '<header><div class="grow"><h2>Accounts</h2>' +
          '<div class="hint">Every cPanel account, biggest offenders first.</div></div>' +
          '<div class="chips" id="sky-acct-views">' + views.map(function (v) {
            return '<button class="fchip" data-view="' + v[0] + '" aria-pressed="' + (acctView === v[0]) + '">' +
                   v[1] + ' <span class="dim">' + count(v[0]) + '</span></button>';
          }).join('') + '</div>' +
          '<div class="field search" style="margin:0">' + svg('search') +
            '<input type="search" id="sky-acct-search" placeholder="Account, domain, email…" value="' + esc(acctFilter) + '">' +
          '</div>' +
        '</header>' + body +
      '</div>';

    $('sky-acct-views').addEventListener('click', function (e) {
      var b = e.target.closest('[data-view]');
      if (b) { acctView = b.dataset.view; renderAccounts(); paintIcons($('p-accounts')); }
    });
    var box = $('sky-acct-search');
    box.addEventListener('input', function () {
      acctFilter = box.value.trim().toLowerCase();
      renderAccounts();
      paintIcons($('p-accounts'));
      var again = $('sky-acct-search');
      again.focus();
      again.setSelectionRange(again.value.length, again.value.length);
    });
  }

  function allFiles() {
    var out = [];
    STATE.accounts.forEach(function (r) {
      (r.top || []).forEach(function (f) {
        out.push({ user: r.user, status: r.status, id: f.id, path: f.path, size: f.size, mtime: f.mtime, kind: f.kind });
      });
    });
    return out.sort(function (a, b) { return b.size - a.size; });
  }

  function renderFiles() {
    var files = allFiles().filter(function (f) {
      if (fileKind !== 'all' && f.kind !== fileKind) return false;
      return !fileFilter || (f.user + ' ' + f.path).toLowerCase().indexOf(fileFilter) !== -1;
    });
    var shown = files.slice(0, 400);
    var selKeys = Object.keys(selFiles);
    var selBytes = 0;
    allFiles().forEach(function (f) { if (selFiles[f.user + '|' + f.id]) selBytes += f.size; });

    var body = shown.length ? '<div class="tbl-scroll"><table><thead><tr>' +
        '<th class="check"><input type="checkbox" id="sky-sel-all" title="Select all shown"></th>' +
        '<th>File</th><th>Account</th><th>Type</th><th class="right">Size</th><th>Modified</th>' +
      '</tr></thead><tbody>' +
      shown.map(function (f) {
        var key = f.user + '|' + f.id;
        return '<tr><td class="check"><input type="checkbox" data-sel="' + esc(key) + '"' + (selFiles[key] ? ' checked' : '') + '></td>' +
          '<td>' + pathHtml(f.path) + '</td>' +
          '<td class="nowrap"><a href="#" data-act="open" data-user="' + esc(f.user) + '">' + esc(f.user) + '</a></td>' +
          '<td>' + kindChip(f.kind) + '</td>' +
          '<td class="right nowrap mono"><b>' + bytes(f.size) + '</b></td>' +
          '<td class="nowrap dim">' + esc(fmtDate(new Date(f.mtime * 1000).toISOString())) + '</td></tr>';
      }).join('') + '</tbody></table></div>' +
      (files.length > shown.length ? '<div class="body dim" style="font-size:12.5px">Showing the largest ' + shown.length + ' of ' + files.length +
        '. Narrow it with the search, or open an account for its full list.</div>' : '')
      : emptyState('file', STATE.totals.scanned ? 'No flagged files' : 'Nothing scanned yet',
          fileFilter ? 'Nothing matches the search.' : 'Files over the limits will be listed here, largest first.');

    $('p-files').innerHTML =
      '<div class="card">' +
        '<header><div class="grow"><h2>Flagged files</h2>' +
          '<div class="hint">The largest files over the limits, across every account. Paths are relative to each home directory.</div></div>' +
          '<div class="chips" id="sky-kind">' + [['all', 'All'], ['archive', 'Archives & backups'], ['large', 'Other large files']].map(function (k) {
            return '<button class="fchip" data-kind="' + k[0] + '" aria-pressed="' + (fileKind === k[0]) + '">' + k[1] + '</button>';
          }).join('') + '</div>' +
          '<div class="field search" style="margin:0">' + svg('search') +
            '<input type="search" id="sky-file-search" placeholder="Path or account…" value="' + esc(fileFilter) + '">' +
          '</div>' +
        '</header>' + body +
        (selKeys.length ? '<div class="selbar"><div class="grow"><b>' + selKeys.length + '</b> selected · <b>' + bytes(selBytes) + '</b></div>' +
          '<button class="btn btn-sm" data-act="bulk" data-mode="ignore" data-icon="eyeoff">Allow to stay</button>' +
          '<button class="btn btn-sm" data-act="bulk" data-mode="quarantine" data-icon="archive">Quarantine</button>' +
          '<button class="btn btn-sm btn-danger" data-act="bulk" data-mode="delete" data-icon="trash">Delete permanently</button>' +
          '<button class="btn btn-sm" data-act="bulk-clear">Clear</button></div>' : '') +
      '</div>';

    $('sky-kind').addEventListener('click', function (e) {
      var b = e.target.closest('[data-kind]');
      if (b) { fileKind = b.dataset.kind; renderFiles(); paintIcons($('p-files')); }
    });
    var box = $('sky-file-search');
    box.addEventListener('input', function () {
      fileFilter = box.value.trim().toLowerCase();
      renderFiles();
      paintIcons($('p-files'));
      var again = $('sky-file-search');
      again.focus();
      again.setSelectionRange(again.value.length, again.value.length);
    });
    var all = $('sky-sel-all');
    if (all) {
      all.checked = shown.length > 0 && shown.every(function (f) { return selFiles[f.user + '|' + f.id]; });
      all.addEventListener('change', function () {
        shown.forEach(function (f) {
          if (all.checked) selFiles[f.user + '|' + f.id] = true; else delete selFiles[f.user + '|' + f.id];
        });
        renderFiles();
        paintIcons($('p-files'));
      });
    }
  }

  function renderQuarantine() {
    var q = STATE.quarantine;
    var total = q.reduce(function (s, b) { return s + b.bytes; }, 0);
    $('p-quarantine').innerHTML =
      '<div class="card">' +
        '<header><div class="grow"><h2>Quarantine</h2>' +
          '<div class="hint">Files moved out of an account. They no longer count against the customer\'s quota and the customer cannot see them; ' +
          'they are deleted for good after ' + esc(STATE.config.QUARANTINE_DAYS) + ' days. Disk space is freed when a batch is deleted.</div></div>' +
          (q.length ? '<span class="pill pill-none">' + bytes(total) + ' held</span>' : '') +
        '</header>' +
        (q.length ? '<div class="tbl-scroll"><table><thead><tr><th>Account</th><th>Files</th><th class="right">Size</th>' +
            '<th>Moved</th><th>Deleted on</th><th class="right">Actions</th></tr></thead><tbody>' +
            q.map(function (b) {
              return '<tr><td><div class="who"><span class="avatar">' + esc(initials(b.user)) + '</span><b>' + esc(b.user) + '</b></div></td>' +
                '<td><details class="raw" style="margin:0;border:0;padding:0"><summary>' + b.count + ' file' + (b.count === 1 ? '' : 's') + '</summary>' +
                  b.files.map(function (f) { return '<div style="margin-top:4px">' + pathHtml(f.path) + ' <span class="dim mono">' + bytes(f.size) + '</span></div>'; }).join('') +
                '</details></td>' +
                '<td class="right mono nowrap"><b>' + bytes(b.bytes) + '</b></td>' +
                '<td class="nowrap dim">' + esc(ago(b.created_at)) + ' · ' + esc(b.by) + '</td>' +
                '<td class="nowrap">' + esc(fmtDate(b.purge_after)) + '</td>' +
                '<td class="right nowrap"><button class="btn btn-sm" data-act="q-restore" data-id="' + esc(b.id) + '" data-icon="rotate">Put back</button> ' +
                  '<button class="btn btn-sm btn-danger" data-act="q-purge" data-id="' + esc(b.id) + '" data-icon="trash">Delete now</button></td></tr>';
            }).join('') + '</tbody></table></div>'
          : emptyState('archive', 'Quarantine is empty', 'Files you quarantine from an account are kept here until they expire.')) +
      '</div>';
  }

  function renderSettings() {
    var c = STATE.config;
    function f(label, name, type, help, ph) {
      return '<div class="field"><label for="f-' + name + '">' + esc(label) + '</label>' +
        '<input id="f-' + name + '" name="' + name + '" type="' + (type || 'text') + '" value="' + esc(c[name]) + '"' +
        (ph ? ' placeholder="' + esc(ph) + '"' : '') + '>' + (help ? '<div class="help">' + esc(help) + '</div>' : '') + '</div>';
    }
    function sw(name, title, text) {
      var on = c[name] === '1';
      return '<div class="switch"><div class="txt"><b>' + esc(title) + '</b><span>' + esc(text) + '</span></div>' +
        '<button type="button" class="toggle" data-toggle="' + name + '" role="switch" aria-checked="' + on + '"></button>' +
        '<input type="hidden" name="' + name + '" value="' + (on ? '1' : '0') + '"></div>';
    }

    $('p-settings').innerHTML =
      '<form id="sky-config">' +
      '<div class="card"><header><div class="grow"><h2>What gets flagged</h2>' +
        '<div class="hint">Applies from the next scan.</div></div></header>' +
        '<div class="body"><div class="grid">' +
          f('Archive / backup files over (MB)', 'ARCHIVE_MIN_MB', 'number', 'Files with one of the extensions below and at least this big.') +
          f('Any file over (MB)', 'LARGE_FILE_MB', 'number', 'Any file at all this big — videos, disk images, dumps with odd names.') +
          '<div class="field full"><label for="f-ARCHIVE_EXTENSIONS">Archive and backup extensions</label>' +
            '<input id="f-ARCHIVE_EXTENSIONS" name="ARCHIVE_EXTENSIONS" value="' + esc(c.ARCHIVE_EXTENSIONS) + '">' +
            '<div class="help">Space separated. Multi-part ones like tar.gz work.</div></div>' +
          '<div class="field full"><label for="f-EXCLUDE_PATHS">Folders never scanned</label>' +
            '<input id="f-EXCLUDE_PATHS" name="EXCLUDE_PATHS" value="' + esc(c.EXCLUDE_PATHS) + '">' +
            '<div class="help">Relative to each home directory, space separated. Mail is excluded by default — mailbox size is managed by quotas.</div></div>' +
          f('Files listed per account', 'MAX_FILES_PER_ACCOUNT', 'number', 'Largest first. Totals always count every file.') +
          f('Per-account scan limit (minutes)', 'SCAN_TIMEOUT_MIN', 'number', 'A scan of one account stops after this, so one huge home cannot stall the night.') +
        '</div></div></div>' +

      '<div class="card"><header><div class="grow"><h2>Enforcement</h2>' +
        '<div class="hint">Off by default: nothing goes to a customer and no account is suspended unless you do it, or switch it on here.</div></div></header>' +
        '<div class="body"><div class="stack">' +
          sw('AUTO_REMIND', 'Send reminders automatically', 'After each nightly scan, flagged accounts get a reminder — the first one starts the grace period.') +
          '<div class="grid">' +
            f('Grace period (days)', 'GRACE_DAYS', 'number', 'From the first reminder to the deadline.') +
            f('Repeat reminders every (days)', 'REMIND_EVERY_DAYS', 'number', 'Only used when reminders are automatic.') +
            f('Keep quarantined files (days)', 'QUARANTINE_DAYS', 'number', 'Then they are deleted for good.') +
          '</div>' +
          sw('AUTO_SUSPEND', 'Suspend overdue accounts automatically', 'An account still over the limits after its deadline is suspended by the nightly run. Its sites go offline.') +
          '<div class="grid">' +
            '<div class="field full"><label for="f-SUSPEND_REASON">Suspension reason</label>' +
              '<input id="f-SUSPEND_REASON" name="SUSPEND_REASON" value="' + esc(c.SUSPEND_REASON) + '">' +
              '<div class="help">Shown in WHM, and to the customer on the suspended page.</div></div>' +
          '</div>' +
        '</div></div></div>' +

      '<div class="card"><header><div class="grow"><h2>Notices</h2>' +
        '<div class="hint">The reminder is an email to the account\'s contact address plus a notice on its cPanel Storage Report page.</div></div>' +
        '<button type="button" class="btn btn-sm" data-act="test-email" data-icon="mail">Send test email</button></header>' +
        '<div class="body"><div class="grid">' +
          f('From address', 'NOTICE_FROM', 'email', 'Blank uses noreply@ this server\'s hostname.', 'support@example.com') +
          f('Subject', 'NOTICE_SUBJECT', 'text') +
          f('Support contact', 'SUPPORT_CONTACT', 'text', 'Shown to the customer — an email, phone or ticket URL.', 'support@example.com') +
          f('Admin summary to', 'ALERT_EMAIL', 'email', 'Gets a summary after a nightly scan with new or overdue accounts. Blank disables it.', 'ops@example.com') +
          '<div class="field full"><label for="f-NOTICE_MESSAGE">Extra message</label>' +
            '<textarea id="f-NOTICE_MESSAGE" name="NOTICE_MESSAGE" placeholder="e.g. Need more space? Ask us about our larger plans.">' + esc(c.NOTICE_MESSAGE) + '</textarea>' +
            '<div class="help">Added to every reminder email and the customer\'s page.</div></div>' +
        '</div></div></div>' +

      '<div class="row" style="justify-content:flex-end; margin-bottom:16px">' +
        '<span class="dim" style="font-size:12px" id="sky-save-hint"></span>' +
        '<button type="submit" class="btn btn-primary" data-icon="check">Save settings</button>' +
      '</div></form>';

    var form = $('sky-config');
    form.addEventListener('click', function (e) {
      var t = e.target.closest('[data-toggle]');
      if (!t) return;
      var next = t.getAttribute('aria-checked') !== 'true';
      t.setAttribute('aria-checked', String(next));
      form.querySelector('input[name="' + t.dataset.toggle + '"]').value = next ? '1' : '0';
      $('sky-save-hint').textContent = 'Unsaved changes';
    });
    form.addEventListener('input', function () { $('sky-save-hint').textContent = 'Unsaved changes'; });
    form.addEventListener('submit', onSaveConfig);
  }

  function colorLog(text) {
    return String(text).split('\n').map(function (line) {
      var cls = 'l-dim';
      if (/=====/.test(line))                 cls = 'l-hdr';
      else if (/\[OK\]/.test(line))           cls = 'l-ok';
      else if (/\[FAIL\]|\[!\]/.test(line))   cls = 'l-bad';
      else if (/\[WARN\]/.test(line))         cls = 'l-warn';
      else if (/\[\*\]/.test(line))           cls = '';
      return '<span class="' + cls + '">' + esc(line) + '</span>';
    }).join('\n');
  }

  function renderLogs() {
    $('p-logs').innerHTML =
      '<div class="card">' +
        '<header><div class="grow"><h2>Activity log</h2>' +
          '<div class="hint">Every scan, reminder, removal and suspension — the last 250 lines of /var/log/skyserver-storage-guard.log.</div></div>' +
          (STATE.scanning ? '<span class="pill pill-info pill-live">live</span> ' : '') +
          '<button class="btn btn-sm" data-act="refresh-log" data-icon="refresh">Refresh</button>' +
        '</header>' +
        '<pre class="console" id="sky-log">' + colorLog(LOG) + '</pre>' +
      '</div>';
    var pre = $('sky-log');
    pre.scrollTop = pre.scrollHeight;
  }

  function renderAll() {
    renderTiles(); renderBanner(); renderOverview(); renderAccounts(); renderFiles();
    renderQuarantine(); renderSettings(); renderLogs();
    paintIcons(document);
    syncPolling();
  }

  /** Everything but the settings form, which must not be redrawn under the admin's typing. */
  function renderDynamic() {
    renderTiles(); renderBanner(); renderOverview();
    var a = document.activeElement && document.activeElement.id;
    if (a !== 'sky-acct-search') renderAccounts();
    if (a !== 'sky-file-search') renderFiles();
    renderQuarantine();
    paintIcons(document);
    syncPolling();
  }

  // ------------------------------------------------------------ polling
  function needsPoll() { return STATE.scanning || STATE.scanning_users.length > 0; }
  function syncPolling() {
    if (needsPoll() && !pollTimer) {
      pollTimer = setInterval(function () { refreshState(true); }, 4000);
    } else if (!needsPoll() && pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function refreshState(quiet) {
    var was = STATE.scanning;
    return api('state').then(function (res) {
      if (!res.ok) throw new Error(res.error || 'The server could not report its state.');
      STATE = res.state;
      pruneSelection();
      renderDynamic();
      if (was && !STATE.scanning && STATE.scan) {
        toast('ok', 'Scan finished — ' + STATE.scan.flagged + ' of ' + STATE.scan.total + ' accounts flagged.');
      }
      if (was || STATE.scanning) refreshLog().catch(function () {});
    }).catch(function (err) {
      if (!quiet) throw err;
    });
  }

  function refreshLog() {
    return api('log').then(function (res) {
      if (!res.ok) throw new Error(res.error || 'Could not read the log.');
      LOG = res.log;
      var pre = $('sky-log');
      if (!pre) return;
      var atBottom = pre.scrollHeight - pre.scrollTop - pre.clientHeight < 40;
      pre.innerHTML = colorLog(LOG);
      if (atBottom) pre.scrollTop = pre.scrollHeight;
    });
  }

  /** Selected files that a removal or rescan made disappear stay unselected. */
  function pruneSelection() {
    var live = {};
    allFiles().forEach(function (f) { live[f.user + '|' + f.id] = true; });
    Object.keys(selFiles).forEach(function (k) { if (!live[k]) delete selFiles[k]; });
  }

  // ------------------------------------------------------------ actions
  function after(res) {
    if (!res.ok) { toast('bad', res.error || 'That did not work.'); return false; }
    toast('ok', res.message);
    return true;
  }

  function onScanAll(btn) {
    withBusy(btn, api('scan_all', { go: '1' })).then(function (res) {
      if (!after(res)) return;
      STATE.scanning = true;
      setTimeout(function () { refreshState(true); }, 1200);
      syncPolling();
    }).catch(function () {});
  }

  function onScanUser(btn, user) {
    withBusy(btn, api('scan_user', { user: user })).then(function (res) {
      if (!after(res)) return;
      STATE.scanning_users.push(user);
      renderDynamic();
    }).catch(function () {});
  }

  function onRemind(btn, user) {
    var r = rowFor(user);
    var c = STATE.config;
    modal({
      icon: 'mail',
      title: (r && r.reminders ? 'Send reminder ' + (r.reminders + 1) : 'Notify') + ' ' + user + '?',
      confirmLabel: 'Send reminder',
      body: '<p><b>' + (r ? r.flagged_count : '?') + '</b> file' + (r && r.flagged_count === 1 ? '' : 's') + ' (' +
            bytes(r ? r.flagged_bytes : 0) + ') are listed.</p><ul>' +
            '<li>' + (r && r.email ? 'Emailed to <b>' + esc(r.email) + '</b>.' : '<b>No contact email</b> on this account — the notice will only show in its cPanel.') + '</li>' +
            '<li>Shown on the account\'s cPanel → Storage Report page.</li>' +
            (r && r.deadline ? '<li>Deadline stays ' + esc(fmtDate(r.deadline)) + '.</li>'
                             : '<li>Starts the grace period: deadline in <b>' + esc(c.GRACE_DAYS) + ' days</b>.</li>') + '</ul>'
    }).then(function (yes) {
      if (!yes) return;
      withBusy(btn, api('remind', { user: user })).then(function (res) {
        if (after(res)) { refreshState(true); refreshOpenAccount(); }
      }).catch(function () {});
    });
  }

  function onRemindAll(btn) {
    var n = STATE.accounts.filter(function (r) { return r.status === 'flagged'; }).length;
    modal({
      icon: 'mail', title: 'Notify ' + n + ' account' + (n === 1 ? '' : 's') + '?', confirmLabel: 'Send ' + n + ' reminder' + (n === 1 ? '' : 's'),
      body: '<p>Every account that has flagged files and has <b>not been told yet</b> gets its first reminder, which starts its ' +
            esc(STATE.config.GRACE_DAYS) + '-day grace period.</p><ul><li>Accounts already notified are left on their own schedule.</li>' +
            '<li>Suspended accounts are skipped.</li></ul>'
    }).then(function (yes) {
      if (!yes) return;
      withBusy(btn, api('remind_all', { go: '1' })).then(function (res) {
        if (after(res) && res.state) { STATE = res.state; renderDynamic(); }
      }).catch(function () {});
    });
  }

  function onSuspend(btn, user) {
    var r = rowFor(user);
    modal({
      icon: 'ban', danger: true, title: 'Suspend ' + user + '?', confirmLabel: 'Suspend account',
      body: '<p>Every site, mailbox and FTP login on <b>' + esc(user) + '</b>' + (r && r.domain ? ' (' + esc(r.domain) + ')' : '') +
            ' stops working until it is unsuspended.</p>' +
            (r && r.flagged_count ? '<p style="margin-top:8px">' + r.flagged_count + ' flagged file' + (r.flagged_count === 1 ? '' : 's') + ', ' +
              bytes(r.flagged_bytes) + ' · ' + (r.reminders ? r.reminders + ' reminder' + (r.reminders === 1 ? '' : 's') + ' sent' : '<b>never reminded</b>') + '</p>' : '') +
            '<div class="field" style="margin-top:12px"><label for="m-reason">Reason</label>' +
            '<input id="m-reason" value="' + esc(STATE.config.SUSPEND_REASON) + '"></div>',
      collect: function (box) { return { reason: box.querySelector('#m-reason').value }; }
    }).then(function (pick) {
      if (!pick) return;
      withBusy(btn, api('suspend', { user: user, reason: pick.reason })).then(function (res) {
        if (after(res)) { refreshState(true); refreshOpenAccount(); }
      }).catch(function () {});
    });
  }

  function onUnsuspend(btn, user) {
    var r = rowFor(user);
    modal({
      icon: 'unlock', title: 'Unsuspend ' + user + '?', confirmLabel: 'Unsuspend',
      body: '<p>The account\'s sites and mail come back online.</p>' +
            (r && r.flagged_count ? '<p style="margin-top:8px">It still has <b>' + r.flagged_count + '</b> flagged file' +
              (r.flagged_count === 1 ? '' : 's') + ' (' + bytes(r.flagged_bytes) + '). Its deadline is unchanged.</p>'
              : '<p style="margin-top:8px">It has nothing flagged any more.</p>')
    }).then(function (yes) {
      if (!yes) return;
      withBusy(btn, api('unsuspend', { user: user })).then(function (res) {
        if (after(res)) { refreshState(true); refreshOpenAccount(); }
      }).catch(function () {});
    });
  }

  /**
   * Quarantine, delete or allow a set of files, possibly across several
   * accounts. Always confirmed first, with the size and the consequence in
   * the dialog — permanent deletion gets its own wording.
   */
  function removeFiles(btn, mode, byUser, total, count) {
    var users = Object.keys(byUser);
    var title = mode === 'delete' ? 'Delete ' + count + ' file' + (count === 1 ? '' : 's') + ' permanently?'
              : mode === 'quarantine' ? 'Quarantine ' + count + ' file' + (count === 1 ? '' : 's') + '?'
              : 'Allow ' + count + ' file' + (count === 1 ? '' : 's') + ' to stay?';
    var body = mode === 'delete'
      ? '<p><b>' + bytes(total) + '</b> from ' + esc(users.join(', ')) + ' will be deleted. <b>There is no undo</b> — ' +
        'unless the customer has a backup, these files are gone.</p>'
      : mode === 'quarantine'
      ? '<p><b>' + bytes(total) + '</b> moved out of ' + esc(users.join(', ')) + '.</p><ul>' +
        '<li>Stops counting against the account\'s quota straight away.</li>' +
        '<li>Can be put back from the Quarantine tab for ' + esc(STATE.config.QUARANTINE_DAYS) + ' days, then deleted for good.</li></ul>'
      : '<p>These files will not be flagged again, on any later scan. Use it for files you have agreed the customer may keep.</p>';
    return modal({
      icon: mode === 'delete' ? 'trash' : mode === 'quarantine' ? 'archive' : 'eyeoff',
      danger: mode === 'delete', title: title,
      confirmLabel: mode === 'delete' ? 'Delete permanently' : mode === 'quarantine' ? 'Quarantine' : 'Allow to stay',
      body: body
    }).then(function (yes) {
      if (!yes) return null;
      var done = 0, failed = [];
      var chain = Promise.resolve();
      users.forEach(function (u) {
        chain = chain.then(function () {
          var action = mode === 'ignore' ? 'ignore_files' : 'remove_files';
          return api(action, { user: u, mode: mode, ids: byUser[u].join(',') }).then(function (res) {
            if (res.ok) done += (res.removed !== undefined ? res.removed : byUser[u].length);
            if (!res.ok) failed.push(u + ': ' + res.error);
            else if (res.failed && res.failed.length) failed.push(u + ': ' + res.failed[0].path + ' — ' + res.failed[0].error);
          });
        });
      });
      return withBusy(btn, chain).then(function () {
        var verb = mode === 'delete' ? 'deleted' : mode === 'quarantine' ? 'quarantined' : 'allowed to stay';
        if (done) toast('ok', done + ' file' + (done === 1 ? '' : 's') + ' ' + verb + '.');
        if (failed.length) toast('bad', failed[0] + (failed.length > 1 ? ' (+' + (failed.length - 1) + ' more)' : ''));
        return refreshState(true);
      });
    });
  }

  function onBulk(btn, mode) {
    var byUser = {}, total = 0, count = 0;
    allFiles().forEach(function (f) {
      if (!selFiles[f.user + '|' + f.id]) return;
      (byUser[f.user] = byUser[f.user] || []).push(f.id);
      total += f.size;
      count++;
    });
    if (!count) return;
    removeFiles(btn, mode, byUser, total, count).then(function (r) {
      if (r !== null) { selFiles = {}; renderFiles(); paintIcons($('p-files')); }
    }).catch(function () {});
  }

  function onQuarantine(btn, id, purge) {
    var b = STATE.quarantine.filter(function (x) { return x.id === id; })[0];
    if (!b) return;
    modal({
      icon: purge ? 'trash' : 'rotate', danger: purge,
      title: purge ? 'Delete this batch for good?' : 'Put these files back?',
      confirmLabel: purge ? 'Delete permanently' : 'Put back',
      body: purge
        ? '<p>' + b.count + ' file' + (b.count === 1 ? '' : 's') + ' (' + bytes(b.bytes) + ') from <b>' + esc(b.user) + '</b> are deleted now instead of on ' +
          esc(fmtDate(b.purge_after)) + '. <b>There is no undo.</b></p>'
        : '<p>' + b.count + ' file' + (b.count === 1 ? '' : 's') + ' (' + bytes(b.bytes) + ') go back to where they were in <b>' + esc(b.user) +
          '</b>, owned by the account again.</p><ul><li>They count against its quota again and will be flagged again.</li>' +
          '<li>A file whose old path is now taken stays in quarantine — nothing of the customer\'s is overwritten.</li></ul>'
    }).then(function (yes) {
      if (!yes) return;
      withBusy(btn, api(purge ? 'quarantine_purge' : 'quarantine_restore', { id: id })).then(function (res) {
        if (after(res)) refreshState(true);
      }).catch(function () {});
    });
  }

  function onSaveConfig(e) {
    e.preventDefault();
    var form = e.target, data = {};
    new FormData(form).forEach(function (v, k) { data[k] = v; });
    withBusy(form.querySelector('button[type="submit"]'), api('save_config', data)).then(function (res) {
      if (!after(res)) return;
      STATE = res.state;
      renderAll();
      showTab('settings');
    }).catch(function () {});
  }

  function onTestEmail(btn) {
    modal({
      icon: 'mail', title: 'Send a test email', confirmLabel: 'Send',
      body: '<p>Sent the same way customer reminders are, from the address in these settings (save them first).</p>' +
            '<div class="field" style="margin-top:12px"><label for="m-to">To</label>' +
            '<input id="m-to" type="email" value="' + esc(STATE.config.ALERT_EMAIL) + '" placeholder="you@example.com"></div>',
      collect: function (box) { return box.querySelector('#m-to').value; }
    }).then(function (to) {
      if (!to) return;
      withBusy(btn, api('test_email', { to: to })).then(after).catch(function () {});
    });
  }

  // ------------------------------------------------------ account dialog
  var openAcct = null;   // { user, box, data, sel }

  function refreshOpenAccount() {
    if (!openAcct) return Promise.resolve();
    var cur = openAcct;
    return api('account', { user: cur.user }).then(function (res) {
      if (!res.ok || openAcct !== cur) return;
      cur.data = res.account;
      Object.keys(cur.sel).forEach(function (id) {
        if (!(cur.data.report && cur.data.report.files.some(function (f) { return f.id === id; }))) delete cur.sel[id];
      });
      paintAccount();
    }).catch(function () {});
  }

  function openAccount(user) {
    openAcct = { user: user, box: null, data: null, sel: {} };
    var mine = openAcct;
    modal({
      wide: true, single: true, icon: 'folder', title: user, confirmLabel: 'Close',
      body: '<div id="sky-acct-body"><div class="empty"><b>Loading…</b></div></div>',
      ready: function (box) {
        mine.box = box;
        box.addEventListener('click', onAccountClick);
        box.addEventListener('change', onAccountChange);
        refreshOpenAccount();
      }
    }).then(function () { if (openAcct === mine) openAcct = null; });
  }

  function paintAccount() {
    var o = openAcct;
    if (!o || !o.box || !o.data) return;
    var d = o.data, rep = d.report, c = d.case, a = d.acct;
    var files = rep ? rep.files : [];
    var selIds = Object.keys(o.sel);
    var selBytes = files.reduce(function (s, f) { return s + (o.sel[f.id] ? f.size : 0); }, 0);

    var html =
      '<dl class="kv" style="margin-top:4px">' +
        '<dt>Domain</dt><dd>' + esc(a.domain || '—') + '</dd>' +
        '<dt>Contact email</dt><dd>' + (a.email ? esc(a.email) : '<span style="color:var(--bad)">none — reminders show in cPanel only</span>') + '</dd>' +
        '<dt>Disk</dt><dd>' + bytes(a.disk_used) + (a.disk_limit ? ' of ' + bytes(a.disk_limit) : ' (no limit)') + '</dd>' +
        '<dt>Status</dt><dd>' + (d.scanning ? '<span class="pill pill-info pill-live">scanning</span>' : statusPill(d.status)) +
          (c.deadline && rep && rep.flagged_count ? ' <span class="dim">deadline ' + esc(fmtDate(c.deadline)) + ' · ' +
            c.reminders.length + ' reminder' + (c.reminders.length === 1 ? '' : 's') + '</span>' : '') + '</dd>' +
        '<dt>Last scan</dt><dd>' + (rep ? esc(fmtWhen(rep.scanned_at)) + ' <span class="dim">(' + rep.duration_s + 's)</span>' : 'never') +
          (rep && rep.error ? ' <span style="color:var(--bad)">' + esc(rep.error) + '</span>' : '') + '</dd>' +
      '</dl>' +
      '<div class="acts">' +
        (rep && rep.flagged_count && !a.suspended
          ? '<button class="btn btn-sm' + (d.status === 'flagged' ? ' btn-primary' : '') + '" data-m="remind" data-icon="mail">' +
            (c.reminders.length ? 'Remind again' : 'Send reminder') + '</button>' : '') +
        (a.suspended
          ? '<button class="btn btn-sm" data-m="unsuspend" data-icon="unlock">Unsuspend</button>'
          : (rep && rep.flagged_count
              ? '<button class="btn btn-sm' + (d.status === 'overdue' ? ' btn-danger' : '') + '" data-m="suspend" data-icon="ban">Suspend</button>' : '')) +
        '<button class="btn btn-sm" data-m="scan-user" data-icon="refresh"' + (d.scanning ? ' disabled' : '') + '>Scan again</button>' +
      '</div>' +

      '<h4>Flagged files' + (rep && rep.flagged_count ? ' — ' + rep.flagged_count + ', ' + bytes(rep.flagged_bytes) : '') + '</h4>' +
      (files.length
        ? '<div class="tbl-scroll" style="border:1px solid var(--line);border-radius:10px"><table><thead><tr>' +
            '<th class="check"><input type="checkbox" data-m-all="1"' + (selIds.length === files.length ? ' checked' : '') + '></th>' +
            '<th>File</th><th>Type</th><th class="right">Size</th><th>Modified</th></tr></thead><tbody>' +
            files.map(function (f) {
              return '<tr><td class="check"><input type="checkbox" data-m-sel="' + esc(f.id) + '"' + (o.sel[f.id] ? ' checked' : '') + '></td>' +
                '<td>' + pathHtml(f.path) + '</td><td>' + kindChip(f.kind) + '</td>' +
                '<td class="right mono nowrap"><b>' + bytes(f.size) + '</b></td>' +
                '<td class="nowrap dim">' + esc(fmtDate(new Date(f.mtime * 1000).toISOString())) + '</td></tr>';
            }).join('') + '</tbody></table></div>' +
          (rep.truncated ? '<p class="dim" style="font-size:12px;margin-top:6px">Only the largest ' + files.length + ' are listed. ' +
            'Remove some and scan again to see the rest.</p>' : '') +
          '<div class="acts"><span class="sel-note">' + (selIds.length ? '<b>' + selIds.length + '</b> selected · <b>' + bytes(selBytes) + '</b>'
              : 'Select files to act on them.') + '</span>' +
            '<button class="btn btn-sm" data-m="m-bulk" data-mode="ignore" data-icon="eyeoff"' + (selIds.length ? '' : ' disabled') + '>Allow to stay</button>' +
            '<button class="btn btn-sm" data-m="m-bulk" data-mode="quarantine" data-icon="archive"' + (selIds.length ? '' : ' disabled') + '>Quarantine</button>' +
            '<button class="btn btn-sm btn-danger" data-m="m-bulk" data-mode="delete" data-icon="trash"' + (selIds.length ? '' : ' disabled') + '>Delete permanently</button>' +
          '</div>'
        : '<p class="dim">' + (rep ? 'Nothing over the limits.' : 'Not scanned yet.') + '</p>') +

      (d.ignored.length ? '<h4>Allowed to stay</h4><ul class="hist">' + d.ignored.map(function (p) {
          return '<li><span style="flex:1 1 auto">' + pathHtml(p) + '</span><button class="btn btn-sm" data-m="unignore" data-path="' +
                 esc(p) + '">Flag again</button></li>';
        }).join('') + '</ul>' : '') +

      (d.quarantine.length ? '<h4>In quarantine</h4><ul class="hist">' + d.quarantine.map(function (b) {
          return '<li><span class="when">' + esc(fmtWhen(b.created_at)) + '</span><span style="flex:1 1 auto">' + b.count + ' file' +
                 (b.count === 1 ? '' : 's') + ', ' + bytes(b.bytes) + ' — deleted on ' + esc(fmtDate(b.purge_after)) + '</span></li>';
        }).join('') + '</ul>' : '') +

      (c.history.length ? '<h4>History</h4><ul class="hist">' + c.history.slice().reverse().slice(0, 25).map(function (h) {
          return '<li><span class="when">' + esc(fmtWhen(h.at)) + '</span><span><b>' + esc(h.action) + '</b> <span class="dim">by ' + esc(h.by) + '</span>' +
                 (h.detail ? ' — ' + esc(h.detail) : '') + '</span></li>';
        }).join('') + '</ul>' : '');

    var host = o.box.querySelector('#sky-acct-body');
    var scroller = o.box.querySelector('.m-body');
    var keep = scroller ? scroller.scrollTop : 0;
    host.innerHTML = html;
    paintIcons(host);
    if (scroller) scroller.scrollTop = keep;
  }

  function onAccountChange(e) {
    var o = openAcct;
    if (!o || !o.data || !o.data.report) return;
    var t = e.target;
    if (t.dataset.mAll) {
      o.sel = {};
      if (t.checked) o.data.report.files.forEach(function (f) { o.sel[f.id] = true; });
    } else if (t.dataset.mSel) {
      if (t.checked) o.sel[t.dataset.mSel] = true; else delete o.sel[t.dataset.mSel];
    } else {
      return;
    }
    paintAccount();
  }

  function onAccountClick(e) {
    var btn = e.target.closest('[data-m]');
    var o = openAcct;
    if (!btn || !o) return;
    var act = btn.dataset.m, user = o.user;
    if (act === 'remind')         onRemind(btn, user);
    else if (act === 'suspend')   onSuspend(btn, user);
    else if (act === 'unsuspend') onUnsuspend(btn, user);
    else if (act === 'scan-user') {
      withBusy(btn, api('scan_user', { user: user })).then(function (res) {
        if (!after(res)) return;
        o.data.scanning = true;
        paintAccount();
        STATE.scanning_users.push(user);
        syncPolling();
      }).catch(function () {});
    } else if (act === 'unignore') {
      withBusy(btn, api('unignore', { user: user, path: btn.dataset.path })).then(function (res) {
        if (after(res)) refreshOpenAccount();
      }).catch(function () {});
    } else if (act === 'm-bulk') {
      var ids = Object.keys(o.sel);
      var total = o.data.report.files.reduce(function (s, f) { return s + (o.sel[f.id] ? f.size : 0); }, 0);
      var by = {}; by[user] = ids;
      removeFiles(btn, btn.dataset.mode, by, total, ids.length).then(function (r) {
        if (r !== null) { o.sel = {}; refreshOpenAccount(); }
      }).catch(function () {});
    }
  }

  // ------------------------------------------------------------ updates
  function onUpdateCheck(btn) {
    withBusy(btn, api('update_check')).then(function (res) {
      if (!res.ok) { toast('bad', res.error); return; }
      updateInfo = res;
      toast(res.available ? 'info' : 'ok', res.message);
      renderBanner();
      $('sky-version').className = 'vpill' + (res.available ? ' has-update' : '');
    }).catch(function () {});
  }

  function onUpdateApply(btn) {
    modal({
      icon: 'download', title: 'Install update', confirmLabel: 'Install now',
      body: '<p>Pulls the latest module code from GitHub and redeploys it.</p><ul>' +
            '<li>Settings, reports and quarantined files are left untouched.</li><li>Takes a few seconds.</li></ul>'
    }).then(function (yes) {
      if (!yes) return;
      withBusy(btn, api('update_apply', { go: '1' })).then(function (res) {
        modal({
          icon: res.ok ? 'checkc' : 'alert', tone: res.ok ? 'good' : 'danger', single: true, confirmLabel: 'Done',
          title: res.message,
          body: '<details class="raw"><summary>Show output</summary><pre class="console">' + esc(res.raw || '') + '</pre></details>'
        });
        if (res.ok) {
          updateInfo = null;
          if (res.state) STATE = res.state;
          $('sky-version').textContent = 'v' + STATE.version;
          $('sky-version').className = 'vpill';
          renderAll();
        }
      }).catch(function () {});
    });
  }

  // ------------------------------------------------------- event wiring
  var showTab = function () {};

  document.addEventListener('click', function (e) {
    var g = e.target.closest ? e.target.closest('[data-goto]') : null;
    if (g) { e.preventDefault(); showTab(g.dataset.goto); return; }

    var btn = e.target.closest ? e.target.closest('[data-act]') : null;
    if (!btn) return;
    var act = btn.dataset.act, user = btn.dataset.user;
    if (btn.tagName === 'A') e.preventDefault();

    if (act === 'scan-all')          onScanAll(btn);
    else if (act === 'scan-user')    onScanUser(btn, user);
    else if (act === 'open')         openAccount(user);
    else if (act === 'remind')       onRemind(btn, user);
    else if (act === 'remind-all')   onRemindAll(btn);
    else if (act === 'suspend')      onSuspend(btn, user);
    else if (act === 'unsuspend')    onUnsuspend(btn, user);
    else if (act === 'bulk')         onBulk(btn, btn.dataset.mode);
    else if (act === 'bulk-clear')   { selFiles = {}; renderFiles(); paintIcons($('p-files')); }
    else if (act === 'q-restore')    onQuarantine(btn, btn.dataset.id, false);
    else if (act === 'q-purge')      onQuarantine(btn, btn.dataset.id, true);
    else if (act === 'test-email')   onTestEmail(btn);
    else if (act === 'refresh-log')  withBusy(btn, refreshLog()).catch(function () {});
  });

  $('p-files').addEventListener('change', function (e) {
    var key = e.target.dataset && e.target.dataset.sel;
    if (!key) return;
    if (e.target.checked) selFiles[key] = true; else delete selFiles[key];
    renderFiles();
    paintIcons($('p-files'));
  });

  $('sky-refresh').addEventListener('click', function () {
    withBusy(this, Promise.all([refreshState(), refreshLog()])).then(function () { toast('ok', 'Refreshed.'); }).catch(function () {});
  });
  $('sky-update-check').addEventListener('click', function () { onUpdateCheck(this); });
  $('sky-banner').addEventListener('click', function (e) {
    if (e.target.id === 'sky-update-apply') onUpdateApply(e.target);
  });

  // ---------------------------------------------------------------- init
  UI.initTheme();
  UI.hideChromeBranding();
  UI.fillWidth();
  renderAll();
  showTab = UI.tabs(['overview', 'accounts', 'files', 'quarantine', 'settings', 'logs'], function (name) {
    if (name === 'logs') { var pre = $('sky-log'); if (pre) pre.scrollTop = pre.scrollHeight; }
  });
})();
</script>
<?php
$body = ob_get_clean();

/**
 * WHM's own header and footer come from the Perl module
 * Whostmgr::HTMLInterface. Shelling out to it gives the real chrome — the
 * sidebar, the breadcrumb and the session token — rather than a copy.
 */
function whm_chrome(string $fn, array $args = []): string {
    $perl = '/usr/local/cpanel/3rdparty/bin/perl';
    if (!is_executable($perl)) {
        return '';
    }
    $cmd = escapeshellarg($perl) . ' -e ' . escapeshellarg(
        'use Whostmgr::HTMLInterface (); Whostmgr::HTMLInterface::' . $fn . '(@ARGV);'
    );
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $out = shell_exec($cmd . ' 2>/dev/null');
    return is_string($out) ? $out : '';
}

$header = whm_chrome('defheader', [SG_LOGO_TITLE, '', '/cgi/skyserver_storage_guard/index.cgi']);

if (stripos($header, '<html') !== false) {
    echo $header;
    echo $SKY_STYLES;
    echo '<div class="sky" id="sky-root" data-theme="light">' . $body . '</div>';
    echo whm_chrome('deffooter');
} else {
    echo "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . SG_LOGO_TITLE . "</title>\n";
    echo $SKY_STYLES;
    echo "<style>html,body { margin:0; padding:0; background:#f6f7f9; }</style>\n";
    echo "</head>\n<body>\n";
    echo '<div class="sky" id="sky-root" data-theme="light">' . $body . '</div>';
    echo "\n</body>\n</html>\n";
}
