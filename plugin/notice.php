<?php
/**
 * Reading this account's notice, for the cPanel-side pages.
 *
 * These pages run as the logged-in cPanel account, never as root. The
 * notice is written by Storage Guard (bin/guard, as root) in two places the
 * account can read and nobody else can: the spool copy, 0640 root:<user> in
 * a directory no account can list, and a copy in the account's own home for
 * servers that hide /var/spool from it.
 */

function sgu_spool(): string {
    return rtrim(getenv('SG_SPOOL') ?: '/var/spool/skyserver-storage-guard', '/');
}

function sgu_home(string $user): ?string {
    $base = getenv('SG_TEST_HOME_BASE');
    if ($base) {
        return rtrim($base, '/') . '/' . $user;
    }
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        if (is_array($pw) && !empty($pw['dir'])) {
            return rtrim($pw['dir'], '/');
        }
    }
    $home = getenv('HOME');
    return $home ? rtrim($home, '/') : null;
}

function sgu_rel_ok(string $rel): bool {
    if ($rel === '' || $rel[0] === '/' || strpos($rel, "\0") !== false) {
        return false;
    }
    foreach (explode('/', $rel) as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return false;
        }
    }
    return true;
}

/** Returns [notice or null, whether the place it would live could be seen]. */
function sgu_read_notice(string $user): array {
    $paths = [sgu_spool() . "/notices/$user.json"];
    $home = sgu_home($user);
    if ($home) {
        $paths[] = "$home/.skyserver-storage/notice.json";
    }
    foreach ($paths as $p) {
        if (!is_readable($p)) {
            continue;
        }
        $data = json_decode((string) @file_get_contents($p), true);
        if (is_array($data) && ($data['user'] ?? '') === $user) {
            return [$data, true];
        }
    }
    return [null, is_dir(sgu_spool() . '/notices')];
}

function sgu_rescan_pending(string $user): bool {
    return file_exists(sgu_spool() . "/rescan-requests/$user");
}

/**
 * The notice as of the last scan, with each file checked against the disk
 * now — so a file the customer just deleted shows as gone straight away,
 * without waiting for the next scan.
 */
function sgu_state(string $user): array {
    [$notice, $visible] = sgu_read_notice($user);
    $home = sgu_home($user);
    $files = [];
    $left = 0;
    $leftBytes = 0;
    foreach (($notice['files'] ?? []) as $f) {
        $gone = true;
        if ($home && sgu_rel_ok((string) $f['path'])) {
            $st = @lstat($home . '/' . $f['path']);
            $gone = !$st || ($st['mode'] & 0170000) !== 0100000;
        }
        $f['gone'] = $gone;
        if (!$gone) {
            $left++;
            $leftBytes += (int) $f['size'];
        }
        $files[] = $f;
    }
    return [
        'user'           => $user,
        'home'           => $home,
        'visible'        => $visible,
        'notice'         => $notice ? array_diff_key($notice, ['files' => 1]) : null,
        'files'          => $files,
        'left'           => $left,
        'left_bytes'     => $leftBytes,
        'rescan_pending' => sgu_rescan_pending($user),
    ];
}

/** Asks the root worker to scan this account again (cron, within a minute). */
function sgu_request_rescan(string $user): bool {
    $f = sgu_spool() . "/rescan-requests/$user";
    $ok = @file_put_contents($f, (string) time()) !== false;
    if ($ok) {
        @chmod($f, 0600);
    }
    return $ok;
}
