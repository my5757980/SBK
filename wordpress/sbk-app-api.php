<?php
/**
 * Plugin Name: SBK App API
 * Description: The front door for the mobile application — sign in, register, and
 *              a long-lived pass that the chat accepts. Nothing else.
 *
 * WHY THIS IS A SEPARATE FILE FROM sbk-live-chat.php
 * That one is live and working and drives the button, the panel, the bell and the
 * handover for every visitor to the website. This adds a door for the app and
 * touches none of it, so a mistake here cannot take the website's chat down.
 *
 * WHY THE APP SIGNS IN HERE AND NOT AT THE CHAT
 * The chat has never seen a password and must not start now — "one account, one
 * password, and one place to take somebody's access away" is the rule the whole
 * thing was built on. WordPress owns the accounts, so WordPress checks the
 * password, using its own wp_authenticate(): every hash it has ever written,
 * every rule it applies, without this file knowing anything about either.
 *
 * WHAT THE APP GETS BACK is the same signed line the website already hands the
 * chat — the person's id, an expiry and an HMAC over both, with the shared
 * CHAT_LINK_SECRET. It is marked `app` and lasts sixty days rather than five
 * minutes, because a phone is not a browser tab: nobody is going to sign in
 * again every time they open it. It carries no password and nothing that is any
 * use anywhere but this chat, and taking the account away on the website takes
 * the app with it on the person's next request.
 *
 *   POST /?sbk_app=login      {login, password}                -> {token, user}
 *   POST /?sbk_app=register   {name, email, phone, password}    -> {token, user}
 *   POST /?sbk_app=me         {token}                           -> {user}
 *
 * All three answer JSON and nothing else.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SBK_APP_DAYS', 60);
/* Six tries from one address in fifteen minutes. Enough that somebody who has
   genuinely forgotten which of their two e-mail addresses it was can keep
   trying; not enough to walk a password list. */
define('SBK_APP_TRIES', 6);
define('SBK_APP_WINDOW', 900);

/** The one shared secret, read the same way sbk-live-chat.php reads it. */
function sbk_app_secret() {
    return function_exists('sbk_chat_secret') ? sbk_chat_secret() : '';
}

/**
 * A pass for the app: who, until when, and a signature over both.
 *
 * `app.` in front so it can never be mistaken for the website's five-minute
 * handover, and so the chat can tell which kind it is holding.
 */
function sbk_app_token($userId) {
    $secret = sbk_app_secret();
    if (!$userId || $secret === '') {
        return '';
    }
    $body = 'app.' . (int) $userId . '.' . (time() + SBK_APP_DAYS * 86400);
    return $body . '.' . hash_hmac('sha256', $body, $secret);
}

/** What the app is told about a person. No password, no capabilities list. */
function sbk_app_user_out($u) {
    if (!$u instanceof WP_User || !$u->ID) {
        return null;
    }
    $roles = (array) $u->roles;
    $first = $roles ? reset($roles) : '';
    $names = wp_roles()->get_names();
    return array(
        'id'       => (int) $u->ID,
        'name'     => $u->display_name ?: $u->user_login,
        'email'    => $u->user_email,
        'phone'    => (string) get_user_meta($u->ID, 'billing_phone', true),
        'role'     => $first,
        'role_name' => isset($names[$first]) ? $names[$first] : $first,
        /* Staff by ROLE. The "Show in the chat" tick box answers a different
           question - whether a customer may see them - and must never be the
           thing that decides somebody is not the desk. That conflation sent the
           administrator to the sign-up form on 16 September 2026; see
           wpIsStaff() and wpIsListed() in the chat's lib.php. */
        'is_staff' => sbk_app_is_staff($u),
        'is_admin' => in_array('administrator', $roles, true),
    );
}

/** A staff ROLE, and the owner's four are the list. */
function sbk_app_is_staff($u) {
    if (!$u instanceof WP_User || !$u->ID) {
        return false;
    }
    if (get_user_meta($u->ID, 'sbk_chat_visible', true) === 'yes') {
        return true;
    }
    $allowed = array('administrator', 'csd', 'manager', 'sbk_agent');
    return (bool) array_intersect((array) $u->roles, $allowed);
}

function sbk_app_out($data, $code = 200) {
    nocache_headers();
    status_header($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo wp_json_encode($data);
    exit;
}

function sbk_app_field($k) {
    return isset($_POST[$k]) ? trim((string) wp_unslash($_POST[$k])) : '';
}

/** How many times this address has been turned away lately. */
function sbk_app_tries($bump = false) {
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '?';
    $key = 'sbk_app_try_' . md5($ip);
    $n   = (int) get_transient($key);
    if ($bump) {
        set_transient($key, $n + 1, SBK_APP_WINDOW);
    }
    return $n;
}

add_action('init', function () {
    if (!isset($_GET['sbk_app'])) {
        return;
    }
    $do = sanitize_key($_GET['sbk_app']);

    if (sbk_app_secret() === '') {
        sbk_app_out(array('ok' => false, 'error' => 'The app door is not configured.'), 500);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sbk_app_out(array('ok' => false, 'error' => 'POST only.'), 405);
    }

    /* ------------------------------------------------------------- who am I */
    if ($do === 'me') {
        $id = sbk_app_token_user(sbk_app_field('token'));
        if (!$id) {
            sbk_app_out(array('ok' => false, 'signedout' => true), 401);
        }
        sbk_app_out(array('ok' => true, 'user' => sbk_app_user_out(get_userdata($id))));
    }

    /* -------------------------------------------------------------- sign in */
    if ($do === 'login') {
        if (sbk_app_tries() >= SBK_APP_TRIES) {
            sbk_app_out(array('ok' => false,
                'error' => 'Too many attempts. Please wait a few minutes.'), 429);
        }
        $login = sbk_app_field('login');
        $pass  = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if ($login === '' || $pass === '') {
            sbk_app_out(array('ok' => false, 'error' => 'E-mail and password, please.'), 400);
        }
        /* WordPress checks it, not us. It knows every hash it has ever written. */
        $u = wp_authenticate($login, $pass);
        if (is_wp_error($u)) {
            sbk_app_tries(true);
            /* The same words whichever half was wrong, so this cannot be used to
               find out which e-mail addresses have accounts. */
            sbk_app_out(array('ok' => false,
                'error' => 'That e-mail and password do not match.'), 401);
        }
        sbk_app_out(array('ok' => true, 'token' => sbk_app_token($u->ID),
                          'user' => sbk_app_user_out($u)));
    }

    /* ------------------------------------------------------------- register */
    if ($do === 'register') {
        if (sbk_app_tries() >= SBK_APP_TRIES) {
            sbk_app_out(array('ok' => false,
                'error' => 'Too many attempts. Please wait a few minutes.'), 429);
        }
        $name  = sbk_app_field('name');
        $email = sbk_app_field('email');
        $phone = sbk_app_field('phone');
        $pass  = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if ($name === '' || $email === '' || $pass === '') {
            sbk_app_out(array('ok' => false, 'error' => 'Name, e-mail and password, please.'), 400);
        }
        if (!is_email($email)) {
            sbk_app_out(array('ok' => false, 'error' => 'That e-mail does not look right.'), 400);
        }
        if (strlen($pass) < 8) {
            sbk_app_out(array('ok' => false, 'error' => 'Use at least eight characters.'), 400);
        }
        if (email_exists($email)) {
            sbk_app_out(array('ok' => false,
                'error' => 'There is already an account with that e-mail. Try signing in.'), 409);
        }
        /* A customer, always. A role is something the desk gives somebody on the
           website; nobody hands themselves one through a sign-up form. */
        $id = wp_insert_user(array(
            'user_login'   => sbk_app_login_from($email),
            'user_pass'    => $pass,
            'user_email'   => $email,
            'display_name' => $name,
            'first_name'   => $name,
            'role'         => 'customer',
        ));
        if (is_wp_error($id)) {
            sbk_app_tries(true);
            sbk_app_out(array('ok' => false, 'error' => $id->get_error_message()), 400);
        }
        if ($phone !== '') {
            update_user_meta($id, 'billing_phone', $phone);
        }
        sbk_app_out(array('ok' => true, 'token' => sbk_app_token($id),
                          'user' => sbk_app_user_out(get_userdata($id))));
    }

    sbk_app_out(array('ok' => false, 'error' => 'Unknown request.'), 400);
}, 1);

/** A login name from an e-mail, made unique without telling anybody why. */
function sbk_app_login_from($email) {
    $base = sanitize_user(current(explode('@', $email)), true);
    if ($base === '') {
        $base = 'user';
    }
    $try = $base;
    $n = 1;
    while (username_exists($try)) {
        $try = $base . ++$n;
    }
    return $try;
}

/** Who a pass belongs to, or 0. Mirrors the chat's own check. */
function sbk_app_token_user($token) {
    $secret = sbk_app_secret();
    if ($secret === '' || !is_string($token)) {
        return 0;
    }
    $bits = explode('.', $token);
    if (count($bits) !== 4 || $bits[0] !== 'app') {
        return 0;
    }
    list($tag, $id, $exp, $sig) = $bits;
    if (!ctype_digit($id) || !ctype_digit($exp)) {
        return 0;
    }
    if (!hash_equals(hash_hmac('sha256', $tag . '.' . $id . '.' . $exp, $secret), $sig)) {
        return 0;
    }
    if ((int) $exp < time()) {
        return 0;
    }
    /* Gone from the website means gone from the app, on the very next request. */
    return get_userdata((int) $id) ? (int) $id : 0;
}
