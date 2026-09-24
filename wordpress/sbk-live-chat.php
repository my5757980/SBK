<?php
/**
 * Plugin Name: SBK Live Chat
 * Description: Puts the SBK live chat on the website — the button, the bell, the
 *              popup, and the one thing none of that would work without: telling
 *              the chat who is signed in here.
 * Version:     1.0
 * Author:      SBK
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 *
 * The chat runs at chat.sbkautotrading.com. This website runs here. They are
 * different origins, so the website's login cookie cannot be read over there —
 * WordPress sets it on sbkautotrading.com alone (COOKIE_DOMAIN is empty), and
 * widening it would sign out everybody currently logged in to a live shop to
 * solve a problem that has a smaller answer.
 *
 * The smaller answer is this file. It writes one short line saying who is here,
 * signs it with a secret only these two programs know, and hands it over. The
 * chat checks the signature and knows exactly as much as WordPress does — no
 * more, and nothing that can be forged.
 *
 * WHAT THE OWNER ASKED FOR, AND WHERE EACH PART IS
 *
 *   "the chat belongs to the WEBSITE, not the auction"    the button below
 *   "only staff and agents in the list"                   sbk_chat_is_staff()
 *   "accounts will be created on the website"             the Agent role
 *   "green if they are logged in on the website"          the heartbeat
 *   "a customer who is logged in goes straight in"        sbk_chat_go()
 *   "notification or popup on the website itself"         the panel and the badge
 *
 * It is a MUST-USE plugin on purpose: it cannot be deactivated by accident from
 * the plugins screen, and the chat quietly disappearing from the whole website
 * is not a mistake anybody should be able to make with one click.
 */

if (!defined('ABSPATH')) { exit; }

define('SBK_CHAT_URL',    'https://chat.sbkautotrading.com/');
/* The application's own domain runs the same chat (owner's order, 19 Sep 2026).
   A person who starts there is handed back there - `app=1` on the way in says
   so. Only these two addresses are ever used, so the flag cannot be turned into
   a way to send somebody to a site of an attacker's choosing. */
define('SBK_APP_URL',     'https://chat-application.sbkautotrading.com/');
define('SBK_CHAT_SECONDS', 300);        // a handover is spent at once or not at all

/**
 * The shared secret, read from the ONE file on this server that holds it.
 *
 * Not stored in the WordPress database and not written into this file: a plugin
 * file is one careless copy-paste away from a support ticket, and an option is
 * one database export away from a backup nobody encrypted. The .env is already
 * the place this account keeps its secrets.
 */
function sbk_chat_secret() {
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $s = '';
    $path = '/home/thelyfas/auction.sbkautotrading.com/.env';
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if (strpos($line, 'CHAT_LINK_SECRET=') === 0) {
                $s = trim(substr($line, 17), "\"'");
                break;
            }
        }
    }
    return $s;
}

/** One signed line saying who is here. Good for five minutes. */
function sbk_chat_token($userId = 0) {
    $userId = $userId ?: get_current_user_id();
    $secret = sbk_chat_secret();
    if (!$userId || $secret === '') {
        return '';
    }
    $body = $userId . '.' . (time() + SBK_CHAT_SECONDS);
    return $body . '.' . hash_hmac('sha256', $body, $secret);
}

/* ===================================================== who counts as the desk

   The owner's rule, twice over: a ROLE decides it, and then one person can be
   added or held back by hand. A job title is a rule of thumb; whether a
   particular person should be in a customer's list is a fact, and somebody has
   to be able to say so without inventing a role for one person. */

/* Kept in step with the chat's own STAFF_ROLES (sbk-chat/includes/config.php) -
   ONE list should decide this, and until 2026-09-18 this file had the OLD one
   (shop_manager, editor) while the chat had moved on to the owner's actual four
   (administrator, csd, manager, sbk_agent) on 2026-09-15. Nobody currently
   holds shop_manager/editor so it changed nothing visible yet, but the
   mismatch would have quietly misjudged the very next CSD or Manager account
   in the profile screen's own "shown/hidden" hint. */
define('SBK_CHAT_ROLES', 'administrator,csd,manager,sbk_agent');

function sbk_chat_is_staff($user) {
    if (!$user instanceof WP_User || !$user->ID) {
        return false;
    }
    $flag = get_user_meta($user->ID, 'sbk_chat_visible', true);
    if ($flag === 'no')  { return false; }
    if ($flag === 'yes') { return true; }
    $allowed = array_map('trim', explode(',', SBK_CHAT_ROLES));
    return (bool) array_intersect((array) $user->roles, $allowed);
}

/**
 * An "Agent" role, so the owner has something to give somebody who answers
 * customers and nothing else. Shop manager carries 139 permissions over a live
 * shop; an agent needs none of them to say hello.
 */
add_action('init', function () {
    if (get_role('sbk_agent')) {
        return;
    }
    add_role('sbk_agent', 'Agent', array(
        'read' => true,                 // enough to sign in, and no more
    ));
});

/* -------------------------------------------------- the tick box on a profile */

function sbk_chat_profile_field($user) {
    if (!current_user_can('edit_users')) {
        return;
    }
    $val = get_user_meta($user->ID, 'sbk_chat_visible', true);
    $auto = sbk_chat_is_staff($user) ? 'shown' : 'hidden';
    ?>
    <h2>SBK Live Chat</h2>
    <table class="form-table" role="presentation">
      <tr>
        <th><label for="sbk_chat_visible">Show in the chat</label></th>
        <td>
          <select name="sbk_chat_visible" id="sbk_chat_visible">
            <option value=""    <?php selected($val, ''); ?>>Decide from their role</option>
            <option value="yes" <?php selected($val, 'yes'); ?>>Always show</option>
            <option value="no"  <?php selected($val, 'no'); ?>>Never show</option>
          </select>
          <p class="description">
            Whether this person appears in the list a customer chooses from.
            By their role alone they are <strong><?php echo esc_html($auto); ?></strong>.
            Customers never appear, whatever this says.
          </p>
        </td>
      </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'sbk_chat_profile_field');
add_action('edit_user_profile', 'sbk_chat_profile_field');

function sbk_chat_profile_save($userId) {
    if (!current_user_can('edit_user', $userId)) {
        return;
    }
    $v = isset($_POST['sbk_chat_visible']) ? sanitize_text_field($_POST['sbk_chat_visible']) : '';
    if (!in_array($v, array('', 'yes', 'no'), true)) {
        $v = '';
    }
    update_user_meta($userId, 'sbk_chat_visible', $v);
}
add_action('personal_options_update', 'sbk_chat_profile_save');
add_action('edit_user_profile_update', 'sbk_chat_profile_save');

/** A column on the Users screen, so "who is in the chat?" is one glance. */
add_filter('manage_users_columns', function ($cols) {
    $cols['sbk_chat'] = 'Live chat';
    return $cols;
});
add_filter('manage_users_custom_column', function ($out, $col, $userId) {
    if ($col !== 'sbk_chat') {
        return $out;
    }
    $u = get_userdata($userId);
    if (!sbk_chat_is_staff($u)) {
        return '<span style="color:#8b96aa">—</span>';
    }
    $forced = get_user_meta($userId, 'sbk_chat_visible', true) === 'yes';
    return '<span style="color:#12a150;font-weight:600">In the list</span>'
         . ($forced ? '<br><span style="color:#8b96aa;font-size:11px">set by hand</span>' : '');
}, 10, 3);

/* ================================================== the way in, and the way back

   One address does the whole handover, so nothing on the website has to know
   how the chat identifies anybody:

     /?sbk_chat_go=1            signed in  -> straight into the conversation
                                not signed in -> the three boxes, as a stranger
     /?sbk_chat_go=1&login=1    not signed in -> sign in first, then come back

   The second form is for the links that say "sign in on the website": there,
   being sent to the three boxes instead would be exactly the wrong answer. */

function sbk_chat_go() {
    if (!isset($_GET['sbk_chat_go'])) {
        return;
    }
    $to    = isset($_GET['to']) ? (int) $_GET['to'] : 0;
    $tail  = $to > 0 ? '&to=' . $to : '';
    $app   = !empty($_GET['app']);
    $base  = $app ? SBK_APP_URL : SBK_CHAT_URL;

    if (is_user_logged_in()) {
        $t = sbk_chat_token();
        if ($t !== '') {
            wp_redirect($base . 'enter.php?t=' . rawurlencode($t) . $tail);
            exit;
        }
        // No secret on this server: better an honest stranger's screen than a
        // blank page nobody can explain.
        wp_redirect($base . 'index.php');
        exit;
    }

    if (!empty($_GET['login'])) {
        $back = home_url('/?sbk_chat_go=1' . ($app ? '&app=1' : '') . $tail);
        wp_redirect(wp_login_url($back));
        exit;
    }

    /* Not signed in to the website — but that does not make them a stranger.
       Somebody who gave their name here yesterday still has the cookie, and
       index.php sends them straight back to their own conversation. Asking for
       the three boxes AGAIN (which `?new=1` would do) would start a second
       conversation beside the first and lose them their history in front of
       them. `new=1` is for "somebody else is using this computer", and this is
       not that. */
    wp_redirect($base . 'index.php');
    exit;
}
add_action('template_redirect', 'sbk_chat_go', 1);

/* ------------------------------------------------ the link in the top menu ---

   The owner asked for it in his own words: a member of staff signs in on the
   website and then "they'll click the direct link at the top and it'll take
   them to the chat". So it belongs up there beside Home and Shop, where a
   person looks for a link — not only in the corner, where they look for a
   button.

   WHY IT IS ADDED TO THE MENU ITSELF AND NOT TO THE RENDERED MARKUP.
   The obvious hook, `wp_nav_menu_items`, does nothing here: this header is
   drawn by ElementsKit's own walker, which never calls wp_nav_menu() the
   ordinary way, so the item was simply dropped on the floor. `wp_get_nav_menu_items`
   is one step earlier — it is where the menu's CONTENTS come from — so anything
   that draws this menu, by any builder, draws this item too.

   The label stays the neutral "Live chat" because the page is CACHED; saying
   "Chat desk" here would say it to every visitor who arrived after a member of
   staff. The script below makes it specific to whoever is really reading. */

add_filter('wp_get_nav_menu_items', function ($items, $menu, $args) {
    if (is_admin() || !is_array($items) || !$items) {
        return $items;                       // never inside the menu editor
    }
    $loc = get_nav_menu_locations();
    if (empty($loc['primary']) || (int) $loc['primary'] !== (int) $menu->term_id) {
        return $items;                       // only the menu that is the top menu
    }
    foreach ($items as $i) {
        if (!empty($i->classes) && in_array('sbk-chat-menu', (array) $i->classes, true)) {
            return $items;                   // already there; menus can be built twice
        }
    }

    $it = new stdClass();
    $it->ID               = 999900001;       // far above any real id, and stable
    $it->db_id            = $it->ID;
    $it->object_id        = $it->ID;
    $it->menu_item_parent = 0;
    $it->menu_order       = 9999;            // last, after everything the owner set
    $it->type             = 'custom';
    $it->object           = 'custom';
    $it->type_label       = 'Custom Link';
    $it->title            = 'Live chat';
    $it->post_title       = 'Live chat';
    $it->url              = home_url('/?sbk_chat_go=1');
    $it->target           = '';
    $it->attr_title       = 'Chat with a person online';
    $it->description      = '';
    $it->classes          = array('sbk-chat-menu');
    $it->xfn              = '';
    $it->post_type        = 'nav_menu_item';
    $it->post_status      = 'publish';
    $it->post_parent      = 0;

    $items[] = $it;
    return $items;
}, 20, 3);

/* ==================================================== the button on every page */

add_action('wp_enqueue_scripts', function () {
    wp_register_style('sbk-chat', false);
    wp_enqueue_style('sbk-chat');
    wp_add_inline_style('sbk-chat', sbk_chat_css());
});

function sbk_chat_css() {
    return <<<CSS
/* SBK live chat — the corner of the website.
   The colours are the logo's own: navy #18335e, the mark's red #bc1e2d, the
   water's blue #0370cd. Nothing here is loaded from anywhere else and nothing
   blocks the page. */
/* BOTTOM LEFT, AND A CIRCLE.
   The website already carries a Tawk.to bubble in the bottom-RIGHT corner and
   the owner wants it kept there — it was added the day before this and is in
   use. So SBK's own chat lives in the opposite corner, and as a plain round
   icon rather than a bar with a sentence in it: two wide buttons facing each
   other across the bottom of the page would crowd it. The wording is still on
   the button for anybody using a screen reader, and appears on hover. */
#sbk-chat-fab{
  position:fixed;left:20px;bottom:20px;z-index:99998;
  display:inline-flex;align-items:center;justify-content:center;
  width:56px;height:56px;padding:0;border:0;border-radius:999px;cursor:pointer;
  background:linear-gradient(160deg,#2a4c82 0%,#18335e 58%,#12294d 100%);
  color:#fff!important;text-decoration:none!important;
  font:650 14.5px/1 "Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif;
  box-shadow:0 8px 26px -8px rgba(9,22,40,.55),0 2px 6px rgba(9,22,40,.20),
             inset 0 1px 0 rgba(255,255,255,.10);
  transition:transform .18s cubic-bezier(.2,.8,.25,1),
             box-shadow .18s cubic-bezier(.2,.8,.25,1), filter .14s, opacity .18s;
}
/* The sentence is kept in the markup for screen readers and for the tooltip,
   but never drawn: an icon is what was asked for. */
#sbk-chat-fab .t{
  position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;
  clip:rect(0 0 0 0);white-space:nowrap;border:0;
}
#sbk-chat-fab:hover{
  transform:translateY(-2px);filter:brightness(1.10);
  box-shadow:0 16px 36px -10px rgba(9,22,40,.60),0 3px 8px rgba(9,22,40,.22),
             inset 0 1px 0 rgba(255,255,255,.14);
}
#sbk-chat-fab:active{transform:translateY(0)}
#sbk-chat-fab .i{display:inline-flex;align-items:center}
/* Two nods a few seconds after the page settles, then still for good. Somebody
   who needs a person finds the button; somebody reading is not nagged. */
#sbk-chat-fab .i svg{animation:sbkNod 5.5s ease-in-out 2.5s 2 both;transform-origin:50% 70%}
@keyframes sbkNod{
  0%,88%,100%{transform:rotate(0)}
  91%{transform:rotate(-11deg)} 94%{transform:rotate(9deg)} 97%{transform:rotate(-4deg)}
}
#sbk-chat-n{
  position:absolute;top:-3px;right:-3px;
  min-width:21px;height:21px;padding:0 6px;border-radius:999px;
  background:#bc1e2d;color:#fff;font-size:11.5px;font-weight:750;line-height:1;
  display:none;align-items:center;justify-content:center;
  box-shadow:0 0 0 2px #fff,0 2px 8px -2px rgba(188,30,45,.9);
  animation:sbkPop .34s cubic-bezier(.16,1,.3,1) both;
}
#sbk-chat-n.on{display:inline-flex}
@keyframes sbkPop{
  0%{transform:scale(.4);opacity:0}
  62%{transform:scale(1.15);opacity:1}
  100%{transform:scale(1)}
}

/* ---- the panel, which is the chat itself, on this website ---------------- */
#sbk-chat-panel{
  position:fixed;left:20px;bottom:20px;z-index:99999;
  width:404px;height:min(660px,calc(100vh - 40px));
  display:none;flex-direction:column;overflow:hidden;
  background:#fff;border-radius:18px;
  box-shadow:0 30px 80px -20px rgba(6,16,34,.55),0 4px 14px rgba(6,16,34,.18);
  transform-origin:bottom left;
}
#sbk-chat-panel.on{display:flex;animation:sbkPanelIn .26s cubic-bezier(.16,1,.3,1) both}
@keyframes sbkPanelIn{
  from{opacity:0;transform:translateY(14px) scale(.97)}
  to{opacity:1;transform:none}
}
#sbk-chat-panel .bar{
  flex:0 0 auto;display:flex;align-items:center;gap:10px;padding:11px 12px 11px 14px;
  background:linear-gradient(180deg,#2a4c82 -40%,#18335e 46%,#0e1f3d 100%);
  color:#fff;position:relative;
}
#sbk-chat-panel .bar::after{
  content:"";position:absolute;left:0;right:0;bottom:0;height:3px;
  background:linear-gradient(90deg,#bc1e2d 0%,#bc1e2d 46%,#0370cd 100%);
}
#sbk-chat-panel .bar img{height:22px;width:auto;display:block;
  background:#fff;padding:4px 8px;border-radius:8px}
#sbk-chat-panel .bar b{
  font:660 14px/1.25 "Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif;
}
#sbk-chat-panel .bar span{
  display:block;font-weight:400;font-size:11px;color:#9db2cf;
  font-family:"Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif;
}
#sbk-chat-panel .bar .sp{margin-left:auto}
#sbk-chat-panel .bar button{
  background:transparent;border:0;color:#cfe0f4;cursor:pointer;padding:7px;
  border-radius:8px;display:inline-flex;line-height:0;
  transition:background .14s,color .14s,transform .14s;
}
#sbk-chat-panel .bar button:hover{background:rgba(255,255,255,.12);color:#fff}
#sbk-chat-panel .bar button:active{transform:scale(.93)}
#sbk-chat-panel iframe{flex:1;width:100%;border:0;display:block;background:#f4f6f9}

/* ---- the link at the top, beside Sign In -------------------------------- */
.sbk-chat-toprow{display:flex!important;align-items:center;gap:20px;flex-wrap:nowrap}
/* Neither may shrink. Without this the flex row squeezed "Sign In" to make
   room and broke it over two lines, which pushed the whole header down. */
.sbk-chat-toprow > *{flex-shrink:0;white-space:nowrap}
.sbk-chat-toplink{
  display:inline-flex;align-items:center;gap:6px;white-space:nowrap;
  font:600 14px/1 "Nunito","Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif;
  color:#111!important;text-decoration:none!important;
  transition:color .14s;
}
.sbk-chat-toplink svg{color:#18335e;transition:color .14s,transform .18s cubic-bezier(.2,.8,.25,1)}
.sbk-chat-toplink:hover{color:#bc1e2d!important}
.sbk-chat-toplink:hover svg{color:#bc1e2d;transform:translateY(-1px)}

/* ---- the same link inside the phone's slide-out menu --------------------- */
.sbk-chat-menu > a{position:relative;display:inline-flex;align-items:center;gap:7px}
.sbk-chat-navdot{
  min-width:19px;height:19px;padding:0 5px;border-radius:999px;
  background:#bc1e2d;color:#fff!important;font-size:11px;font-weight:750;line-height:1;
  display:inline-flex;align-items:center;justify-content:center;
  box-shadow:0 2px 7px -2px rgba(188,30,45,.9);
  animation:sbkPop .34s cubic-bezier(.16,1,.3,1) both;
}
.sbk-chat-navdot[hidden]{display:none!important}

/* ---- the card that says who wrote, and what ------------------------------
   The owner's words: "a notification pop-up will appear there — like it does on
   WhatsApp — that this person has messaged". A number on a bell says something
   happened; this says WHAT happened, and one click answers it. */
#sbk-chat-toast{
  position:fixed;left:20px;bottom:88px;z-index:99997;width:min(330px,calc(100vw - 40px));
  display:none;align-items:flex-start;gap:11px;padding:13px 14px;
  background:#fff;border-radius:14px;cursor:pointer;text-align:left;
  border:0;border-left:4px solid #bc1e2d;
  box-shadow:0 18px 44px -14px rgba(6,16,34,.45),0 2px 8px rgba(6,16,34,.14);
  font:400 13.5px/1.45 "Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Roboto,Arial,sans-serif;
  color:#0f1c2e;
}
#sbk-chat-toast.on{display:flex;animation:sbkToastIn .3s cubic-bezier(.16,1,.3,1) both}
@keyframes sbkToastIn{
  from{opacity:0;transform:translateY(12px) scale(.97)}
  to{opacity:1;transform:none}
}
#sbk-chat-toast .av{
  flex:0 0 auto;width:38px;height:38px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(140deg,#2a4c82,#18335e);
  color:#fff;font-size:13px;font-weight:730;letter-spacing:.4px;
}
#sbk-chat-toast .tx{min-width:0;flex:1}
#sbk-chat-toast .tx b{display:block;font-size:13.5px;font-weight:680;margin-bottom:1px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#sbk-chat-toast .tx span{display:block;color:#54617a;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
#sbk-chat-toast .x{
  flex:0 0 auto;background:transparent;border:0;color:#8b96aa;cursor:pointer;
  padding:2px;margin:-2px -2px 0 0;line-height:0;border-radius:6px;
}
#sbk-chat-toast .x:hover{background:#eef1f6;color:#0f1c2e}

@media (max-width:640px){
  #sbk-chat-fab{left:14px;bottom:14px;width:52px;height:52px}
  #sbk-chat-panel{right:0;left:0;bottom:0;top:0;width:auto;height:auto;border-radius:0}
  #sbk-chat-toast{left:14px;right:14px;bottom:78px;width:auto}
}
@media (prefers-reduced-motion:reduce){
  #sbk-chat-fab,#sbk-chat-fab .i svg,#sbk-chat-n,#sbk-chat-panel{
    animation:none!important;transition:none!important}
}
@media print{ #sbk-chat-fab,#sbk-chat-panel{display:none!important} }
CSS;
}

/* ------------------------------------------------ who is reading this page ---

   WHY THIS IS AN AJAX CALL AND NOT PRINTED INTO THE PAGE.

   This site caches its pages (there is a cache directory and a caching plugin
   installed). A signed line saying WHO IS HERE, printed into the footer, would
   be cached with the rest of the page and then served to the next person — and
   the next person would walk into somebody else's conversation. That is not a
   theoretical risk; it is what page caching does, correctly, to markup.

   So the page carries no identity at all, and the browser asks for it
   separately through admin-ajax, which no cache touches. */

function sbk_chat_who() {
    nocache_headers();
    $me = wp_get_current_user();
    wp_send_json(array(
        'ok'     => true,
        'in'     => is_user_logged_in(),
        'staff'  => sbk_chat_is_staff($me),
        'name'   => $me && $me->ID ? $me->display_name : '',
        'token'  => sbk_chat_token(),
    ));
}
add_action('wp_ajax_sbk_chat_who', 'sbk_chat_who');
add_action('wp_ajax_nopriv_sbk_chat_who', 'sbk_chat_who');

add_action('wp_footer', function () {
    // Never in the admin: somebody working in there has the panel already.
    if (is_admin()) {
        return;
    }

    $logo = SBK_CHAT_URL . 'assets/img/sbk-logo.png';
    ?>
<button type="button" id="sbk-chat-fab" title="Chat with a person online"
        aria-label="Chat with a person online">
  <span class="i" aria-hidden="true">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
      <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.6A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>
      <path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01"/>
    </svg>
  </span>
  <span class="t" id="sbk-chat-label">Chat with a person online</span>
  <span id="sbk-chat-n">0</span>
</button>

<div id="sbk-chat-panel" role="dialog" aria-label="SBK live chat">
  <div class="bar">
    <img src="<?php echo esc_url($logo); ?>" alt="SBK">
    <div>
      <b id="sbk-chat-title">SBK Global Auto Trading</b>
      <span id="sbk-chat-sub">We usually reply within minutes</span>
    </div>
    <div class="sp"></div>
    <button type="button" id="sbk-chat-open" title="Open in a full window">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 4h6v6M20 4l-8 8M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>
      </svg>
    </button>
    <button type="button" id="sbk-chat-close" title="Close">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M6 6l12 12M18 6L6 18"/>
      </svg>
    </button>
  </div>
  <?php /* camera + microphone for calls, autoplay so the other person's voice
           is heard the moment the call connects. A frame only gets what it is
           given here, whatever the page inside asks for. */ ?>
  <iframe id="sbk-chat-frame" title="SBK live chat" src="about:blank"
          allow="microphone; camera; autoplay; clipboard-write"></iframe>
</div>

<div id="sbk-chat-toast" role="status" aria-live="polite">
  <span class="av" id="sbk-toast-av">?</span>
  <span class="tx">
    <b id="sbk-toast-who">—</b>
    <span id="sbk-toast-text"></span>
  </span>
  <button type="button" class="x" id="sbk-toast-x" aria-label="Close">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
</div>

<?php /* WHY THIS SCRIPT TAG CARRIES THREE STRANGE ATTRIBUTES.

         This site runs Airlift, a speed plugin that rewrites every script into
         `type="bv_inline_delayed_js"` and holds it back until the visitor first
         scrolls, clicks or presses a key. For most scripts that is a sensible
         trade. For this one it quietly broke the owner's central rule:

           a member of staff who signed in and left the website open WITHOUT
           touching it never sent a heartbeat, so they never turned green —
           and the "Live chat" link took twelve seconds to appear in the menu.

         Airlift marks its OWN helper scripts `bv-exclude="true"` and
         `data-cfasync="false"` so that it leaves them alone (see
         airlift/buffer/optimizer.php); the same two words make it leave this one
         alone too. `data-no-optimize` is the equivalent for the other common
         optimisers, so that swapping speed plugins one day does not bring the
         problem back. Nothing here is heavy — a few kilobytes that do nothing
         until the page is idle — so the site loses none of the speed it bought. */ ?>
<script bv-exclude="true" data-cfasync="false" data-no-optimize="1" data-no-defer="1">
(function () {
  var CHAT  = <?php echo wp_json_encode(SBK_CHAT_URL); ?>;
  var PING  = CHAT + 'api/ping.php';
  var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
  var GO    = <?php echo wp_json_encode(home_url('/?sbk_chat_go=1')); ?>;

  var fab    = document.getElementById('sbk-chat-fab');
  var panel  = document.getElementById('sbk-chat-panel');
  var frame  = document.getElementById('sbk-chat-frame');
  var dot    = document.getElementById('sbk-chat-n');
  var open   = false, loaded = false;
  var token  = '';                // filled by who(), refreshed by every ping

  /* ------------------------------------------------- the link at the top ---

     WHERE IT GOES, AND WHY NOT IN THE MAIN MENU.
     The owner asked for a direct link "at the top" that takes staff to the
     chat. The obvious place was the main menu beside Home and Shop — and it was
     tried, and the menu is already full: eight items fill it edge to edge, so a
     ninth wrapped onto a second line under "Blogs" and made the whole header
     look broken.

     So it sits in the top bar instead, right beside "Sign In" — which is also
     the top, has room to spare, and is exactly where a member of staff is
     already looking when they arrive to sign in.

     On a PHONE that top bar is folded away, and there the link comes from the
     other direction: the PHP filter above adds it to the menu WordPress draws
     for the slide-out phone menu. This script only hangs the red number on it.
     Between the two, the link is at the top on a desktop and in the menu on a
     phone, and neither is squeezed into a place with no room for it. */
  var navLinks = [];
  var navDots  = [];

  /* A red number on a link, whoever drew the link. */
  function adopt(a) {
    if (!a || a.getAttribute('data-sbk-adopted')) { return; }
    a.setAttribute('data-sbk-adopted', '1');
    var badge = document.createElement('span');
    badge.className = 'sbk-chat-navdot';
    badge.hidden = true;
    badge.textContent = '0';
    a.appendChild(badge);
    navLinks.push(a);
    navDots.push(badge);
  }

  function addTopLink() {
    if (document.getElementById('sbk-chat-toplink')) { return; }
    var acc = null, all = document.querySelectorAll('a.account-link');
    for (var i = 0; i < all.length && !acc; i++) {
      if (all[i].getBoundingClientRect().width > 0) { acc = all[i]; }   // the visible one
    }
    if (!acc) { return; }

    var a = document.createElement('a');
    a.id = 'sbk-chat-toplink';
    a.className = 'sbk-chat-toplink';
    a.href = GO;
    a.title = 'Chat with a person online';
    a.innerHTML =
      '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
      'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.6A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>' +
      '</svg><span class="sbk-chat-toptext">Live chat</span>';

    /* Side by side with Sign In, whatever the builder had set the box to. */
    acc.parentNode.classList.add('sbk-chat-toprow');
    acc.parentNode.insertBefore(a, acc);
    adopt(a);
  }

  function findPhoneMenuLinks() {
    var ms = document.querySelectorAll('.sbk-chat-menu > a');
    for (var i = 0; i < ms.length; i++) { adopt(ms[i]); }
  }

  function ensureLinks() { addTopLink(); findPhoneMenuLinks(); }

  ensureLinks();
  /* Twice more, because a page builder often finishes its header after the
     rest of the page. Once a link is there, these do nothing. */
  setTimeout(ensureLinks, 1500);
  setTimeout(ensureLinks, 4000);
  function setNavCount(n) {
    for (var i = 0; i < navDots.length; i++) {
      navDots[i].textContent = n > 99 ? '99+' : n;
      navDots[i].hidden = (n === 0);
    }
  }

  var staffLabel = false;
  function relabel() {
    if (!staffLabel) { return; }
    for (var i = 0; i < navLinks.length; i++) {
      var w = document.createTreeWalker(navLinks[i], NodeFilter.SHOW_TEXT), t;
      while ((t = w.nextNode())) {
        if (t.nodeValue.indexOf('Live chat') !== -1) {
          t.nodeValue = t.nodeValue.replace('Live chat', 'Chat desk');
          break;
        }
      }
    }
  }

  if (!fab || !window.fetch) { return; }

  /* Where the panel points. A signed-in person walks straight in carrying the
     handover; a stranger gets the three boxes. If the line has gone stale while
     the page sat open, the address below sends them through the website, which
     writes a fresh one — so this can never be the wrong door, only a slower
     one. */
  function src() {
    return token ? (CHAT + 'enter.php?t=' + encodeURIComponent(token)) : GO;
  }

  function show() {
    /* The chat is fetched only when somebody actually wants it. A page nobody
       clicks costs one button and nothing else - the owner's standing rule is
       that the website must never be made slow by this. */
    if (!loaded) { frame.src = src(); loaded = true; }
    panel.classList.add('on');
    fab.style.opacity = '0';
    fab.style.pointerEvents = 'none';
    open = true;
    dot.classList.remove('on');
    setNavCount(0);
    stopRinging();                 // the chat inside rings from here on
    hideToast();

    /* The desk's computer can announce a message with the site behind other
       work (see popup), but only once the browser has been told yes - and a
       browser only asks after a click. Opening the chat desk is that click. A
       customer is never asked by the website. */
    if (staffLabel && window.Notification && Notification.permission === 'default') {
      try { Notification.requestPermission(); } catch (e) {}
    }
  }
  function hide() {
    panel.classList.remove('on');
    fab.style.opacity = '';
    fab.style.pointerEvents = '';
    open = false;
    ask();
  }

  fab.addEventListener('click', show);

  /* The menu link opens the panel rather than leaving the page.
     It is a real link with a real address underneath, so it still works with
     JavaScript off, opens in a new tab on a middle click, and can be copied —
     but for an ordinary click, staying on the page the person was reading is
     the better answer. */
  /* Listened for on the document rather than on the link, because the link may
     not exist yet when this runs — and a handler attached to something that is
     not there is a link that quietly does the wrong thing. */
  document.addEventListener('click', function (ev) {
    var a = ev.target.closest ? ev.target.closest('a[href*="sbk_chat_go"]') : null;
    if (!a) { return; }
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button) { return; }
    ev.preventDefault();
    show();
  });

  document.getElementById('sbk-chat-close').addEventListener('click', hide);
  document.getElementById('sbk-chat-open').addEventListener('click', function () {
    window.open(src(), '_blank', 'noopener');
    hide();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && open) { hide(); }
  });

  /* Who is reading this page. Asked for separately because the page itself is
     cached and must not carry anybody's name — see sbk_chat_who(). */
  function who() {
    return fetch(AJAX + '?action=sbk_chat_who', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok) { return; }
        token = j.token || '';
        if (j.staff) {
          document.getElementById('sbk-chat-label').textContent = 'Open the chat desk';
          document.getElementById('sbk-chat-title').textContent = 'Chat desk';
          document.getElementById('sbk-chat-sub').textContent   = 'Your conversations';
          fab.setAttribute('aria-label', 'Open the chat desk');
          fab.setAttribute('title', 'Open the chat desk');

          /* "Live chat" becomes "Chat desk" for the people who work here, in
             every copy of the menu. Only the text node is touched, so whatever
             the page builder wrapped around it stays where it is. */
          staffLabel = true;
          relabel();
        }
      })
      .catch(function () {});
  }

  /* The heartbeat and the number waiting, in one call every fifteen seconds.
     The token is what makes a member of staff green while they are merely
     READING the website — the owner's requirement, and the reason this runs on
     every page and not only where the chat is open.

     The answer carries a fresh token each time, so a window left open all day
     stays green, while a token that leaked somewhere stops being any use within
     five minutes of leaving the browser that is rotating it. */
  var hiddenAsk = 0;
  function ask() {
    /* A hidden tab asks nothing - a window left open all day should cost
       nothing. The desk is the one exception (the owner, 24 September 2026): a
       message has to reach them while the site sits behind other work, so for
       staff a hidden tab still asks, twice a minute. */
    if (document.hidden) {
      if (!staffLabel) { return; }
      var t = Date.now();
      if (t - hiddenAsk < 29000) { return; }
      hiddenAsk = t;
    }
    var u = PING + '?from=site' + (token ? '&wp=' + encodeURIComponent(token) : '');
    fetch(u, { credentials: 'include', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok) { return; }
        if (j.token) { token = j.token; }
        var n = (open || !j.unread) ? 0 : j.unread;
        dot.textContent = n > 99 ? '99+' : n;
        dot.classList.toggle('on', n > 0);
        setNavCount(n);
        relabel();                 // a copy of the menu drawn late gets its label too
        if (j.ringing && !open) { ringing(j.ringing); }
        else { stopRinging(); popup(j.last); }
      })
      .catch(function () { /* the badge stays as it was, which is correct */ });
  }

  /* ---------------------------------------------------- the WhatsApp card ---
     Shown once per message, and only while the panel is shut — somebody with
     the conversation open in front of them does not need to be told about it.
     It closes itself after eight seconds, and clicking it opens the panel on
     that conversation, because being told and being able to answer should be
     the same gesture. */
  var toast    = document.getElementById('sbk-chat-toast');
  var toastAv  = document.getElementById('sbk-toast-av');
  var toastWho = document.getElementById('sbk-toast-who');
  var toastTx  = document.getElementById('sbk-toast-text');
  var shown    = 0, hideTimer = null, ac = null, primed = false;

  function initials(name) {
    var p = String(name || '').trim().split(/\s+/);
    return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
  }

  function chime() {
    /* Made in the browser rather than fetched: one less request, and one less
       thing that can fail. A browser that refuses to make a noise is not a
       fault - it is a browser that has not been clicked in yet. */
    try {
      ac = ac || new (window.AudioContext || window.webkitAudioContext)();
      var o = ac.createOscillator(), g = ac.createGain();
      o.connect(g); g.connect(ac.destination);
      o.type = 'sine'; o.frequency.value = 880;
      g.gain.setValueAtTime(0.0001, ac.currentTime);
      g.gain.exponentialRampToValueAtTime(0.12, ac.currentTime + 0.01);
      g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + 0.30);
      o.start(); o.stop(ac.currentTime + 0.32);
    } catch (e) {}
  }

  function hideToast() {
    toast.classList.remove('on');
    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
  }

  function popup(last) {
    if (!toast) { return; }

    /* The first answer only sets the mark, it never pops.
       Otherwise a card would jump up on EVERY page a person opens for as long
       as anything is unread — the message would be old news and the card would
       be a nuisance, and a notification that cries wolf is one people learn to
       close without reading. The red number already says something is waiting;
       the card is for something ARRIVING. */
    if (!primed) {
      primed = true;
      shown  = last ? last.id : 0;
      return;
    }

    if (!last || open) { hideToast(); return; }
    if (last.id <= shown) { return; }          // already said, and once is enough
    shown = last.id;

    toastAv.textContent  = initials(last.from);
    toastWho.textContent = last.from || 'SBK';
    toastTx.textContent  = last.text || '';
    toast.classList.add('on');
    chime();

    if (hideTimer) { clearTimeout(hideTimer); }
    hideTimer = setTimeout(hideToast, 8000);

    /* With the site NOT in front - another tab, another window - the card is
       not seen, so the desk's computer says it in its own notification area:
       the owner's "notification bar", 24 September 2026. Staff only. Clicking
       it brings the site forward with the chat open on the conversation. */
    if (staffLabel && (document.hidden || !document.hasFocus())
        && window.Notification && Notification.permission === 'granted') {
      try {
        var sys = new Notification(last.from || 'SBK', {
          body: last.text || 'New message', tag: 'sbk-chat-' + last.id, renotify: true });
        sys.onclick = function () { sys.close(); window.focus(); stopRinging(); hideToast(); show(); };
      } catch (e) { /* a browser that will not show one is not a fault */ }
    }
  }

  /* ---------------------------------------------------- somebody is CALLING ---
     The same card, but it stays up and it rings, until the call is answered,
     declined or given up on — a call is not a message you read later. Clicking
     it opens the chat panel, where the call is waiting with its Answer button.
     (The answer itself happens inside the chat, because the browser will only
     hand a page the microphone after a tap on that page.) */
  var ringingId = 0, ringTimer = null;
  function ringing(r) {
    if (!toast) { return; }
    if (ringingId !== r.id) {
      ringingId = r.id;
      toastAv.textContent  = initials(r.from);
      toastWho.textContent = (r.from || 'Somebody') + ' is calling…';
      toastTx.textContent  = (r.kind === 'video' ? 'Video call' : 'Voice call') + ' — tap to answer';
      toast.classList.add('on');
      if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
      if (ringTimer) { clearInterval(ringTimer); }
      chime();
      ringTimer = setInterval(chime, 2000);
    }
  }
  function stopRinging() {
    if (!ringingId) { return; }
    ringingId = 0;
    if (ringTimer) { clearInterval(ringTimer); ringTimer = null; }
    hideToast();
  }

  toast.addEventListener('click', function () { stopRinging(); hideToast(); show(); });
  document.getElementById('sbk-toast-x').addEventListener('click', function (ev) {
    ev.stopPropagation();
    hideToast();
  });

  /* Is somebody else already standing here?
     ------------------------------------------------------------------------
     This button sits bottom LEFT because the website's Tawk.to bubble owns the
     bottom right and the owner wants it kept there. But a site picks up widgets
     over time — a WhatsApp button, a cookie notice, a scroll-to-top arrow — and
     the next one may well choose this corner.

     So instead of trusting the corner to stay empty: anything fixed, floating
     above us, and actually overlapping this button pushes us up out of its way,
     and only for as long as it is really there. Checked twice because a
     third-party widget usually arrives after the page has finished loading,
     which is exactly when a hard-coded answer would be wrong. */
  function stepAside() {
    fab.style.bottom = '';
    panel.style.bottom = '';
    var mine = fab.getBoundingClientRect();
    var myZ  = parseInt(getComputedStyle(fab).zIndex, 10) || 0;
    var hit  = false;
    var all  = document.querySelectorAll('iframe,div,button,a');
    for (var i = 0; i < all.length && !hit; i++) {
      var el = all[i];
      if (el === fab || fab.contains(el) || panel.contains(el) || panel === el) { continue; }
      var s = getComputedStyle(el);
      if (s.position !== 'fixed' || s.display === 'none' || s.visibility === 'hidden') { continue; }
      if ((parseInt(s.zIndex, 10) || 0) <= myZ) { continue; }
      var r = el.getBoundingClientRect();
      if (r.width < 30 || r.height < 30 || r.width > 500) { continue; }
      hit = !(r.right < mine.left || r.left > mine.right ||
              r.bottom < mine.top || r.top > mine.bottom);
    }
    fab.style.bottom   = hit ? '92px' : '';
    panel.style.bottom = hit ? '92px' : '';
  }
  setTimeout(stepAside, 1200);
  setTimeout(stepAside, 5000);
  window.addEventListener('resize', stepAside);

  /* Ten seconds, not fifteen. A card that says "so-and-so has messaged you"
     should arrive while the person still feels answered; a quarter of a minute
     is long enough to feel like the site missed it. One request every ten
     seconds answering in about twenty milliseconds is nothing next to this
     account's thirty-process ceiling - fifty people reading the site at once
     occupy, on average, a tenth of one of them. */
  who().then(ask);
  setInterval(ask, 10000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { ask(); } });

  /* The chat asks to be closed when somebody signs out inside the panel. */
  window.addEventListener('message', function (ev) {
    if (ev.origin !== CHAT.replace(/\/$/, '')) { return; }
    if (ev.data === 'sbk-chat-close') { hide(); }
  });
})();
</script>
    <?php
});
