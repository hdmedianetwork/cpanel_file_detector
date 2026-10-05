#!/bin/bash
# End-to-end check of the scanner and every admin action, against a fake
# server: a stub whmapi1, a stub sendmail and two fake home directories.
# Needs PHP and must run as root (quarantine hands files to root).
#
#   tests/smoke.sh
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT

export SG_CONF="$T/guard.conf"
export SG_SPOOL="$T/spool"
export SG_LOG="$T/guard.log"
export SG_TEST_HOME_BASE="$T/home"
export SG_WHMAPI="$T/whmapi1"
export SG_SENDMAIL="$T/sendmail"

fail() { echo "FAIL: $*" >&2; echo "--- log"; cat "$SG_LOG" 2>/dev/null; exit 1; }
pass() { echo "ok   $*"; }

# ---- fake server -----------------------------------------------------------
cat > "$SG_WHMAPI" <<EOF
#!/bin/bash
fn="\$2"; shift 2
case "\$fn" in
  listaccts)
    s=0; [ -f "$T/suspended" ] && s=1
    cat <<JSON
{"metadata":{"result":1},"data":{"acct":[
 {"user":"alice","domain":"alice.test","email":"alice@alice.test","suspended":\$s,"diskused":"2100M","disklimit":"5000M","plan":"basic","owner":"root"},
 {"user":"bob","domain":"bob.test","email":"","suspended":0,"diskused":"20M","disklimit":"unlimited","plan":"basic","owner":"root"}
]}}
JSON
    ;;
  suspendacct)   echo "\$@" > "$T/suspended"; echo '{"metadata":{"result":1}}' ;;
  unsuspendacct) rm -f "$T/suspended";        echo '{"metadata":{"result":1}}' ;;
  *) echo '{"metadata":{"result":0,"reason":"unknown"}}' ;;
esac
EOF
cat > "$SG_SENDMAIL" <<EOF
#!/bin/bash
cat >> "$T/mail.out"
EOF
chmod +x "$SG_WHMAPI" "$SG_SENDMAIL"

H="$SG_TEST_HOME_BASE"
mkdir -p "$H/alice/public_html/old" "$H/alice/mail/cur" "$H/alice/backups" "$H/bob/public_html"
truncate -s 150M "$H/alice/public_html/site-backup.zip"         # archive ≥ 100 MB
truncate -s 600M "$H/alice/backups/disk.img"                     # large ≥ 500 MB
truncate -s 120M "$H/alice/public_html/old/db dump (1).sql.gz"   # archive, awkward name
truncate -s 90M  "$H/alice/public_html/small.zip"                # under threshold
truncate -s 700M "$H/alice/mail/cur/huge"                        # excluded folder
truncate -s 300M "$H/alice/public_html/video.mp4"                # not archive, under large
ln -s /etc/passwd "$H/alice/public_html/link.zip"                # symlinks are never followed
truncate -s 10M  "$H/bob/public_html/index.html"
cp "$REPO/etc/skyserver-storage-guard.conf.example" "$SG_CONF"

G() { php "$REPO/bin/guard.php" "$@"; }
cgi() { # <query> [post-body]
  local method=GET body="${2:-}"; [ -n "$body" ] && method=POST
  QUERY_STRING="$1" REQUEST_METHOD="$method" CONTENT_TYPE=application/x-www-form-urlencoded \
    CONTENT_LENGTH="${#body}" SG_GUARD_LIB="$REPO/lib/guard-lib.php" \
    php "$REPO/whm-plugin/index.cgi" <<<"$body" | sed '1,/^\r$/d'
}
jqr() { jq -r "$@"; }

# ---- scan ------------------------------------------------------------------
G scan-all >/dev/null || fail "scan-all"
R="$SG_SPOOL/reports/alice.json"
[ "$(jqr .flagged_count "$R")" = 3 ] || fail "alice should have 3 flagged files, got $(jqr .flagged_count "$R")"
[ "$(jqr '.files[0].path' "$R")" = "backups/disk.img" ] || fail "largest first"
[ "$(jqr '.files[0].kind' "$R")" = "large" ] || fail "disk.img is large"
jqr '.files[].path' "$R" | grep -qx 'public_html/old/db dump (1).sql.gz' || fail "awkward filename"
jqr '.files[].path' "$R" | grep -q 'mail/' && fail "mail/ must be excluded"
jqr '.files[].path' "$R" | grep -q 'link.zip' && fail "symlink followed"
[ "$(jqr .flagged_count "$SG_SPOOL/reports/bob.json")" = 0 ] || fail "bob is clean"
[ "$(jqr .open "$SG_SPOOL/cases/alice.json")" = true ] || fail "case opened"
[ "$(jqr .notified "$SG_SPOOL/notices/alice.json")" = false ] || fail "notice not yet a warning"
[ -f "$H/alice/.skyserver-storage/notice.json" ] || fail "home copy of notice"
[ "$(jqr .status "$SG_SPOOL/scan-state.json")" = finished ] || fail "scan state"
pass "scan finds the right files and skips excluded, small and symlinked ones"

# ---- remind ----------------------------------------------------------------
G remind alice >/dev/null || fail "remind"
grep -q '^To: alice@alice.test' "$T/mail.out" || fail "reminder email"
grep -q 'backups/disk.img' "$T/mail.out" || fail "email lists files"
[ "$(jqr .notified "$SG_SPOOL/notices/alice.json")" = true ] || fail "notice now a warning"
[ "$(jqr '.deadline != null' "$SG_SPOOL/cases/alice.json")" = true ] || fail "deadline set"
D1="$(jqr .deadline "$SG_SPOOL/cases/alice.json")"
G remind alice >/dev/null
grep -q '^Subject: Reminder 2:' "$T/mail.out" || fail "second reminder is numbered"
[ "$(jqr .deadline "$SG_SPOOL/cases/alice.json")" = "$D1" ] || fail "deadline does not move"
G remind bob >/dev/null && fail "bob has nothing to be reminded about"
pass "reminders email the account, number themselves and keep one deadline"

# ---- WHM API ---------------------------------------------------------------
cgi 'api=state' | jq -e '.ok and (.state.accounts|length)==2 and .state.totals.flagged==1' >/dev/null \
  || fail "api=state"
cgi 'api=remind' | jq -e '.ok==false' >/dev/null || fail "mutating action must refuse GET"
ID_ZIP="$(jqr '.files[] | select(.path=="public_html/site-backup.zip") | .id' "$R")"
ID_IMG="$(jqr '.files[] | select(.path=="backups/disk.img") | .id' "$R")"
ID_SQL="$(jqr '.files[] | select(.kind=="archive" and (.path|test("sql"))) | .id' "$R")"

out="$(cgi 'api=remove_files' "user=alice&mode=quarantine&ids=$ID_ZIP")"
echo "$out" | jq -e '.ok and .removed==1' >/dev/null || fail "quarantine via API: $out"
[ ! -e "$H/alice/public_html/site-backup.zip" ] || fail "file left in place"
Q="$(ls "$H/.skyserver-quarantine/alice/")"
[ -f "$H/.skyserver-quarantine/alice/$Q/files/public_html/site-backup.zip" ] || fail "file in quarantine"
[ "$(stat -c %u "$H/.skyserver-quarantine/alice/$Q/files/public_html/site-backup.zip")" = 0 ] || fail "quarantined file owned by root"
[ "$(jqr .flagged_count "$R")" = 2 ] || fail "report updated"
pass "quarantine moves the file out of the account and updates the report"

out="$(cgi 'api=quarantine_restore' "id=$Q")"
echo "$out" | jq -e '.ok' >/dev/null || fail "restore: $out"
[ -f "$H/alice/public_html/site-backup.zip" ] || fail "file restored"
[ "$(jqr .flagged_count "$R")" = 3 ] || fail "restored file is flagged again"
pass "quarantine restore puts it back"

# A symlinked folder on the way must never be followed by root.
mv "$H/alice/public_html/old" "$H/alice/public_html/old.real"
mkdir -p "$T/outside"; truncate -s 120M "$T/outside/db dump (1).sql.gz"
ln -s "$T/outside" "$H/alice/public_html/old"
out="$(cgi 'api=remove_files' "user=alice&mode=delete&ids=$ID_SQL")"
echo "$out" | jq -e '.ok==false and (.error|test("not a plain folder"))' >/dev/null || fail "symlinked folder followed: $out"
[ -f "$T/outside/db dump (1).sql.gz" ] || fail "file outside the home was deleted"
rm "$H/alice/public_html/old"; mv "$H/alice/public_html/old.real" "$H/alice/public_html/old"
pass "a symlinked folder is refused, nothing outside the home is touched"

cgi 'api=remove_files' "user=alice&mode=delete&ids=$ID_SQL" | jq -e '.ok' >/dev/null || fail "delete"
[ ! -e "$H/alice/public_html/old/db dump (1).sql.gz" ] || fail "file deleted"
cgi 'api=ignore_files' "user=alice&ids=$ID_IMG" | jq -e '.ok' >/dev/null || fail "ignore"
G scan-user alice >/dev/null
jqr '.files[].path' "$R" | grep -q disk.img && fail "ignored file came back"
pass "permanent delete, and an allowed file stays allowed across scans"

cgi 'api=remove_files' "user=alice&mode=delete&ids=$ID_ZIP" | jq -e '.ok' >/dev/null || fail "delete last"
[ "$(jqr .open "$SG_SPOOL/cases/alice.json")" = false ] || fail "case closes when nothing is left"
[ "$(jqr .status "$SG_SPOOL/notices/alice.json")" = clean ] || fail "notice says clean"
pass "the case closes itself once the account is clean"

# ---- suspension ------------------------------------------------------------
cgi 'api=suspend' "user=bob&reason=testing" | jq -e '.ok' >/dev/null || fail "suspend"
grep -q 'reason=testing' "$T/suspended" || fail "suspendacct called with reason"
cgi 'api=state' | jq -e '.state.accounts[] | select(.user=="alice") | .status=="suspended"' >/dev/null \
  || fail "suspended status (stub suspends everyone)"
cgi 'api=unsuspend' "user=bob" | jq -e '.ok' >/dev/null || fail "unsuspend"
[ ! -f "$T/suspended" ] || fail "unsuspendacct called"
pass "suspend and unsuspend go through whmapi1"

# ---- auto enforcement -------------------------------------------------------
truncate -s 200M "$H/bob/public_html/full.tar.gz"
sed -i 's/^AUTO_REMIND=.*/AUTO_REMIND="1"/; s/^GRACE_DAYS=.*/GRACE_DAYS="0"/' "$SG_CONF"
: > "$T/mail.out"
G scan-all --enforce >/dev/null
[ "$(jqr '.reminders|length' "$SG_SPOOL/cases/bob.json")" = 1 ] || fail "auto reminder"
G scan-all --enforce >/dev/null
[ "$(jqr '.reminders|length' "$SG_SPOOL/cases/bob.json")" = 1 ] || fail "auto reminder must wait REMIND_EVERY_DAYS"
[ ! -f "$T/suspended" ] || fail "auto-suspend is off by default"
sed -i 's/^AUTO_SUSPEND=.*/AUTO_SUSPEND="1"/' "$SG_CONF"
sleep 1
G scan-all --enforce >/dev/null
grep -q 'user=bob' "$T/suspended" || fail "auto-suspend overdue account"
pass "cron enforcement: reminds when due, suspends only when switched on"

# ---- customer page -----------------------------------------------------------
rm -f "$T/suspended"
G scan-user bob >/dev/null
BID="$(jqr '.files[0].id' "$SG_SPOOL/reports/bob.json")"
up() { # <query> [post]
  local method=GET; [ -n "${2:-}" ] && method=POST
  REMOTE_USER=bob QUERY_STRING="$1" REQUEST_METHOD="$method" SG_SPOOL="$SG_SPOOL" \
    php -r 'parse_str(getenv("QUERY_STRING"), $_GET); parse_str($argv[1] ?? "", $_POST);
            $_SERVER["REQUEST_METHOD"] = getenv("REQUEST_METHOD"); include $argv[2];' "${2:-}" "$REPO/plugin/api.live.php"
}
up 'api=state' | jq -e '.ok and (.state.files|length)==1' >/dev/null || fail "customer state"
up 'api=delete' "ids=$BID" | jq -e '.ok and .deleted==1' >/dev/null || fail "customer delete"
[ ! -e "$H/bob/public_html/full.tar.gz" ] || fail "customer file deleted"
[ -f "$SG_SPOOL/rescan-requests/bob" ] || fail "customer delete asks for a rescan"
G worker
[ "$(jqr .flagged_count "$SG_SPOOL/reports/bob.json")" = 0 ] || fail "worker rescanned bob"
pass "the customer can delete their own flagged files and the worker rescans"

# ---- the home copy of the notice cannot be turned against root --------------
rm -rf "$H/bob/.skyserver-storage"; mkdir "$H/bob/.skyserver-storage"; chmod 777 "$H/bob/.skyserver-storage"
G scan-user bob >/dev/null
[ "$(stat -c '%u %a' "$H/bob/.skyserver-storage")" = "0 750" ] || fail "account-made folder is taken over before writing"
[ -f "$H/bob/.skyserver-storage/notice.json" ] || fail "notice written"
pass "the notice folder in the home is root's before anything is written into it"

echo "all smoke tests passed"
