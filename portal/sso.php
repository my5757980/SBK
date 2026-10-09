<?php
/**
 * SBK Auction — sign-in from the SBK website ("Auction" button in its admin panel).
 *
 * The same scheme as the website's "CRM Global" button: the website signs a
 * one-minute, single-use token with its RSA private key; this file holds only
 * the public half, so nothing here can make a token. A valid token signs in
 * the auction staff account with the same email (admins.email, exactly one,
 * active) and opens the admin panel; anything else lands on the staff login
 * page, which says why. No auction page is touched by this.
 *
 * Token: v1.<base64url JSON>.<base64url RSA-SHA256 signature over "v1.<JSON part>">
 * Claims: iss "sbk-website", aud "sbk-auction", purpose "sso", sub = email,
 *         iat/exp (at most 60 s apart), nonce (used once, kept a day).
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

// The website's public key (the same one the CRM trusts). WEBSITE_PUBLIC_KEY in
// .env may replace it: the PEM, or the PEM in base64 on one line.
const SSO_WEBSITE_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzX7G2AuIgf/RJCphNiFI
fvjT/2g0YcAEdE6tznFOLKWX6Zm2k902XLXxzhpaZ9bfP6jijWfqFQMHOFYzSuXr
j9tijHefcCkqNN55tRVJMj7WZkCeZ802OE3SYNV2KRdrAvTvWdlNwpkqGpi9Ptp6
FfFqDPtSUuK+fFyXk8/dEA54E+LGN5zG9PdnpuMY+vyOHOTb8wKVA/yUfXrYuo7I
DKgn5lx4gXk3Q+2eUMmj02NBUPqTfy985pjC9FezKWDLiLHXIwz94oJ3Z0l93RXY
YVRsWcOcTzjK1ftqHv5hbN0373c4nt+SxI7yIO11ncPu5QuISM2W0xFA75Wo6qXF
YwIDAQAB
-----END PUBLIC KEY-----
PEM;

function ssoPublicKey() {
    $fromEnv = trim((string) env_get('WEBSITE_PUBLIC_KEY', ''));
    if ($fromEnv === '') {
        return SSO_WEBSITE_PUBLIC_KEY;
    }
    return strpos($fromEnv, 'BEGIN') !== false
        ? str_replace('\n', "\n", $fromEnv)
        : (string) base64_decode($fromEnv, true);
}

function ssoBase64urlDecode($text) {
    $text = strtr($text, '-_', '+/');
    $pad = strlen($text) % 4;
    if ($pad) {
        $text .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($text, true);
}

/** The token's claims when it is genuine, current and meant for the auction; otherwise null. */
function ssoVerify($token) {
    $parts = explode('.', (string) $token);
    if (count($parts) !== 3 || $parts[0] !== 'v1' || strlen($token) > 4096) {
        return null;
    }
    $json = ssoBase64urlDecode($parts[1]);
    $signature = ssoBase64urlDecode($parts[2]);
    $key = openssl_pkey_get_public(ssoPublicKey());
    if ($json === false || $signature === false || $key === false) {
        return null;
    }
    if (openssl_verify('v1.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }

    $claims = json_decode($json, true);
    if (!is_array($claims)
        || ($claims['iss'] ?? '') !== 'sbk-website'
        || ($claims['aud'] ?? '') !== 'sbk-auction'
        || ($claims['purpose'] ?? '') !== 'sso'
        || !is_int($claims['iat'] ?? null) || !is_int($claims['exp'] ?? null)
        || !is_string($claims['nonce'] ?? null) || strlen($claims['nonce']) < 16 || strlen($claims['nonce']) > 128
        || !filter_var($claims['sub'] ?? '', FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $now = time();
    $skew = 30;
    if ($claims['iat'] > $now + $skew || $claims['exp'] + $skew < $now || $claims['exp'] - $claims['iat'] > 60) {
        return null;
    }
    return $claims;
}

/** True the first time a nonce is seen; a token works once. The table makes itself. */
function ssoConsumeNonce($nonce, $exp) {
    global $conn;
    $conn->query("CREATE TABLE IF NOT EXISTS sso_nonces (
        nonce VARCHAR(128) NOT NULL PRIMARY KEY,
        expires_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("DELETE FROM sso_nonces WHERE expires_at < NOW() - INTERVAL 1 DAY");
    $stmt = $conn->prepare("INSERT IGNORE INTO sso_nonces (nonce, expires_at) VALUES (?, FROM_UNIXTIME(?))");
    $stmt->bind_param('si', $nonce, $exp);
    $stmt->execute();
    $fresh = $stmt->affected_rows === 1;
    $stmt->close();
    return $fresh;
}

function ssoFail($why) {
    header('Location: ' . SITE_URL . 'admin/login.php?sso=' . $why);
    exit;
}

$claims = ssoVerify($_GET['token'] ?? '');
if ($claims === null || !ssoConsumeNonce($claims['nonce'], $claims['exp'])) {
    ssoFail('invalid');
}

// The staff account with this email - only when exactly one active account has it.
$email = strtolower(trim($claims['sub']));
$stmt = $conn->prepare("SELECT id, name FROM admins WHERE email = ? AND email <> '' AND is_active = 1 LIMIT 2");
$stmt->bind_param('s', $email);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
if (count($rows) !== 1) {
    ssoFail('nouser');
}

session_regenerate_id(true);
$_SESSION['admin_id'] = $rows[0]['id'];
$_SESSION['admin_name'] = $rows[0]['name'];
$stmt = $conn->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
$stmt->bind_param('i', $rows[0]['id']);
$stmt->execute();
$stmt->close();

header('Location: ' . SITE_URL . 'admin/dashboard.php');
exit;
