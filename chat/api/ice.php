<?php
/**
 * Where two browsers should look for each other.
 *
 * STUN — free, public, and enough for roughly eight calls in ten: it only tells
 * each browser what its own address looks like from outside, and then the call
 * goes straight across.
 *
 * TURN — a relay, for the rest: mobile data, office networks and firewalls that
 * will not let two browsers meet directly. Relays carry the call's traffic, so
 * they are what costs money; the ones configured here are FREE-quota services
 * (the owner's condition: "poora 100% free"), their allowance resetting every
 * month the way the Groq key's does.
 *
 * The relay's own key never leaves this server. The browser is handed short-
 * lived credentials, fetched here and cached for a few minutes, so a key copied
 * out of somebody's browser tools is not a key at all.
 *
 * Configured in the auction's .env (the one file on this account that holds
 * secrets) — any, all or none of:
 *   METERED_APP / METERED_KEY          https://<app>.metered.live, 20 GB/month free
 *   TURN_URLS / TURN_USER / TURN_PASS  a fixed relay, e.g. ExpressTURN (1,000 GB/month free)
 * With none of them the calls still work; the relay simply is not there for the
 * two in ten that need it.
 */

require_once __DIR__ . '/../includes/lib.php';

if (!guest() && !staff()) {
    jsonOut(array('ok' => false, 'signedout' => true), 401);
}
refuseUntilDetails(guest(), staff());

$servers = array(
    array('urls' => array('stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302')),
    array('urls' => 'stun:stun.cloudflare.com:3478'),
);
$relay = false;

/* ---- Metered: short-lived credentials from their REST API, cached. */
$app = env_get('METERED_APP', '');
$key = env_get('METERED_KEY', '');
if ($app !== '' && $key !== '') {
    $cache = sys_get_temp_dir() . '/sbkchat-metered-' . md5($app . $key) . '.json';
    $got = null;
    if (is_file($cache) && filemtime($cache) > time() - 600) {
        $got = json_decode((string) @file_get_contents($cache), true);
    }
    if (!is_array($got)) {
        $url = 'https://' . rawurlencode($app) . '.metered.live/api/v1/turn/credentials?apiKey=' . rawurlencode($key);
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 6,
                                     CURLOPT_CONNECTTIMEOUT => 4));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $got = ($code === 200) ? json_decode((string) $body, true) : null;
        if (is_array($got) && $got) {
            @file_put_contents($cache, json_encode($got), LOCK_EX);
        }
    }
    if (is_array($got)) {
        foreach ($got as $srv) {
            if (!empty($srv['urls'])) {
                $servers[] = $srv;
                if (strpos(json_encode($srv['urls']), 'turn') !== false) { $relay = true; }
            }
        }
    }
}

/* ---- A fixed relay (ExpressTURN and the like). */
$turnUrls = env_get('TURN_URLS', '');
if ($turnUrls !== '') {
    $servers[] = array(
        'urls'       => array_values(array_filter(array_map('trim', explode(',', $turnUrls)))),
        'username'   => (string) env_get('TURN_USER', ''),
        'credential' => (string) env_get('TURN_PASS', ''),
    );
    $relay = true;
}

header('Cache-Control: no-store');
jsonOut(array('ok' => true, 'iceServers' => $servers, 'relay' => $relay));
