<?php
/**
 * The design system both SkyServer panels are built from — the WHM admin
 * dashboard (whm-plugin/index.cgi) and the cPanel end-user page
 * (plugin/index.live.php). Shared with the SkyServer Backup Manager, so the
 * two modules look like one product; this copy adds the icons and the wide
 * dialog that a file list needs.
 *
 * It lives in one file so the two cannot drift apart: bin/deploy.sh copies
 * it next to each of them, and both require it from their own directory.
 *
 * Nothing here reaches the network. The icons are inline SVG and the fonts
 * are the system stack, so both panels render identically on a server with
 * no outbound access.
 */

function sky_styles(): string {
    return <<<'CSS'
<style>
.sky {
  --accent:#2563eb; --accent-soft:#eff5ff; --accent-line:#bfd6fe; --accent-ink:#1d4ed8;
  --ok:#0f8a4d; --ok-soft:#e9f8ef; --ok-line:#bfe8cf;
  --warn:#b4750d; --warn-soft:#fff6e6; --warn-line:#f2ddb0;
  --bad:#cc2f2f; --bad-soft:#fdeded; --bad-line:#f6c9c9;
  --bg:#f6f7f9; --card:#ffffff; --raised:#fbfcfd;
  --line:#e5e8ee; --line-soft:#eef0f5;
  --ink:#141a22; --ink-2:#4a5567; --ink-3:#77839a;
  --shadow:0 1px 2px rgba(17,24,39,.05), 0 8px 24px -12px rgba(17,24,39,.12);
  --shadow-lg:0 24px 60px -18px rgba(17,24,39,.32);
  --r:14px; --r-sm:9px;
  --mono:ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;

  font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  font-size:14px; line-height:1.5; color:var(--ink);
  background:var(--bg); padding:22px 26px 40px; min-height:100%;
  -webkit-font-smoothing:antialiased;
}
.sky[data-theme="dark"] {
  --accent:#5b9bff; --accent-soft:#16233a; --accent-line:#28406b; --accent-ink:#8fbaff;
  --ok:#4ec98a; --ok-soft:#12271c; --ok-line:#1f4632;
  --warn:#e0a94a; --warn-soft:#2a2113; --warn-line:#4a3a1b;
  --bad:#ff7b7b; --bad-soft:#2d1717; --bad-line:#5a2a2a;
  --bg:#0d1117; --card:#151b23; --raised:#1b222c;
  --line:#262d38; --line-soft:#1f2630;
  --ink:#e6edf5; --ink-2:#a2aebf; --ink-3:#71809a;
  --shadow:0 1px 2px rgba(0,0,0,.4), 0 8px 24px -12px rgba(0,0,0,.6);
  --shadow-lg:0 24px 60px -18px rgba(0,0,0,.75);
}
.sky *, .sky *::before, .sky *::after { box-sizing:border-box; }
.sky h1, .sky h2, .sky h3 { margin:0; font-weight:650; letter-spacing:-.011em; color:var(--ink); }
.sky h1 { font-size:20px; }
.sky h2 { font-size:14px; }
.sky p  { margin:0; }
.sky a  { color:var(--accent); }
/* Full width: the panel is the only thing on its page, so centring it in a
   narrow column just wastes the screen. SkyUI.fillWidth() clears the same
   cap on whatever container WHM or cPanel wraps us in. */
.sky .wrap { max-width:none; margin:0; }
.sky .muted { color:var(--ink-2); }
.sky .dim   { color:var(--ink-3); }
.sky .mono  { font-family:var(--mono); font-variant-numeric:tabular-nums; }
.sky .nowrap { white-space:nowrap; }

/* ---------- masthead ---------- */
.sky .mast { display:flex; align-items:center; gap:14px; margin-bottom:20px; flex-wrap:wrap; }
.sky .mast img { height:42px; width:auto; max-width:190px; display:block; }
.sky .mast .titles { min-width:0; }
.sky .mast .sub { font-size:12.5px; color:var(--ink-2); margin-top:2px; }
.sky .mast .spacer { flex:1 1 auto; }
.sky .mast .tools { display:flex; align-items:center; gap:8px; }
.sky .vpill { display:inline-flex; align-items:center; gap:5px; font-family:var(--mono); font-size:11px;
  padding:2px 8px; border-radius:99px; border:1px solid var(--line); color:var(--ink-2); background:var(--raised); }
.sky .vpill.has-update { background:var(--warn-soft); border-color:var(--warn-line); color:var(--warn); }

/* ---------- buttons ---------- */
.sky .btn {
  display:inline-flex; align-items:center; justify-content:center; gap:7px;
  border:1px solid var(--line); background:var(--card); color:var(--ink);
  border-radius:var(--r-sm); padding:8px 13px; font-size:13px; font-weight:550;
  font-family:inherit; cursor:pointer; white-space:nowrap;
  transition:background .14s, border-color .14s, transform .06s, box-shadow .14s;
}
.sky .btn:hover:not(:disabled) { background:var(--raised); border-color:var(--ink-3); }
.sky .btn:active:not(:disabled) { transform:translateY(1px); }
.sky .btn:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
.sky .btn:disabled { opacity:.5; cursor:not-allowed; }
.sky .btn svg { width:15px; height:15px; flex:none; }
.sky .btn-primary { background:var(--accent); border-color:var(--accent); color:#fff; }
.sky .btn-primary:hover:not(:disabled) { background:var(--accent-ink); border-color:var(--accent-ink); }
.sky .btn-danger { background:var(--bad); border-color:var(--bad); color:#fff; }
.sky .btn-danger:hover:not(:disabled) { filter:brightness(.93); }
.sky .btn-sm { padding:5px 10px; font-size:12px; border-radius:7px; }
.sky a.btn { text-decoration:none; color:var(--ink); }
.sky .btn-icon { padding:8px; width:34px; }
.sky .btn-icon svg { width:16px; height:16px; }

/* A button mid-request: the label is replaced by a spinner, so the row
   doesn't reflow and the admin can't fire the same action twice. */
.sky .btn.busy { color:transparent !important; position:relative; pointer-events:none; }
.sky .btn.busy svg { visibility:hidden; }
.sky .btn.busy::after {
  content:""; position:absolute; width:14px; height:14px; border-radius:50%;
  border:2px solid currentColor; border-top-color:transparent; opacity:.9;
  color:var(--ink-2); animation:sky-spin .6s linear infinite;
}
.sky .btn-primary.busy::after, .sky .btn-danger.busy::after { color:#fff; }
@keyframes sky-spin { to { transform:rotate(360deg); } }

/* ---------- stat tiles ---------- */
.sky .tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(186px, 1fr)); gap:12px; margin-bottom:18px; }
.sky .tile { background:var(--card); border:1px solid var(--line); border-radius:var(--r);
  padding:14px 16px; box-shadow:var(--shadow); display:flex; gap:12px; align-items:flex-start; }
.sky .tile .ico { width:32px; height:32px; border-radius:9px; flex:none;
  display:flex; align-items:center; justify-content:center; background:var(--accent-soft); color:var(--accent); }
.sky .tile .ico svg { width:17px; height:17px; }
.sky .tile.ok   .ico { background:var(--ok-soft);   color:var(--ok); }
.sky .tile.warn .ico { background:var(--warn-soft); color:var(--warn); }
.sky .tile.bad  .ico { background:var(--bad-soft);  color:var(--bad); }
.sky .tile .k { font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase; color:var(--ink-3); }
.sky .tile .v { font-size:23px; font-weight:660; letter-spacing:-.02em; margin-top:3px; line-height:1.15;
  font-variant-numeric:tabular-nums; }
.sky .tile .meta { font-size:11.5px; color:var(--ink-3); margin-top:2px; }

/* ---------- tabs ---------- */
.sky .tabs { display:flex; gap:3px; padding:4px; background:var(--card); border:1px solid var(--line);
  border-radius:12px; margin-bottom:16px; overflow-x:auto; box-shadow:var(--shadow); }
.sky .tab { display:inline-flex; align-items:center; gap:7px; border:0; background:none; cursor:pointer;
  font:inherit; font-size:13px; font-weight:550; color:var(--ink-2); padding:8px 13px; border-radius:8px;
  white-space:nowrap; transition:background .14s, color .14s; }
.sky .tab:hover { background:var(--raised); color:var(--ink); }
.sky .tab[aria-selected="true"] { background:var(--accent); color:#fff; }
.sky .tab svg { width:15px; height:15px; }
.sky .tab .count { font-size:11px; font-family:var(--mono); padding:1px 6px; border-radius:99px;
  background:var(--line-soft); color:var(--ink-2); }
.sky .tab[aria-selected="true"] .count { background:rgba(255,255,255,.22); color:#fff; }
.sky .panel[hidden] { display:none; }

/* ---------- cards ---------- */
.sky .card { background:var(--card); border:1px solid var(--line); border-radius:var(--r);
  box-shadow:var(--shadow); margin-bottom:16px; overflow:hidden; }
.sky .card > header { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
  padding:13px 16px; border-bottom:1px solid var(--line); background:var(--raised); }
.sky .card > header .grow { flex:1 1 auto; }
.sky .card > header .hint { font-size:12px; color:var(--ink-3); font-weight:400; }
.sky .card .body { padding:16px; }
.sky .card .body.tight { padding:0; }

/* ---------- tables ---------- */
.sky .tbl-scroll { overflow-x:auto; }
.sky table { width:100%; border-collapse:collapse; }
.sky thead th { font-size:11px; font-weight:600; letter-spacing:.04em; text-transform:uppercase;
  color:var(--ink-3); text-align:left; padding:9px 16px; background:var(--raised);
  border-bottom:1px solid var(--line); white-space:nowrap; }
.sky tbody td { padding:11px 16px; font-size:13px; border-bottom:1px solid var(--line-soft);
  background:none; vertical-align:middle; }
.sky tbody tr:last-child td { border-bottom:0; }
.sky tbody tr { transition:background .12s; }
.sky tbody tr:hover td { background:var(--raised); }
.sky td.right, .sky th.right { text-align:right; }
.sky .who { display:flex; align-items:center; gap:9px; }
.sky .avatar { width:27px; height:27px; border-radius:8px; flex:none; display:flex; align-items:center;
  justify-content:center; font-size:11px; font-weight:650; background:var(--accent-soft);
  color:var(--accent-ink); text-transform:uppercase; }

/* ---------- pills ---------- */
.sky .pill { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:600;
  padding:3px 9px; border-radius:99px; border:1px solid transparent; white-space:nowrap; }
.sky .pill::before { content:""; width:6px; height:6px; border-radius:50%; background:currentColor; flex:none; }
.sky .pill-ok      { background:var(--ok-soft);     color:var(--ok);     border-color:var(--ok-line); }
.sky .pill-bad     { background:var(--bad-soft);    color:var(--bad);    border-color:var(--bad-line); }
.sky .pill-warn    { background:var(--warn-soft);   color:var(--warn);   border-color:var(--warn-line); }
.sky .pill-info    { background:var(--accent-soft); color:var(--accent-ink); border-color:var(--accent-line); }
.sky .pill-none    { background:var(--line-soft);   color:var(--ink-3);  border-color:var(--line); }
.sky .pill-live::before { animation:sky-pulse 1.4s ease-in-out infinite; }
@keyframes sky-pulse { 0%,100% { opacity:1; transform:scale(1); } 50% { opacity:.35; transform:scale(.72); } }
.sky .chip { display:inline-block; font-family:var(--mono); font-size:11px; padding:2px 7px; border-radius:6px;
  background:var(--accent-soft); color:var(--accent-ink); border:1px solid var(--accent-line); margin:1px 3px 1px 0; }
.sky .dl-link { font-size:12.5px; font-weight:600; color:var(--accent); text-decoration:none; white-space:nowrap; }
.sky .dl-link:hover { text-decoration:underline; }

/* ---------- forms ---------- */
.sky .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(258px, 1fr)); gap:14px; }
.sky .field.full { grid-column:1 / -1; }
.sky .field label { display:block; font-size:12px; font-weight:600; color:var(--ink-2); margin-bottom:5px; }
.sky .field .help { font-size:11.5px; color:var(--ink-3); margin-top:5px; font-weight:400; }
.sky .field input, .sky .field select, .sky .field textarea {
  width:100%; padding:8px 11px; font:inherit; font-size:13px; color:var(--ink);
  background:var(--card); border:1px solid var(--line); border-radius:var(--r-sm);
  transition:border-color .14s, box-shadow .14s; }
.sky .field input:focus, .sky .field select:focus {
  outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }
.sky .field input::placeholder { color:var(--ink-3); }
.sky .switch { display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid var(--line);
  border-radius:var(--r-sm); background:var(--raised); }
.sky .switch .txt { flex:1 1 auto; }
.sky .switch .txt b { display:block; font-size:13px; font-weight:600; }
.sky .switch .txt span { font-size:11.5px; color:var(--ink-3); }
.sky .toggle { position:relative; width:42px; height:24px; flex:none; border:0; border-radius:99px;
  background:var(--line); cursor:pointer; transition:background .18s; padding:0; }
.sky .toggle::after { content:""; position:absolute; top:3px; left:3px; width:18px; height:18px;
  border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.3); transition:transform .18s; }
.sky .toggle[aria-checked="true"] { background:var(--ok); }
.sky .toggle[aria-checked="true"]::after { transform:translateX(18px); }
.sky .toggle:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
.sky .search { position:relative; }
.sky .search input { padding-left:31px; min-width:210px; }
.sky .search svg { position:absolute; left:9px; top:50%; transform:translateY(-50%);
  width:15px; height:15px; color:var(--ink-3); pointer-events:none; }

/* ---------- console ---------- */
.sky .console { margin:0; padding:14px 16px; background:#0b0f14; color:#c9d5e3;
  font-family:var(--mono); font-size:12px; line-height:1.6; max-height:430px; overflow:auto;
  white-space:pre-wrap; word-break:break-word; }
.sky .console .l-ok   { color:#5fd9a0; }
.sky .console .l-bad  { color:#ff8b8b; }
.sky .console .l-warn { color:#f0c674; }
.sky .console .l-hdr  { color:#7fb2ff; font-weight:600; }
.sky .console .l-dim  { color:#6b7a8d; }

/* ---------- empty / notices ---------- */
.sky .empty { padding:40px 20px; text-align:center; color:var(--ink-3); }
.sky .empty svg { width:30px; height:30px; margin-bottom:9px; opacity:.45; }
.sky .empty b { display:block; font-size:13.5px; color:var(--ink-2); font-weight:600; margin-bottom:3px; }
.sky .empty span { font-size:12.5px; }
.sky .note { display:flex; gap:11px; padding:12px 14px; border-radius:var(--r-sm);
  border:1px solid var(--line); background:var(--raised); font-size:12.5px; color:var(--ink-2); }
.sky .note svg { width:16px; height:16px; flex:none; margin-top:1px; }
.sky .note b { color:var(--ink); }
.sky .note-warn { background:var(--warn-soft); border-color:var(--warn-line); color:var(--warn); }
.sky .note-warn b { color:var(--warn); }
.sky .note-bad  { background:var(--bad-soft);  border-color:var(--bad-line);  color:var(--bad); }
.sky .note-bad b { color:var(--bad); }
.sky .note-ok   { background:var(--ok-soft);   border-color:var(--ok-line);   color:var(--ok); }
.sky .note-ok b { color:var(--ok); }

/* ---------- toasts ---------- */
.sky-toasts { position:fixed; right:18px; bottom:18px; z-index:2147483000;
  display:flex; flex-direction:column; gap:9px; max-width:min(380px, calc(100vw - 36px)); }
.sky-toast { display:flex; gap:10px; align-items:flex-start; padding:12px 14px; border-radius:11px;
  background:#151b23; color:#e6edf5; border:1px solid #2a3340; box-shadow:0 18px 44px -14px rgba(0,0,0,.6);
  font:550 13px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  animation:sky-in .22s cubic-bezier(.22,1,.36,1); }
.sky-toast.out { animation:sky-out .18s ease-in forwards; }
.sky-toast svg { width:16px; height:16px; flex:none; margin-top:1px; }
.sky-toast.ok   svg { color:#4ec98a; }
.sky-toast.bad  svg { color:#ff7b7b; }
.sky-toast.info svg { color:#5b9bff; }
.sky-toast .x { margin-left:auto; border:0; background:none; color:#77839a; cursor:pointer; padding:0 2px; font-size:15px; line-height:1; }
@keyframes sky-in  { from { opacity:0; transform:translateY(10px) scale(.97); } }
@keyframes sky-out { to   { opacity:0; transform:translateY(6px) scale(.98); } }

/* ---------- modal ---------- */
.sky-backdrop { position:fixed; inset:0; z-index:2147482000; background:rgba(10,14,20,.55);
  backdrop-filter:blur(3px); display:flex; align-items:center; justify-content:center; padding:20px;
  animation:sky-fade .16s ease-out; }
@keyframes sky-fade { from { opacity:0; } }
.sky-modal { width:100%; max-width:470px; background:var(--card,#fff); color:var(--ink,#141a22);
  border:1px solid var(--line,#e5e8ee); border-radius:15px; box-shadow:var(--shadow-lg);
  overflow:hidden; animation:sky-pop .2s cubic-bezier(.22,1,.36,1);
  font:14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
@keyframes sky-pop { from { opacity:0; transform:translateY(12px) scale(.97); } }
.sky-modal .m-head { display:flex; gap:12px; align-items:flex-start; padding:18px 20px 0; }
.sky-modal .m-ico { width:36px; height:36px; border-radius:10px; flex:none; display:flex;
  align-items:center; justify-content:center; background:var(--accent-soft); color:var(--accent); }
.sky-modal.danger .m-ico { background:var(--bad-soft); color:var(--bad); }
.sky-modal.good .m-ico { background:var(--ok-soft); color:var(--ok); }
.sky-modal .m-ico svg { width:19px; height:19px; }
.sky-modal h3 { font-size:15.5px; font-weight:650; }
.sky-modal .m-body { padding:10px 20px 4px; font-size:13px; color:var(--ink-2); }
.sky-modal .m-body ul { margin:10px 0 0; padding-left:18px; }
.sky-modal .m-body li { margin-bottom:4px; }
.sky-modal .m-foot { display:flex; justify-content:flex-end; gap:9px; padding:16px 20px 18px; }
/* A dialog that holds a table of files rather than a sentence. */
.sky-modal.wide { max-width:1040px; max-height:calc(100vh - 40px); display:flex; flex-direction:column; }
.sky-modal.wide .m-body { overflow:auto; flex:1 1 auto; }
.sky-modal.wide .m-body table { margin-top:10px; }

/* ---------- update result ---------- */
.sky .steps { list-style:none; margin:0; padding:0; }
.sky .steps li { display:flex; gap:9px; align-items:flex-start; padding:6px 0; font-size:13px; }
.sky .steps li.sub { padding-left:22px; font-size:12.5px; color:var(--ink-2); }
.sky .steps svg { width:15px; height:15px; flex:none; margin-top:2px; color:var(--ok); }
.sky .steps li.warn svg  { color:var(--warn); }
.sky .steps li.error svg { color:var(--bad); }
.sky details.raw { margin-top:12px; border-top:1px solid var(--line); padding-top:10px; }
.sky details.raw summary { cursor:pointer; font-size:12px; font-weight:600; color:var(--ink-2); list-style:none; }
.sky details.raw summary::-webkit-details-marker { display:none; }
.sky details.raw summary::before { content:"▸ "; color:var(--ink-3); }
.sky details.raw[open] summary::before { content:"▾ "; }
.sky details.raw .console { margin-top:9px; border-radius:var(--r-sm); max-height:280px; }

/* ---------- selection bar ---------- */
.sky .selbar { display:flex; align-items:center; gap:9px; flex-wrap:wrap; padding:10px 16px;
  border-top:1px solid var(--line); background:var(--raised); }
.sky .selbar .grow { flex:1 1 auto; font-size:12.5px; color:var(--ink-2); }
.sky input[type="checkbox"] { width:15px; height:15px; accent-color:var(--accent); cursor:pointer; vertical-align:middle; }
.sky .path { font-family:var(--mono); font-size:12px; word-break:break-all; color:var(--ink); }
.sky .path .dir { color:var(--ink-3); }
.sky .meter { width:110px; height:6px; border-radius:99px; background:var(--line-soft); overflow:hidden; display:inline-block; vertical-align:middle; }
.sky .meter i { display:block; height:100%; background:var(--accent); border-radius:99px; }
.sky .chips { display:flex; gap:6px; flex-wrap:wrap; }
.sky .fchip { border:1px solid var(--line); background:var(--card); color:var(--ink-2); font:inherit; font-size:12px;
  font-weight:550; padding:4px 10px; border-radius:99px; cursor:pointer; }
.sky .fchip[aria-pressed="true"] { background:var(--accent-soft); border-color:var(--accent-line); color:var(--accent-ink); }
.sky .field textarea { min-height:84px; resize:vertical; }
.sky .field textarea:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-soft); }

/* ---------- misc ---------- */
.sky .skel { background:linear-gradient(90deg, var(--line-soft) 25%, var(--line) 37%, var(--line-soft) 63%);
  background-size:400% 100%; animation:sky-shim 1.3s ease infinite; border-radius:6px; color:transparent; }
@keyframes sky-shim { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }
.sky .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.sky .stack { display:flex; flex-direction:column; gap:14px; }
.sky .kv { display:grid; grid-template-columns:auto 1fr; gap:6px 16px; font-size:13px; }
.sky .kv dt { color:var(--ink-3); }
.sky .kv dd { margin:0; font-weight:550; }
.sky .bar { height:6px; border-radius:99px; background:var(--line-soft); overflow:hidden; margin-top:8px; }
.sky .bar i { display:block; height:100%; border-radius:99px; background:var(--ok); transition:width .4s ease; }

/* A job that is actually moving. The stripes carry on even when the
   percentage is unknown, so "working" never looks like "stuck". */
.sky .prog { min-width:230px; text-align:left; }
.sky .prog .top { display:flex; align-items:baseline; gap:8px; }
.sky .prog .what { font-size:12.5px; font-weight:600; color:var(--ink); }
.sky .prog .pc { margin-left:auto; font-family:var(--mono); font-size:11.5px; color:var(--ink-2); }
.sky .prog .sub { font-size:11.5px; color:var(--ink-3); margin-top:2px; }
.sky .prog .bar { margin-top:6px; background:var(--line-soft); }
.sky .prog .bar i { background:var(--accent); }
.sky .prog .bar.indet i { width:35%; background:linear-gradient(90deg,
    transparent, var(--accent) 35%, var(--accent) 65%, transparent);
  animation:sky-slide 1.25s ease-in-out infinite; }
@keyframes sky-slide { 0% { transform:translateX(-100%); } 100% { transform:translateX(300%); } }
.sky .prog .steps-of { font-family:var(--mono); font-size:11px; color:var(--ink-3); }
@media (max-width:640px) {
  .sky { padding:16px 14px 32px; }
  .sky .mast .tools { width:100%; }
}
@media (prefers-reduced-motion:reduce) {
  .sky *, .sky-toast, .sky-modal { animation:none !important; transition:none !important; }
}
</style>
CSS;
}

/**
 * The shared runtime, published as window.SkyUI: escaping, icons, toasts,
 * the modal, the JSON helper, the theme switch and the tab strip. Each
 * panel's own script picks it up and only implements what is specific to it.
 */
function sky_runtime_js(): string {
    return <<<'JS'
<script>
window.SkyUI = (function () {
  "use strict";

  // Drawn inline rather than pulled from an icon font or a CDN: both panels
  // have to render identically on a server with no outbound access.
  var PATHS = {
    grid:    '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    users:   '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    rotate:  '<path d="M3 12a9 9 0 1 0 2.64-6.36L3 8"/><path d="M3 3v5h5"/>',
    sliders: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
    terminal:'<path d="M4 17l6-5-6-5"/><path d="M12 19h8"/>',
    refresh: '<path d="M21 12a9 9 0 1 1-2.64-6.36L21 8"/><path d="M21 3v5h-5"/>',
    download:'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
    play:    '<path d="M6 4l14 8-14 8z"/>',
    check:   '<path d="M20 6L9 17l-5-5"/>',
    checkc:  '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
    alert:   '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    info:    '<circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/>',
    x:       '<path d="M18 6L6 18M6 6l12 12"/>',
    search:  '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
    sun:     '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon:    '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
    db:      '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
    drive:   '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 12h20"/><path d="M6 16h.01M10 16h.01"/>',
    clock:   '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
    shield:  '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/>',
    cloud:   '<path d="M17.5 19a4.5 4.5 0 0 0 .5-8.97A6 6 0 0 0 6.1 11 4 4 0 0 0 6.5 19z"/>',
    inbox:   '<path d="M21 12h-6l-2 3h-2l-2-3H3"/><path d="M5.5 5h13l2.5 7v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-5z"/>',
    key:     '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.8 12.2L21 2M17 6l3 3M14 9l3 3"/>',
    zap:     '<path d="M13 2L4 14h7l-1 8 9-12h-7l1-8z"/>',
    lock:    '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    file:    '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
    trash:   '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
    mail:    '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
    ban:     '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
    archive: '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/>',
    folder:  '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    eyeoff:  '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.6 6.6C3.9 8.4 2 12 2 12s4 7 10 7a9.6 9.6 0 0 0 4.4-1.1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    unlock:  '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.7-1.5"/>',
    pie:     '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.4A9 9 0 0 1 20.6 9H15z"/>'
  };

  function svg(name, cls) {
    if (!PATHS[name]) return '';
    return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
           'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
           PATHS[name] + '</svg>';
  }

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function bytes(n) {
    n = Number(n) || 0;
    if (!n) return '—';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (n >= 10 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
  }

  function ago(iso) {
    if (!iso) return '—';
    var t = Date.parse(iso);
    if (isNaN(t)) return iso;
    var s = Math.round((Date.now() - t) / 1000);
    if (s < 60)    return s + 's ago';
    if (s < 3600)  return Math.round(s / 60) + 'm ago';
    if (s < 86400) return Math.round(s / 3600) + 'h ago';
    return Math.round(s / 86400) + 'd ago';
  }

  function daysSince(date) {
    if (!date) return null;
    var t = Date.parse(date + 'T00:00:00');
    if (isNaN(t)) return null;
    return Math.floor((Date.now() - t) / 86400000);
  }

  function initials(u) { return String(u || '?').slice(0, 2); }

  function toast(kind, msg) {
    var box = document.getElementById('sky-toasts');
    if (!box) return;
    var el = document.createElement('div');
    el.className = 'sky-toast ' + kind;
    el.innerHTML = svg(kind === 'ok' ? 'checkc' : kind === 'bad' ? 'alert' : 'info') +
                   '<div>' + esc(msg) + '</div>' +
                   '<button class="x" aria-label="Dismiss">&times;</button>';
    function close() {
      el.classList.add('out');
      setTimeout(function () { el.remove(); }, 200);
    }
    el.querySelector('.x').addEventListener('click', close);
    box.appendChild(el);
    setTimeout(close, kind === 'bad' ? 8000 : 4500);
  }

  function modal(opts) {
    return new Promise(function (resolve) {
      var root = document.getElementById('sky-root') || document.body;
      var back = document.createElement('div');
      back.className = 'sky-backdrop';
      back.innerHTML =
        '<div class="sky-modal ' + (opts.danger ? 'danger' : (opts.tone || '')) + (opts.wide ? ' wide' : '') +
          '" role="dialog" aria-modal="true">' +
          '<div class="m-head"><div class="m-ico">' + svg(opts.icon || 'alert') + '</div>' +
            '<div><h3>' + esc(opts.title) + '</h3></div></div>' +
          '<div class="m-body">' + (opts.body || '') + '</div>' +
          '<div class="m-foot">' +
            // A result dialog has nothing to cancel — it reports what already
            // happened, so it gets one button that just closes it.
            (opts.single ? '' :
              '<button class="btn" data-act="cancel">' + esc(opts.cancelLabel || 'Cancel') + '</button>') +
            '<button class="btn ' + (opts.danger ? 'btn-danger' : 'btn-primary') + '" data-act="ok">' +
              esc(opts.confirmLabel || 'Confirm') + '</button>' +
          '</div>' +
        '</div>';

      // The theme lives on .sky, and the backdrop is a child of it, so the
      // modal inherits light or dark without any extra bookkeeping.
      root.appendChild(back);

      function done(val) {
        document.removeEventListener('keydown', onKey);
        back.remove();
        resolve(val);
      }
      // Only the topmost dialog answers Escape — a confirmation opened from
      // inside another dialog must not close both.
      function onKey(e) {
        var open = document.querySelectorAll('.sky-backdrop');
        if (e.key === 'Escape' && open[open.length - 1] === back) done(null);
      }

      var cancelBtn = back.querySelector('[data-act="cancel"]');
      if (cancelBtn) cancelBtn.addEventListener('click', function () { done(null); });
      back.querySelector('[data-act="ok"]').addEventListener('click', function () {
        done(opts.collect ? opts.collect(back) : true);
      });
      back.addEventListener('click', function (e) { if (e.target === back) done(null); });
      document.addEventListener('keydown', onKey);

      var focus = back.querySelector(opts.wide ? '[data-act="ok"]' : 'select, input, textarea, [data-act="ok"]');
      if (focus) focus.focus();
      if (opts.ready) opts.ready(back);
    });
  }

  /** POSTs form-encoded when given data, GETs otherwise. Always JSON back. */
  function api(url, data) {
    var opts = { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } };
    if (data) {
      opts.method = 'POST';
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
      var parts = [];
      for (var k in data) {
        if (Object.prototype.hasOwnProperty.call(data, k)) {
          parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
        }
      }
      opts.body = parts.join('&');
    }
    return fetch(url, opts).catch(function () {
      // fetch rejects with a bare "Failed to fetch" for every network-level
      // problem, which tells the reader nothing about what to go and check.
      throw new Error('Could not reach the server. Check that you are still signed in and try again.');
    }).then(function (r) {
      return r.text().then(function (text) {
        try {
          return JSON.parse(text);
        } catch (e) {
          // A PHP warning or the panel's own error page landing in the body
          // is the usual cause, and its first line says what broke.
          throw new Error(text.replace(/<[^>]*>/g, ' ').trim().split('\n')[0].slice(0, 200) ||
                          'The server sent a response this page could not read.');
        }
      });
    });
  }

  /** Runs an action with the button locked and spinning for its duration. */
  function withBusy(btn, promise) {
    if (btn) { btn.classList.add('busy'); btn.disabled = true; }
    function release() { if (btn) { btn.classList.remove('busy'); btn.disabled = false; } }
    return promise.then(function (res) { release(); return res; },
                        function (err) { release(); toast('bad', err.message || 'Request failed.'); throw err; });
  }

  /** Fills every [data-icon] that hasn't been painted yet. */
  function paintIcons(scope) {
    (scope || document).querySelectorAll('[data-icon]').forEach(function (el) {
      if (el.dataset.painted) return;
      el.insertAdjacentHTML('afterbegin', svg(el.dataset.icon));
      el.dataset.painted = '1';
    });
  }

  function emptyState(iconName, title, text) {
    return '<div class="empty">' + svg(iconName) + '<b>' + esc(title) + '</b><span>' + esc(text) + '</span></div>';
  }

  // The theme is an attribute on .sky rather than a media query: the chrome
  // around both panels is light, so following the OS would leave a dark
  // panel sitting in a light page.
  function applyTheme(mode) {
    var root = document.getElementById('sky-root');
    if (!root) return;
    root.setAttribute('data-theme', mode);
    var btn = document.getElementById('sky-theme');
    if (btn) {
      btn.innerHTML = svg(mode === 'dark' ? 'sun' : 'moon');
      btn.title = mode === 'dark' ? 'Switch to light' : 'Switch to dark';
    }
    try { localStorage.setItem('sky.theme', mode); } catch (e) {}
  }

  function initTheme() {
    var saved = 'light';
    try { saved = localStorage.getItem('sky.theme') || 'light'; } catch (e) {}
    applyTheme(saved === 'dark' ? 'dark' : 'light');
    var btn = document.getElementById('sky-theme');
    if (btn) {
      btn.addEventListener('click', function () {
        var root = document.getElementById('sky-root');
        applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
      });
    }
  }

  /**
   * WHM and cPanel both wrap plugin output in their own centred container,
   * so widening our own element alone would leave the page pinned at its
   * width. Only containers that actually cap the width are touched, and the
   * walk stops at <body> — nothing outside the chain above us is changed.
   */
  function fillWidth() {
    var root = document.getElementById('sky-root');
    if (!root) return;
    for (var el = root.parentElement; el && el !== document.body; el = el.parentElement) {
      try {
        if (getComputedStyle(el).maxWidth !== 'none') el.style.maxWidth = 'none';
      } catch (e) { return; }
    }
  }

  /**
   * Hides the row of cPanel's own branding and links that the chrome prints
   * above a plugin's content. Inside a panel that already has its own
   * heading it is duplicate furniture, and it pushes everything down.
   *
   * Keyed off the links themselves rather than a class name — the markup is
   * cPanel's, not ours, and it changes between versions. The walk is bounded
   * and refuses to touch anything that contains our own panel, so if the
   * layout is not what this expects, nothing is hidden.
   */
  function hideChromeBranding() {
    var root = document.getElementById('sky-root');
    if (!root) return;
    var marker = null;
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      if (root.contains(links[i])) continue;
      if (/cpanel\.(net|com)/i.test(links[i].getAttribute('href') || '')) { marker = links[i]; break; }
    }
    if (!marker) return;

    // Walk up only as far as the blocks that are exclusively chrome: the
    // moment an ancestor also contains our panel, that ancestor is shared
    // and everything above it is off limits.
    var chain = [];
    for (var el = marker.parentElement, depth = 0; el && depth < 8; el = el.parentElement, depth++) {
      if (el === document.body || el.contains(root)) break;
      chain.push(el);
    }
    if (!chain.length) return;

    // A single stray cpanel.net link is not the branding row — a row of
    // them is. Requiring several keeps this from hiding something else that
    // happens to sit above us.
    var isRow = chain.some(function (el) {
      return el.querySelectorAll('a[href*="cpanel.net"], a[href*="cpanel.com"]').length >= 3;
    });
    if (!isRow) return;

    // Hide the outermost of those blocks, so the logo beside the links goes
    // with them instead of leaving half a row behind.
    chain[chain.length - 1].style.display = 'none';
  }

  /** Wires a [role=tablist] of .tab buttons to #p-<name> panels. */
  function tabs(names, onShow) {
    var strip = document.getElementById('sky-tabs');
    function show(name) {
      strip.querySelectorAll('.tab').forEach(function (t) {
        t.setAttribute('aria-selected', String(t.dataset.tab === name));
      });
      names.forEach(function (n) {
        var panel = document.getElementById('p-' + n);
        if (panel) panel.hidden = (n !== name);
      });
      try { sessionStorage.setItem('sky.tab', name); } catch (e) {}
      if (onShow) onShow(name);
    }
    strip.addEventListener('click', function (e) {
      var tab = e.target.closest('.tab');
      if (tab) show(tab.dataset.tab);
    });
    var saved = 'x';
    try { saved = sessionStorage.getItem('sky.tab') || 'x'; } catch (e) {}
    show(names.indexOf(saved) >= 0 ? saved : names[0]);
    return show;
  }

  return {
    svg: svg, esc: esc, bytes: bytes, ago: ago, daysSince: daysSince, initials: initials,
    toast: toast, modal: modal, api: api, withBusy: withBusy, paintIcons: paintIcons,
    emptyState: emptyState, applyTheme: applyTheme, initTheme: initTheme,
    fillWidth: fillWidth, hideChromeBranding: hideChromeBranding, tabs: tabs
  };
})();
</script>
JS;
}
