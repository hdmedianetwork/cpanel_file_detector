<?php
/**
 * Shared LiveAPI plumbing for the cPanel-side pages.
 *
 * cPanel serves .live.php files through LiveAPI and expects the script to
 * open that connection. One that doesn't gets "Child failed to make LIVEAPI
 * connection to cPanel." appended to whatever it printed — visible under the
 * page, and enough to turn a JSON reply into something no parser accepts.
 *
 * Every failure here is soft: a server without a usable LiveAPI still gets a
 * working plugin, just without cPanel's chrome around it.
 */

function liveapi_connect() {
    $lib = '/usr/local/cpanel/php/cpanel.php';
    if (!is_readable($lib)) {
        return null;
    }
    require_once $lib;
    if (!class_exists('CPANEL')) {
        return null;
    }
    try {
        return new CPANEL();
    } catch (Throwable $e) {
        return null;
    }
}

function liveapi_end($cpanel): void {
    if ($cpanel && method_exists($cpanel, 'end')) {
        try {
            $cpanel->end();
        } catch (Throwable $e) {
            // Nothing useful to do while the response is already on its way.
        }
    }
}
