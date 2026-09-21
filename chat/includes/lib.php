<?php
/**
 * SBK Chat — everything the pages and the API both need.
 *
 * WHO CAN BE HERE, AND WHERE THEY COME FROM.
 *
 * Everybody in this app is one of three things, and all three are decided by the
 * WordPress website at sbkautotrading.com:
 *
 *   STAFF     a WordPress user whose role is on the STAFF_ROLES list (or who has
 *             been ticked on by hand). They answer customers. They are the only
 *             people who appear in the list a customer chooses from.
 *   CUSTOMER  a WordPress user who is not staff — 59 of the website's 64
 *             accounts. Signed in on the website, they walk straight into the
 *             chat without being asked who they are, and they never appear in
 *             anybody's list of people to write to.
 *   VISITOR   somebody with no account at all: three boxes on the way in, and a
 *             cookie so they are the same person tomorrow.
 *
 * Nobody signs in HERE. Signing in happens on the website, once, and the website
 * hands this app a short signed line of text saying who arrived — see enter.php.
 * That is the owner's requirement in his own words: the chat belongs to the
 * website, an account is made on the website, and being signed in there is what
 * turns the light green here.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push.php';     // phones hear about messages with the app shut

/* ======================================================== the website's people

   Read out of WordPress, never copied into this app's tables. A person removed
   from the website is gone from the chat in the same second, which is the whole
   reason for reading it live rather than keeping a list of our own. */

/** Role key => the name the website shows for it. */
function wpRoleNames() {
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $names = array();
    $w = wpdb();
    if (!$w) {
        return $names;
    }
    $opt = WP_PREFIX . 'user_roles';
    $st = $w->prepare("SELECT option_value FROM " . WP_PREFIX . "options WHERE option_name = ? LIMIT 1");
    $st->bind_param('s', $opt);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    if ($row) {
        $all = @unserialize($row[0]);
        if (is_array($all)) {
            foreach ($all as $key => $r) {
                $names[$key] = (string) ($r['name'] ?? $key);
            }
        }
    }
    return $names;
}

/**
 * Is this person one of the desk?
 *
 * The role decides it, and then the tick box on their WordPress profile can
 * overrule the role either way. A job title is a rule of thumb; a person who
 * should or should not be in the list is a fact, and somebody has to be able to
 * say so without inventing a role for one person.
 *
 * @param array  $roles the role keys held
 * @param string $flag  'yes' | 'no' | '' from the sbk_chat_visible profile field
 */
function wpIsStaff(array $roles, $flag = '') {
    /* "Show in the chat = no" hides somebody from a CUSTOMER'S list. It must
       never take the desk away from them - that is a different question, and
       letting one tick box answer both is what sent the administrator to the
       three boxes as though he were a stranger (16 September 2026). The owner:
       "no matter who I log in as, it's asking the same thing. It should ask
       only when someone isn't logged in."
       So: a staff ROLE is the desk. `yes` can still let somebody in who has no
       staff role; `no` is not consulted here at all. */
    if ($flag === 'yes') { return true; }
    $allowed = array_map('trim', explode(',', STAFF_ROLES));
    foreach ($roles as $r) {
        if (in_array($r, $allowed, true)) {
            return true;
        }
    }
    return false;
}

/**
 * May a CUSTOMER see this person, and write to them?
 *
 * This is what the profile tick box is for, and the only thing it is for. The
 * `Admin` account is ticked off on purpose: an administrator reads every
 * conversation and should not also be sitting in the list of people to start a
 * new one with. He still has his desk — see wpIsStaff() above.
 *
 * @param string $flag  'yes' | 'no' | '' from sbk_chat_visible
 */
function wpIsListed(array $roles, $flag = '') {
    if ($flag === 'no')  { return false; }
    if ($flag === 'yes') { return true; }
    return wpIsStaff($roles, '');
}

/**
 * A person's REAL name, from wherever this shop happens to keep it — or ''.
 *
 * WooCommerce makes a customer's `display_name` out of their login, and it makes
 * the login out of the front of their e-mail address. So 53 of this site's 59
 * customers have a "name" that is half an e-mail — `jd3885325` — and that is
 * exactly what the desk was reading in bold at the top of every conversation.
 * The owner saw it and said the bold line must be a NAME.
 *
 * The real name is usually somewhere; it is just not in display_name. In order:
 *   1. first_name + last_name     — what the customer put on their own profile
 *   2. billing_first/last_name    — what they typed at checkout
 *   3. display_name               — only if it is NOT just their login or e-mail
 *
 * An empty string means there honestly is no name on file (36 of the 59), and
 * the caller decides what to do about that rather than this function
 * inventing one.
 */
function wpRealName($id, $login, $display, $email) {
    $w = wpdb();
    if (!$w) {
        return '';
    }
    $keys = array('first_name', 'last_name', 'billing_first_name', 'billing_last_name');
    $m = array_fill_keys($keys, '');
    $st = $w->prepare("SELECT meta_key, meta_value FROM " . WP_PREFIX . "usermeta
                        WHERE user_id = ? AND meta_key IN ('first_name','last_name',
                              'billing_first_name','billing_last_name')");
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_row()) {
        $m[$row[0]] = trim((string) $row[1]);
    }
    $st->close();

    $own  = trim($m['first_name'] . ' ' . $m['last_name']);
    $bill = trim($m['billing_first_name'] . ' ' . $m['billing_last_name']);
    if ($own !== '')  { return $own; }
    if ($bill !== '') { return $bill; }

    $d = trim((string) $display);
    if ($d !== '' && !looksLikeLogin($d, $login, $email)) {
        return $d;
    }
    return '';
}

/** Is this "name" really just somebody's login or the front of their e-mail? */
function looksLikeLogin($name, $login, $email) {
    $n = strtolower(trim((string) $name));
    if ($n === '' || strpos($n, '@') !== false) {
        return true;
    }
    $pre = strtolower((string) strstr((string) $email, '@', true));
    return ($n === strtolower((string) $login) || ($pre !== '' && $n === $pre));
}

/** One WordPress user, with their roles unpacked, or null. */
function wpUser($id) {
    static $cache = array();
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $w = wpdb();
    if (!$w) {
        return $cache[$id] = null;
    }
    $capsKey = WP_PREFIX . 'capabilities';
    $st = $w->prepare(
        "SELECT u.ID, u.user_login, u.display_name, u.user_email,
                c.meta_value AS caps, v.meta_value AS vis, p.meta_value AS phone
           FROM " . WP_PREFIX . "users u
      LEFT JOIN " . WP_PREFIX . "usermeta c ON c.user_id = u.ID AND c.meta_key = ?
      LEFT JOIN " . WP_PREFIX . "usermeta v ON v.user_id = u.ID AND v.meta_key = 'sbk_chat_visible'
      LEFT JOIN " . WP_PREFIX . "usermeta p ON p.user_id = u.ID AND p.meta_key = 'billing_phone'
          WHERE u.ID = ? LIMIT 1");
    $st->bind_param('si', $capsKey, $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return $cache[$id] = null;
    }

    $caps    = @unserialize((string) $row['caps']);
    $roles   = is_array($caps) ? array_keys(array_filter($caps)) : array();
    $names   = wpRoleNames();
    $isStaff  = wpIsStaff($roles, (string) $row['vis']);
    $isListed = wpIsListed($roles, (string) $row['vis']);
    $display = (string) ($row['display_name'] ?: $row['user_login']);

    /* Two rules, because the two kinds of person got their names differently.
       A member of STAFF was named on purpose by whoever made their account, and
       that name is also what the team list shows — so it is used as it stands,
       and the conversation header can never disagree with the list beside it.
       A CUSTOMER was named by WooCommerce out of their e-mail address, so the
       real name is looked for instead. */
    if ($isStaff) {
        $name    = $display;
        $hasName = true;
    } else {
        $real    = wpRealName((int) $row['ID'], $row['user_login'], $row['display_name'], $row['user_email']);
        $name    = $real !== '' ? $real : $display;
        $hasName = ($real !== '');
    }

    return $cache[$id] = array(
        'id'         => (int) $row['ID'],
        'username'   => (string) $row['user_login'],
        'name'       => $name,
        'has_name'   => $hasName,
        'email'      => (string) $row['user_email'],
        /* What the shop already knows. WooCommerce writes it at checkout and the
           app writes it at registration, so a customer who has given their
           number once is not asked for it again. */
        'phone'      => (string) ($row['phone'] ?? ''),
        'roles'      => $roles,
        'role'       => $roles[0] ?? '',
        'role_label' => isset($roles[0]) ? ($names[$roles[0]] ?? $roles[0]) : '',
        'is_staff'   => $isStaff,
        /* Whether a CUSTOMER may see them and write to them. Not the same thing
           as being the desk: see wpIsListed(). */
        'is_listed'  => $isListed,
        'is_admin'   => in_array('administrator', $roles, true),
    );
}

/* ================================================================ the staff */

/** The member of staff at this screen, or null. */
function staff() {
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (empty($_SESSION['chat_wp_staff'])) {
        return $cache = null;
    }
    $u = wpUser($_SESSION['chat_wp_staff']);

    /* Checked on every request, not once when they arrived. Somebody whose role
       is taken away on the website loses the desk on their very next poll, which
       is what "one set of accounts" has to mean if it is to mean anything. */
    if (!$u || !$u['is_staff']) {
        unset($_SESSION['chat_wp_staff']);
        return $cache = null;
    }
    return $cache = $u;
}

/**
 * May this member of staff read every conversation?
 *
 * The owner asked for "a user who can see all the chats". That is a WordPress
 * Administrator — there is no second idea of what an administrator is, and no
 * separate switch to forget to set.
 */
function staffSeesEverything() {
    $me = staff();
    return (bool) ($me && $me['is_admin']);
}

/**
 * The people a customer may write to.
 *
 * Staff and agents only. A customer cannot appear here whatever happens: the
 * list is built by ASKING for the staff roles, not by removing customers from
 * everybody, so a new kind of account that nobody told this code about is left
 * out rather than let in.
 *
 * Online first, then by name — somebody looking for help wants whoever can
 * answer now.
 */
function staffDirectory() {
    $w = wpdb();
    if (!$w) {
        return array();
    }

    $capsKey = WP_PREFIX . 'capabilities';
    $st = $w->prepare(
        "SELECT u.ID, u.user_login, u.display_name, u.user_email,
                c.meta_value AS caps, v.meta_value AS vis
           FROM " . WP_PREFIX . "users u
      LEFT JOIN " . WP_PREFIX . "usermeta c ON c.user_id = u.ID AND c.meta_key = ?
      LEFT JOIN " . WP_PREFIX . "usermeta v ON v.user_id = u.ID AND v.meta_key = 'sbk_chat_visible'
       ORDER BY u.display_name ASC");
    $st->bind_param('s', $capsKey);
    $st->execute();
    $res = $st->get_result();

    $names = wpRoleNames();
    $people = array();
    while ($row = $res->fetch_assoc()) {
        $caps  = @unserialize((string) $row['caps']);
        $roles = is_array($caps) ? array_keys(array_filter($caps)) : array();
        if (!wpIsListed($roles, (string) $row['vis'])) {
            continue;
        }
        $people[(int) $row['ID']] = array(
            'id'         => (int) $row['ID'],
            'name'       => (string) ($row['display_name'] ?: $row['user_login']),
            'username'   => (string) $row['user_login'],
            'role_label' => isset($roles[0]) ? ($names[$roles[0]] ?? $roles[0]) : '',
            'online'     => false,
        );
    }
    $st->close();
    if (!$people) {
        return array();
    }

    /* Presence lives in this app's own database, not the website's, so it is a
       second question rather than a join. One query for everybody, not one per
       person. */
    $ids = implode(',', array_map('intval', array_keys($people)));
    $res = db()->query(
        "SELECT who_id FROM chat_presence
          WHERE who = 'staff' AND who_id IN ($ids)
            AND last_ping > NOW() - INTERVAL " . ONLINE_SECONDS . " SECOND");
    while ($res && $row = $res->fetch_row()) {
        if (isset($people[(int) $row[0]])) {
            $people[(int) $row[0]]['online'] = true;
        }
    }

    $out = array_values($people);
    usort($out, function ($a, $b) {
        if ($a['online'] !== $b['online']) { return $a['online'] ? -1 : 1; }
        return strcasecmp($a['name'], $b['name']);
    });
    return $out;
}

/** Staff names for a list of ids, in one question. */
function staffNames(array $ids) {
    $out = array();
    $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
    if (!$ids) {
        return $out;
    }
    $w = wpdb();
    if (!$w) {
        return $out;
    }
    $in  = implode(',', $ids);
    $res = $w->query("SELECT ID, user_login, display_name
                        FROM " . WP_PREFIX . "users WHERE ID IN ($in)");
    while ($res && $row = $res->fetch_assoc()) {
        $out[(int) $row['ID']] = (string) ($row['display_name'] ?: $row['user_login']);
    }
    return $out;
}

/* ============================================================== the customer

   Two ways in, one row in `chat_guests` either way, so everything downstream —
   threads, messages, ticks, media — has one kind of person to deal with.

   1. Signed in on the website. `chat_guests.wp_user_id` holds their WordPress
      id and their name, e-mail and telephone are taken from their account.
      Nobody is asked to type what the website already knows.
   2. Not signed in at all. Three boxes, and a token in a cookie. */

/** The customer or visitor at this screen, or null. */
function guest() {
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }

    /* A signed-in website customer first: an account beats a cookie, because a
       person who has signed in has told us who they are on purpose. */
    if (!empty($_SESSION['chat_wp_guest'])) {
        $st = db()->prepare("SELECT * FROM chat_guests WHERE wp_user_id = ? LIMIT 1");
        $st->bind_param('i', $_SESSION['chat_wp_guest']);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            return $cache = $row;
        }
        unset($_SESSION['chat_wp_guest']);
    }

    $tok = (string) ($_COOKIE['sbk_guest'] ?? '');
    if (!preg_match('/^[a-f0-9]{40}$/', $tok)) {
        return $cache = null;
    }
    $st = db()->prepare("SELECT * FROM chat_guests WHERE token = ? LIMIT 1");
    $st->bind_param('s', $tok);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $cache = ($row ?: null);
}

/**
 * The chat's row for a customer who is signed in to the website.
 *
 * Matched on the WordPress id first and the e-mail second, so somebody who
 * talked to the desk as a stranger last week and has since made an account
 * keeps the conversation they already had instead of starting a second one.
 */
function guestForWpUser(array $u) {
    $wpId  = (int) $u['id'];
    $name  = trim((string) $u['name']);
    $email = strtolower(trim((string) $u['email']));

    $st = db()->prepare("SELECT * FROM chat_guests WHERE wp_user_id = ? LIMIT 1");
    $st->bind_param('i', $wpId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$row && $email !== '') {
        $st = db()->prepare("SELECT * FROM chat_guests
                              WHERE email = ? AND (wp_user_id IS NULL OR wp_user_id = 0)
                           ORDER BY id DESC LIMIT 1");
        $st->bind_param('s', $email);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
    }

    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    /* The website has no real name for this person, but they may have TOLD the
       chat theirs (see api/name.php). That typed name must survive: this runs on
       every heartbeat, and without this line the next beat would overwrite
       "Kenji Watanabe" with "kenji.w1987" ten seconds after they typed it.

       The same holds when the website DOES have a "name" but it is itself only
       the login or the front of the e-mail - a first name of "ztest.fcust" is
       still not a name. Found 19 September 2026: such a customer was asked
       "What's your name?", answered, and had the answer undone by the very next
       request, so the card never went away (the app signs in on every request,
       which is what made it show). A real name typed into the chat beats a
       login-shaped one from the website; a real name on the website still wins. */
    if ($row && !looksLikeLogin($row['name'], $u['username'] ?? '', $email)
        && (empty($u['has_name']) || looksLikeLogin($name, $u['username'] ?? '', $email))) {
        $name = (string) $row['name'];
    }

    /* The telephone number, if the chat has not got a usable one and the
       website has. Never the other way round: a number typed INTO the chat is
       the more recent thing the person said, and this runs on every heartbeat. */
    $wpPhone = preg_replace('/\D+/', '', (string) ($u['phone'] ?? ''));
    $havePhone = $row ? preg_replace('/\D+/', '', (string) $row['phone']) : '';
    $phone = (strlen($havePhone) < 7 && strlen($wpPhone) >= 7)
        ? trim((string) $u['phone'])
        : ($row ? (string) $row['phone'] : '');

    if ($row) {
        $st = db()->prepare("UPDATE chat_guests
                                SET wp_user_id = ?, name = ?, email = ?, phone = ?,
                                    ip = ?, user_agent = ?, last_seen = NOW()
                              WHERE id = ?");
        $st->bind_param('isssssi', $wpId, $name, $email, $phone, $ip, $ua, $row['id']);
        $st->execute();
        $st->close();
        $row['wp_user_id'] = $wpId;
        $row['name']       = $name;
        $row['email']      = $email;
        $row['phone']      = $phone;
    } else {
        $tok = bin2hex(random_bytes(20));
        $st = db()->prepare("INSERT INTO chat_guests
                                (token, wp_user_id, name, email, phone, ip, user_agent, last_seen)
                             VALUES (?,?,?,?,?,?,?,NOW())");
        $st->bind_param('sisssss', $tok, $wpId, $name, $email, $phone, $ip, $ua);
        $st->execute();
        $id = $st->insert_id;
        $st->close();
        $row = array('id' => $id, 'token' => $tok, 'wp_user_id' => $wpId,
                     'name' => $name, 'email' => $email, 'phone' => $phone);
    }

    $_SESSION['chat_wp_guest'] = $wpId;
    return $row;
}

/**
 * Take the three boxes and let a stranger in.
 *
 * An e-mail seen before is treated as the same person rather than a new one.
 * Somebody who clears their cookies and comes back is still the customer the
 * desk was talking to yesterday, and starting a fresh thread would lose that
 * conversation in front of them.
 *
 * @return array the guest row
 */
function guestSignIn($name, $email, $phone) {
    $name  = trim(preg_replace('/\s+/u', ' ', (string) $name));
    $email = strtolower(trim((string) $email));
    $phone = trim((string) $phone);

    $st = db()->prepare("SELECT * FROM chat_guests WHERE email = ? ORDER BY id DESC LIMIT 1");
    $st->bind_param('s', $email);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    if ($row) {
        $st = db()->prepare("UPDATE chat_guests
                                SET name = ?, phone = ?, ip = ?, user_agent = ?, last_seen = NOW()
                              WHERE id = ?");
        $st->bind_param('ssssi', $name, $phone, $ip, $ua, $row['id']);
        $st->execute();
        $st->close();
        $row['name']  = $name;
        $row['phone'] = $phone;
    } else {
        $tok = bin2hex(random_bytes(20));
        $st = db()->prepare("INSERT INTO chat_guests (token, name, email, phone, ip, user_agent, last_seen)
                             VALUES (?,?,?,?,?,?,NOW())");
        $st->bind_param('ssssss', $tok, $name, $email, $phone, $ip, $ua);
        $st->execute();
        $id = $st->insert_id;
        $st->close();
        $row = array('id' => $id, 'token' => $tok, 'name' => $name,
                     'email' => $email, 'phone' => $phone);
    }

    /* A year, because a customer who comes back next month should not have to
       introduce themselves again. Set on `.sbkautotrading.com` rather than on
       this subdomain alone, so the website can read the unread count without
       the cookie being loosened to SameSite=None. */
    forgetHostOnlyGuestCookie();

    setcookie('sbk_guest', $row['token'], array(
        'expires'  => time() + 31536000,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    return $row;
}

/**
 * Must this customer still give their name, e-mail and telephone?
 *
 * The owner's rule, said again on 14 September 2026: nobody reaches the desk
 * without all three - "name, email, phone number", the way the chat began. A
 * stranger gives them on index.php and cannot get in without them. A customer
 * signed in on the WEBSITE arrived with only the e-mail on their account and was
 * asked for a name alone, in a box they could skip (11 September) - so anybody who
 * registered on the shop walked straight in with no telephone, which is exactly
 * what the owner found. Such a customer now fills the same three boxes once, the
 * e-mail taken from their account, before the chat opens.
 *
 * Only a row that came from a website account can be incomplete: the three boxes
 * already refuse a stranger who leaves one out.
 */
function guestNeedsDetails($g) {
    if (!$g || empty($g['wp_user_id'])) {
        return false;
    }
    $name = trim((string) ($g['name'] ?? ''));
    return strlen(preg_replace('/\D+/', '', (string) ($g['phone'] ?? ''))) < 7
        || mb_strlen($name) < 2 || strpos($name, '@') !== false;
}

/** A signed-in customer's answers from the three boxes: their name and telephone. */
function guestSaveDetails($id, $name, $phone) {
    $id    = (int) $id;
    $name  = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $name)), 0, 120);
    $phone = mb_substr(trim((string) $phone), 0, 40);
    $st = db()->prepare("UPDATE chat_guests SET name = ?, phone = ?, last_seen = NOW() WHERE id = ?");
    $st->bind_param('ssi', $name, $phone, $id);
    $st->execute();
    $st->close();
}

/**
 * The API's answer to a customer who has not given all three yet.
 *
 * The same "signed out" every screen of the chat already obeys by going to
 * index.php - which is where the three boxes are - so a window left open, or a
 * request made without the page at all, gets nothing from the desk until they
 * are filled in.
 */
function refuseUntilDetails($g, $s) {
    if ($g && !$s && guestNeedsDetails($g)) {
        jsonOut(array('ok' => false, 'signedout' => true, 'details' => true), 401);
    }
}

/**
 * Kill any `sbk_guest` cookie left on the SUBDOMAIN alone.
 *
 * A cookie on `chat.sbkautotrading.com` and a cookie on `.sbkautotrading.com`
 * are two different cookies even though they share a name. A browser holding
 * both sends both, in one header, and PHP keeps whichever it parses last — so a
 * dead token from an older build can beat the one just issued, and the person is
 * bounced back to the sign-in screen for ever with nothing on the screen to
 * explain why.
 *
 * That is not hypothetical. This app set the cookie on the subdomain alone
 * before the website needed to read the unread count, and every browser that
 * visited in that hour was locked out afterwards — found on 2026-09-10 by
 * signing in through a real browser and watching a good sign-in bounce straight
 * back to the form while the identical request from a fresh client worked.
 *
 * One extra header, harmless for a browser that never had one, and it goes out
 * BEFORE the real cookie so that nothing can undo it.
 */
function forgetHostOnlyGuestCookie() {
    if (COOKIE_DOMAIN === '') {
        return;                     // already host-only; there is nothing to clear
    }
    setcookie('sbk_guest', '', array(
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}

/* ========================================================== the handover ====

   The website's login cookie is set on sbkautotrading.com alone — its
   COOKIE_DOMAIN is empty — so it never reaches this subdomain and could not be
   read here even if that were wise. Widening it would sign out all 41 people
   who are currently logged in to a live shop, to solve a problem that has a
   smaller answer.

   The smaller answer: the website writes one line saying who is arriving and
   signs it with a secret only these two programs know. It is good for five
   minutes and is spent the moment it is used. Nothing about WordPress's own
   login changes, and a stolen link is worthless within the length of a coffee
   break. */

/** Make a handover line for a WordPress user id (used by the mu-plugin's twin). */
function handoverFor($wpUserId) {
    $body = $wpUserId . '.' . (time() + LINK_SECONDS);
    return $body . '.' . hash_hmac('sha256', $body, LINK_SECRET);
}

/**
 * Check one, and say whose it is.
 *
 * @return int the WordPress user id, or 0 if it is not good
 */
function handoverUser($token) {
    if (LINK_SECRET === '' || !is_string($token)) {
        return 0;
    }
    $bits = explode('.', $token);
    if (count($bits) !== 3) {
        return 0;
    }
    list($id, $exp, $sig) = $bits;
    if (!ctype_digit($id) || !ctype_digit($exp)) {
        return 0;
    }
    // hash_equals, not ==, so the comparison cannot be timed.
    if (!hash_equals(hash_hmac('sha256', $id . '.' . $exp, LINK_SECRET), $sig)) {
        return 0;
    }
    if ((int) $exp < time()) {
        return 0;
    }
    return (int) $id;
}

/* ================================================================= presence

   Online means signed in to the WEBSITE — the owner's rule, not "has the chat
   open". The website's own pages send the beat, so a member of staff reading
   the blog is as green as one staring at the chat. */

function touchPresence($who, $id, $where = 'chat') {
    $st = db()->prepare("INSERT INTO chat_presence (who, who_id, last_ping, where_at)
                         VALUES (?,?,NOW(),?)
                         ON DUPLICATE KEY UPDATE last_ping = NOW(), where_at = VALUES(where_at)");
    $st->bind_param('sis', $who, $id, $where);
    $st->execute();
    $st->close();
}

/** Two initials for the round avatar, so every face is drawn without a file. */
function initials($name) {
    $parts = preg_split('/\s+/u', trim((string) $name));
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = (count($parts) > 1) ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($a . $b) ?: '?';
}

/** A stable colour per person, so the same face is the same colour every time. */
function avatarHue($seed) {
    return (int) (hexdec(substr(md5((string) $seed), 0, 4)) % 360);
}

/* ================================================================== threads */

/** The conversation between this customer and this member of staff, made if new. */
function threadFor($guestId, $staffId) {
    $st = db()->prepare("SELECT * FROM chat_threads WHERE guest_id = ? AND staff_id = ? LIMIT 1");
    $st->bind_param('ii', $guestId, $staffId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if ($row) {
        return $row;
    }
    $st = db()->prepare("INSERT INTO chat_threads (guest_id, staff_id) VALUES (?,?)");
    $st->bind_param('ii', $guestId, $staffId);
    $st->execute();
    $id = $st->insert_id;
    $st->close();
    return array('id' => $id, 'guest_id' => $guestId, 'staff_id' => $staffId,
                 'guest_unread' => 0, 'staff_unread' => 0);
}

/**
 * May whoever is asking read this thread?
 *
 * Every endpoint calls this before it answers, and it is the only place the
 * rule lives: the customer whose thread it is, the member of staff it was sent
 * to, or a WordPress administrator. Nobody else, ever.
 *
 * @return array|null the thread row, or null
 */
function mayReadThread($threadId) {
    $st = db()->prepare("SELECT * FROM chat_threads WHERE id = ? LIMIT 1");
    $st->bind_param('i', $threadId);
    $st->execute();
    $t = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$t) {
        return null;
    }
    $g = guest();
    if ($g && (int) $t['guest_id'] === (int) $g['id']) {
        return $t;
    }
    $s = staff();
    if ($s && ((int) $t['staff_id'] === (int) $s['id'] || staffSeesEverything())) {
        return $t;
    }
    return null;
}

/** What a thread list shows instead of the message itself. */
function previewOf($kind, $body) {
    if ($kind === 'image') { return '📷 Photo'; }
    if ($kind === 'voice') { return '🎤 Voice note'; }
    if ($kind === 'file')  { return '📎 File'; }
    if ($kind === 'call') {
        // body is "voice|ended|192" — kind of call, how it ended, seconds.
        $b = explode('|', (string) $body);
        $what = (($b[0] ?? '') === 'video') ? 'Video call' : 'Voice call';
        if (($b[1] ?? '') === 'missed')   { return '📞 Missed ' . strtolower($what); }
        if (($b[1] ?? '') === 'declined') { return '📞 ' . $what . ' declined'; }
        return '📞 ' . $what;
    }
    return mb_substr((string) $body, 0, 150);
}

/**
 * Write a message and move the thread with it.
 *
 * The preview and the unread count are kept ON the thread rather than counted
 * from the messages every time a list is drawn: a desk with a thousand threads
 * should cost one query, not a thousand.
 */
function addMessage($threadId, $fromGuest, $staffId, $kind, $body,
                    $mediaPath = null, $mediaMime = null, $mediaBytes = null, $mediaSecs = null,
                    $bumpUnread = true) {
    $st = db()->prepare("INSERT INTO chat_messages
        (thread_id, from_guest, staff_id, kind, body, media_path, media_mime, media_bytes, media_secs)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $fg = $fromGuest ? 1 : 0;
    $st->bind_param('iiissssii', $threadId, $fg, $staffId, $kind, $body,
                    $mediaPath, $mediaMime, $mediaBytes, $mediaSecs);
    $st->execute();
    $id = $st->insert_id;
    $st->close();

    $preview = previewOf($kind, $body);
    $col = $fromGuest ? 'staff_unread' : 'guest_unread';
    /* A call that was answered is not news to the person who answered it — only
       a MISSED call should light their bell. So the log line of a call may be
       written without counting as unread. */
    $inc = $bumpUnread ? 1 : 0;
    $st = db()->prepare("UPDATE chat_threads
                            SET last_message_at = NOW(), last_preview = ?, $col = $col + $inc
                          WHERE id = ?");
    $st->bind_param('si', $preview, $threadId);
    $st->execute();
    $st->close();

    /* The other person's phone, if the app is shut (includes/push.php). Sent
       after the answer has gone back, and never allowed to stop a message. */
    try {
        pushThreadMessage((int) $threadId, (bool) $fromGuest, $kind, $body, $id, $mediaSecs);
    } catch (\Throwable $e) {
        pushLog('thread push not queued: ' . $e->getMessage());
    }
    return $id;
}

/* ================================================================== calls

   Voice and video, browser to browser. What lives here is only the handshake:
   the caller's offer, the answer, and the state of the call. The media never
   touches this server, so a call — however long, however many — costs it
   nothing more than the polling it already does.

   Who may be in a call is exactly who may be in the conversation it belongs to:
   the customer, and the member of staff that conversation is WITH. An
   administrator who can read every conversation can still not ring into, or
   pick up, somebody else's call. */

function callRow($callId) {
    $st = db()->prepare("SELECT c.*, t.guest_id, t.staff_id
                           FROM chat_calls c JOIN chat_threads t ON t.id = c.thread_id
                          WHERE c.id = ? LIMIT 1");
    $st->bind_param('i', $callId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Is this viewer one of the two people in this call? */
function isCallParty(array $call, $viewerIsGuest, $meId) {
    return $viewerIsGuest ? ((int) $call['guest_id'] === (int) $meId)
                          : ((int) $call['staff_id'] === (int) $meId);
}

/** Did the viewer RECEIVE this call (rather than make it)? */
function callIsIncoming(array $call, $viewerIsGuest) {
    return ((int) $call['from_guest'] === 1) !== (bool) $viewerIsGuest;
}

/**
 * Close a call, once, and leave a line in the conversation saying so.
 *
 * The line is written from the CALLER's side, like any message they sent, so it
 * sits on their side of the conversation. Only a missed call counts as unread
 * for the other person; an answered one they already know about.
 */
function finishCall(array $call, $state) {
    $st = db()->prepare("UPDATE chat_calls SET state = ?, ended_at = NOW()
                          WHERE id = ? AND state IN ('ringing','accepted')");
    $st->bind_param('si', $state, $call['id']);
    $st->execute();
    $changed = $st->affected_rows;
    $st->close();
    if ($changed < 1) {
        return false;      // somebody else closed it first; one log line per call
    }

    $secs = 0;
    if ($state === 'ended' && !empty($call['answered_at'])) {
        $secs = max(0, time() - strtotime($call['answered_at'] . ' UTC'));
    }
    $fromGuest = ((int) $call['from_guest'] === 1);
    $body = $call['kind'] . '|' . $state . '|' . $secs;
    addMessage((int) $call['thread_id'], $fromGuest,
               $fromGuest ? null : (int) $call['staff_id'],
               'call', $body, null, null, null, null,
               $state === 'missed');

    // The ringing on the other person's phone becomes "Missed call", or goes away.
    try {
        pushCallOver($call, $state);
    } catch (\Throwable $e) {
        pushLog('call-over push not queued: ' . $e->getMessage());
    }
    return true;
}

/**
 * Tidy the calls that have ended without anybody saying so.
 *
 * Ringing too long is a missed call. Answered, but one side's browser has
 * stopped asking about it for CALL_STALE seconds, is over — a laptop lid closed
 * mid-call does not hang up, and the other person must not sit listening to
 * silence for ever. Run from every poll, and costs one indexed query.
 */
function sweepCalls() {
    $res = db()->query(
        "SELECT c.*, t.guest_id, t.staff_id
           FROM chat_calls c JOIN chat_threads t ON t.id = c.thread_id
          WHERE (c.state = 'ringing'  AND c.created_at < NOW() - INTERVAL " . RING_SECONDS . " SECOND)
             OR (c.state = 'accepted' AND (
                    COALESCE(c.guest_seen, c.answered_at) < NOW() - INTERVAL " . CALL_STALE . " SECOND
                 OR COALESCE(c.staff_seen, c.answered_at) < NOW() - INTERVAL " . CALL_STALE . " SECOND))
          LIMIT 20");
    while ($res && $c = $res->fetch_assoc()) {
        finishCall($c, $c['state'] === 'ringing' ? 'missed' : 'ended');
    }
}

/** The call this person is in, or being rung by, right now — or null. */
function liveCallFor($viewerIsGuest, $meId) {
    $col = $viewerIsGuest ? 't.guest_id' : 't.staff_id';
    $st = db()->prepare("SELECT c.*, t.guest_id, t.staff_id
                           FROM chat_calls c JOIN chat_threads t ON t.id = c.thread_id
                          WHERE $col = ? AND c.state IN ('ringing','accepted')
                       ORDER BY c.id DESC LIMIT 1");
    $st->bind_param('i', $meId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** Stamp that this side's browser is still here, for the stale check. */
function touchCall(array $call, $viewerIsGuest) {
    $col = $viewerIsGuest ? 'guest_seen' : 'staff_seen';
    $st = db()->prepare("UPDATE chat_calls SET $col = NOW() WHERE id = ?");
    $st->bind_param('i', $call['id']);
    $st->execute();
    $st->close();
}

/** The name of the other person in a call, as the viewer should see it. */
function callPeerName(array $call, $viewerIsGuest) {
    if ($viewerIsGuest) {
        $u = wpUser((int) $call['staff_id']);
        return $u ? $u['name'] : 'SBK';
    }
    $st = db()->prepare("SELECT name FROM chat_guests WHERE id = ? LIMIT 1");
    $st->bind_param('i', $call['guest_id']);
    $st->execute();
    $row = $st->get_result()->fetch_row();
    $st->close();
    return $row ? (string) $row[0] : 'Customer';
}

/** A call, shaped for the viewer. Never carries the session descriptions. */
function callOut(array $call, $viewerIsGuest) {
    return array(
        'id'       => (int) $call['id'],
        'thread'   => (int) $call['thread_id'],
        'kind'     => $call['kind'],
        'state'    => $call['state'],
        'incoming' => callIsIncoming($call, $viewerIsGuest),
        'peer'     => callPeerName($call, $viewerIsGuest),
        'peerId'   => $viewerIsGuest ? (int) $call['staff_id'] : (int) $call['guest_id'],
    );
}

/** One message, shaped for the browser. */
function messageOut($m, $viewerIsGuest) {
    return array(
        'id'        => (int) $m['id'],
        'thread'    => (int) $m['thread_id'],
        'mine'      => ($viewerIsGuest === ((int) $m['from_guest'] === 1)),
        'kind'      => $m['kind'],
        'body'      => (string) $m['body'],
        'media'     => $m['media_path'] ? ('media.php?m=' . (int) $m['id']) : null,
        'mime'      => $m['media_mime'],
        'secs'      => $m['media_secs'] !== null ? (int) $m['media_secs'] : null,
        'at'        => $m['created_at'],
        'delivered' => !empty($m['delivered_at']),
        'read'      => !empty($m['read_at']),
    );
}

/**
 * Everything in a thread after `$after`.
 *
 * Fetching is also what marks a message delivered — the second tick means "it
 * reached them", and this is the moment it did.
 */
function messagesSince($threadId, $after, $viewerIsGuest, $limit = 300) {
    $out = array();
    $st = db()->prepare("SELECT * FROM chat_messages
                          WHERE thread_id = ? AND id > ?
                       ORDER BY id ASC LIMIT " . (int) $limit);
    $st->bind_param('ii', $threadId, $after);
    $st->execute();
    $res = $st->get_result();
    while ($m = $res->fetch_assoc()) {
        $out[] = messageOut($m, $viewerIsGuest);
    }
    $st->close();

    // The other side's messages have now arrived on this screen.
    $theirs = $viewerIsGuest ? 0 : 1;
    $st = db()->prepare("UPDATE chat_messages SET delivered_at = NOW()
                          WHERE thread_id = ? AND from_guest = ? AND delivered_at IS NULL");
    $st->bind_param('ii', $threadId, $theirs);
    $st->execute();
    $st->close();
    return $out;
}

/** The reader has the thread open, so what they can see is read. */
function markRead($threadId, $viewerIsGuest) {
    $theirs = $viewerIsGuest ? 0 : 1;
    $st = db()->prepare("UPDATE chat_messages
                            SET read_at = NOW(), delivered_at = COALESCE(delivered_at, NOW())
                          WHERE thread_id = ? AND from_guest = ? AND read_at IS NULL");
    $st->bind_param('ii', $threadId, $theirs);
    $st->execute();
    $st->close();

    $col = $viewerIsGuest ? 'guest_unread' : 'staff_unread';
    $st = db()->prepare("UPDATE chat_threads SET $col = 0 WHERE id = ?");
    $st->bind_param('i', $threadId);
    $st->execute();
    $st->close();
}

/**
 * The threads on a member of staff's screen.
 *
 * `$all` is for a WordPress administrator: every conversation in the building,
 * with the name of the member of staff it belongs to beside each one.
 *
 * The staff NAMES come from the website's database, which is a different
 * connection, so they are fetched in one go afterwards rather than joined.
 */
function staffThreads($staffId, $all = false) {
    $sql = "SELECT t.*, g.name AS guest_name, g.email AS guest_email, g.phone AS guest_phone,
                   (gp.last_ping IS NOT NULL
                    AND gp.last_ping > NOW() - INTERVAL " . ONLINE_SECONDS . " SECOND) AS guest_online
              FROM chat_threads t
              JOIN chat_guests g ON g.id = t.guest_id
         LEFT JOIN chat_presence gp ON gp.who = 'guest' AND gp.who_id = t.guest_id";
    if (!$all) {
        $sql .= " WHERE t.staff_id = " . (int) $staffId;
    }
    $sql .= " ORDER BY t.last_message_at IS NULL, t.last_message_at DESC, t.id DESC LIMIT 300";

    $out = array();
    $res = db()->query($sql);
    while ($res && $row = $res->fetch_assoc()) {
        $row['guest_online'] = ((int) $row['guest_online'] === 1);
        $out[] = $row;
    }
    if ($all && $out) {
        $names = staffNames(array_column($out, 'staff_id'));
        foreach ($out as &$row) {
            $row['staff_name'] = $names[(int) $row['staff_id']] ?? '';
        }
        unset($row);
    }
    return $out;
}

/**
 * The newest message waiting for this person, for the pop-up on the website.
 *
 * A number on a bell says SOMETHING happened. The owner asked for what WhatsApp
 * does instead — a small card that says who wrote and what they said — and that
 * needs the message itself, which is why this exists beside unreadFor().
 *
 * The newest one only. Three messages arriving together should be one card
 * showing the last of them, not three cards stacked up the side of a page
 * somebody is trying to read.
 *
 * @return array|null  {id, thread, from, text}
 */
function latestWaiting($who, $id) {
    if ($who === 'staff') {
        $st = db()->prepare(
            "SELECT m.id, m.thread_id, m.kind, m.body, g.name AS who
               FROM chat_messages m
               JOIN chat_threads t ON t.id = m.thread_id
               JOIN chat_guests  g ON g.id = t.guest_id
              WHERE t.staff_id = ? AND m.from_guest = 1 AND m.read_at IS NULL
           ORDER BY m.id DESC LIMIT 1");
    } else {
        $st = db()->prepare(
            "SELECT m.id, m.thread_id, m.kind, m.body, m.staff_id AS who
               FROM chat_messages m
               JOIN chat_threads t ON t.id = m.thread_id
              WHERE t.guest_id = ? AND m.from_guest = 0 AND m.read_at IS NULL
           ORDER BY m.id DESC LIMIT 1");
    }
    $st->bind_param('i', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) {
        return null;
    }

    /* A customer's card names the member of staff, and that name lives in the
       website's database rather than ours. */
    $from = (string) $row['who'];
    if ($who !== 'staff') {
        $u = wpUser((int) $row['who']);
        $from = $u ? $u['name'] : 'SBK';
    }

    return array(
        'id'     => (int) $row['id'],
        'thread' => (int) $row['thread_id'],
        'from'   => $from,
        'text'   => previewOf($row['kind'], $row['body']),
    );
}

/** How many messages are waiting for this person, for the bell. */
function unreadFor($who, $id) {
    if ($who === 'staff') {
        $st = db()->prepare("SELECT COALESCE(SUM(staff_unread),0) FROM chat_threads WHERE staff_id = ?");
    } else {
        $st = db()->prepare("SELECT COALESCE(SUM(guest_unread),0) FROM chat_threads WHERE guest_id = ?");
    }
    $st->bind_param('i', $id);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
}

/** Only what a browser should be handed back. */
function jsonOut($v, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($v);
    exit;
}

/* ================================================================== groups

   The owner's ask, 2026-09-16: "we also need to add a grouping feature inside
   it so we can create groups... like how groups are made on WhatsApp".

   A group is the desk talking to itself: every member is a member of STAFF. A
   customer is never in one and never sees one - their side of the chat is
   untouched by everything below.

   WHERE A GROUP MESSAGE LIVES. In `chat_messages`, like every other message,
   with `thread_id = 0` and `group_id` set. That looks like a sentinel because
   it is one, and it is safe for a reason worth writing down: every query the
   rest of this file runs against that table filters on a real `thread_id`, so
   none of them can see a group row. In exchange groups inherit, for nothing,
   the parts that were expensive to get right - the upload path, the
   re-encoding, the two directory levels, media.php's door, the bubbles.

   WHAT IS KEPT ON THE GROUP rather than counted: the last line and when it was
   written (for the list) and, per member, what is waiting and how far they have
   read. A desk with a hundred groups should cost one query to draw. */

/** One group, or null. */
function groupRow($id) {
    $st = db()->prepare("SELECT * FROM chat_groups WHERE id = ? LIMIT 1");
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return $r ?: null;
}

/** Is this member of staff in this group? */
function inGroup($groupId, $staffId) {
    $st = db()->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND staff_id = ? LIMIT 1");
    $st->bind_param('ii', $groupId, $staffId);
    $st->execute();
    $yes = (bool) $st->get_result()->fetch_row();
    $st->close();
    return $yes;
}

/**
 * The group this request is allowed to touch, or null.
 *
 * Membership, and nothing else. An administrator may read every CONVERSATION -
 * that is the desk's own work and the owner asked to see it - but a group is a
 * room people were put in, and being an administrator is not an invitation.
 */
function mayReadGroup($groupId) {
    $s = staff();
    if (!$s || $groupId <= 0) {
        return null;
    }
    $g = groupRow($groupId);
    if (!$g || !inGroup($groupId, (int) $s['id'])) {
        return null;
    }
    return $g;
}

/** Whoever owns the group may rename it and change who is in it. */
function ownsGroup($groupId, $staffId) {
    $st = db()->prepare("SELECT is_owner FROM chat_group_members
                          WHERE group_id = ? AND staff_id = ? LIMIT 1");
    $st->bind_param('ii', $groupId, $staffId);
    $st->execute();
    $r = $st->get_result()->fetch_row();
    $st->close();
    return $r ? ((int) $r[0] === 1) : false;
}

/** The groups this member of staff is in, newest first. */
function groupsFor($staffId) {
    $st = db()->prepare(
        "SELECT g.id, g.name, g.created_by, g.last_message_at, g.last_preview,
                m.unread, m.is_owner,
                (SELECT COUNT(*) FROM chat_group_members x WHERE x.group_id = g.id) AS members
           FROM chat_groups g
           JOIN chat_group_members m ON m.group_id = g.id AND m.staff_id = ?
       ORDER BY g.last_message_at IS NULL, g.last_message_at DESC, g.id DESC
          LIMIT 200");
    $st->bind_param('i', $staffId);
    $st->execute();
    $out = array();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $out[] = $row;
    }
    $st->close();
    return $out;
}

/** Who is in a group - the owner first, then in joining order. */
function groupMemberIds($groupId) {
    $st = db()->prepare("SELECT staff_id FROM chat_group_members
                          WHERE group_id = ? ORDER BY is_owner DESC, joined_at ASC, staff_id ASC");
    $st->bind_param('i', $groupId);
    $st->execute();
    $ids = array();
    $r = $st->get_result();
    while ($row = $r->fetch_row()) {
        $ids[] = (int) $row[0];
    }
    $st->close();
    return $ids;
}

/**
 * Make a group.
 *
 * Whoever makes it is in it and owns it, whatever they passed. Everybody else
 * must be staff ON THE WEBSITE RIGHT NOW - the same question send.php asks
 * before letting a customer write to somebody - so a made-up id, a customer's
 * id, or somebody whose role was taken away this morning cannot be put in a
 * room where the desk talks.
 *
 * @return array  {id, skipped}
 */
function createGroup($name, $ownerId, array $memberIds) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    $name = mb_substr($name === '' ? 'New group' : $name, 0, 120);
    $ownerId = (int) $ownerId;

    $st = db()->prepare("INSERT INTO chat_groups (name, created_by) VALUES (?,?)");
    $st->bind_param('si', $name, $ownerId);
    $st->execute();
    $gid = $st->insert_id;
    $st->close();

    $st = db()->prepare("INSERT IGNORE INTO chat_group_members (group_id, staff_id, is_owner)
                         VALUES (?,?,1)");
    $st->bind_param('ii', $gid, $ownerId);
    $st->execute();
    $st->close();

    $skipped = 0;
    foreach (array_unique(array_map('intval', $memberIds)) as $id) {
        if ($id <= 0 || $id === $ownerId) {
            continue;
        }
        $who = wpUser($id);
        if (!$who || !$who['is_staff']) {
            $skipped++;
            continue;
        }
        $st = db()->prepare("INSERT IGNORE INTO chat_group_members (group_id, staff_id) VALUES (?,?)");
        $st->bind_param('ii', $gid, $id);
        $st->execute();
        $st->close();
    }
    return array('id' => (int) $gid, 'skipped' => $skipped);
}

/** Rename a group. The owner only - checked by the caller. */
function renameGroup($groupId, $name) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') {
        return false;
    }
    $name = mb_substr($name, 0, 120);
    $st = db()->prepare("UPDATE chat_groups SET name = ? WHERE id = ?");
    $st->bind_param('si', $name, $groupId);
    $st->execute();
    $st->close();
    return true;
}

/** Put more people in. Staff only, the same question createGroup asks. */
function addGroupMembers($groupId, array $ids) {
    $added = 0;
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if ($id <= 0 || inGroup($groupId, $id)) {
            continue;
        }
        $who = wpUser($id);
        if (!$who || !$who['is_staff']) {
            continue;
        }
        $st = db()->prepare("INSERT IGNORE INTO chat_group_members (group_id, staff_id) VALUES (?,?)");
        $st->bind_param('ii', $groupId, $id);
        $st->execute();
        $added += ($st->affected_rows > 0) ? 1 : 0;
        $st->close();
    }
    return $added;
}

/**
 * Take somebody out - or let them walk out themselves.
 *
 * If the person leaving OWNED the group it is handed to whoever has been in it
 * longest, because a room with nobody who can change it has to be repaired by
 * hand. If the last person leaves, the group and everything written in it go
 * with them: there is nobody left it could belong to.
 */
function removeGroupMember($groupId, $staffId) {
    $wasOwner = ownsGroup($groupId, $staffId);

    $st = db()->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND staff_id = ?");
    $st->bind_param('ii', $groupId, $staffId);
    $st->execute();
    $st->close();

    $left = groupMemberIds($groupId);
    if (!$left) {
        $st = db()->prepare("DELETE FROM chat_messages WHERE group_id = ?");
        $st->bind_param('i', $groupId);
        $st->execute();
        $st->close();
        $st = db()->prepare("DELETE FROM chat_groups WHERE id = ?");
        $st->bind_param('i', $groupId);
        $st->execute();
        $st->close();
        return 'gone';
    }
    if ($wasOwner) {
        $next = $left[0];
        $st = db()->prepare("UPDATE chat_group_members SET is_owner = 1
                              WHERE group_id = ? AND staff_id = ?");
        $st->bind_param('ii', $groupId, $next);
        $st->execute();
        $st->close();
    }
    return 'ok';
}

/**
 * Write into a group and move it up the list.
 *
 * Everyone EXCEPT the writer gets one more waiting. `thread_id = 0` marks it as
 * belonging to no conversation; see the note at the top of this section.
 */
function addGroupMessage($groupId, $staffId, $kind, $body,
                         $mediaPath = null, $mediaMime = null, $mediaBytes = null, $mediaSecs = null) {
    $zero = 0;
    $st = db()->prepare("INSERT INTO chat_messages
        (thread_id, group_id, from_guest, staff_id, kind, body,
         media_path, media_mime, media_bytes, media_secs)
        VALUES (?,?,0,?,?,?,?,?,?,?)");
    $st->bind_param('iiissssii', $zero, $groupId, $staffId, $kind, $body,
                    $mediaPath, $mediaMime, $mediaBytes, $mediaSecs);
    $st->execute();
    $id = $st->insert_id;
    $st->close();

    $preview = previewOf($kind, $body);
    $st = db()->prepare("UPDATE chat_groups SET last_message_at = NOW(), last_preview = ? WHERE id = ?");
    $st->bind_param('si', $preview, $groupId);
    $st->execute();
    $st->close();

    $st = db()->prepare("UPDATE chat_group_members SET unread = unread + 1
                          WHERE group_id = ? AND staff_id <> ?");
    $st->bind_param('ii', $groupId, $staffId);
    $st->execute();
    $st->close();

    try {
        pushGroupMessage((int) $groupId, (int) $staffId, $kind, $body, $id, $mediaSecs);
    } catch (\Throwable $e) {
        pushLog('group push not queued: ' . $e->getMessage());
    }
    return $id;
}

/**
 * One group message, shaped for the browser.
 *
 * The extra over a one-to-one message is `who` - in a room of six, a bubble
 * with no name on it is a bubble you cannot answer.
 */
function groupMessageOut($m, $meId, array $names) {
    $from = (int) $m['staff_id'];
    $name = (string) (isset($names[$from]) ? $names[$from] : 'Someone');
    return array(
        'id'        => (int) $m['id'],
        'group'     => (int) $m['group_id'],
        'mine'      => ($from === (int) $meId),
        'kind'      => $m['kind'],
        'body'      => (string) $m['body'],
        'media'     => $m['media_path'] ? ('media.php?m=' . (int) $m['id']) : null,
        'mime'      => $m['media_mime'],
        'secs'      => $m['media_secs'] !== null ? (int) $m['media_secs'] : null,
        'at'        => $m['created_at'],
        'who'       => $name,
        'hue'       => avatarHue($from . $name),
        'ini'       => initials($name),
        'delivered' => true,
        'read'      => false,
    );
}

/** Everything written in a group after `$after`. */
function groupMessagesSince($groupId, $after, $meId, $limit = 300) {
    $st = db()->prepare("SELECT * FROM chat_messages
                          WHERE group_id = ? AND id > ?
                       ORDER BY id ASC LIMIT " . (int) $limit);
    $st->bind_param('ii', $groupId, $after);
    $st->execute();
    $rows = array();
    $r = $st->get_result();
    while ($row = $r->fetch_assoc()) {
        $rows[] = $row;
    }
    $st->close();
    if (!$rows) {
        return array();
    }
    $ids = array();
    foreach ($rows as $row) {
        $ids[] = (int) $row['staff_id'];
    }
    $names = staffNames(array_unique($ids));
    $out = array();
    foreach ($rows as $row) {
        $out[] = groupMessageOut($row, $meId, $names);
    }
    return $out;
}

/**
 * The ticks on our own messages in a group.
 *
 * WhatsApp turns them blue when EVERY other person has read it, and that is
 * what this answers: the lowest `last_read_id` among the others is the line
 * below which everything has been seen by all of them. One number, one query,
 * however many people are in the room.
 */
function groupReceipts($groupId, $meId, $limit = 50) {
    $st = db()->prepare("SELECT COALESCE(MIN(last_read_id), -1) FROM chat_group_members
                          WHERE group_id = ? AND staff_id <> ?");
    $st->bind_param('ii', $groupId, $meId);
    $st->execute();
    $min = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    $alone = ($min === -1);

    $st = db()->prepare("SELECT id FROM chat_messages
                          WHERE group_id = ? AND staff_id = ?
                       ORDER BY id DESC LIMIT " . (int) $limit);
    $st->bind_param('ii', $groupId, $meId);
    $st->execute();
    $out = array();
    $r = $st->get_result();
    while ($row = $r->fetch_row()) {
        $id = (int) $row[0];
        $out[] = array('id' => $id, 'd' => true, 'r' => (!$alone && $id <= $min));
    }
    $st->close();
    return $out;
}

/** Somebody is looking at a group: nothing in it is waiting for them any more. */
function markGroupRead($groupId, $staffId) {
    $st = db()->prepare("SELECT COALESCE(MAX(id),0) FROM chat_messages WHERE group_id = ?");
    $st->bind_param('i', $groupId);
    $st->execute();
    $top = (int) $st->get_result()->fetch_row()[0];
    $st->close();

    $st = db()->prepare("UPDATE chat_group_members
                            SET unread = 0, last_read_id = GREATEST(last_read_id, ?)
                          WHERE group_id = ? AND staff_id = ?");
    $st->bind_param('iii', $top, $groupId, $staffId);
    $st->execute();
    $st->close();
}

/** How much is waiting for this person across all their groups. */
function groupUnreadFor($staffId) {
    $st = db()->prepare("SELECT COALESCE(SUM(unread),0) FROM chat_group_members WHERE staff_id = ?");
    $st->bind_param('i', $staffId);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
}

/* ================================================ the mobile application

   The app is the same chat, so it must be the same code - not a second one that
   agrees with this until the day it quietly does not. Everything it asks for
   goes to the endpoints already here: api/poll.php, send.php, upload.php,
   group.php, call.php, media.php. Nothing in any of them changed.

   The only thing an app cannot do is hold the browser's session cookie the way
   a tab does, so it carries a PASS instead - minted on the website by
   sbk-app-api.php, where the accounts and the passwords live, signed with the
   same CHAT_LINK_SECRET this file already uses. It is marked `app`, so it can
   never be confused with the website's five-minute handover, and it is good for
   sixty days because a phone is not a tab somebody leaves open over lunch.

   A pass carries no password and is worth nothing anywhere but here. It is
   checked on EVERY request, and the person is looked up on the website every
   time (wpUser()), so an account taken away over there is gone from the app on
   its very next poll - which is what "one place to take somebody's access away"
   has to mean.

   appArrive() does exactly what enter.php does once the signature is good: the
   desk gets `chat_wp_staff`, a customer gets their own row through
   guestForWpUser(). From that line onwards every check downstream - whose thread
   is this, may this person read that group - is the same check it always was. */

/** Who a pass belongs to, or 0. The mirror of sbk_app_token_user() on the website. */
function appTokenUser($token) {
    if (LINK_SECRET === '' || !is_string($token) || $token === '') {
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
    if (!hash_equals(hash_hmac('sha256', $tag . '.' . $id . '.' . $exp, LINK_SECRET), $sig)) {
        return 0;
    }
    return ((int) $exp < time()) ? 0 : (int) $id;
}

/** The pass on this request, from the header or, failing that, the parameters. */
function appTokenOnRequest() {
    /* Some hosts drop Authorization before PHP sees it - LiteSpeed does unless
       it is asked not to - so the parameter is not a convenience, it is the
       fallback that makes this work at all. */
    $raw = '';
    foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $k) {
        if (!empty($_SERVER[$k])) {
            $raw = (string) $_SERVER[$k];
            break;
        }
    }
    if ($raw !== '' && stripos($raw, 'bearer ') === 0) {
        return trim(substr($raw, 7));
    }
    if (!empty($_POST['sbk_token'])) {
        return trim((string) $_POST['sbk_token']);
    }
    if (!empty($_GET['sbk_token'])) {
        return trim((string) $_GET['sbk_token']);
    }
    return '';
}

/**
 * Sign the app's owner in for this request, if it carries a good pass.
 *
 * Called once, at the bottom of this file, so every endpoint that includes
 * lib.php is already holding the right person before its first line runs.
 */
function appArrive() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $token = appTokenOnRequest();
    if ($token === '') {
        return;
    }
    $wpId = appTokenUser($token);
    if ($wpId <= 0) {
        return;
    }
    $u = wpUser($wpId);
    if (!$u) {
        return;
    }

    /* One person at a time in this session, exactly as enter.php insists. A
       phone that signed in as the desk and comes back as a customer must not
       still be holding the desk. */
    if ($u['is_staff']) {
        if (($_SESSION['chat_wp_staff'] ?? 0) !== (int) $u['id']) {
            unset($_SESSION['chat_wp_guest']);
            $_SESSION['chat_wp_staff'] = (int) $u['id'];
        }
        touchPresence('staff', (int) $u['id'], 'app');
    } else {
        unset($_SESSION['chat_wp_staff']);
        $g = guestForWpUser($u);
        touchPresence('guest', (int) $g['id'], 'app');
    }
}

appArrive();
