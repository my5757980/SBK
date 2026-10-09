<?php
/**
 * Data sources - the IDs the feeds fetch with (spec 010).
 *
 * The client, 2 October 2026: if an ID is ever suspended, the desk changes it here
 * and the feed carries on from the new one. Two feeds, and they differ in one way
 * that shapes this whole page:
 *
 *   Feed B (auction B + statistics) signs in with username and password by itself.
 *   Saving a new ID here is all it takes; the job on GitHub asks for it at the
 *   start of its next run, within about twenty minutes.
 *
 *   Feed A (the auction) is behind an "I'm not a robot" check, which only a person
 *   may tick - nothing here ever tries to get round it. A new ID is used by a
 *   person once: they sign in on the site and hand the sign-in (its session code)
 *   over below; the page asks the site whose it is and installs it.
 *
 * The page names no source in its own words (the client's rule); the addresses
 * shown are the ones saved for this permission's holders to edit.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/source-ids.php';

requirePermission('sources.manage');

if (empty($_SESSION['src_csrf'])) {
    $_SESSION['src_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['src_csrf'];
$me = currentStaff();
$by = $me ? ($me['name'] ?: $me['username']) : 'staff';

function srcFlash($msg, $ok, $feed) {
    $_SESSION['src_flash'] = array('msg' => $msg, 'ok' => $ok, 'feed' => $feed);
    header('Location: sources.php#feed-' . $feed);
    exit;
}

/* ---------------------------------------------------------------- the actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isAjax = isset($_POST['ajax']);
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(array('ok' => false, 'error' => 'The page is out of date - reload it.'));
            exit;
        }
        srcFlash('The page was out of date - nothing was changed. Try again.', false, 'a');
    }
    $feed = in_array($_POST['feed'] ?? '', SOURCE_FEEDS, true) ? $_POST['feed'] : '';
    $action = (string) ($_POST['action'] ?? '');

    /* The saved password, for the person who signs in by hand (Show / Copy). */
    if ($action === 'reveal' && $feed !== '') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        try {
            $p = sourceIdPass($feed);
            if ($p !== '') {
                logStaffAction('source.reveal', 'Feed ' . strtoupper($feed));
            }
            echo json_encode(array('ok' => $p !== '', 'pass' => $p,
                                   'error' => $p === '' ? 'No password is saved yet.' : ''));
        } catch (Throwable $e) {
            echo json_encode(array('ok' => false, 'error' => 'The saved password could not be read.'));
        }
        exit;
    }

    if ($action === 'save' && $feed !== '') {
        $pass = (string) ($_POST['pass'] ?? '');
        try {
            $rev = sourceIdSave($feed, $_POST['base'] ?? '', $_POST['user'] ?? '', $pass === '' ? null : $pass, $by);
            logStaffAction('source.id', 'Feed ' . strtoupper($feed), $rev);
            srcFlash($feed === 'b'
                ? 'Saved. Feed B signs in with this ID on its next run - within about 20 minutes. Its status below changes when it does.'
                : 'Saved. Now sign in on the site with this ID and hand the sign-in over in step 4 below.', true, $feed);
        } catch (InvalidArgumentException $e) {
            srcFlash($e->getMessage(), false, $feed);
        } catch (Throwable $e) {
            srcFlash('Not saved: ' . $e->getMessage(), false, $feed);
        }
    }

    if ($action === 'connect') {
        // one question to the site per ten seconds from this desk - it is a real request
        $last = (int) ($_SESSION['src_check_at'] ?? 0);
        if (time() - $last < 10) {
            srcFlash('Please wait a few seconds before checking again.', false, 'a');
        }
        $_SESSION['src_check_at'] = time();
        $code = pbSessionClean($_POST['code'] ?? '');
        if ($code === '') {
            srcFlash('That does not look like a session code. Copy the Value of "session_id" (step 3) and paste it again.', false, 'a');
        }
        $chk = pbSessionCheck(sourceIdBase('a'), $code);
        if (!$chk['ok']) {
            srcFlash($chk['why'], false, 'a');
        }
        try {
            $where = pbSessionInstall($code);
        } catch (Throwable $e) {
            srcFlash('The sign-in is good but the server did not save it - try again. (' . $e->getMessage() . ')', false, 'a');
        }
        sourceIdMark('a', array('login' => $chk['login'], 'name' => $chk['name'], 'at' => time(), 'by' => $by));
        logStaffAction('source.session', 'Feed A');
        srcFlash('Connected - signed in as ' . ($chk['login'] ?: $chk['name'] ?: 'member ' . $chk['uid']) . '. '
                 . ($where === 'now' ? 'Feed A uses it from its next run, within 5 minutes.'
                                     : 'A run is in progress; the next one, within 5 minutes, takes it.'), true, 'a');
    }
    srcFlash('Nothing was changed.', false, 'a');
}

/* ---------------------------------------------------------------- the picture */
$flash = $_SESSION['src_flash'] ?? null;
unset($_SESSION['src_flash']);

try {
    $idA = sourceIdGet('a');
    $idB = sourceIdGet('b');
    $readErr = '';
} catch (Throwable $e) {
    $idA = $idB = array('base' => '', 'custom' => false, 'user' => '', 'has_pass' => false, 'rev' => 0,
                        'saved_at' => 0, 'saved_by' => '');
    $readErr = $e->getMessage();
}
$def = sourceIdDefaults();
$notesA = sourceIdNotes('a');

/* Feed A: the harvester's own state, in our words. */
$hA = sourceHealthAuction();
$pendingA = is_file(sourceDataDir('pb-harvest') . '/session.pending');
if ($pendingA) {
    $sigA = array('cls' => 'is-wait', 'text' => 'Taking the new sign-in',
                  'detail' => 'A new sign-in is waiting; the next run takes it, within 5 minutes.');
} elseif ($hA['ok']) {
    $sigA = array('cls' => 'is-ok', 'text' => 'Working', 'detail' => $hA['detail']);
} elseif ($hA['state'] === 'signedout') {
    $sigA = array('cls' => 'is-bad', 'text' => 'Needs a sign-in',
                  'detail' => 'The ID is no longer signed in. Do steps 1-4 below - with the same ID, or a new one.');
} else {
    $sigA = array('cls' => 'is-bad', 'text' => 'Not working', 'detail' => $hA['detail']);
}

/* Feed B: the job's last report, and whether it has taken the saved ID yet. */
$hS = sourceHealthStatistics();
$hB = sourceHealthAuctionB();
$health = json_decode((string) @file_get_contents(sourceDataDir('aaa-fetch') . '/health.json'), true) ?: array();
$usedRev = (int) ($health['id_rev'] ?? 0);
$waitingB = $idB['rev'] > 0 && $usedRev < $idB['rev'];
if ($waitingB) {
    $sigB = array('cls' => 'is-wait', 'text' => 'Waiting for the new ID',
                  'detail' => 'Saved ' . sourceAgo(time() - $idB['saved_at']) . '. The next run signs in with it - within about 20 minutes.');
} elseif ($hS['ok'] && $hB['ok']) {
    $sigB = array('cls' => 'is-ok', 'text' => 'Working', 'detail' => $hS['detail']);
} else {
    $bad = !$hS['ok'] ? $hS : $hB;
    $sigB = array('cls' => 'is-bad', 'text' => 'Not working', 'detail' => $bad['detail']);
}
if ($idB['rev'] > 0) {
    $inUseB = $waitingB ? 'The job is still on the previous ID until its next run.'
                        : 'In use: the ID saved ' . date('j M, H:i', $idB['saved_at']) . ($idB['saved_by'] ? ' by ' . $idB['saved_by'] : '') . '.';
} else {
    $inUseB = 'In use: the ID set up when the job was installed (kept on GitHub, not shown here). Save one below to take over.';
}

function srcSig($s) {
    return '<span class="src-sig ' . $s['cls'] . '" title="' . sanitize($s['detail']) . '">'
         . '<span class="src-dot"></span><span class="src-text">' . sanitize($s['text']) . '</span></span>';
}

/** One feed's ID form. */
function srcIdForm($feed, $id, $def, $csrf) {
    $p = 'f' . $feed;
    ob_start(); ?>
    <form method="POST" class="src-form" autocomplete="off" data-busy="Saving…">
      <input type="hidden" name="csrf" value="<?php echo sanitize($csrf); ?>">
      <input type="hidden" name="feed" value="<?php echo $feed; ?>">
      <input type="hidden" name="action" value="save">
      <div class="form-grid">
        <div class="field field-full">
          <label for="<?php echo $p; ?>-base">Website address</label>
          <div class="src-inline">
            <input type="url" id="<?php echo $p; ?>-base" name="base" class="input" required maxlength="200"
                   value="<?php echo sanitize($id['base']); ?>" spellcheck="false" inputmode="url">
            <?php if ($id['base'] !== $def[$feed]): ?>
              <button type="button" class="btn btn-ghost btn-sm src-std" data-target="<?php echo $p; ?>-base"
                      data-value="<?php echo sanitize($def[$feed]); ?>">Use the standard address</button>
            <?php endif; ?>
          </div>
          <p class="hint">Only this same website at a new address. A different website will not work - it needs its own code.</p>
        </div>
        <div class="field">
          <label for="<?php echo $p; ?>-user">Username</label>
          <div class="src-inline">
            <input type="text" id="<?php echo $p; ?>-user" name="user" class="input" required maxlength="120"
                   value="<?php echo sanitize($id['user']); ?>" spellcheck="false" autocapitalize="off"
                   placeholder="<?php echo $id['user'] === '' ? 'The ID’s username or e-mail' : ''; ?>">
            <?php if ($id['user'] !== ''): ?>
              <button type="button" class="btn btn-ghost btn-sm src-copy" data-copy-from="<?php echo $p; ?>-user">Copy</button>
            <?php endif; ?>
          </div>
        </div>
        <div class="field">
          <label for="<?php echo $p; ?>-pass">Password <?php if ($id['has_pass']): ?><span class="bound">saved</span><?php endif; ?></label>
          <div class="pw-wrap">
            <input type="password" id="<?php echo $p; ?>-pass" name="pass" class="input" maxlength="200"
                   autocomplete="new-password" <?php echo $id['has_pass'] ? '' : 'required'; ?>
                   placeholder="<?php echo $id['has_pass'] ? 'Leave empty to keep the saved one' : 'The ID’s password'; ?>">
            <button type="button" class="pw-eye" data-for="<?php echo $p; ?>-pass" aria-label="Show password">&#128065;</button>
          </div>
          <?php if ($id['has_pass']): ?>
            <div class="src-pass-tools">
              <button type="button" class="btn btn-ghost btn-sm src-reveal" data-feed="<?php echo $feed; ?>" data-mode="show">Show saved</button>
              <button type="button" class="btn btn-ghost btn-sm src-reveal" data-feed="<?php echo $feed; ?>" data-mode="copy">Copy saved</button>
              <span class="src-revealed" id="<?php echo $p; ?>-shown" hidden></span>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="src-actions">
        <button type="submit" class="btn btn-primary">Save</button>
        <?php if ($id['rev'] > 0): ?>
          <span class="src-meta">Saved <?php echo date('j M Y, H:i', $id['saved_at']); ?><?php echo $id['saved_by'] ? ' by ' . sanitize($id['saved_by']) : ''; ?></span>
        <?php endif; ?>
      </div>
    </form>
    <?php return ob_get_clean();
}

$page_title = 'Data sources';
$active = 'sources';
require_once '_header.php';
?>

<h1>Data sources</h1>
<p class="lede">The website address, username and password each feed fetches with. If an ID is ever
   suspended, put a new one here and the feed carries on from it.</p>

<?php if ($readErr): ?>
  <div class="alert alert-error">The saved IDs could not be read just now (<?php echo sanitize($readErr); ?>). Reload the page.</div>
<?php endif; ?>

<div class="src-cards">

  <!-- ------------------------------------------------------------ feed A -->
  <section class="admin-card src-card" id="feed-a">
    <div class="admin-card-head">
      <div class="src-title"><h2>Feed A</h2><span class="src-sub">Auction</span></div>
      <span class="spacer"></span>
      <?php echo srcSig($sigA); ?>
    </div>
    <div class="admin-card-body">
      <?php if ($flash && $flash['feed'] === 'a'): ?>
        <div class="alert <?php echo $flash['ok'] ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($flash['msg']); ?></div>
      <?php endif; ?>
      <p class="src-status"><?php echo sanitize($sigA['detail']); ?></p>
      <?php if (!empty($notesA['login']) || !empty($notesA['name'])): ?>
        <p class="src-meta">Last sign-in handed over here: <b><?php echo sanitize($notesA['login'] ?: $notesA['name']); ?></b>,
           <?php echo date('j M Y, H:i', (int) $notesA['at']); ?><?php echo !empty($notesA['by']) ? ' by ' . sanitize($notesA['by']) : ''; ?></p>
      <?php endif; ?>

      <div class="src-note">
        <b>This site asks a person to tick “I’m not a robot”.</b> The feed cannot sign in by itself, so a
        new ID is used in two parts: save it here, then sign in with it once and hand the sign-in over
        (steps 1-4). After that it runs by itself.
      </div>

      <h3 class="src-h">Sign-in details</h3>
      <?php echo srcIdForm('a', $idA, $def, $csrf); ?>

      <h3 class="src-h">Hand over a sign-in <span class="src-when">a computer is needed for step 3</span></h3>
      <ol class="src-steps">
        <li>
          <b>Open the site</b> and choose sign in.
          <div class="src-step-do">
            <a class="btn btn-secondary btn-sm" href="<?php echo sanitize($idA['base']); ?>" target="_blank" rel="noopener noreferrer">Open the site &#8599;</a>
            <?php if ($idA['user'] !== ''): ?>
              <button type="button" class="btn btn-ghost btn-sm src-copy" data-copy-text="<?php echo sanitize($idA['user']); ?>">Copy username</button>
            <?php endif; ?>
            <?php if ($idA['has_pass']): ?>
              <button type="button" class="btn btn-ghost btn-sm src-reveal" data-feed="a" data-mode="copy">Copy password</button>
            <?php endif; ?>
          </div>
        </li>
        <li><b>Sign in</b> with the username and password, tick <b>“I’m not a robot”</b>, and finish signing in.</li>
        <li>
          <b>Copy the session code</b> from that tab.
          <details class="src-howto">
            <summary>How do I copy it?</summary>
            <ol>
              <li>On the site’s tab press <kbd>F12</kbd> (or right-click &rarr; <i>Inspect</i>).</li>
              <li>Open the <b>Application</b> tab (Firefox: <b>Storage</b>). If it is hidden, it is behind <b>&raquo;</b>.</li>
              <li>On the left: <b>Cookies</b> &rarr; the site’s address.</li>
              <li>Click the row named <b>session_id</b> and copy its <b>Value</b> (double-click it, then <kbd>Ctrl</kbd>+<kbd>C</kbd>).</li>
            </ol>
          </details>
        </li>
        <li>
          <b>Paste it here</b> and check it. The site is asked once whose sign-in it is; only a signed-in
          member’s code is used.
          <form method="POST" class="src-connect" autocomplete="off" data-busy="Checking…">
            <input type="hidden" name="csrf" value="<?php echo sanitize($csrf); ?>">
            <input type="hidden" name="action" value="connect">
            <label class="sr-only" for="fa-code">Session code</label>
            <input type="text" id="fa-code" name="code" class="input" required maxlength="400"
                   spellcheck="false" autocapitalize="off" placeholder="Paste the session code">
            <button type="submit" class="btn btn-primary">Check and connect</button>
          </form>
        </li>
      </ol>
    </div>
  </section>

  <!-- ------------------------------------------------------------ feed B -->
  <section class="admin-card src-card" id="feed-b">
    <div class="admin-card-head">
      <div class="src-title"><h2>Feed B</h2><span class="src-sub">Auction B and statistics</span></div>
      <span class="spacer"></span>
      <?php echo srcSig($sigB); ?>
    </div>
    <div class="admin-card-body">
      <?php if ($flash && $flash['feed'] === 'b'): ?>
        <div class="alert <?php echo $flash['ok'] ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($flash['msg']); ?></div>
      <?php endif; ?>
      <p class="src-status"><?php echo sanitize($sigB['detail']); ?></p>
      <p class="src-meta"><?php echo sanitize($inUseB); ?></p>

      <div class="src-note src-note-ok">
        <b>Signs in by itself.</b> Save a new ID and the feed starts using it on its next run - within
        about 20 minutes. Nothing else to do. If it had stopped for 24 hours after a refusal, a new ID
        ends that stop.
      </div>

      <h3 class="src-h">Sign-in details</h3>
      <?php echo srcIdForm('b', $idB, $def, $csrf); ?>
    </div>
  </section>

</div>

<input type="hidden" id="src-csrf" value="<?php echo sanitize($csrf); ?>">
<script>
(function () {
  function toast(btn, text) {
    var old = btn.getAttribute('data-label') || btn.textContent;
    btn.setAttribute('data-label', old);
    btn.textContent = text;
    setTimeout(function () { btn.textContent = old; }, 1600);
  }
  function copy(text, btn) {
    function done() { toast(btn, 'Copied'); }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var t = document.createElement('textarea');
      t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.appendChild(t); t.select();
      try { document.execCommand('copy'); done(); } catch (e) { toast(btn, 'Could not copy'); }
      document.body.removeChild(t);
    }
  }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button');
    if (!b) { return; }
    if (b.classList.contains('src-copy')) {
      var from = b.getAttribute('data-copy-from');
      copy(from ? document.getElementById(from).value : b.getAttribute('data-copy-text'), b);
    }
    if (b.classList.contains('src-std')) {
      var f = document.getElementById(b.getAttribute('data-target'));
      f.value = b.getAttribute('data-value'); f.focus();
    }
    if (b.classList.contains('src-reveal')) {
      var feed = b.getAttribute('data-feed'), mode = b.getAttribute('data-mode');
      var body = new FormData();
      body.append('csrf', document.getElementById('src-csrf').value);
      body.append('action', 'reveal'); body.append('feed', feed); body.append('ajax', '1');
      b.disabled = true;
      fetch('sources.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          b.disabled = false;
          if (!j.ok) { toast(b, j.error || 'Not available'); return; }
          if (mode === 'copy') { copy(j.pass, b); return; }
          var span = document.getElementById('f' + feed + '-shown');
          if (span) {
            span.textContent = j.pass; span.hidden = false;
            setTimeout(function () { span.hidden = true; span.textContent = ''; }, 15000);
          }
        })
        .catch(function () { b.disabled = false; toast(b, 'Try again'); });
    }
  });
  // a form that is working says so, and cannot be sent twice
  document.querySelectorAll('form[data-busy]').forEach(function (f) {
    f.addEventListener('submit', function () {
      var s = f.querySelector('button[type="submit"]');
      if (s) { s.disabled = true; s.textContent = f.getAttribute('data-busy'); }
    });
  });
})();
</script>

<?php require_once '_footer.php'; ?>
