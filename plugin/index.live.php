<?php
/**
 * Storage Report — the cPanel end-user page of SkyServer Storage Guard.
 *
 * Shows the account the large and archive files the last scan found, any
 * notice its host has sent about them and the deadline, and lets the
 * customer delete them right here. It runs as the account itself, so it can
 * only ever do what the customer could already do in File Manager.
 *
 * Built from the same design system as the WHM dashboard (ui/sky-ui.php),
 * which bin/deploy.sh copies next to it.
 */

require_once __DIR__ . '/liveapi.php';
require_once __DIR__ . '/notice.php';
require_once __DIR__ . '/sky-ui.php';

$user = (string) getenv('REMOTE_USER');
if (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
    http_response_code(403);
    die('Unable to determine cPanel user.');
}

// Opened before anything is printed — cPanel expects every .live.php page to
// make its LiveAPI connection, and it is what gives us cPanel's own chrome.
$cpanel = liveapi_connect();

$initialState = sgu_state($user);
$SKY_STYLES = sky_styles();

ob_start();
?>
<style>
.sky .mast .logo { width:42px; height:42px; border-radius:12px; display:flex; align-items:center; justify-content:center;
  background:linear-gradient(135deg, #2563eb, #0ea5e9); color:#fff; box-shadow:0 6px 16px -6px rgba(37,99,235,.6); }
.sky .mast .logo svg { width:22px; height:22px; }
.sky .kchip { display:inline-block; font-size:11px; font-weight:600; padding:1px 7px; border-radius:6px;
  background:var(--accent-soft); color:var(--accent-ink); border:1px solid var(--accent-line); }
.sky .kchip.archive { background:var(--warn-soft); color:var(--warn); border-color:var(--warn-line); }
.sky tr.gone td { opacity:.55; }
.sky tr.gone .path { text-decoration:line-through; }
.sky td.check, .sky th.check { width:34px; padding-right:0; }
.sky .note .msg { margin-top:6px; white-space:pre-line; }
</style>
<div class="wrap">

  <div class="mast">
    <div class="logo" data-icon="pie"></div>
    <div class="titles">
      <h1>Storage Report</h1>
      <div class="sub">Large and archive files in <strong><?= htmlspecialchars($user) ?></strong>'s hosting space</div>
    </div>
    <div class="spacer"></div>
    <div class="tools">
      <button class="btn btn-icon" id="sky-theme" title="Switch between light and dark" aria-label="Switch theme"></button>
      <button class="btn" id="sky-rescan" data-icon="refresh">Check again</button>
    </div>
  </div>

  <div id="sky-banner"></div>
  <div class="tiles" id="sky-tiles"></div>
  <div id="sky-files"></div>

  <noscript>
    <div class="note note-warn" style="margin-top:16px">
      This page needs JavaScript. cPanel itself requires it too.
    </div>
  </noscript>
</div>

<div class="sky-toasts" id="sky-toasts"></div>

<?= sky_runtime_js() ?>
<script>
(function () {
  "use strict";

  var STATE = <?= json_encode($initialState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

  var UI = window.SkyUI;
  var svg = UI.svg, esc = UI.esc, bytes = UI.bytes, ago = UI.ago, toast = UI.toast,
      modal = UI.modal, withBusy = UI.withBusy, paintIcons = UI.paintIcons, emptyState = UI.emptyState;
  var $ = function (id) { return document.getElementById(id); };

  var sel = {};
  var waitFor = null;     // generated_at of the notice we are waiting to be replaced
  var waitUntil = 0;
  var pollTimer = null;

  function api(action, data) {
    return UI.api('api.live.php?api=' + encodeURIComponent(action), data);
  }

  function fmtDate(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    return isNaN(d) ? iso : d.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
  }
  function daysUntil(iso) {
    var t = Date.parse(iso);
    return isNaN(t) ? null : Math.ceil((t - Date.now()) / 86400000);
  }
  function fmUrl(path) {
    var i = path.lastIndexOf('/');
    var dir = (STATE.home || '') + (i > 0 ? '/' + path.slice(0, i) : '');
    return '../filemanager/index.html?dirselect=homedir&dir=' + encodeURIComponent(dir);
  }

  function tile(cls, icon, key, value, note) {
    return '<div class="tile ' + cls + '"><div class="ico">' + svg(icon) + '</div>' +
           '<div><div class="k">' + esc(key) + '</div><div class="v">' + value + '</div>' +
           (note ? '<div class="meta">' + note + '</div>' : '') + '</div></div>';
  }

  function render() {
    var n = STATE.notice;
    var banner = '';

    if (!n) {
      banner = '<div class="note" style="margin-bottom:14px">' + svg('info') + '<div><b>' +
        (STATE.visible ? 'Your account has not been checked yet.' : 'Your storage report could not be read.') + '</b> ' +
        (STATE.visible ? 'The report appears here after the next scan.' : 'Please contact support if this does not clear by tomorrow.') +
        '</div></div>';
    } else if (n.status === 'suspended') {
      banner = '<div class="note note-bad" style="margin-bottom:14px">' + svg('ban') +
        '<div><b>This account is suspended.</b> Please contact support.' + (n.support ? ' ' + esc(n.support) : '') + '</div></div>';
    } else if (STATE.left === 0 && n.flagged_count > 0) {
      banner = '<div class="note note-ok" style="margin-bottom:14px">' + svg('checkc') +
        '<div><b>All listed files are gone — thank you.</b> ' + (waitFor || STATE.rescan_pending
          ? 'Your account is being checked again; this notice clears by itself in a minute or two.'
          : 'Press <b>Check again</b> to clear this notice now.') + '</div></div>';
    } else if (n.flagged_count === 0) {
      banner = '<div class="note note-ok" style="margin-bottom:14px">' + svg('checkc') +
        '<div><b>No large or archive files found.</b> Last checked ' + esc(ago(n.scanned_at)) + '.</div></div>';
    } else if (n.notified) {
      var d = daysUntil(n.deadline);
      var overdue = d !== null && d < 0;
      banner = '<div class="note ' + (overdue ? 'note-bad' : 'note-warn') + '" style="margin-bottom:14px">' + svg('alert') +
        '<div><b>Action needed: please remove these files ' + (overdue ? '— the deadline was ' : 'by ') + esc(fmtDate(n.deadline)) + '.</b> ' +
        'Hosting space is for your website and email, not for storing backups or archives. Download anything you want to keep, then delete it here. ' +
        (overdue ? 'Our team may now remove the files, or suspend the account until they are removed.'
                 : 'After the deadline our team may remove them, or suspend the account until they are removed.') +
        (n.message ? '<div class="msg">' + esc(n.message) + '</div>' : '') +
        (n.support ? '<div class="msg">Questions? ' + esc(n.support) + '</div>' : '') + '</div></div>';
    } else {
      banner = '<div class="note" style="margin-bottom:14px">' + svg('info') +
        '<div><b>These files are over this server\'s limits</b> — archives and backups over ' + esc(n.policy.archive_mb) +
        ' MB, and any file over ' + esc(n.policy.large_mb) + ' MB. Please download what you need and delete them to keep your account within policy.' +
        (n.message ? '<div class="msg">' + esc(n.message) + '</div>' : '') + '</div></div>';
    }
    $('sky-banner').innerHTML = banner;

    if (n) {
      var pct = n.disk_limit ? Math.min(100, Math.round(n.disk_used / n.disk_limit * 100)) : 0;
      var d2 = n.deadline ? daysUntil(n.deadline) : null;
      $('sky-tiles').innerHTML =
        tile(pct >= 90 ? 'bad' : pct >= 75 ? 'warn' : '', 'drive', 'Disk used', bytes(n.disk_used),
             n.disk_limit ? 'of ' + bytes(n.disk_limit) + '<div class="bar"><i style="width:' + pct + '%;background:' +
               (pct >= 90 ? 'var(--bad)' : pct >= 75 ? 'var(--warn)' : 'var(--accent)') + '"></i></div>' : 'no limit') +
        tile(STATE.left ? 'warn' : 'ok', 'file', 'Files to remove', String(STATE.left),
             n.flagged_count > (STATE.files || []).length ? 'largest ' + STATE.files.length + ' of ' + n.flagged_count + ' shown' : 'from the last check') +
        tile(STATE.left ? 'warn' : 'ok', 'pie', 'Space they use', STATE.left_bytes ? bytes(STATE.left_bytes) : '0', 'freed when deleted') +
        tile(n.notified && STATE.left ? (d2 < 0 ? 'bad' : 'warn') : '', 'clock', 'Deadline',
             n.notified && STATE.left ? '<span style="font-size:17px">' + esc(fmtDate(n.deadline)) + '</span>' : '—',
             n.notified && STATE.left ? (d2 < 0 ? Math.abs(d2) + ' days ago' : d2 === 0 ? 'today' : 'in ' + d2 + ' days') : 'no notice');
    } else {
      $('sky-tiles').innerHTML = '';
    }

    var files = STATE.files || [];
    var live = files.filter(function (f) { return !f.gone; });
    var selIds = Object.keys(sel).filter(function (id) { return live.some(function (f) { return f.id === id; }); });
    var selBytes = live.reduce(function (s, f) { return s + (sel[f.id] ? f.size : 0); }, 0);

    $('sky-files').innerHTML =
      '<div class="card"><header><div class="grow"><h2>Flagged files</h2>' +
        '<div class="hint">Paths are inside your home directory. Deleting here is permanent — download a copy first if you need one.</div></div>' +
        (n ? '<span class="dim" style="font-size:12px">checked ' + esc(ago(n.scanned_at)) + '</span>' : '') + '</header>' +
      (files.length
        ? '<div class="tbl-scroll"><table><thead><tr>' +
            '<th class="check"><input type="checkbox" id="sky-all"' + (live.length && selIds.length === live.length ? ' checked' : '') + (live.length ? '' : ' disabled') + '></th>' +
            '<th>File</th><th>Type</th><th class="right">Size</th><th>Modified</th><th class="right"></th></tr></thead><tbody>' +
            files.map(function (f) {
              var i = f.path.lastIndexOf('/');
              return '<tr class="' + (f.gone ? 'gone' : '') + '">' +
                '<td class="check">' + (f.gone ? '' : '<input type="checkbox" data-sel="' + esc(f.id) + '"' + (sel[f.id] ? ' checked' : '') + '>') + '</td>' +
                '<td><span class="path">' + (i >= 0 ? '<span class="dir">' + esc(f.path.slice(0, i + 1)) + '</span>' : '') + esc(f.path.slice(i + 1)) + '</span></td>' +
                '<td><span class="kchip ' + esc(f.kind) + '">' + (f.kind === 'archive' ? 'archive' : 'large') + '</span></td>' +
                '<td class="right mono nowrap"><b>' + bytes(f.size) + '</b></td>' +
                '<td class="nowrap dim">' + esc(fmtDate(new Date(f.mtime * 1000).toISOString())) + '</td>' +
                '<td class="right nowrap">' + (f.gone ? '<span class="pill pill-ok">deleted</span>'
                  : '<a class="btn btn-sm" href="' + esc(fmUrl(f.path)) + '" target="_blank" rel="noopener" data-icon="folder">Open folder</a> ' +
                    '<button class="btn btn-sm btn-danger" data-del="' + esc(f.id) + '" data-icon="trash">Delete</button>') + '</td></tr>';
            }).join('') + '</tbody></table></div>' +
          (selIds.length ? '<div class="selbar"><div class="grow"><b>' + selIds.length + '</b> selected · <b>' + bytes(selBytes) + '</b></div>' +
            '<button class="btn btn-sm btn-danger" id="sky-del-sel" data-icon="trash">Delete selected</button></div>' : '')
        : emptyState('checkc', 'Nothing to clean up', 'Files over the limits will be listed here.')) +
      '</div>';

    paintIcons(document);
    $('sky-rescan').disabled = !!(waitFor || STATE.rescan_pending);
  }

  // ------------------------------------------------------------ actions
  function refresh() {
    return api('state').then(function (res) {
      if (!res.ok) throw new Error(res.error);
      var before = STATE.notice && STATE.notice.generated_at;
      STATE = res.state;
      var now = STATE.notice && STATE.notice.generated_at;
      if (waitFor !== null && now && now !== waitFor) {
        waitFor = null;
        toast('ok', 'Your account was checked again.');
      } else if (waitFor !== null && Date.now() > waitUntil) {
        waitFor = null;
      }
      if (before !== now) sel = {};
      render();
      syncPoll();
    });
  }

  function syncPoll() {
    var want = waitFor !== null;
    if (want && !pollTimer) pollTimer = setInterval(function () { refresh().catch(function () {}); }, 5000);
    if (!want && pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function startWaiting() {
    waitFor = (STATE.notice && STATE.notice.generated_at) || '';
    waitUntil = Date.now() + 4 * 60 * 1000;
    syncPoll();
  }

  function del(btn, ids) {
    var list = STATE.files.filter(function (f) { return ids.indexOf(f.id) !== -1; });
    var total = list.reduce(function (s, f) { return s + f.size; }, 0);
    modal({
      icon: 'trash', danger: true,
      title: 'Delete ' + (list.length === 1 ? 'this file' : list.length + ' files') + '?',
      confirmLabel: 'Delete permanently',
      body: '<p><b>' + bytes(total) + '</b> will be deleted from your hosting space. <b>This cannot be undone.</b></p>' +
            '<ul>' + list.slice(0, 6).map(function (f) { return '<li class="path">' + esc(f.path) + '</li>'; }).join('') +
            (list.length > 6 ? '<li>… and ' + (list.length - 6) + ' more</li>' : '') + '</ul>'
    }).then(function (yes) {
      if (!yes) return;
      withBusy(btn, api('delete', { ids: ids.join(',') })).then(function (res) {
        if (res.state) STATE = res.state;
        if (!res.ok) { toast('bad', res.error || 'Could not delete.'); render(); return; }
        toast('ok', res.deleted + ' file' + (res.deleted === 1 ? '' : 's') + ' deleted — ' + bytes(res.bytes) + ' freed.' +
          (res.failed && res.failed.length ? ' ' + res.failed.length + ' could not be deleted.' : ''));
        sel = {};
        if (res.deleted) startWaiting();
        render();
      }).catch(function () {});
    });
  }

  document.addEventListener('click', function (e) {
    var d = e.target.closest && e.target.closest('[data-del]');
    if (d) { del(d, [d.dataset.del]); return; }
    if (e.target.closest && e.target.closest('#sky-del-sel')) {
      var ids = Object.keys(sel).filter(function (id) { return STATE.files.some(function (f) { return f.id === id && !f.gone; }); });
      if (ids.length) del(e.target.closest('#sky-del-sel'), ids);
    }
  });

  document.addEventListener('change', function (e) {
    var t = e.target;
    if (t.id === 'sky-all') {
      sel = {};
      if (t.checked) STATE.files.forEach(function (f) { if (!f.gone) sel[f.id] = true; });
      render();
    } else if (t.dataset && t.dataset.sel) {
      if (t.checked) sel[t.dataset.sel] = true; else delete sel[t.dataset.sel];
      render();
    }
  });

  $('sky-rescan').addEventListener('click', function () {
    var btn = this;
    withBusy(btn, api('rescan', { go: '1' })).then(function (res) {
      if (!res.ok) { toast('bad', res.error); return; }
      toast('info', res.message);
      startWaiting();
      render();
    }).catch(function () {});
  });

  UI.initTheme();
  UI.hideChromeBranding();
  UI.fillWidth();
  render();
  if (STATE.rescan_pending) startWaiting();
})();
</script>
<?php
$body = ob_get_clean();

// Inside cPanel's own chrome when LiveAPI is up; a plain page otherwise.
$header = $cpanel ? (string) $cpanel->header('Storage Report') : '';

if (stripos($header, '<html') !== false) {
    echo $header;
    echo $SKY_STYLES;
    echo '<div class="sky" id="sky-root" data-theme="light">' . $body . '</div>';
    echo (string) $cpanel->footer();
} else {
    echo "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo "<title>Storage Report</title>\n";
    echo $SKY_STYLES;
    echo "<style>html,body { margin:0; padding:0; background:#f6f7f9; }</style>\n";
    echo "</head>\n<body>\n";
    echo '<div class="sky" id="sky-root" data-theme="light">' . $body . '</div>';
    echo "\n</body>\n</html>\n";
}
liveapi_end($cpanel);
