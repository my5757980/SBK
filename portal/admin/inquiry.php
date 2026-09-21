<?php
/**
 * SBK Auction — one enquiry
 *
 * The whole of it: who asked, which vehicle, what they wrote, what we said
 * back, and the box to say something else.
 *
 * The client's other enquiries are listed underneath. Somebody asking about a
 * third car this week is a different conversation from somebody asking once,
 * and the answer usually depends on knowing which of the two you have.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('enquiries.view');

global $conn;

$id = intval($_GET['id'] ?? 0);
$statuses = array('open', 'answered', 'closed');

$message = '';
$message_ok = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = intval($_POST['inquiry_id'] ?? 0);
    $status   = trim($_POST['status'] ?? '');
    $response = trim($_POST['response'] ?? '');

    $before = inquiryRow($id);

    // Writing a reply is answering. The dropdown opened on whatever the enquiry
    // already said, so a reply saved with it untouched left the enquiry marked
    // Open - a customer answered an hour ago sitting in the queue as though
    // nobody had looked at it, and the next person on the desk answering them
    // again.
    //
    // Only when the status was left alone. Someone who deliberately picks
    // Closed while replying means Closed, and is not overruled. And only for a
    // role allowed to set the status: a reply must not become a way around a
    // permission that was deliberately withheld.
    if ($before
        && $response !== ''
        && $status === $before['status']
        && $status !== 'closed'
        && staffCan('enquiries.status')) {
        $status = 'answered';
    }

    // Writing back to the customer and closing the enquiry are different jobs,
    // so they are different permissions - checked against what the submission
    // changes rather than against the fact that it was submitted at all.
    $touches = array();
    if (!$before || $status !== $before['status']) { $touches[] = 'enquiries.status'; }
    // An empty box means "no reply written", not "delete the reply" - that is
    // what updateInquiry() does with it - so a blank response is not a change.
    if ($response !== '' && $response !== (string) $before['response']) { $touches[] = 'enquiries.reply'; }

    $lacks = array_values(array_filter($touches, function ($p) { return !staffCan($p); }));

    if (!$before) {
        $message = 'Unknown enquiry'; $message_ok = false;
    } elseif ($lacks) {
        $message = in_array('enquiries.reply', $lacks, true)
            ? 'Your role can read enquiries but not answer them'
            : 'Your role cannot change the status of an enquiry';
        $message_ok = false;
    } elseif (!$touches) {
        $message = 'Nothing changed';
    } elseif (!in_array($status, $statuses, true)) {
        $message = 'Unknown status'; $message_ok = false;
    } elseif (updateInquiry($id, $status, $response)) {
        // $enq is not loaded until below, so the status is what there is to
        // name it by here - the id carries the rest.
        logStaffAction(in_array('enquiries.reply', $touches, true) ? 'enquiry.reply' : 'enquiry.status',
                       $status, $id);
        $message = $response !== '' ? 'Reply saved and sent to the client' : 'Enquiry updated';
    } else {
        $message = 'Could not update that enquiry'; $message_ok = false;
    }
}

/* ------------------------------------------------------------- the enquiry */
$stmt = $conn->prepare("
    SELECT i.*, cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone,
           c.id AS car_row_id, c.lot_no, c.auction, c.auction_date, c.year, c.grade
    FROM inquiries i
    JOIN clients cl ON cl.id = i.client_id
    LEFT JOIN cars c ON c.car_id = i.car_id
    WHERE i.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$enq = $stmt->get_result()->fetch_assoc();
$stmt->close();

$others = array();
if ($enq) {
    $stmt = $conn->prepare("
        SELECT i.id, i.inquiry_number, i.make, i.model, i.status, i.created_at, c.lot_no
        FROM inquiries i
        LEFT JOIN cars c ON c.car_id = i.car_id
        WHERE i.client_id = ? AND i.id <> ?
        ORDER BY i.created_at DESC
        LIMIT 20
    ");
    $stmt->bind_param('ii', $enq['client_id'], $enq['id']);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) { $others[] = $row; }
    $stmt->close();
}

/* An enquiry nobody has opened is 'new'. Opening it is what makes it 'open' -
   somebody has it in front of them.

   Done on the way in, after the row is read so the page still shows the state
   the reader arrived to, and before the desk list is next drawn.

   Not gated on the status permission, unlike every other status move. This one
   is not an act somebody performs on the enquiry; it is a record of the reading
   that is already happening, and a role allowed to read enquiries is by
   definition allowed to do the thing being recorded. Gating it would leave the
   desk unable to tell an unread enquiry from one three people had looked at.

   Only from 'new'. Nothing else is walked backwards or forwards by a page view:
   an answered enquiry stays answered when it is re-read. */
if ($enq && $enq['status'] === 'new') {
    $st = $conn->prepare("UPDATE inquiries SET status = 'open' WHERE id = ? AND status = 'new'");
    $st->bind_param('i', $enq['id']);
    $st->execute();
    $st->close();
    $enq['status'] = 'open';
    logStaffAction('enquiry.open', $enq['inquiry_number'], $enq['id']);
}

$page_title = 'Enquiry';
$active = 'inquiries';
require_once '_header.php';
?>

<?php if (!$enq): ?>
  <h1>Enquiry</h1>
  <div class="admin-card"><div class="admin-card-body">
    <p class="hint">No enquiry with that number. It may have been removed.</p>
    <p><a href="inquiries.php" class="btn btn-secondary">Back to enquiries</a></p>
  </div></div>
<?php else: ?>

<div class="detail-top">
  <a href="inquiries.php" class="btn btn-secondary btn-xs">&larr; All enquiries</a>
  <span class="spacer"></span>
  <span class="enq-status s-<?php echo sanitize(strtolower($enq['status'])); ?>">
    <?php echo ucfirst(sanitize($enq['status'])); ?>
  </span>
</div>

<h1>Enquiry <span class="mono enq-no"><?php echo sanitize($enq['inquiry_number']); ?></span></h1>

<?php if ($message): ?>
  <div class="alert <?php echo $message_ok ? 'alert-success' : 'alert-error'; ?>"><?php echo sanitize($message); ?></div>
<?php endif; ?>

<div class="enq-detail">

  <!-- ------------------------------------------------------ the conversation -->
  <div class="admin-card">
    <div class="admin-card-head"><h2>The conversation</h2></div>
    <div class="admin-card-body">

      <article class="msg msg-in">
        <header class="msg-h">
          <b><?php echo sanitize($enq['client_name']); ?></b>
          <span class="msg-when"><?php echo formatDate($enq['created_at']); ?></span>
        </header>
        <div class="msg-body"><?php echo nl2br(sanitize($enq['message'])); ?></div>
      </article>

      <?php if (!empty($enq['response'])): ?>
        <article class="msg msg-out">
          <header class="msg-h">
            <b>SBK</b>
            <span class="msg-when">
              <?php echo !empty($enq['responded_at']) ? formatDate($enq['responded_at']) : ''; ?>
            </span>
          </header>
          <div class="msg-body"><?php echo nl2br(sanitize($enq['response'])); ?></div>
        </article>
      <?php else: ?>
        <p class="hint">No reply sent yet.</p>
      <?php endif; ?>

      <?php $mayReply = staffCan('enquiries.reply'); $mayStatus = staffCan('enquiries.status'); ?>
      <?php if ($mayReply || $mayStatus): ?>
        <form method="POST" class="enq-form">
          <input type="hidden" name="inquiry_id" value="<?php echo (int) $enq['id']; ?>">
          <?php if ($mayReply): ?>
            <textarea name="response" class="form-textarea" rows="5"
                      placeholder="<?php echo empty($enq['response']) ? 'Write your reply…' : 'Replace the reply…'; ?>"></textarea>
          <?php endif; ?>
          <div class="enq-form-foot">
            <?php if ($mayStatus): ?>
              <select name="status" class="select">
                <?php foreach ($statuses as $s): ?>
                  <option value="<?php echo $s; ?>"
                    <?php echo strtolower($enq['status']) === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <span class="ro-value"><?php echo ucfirst(sanitize($enq['status'])); ?></span>
              <input type="hidden" name="status" value="<?php echo sanitize(strtolower($enq['status'])); ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- ------------------------------------------------------------- the facts -->
  <div class="enq-side">
    <div class="admin-card">
      <div class="admin-card-head"><h2>Client</h2></div>
      <div class="admin-card-body">
        <dl class="kv">
          <dt>Name</dt><dd><?php echo sanitize($enq['client_name']); ?></dd>
          <?php if (!empty($enq['client_email'])): ?>
            <dt>Email</dt><dd><a href="mailto:<?php echo sanitize($enq['client_email']); ?>"><?php echo sanitize($enq['client_email']); ?></a></dd>
          <?php endif; ?>
          <?php if (!empty($enq['client_phone'])): ?>
            <dt>Phone</dt><dd><?php echo sanitize($enq['client_phone']); ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="admin-card">
      <div class="admin-card-head"><h2>Vehicle</h2></div>
      <div class="admin-card-body">
        <?php $v = trim($enq['make'] . ' ' . $enq['model']); ?>
        <?php if ($v === '' && empty($enq['lot_no'])): ?>
          <p class="hint">The enquiry does not name a vehicle.</p>
        <?php else: ?>
          <dl class="kv">
            <dt>Vehicle</dt>
            <dd>
              <?php if (!empty($enq['car_row_id'])): ?>
                <a href="../car-details.php?id=<?php echo intval($enq['car_row_id']); ?>"
                   target="_blank" rel="noopener"><?php echo sanitize($v !== '' ? $v : 'Open the lot'); ?></a>
              <?php else: ?>
                <?php echo sanitize($v !== '' ? $v : '—'); ?>
              <?php endif; ?>
            </dd>
            <?php if (!empty($enq['year'])): ?><dt>Year</dt><dd><?php echo intval($enq['year']); ?></dd><?php endif; ?>
            <?php if (!empty($enq['grade'])): ?><dt>Grade</dt><dd><?php echo sanitize($enq['grade']); ?></dd><?php endif; ?>
            <?php if (!empty($enq['lot_no'])): ?><dt>Lot</dt><dd class="mono"><?php echo sanitize($enq['lot_no']); ?></dd><?php endif; ?>
            <?php if (!empty($enq['auction'])): ?><dt>Hall</dt><dd><?php echo sanitize($enq['auction']); ?></dd><?php endif; ?>
            <?php if (!empty($enq['auction_date'])): ?><dt>Auction</dt><dd><?php echo sanitize($enq['auction_date']); ?></dd><?php endif; ?>
          </dl>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($others): ?>
      <div class="admin-card">
        <div class="admin-card-head">
          <h2>Their other enquiries</h2>
          <span class="spacer"></span>
          <span class="page-info"><?php echo count($others); ?></span>
        </div>
        <div class="admin-card-body">
          <ul class="enq-others">
            <?php foreach ($others as $o): ?>
              <li>
                <a href="inquiry.php?id=<?php echo intval($o['id']); ?>">
                  <?php
                    $ov = trim($o['make'] . ' ' . $o['model']);
                    echo sanitize($ov !== '' ? $ov : $o['inquiry_number']);
                  ?>
                </a>
                <span class="enq-others-meta">
                  <?php echo formatDate($o['created_at']); ?>
                  · <?php echo ucfirst(sanitize($o['status'])); ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<?php require_once '_footer.php'; ?>
