<?php
/**
 * JSON endpoint for the Storage Report page, running as the logged-in
 * cPanel account.
 *
 *   GET  ?api=state             this account's notice, checked against disk
 *   POST ?api=delete  ids=a,b   delete flagged files (only ones in the notice)
 *   POST ?api=rescan            ask for a fresh scan
 *
 * Deleting happens here, as the account itself, so the kernel's own
 * permissions are the boundary: this page can only ever delete what the
 * customer could already delete in File Manager.
 */
require_once __DIR__ . '/liveapi.php';
require_once __DIR__ . '/notice.php';

$cpanel = liveapi_connect();
register_shutdown_function('liveapi_end', $cpanel);

header('Content-Type: application/json');

function reply(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$user = (string) getenv('REMOTE_USER');
if (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
    http_response_code(403);
    reply(['ok' => false, 'error' => 'Unable to determine cPanel user.']);
}

$action = (string) ($_GET['api'] ?? 'state');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($action !== 'state' && !$isPost) {
    http_response_code(405);
    reply(['ok' => false, 'error' => 'This action requires POST.']);
}

switch ($action) {
    case 'state':
        reply(['ok' => true, 'state' => sgu_state($user)]);

    case 'rescan':
        if (!sgu_request_rescan($user)) {
            reply(['ok' => false, 'error' => 'Could not ask for a new check. Please contact support.']);
        }
        reply(['ok' => true, 'message' => 'Checking your account again — this takes a minute or two.']);

    case 'delete':
        [$notice] = sgu_read_notice($user);
        $home = sgu_home($user);
        if (!$notice || !$home) {
            reply(['ok' => false, 'error' => 'Your storage report could not be read.']);
        }
        $realHome = realpath($home);
        $byId = [];
        foreach ($notice['files'] as $f) {
            $byId[$f['id']] = $f;
        }
        $ids = array_filter(explode(',', (string) ($_POST['ids'] ?? '')));
        $deleted = 0;
        $bytes = 0;
        $failed = [];
        foreach ($ids as $id) {
            $f = $byId[$id] ?? null;
            if (!$f || !sgu_rel_ok((string) $f['path'])) {
                continue;
            }
            $path = $home . '/' . $f['path'];
            // The folder must really be inside this home — a symlinked
            // folder pointing elsewhere is not followed.
            $dir = realpath(dirname($path));
            if ($dir === false || $realHome === false || ($dir !== $realHome && strpos($dir, $realHome . '/') !== 0)) {
                $failed[] = $f['path'];
                continue;
            }
            clearstatcache();
            $st = @lstat($path);
            if (!$st) {
                continue;
            }
            if (($st['mode'] & 0170000) !== 0100000 || !@unlink($path)) {
                $failed[] = $f['path'];
                continue;
            }
            $deleted++;
            $bytes += (int) $f['size'];
        }
        if ($deleted) {
            sgu_request_rescan($user);
        }
        reply([
            'ok'      => $deleted > 0 || !$failed,
            'deleted' => $deleted,
            'bytes'   => $bytes,
            'failed'  => $failed,
            'error'   => $failed && !$deleted ? 'Could not delete ' . $failed[0] . '. Try File Manager, or contact support.' : null,
            'state'   => sgu_state($user),
        ]);
}

http_response_code(404);
reply(['ok' => false, 'error' => 'Unknown action.']);
