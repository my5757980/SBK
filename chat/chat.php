<?php
/**
 * The conversation — one page for both sides.
 *
 * A customer sees the list of people they may write to — staff and agents from
 * the website, and never another customer. A member of staff sees the list of
 * conversations waiting for them. Everything to the right of that list is
 * identical, because it is the same thing: a column of messages and a box to
 * write in. Two screens would have been two of every bug.
 *
 * A WordPress ADMINISTRATOR sees every conversation in the building instead of
 * only their own, with the name of the member of staff it belongs to beside
 * each — which is the "user who can see all the chats" the owner asked for.
 */

require_once __DIR__ . '/includes/lib.php';

$g = guest();
$s = staff();

if (!$g && !$s) {
    /* Not "who are you?" but "the website already knows". enter.php asks the
       website for a signed handover; somebody signed in there - customer or
       staff - lands straight in the conversation, and only a complete stranger
       is ever shown the three boxes. That is the owner's flow: a customer with
       an account "should go directly to the staff side". */
    header('Location: enter.php');
    exit;
}

$isGuest = ($g && !$s);

/* Name, e-mail and telephone before anything else - the owner's rule for everybody
   who is not the desk, a customer signed in on the website included. See
   guestNeedsDetails(); index.php shows them the three boxes. */
if ($isGuest && guestNeedsDetails($g)) {
    header('Location: index.php');
    exit;
}

$me      = $isGuest ? $g : $s;
$seesAll = (!$isGuest && staffSeesEverything());

/* A customer whose only "name" is their login — `jd3885325` — is asked once for
   a real one, so the desk sees a person and not half an e-mail address. See
   api/name.php for why most of this shop's customers have no name on file. */
$needsName = false;
if ($isGuest) {
    $login = '';
    if (!empty($g['wp_user_id'])) {
        $wu = wpUser((int) $g['wp_user_id']);
        $login = $wu ? $wu['username'] : '';
    }
    $needsName = looksLikeLogin($g['name'], $login, $g['email']);
}

$css = @filemtime(__DIR__ . '/assets/css/chat.css');
$js  = @filemtime(__DIR__ . '/assets/js/chat.js');
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title><?php echo $isGuest ? 'Chat' : 'Chat desk'; ?> — <?php echo e(CHAT_NAME); ?></title>
<link rel="icon" href="assets/img/favicon.png">
<link rel="stylesheet" href="assets/css/chat.css?v=<?php echo $css; ?>">
<script>
/* Am I inside the website's own panel? Answered here, in the head, before
   anything is painted - a bar that appears and then vanishes is worse than one
   that was never drawn. See "inside the website's own panel" in chat.css. */
if (window.self !== window.top) { document.documentElement.className += ' framed'; }
</script>
</head>
<body>

<div class="app">

  <!-- ------------------------------------------------------------- the bar -->
  <header class="top">
    <?php /* The mark gets a plate of its own. It is drawn in red and navy for a
             white ground, so on the navy bar the ship would simply vanish - and
             a logo you have to hunt for is worse than none. */ ?>
    <span class="mark">
      <img src="assets/img/sbk-logo.png" alt="<?php echo e(CHAT_NAME); ?>">
    </span>
    <div class="who">
      <span id="meName"><?php echo e($me['name']); ?></span>
      <small>
        <?php if ($isGuest): ?>
          <?php echo e($me['email']); ?>
        <?php elseif ($seesAll): ?>
          Chat desk · every conversation
        <?php else: ?>
          Chat desk
        <?php endif; ?>
      </small>
    </div>

    <div class="sp"></div>

    <button class="icon-btn" id="bell" title="Waiting for you" type="button">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.7 21a2 2 0 0 1-3.4 0"/>
      </svg>
      <span class="bell-dot" id="bellDot">0</span>
    </button>

    <a href="<?php echo e($isGuest ? 'logout.php' : 'logout.php'); ?>"
       class="icon-btn" title="Sign out">
      <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M9 8l-4 4 4 4M5 12h10"/>
      </svg>
    </a>
  </header>

  <div class="body" id="body">

    <!-- ------------------------------------------------------- the side list -->
    <aside class="side">
      <?php /* For an administrator this line is also the way back: the list
               below it is the AGENTS, and stepping into one puts that agent's
               name here with a chevron. chat.js writes it - see syncSideHead. */ ?>
      <div class="side-head" id="sideHead">
        <?php if ($isGuest): ?>
          Our team
        <?php elseif ($seesAll): ?>
          Agents
        <?php else: ?>
          Your conversations
        <?php endif; ?>
      </div>
      <?php if ($needsName): ?>
        <?php /* Asked once, above the list, and gone the moment it is answered.
                 Not a wall in front of the chat: somebody can ignore it and
                 write to the desk straight away, and is simply known by their
                 login until they fill it in. */ ?>
        <form class="ask-name" id="askName" autocomplete="on">
          <b>What's your name?</b>
          <span>So our team knows who they're talking to.</span>
          <div class="ask-row">
            <input type="text" name="name" autocomplete="name" maxlength="80"
                   placeholder="Your name" required>
            <button type="submit">Save</button>
          </div>
          <em class="ask-err" hidden></em>
        </form>
      <?php endif; ?>
      <div class="side-list" id="sideList">
        <?php /* Rows of the right shape with nothing written in them yet. The
                 owner's instruction was that nothing inside the chat may show a
                 loading state; a list that is already arriving reads as arriving,
                 while the word "Loading…" reads as a list that has not begun. */ ?>
        <div class="skel">
          <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="skel-row">
              <span class="skel-b skel-av"></span>
              <span class="skel-l"><i class="skel-b"></i><i class="skel-b"></i></span>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </aside>

    <!-- ------------------------------------------------------- the messages -->
    <section class="pane">
      <div class="pane-head" id="paneHead" hidden>
        <button class="tool back-btn" id="backBtn" type="button" title="Back"
                style="width:36px;height:36px;background:transparent">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15 6l-6 6 6 6"/>
          </svg>
        </button>
        <div class="av sm" id="headAv"></div>
        <div class="nm">
          <b id="headName">—</b>
          <span id="headSub"></span>
        </div>
        <?php /* Call buttons. They work whether or not the other person is at
                 their screen - the owner's instruction, 20 September 2026: the
                 call rings their phone through the app's notification, and if
                 nobody answers it becomes a missed call after RING_SECONDS.
                 They are dead only while a call is already up. */ ?>
        <div class="call-btns" id="callBtns" hidden>
          <button class="tool call-go" id="callVoice" type="button" title="Voice call">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>
            </svg>
          </button>
          <button class="tool call-go" id="callVideo" type="button" title="Video call">
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="6" width="14" height="12" rx="2.5"/><path d="M16 10.5l6-3.5v10l-6-3.5z"/>
            </svg>
          </button>
        </div>
      </div>

      <?php /* ------------------------------------------------ a call in progress
               Over the conversation, not instead of it: when the call ends the
               messages are exactly where they were. */ ?>
      <div class="call" id="callBox" hidden>
        <video id="callRemote" class="call-remote" autoplay playsinline></video>
        <video id="callLocal" class="call-local" autoplay playsinline muted></video>
        <audio id="callAudio" autoplay></audio>
        <div class="call-card">
          <div class="av call-av" id="callAv">?</div>
          <b id="callName">—</b>
          <span id="callState">Calling…</span>
        </div>
        <div class="call-bar" id="callBar">
          <button class="cbtn" id="callMute" type="button" title="Mute">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
              <rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v3"/>
            </svg>
          </button>
          <button class="cbtn" id="callCam" type="button" title="Camera">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="6" width="14" height="12" rx="2.5"/><path d="M16 10.5l6-3.5v10l-6-3.5z"/>
            </svg>
          </button>
          <button class="cbtn cbtn-end" id="callEnd" type="button" title="End call">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 9c-1.6 0-3.1.3-4.5.8v3.1c0 .4-.2.7-.6.9-1 .5-1.9 1.1-2.7 1.8-.2.2-.4.3-.7.3s-.5-.1-.7-.3L.3 13.1c-.2-.2-.3-.4-.3-.7s.1-.5.3-.7C3.4 8.8 7.5 7 12 7s8.6 1.8 11.7 4.7c.2.2.3.4.3.7s-.1.5-.3.7l-2.5 2.5c-.2.2-.4.3-.7.3s-.5-.1-.7-.3c-.8-.7-1.7-1.3-2.7-1.8-.3-.2-.6-.5-.6-.9V9.8C15.1 9.3 13.6 9 12 9z"/>
            </svg>
          </button>
        </div>
        <div class="call-bar" id="callAsk" hidden>
          <button class="cbtn cbtn-end" id="callNo" type="button" title="Decline">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 9c-1.6 0-3.1.3-4.5.8v3.1c0 .4-.2.7-.6.9-1 .5-1.9 1.1-2.7 1.8-.2.2-.4.3-.7.3s-.5-.1-.7-.3L.3 13.1c-.2-.2-.3-.4-.3-.7s.1-.5.3-.7C3.4 8.8 7.5 7 12 7s8.6 1.8 11.7 4.7c.2.2.3.4.3.7s-.1.5-.3.7l-2.5 2.5c-.2.2-.4.3-.7.3s-.5-.1-.7-.3c-.8-.7-1.7-1.3-2.7-1.8-.3-.2-.6-.5-.6-.9V9.8C15.1 9.3 13.6 9 12 9z"/>
            </svg>
          </button>
          <button class="cbtn cbtn-yes" id="callYes" type="button" title="Answer">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="scroll" id="scroll">
        <div class="empty">
          <span class="ico">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.6A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/>
              <path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01"/>
            </svg>
          </span>
          <b><?php echo $isGuest ? 'Pick somebody to talk to'
                                 : 'Pick a conversation'; ?></b>
          <span><?php echo $isGuest
              ? 'Anyone with a green dot is at their desk right now.'
              : 'Everything a customer has written is on the left.'; ?></span>
        </div>
      </div>

      <?php /* Pictures wait here before they are sent.
               They used to leave the moment they were chosen, so a wrong one
               was already in the customer's conversation before anybody could
               look at it. Now they land in this tray first - the way every
               messenger does it - each with a cross to take it out again, and
               nothing goes anywhere until Send is pressed. */ ?>
      <div class="tray" id="tray" hidden>
        <div class="tray-strip" id="trayStrip"></div>
        <button class="tray-clear" id="trayClear" type="button">Remove all</button>
      </div>

      <div class="compose wrap-rel" id="compose" hidden>
        <?php /* `multiple`: several pictures from one trip to the picker. */ ?>
        <input type="file" id="fileIn" accept="image/*" multiple hidden>

        <button class="tool" id="emojiBtn" type="button" title="Emoji">
          <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9"/><path d="M8.5 14.5s1.3 1.8 3.5 1.8 3.5-1.8 3.5-1.8"/>
            <path d="M9 9.5h.01M15 9.5h.01"/>
          </svg>
        </button>

        <button class="tool" id="picBtn" type="button" title="Send a picture">
          <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4.5" width="18" height="15" rx="2.5"/>
            <circle cx="8.7" cy="9.7" r="1.6"/><path d="M21 16l-5-5-6.5 6.5"/>
          </svg>
        </button>

        <textarea id="box" rows="1" placeholder="Write a message…" maxlength="4000"></textarea>

        <div class="rec-bar" id="recBar">
          <span class="blip"></span>
          <span>Recording… <b id="recTime">0:00</b></span>
          <span style="margin-left:auto;font-weight:400;font-size:12.5px">tap the square to send</span>
        </div>

        <button class="tool" id="micBtn" type="button" title="Record a voice note">
          <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
            <rect x="9" y="3" width="6" height="11" rx="3"/>
            <path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v3"/>
          </svg>
        </button>

        <button class="tool send" id="sendBtn" type="button" title="Send">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 12l16-8-6 16-2.6-6.2L4 12z"/>
          </svg>
        </button>

        <div class="emoji" id="emojiPad"></div>
      </div>
    </section>

  </div>
</div>

<?php /* One picture opened used to be one picture only: to see the next one you
         closed this and found it in the conversation again. It now holds every
         picture in the conversation - arrows, arrow keys, and a swipe on a
         phone - with the one that was clicked showing first. */ ?>
<div class="lb" id="lb">
  <button class="lb-x" id="lbClose" type="button" aria-label="Close">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
  </button>
  <button class="lb-nav lb-prev" id="lbPrev" type="button" aria-label="Previous picture">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
  </button>
  <img id="lbImg" alt="">
  <button class="lb-nav lb-next" id="lbNext" type="button" aria-label="Next picture">
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 5l7 7-7 7"/></svg>
  </button>
  <div class="lb-count" id="lbCount"></div>
</div>

<script>
window.SBK = {
  mode:  <?php echo $isGuest ? '"guest"' : '"staff"'; ?>,
  meId:  <?php echo (int) $me['id']; ?>,
  seesAll: <?php echo $seesAll ? 'true' : 'false'; ?>,
  /* Somebody who clicked a particular person on the website arrives with them
     named, and the conversation opens by itself rather than making them find
     the same name a second time. */
  openTo: <?php echo (int) ($_GET['to'] ?? 0); ?>
};
</script>
<script src="assets/js/chat.js?v=<?php echo $js; ?>"></script>

</body>
</html>
