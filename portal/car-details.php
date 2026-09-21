<?php
/**
 * SBK Auction — vehicle detail.
 * Signed-in clients only. Bids and enquiries from here land in the admin panel.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

requireLogin();

$car_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$car = getCarById($car_id);

if (!$car) {
    header("Location: welcome.php");
    exit;
}

// A customer only sees lots still to be auctioned, so a sold or past lot must
// not be reachable by typing its URL either. Staff keep the full archive.
// Fixed price is gone from the portal (owner's order, 13 September 2026): a row
// from that section is not offered to anybody, the same as a lot that has left.
$isFixedPrice = false;
if ((($car['source_section'] ?? 'japan') !== 'japan') || (!viewerSeesPastLots() && !lotIsCurrent($car))) {
    header("Location: welcome.php?gone=1");
    exit;
}

/* Two kinds of person can be on this page, and now both of them can bid.
   A customer bids as themselves. A member of staff - the owner included -
   bids as themselves too: the bid records their staff id instead of a client
   id, and it comes back to them and to nobody else on the Bids screen. That is
   the owner's instruction: bidding is open to everyone, and a bid belongs to
   whoever placed it.

   Enquiries are still a customer action. An enquiry is a question put TO the
   desk, and the desk has no one to ask. */
$client = getClient();
$staff  = currentStaff();

// The list shows concluded lots so it reads the same as the source site, but a
// lot whose auction has already run can no longer be bid on - it shows its
// result instead.
$canBid = !$isFixedPrice && lotIsBiddable($car);

// The feed sends three images and the third is always the inspection sheet -
// its id carries a different prefix from the two photographs, on every lot.
// They are shown in different places and mean different things, so they are
// separated here rather than paged through together as if they were alike.
// JPAuc's listing links one photograph per vehicle, but its address ends in
// "number=1" and the rest of the set answers to 2, 3 and so on - twelve on a
// typical lot, the last of them the inspection sheet. Deriving them costs
// nothing: no page is read and no request is spent, and the buyer's browser
// fetches them from the picture host directly. Lots from the older supplier
// still carry their three stored images, so both are handled.
$derived = jpaucImageSet($car, 12);
if ($derived) {
    // The sheet is number=0 - before the photographs, not after them, which is
    // why looking at the end of the set kept finding another bumper. It is
    // square where a photograph is four by three, and that is what told them
    // apart in the end.
    $sheet = jpaucSheetUrl($car);
    $photos = $derived;
    $thumbs = $derived;
} else {
    $allShots = getCarImages($car, 640);
    $allThumbs = getCarImages($car, 320);
    $sheet = null;
    if (!$isFixedPrice && count($allShots) > 2) {
        $sheet = array_pop($allShots);
        array_pop($allThumbs);
    }
    $photos = $allShots;
    $thumbs = $allThumbs;
}

/* USS halls: the host's usual address gives everyone a 100x75 preview and
   nothing else. The full set comes from Pacific Boeki - kept already if anybody
   has opened this car before, otherwise asked for by photos.js once the page is
   up (data-uss), so the page never waits on it. See ussPhotoSet(). */
$ussSet = ussPhotoSet($car);
if ($ussSet) {
    $sheet  = $ussSet['sheet'] ?: $sheet;
    $photos = $ussSet['photos'];
    $thumbs = $ussSet['photos'];
}
$ussAsk = (!$ussSet && isUssLot($car)) ? (int) $car['id'] : 0;

// Previous / Next walk the same make in lot order, so paging through a hall's
// listing from a lot page lands where a buyer expects rather than jumping to
// whatever id happens to sit beside it in the table.
$prevId = $nextId = null;
$backHref = 'index.php?make=' . urlencode((string) $car['make'])
          . '&model=' . urlencode((string) $car['model']);
if (!$isFixedPrice) {
    $nb = getLotNeighbours($car);
    $prevId = $nb['prev'];
    $nextId = $nb['next'];
}

$notice = null;   // ['ok'|'err', message]

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$client && !$staff) {
    $notice = array('err', 'Please sign in before bidding.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($client || $staff)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'inquiry' && !$client) {
        $notice = array('err', 'An enquiry is a question for the desk, so it comes '
                             . 'from a customer account. You are signed in as staff.');
    } elseif ($action === 'inquiry') {
        $message = trim($_POST['message'] ?? '');
        if ($message === '') {
            $notice = array('err', 'Please write your question before sending.');
        } else {
            $res = createInquiry($client['id'], $car['car_id'], $message);
            $notice = $res['success']
                ? array('ok', 'Enquiry ' . $res['inquiry_number'] . ' sent. Our team will reply shortly.')
                : array('err', $res['message']);
        }
    }

    if ($action === 'bid') {
        // Checked here, not only in the browser. The box was decoration: it
        // could be left empty and the bid went through anyway, so every bid on
        // record was placed without the buyer having agreed to anything. A
        // browser check alone is not agreement either - the form can be posted
        // without one.
        if (empty($_POST['agree'])) {
            $notice = array('err', 'Please accept the Terms & Conditions before placing your bid.');
        } else {
            /* One of the two, never both - see placeBid(). */
            $res = $client
                ? placeBid($client['id'], $car, $_POST['amount'] ?? '')
                : placeBid(null, $car, $_POST['amount'] ?? '', $staff['id']);
            $notice = $res['success']
                ? array('ok', 'Bid ' . $res['bid_number'] . ' placed at ¥' . number_format($res['amount'])
                        . '. It is now with our auction desk.')
                : array('err', $res['message']);
        }
    }
}

function assetV($path) {
    $full = __DIR__ . '/' . ltrim($path, '/');
    return $path . '?v=' . (is_file($full) ? filemtime($full) : time());
}

$title = trim($car['make'] . ' ' . $car['model']);

$page_title = $title . ' — Lot ' . $car['lot_no'] . ' | ' . SITE_NAME;
require_once 'includes/header.php';
?>

<div class="container">
  <!-- ------------------------------------------------------- lot header bar -->
  <div class="pb-lot-bar">
    <div class="pb-lot-name">
      <h1><?php echo sanitize(trim($car['make'] . ' ' . $car['model'])); ?><?php
        if ($car['year']): ?> <i><?php echo intval($car['year']); ?></i><?php endif; ?></h1>
      <?php if (!empty($car['grade'])): ?>
        <span class="pb-lot-grade"><?php echo sanitize($car['grade']); ?></span>
      <?php endif; ?>
    </div>

    <div class="pb-lot-mid">
      <?php // Both links opened the print dialogue, so "PDF" gave a print
            // preview and left the buyer to find "Save as PDF" inside it.
            // PDF now builds a spec sheet and downloads it. ?>
      <a href="#" onclick="window.print();return false" class="pb-lnk">&#128424; Print</a>
      <span class="pb-sep">|</span>
      <a href="car-pdf.php?id=<?php echo (int) $car['id']; ?>" class="pb-lnk">&#128196; PDF</a>
    </div>

    <div class="pb-lot-price">
      Start Price : <b><?php echo $car['price'] > 0 ? number_format($car['price']) : '---'; ?></b>
      <span class="pb-cur">JPY</span>
    </div>
  </div>

  <div class="pb-lot-bar2">
    <div class="pb-abcd">
      <?php foreach (array('A', 'B', 'C', 'D') as $i => $L): ?>
        <?php if (isset($photos[$i])): ?>
          <a class="ltr thumb" href="#" data-full="<?php echo sanitize($photos[$i]); ?>"><?php echo $L; ?></a>
        <?php else: ?>
          <span class="ltr is-off"><?php echo $L; ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php // The reference portal offers these two next. Both come from parts of
            // the feed our account is not given, so they are shown as unavailable
            // rather than as buttons that quietly do nothing. ?>
      <span class="pb-btn is-dead" title="Needs the feed's paid plan — not on this account">Vehicle History</span>
    </div>
    <?php // Two buttons, not three links separated by bars. "Search Result" sat
          // between them saying where you came from, which the breadcrumb above
          // already says; what a buyer wants here is the next car. ?>
    <nav class="pb-updown">
      <?php if ($prevId): ?>
        <a class="pb-step" href="car-details.php?id=<?php echo (int) $prevId; ?>">&laquo; Previous</a>
      <?php else: ?>
        <span class="pb-step is-off">&laquo; Previous</span>
      <?php endif; ?>
      <?php if ($nextId): ?>
        <a class="pb-step" href="car-details.php?id=<?php echo (int) $nextId; ?>">Next &raquo;</a>
      <?php else: ?>
        <span class="pb-step is-off">Next &raquo;</span>
      <?php endif; ?>
    </nav>
  </div>

  <!-- ------------------------------------------------------------- spec grid -->
  <?php
    $ends = auctionEndsAt($car);
    $specs = array(
        array('',              longAuctionDate($car['auction_date']), 'is-day'),
        array('Auction Time',  trim((string) $car['auction_time'], '[]') ?: '—', 'is-time'),
        array('Lot Number',    $car['lot_no'] ?: '—', ''),
        array('Auction Hall',  $car['auction'] ?: '—', ''),
        array('Make',          $car['make'] ?: '—', ''),
        array('Model',         $car['model'] ?: '—', ''),
        array('Year',          $car['year'] ? (int) $car['year'] : '—', ''),
        array('Chassis Model', $car['chassis'] ?: '—', ''),
        array('Transmission',  $car['transmission'] ?: '—', ''),
        array('Engine',        $car['engine_cc'] ? number_format($car['engine_cc']) : '—', ''),
        array('Mileage',       $car['mileage'] ? number_format($car['mileage']) . ' km' : '—', ''),
        array('Color',         $car['color'] ?: '—', ''),
        array('Start Price',   $car['price'] > 0 ? number_format($car['price']) . ' JPY' : '—', ''),
        // What it actually went for. It was in the table, in the PDF and in one
        // sentence beside the closed bid box, but not here - so a buyer looking
        // at a concluded lot read "Status: Sold" and had to hunt for the figure.
        // Blank rows are dropped below, so this appears only once there is one.
        array('Sold For',      $car['sold_price'] > 0
                                 ? number_format($car['sold_price']) . ' JPY' : '', 'is-final'),
        array('Status',        lotStatusLabel($car), 'is-status ' . lotStatusClass($car)),
        array('Auction Grade', $car['rating'] ?: '—', 'is-grade'),
        array('Equipment',     $car['equipment'] ?: '', ''),
        array('Engine Power',  $car['engine_hp'] ?: '', ''),
        array('Model Grade',   $car['grade'] ?: '', ''),
    );
    // Drop what the source did not send. A grid two-thirds full of dashes
    // reads as a broken page rather than a complete one, and "Auction Time
    // 00:00" is the listing saying it has no time, not a real midnight.
    $specs = array_values(array_filter($specs, function ($s) {
        $v = trim((string) $s[1]);
        if ($v === '' || $v === '—' || $v === '0') return false;
        // `00:00`, `0:00` and `00:00:00` are all the feed saying it has no
        // time for this lot. Only the last two were being dropped.
        if ($s[2] === 'is-time' && preg_match('/^0?0:00(:00)?$/', $v)) return false;
        return true;
    }));
  ?>
  <div class="pb-specs">
    <?php foreach ($specs as $s): ?>
      <div class="pb-spec <?php echo $s[2]; ?>">
        <?php if ($s[0] !== ''): ?><span class="k"><?php echo sanitize($s[0]); ?></span><?php endif; ?>
        <span class="v"><?php echo sanitize((string) $s[1]); ?><?php
          if ($s[2] === 'is-time' && $ends !== null): ?><i class="left" data-ends="<?php
            echo (int) $ends; ?>"></i><?php endif; ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($notice): ?>
    <div class="alert alert-<?php echo $notice[0] === 'ok' ? 'success' : 'error'; ?>" id="notice">
      <?php echo sanitize($notice[1]); ?>
    </div>
  <?php endif; ?>

  <!-- ------------------------------------------------- chassis lookup strip -->
  <?php if (!$isFixedPrice): ?>
    <?php /* One box, and it answers here.

             There were two. Both were searches of our own list, both landed the
             reader back on the auction page they had just left, and neither
             could do the thing the labels implied: the feed carries a model
             code beside the engine size - NHP10, ZVW30, JF1 - and never the
             whole chassis number, so nothing of ours can be found by one.

             jpauc answers a different question at /vin, and it is the question a
             buyer holding a document actually asks: what year was this chassis
             built. Maker, the code, the number after it, and it gives back the
             year and month, the model name, the grade code and the seats. Same
             shape as jpauc's own page, and the answer appears under the box
             rather than on another screen.

             The request goes through api/prod-year.php because jpauc's answer
             carries no cross-origin header and the browser cannot ask it. */ ?>
    <?php
      $vinMakers = array('DAIHATSU', 'HONDA', 'ISUZU', 'MAZDA', 'MITSUBISHI',
                         'NISSAN', 'SUBARU', 'SUZUKI', 'TOYOTA');
      $vinMake   = strtoupper(trim((string) ($car['make'] ?? '')));
      if (!in_array($vinMake, $vinMakers, true)) { $vinMake = ''; }
    ?>
    <div class="pb-vin" id="pbVin">
      <div class="pb-vin-head">
        <b>Prod. Year</b>
        <span>Year of manufacture from a chassis number</span>
      </div>
      <div class="pb-vin-row">
        <label for="vinType">Chassis number</label>
        <?php // The model code on its own: Pacific Boeki writes some with a prefix
              // (QDF-KDY221), and the box's number is KDY221-..., not QDF-... ?>
        <input type="text" id="vinType" autocomplete="off" placeholder="ANHXX"
               value="<?php echo sanitize(chassisParts($car['chassis'] ?? '')[0]); ?>">
        <span class="pb-vin-dash">&ndash;</span>
        <input type="text" id="vinNo" autocomplete="off" placeholder="8XXXXXX">
        <label for="vinMaker" class="pb-vin-l2">Maker</label>
        <select id="vinMaker">
          <option value="">— select maker —</option>
          <?php foreach ($vinMakers as $mk): ?>
            <option value="<?php echo $mk; ?>"
              <?php echo $vinMake === $mk ? 'selected' : ''; ?>><?php echo $mk; ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="pb-btn" id="vinGo">Check</button>
        <?php /* The other question, asked without a document.
                 Most of the time the reader is not holding a chassis number at
                 all - they are looking at this car and want to know what else
                 like it is in the sale. The model code is already in the box, so
                 that answer costs nothing and asks jpauc nothing. */ ?>
        <button type="button" class="pb-btn pb-vin-ghost" id="vinStockGo">Our stock</button>
      </div>
      <p class="pb-vin-note" id="vinNote" hidden></p>
      <table class="pb-vin-tbl" id="vinTbl" hidden>
        <thead>
          <tr><th>Year / Month</th><th>Model Name</th><th>Grade Code</th><th>Seat</th></tr>
        </thead>
        <tbody></tbody>
      </table>
      <?php /* The rest of the same answer, and then our own stock.

               jpauc's page prints four columns and drops what else it was sent -
               the engine number, the grade, the paint and trim codes, the body
               and drive, the catalogue number. All of it arrives in the one
               request the Check button already makes, so all of it is shown.

               And underneath, the half jpauc has no way to give: the lots WE
               hold of that model, with their lot numbers, halls and sale days,
               each one openable. The feed carries the model code and not the
               whole number, so the code is what joins a buyer's document to our
               catalogue - which is what the client asked this panel to do. */ ?>
      <div class="pb-vin-more" id="vinMore" hidden></div>
      <div class="pb-vin-stock" id="vinStock" hidden></div>
    </div>
  <?php endif; ?>

  <!-- --------------------------------------------------- sheet and photos -->
  <div class="pb-body<?php echo $sheet === null ? ' is-solo' : ''; ?>"<?php echo $ussAsk ? ' data-uss="' . $ussAsk . '"' : ''; ?>>

    <?php if ($sheet !== null): ?>
    <div class="pb-sheet">
      <?php if (true): ?>
        <img src="<?php echo sanitize($sheet); ?>"
             data-zoom="<?php echo sanitize($sheet); ?>"
             alt="Auction inspection sheet">
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="pb-shots">
      <div class="pb-stage">
        <?php if ($photos): ?>
          <?php // The frame shows the height the column needs; the zoom asks the
                // host for the file itself, which is half as wide again. They were
                // the same address, so opening a picture returned the picture
                // already on the screen. ?>
          <img id="stageImg" src="<?php echo sanitize($photos[0]); ?>"
               data-zoom="<?php echo sanitize(fullSizeImage($photos[0])); ?>"
               alt="<?php echo sanitize($title); ?>">
        <?php else: ?>
          <div class="noimg">No photo available</div>
        <?php endif; ?>
      </div>

      <?php if ($photos): ?>
        <?php /* Every slot the set may hold, printed here.
                 Discovering the count in the browser instead - ask for the next
                 only when the last arrived - saved eleven wasted requests a
                 view, and lost the thumbnail strip: the owner opened a lot and
                 found one photograph where there had been a row of them. It
                 went out untested, because there was no browser to test it in.
                 Printed again, then, and photos.js takes out the slots that
                 answer 404. The waste is real and measured - eighteen requests
                 a view where seven would do - but a strip that is there beats a
                 strip that might be. */ ?>
        <div class="pb-thumbs">
          <?php foreach ($photos as $i => $p): ?>
            <?php // three sizes, one picture: the strip's own, the one the frame
                  // takes when this slot is chosen, and the original for zooming ?>
            <img src="<?php echo sanitize($thumbs[$i] ?? $p); ?>"
                 data-full="<?php echo sanitize($p); ?>"
                 data-zoom="<?php echo sanitize(fullSizeImage($p)); ?>"
                 class="thumb<?php echo $i === 0 ? ' active' : ''; ?>"
                 alt="View <?php echo $i + 1; ?>">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$isFixedPrice): ?>
        <p class="pb-deadline">
          <b>Deadline</b>
          Bid : 2 Hours Before<br>
          Bid Cancel: 1 Hour Before
        </p>
      <?php endif; ?>
    </div>

  </div>

  <div class="pb-bottom">

    <?php // the shorthand on the auction sheet, spelled out
          if (!$isFixedPrice) { require __DIR__ . '/includes/inspection-legend.php'; } ?>

    <!-- side -->
    <div class="pb-bidrow">

    <div class="detail-side">



      <!-- ------------------------------------------------------------- bidding -->
      <div class="panel bidbox" id="bid">
        <h3>Make a Bid</h3>
        <?php
          // The panel keeps its full shape whether or not this viewer can bid.
          // The reference portal shows every line - the charges, the estimate,
          // the balance - because a buyer weighing a lot wants to see what a bid
          // would cost before deciding to place one. Hiding the lot behind a
          // one-line notice answered a different question than they asked.
          /* Bids are OPEN on every lot the portal lists, for customers and
             staff alike — the owner's instruction of 11 September 2026. What
             still stops a bid is only what makes it meaningless: fixed-price
             stock (nothing is auctioned), a lot with a result or whose whole
             sale day is over, and nobody being signed in to own the bid. */
          $bidReason = '';
          if (!$canBid) {
              $bidReason = 'This lot is no longer in the auction — '
                         . lotStatusLabel($car)
                         . ($car['sold_price'] > 0 ? ' at ¥' . number_format($car['sold_price']) : '')
                         . '. Bidding is closed.';
          } elseif (!$client && !$staff) {
              $bidReason = 'Please sign in to place a bid.';
          }
          $live = ($bidReason === '');
          $serviceFee = 20000;
          $bidStart = $car['price'] > 0 ? (int) $car['price'] : 0;

          /* Told, not stopped. Where the sale may already have happened — its
             time has passed, or its hall publishes none and the sale day has
             begun — the buyer can still bid, and is told the desk will confirm
             the car is still there before the bid goes to the hall. */
          $bidWarn = '';
          if ($live && lotSaleMayHaveRun($car)) {
              $tm = trim((string) ($car['auction_time'] ?? ''), " \t[]");
              $timed = preg_match('/^(\d{1,2}):(\d{2})/', $tm, $mm)
                    && !((int) $mm[1] === 0 && (int) $mm[2] === 0);
              $bidWarn = $timed
                  ? 'This lot\'s auction time has passed. You can still bid — our desk will confirm whether the car is still available before your bid goes in.'
                  : 'This hall does not publish a time for its lots and today is the sale day. You can still bid — our desk will confirm whether the car is still available.';
          }
        ?>

        <?php if ($bidReason !== ''): ?>
          <p class="bid-why"><?php echo sanitize($bidReason); ?></p>
        <?php elseif ($bidStart > 0): ?>
          <?php /* No window - the owner's instruction of 12 September 2026: a bid
                   can be any amount. The start price is shown as information,
                   not as a limit. */ ?>
          <p class="bid-why is-ok">
            Start price <b>¥<?php echo number_format($bidStart); ?></b> — bid any amount;
            our desk confirms every bid.
          </p>
        <?php else: ?>
          <p class="bid-why is-ok">
            No start price has been published for this lot — enter your bid and
            our desk will confirm it.
          </p>
        <?php endif; ?>
        <?php if ($bidWarn !== ''): ?>
          <p class="bid-why bid-warn"><?php echo sanitize($bidWarn); ?></p>
        <?php endif; ?>

        <form method="POST" class="bid-form"<?php echo $live ? '' : ' onsubmit="return false"'; ?>>
          <input type="hidden" name="action" value="bid">

          <div class="bid-row">
            <select disabled><option>BID</option></select>
            <?php /* Any amount. No max, and step="any": a step of 1000 counted
                     from a start price made the browser itself refuse ordinary
                     numbers ("the two nearest valid values are…") before the bid
                     ever left the page. */ ?>
            <input type="number" name="amount" id="bidAmount" step="any" min="1"
                   <?php echo $live ? '' : 'disabled'; ?>
                   value="<?php echo sanitize($_POST['amount'] ?? ($bidStart > 0 ? (string) $bidStart : '')); ?>">
            <select disabled><option>JPY</option></select>
          </div>

          <div class="bid-row">
            <label>Over Bid:</label>
            <input type="number" id="overBid" step="1000" <?php echo $live ? '' : 'disabled'; ?>>
            <select disabled><option>JPY</option></select>
          </div>

          <dl class="bid-facts">
            <dt>Auction Charges:</dt><dd>Ask Rate</dd>
            <dt>Service Charges:</dt><dd><?php echo number_format($serviceFee); ?> JPY</dd>
            <dt>Approx FOB:</dt><dd><b id="bidFob">—</b> JPY</dd>
          </dl>

          <select class="bid-group" disabled><option>No Group</option></select>

          <label class="bid-msg-l">Message <i>(<span id="bidChars">0</span> / 1000)</i></label>
          <textarea id="bidMsg" maxlength="1000" rows="4" <?php echo $live ? '' : 'disabled'; ?>></textarea>

          <?php // It needs a name to be posted at all - without one the server
                // never saw whether it had been ticked. ?>
          <label class="bid-tc">
            <input type="checkbox" id="bidTc" name="agree" value="1" <?php echo $live ? '' : 'disabled'; ?>>
            I agree to <a href="#" onclick="return false">Terms &amp; Conditions</a>
          </label>

          <p class="bid-balance">Balance: <span>&ndash;&ndash;</span></p>

          <div class="bid-acts">
            <button type="submit" class="pb-btn"<?php echo $live ? '' : ' disabled'; ?>>Make Bid</button>
            <a class="pb-btn pb-btn-2" href="dashboard-client.php">Add Funds</a>
          </div>
        </form>
      </div>



      <!-- ------------------------------------------------------------ enquiry -->


    </div>

    <!-- ------------------------------------------------- auction FOB price -->
    <div class="panel pb-fob" id="fob">
      <h3>Auction FOB Price</h3>
      <?php
        // The desk's own shipping rates.
        //
        // The four fixed charges below are shown but not editable. They are
        // SBK's prices, not a buyer's assumption, and a buyer who can type over
        // them can produce a total the desk will not honour and then quote it
        // back. What stays open is what genuinely varies per buyer: the bid,
        // the inspection body, L/C and vanning.
        $fobRates = array(
            'transport'  => 15000,
            'clearance'  => 20000,
            'radiation'  => 1500,
            'range'      => 20000,
            'service'    => 20000,
        );
        $bidStart = $car['price'] > 0 ? (int) $car['price'] : 100000;
      ?>
      <table class="pb-fob-t">
        <tr class="h"><th>Description</th><th>JPY</th></tr>
        <tr>
          <td>Your Bidding Amount</td>
          <td><input type="number" id="fobBid" value="<?php echo $bidStart; ?>" step="1000"></td>
        </tr>
        <tr class="h2"><td colspan="2">Choose Vehicle</td></tr>
        <tr>
          <td colspan="2"><label class="pb-rd">
            <input type="radio" name="fobKind" value="car" checked> Passenger Car, SUV and Van
          </label></td>
        </tr>
        <tr>
          <td colspan="2"><label class="pb-rd">
            <input type="radio" name="fobKind" value="truck"> Truck, Big Truck
          </label></td>
        </tr>
        <tr>
          <td>Domestic Transportation Fee</td>
          <td><input type="number" class="fobN is-fixed" id="fobTransport" readonly tabindex="-1"
                     value="<?php echo $fobRates['transport']; ?>"></td>
        </tr>
        <tr>
          <td>Custom Clearance</td>
          <td><input type="number" class="fobN is-fixed" id="fobClearance" readonly tabindex="-1"
                     value="<?php echo $fobRates['clearance']; ?>"></td>
        </tr>
        <tr>
          <td>Inspection
            <select id="fobInspect">
              <option value="0">None</option>
              <option value="15000">JAAI</option>
              <option value="20000">JEVIC</option>
            </select>
          </td>
          <td><span class="fobOut" id="fobInspectOut">0</span></td>
        </tr>
        <tr>
          <td>L/C <input type="checkbox" id="fobLc" data-fee="8000"></td>
          <td><span class="fobOut" id="fobLcOut">0</span></td>
        </tr>
        <tr>
          <td>Vanning Charges <input type="checkbox" id="fobVan" data-fee="12000"></td>
          <td><span class="fobOut" id="fobVanOut">0</span></td>
        </tr>
        <tr>
          <td>Radiation</td>
          <td><input type="number" class="fobN is-fixed" id="fobRadiation" readonly tabindex="-1"
                     value="<?php echo $fobRates['radiation']; ?>"></td>
        </tr>
        <tr>
          <td>Range 10</td>
          <td><input type="number" class="fobN is-fixed" id="fobRange" readonly tabindex="-1"
                     value="<?php echo $fobRates['range']; ?>"></td>
        </tr>
        <tr class="tot">
          <td>FOB Cost :</td>
          <td><span id="fobTotal">0</span></td>
        </tr>
      </table>
      <p class="pb-fob-note">
        Indicative only. Auction charges are quoted by the desk on the day.
      </p>
    </div>
    </div>


  </div>


  <div class="pb-zip pb-zip-right">
    <?php // Every picture the feed sent plus the spec sheet, in one go - a buyer
          // forwarding a lot to a colleague should not save a dozen files by
          // hand, and pictures on their own do not say which car they are. ?>
    <a class="pb-btn" href="api/images-zip.php?id=<?php echo (int) $car['id']; ?>">
      Download all images + PDF in ZIP file
    </a>
  </div>

  <div class="pb-enquiry">
      <div class="panel" id="enquiry">
      <h3>Send enquiry</h3>
      <?php if (!$client): ?>
        <p class="hint">
          Customer enquiries on this lot arrive in the
          <a href="admin/inquiries.php">admin panel</a>.
        </p>
      <?php else: ?>
      <form method="POST" class="stack">
        <input type="hidden" name="action" value="inquiry">
        <textarea name="message" class="form-textarea" required
                  placeholder="Ask about condition, shipping, or total landed cost…"><?php
          echo isset($_POST['message']) && ($notice[0] ?? '') === 'err' ? sanitize($_POST['message']) : '';
        ?></textarea>
        <button type="submit" class="btn btn-dark btn-full">Send Enquiry</button>
        <p class="hint" style="font-size:12px">
          Goes straight to our team with this lot attached. Replies arrive on your
          <a href="dashboard-client.php">dashboard</a>.
        </p>
      </form>
      <?php endif; ?>
    </div>
  </div>


  <?php
  $related_result = getActiveCars(1, 8, array('make' => $car['make'], 'model' => $car['model']));
  $related = array_values(array_filter($related_result['cars'], function ($c) use ($car) {
      return $c['id'] !== $car['id'];
  }));
  $related = array_slice($related, 0, 4);
  ?>
  <?php if ($related): ?>
    <div class="section-head">
      <h2>More <?php echo sanitize($car['make'] . ' ' . $car['model']); ?></h2>
      <span class="rule"></span>
      <a href="index.php?make=<?php echo urlencode($car['make']); ?>&amp;model=<?php echo urlencode($car['model']); ?>"
         class="btn btn-ghost">View all</a>
    </div>
    <div class="cars-grid">
      <?php foreach ($related as $rc): ?>
        <?php
          $rShots = getCarImages($rc, 320);
          $rt = trim($rc['make'] . ' ' . $rc['model']);
        ?>
        <article class="car-card">
          <div class="car-photo">
            <?php if ($rShots): ?>
              <img src="<?php echo sanitize($rShots[0]); ?>"
                   data-zoom="<?php echo sanitize($rShots[0]); ?>"
                   alt="<?php echo sanitize($rt); ?>" loading="lazy">
            <?php else: ?><div class="noimg">No photo</div><?php endif; ?>
            <span class="chip chip-status <?php echo statusClass($rc['status']); ?>">
              <?php echo sanitize(statusLabel($rc['status'])); ?>
            </span>
          </div>
          <?php if (count($rShots) > 1): ?>
            <div class="pb-more-shots">
              <?php foreach ($rShots as $rs): ?>
                <img src="<?php echo sanitize($rs); ?>" data-zoom="<?php echo sanitize($rs); ?>"
                     alt="<?php echo sanitize($rt); ?>" loading="lazy">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="car-body">
            <a href="car-details.php?id=<?php echo $rc['id']; ?>"><h3 class="car-title"><?php echo sanitize($rt); ?></h3></a>
            <div class="car-grade">Lot <?php echo sanitize($rc['lot_no']); ?></div>
            <div class="car-meta">
              <?php if ($rc['year']): ?><span class="tag"><?php echo intval($rc['year']); ?></span><?php endif; ?>
              <?php if ($rc['mileage']): ?><span class="tag"><?php echo number_format($rc['mileage']); ?> km</span><?php endif; ?>
            </div>
            <div class="car-foot">
              <div class="price <?php echo $rc['price'] > 0 ? '' : 'is-none'; ?>">
                <small>Start price</small>
                <?php echo $rc['price'] > 0 ? '¥' . number_format($rc['price']) : 'On request'; ?>
              </div>
              <a href="car-details.php?id=<?php echo $rc['id']; ?>" class="btn btn-secondary">Details</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php // The scroll-to-the-notice that used to live here has gone with the
      // notice. Messages are toasts now: they come to the reader at the top of
      // the window, so there is nothing to scroll to. ?>
<?php require_once 'includes/footer.php'; ?>
