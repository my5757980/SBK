<?php
/**
 * Sweep JPAuc into the catalogue - both the Oneprice stock and the auction.
 *
 * This used to run inside the owner's browser, because the previous supplier
 * refused this server's address and everyone assumed JPAuc would too. Nobody
 * tested that assumption. It does not: this server reaches jpauc.com and gets
 * a full page back. So the work moved here, and with it went the browser tab
 * that had to stay open, the VPN, and the harvester that died whenever the tab
 * reloaded.
 *
 * A guest may not filter. A sale day on its own returns nothing, an auction
 * hall on its own returns nothing, and passing either alongside lot numbers
 * changes nothing - both are ignored. Only `lots` is honoured, so coverage is
 * a walk over lot numbers, ten vehicles to a page.
 *
 * Run it from cron. Each call does a bounded number of requests and writes its
 * position down, so the next call resumes mid-range rather than starting over:
 *
 *     php jpauc-harvest.php --max=50
 *
 * or over HTTP with the token, which is how it is driven interactively:
 *
 *     jpauc-harvest.php?t=TOKEN&max=50
 */

require_once __DIR__ . '/includes/config.php';

/* The token is not written here any more (21 September 2026): it lives in the
   server's .env, the one file that already knows the database password and is
   never committed. An empty token refuses everything rather than letting an
   empty one in - hash_equals('', '') is true, and that would be an open door. */
define('HARVEST_TOKEN', env_get('HARVEST_TOKEN', ''));
const HARVEST_STATE = __DIR__ . '/jpauc-harvest-state.json';
const HARVEST_LOCK  = __DIR__ . '/jpauc-harvest.lock';

/** Written by jpauc-pulse.php: the source's own stock counters. */
const HARVEST_PULSE = __DIR__ . '/jpauc-pulse-state.json';

/**
 * The two sections, and where each one's vehicles end up.
 *
 * `japan` is the auction list the portal shows and bids on; `oneprice_mix` is
 * fixed-price stock, which is never auctioned and lives on its own page.
 */
function sections() {
    return array(
        'oneprice' => array(
            'url'     => 'https://jpauc.com/oneprice/search',
            'section' => 'oneprice_mix',
            'parse'   => 'parseOneprice',
        ),
        'auction' => array(
            'url'     => 'https://jpauc.com/auction/search',
            'section' => 'japan',
            'parse'   => 'parseAuction',
        ),
    );
}

/** Lot numbers run out here - probing above it returned nothing. */
const LOT_MAX = 99999;

/** Lots asked for at once when actually reading vehicles. Small enough that a
 *  batch is genuinely exhaustible. */
const LOT_BATCH = 40;

/**
 * Lots asked about at once when only the count is wanted.
 *
 * A page states its own total whatever the range, and a thousand lots answer
 * as quickly as forty - so a whole section can be surveyed in a hundred
 * requests instead of two and a half thousand. Two thousand lots make the
 * address too long and the server refuses it.
 */
const SCAN_BLOCK = 1000;

/** How stale a survey may get before it is worth repeating. The auction is
 *  where money moves and its sale days are only days away; fixed-price stock
 *  has no deadline at all. */
/**
 * How much of the day's budget one section may spend.
 *
 * The auction goes first when both want work - an auction lot is sold within
 * days of being listed and is worthless to us after, while fixed-price stock
 * sits until somebody buys it. But "first" was written as "always", and the
 * source adds five to eight thousand auction lots a day, so the auction was
 * never once finished and oneprice never got a single request: it sat at
 * 142,776 against a source holding 161,481 while the auction ran all day.
 *
 * An allowance fixes that without slowing anything down - the same requests,
 * spent so that both sections move.
 *
 * Forty to the auction, sixty to oneprice. oneprice gets the larger share
 * because its work is the larger job: it has no day to divide by and no filter
 * the source will honour - I checked both rather than assuming - so seeing all
 * of it costs about 15,000 requests however it is arranged, and at forty per
 * cent it could not finish a pass inside a day, which is why it sat on 4,879
 * vehicles the source had already dropped.
 *
 * A quarter to the auction was tried first and was too little. The estimate
 * behind it assumed the auction was nearly level and only had a night's
 * turnover to fetch; instead the source added 5,457 lots during the day, the
 * auction got 516 requests in seven hours, and its shortfall grew from 18,537
 * to 22,627 while oneprice spent 8,797. Forty per cent is 8,800 - about three
 * times what the auction needs - and it hands back whatever it does not use, so
 * the margin costs oneprice nothing.
 *
 * A section that has spent its allowance yields to the other only if the other
 * has work to do, and a level auction yields everything - nothing is left
 * unspent while there is something to fetch.
 *
 * @param string $section 'auction' or 'oneprice'
 * @return int requests
 */
function sectionRatio($section) {
    // All of it to the auction, by the owner's instruction, until the auction
    // matches the source. Both sections changed the view they read from on the
    // same afternoon; the auction is the one a customer opens first, so it goes
    // right first and oneprice waits rather than sharing.
    //
    // Oneprice is not stopped - it keeps what it has and is read again the
    // moment this returns to a share. Put 0.85/0.15 back then.
    return $section === 'auction' ? 1.0 : 0.0;
}

function sectionShare($section) {
    return (int) round(DAILY_BUDGET * sectionRatio($section));
}

/**
 * Is there anything for this section to do right now?
 *
 * Mid-block, mid-survey, due a fresh survey, or holding a block that is short
 * of what the source says it holds. Anything else means level, and a level
 * section should not be handed a turn it cannot use.
 *
 * @return bool
 */
function sectionWants($conn, $s, $secs, $section) {
    $st = $s[$section];
    if ($st['fillTo'] > 0 || $st['scanNext'] >= 0 || $st['scanAt'] === '') {
        return true;
    }
    if (strtotime($st['scanAt']) < time() - rescanAfter($section)) {
        return true;
    }
    return neediestBlock($conn, $secs[$section]['section'], $st['blocks']) !== null;
}

function rescanAfter($section) {
    return $section === 'auction' ? 1800 : 7200;   // 30 minutes / 2 hours
}

/**
 * Lot numbers in one day-request.
 *
 * The page returns ten vehicles however many lots are asked for, so a wide ask
 * costs nothing extra and saves every request that would have gone into opening
 * a range the day holds nothing at.
 *
 * Six hundred, not the fifteen hundred it was: the lot numbers go into the URL
 * and a five-digit one costs six characters, so fifteen hundred of them made a
 * nine-kilobyte address and the server answered 414 rather than reading it. It
 * was tested at fifteen hundred over lots 1 to 1500 - four digits, five and a
 * half kilobytes - which fit, and every band above lot 10,000 did not. The
 * auction spent the day failing on them.
 */
const DAY_BATCH = 600;

/**
 * Listing pages asked for at once.
 *
 * Two, and it is worth writing down why not more, because the arithmetic says
 * more and the arithmetic is wrong here. The source takes six or seven seconds
 * to build a listing page, so six in flight ought to be a page every 1.17
 * seconds. Measured over the same stretch of the listing:
 *
 *     one at a time    7.6 seconds a page
 *     two at a time    7.1
 *     six at a time    7.5
 *
 * It serves us one page at a time whatever we ask for - six in flight took
 * thirty-three seconds, which is six sixes end to end. So the seven seconds is
 * the source's, not ours, and no amount of parallelism touches it.
 *
 * One, in the end, and the reason is worse than "no gain". Watched over half an
 * hour of the rebuild it was spending 2.1 requests for every page it advanced:
 * the second page of each pair was not being kept - it comes back on a cookie
 * the first handle may still be rewriting - so half the requests bought
 * nothing. One page a request, and the pass moves at the rate the requests are
 * actually going out.
 */
const LISTING_BATCH = 1;

/**
 * How many pages back from the end of the listing the tail check may walk.
 *
 * It stops on its own as soon as it reaches ids it has already seen, so this is
 * only a guard against a first run - or a run after a long silence - reading
 * the whole listing backwards a page at a time. Thirty pages is three hundred
 * vehicles, which is more than an hour's arrivals have ever been.
 */
const TAIL_MAX = 30;

/**
 * A minute-by-minute record of what the source is holding.
 *
 * The owner asked a fair question that could not be answered from the code:
 * how fast does jpauc actually list vehicles? Everything here is built for a
 * worst case - thousands arriving at once - and nobody had ever watched to see
 * whether that happens. Fourteen minutes of watching showed nothing move at
 * all, which is an observation, not an answer.
 *
 * The tail check already reads the listing's total every minute. Writing it
 * down costs nothing and no request, and a day of it says plainly whether
 * arrivals come in a trickle or a flood - and so whether "in the portal within
 * a minute" is a promise that can be kept.
 *
 * One line a minute is 1,440 a day; the file is trimmed to a week.
 */
const PULSE_LOG = __DIR__ . '/jpauc-source-pulse.log';

/** Seconds between tail checks - how long a newly listed vehicle waits. */
const TAIL_EVERY = 60;

/**
 * Pages of nothing new that end the tail walk.
 *
 * Three, because one was wrong. New ids are not a single unbroken block at the
 * end of the listing - a page of older ones can sit between two pages of new
 * ones - and stopping at the first of those left 5,373 of a new sale day's
 * 6,181 vehicles unread, and marked as seen.
 *
 * Three pages is thirty vehicles of proof, and costs two extra requests on an
 * ordinary quiet check.
 */
/* How many pages we already hold in full may pass before the walk gives up.

   Three was right while the tail was only ever looking for the newest
   arrivals - a handful of pages at the very end. It is far too few for the
   thing it actually has to do: a sale day published late sits in the MIDDLE of
   the listing, behind hundreds of pages we do hold, and three quiet pages stop
   the walk long before it gets there.

   Eight hundred is not a stretch, it is "keep going". Together with the page
   cap below and tailBack, that turns the tail into what it now has to be: a
   sweep of the catalogue in reverse, sixty pages a run, taking up where it left
   off, resetting to the end when it reaches the front.

   The forward pass and the reverse tail then meet somewhere in the middle, and
   a sale day published anywhere in the listing is found by whichever of them is
   nearer to it. On 4 September the missing block sat at page 4,300 of 4,906 -
   six hundred pages from the end and two thousand seven hundred from the pass.
   The tail reaches it in ten minutes; the pass would have taken five hours. */
const TAIL_QUIET = 800;

/* And how many pages one run's tail walk may read.

   Without this the walk runs until the run's clock stops it, which is the whole
   run - and the pass, which is the only thing that reads the catalogue whole,
   gets nothing. That mistake has been made twice in this file already.

   Sixty is about what the pass reads in a run, so the two share the source
   evenly and neither can starve the other. */
const TAIL_PAGES = 60;

/* How far apart the walk samples when it is only looking.

   Reading the catalogue page by page is the wrong shape of work. Five thousand
   seven hundred pages take four and a half hours and most of them come back
   holding vehicles we already have - on 5 September the portal was 22,541 short
   and gaining 5,000 while the source gained 8,000, so the gap grew while the
   walk was busy re-reading what it already knew.

   A missing sale day is not scattered; it is a block, because the listing is
   ordered by sale time. So one page in twenty is enough to find it: the sample
   either holds vehicles we are missing, in which case the nineteen pages behind
   it are read too, or it does not, in which case there is nothing there and the
   walk moves on. Scanning the whole catalogue costs 286 requests instead of
   5,710 - about a quarter of an hour instead of four and a half. */
const TAIL_STRIDE = 20;

/**
 * Seconds between checks of the houses that are actually selling.
 *
 * All forty-one are swept every ten minutes, but on a sale day only two to five
 * of them are moving - the rest are days away from their auction and do not
 * change at all. The ones that moved last time are asked again every minute,
 * which is two or three requests, and it is what puts a sold vehicle off the
 * portal in about a minute instead of ten.
 */
const HOT_EVERY = 60;

/* The same, while a hall is being read out.
 *
 * A round of the counts is two turns and about fifty seconds of a run that only
 * has two hundred and eighty-five. Every minute, that is most of what the read
 * would otherwise have had - Chubu's 475 pages were moving at twelve a minute.
 *
 * The counts are worth that when they are the only thing watching a sale. They
 * are not, while a read is running: the read is looking at a hall directly, and
 * what the counts would find in the meantime is a hall to queue, which can wait
 * three minutes. The moment the read finishes they go back to the minute. */
const HOT_BUSY = 180;

/* How far a hall may run ahead of us before we go and read it.

   A hall's count is asked every minute anyway, and until now the answer was
   only ever used one way round: fewer at the source than we hold means
   something has sold. More at the source than we hold means the opposite - a
   hall has published a catalogue the pass has not reached - and that was left
   for the pass to find on its way round.

   Which it does, in about eight hours. USS Tokyo put up 10,302 vehicles for a
   single sale day; we held 923 of that hall and not one for that day, and the
   portal showed 33,050 against the source's 39,910. Every other hall was within
   a few hundred. The whole difference the owner kept asking about was one hall
   the pass had not got to yet.

   Filtered to the hall, those ten thousand are a thousand pages instead of four
   thousand, and nothing else is waiting behind them. The slack is there so a
   hall a few vehicles out - which is ordinary, between a count and a write -
   does not start a read of the whole hall.

   Thirty. It was two hundred for a while, on the reasoning that a small gap
   closes by itself when the pass next comes past - which is true, and takes
   eight to twelve hours. That is the wrong answer for the thing the owner
   actually asked for: the vehicles a hall adds during the day, thirty or fifty
   at a time, arriving in the portal while they still matter.

   The cost of a small gap is real - the hall has to be read whole, because the
   source will not say which vehicles are new - but a hall is a hundred to two
   hundred pages and the run is not short of budget: measured at 0.27 requests a
   second against the one a second the owner set. It is time that is scarce, not
   requests, and a fill spends it on the one thing nothing else can do quickly.

   Below thirty is left to the pass. That far down it is as likely to be a count
   taken between two writes as a real arrival. */
const FILL_SLACK = 30;

/* The fill is off.

   It read a hall's own filtered listing, and that view is not the listing the
   portal is meant to match: the halls' counts add up to more than the listing
   declares - 40,556 against 39,910 when it was first measured - so taking
   vehicles from it walked the portal past the website. Within an hour the
   portal held 43,948 where the website showed 42,421, and the owner saw it.

   Nothing it took in was invented; every one is still at the source. They are
   simply not in the set a customer browsing jpauc sees, and matching that set
   is the whole instruction. A faster pass is the answer, not a different
   source. */
const FILL_ON = false;

/* Reconciling a hall against the listing, which is the only thing that can say
   a vehicle has gone.

   The counts say a hall holds fewer than we do. They cannot say WHICH of ours
   have gone, and that question was put to the source's lot search -
   /auction/search?lots=N - one vehicle at a time.

   The lot search is not the listing. It answers from a wider set. On 7 September
   MIRIVE Aichi listed 322 vehicles for the 11th and we held 342; every one of
   the twenty extra came back from the lot search as present. So the question
   always answered yes, nothing was ever taken off by it, and the nomination
   queue sat permanently full at thirty while the portal ran 2,744 ahead of the
   website and climbing. Tokyo alone was showing 161 lots already sold.

   The listing narrowed to one hall is the same listing a customer reads. So the
   hall is read out of it, and what the read does not contain is taken off. */
/* Reading the auction's own result, which is what actually takes a sold
   vehicle off the portal within a minute.

   The lot search - /auction/search?lots=N - was being asked whether a vehicle
   was still listed, and it always said yes, because it answers from a wider set
   than the listing. That is why nothing was ever removed.

   But presence was never the interesting part of its answer. Every row it
   returns carries the result of the sale:

       lot 8119   ... Start: 30,000   unsold | End: -
       lot 8204   ... Start:  1,000   sold   | End: -

   The parser has always read that column - `statusendprice` - and storeCars has
   always written it. The old code threw it away and looked at presence instead.

   That changes what is possible. Finding WHICH vehicles have gone by reading a
   hall out of the listing is 475 pages for Chubu and most of an hour. But we do
   not have to find them: we hold every lot's sale time already, so we know
   exactly which lots have just gone under the hammer, and one cheap request
   returns the result. About eighteen lots conclude a minute across the whole
   catalogue - eighteen requests, no session needed, and the vehicle leaves the
   portal on the next page load because sellableSql only shows 'available'.

   The hall read stays. It is what catches a lot WITHDRAWN days before its sale,
   which has no result to read and no sale time to watch for. */
const RESULT_ON = true;

/** How far back to keep asking for a result. A sale concluded three hours ago
 *  whose result the source has still not published is not going to arrive by
 *  asking again; it is left to the hall read, so that the newly concluded lots
 *  behind it are never held up. */
const RESULT_WINDOW = 10800;

/** Lot searches in one turn, and turns of them in one run. Eighteen lots a
 *  minute conclude at the busiest; seventy-two a run is four times that, so the
 *  queue is always empty before the cap is reached and the cap only matters if
 *  something has gone wrong. */
const RESULT_BATCH = 6;
const RESULT_TURNS = 12;

const RECON_ON = true;

/** How far over a hall must be before reading it out is worth the pages. Small,
 *  because a hall three over is three lots a customer cannot actually buy. */
const RECON_SLACK = 3;

/** How many more than the count's gap one reconciliation may ever take off.
 *
 *  The count says the hall lists 4,275 where we hold 4,750, so 475 have gone.
 *  The read says WHICH. Two witnesses, and the removal is held to what both
 *  agree on: at most the gap, plus a little for what sold while the read was
 *  running. A read that came back short then removes less than it should rather
 *  than more, and the next round takes the rest - which is the right way round,
 *  because a vehicle wrongly left on the portal is a page a customer can still
 *  see, and a vehicle wrongly taken off is one they cannot. */
const RECON_CAP = 20;

/* How long one hall may be held before it is put back.

   A hall is taken out of the queue, walked backwards page by page, and only
   given up when page one is reached. Nothing bounded that. Hiroshima was taken
   up at 14:01 and was still holding page 51 twenty hours later with forty-seven
   halls queued behind it, and while it held them the portal ran 3,885 vehicles
   ahead of the source. The walk itself is twenty-five minutes for the largest
   hall we have, so an hour in hand means something in front of it is taking its
   turns, and the queue is worth more than the half-read hall. */
const RECON_HOLD = 3600;

/* One repair, once, carried out by the server on its own.

   The fill above put vehicles into the portal that the website's own listing
   does not carry - 6,496 of them at the worst, and the owner saw the portal
   holding more than the source. Turning the fill off stopped it growing but
   could not undo it, and the ordinary retirement will not reach it for about
   five hours: a vehicle has to be missed by two consecutive complete passes
   before it counts as gone, and the fill touched these rows this morning, so
   they look freshly seen to the first of those two passes.

   Two passes is the right rule and it stays. This is a single exception for a
   single mistake: at the end of the very next complete pass, anything that pass
   did not see is taken off - which is safe precisely because that pass has just
   read the entire listing from first page to last. Then the tag is written into
   the state and this never runs again.

   It is here, in the harvester, rather than being done by hand, because the
   owner asked the obvious question: what happens if the computer is off. The
   answer has to be that nothing depends on it. */
const FIX_TAG = 'fill-surplus-2026-09-02';

/** Turns of a fill that may bring back no vehicles at all before it stops. */
const FILL_QUIET = 2;

/**
 * Seconds between full sweeps of all forty-one houses.
 *
 * This is not how fast a sold vehicle leaves the portal - HOT_EVERY is, and
 * that is a minute. This is how fast a house that has just STARTED selling is
 * noticed, because until a sweep sees it move it is not on the hot list and
 * nobody is asking it anything. So the worst case a customer could meet - the
 * first sale of the day at a house nobody was watching - was ten minutes.
 *
 * Five, by the owner's instruction, once he was shown that distinction. It
 * costs forty-one requests twice in ten minutes instead of once: about four
 * more a minute against the seventeen the pass already uses, well inside the
 * one-a-second he set. Making it truly one minute would mean asking all
 * forty-one every minute - three times the total traffic - and he was asked
 * directly and said no, for the same reason nikkyocars once blocked this host.
 */
const SWEEP_EVERY = 300;

/**
 * Seconds a browse session may be kept before it is opened again.
 *
 * The wizard's selection is fixed at the moment it is walked, and the sale days
 * on offer are part of it. When 1 September appeared, the session in hand had
 * been opened when there were three days and it went on serving three: 49,530
 * vehicles against the 50,472 a browser was being shown, and an entire sale day
 * invisible. Nothing in the listing says the selection has gone stale, so it is
 * simply not kept long enough for that to matter.
 */
const SESSION_EVERY = 1800;

/** Vehicles a guest sees per page. Not ours to change; it sets the floor on
 *  how many requests a complete sweep can possibly take. */
const PER_PAGE = 10;

/**
 * Seconds a single run may last, whatever it is doing.
 *
 * There was no limit at all, and a run that hung held the lock for four and a
 * half hours: nothing else could start, the catalogue stopped where it was, and
 * the portal slowed to twenty-second page loads under whatever the stuck
 * process was doing. set_time_limit(0) is no help - it stops PHP counting, not
 * the run going wrong.
 *
 * Forty-five seconds, and short for a second reason as well as the first.
 * A run spends nearly all of its life waiting on the source - six or seven
 * seconds a page - and while it waits it is holding one of the account's few
 * process slots. At four minutes the portal was answering its own visitors in
 * eight to twenty-one seconds, and a TCP connection alone was taking nine.
 * Paused for two minutes, the same page came back in 1.8. That is not the
 * database and not the code: it is one long-lived process too many on a shared
 * host.
 *
 * Short runs, often. The next one is two minutes away and resumes on the same
 * page, so nothing is lost but the waiting.
 *
 * Two hundred and forty, not two hundred and eighty, and the sixty seconds
 * bought back are worth more than the forty given up. Cron starts a run every
 * three hundred seconds; a run that overruns its start by even a little is
 * still holding the lock when the next one arrives, and that one exits rather
 * than wait - five minutes gone. It was visible in the numbers: twenty-two
 * pages a minute while a run was up, thirteen and a half averaged over half an
 * hour. Ending with a minute to spare means every tick finds the lock free.
 */
/* Two hundred and forty, not two hundred and eighty-five.

   The cron fires every three hundred seconds and this bounds the loop, not the
   run: after the loop there are still the closing counts and the summary, so a
   285-second loop is a 295-second script. The next cron then arrives while the
   lock is still held, exits without doing anything, and the one after that has
   to wait its turn - so half the firings do nothing at all. Measured on
   8 September: forty-four runs in eight hours where ninety-six were due, and
   0.2 requests a second against the one a second the owner allows.

   Sixty seconds of margin costs fifteen percent of a run and buys back half the
   day. */
const RUN_LIMIT = 240;

/**
 * Seconds waited after each request, on top of however long it took.
 *
 * Back to a whole second. Shortening it to a quarter bought perhaps ten per
 * cent on the reading and cost the portal its afternoon: with the wait cut, the
 * harvest holds its process almost continuously rather than letting go four
 * times a minute, and on this host that is the difference between visitors
 * getting a page in one second and in sixteen.
 *
 * It was never the number that mattered - the source takes six or seven seconds
 * to build a page whatever we do, so a second's wait is a seventh of the cycle.
 * What it buys is the process standing down regularly, which is what the rest
 * of the account needs from it.
 */
const REQ_GAP = 1.0;

/**
 * Requests a day, across all runs and both sections.
 *
 * This number is not a policy, it is arithmetic, and for a week it was set too
 * low to do the job while every attempt to make the reading cleverer was tried
 * instead. Each of those found a real fault and each of them helped, and none
 * of them could have worked, because:
 *
 *     the source publishes  238,457 vehicles
 *     a guest is shown           10 per request - no parameter changes it,
 *                                   thirteen were tried at two sizes each
 *     so one complete look   23,846 requests
 *
 * Being level MEANS having looked at all of it. At 22,000 a day one look cost
 * more than the day allowed, so the catalogue was permanently partway through a
 * pass - closing on the source, then falling back when the night's 30,000 to
 * 40,000 new lots landed. No arrangement of requests fixes a budget smaller
 * than the thing being read.
 *
 * Forty-five thousand, agreed with the owner on 26 August: one full pass with
 * most of the day still spare, which is what a churning source needs. The gap
 * between requests is unchanged at one second - the only rate the source
 * actually feels - so this is twelve and a half hours of asking rather than
 * six, and no burst is faster than it has ever been. The previous supplier cut
 * us off after a fifteen-fold raise in one jump, to 149,000; this is under a
 * third of that number and reached by doubling, not by jumping.
 */
const DAILY_BUDGET = 45000;

/**
 * Included rather than run.
 *
 * Something has to be able to hold the parser up against what is stored and
 * say whether they agree - the only way to know a field is missing because the
 * source never sent it, rather than because the reading dropped it. Defining
 * JPAUC_HARVEST_LIB before the include gives a caller the parsing and nothing
 * else: no token, no budget, no writing.
 */
$asLib = defined('JPAUC_HARVEST_LIB');

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && !$asLib) {
    header('Content-Type: text/plain; charset=utf-8');
    if (HARVEST_TOKEN === '' || !hash_equals(HARVEST_TOKEN, (string) ($_GET['t'] ?? ''))) {
        http_response_code(404);
        exit;
    }
}

$opts = $isCli ? getopt('', array('max::', 'reset::', 'from::', 'sec::')) : $_GET;

// Keep a run comfortably shorter than the hosting's own request timeout. A
// longer one is killed mid-flight while PHP carries on in the background, so
// the next call starts on top of a run that never finished - which is how a
// stack of overlapping processes earned us a 503 from our own host.
// A run has to be able to spend a five-minute slice of the day's budget:
// 45,000 over 288 runs is 156 apiece, and a cap of 90 quietly held the
// day to 26,000 however high the budget was set.
$maxRequests = max(1, min(200, (int) ($opts['max'] ?? 50)));

@set_time_limit(0);
ignore_user_abort(true);

/* One run at a time. Two runs share one cursor, so both walk the same pages
   and spend the day's budget twice over for one range's worth of vehicles.

   Not taken when included as a library: a caller that only wants the parser
   is not a run, and taking the lock would both block the real harvest and
   turn the caller away whenever the harvest was working. */
$lock = null;
if (!$asLib) {
    $lock = fopen(HARVEST_LOCK, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        // Held by a run still going - or by one that hung four and a half hours
        // ago and took the whole section down with it: nothing else could
        // start, the catalogue stopped where it was, and the portal slowed to
        // twenty-second page loads under whatever the stuck process was doing.
        //
        // flock dies with the process, so a lock older than any run may live is
        // held by something that is not coming back. Replacing the file leaves
        // that process holding a lock on an inode nobody can reach any more,
        // and the new run takes one of its own.
        $age = is_file(HARVEST_LOCK) ? time() - (int) filemtime(HARVEST_LOCK) : 0;
        if ($age < RUN_LIMIT * 2) {
            echo "pehle se chal raha hai - chhor diya\n";
            exit;
        }
        @fclose($lock);
        @unlink(HARVEST_LOCK);
        $lock = fopen(HARVEST_LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo "lock nahi mila\n";
            exit;
        }
        echo "purana lock hataya ({$age} sec purana)\n";
    }
    touch(HARVEST_LOCK);
    register_shutdown_function(function () use ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    });
}

/* ------------------------------------------------------------------ state */

function stateLoad() {
    $fresh = array(
        'day'      => date('Y-m-d'),
        'used'     => 0,
        'runs'     => 0,
        'wrote'    => 0,
        'seen'     => 0,
        'halted'   => '',
        'cur'      => 'oneprice',
        // blocks:   first lot of each surveyed block => how many the source holds
        // scanNext: index of the next block to survey, -1 when the survey is done
        // fillTo:   last lot of the block currently being read, 0 when between blocks
        // `used` is per section as well as overall: the total says when the day
        // is done, the per-section figure says whose turn it is.
        // walk:     how far along the survey's blocks this pass has reached, so
        //           the next block is the next one in order rather than
        //           whichever is worst - see nextShortBlock()
        // passes:    completed passes over the whole lot range
        // passFrom: when the pass in progress began. Anything still carrying an
        //           older stamp when it finishes is gone from the source.
        // base: the browse listing's address, opened by walking the wizard
        // page: where the walk through that listing has got to
        'oneprice' => array('lot' => 1, 'page' => 1, 'passes' => 0, 'used' => 0, 'walk' => 0, 'passFrom' => '', 'passPrev' => '', 'base' => '', 'blocks' => array(), 'scanNext' => 0, 'scanAt' => '', 'fillTo' => 0),
        // The auction is worked a day at a time - see the day path in the loop.
        // days:   the source's own per-day counts, one request for all five
        // daysAt: when those were read
        // day:    the day being filled; want: the lot numbers still to ask for
        'auction'  => array('lot' => 1, 'page' => 1, 'passes' => 0, 'used' => 0, 'walk' => 0, 'blocks' => array(), 'scanNext' => 0, 'scanAt' => '', 'fillTo' => 0,
                            'days' => array(), 'daysAt' => 0, 'day' => '', 'want' => array(),
                            // real:  what a day actually holds, counted from the
                            //        bands as they were read - the front page's
                            //        figure is a different number entirely
                            // dayAt: when the day in hand began being read
                            'real' => array(), 'dayAt' => '', 'dayN' => 0,
                            'base' => '', 'passFrom' => '', 'passPrev' => '',
                            // houses: the listing's own auction houses
                            // hIdx:   how far the house count sweep has got, -1 idle
                            // hAt:    when the last sweep finished
                            'houses' => array(), 'hIdx' => -1, 'hAt' => 0,
                            // maxId:  the highest source row id we hold
                            // tailAt: when the end of the listing was last read
                            // hot:   the houses that were selling last time we looked
                            // hotAt: when those were last asked
                            // baseAt: when the browse session was opened
                            // countBase: a second session, used only for counting
                            // lastEnd:   the last credible page count, so a
                            //            collapsed total cannot end a pass
                            'maxId' => 0, 'tailAt' => 0, 'hot' => array(), 'hotAt' => 0, 'baseAt' => 0,
                            // daysKey: the sale days the session was opened with
                            // lanes: the further reading sessions, opened
                            // beside base and read from in parallel
                            // laneCap: how many lanes are currently allowed -
                            // lowered when sessions fail together, lifted again
                            // when a pass ends
                            'countBase' => '', 'lastEnd' => 0, 'daysKey' => '',
                            // tailBack: how far back a deep tail walk got,
                            // so a run out of time takes it up again
                            // countLanes: sessions kept apart from the
                            // pass's, for the per-house counts
                            'lanes' => array(), 'laneCap' => PASS_LANES - 1,
                            // lastTotal: what the listing last declared
                            'tailBack' => 0, 'gapQ' => array(), 'countLanes' => array(),
                            // hotIdx: how far round the halls the counts have got
                            // toCheck: vehicles a hall's count has
                            //          nominated, waiting to be asked after
                            // fillHouse: a hall being read on its own because
                            //            it holds more than we do
                            'lastTotal' => 0, 'hotIdx' => 0, 'toCheck' => array(),
                            'fillHouse' => '', 'fillPage' => 1,
                            'fillEnd' => 0, 'fillQuiet' => 0,
                            'fillQueue' => array(), 'fillWait' => 0,
                            // recQ: halls the counts have found to be over,
                            //       waiting to be read out of the listing
                            // recHall/recPage: the hall in hand, and how far
                            //       back through its pages the read has got
                            // recFrom: the database moment that read began
                            'recQ' => array(), 'recHall' => '', 'recPage' => 0,
                            'recFrom' => '', 'recSeen' => 0, 'recBad' => 0,
                            'recListed' => 0, 'recWait' => 0, 'restIdx' => 0,
                            'tick' => 0),
    );
    if (!is_file(HARVEST_STATE)) {
        return $fresh;
    }
    $s = json_decode((string) file_get_contents(HARVEST_STATE), true);
    if (!is_array($s)) {
        return $fresh;
    }

    // An older state file carried a single cursor and no section. Carry that
    // position across BEFORE the defaults are merged in - filling the gaps
    // first makes the section cursor look present at lot 1, the migration
    // never fires, and days of walking are silently thrown away.
    if (isset($s['lot']) && !isset($s['oneprice'])) {
        $s['oneprice'] = array(
            'lot'    => (int) $s['lot'],
            'page'   => (int) ($s['page'] ?? 1),
            'passes' => 0,
        );
    }

    $s += $fresh;
    foreach (array('oneprice', 'auction') as $k) {
        $s[$k] = (isset($s[$k]) && is_array($s[$k])) ? $s[$k] + $fresh[$k] : $fresh[$k];
    }

    // The budget is a daily one, so it resets when the date does.
    if ($s['day'] !== date('Y-m-d')) {
        $s['day'] = date('Y-m-d');
        $s['used'] = 0;
        $s['wrote'] = 0;
        $s['seen'] = 0;
        $s['runs'] = 0;
        $s['oneprice']['used'] = 0;
        $s['auction']['used'] = 0;
    }
    return $s;
}

function stateSave($s) {
    file_put_contents(HARVEST_STATE, json_encode($s), LOCK_EX);
}

/**
 * The block we are furthest behind on, or null when nothing is short.
 *
 * Compares the survey's per-block totals against what the catalogue actually
 * holds for the same lot numbers, and returns the first lot of the block with
 * the largest shortfall - so the reading goes where the missing vehicles are
 * rather than where the cursor happens to have got to.
 *
 * A block we hold MORE of than the source is not short: vehicles withdrawn at
 * the source stay here until retired, and chasing that difference would reread
 * the same block forever.
 *
 * @return int|null first lot number of the block
 */
function heldPerBlock($conn, $section) {
    $have = array();
    // A row bearing the old parser's fingerprint does not count as held.
    //
    // The survey compares counts, and a block whose vehicles are all present
    // but all wrong shows no gap - so eleven thousand rows carrying merged
    // fields from before the parser was fixed would have sat there forever,
    // complete by the only measure being taken. Not counting them opens a gap,
    // the sweep goes to fill it, and every row in the block is rewritten.
    //
    // The fingerprint is what the old reading produced and the new one cannot:
    // a gearbox with a space in it ("FAT 61,000" - the mileage ran into it) and
    // a colour ending in the title ("PEARL N/A"). Real gearboxes are AT, FAT,
    // F5, C4 - letters and digits, never a space - so nothing sound is caught.
    // As the sweep repairs them the fingerprint stops matching and the gap
    // closes by itself.
    // NULL has to be spelled out. A construction machine has no gearbox and no
    // colour, so both are NULL - and `NOT LIKE` on NULL is NULL, not true, so
    // every machine would have counted as missing and its block would have
    // been swept again for ever without anything changing.
    // Only lots the source still lists count as held. A block full of last
    // week's auctions is not a full block - it is an empty one wearing the old
    // stock's clothes. Counting them made the shortfall look closed, so the
    // block was never swept, and the auction sat 28,598 short of the source
    // while its raw row count read higher than the source's own total.
    // A retired row must stop counting, or the block it sits in stays over-full
    // for ever and the sweep returns to it on every pass, retiring nothing.
    $live = ($section === 'japan') ? " AND auction_on >= CURDATE()" : "";
    $live .= " AND (status IS NULL OR status <> 'removed')";

    $sql = "SELECT FLOOR((CAST(lot_no AS UNSIGNED) - 1) / " . SCAN_BLOCK . ") b, COUNT(*) n
            FROM cars
            WHERE source_section = ?
              AND (transmission IS NULL OR transmission NOT LIKE '% %')
              AND (color IS NULL OR (color NOT LIKE '% N/A' AND color <> 'N/A'))
              $live
            GROUP BY b";
    $st = $conn->prepare($sql);
    if ($st) {
        $st->bind_param('s', $section);
        $st->execute();
        $r = $st->get_result();
        while ($r && $row = $r->fetch_assoc()) {
            $have[(int) $row['b'] * SCAN_BLOCK + 1] = (int) $row['n'];
        }
        $st->close();
    }
    return $have;
}

/**
 * The block we are furthest behind on, or null when nothing is short.
 *
 * Used now only to answer "is there anything at all to do", since the reading
 * itself goes in order rather than by need - see nextShortBlock().
 *
 * @return int|null first lot number of the block
 */
function neediestBlock($conn, $section, $blocks) {
    if (!$blocks) {
        return null;
    }
    $have = heldPerBlock($conn, $section);

    $bestFrom = null;
    $bestGap = 0;
    foreach ($blocks as $from => $srcCount) {
        $from = (int) $from;
        // Distance, not shortfall. A block we hold more of than the source is
        // as wrong as one we hold less of - it is carrying cars that have been
        // sold - and reading it is what corrects both: what is missing gets
        // written, what has gone gets retired. Measuring only the shortfall is
        // why oneprice grew to 142,776 against a source holding 117,628.
        $gap = abs((int) $srcCount - (isset($have[$from]) ? $have[$from] : 0));
        if ($gap > $bestGap) {
            $bestGap = $gap;
            $bestFrom = $from;
        }
    }
    return $bestFrom;
}

/**
 * The next block to read, walking the lot numbers in order from where we left
 * off, and the cursor to resume from after it.
 *
 * Reading by need looked right and was not. It sends every request to whichever
 * block is worst at that moment, so the same few blocks are read over and over
 * while the rest of the range waits, and a pass over the whole catalogue never
 * finishes - which is the only thing that makes the two sides equal. In order,
 * every block is reached once per pass and the pass has a knowable price.
 *
 * A block whose count already matches the source is stepped over rather than
 * read: that is what keeps the second pass cheap, when only the blocks the
 * night's turnover touched are left to do.
 *
 * @return array(int|null $fromLot, int $walk) - null when the pass is finished
 */
function nextShortBlock($conn, $section, $blocks, $walk) {
    if (!$blocks) {
        return array(null, 0);
    }
    $starts = array_map('intval', array_keys($blocks));
    sort($starts);
    $have = heldPerBlock($conn, $section);

    for ($i = max(0, (int) $walk); $i < count($starts); $i++) {
        $from = $starts[$i];
        // Every block, including the ones whose count already matches.
        //
        // Skipping those looked like the whole saving and it cost the section
        // its only way of knowing what had gone. A block can hold the number
        // the source says while holding different vehicles - one sold, one
        // added - and skipped blocks are never touched, so their rows keep a
        // last_updated from some earlier pass. Sweeping the lot means every
        // vehicle the source still lists is stamped during the pass, and
        // anything left unstamped when the pass closes has gone. That test is
        // what oneprice has instead of the auction's sale day, and it is exact.
        //
        // It costs 12,924 requests a pass against a share of 27,000, so the
        // section can do it twice a day.
        if (isset($blocks[(string) $from])) {
            return array($from, $i + 1);
        }
    }
    return array(null, 0);   // pass complete - start again from the beginning
}

/**
 * How many browse sessions the auction reads through at once.
 *
 * The source serves one page at a time per session, and asking for more at once
 * changes nothing - measured on a single session, the time rose exactly with the
 * number asked for:
 *
 *     1 page   6.0s      4 pages  23.8s
 *     2 pages 10.8s      8 pages  40.8s
 *
 * But the limit is the session, not the account or the address. Four separate
 * sessions, four pages, came back in 9.9 seconds - 2.5 a page against 6.0, and a
 * pass of the auction from ten hours to under four.
 *
 * Four, because that is a browser's worth of connections, and because it still
 * works out at a request every 2.5 seconds - slower than the one a second the
 * owner set as the limit. Each session costs four requests to open and is only
 * reopened when the pass reopens the first.
 */
/* Six, and eight was tried.
   Raising it looked free - the rate was 0.37 a second against a limit of one -
   but the number governs the counting sessions as well as the reading ones, so
   eight meant fourteen wizard walks to open, one to a turn, four requests each.
   The pass fell to 1.7 pages a minute while it opened them: a sweep in
   forty-three hours against ten. The limit inside a run is not the rate, it is
   the clock - every request costs seven seconds of a four-minute run - and more
   sessions only help once they are open. */
const AUC_LANES = 6;

/* How many sessions the pass reads down.

   Six was the number for both jobs, and eight was tried twice and made things
   worse - but the reason was never the concurrency. AUC_LANES governed the
   counting sessions too, so eight meant fourteen wizard walks to open at four
   requests each, one to a turn, and the pass crawled while they opened. The
   steady state was never reached.

   Split apart, the counting keeps its six - it asks forty-one halls a round and
   six is already a second's work - and the pass takes eighteen. What that buys
   was measured on the source itself: six requests together come back in about
   seventeen seconds, eighteen together in twenty-seven. Each one is slower and
   the batch is far larger, so the throughput roughly doubles - 0.25 requests a
   second becomes about 0.65, against the one a second the owner set as the
   limit. A full sweep of the catalogue falls from about nine hours to under
   three.

   It is safe to ask for because the run does not have to keep it: two sessions
   failing in one batch drops a lane and the ceiling with it, and the ceiling
   only climbs back after fifty clean batches. If eighteen is more than the
   source will carry, the pass finds its own level within a few minutes. */
/* Eighteen was tried and the source would not carry it.

   The measurement that suggested it was real - eighteen requests together came
   back in twenty-seven seconds where six took seventeen - but a steady eighteen
   sessions is a different thing from one burst of eighteen requests. The
   give-way valve started dropping lanes within the hour (twelve down to eight),
   and between the dropping and the reopening the pass stopped advancing
   altogether: three minutes at 0.58 requests a second and not one page read.

   Six, and that is settled. Eighteen was tried and the sessions collapsed within
   the hour; nine was tried as the smaller step and collapsed the same way -
   from six open down to one inside five minutes, with the pass crawling behind
   them. The budget was never the limit: the run uses about a third of the one
   request a second the owner set. The source's patience is the limit, and it is
   six.

   Read that as settled. More sessions is not the lever; reading fewer pages is,
   which is what TAIL_STRIDE does.

   The pass is worth more steady than fast: a pass that stalls is the one thing
   that puts the portal out of step with the website in both directions at
   once. */
const PASS_LANES = 6;

/** Where the auction browse session's cookies live between runs. */
const AUC_COOKIE = __DIR__ . '/jpauc-auction.cookie';

/**
 * A second auction session, for the counting.
 *
 * The house sweep asks the listing for one house at a time, and the source
 * remembers that narrowing in the session. The next unfiltered request then
 * came back describing one house - a total of a few hundred where the
 * catalogue holds fifty thousand - and the pass, seeing its page number past
 * that total, declared itself finished and retired everything it had not
 * reached. Twice, before it was caught.
 *
 * The counting gets its own session so it cannot say anything about the pass's.
 */
const AUC_COUNT_COOKIE = __DIR__ . '/jpauc-auction-count.cookie';

/**
 * Walk one of jpauc's four-step wizards and return the listing's address.
 *
 * Both sections have two views of themselves and they do not agree. The lot
 * search returns the larger, front-page-sized set - 8,377 vehicles for 29
 * August where the listing shows 4,905, and about 130,000 for oneprice where
 * the listing shows 89,138. The listing is what a customer browsing the site
 * sees, and the client's instruction is that the portal matches it.
 *
 * The wizard holds its selection in a cookie rather than the URL, so it has to
 * be walked and the cookie kept: four requests, then the listing pages on ?p=
 * for as long as the session lasts. Each step ticks whatever boxes the page
 * offers rather than a list written here - that is how GAO's 13,723 vehicles
 * came to be missing for good, and why a hall added tomorrow will not be.
 *
 * @param string $home  'https://jpauc.com/auction' or '.../oneprice'
 * @param string $first the URL the first step posts to
 * @param string $jar   where to keep the session cookie
 * @return string|null  the listing URL without its query
 */
function wizardBase($home, $first, $jar) {
    $step = function ($url, $post = null) use ($jar) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ));
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $b = curl_exec($ch);
        $u = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return array((string) $b, (string) $u);
    };
    $boxes = function ($html) {
        preg_match_all('/<input[^>]*type="checkbox"[^>]*>/i', $html, $m);
        $out = array('go=submit');
        foreach ($m[0] as $tag) {
            if (!preg_match('/name="([^"]+)"/i', $tag, $n)) { continue; }
            if (stripos($n[1], 'checkall') !== false) { continue; }
            $v = preg_match('/value="([^"]*)"/i', $tag, $x) ? $x[1] : '1';
            $out[] = rawurlencode($n[1]) . '=' . rawurlencode($v);
        }
        return implode('&', $out);
    };

    list($b, ) = $step($home);
    if ($b === '') { return null; }
    list($b, $u) = $step($first, $boxes($b));
    if (stripos($u, 'listing') === false) { list($b, $u) = $step($u, $boxes($b)); }
    if (stripos($u, 'listing') === false) { list($b, $u) = $step($u, $boxes($b)); }
    if (stripos($u, 'listing') === false) { return null; }
    return preg_replace('/\?.*$/', '', $u);
}

/**
 * Two listing pages at once.
 *
 * Not a rate rise, a wait removed. The source takes six or seven seconds to
 * build a listing page, so with one request in flight and a second's gap after
 * it, this was asking for a page every 7.6 seconds - seven times slower than
 * the one-a-second the owner agreed to, and a complete pass took nine hours
 * because of it.
 *
 * Six in flight makes it a page every 1.17 seconds - just inside the agreed
 * one-a-second, and no faster, which is the point: the limit is the limit and
 * the waiting was never part of it. Six connections is what an ordinary browser
 * opens on any page it loads. The gap between batches stays at REQ_GAP, so if
 * the source ever gets quicker the interval cannot drift past the limit.
 *
 * It takes a complete pass from nine hours to about ninety minutes, which is
 * what the portal's freshness actually is: a vehicle listed or sold at the
 * source reaches us within one pass.
 *
 * Either page refusing stops everything, exactly as one did.
 *
 * @return array list of array($html, $err) in the order asked
 */
function fetchListingPair($base, array $pages, $jar, $extra = '') {
    $mh = curl_multi_init();
    $hs = array();
    foreach ($pages as $i => $pg) {
        $url = $base . '?m=0&t=&mm=0&mx=9999'
             . '&start_price_from=&start_price_to=&ob=none' . $extra
             . '&p=' . (int) $pg;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
            // Both handles share one cookie file. Reading it is safe; writing
            // it from two at once is not, so only the first may save.
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ));
        if ($i === 0) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        }
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) { curl_multi_select($mh, 1.0); }
    } while ($running > 0);

    $out = array();
    foreach ($hs as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code === 403 || ($body && stripos($body, 'Your IP address') !== false)) {
            $out[$i] = array(null, 'refused');
        } elseif ($code !== 200 || !$body) {
            $out[$i] = array(null, 'http ' . $code);
        } else {
            $out[$i] = array($body, '');
        }
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * The listing, as the site itself shows it.
 *
 * The query used to carry ys=1900&ye=2100 - a year range meant to mean "any
 * year". It does not: the source drops every vehicle whose year it cannot
 * place inside it, and there are more of those than anybody would guess. On
 * 29 August the listing held 45,842 vehicles and this query returned 44,747.
 * One thousand and ninety-five, never fetched, never in the portal, and
 * invisible from inside - the pass read every page it was given and every page
 * was complete.
 *
 * Measured that morning, one parameter at a time:
 *
 *     no query at all ................. 45,842
 *     ours, as it was ................. 44,747
 *     ours without ys/ye .............. 45,842
 *     ours without mm/mx .............. 44,747
 *
 * So the year range goes and the mileage range stays, because the mileage one
 * costs nothing and a bounded numeric field is what the form wants. Anything
 * added to this query from now on gets the same test: ask for the total with
 * it and without it, and keep it only if the two agree.
 */
function fetchListing($base, $page, $jar, $extra = '') {
    $url = $base . '?m=0&t=&mm=0&mx=9999'
         . '&start_price_from=&start_price_to=&ob=none' . $extra
         . '&p=' . (int) $page;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 403 || ($body && stripos($body, 'Your IP address') !== false)) {
        return array(null, 'refused');
    }
    if ($code !== 200 || !$body) {
        return array(null, 'http ' . $code);
    }
    return array($body, '');
}

/**
 * Just the vehicle rows out of a listing page.
 *
 * A listing page is 847KB, and almost none of it is vehicles: the filter bar
 * carries every maker, model, colour and grade the site knows, as options. The
 * ten rows that matter are about 30KB of it, and handing the rest to the DOM
 * parser was costing twenty-odd seconds a page - a complete pass would have
 * taken thirty-four hours rather than four.
 *
 * Cutting to the rows first is safe because they are the only <tr> carrying a
 * data-id; if the shape ever changes and nothing is found, the whole page is
 * returned and the parser reads it as before.
 */
function listingRows($html) {
    if (!preg_match_all('~<tr[^>]*\sdata-id=[^>]*>.*?</tr>~is', $html, $m) || !$m[0]) {
        return $html;
    }
    return '<table>' . implode('', $m[0]) . '</table>';
}

/**
 * The auction houses the listing can be narrowed to, read off its own filter.
 *
 * @return array of house names, e.g. Aichi, Chubu, Miyazaki
 */
function listingHouses($html) {
    if (!preg_match('/<select[^>]*name="a\[\]"[^>]*>(.*?)<\/select>/is', $html, $sm)) {
        return array();
    }
    preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>/i', $sm[1], $om);
    $out = array();
    foreach ($om[1] as $v) {
        $v = trim(html_entity_decode($v, ENT_QUOTES, 'UTF-8'));
        if ($v !== '' && strcasecmp($v, 'all') !== 0) { $out[] = $v; }
    }
    return array_values(array_unique($out));
}

/**
 * What we hold for each auction house, across the days the source still lists.
 *
 * @return array house => count
 */
function heldPerHouse($conn) {
    $out = array();
    $r = $conn->query(
        "SELECT auction a, COUNT(*) n FROM cars
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND (status IS NULL OR status <> 'removed')
          GROUP BY a");
    while ($r && $w = $r->fetch_row()) { $out[(string) $w[0]] = (int) $w[1]; }
    return $out;
}

/**
 * Bring one auction house down to the number the listing says it holds.
 *
 * This is what makes a sold vehicle leave the portal in minutes rather than
 * hours, and it is the whole answer to the thing that actually mattered: a
 * customer could open a vehicle on our portal that had been sold at the source
 * that morning, because a complete pass takes nine hours and nothing can make
 * the source build its pages faster.
 *
 * It does not need a pass. Asking the listing for one house is a single request
 * and it states its own total; if it says 1,870 where we hold 1,920, then fifty
 * of ours have gone. Which fifty is unknown, so the fifty we read longest ago
 * are taken - they are the likeliest, and a vehicle taken wrongly comes back the
 * next time the pass reaches it, because storeCars writes status=VALUES(status).
 *
 * Forty-one houses, one request each: the whole auction is checked for about
 * five minutes of asking, against nine hours to read it.
 *
 * @return int rows retired
 */
function trimHouse($conn, $house, $listed) {
    if ($listed < 0) {
        return 0;
    }
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM cars
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND auction = ? AND (status IS NULL OR status <> 'removed')");
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $house);
    $st->execute();
    $mine = (int) $st->get_result()->fetch_row()[0];
    $st->close();

    $over = $mine - $listed;
    if ($over <= 0) {
        return 0;
    }
    $up = $conn->prepare(
        "UPDATE cars SET status = 'removed', last_updated = NOW()
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND auction = ? AND (status IS NULL OR status <> 'removed')
          ORDER BY last_updated ASC
          LIMIT " . (int) $over);
    if (!$up) {
        return 0;
    }
    $up->bind_param('s', $house);
    $up->execute();
    $n = $up->affected_rows;
    $up->close();
    return max(0, $n);
}

/**
 * One page down each open session, all at once.
 *
 * fetchListingPair() asks for several pages together too, but down one session,
 * and the source answers those strictly in turn. Here each page goes down a
 * session of its own, which is the arrangement it does answer in parallel.
 *
 * @param array $bases listing URL per session
 * @param array $jars  cookie file per session, one each
 * @param int   $from  first page; session n takes $from + n
 * @return array list of array($html, $err) in session order
 */
function fetchLanes(array $bases, array $jars, $from) {
    $pages = array();
    foreach ($bases as $i => $b) { $pages[] = (int) $from + $i; }
    return fetchLanesAt($bases, $jars, $pages);
}

/**
 * Whatever is wanted, all at once.
 *
 * Everything this harvester asks the source is a GET that takes six or seven
 * seconds, and for a long time each kind waited its turn: the pass read its
 * pages, then the halls were counted, then the vehicles nominated by those
 * counts were asked after one at a time. Three queues, each idle while another
 * ran, inside a four-minute run - and the pass, which is the one that cannot be
 * skipped, was left six pages a minute where it had managed thirty-three.
 *
 * They do not need to take turns. They go down different sessions and none of
 * them reads the others' answers, so they belong in one batch. The run then
 * costs what its slowest single request costs, not the sum of all of them.
 *
 * @param array $reqs each ['url' => string, 'jar' => string|null] plus whatever
 *                    the caller wants to carry through; returned untouched
 * @return array the same list, each with 'body' and 'err' added
 */
function fetchMany(array $reqs) {
    if (!$reqs) {
        return array();
    }
    $mh = curl_multi_init();
    $hs = array();
    foreach ($reqs as $k => $r) {
        $ch = curl_init($r['url']);
        $opt = array(
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        );
        // Read-only, and never shared: a session that writes its cookie over
        // another's is the source seeing one session where it should see two.
        if (!empty($r['jar'])) { $opt[CURLOPT_COOKIEFILE] = $r['jar']; }
        curl_setopt_array($ch, $opt);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) { curl_multi_select($mh, 1.0); }
    } while ($running > 0);

    foreach ($hs as $k => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code === 403 || ($body && stripos($body, 'Your IP address') !== false)) {
            $reqs[$k]['body'] = null; $reqs[$k]['err'] = 'refused';
        } elseif ($code !== 200 || !$body) {
            $reqs[$k]['body'] = null; $reqs[$k]['err'] = 'http ' . $code;
        } else {
            $reqs[$k]['body'] = (string) $body; $reqs[$k]['err'] = '';
        }
    }
    curl_multi_close($mh);
    return $reqs;
}

/** A listing page's address, on a given session's base. */
function pageUrl($base, $page) {
    return $base . '?m=0&t=&mm=0&mx=9999'
         . '&start_price_from=&start_price_to=&ob=none&d=&p=' . (int) $page;
}

/** The same, narrowed to one auction house - and, when asked, to one page. */
function houseUrl($base, $house, $page = 1) {
    return $base . '?m=0&t=&mm=0&mx=9999'
         . '&start_price_from=&start_price_to=&ob=none&d='
         . '&a%5B%5D=' . rawurlencode($house) . '&p=' . max(1, (int) $page);
}

/** The source's lot search, which needs no session at all. */
function lotUrl($lot) {
    return 'https://jpauc.com/auction/search?lots=' . rawurlencode(trim((string) $lot));
}

/**
 * The same, for pages that are neither consecutive nor ascending.
 *
 * The tail walks backwards from the end, so it has to name its pages
 * rather than count up from one.
 *
 * @param array $pages one page per session, in session order
 * @return array list of array($html, $err), in the order asked
 */
function fetchLanesAt(array $bases, array $jars, array $pages) {
    $mh = curl_multi_init();
    $hs = array();
    foreach (array_values($pages) as $i => $pg) {
        if (!isset($bases[$i])) { break; }
        $b = $bases[$i];
        $url = $b . '?m=0&t=&mm=0&mx=9999'
             . '&start_price_from=&start_price_to=&ob=none&d='
             . '&p=' . (int) $pg;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
            // Read-only, and each from its own file: a shared jar would let one
            // session's cookie overwrite another's and the source would see a
            // single session again - which is the whole thing being avoided.
            CURLOPT_COOKIEFILE => $jars[$i],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ));
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) { curl_multi_select($mh, 1.0); }
    } while ($running > 0);

    $out = array();
    foreach ($hs as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code === 403 || ($body && stripos($body, 'Your IP address') !== false)) {
            $out[$i] = array(null, 'refused');
        } elseif ($code !== 200 || !$body) {
            $out[$i] = array(null, 'http ' . $code);
        } else {
            $out[$i] = array($body, '');
        }
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Vehicles from the auction browse listing.
 *
 * A <tr> a vehicle, two facts to a cell separated by <br>. The identity it
 * yields - day, hall, lot - is the same one the lot search yields, so a vehicle
 * read here updates the row already stored for it rather than making a second.
 */
function parseAuctionListing($html) {
    $html = listingRows($html);
    $html = preg_replace('~<span[^>]*class="[^"]*(mobilelabel|gridlabel)[^"]*"[^>]*>.*?</span>~is', '', $html);
    $doc = docOf($html);
    $xp  = new DOMXPath($doc);
    $cars = array();

    foreach ($doc->getElementsByTagName('tr') as $tr) {
        if (!ctype_digit($tr->getAttribute('data-id'))) { continue; }
        $tds = array();
        foreach ($tr->getElementsByTagName('td') as $td) { $tds[] = $td; }
        if (count($tds) < 11) { continue; }

        $when = cellLines($tds[2]);      // 2026-08-27 00:00:00
        $ll   = cellLines($tds[3]);      // hall, lot
        $mm   = cellLines($tds[4]);      // maker, model
        $yr   = cellLines($tds[5]);      // year, model grade
        $cc   = cellLines($tds[6]);      // cc, model code
        $sk   = cellLines($tds[7]);      // shift, km
        $ct   = cellLines($tds[8]);      // colour, title
        $gp   = cellLines($tds[9]);      // auction grade, start price
        $se   = cellLines($tds[10]);     // status, end price

        // Money is whatever carries a currency mark, and the grade is what is
        // left - never "the first line" and "the second".
        //
        // A vehicle with no auction grade prints one line where a graded one
        // prints two, so reading by position put its start price into the grade
        // column. The portal's Auction Grade filter then offered the customer
        // twenty-five prices to tick alongside S, 4.5 and RA. Same mistake as
        // the location cell, same fix: read for the thing, not the place.
        $money = function (array $lines) {
            $val = '';
            $rest = array();
            foreach ($lines as $ln) {
                if (preg_match('/[¥$€£]|JPY|USD/u', $ln)) { $val = $ln; }
                else { $rest[] = $ln; }
            }
            if ($val === '' && count($lines) > 1) { $val = end($lines); array_pop($rest); }
            return array($val, $rest);
        };
        list($startPrice, $gradeLines) = $money($gp);
        list($endPrice,   $stateLines) = $money($se);

        $stamp = trim($when[0] ?? '');
        $day   = substr($stamp, 0, 10);
        $hall  = trim($ll[0] ?? '');
        $lot   = trim($ll[1] ?? '');
        if ($day === '' || $hall === '' || $lot === '') { continue; }

        $img = '';
        $ims = $xp->query('.//img[@data-original]', $tr);
        if ($ims->length) { $img = $ims->item(0)->getAttribute('data-original'); }

        // A vehicle still to be sold shows N/A here; anything else is a result,
        // and a result is what takes it off the portal.
        $status = trim($stateLines[0] ?? '');
        if ($status === '' || strcasecmp($status, 'N/A') === 0) { $status = 'available'; }

        // Name a value by what it looks like, never by which line it is on. A
        // row missing one of a pair prints a single line, and counting from the
        // front then files the survivor under the wrong name - which is how
        // mileage got into the portal's transmission list ("0,000", "DAT") and
        // why the model code never reached the Chassis Model column at all.
        $km = ''; $shift = '';
        foreach ($sk as $ln) {
            $ln = trim($ln);
            if ($ln === '') { continue; }
            if (preg_match('/\d{3}/', $ln)) { $km = $ln; } else { $shift = $ln; }
        }
        $ccVal = ''; $modelCode = '';
        foreach ($cc as $ln) {
            $ln = trim($ln);
            if ($ln === '') { continue; }
            if (stripos($ln, 'cc') !== false) { $ccVal = $ln; } else { $modelCode = $ln; }
        }

        $cars[] = array(
            // The source's own row id. It counts upward as vehicles are
            // listed, and the listing is in that order - which is what makes
            // new arrivals findable without reading the catalogue.
            'srcId'      => (int) $tr->getAttribute('data-id'),
            'day'        => $day,
            'hall'       => $hall,
            'lot'        => $lot,
            'maker'      => $mm[0] ?? '',
            'model'      => $mm[1] ?? '',
            'modelGrade' => $yr[1] ?? '',
            'cc'         => $ccVal,
            'year'       => preg_replace('/\D.*$/', '', trim($yr[0] ?? '')),
            'km'         => $km,
            'shift'      => $shift,
            'status'     => $status,
            'price'      => $startPrice,
            'endPrice'   => $endPrice,
            'grade'      => trim($gradeLines[0] ?? ''),
            'color'      => $ct[0] ?? '',
            'chassis'    => $modelCode,
            'time'       => trim(substr($stamp, 11)),
            'images'     => array($img),
        );
    }
    return $cars;
}

/** Where the browse session's cookies live between runs. */
const OP_COOKIE = __DIR__ . '/jpauc-oneprice.cookie';

/**
 * Open a oneprice browse session and return the listing's address.
 *
 * The fixed-price section has two views of itself and they do not agree. Asking
 * it by lot number - which is what this harvester did - returns 130,000
 * vehicles. Walking into the site and listing everything returns 89,138, and
 * that is the one a customer sees and the one the client says the portal must
 * match. The difference is not small or cosmetic: we were carrying about 29,000
 * vehicles the browse view does not list, and missing the whole of GAO's 13,723
 * because the lot search never returned them.
 *
 * The listing is behind a four-step wizard - location, maker, model, result -
 * and it holds the selection in a cookie rather than the URL, so the walk has
 * to be done once and the cookie kept. Four requests, and then the listing
 * pages on ?p= for as long as the cookie lasts.
 *
 * @return string|null the listing URL without its query, or null if the walk failed
 */
function onepriceBase() {
    $step = function ($url, $post = null) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 40,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
            CURLOPT_COOKIEJAR => OP_COOKIE, CURLOPT_COOKIEFILE => OP_COOKIE,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ));
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $b = curl_exec($ch);
        $u = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return array((string) $b, (string) $u);
    };

    // Every box on the step, which is what "select all" does. Read from the page
    // rather than listed here: a hall added later is then picked up on its own,
    // and a hall missed is a hall of vehicles missing from the portal.
    $boxes = function ($html) {
        preg_match_all('/<input[^>]*type="checkbox"[^>]*>/i', $html, $m);
        $out = array('go=submit');
        foreach ($m[0] as $tag) {
            if (!preg_match('/name="([^"]+)"/i', $tag, $n)) { continue; }
            if (stripos($n[1], 'checkall') !== false) { continue; }
            $v = preg_match('/value="([^"]*)"/i', $tag, $x) ? $x[1] : '1';
            $out[] = rawurlencode($n[1]) . '=' . rawurlencode($v);
        }
        return implode('&', $out);
    };

    return wizardBase('https://jpauc.com/oneprice',
                      'https://jpauc.com/oneprice/location', OP_COOKIE);
}

/** One page of the oneprice browse listing. */
function fetchOneprice($base, $page) {
    $url = $base . '?a=all&m=all&t=&mm=0&mx=9999&ob=none&p=' . (int) $page;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
        CURLOPT_COOKIEJAR => OP_COOKIE, CURLOPT_COOKIEFILE => OP_COOKIE,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 403 || ($body && stripos($body, 'Your IP address') !== false)) {
        return array(null, 'refused');
    }
    if ($code !== 200 || !$body) {
        return array(null, 'http ' . $code);
    }
    return array($body, '');
}

/**
 * Vehicles from the oneprice browse listing.
 *
 * A different table from the lot search: one <tr> per vehicle, the cells
 * carrying two facts apiece separated by <br>, and the row's own id on the
 * <tr>. That id is the source's, so it is what identity is built from here -
 * steadier than day + hall + lot, which the browse view does not even print a
 * day for.
 */
function parseOnepriceListing($html) {
    $html = listingRows($html);
    // The same labels the auction parser drops: they are printed for narrow
    // screens and would be read as facts.
    $html = preg_replace('~<span[^>]*class="[^"]*(mobilelabel|gridlabel)[^"]*"[^>]*>.*?</span>~is', '', $html);

    $doc = docOf($html);
    $xp  = new DOMXPath($doc);
    $cars = array();

    foreach ($doc->getElementsByTagName('tr') as $tr) {
        $id = $tr->getAttribute('data-id');
        if ($id === '' || !ctype_digit($id)) { continue; }

        $tds = array();
        foreach ($tr->getElementsByTagName('td') as $td) { $tds[] = $td; }
        if (count($tds) < 9) { continue; }

        $loc = cellLines($tds[2]);          // country, hall, stock number
        $mm  = cellLines($tds[3]);          // maker, model
        $yr  = cellLines($tds[4]);          // year (/ month), model grade
        $cc  = cellLines($tds[5]);          // cc, model code
        $sk  = cellLines($tds[6]);          // shift, km
        $cs  = cellLines($tds[7]);          // colour, seats
        $cp  = cellLines($tds[8]);          // condition, list price

        // Read from the end, never by position. The cell holds country, hall
        // and stock number, except where it holds only the last two - Trendy
        // prints no country - and counting from the front put the hall in the
        // stock number's place and dropped the row. Page two lost all ten that
        // way, page one lost two.
        $loc  = array_values(array_filter(array_map('trim', $loc), 'strlen'));
        $n    = count($loc);
        $lot  = $n >= 1 ? $loc[$n - 1] : '';
        $hall = $n >= 2 ? $loc[$n - 2] : '';
        if ($hall === '' || $lot === '') { continue; }

        $img = '';
        $ims = $xp->query('.//img[@data-original]', $tr);
        if ($ims->length) { $img = $ims->item(0)->getAttribute('data-original'); }

        // Name a value by what it looks like, never by which line it is on. A
        // row missing one of a pair prints a single line, and counting from the
        // front then files the survivor under the wrong name - which is how
        // mileage got into the portal's transmission list ("0,000", "DAT") and
        // why the model code never reached the Chassis Model column at all.
        $km = ''; $shift = '';
        foreach ($sk as $ln) {
            $ln = trim($ln);
            if ($ln === '') { continue; }
            if (preg_match('/\d{3}/', $ln)) { $km = $ln; } else { $shift = $ln; }
        }
        $ccVal = ''; $modelCode = '';
        foreach ($cc as $ln) {
            $ln = trim($ln);
            if ($ln === '') { continue; }
            if (stripos($ln, 'cc') !== false) { $ccVal = $ln; } else { $modelCode = $ln; }
        }

        $cars[] = array(
            // No sale day exists for fixed-price stock. A fixed one keeps the
            // identity stable and valid without pretending to be a date.
            'day'        => '2000-01-01',
            'hall'       => $hall,
            'lot'        => $lot,
            'maker'      => $mm[0] ?? '',
            'model'      => $mm[1] ?? '',
            'modelGrade' => $yr[1] ?? '',
            'cc'         => $ccVal,
            'year'       => preg_replace('/\D.*$/', '', trim($yr[0] ?? '')),
            'km'         => $km,
            'shift'      => $shift,
            'status'     => 'available',
            'price'      => $cp[1] ?? '',
            'endPrice'   => '',
            'grade'      => $cp[0] ?? '',
            'color'      => $cs[0] ?? '',
            'chassis'    => $modelCode,
            'time'       => '',
            'images'     => array($img),
        );
    }
    return $cars;
}

/**
 * How many vehicles the source is listing for each of its auction days.
 *
 * The section's front page prints them - "25 Tuesday ( 35275 )" - one request
 * for all five, and they are what makes the auction affordable to keep up with.
 * The section total on its own says we are 25,522 behind and nothing about
 * where; per day it says which day is short and by how much, and a day already
 * matching is a day nothing needs to be read from at all.
 *
 * @return array 'YYYY-MM-DD' => count, empty if the page could not be read
 */
function sourceDays() {
    $ch = curl_init('https://jpauc.com/auction');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ));
    $b = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$b) {
        return array();
    }

    // The days themselves come from the filter checkboxes, which are exact. The
    // counts are only in the visible text beside them, so they are matched by
    // day number against the tidied text rather than guessed at from position.
    preg_match_all('/name="d\[\]"[^>]*value="(\d{4}-\d{2}-\d{2})"/i', $b, $m);
    $txt  = preg_replace('/\s+/', ' ', strip_tags($b));
    $days = array();
    foreach (array_unique($m[1]) as $d) {
        $n = (int) substr($d, 8, 2);
        if (preg_match('/' . $n . '\s*(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)\w*\s*\(\s*([\d,]+)\s*\)/i',
                       $txt, $g)) {
            $days[$d] = (int) str_replace(',', '', $g[1]);
        }
    }
    return $days;
}

/**
 * Which of these vehicles do we already hold?
 *
 * One query for a page of them, keyed by the same identity the portal stores a
 * vehicle under. Used by the tail to decide whether a page it has just read has
 * anything on it we are missing.
 *
 * @param mysqli $conn
 * @param array  $ids  car_id strings
 * @return array set of the ones present, as keys
 */
function heldCarIds($conn, array $ids) {
    $out = array();
    $ids = array_values(array_unique(array_filter($ids, 'strlen')));
    if (!$ids) {
        return $out;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $conn->prepare("SELECT car_id FROM cars WHERE car_id IN ($marks)");
    if (!$st) {
        // Cannot ask: say we hold none, so the tail keeps what it read rather
        // than throwing it away. storeCars is an upsert; a duplicate is free.
        return $out;
    }
    $st->bind_param(str_repeat('s', count($ids)), ...$ids);
    $st->execute();
    $r = $st->get_result();
    while ($row = $r->fetch_row()) { $out[$row[0]] = true; }
    $st->close();
    return $out;
}

/**
 * Is this exact vehicle still listed?
 *
 * The source has a lot search - /auction/search?lots=N - and it answers with
 * every vehicle carrying that lot number, across all the halls. A lot number
 * is not unique, so the answer is read by identity: day, hall and lot together,
 * which is the same key the portal stores a vehicle under.
 *
 * One request, one certain answer. That is what the house counts could never
 * give: they say a hall is three vehicles lighter than we think, and nothing at
 * all about which three.
 *
 * @return bool true if the source still lists it
 */
function stillListed($carId, $lot) {
    $lot = trim((string) $lot);
    if ($lot === '' || $carId === '') {
        return true;      // nothing to ask with; never remove on a guess
    }
    $ch = curl_init('https://jpauc.com/auction/search?lots=' . rawurlencode($lot));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 40,
        CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ));
    $b = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$b) {
        return true;      // could not ask; leave it alone
    }
    foreach (parseAuctionListing((string) $b) as $c) {
        if (jpaucCarId($c) === $carId) {
            return true;
        }
    }
    return false;
}

/**
 * The vehicles a hall holds that the source may no longer list.
 *
 * Oldest-read first, because a vehicle the pass has stopped finding is the one
 * whose stamp stops moving - but only as an ORDER, never as a verdict. What
 * follows checks each one by name.
 *
 * @return array rows of car_id and lot_no
 */
function trimCandidates($conn, $house, $listed, $want) {
    $out = array();
    if ($listed < 0 || $want < 1) {
        return $out;
    }
    $st = $conn->prepare(
        "SELECT car_id, lot_no FROM cars
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND auction = ? AND (status IS NULL OR status <> 'removed')
          ORDER BY last_updated ASC
          LIMIT " . (int) $want);
    if (!$st) {
        return $out;
    }
    $st->bind_param('s', $house);
    $st->execute();
    $r = $st->get_result();
    while ($w = $r->fetch_assoc()) { $out[] = $w; }
    $st->close();
    return $out;
}

/**
 * Mark one vehicle gone, by name.
 *
 * @return int 1 if it was marked, 0 if it was already
 */
function retireOne($conn, $carId) {
    $st = $conn->prepare(
        "UPDATE cars SET status = 'removed', last_updated = NOW()
          WHERE car_id = ? AND (status IS NULL OR status <> 'removed')");
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $carId);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return max(0, (int) $n);
}

/**
 * Lots whose sale moment has passed while we still offer them.
 *
 * Ours is the only clock that matters here: every vehicle carries its sale day
 * and time, so a lot that has gone under the hammer is arithmetic, not
 * discovery. Newest first - the promise is that a vehicle sold a minute ago
 * leaves within a minute, and a backlog from this morning must never come
 * between a customer and that.
 *
 * The server keeps UTC and the halls sell on Japan's clock, nine hours ahead,
 * and this host has no timezone tables for CONVERT_TZ - so "now, in Japan" is
 * worked out in PHP and handed to the query as a string.
 *
 * @return array rows of car_id and lot_no, newest conclusion first
 */
function concludedLots($conn, $limit, $backlogToo = true) {
    $out = array();
    $limit = max(1, (int) $limit);
    $now = date_create('now', timezone_open('Asia/Tokyo'));
    if (!$now) {
        return $out;
    }
    $jstNow  = $now->format('Y-m-d H:i:s');
    $jstBack = $now->modify('-' . (int) RESULT_WINDOW . ' seconds')->format('Y-m-d H:i:s');

    // auction_time arrives as "[10:13:00]" on some rows and bare on others.
    $moment = "CONCAT(auction_on, ' ', COALESCE(NULLIF("
            . "TRIM(BOTH ']' FROM TRIM(BOTH '[' FROM auction_time)), ''), '09:00:00'))";

    // Only what a customer can still be shown. A concluded lot on a day the
    // portal has already let go of is nobody's problem.
    $where = "source_section = 'japan' AND auction_on >= CURDATE()
              AND (status IS NULL OR status LIKE 'available%')
              AND lot_no <> '' AND $moment <= ?";

    $st = $conn->prepare(
        "SELECT car_id, lot_no FROM cars
          WHERE $where AND $moment >= ?
          ORDER BY $moment DESC
          LIMIT " . $limit);
    if ($st) {
        $st->bind_param('ss', $jstNow, $jstBack);
        $st->execute();
        $r = $st->get_result();
        while ($w = $r->fetch_assoc()) { $out[] = $w; }
        $st->close();
    }
    if ($out || !$backlogToo) {
        return $out;
    }

    /* Nothing has concluded in the last three hours - it is the middle of the
       night in Japan, or the day's selling is over. The lots that concluded
       before the window are still sitting on the portal marked available, and
       nothing else is asking for the source's time right now, so they are
       worked through oldest first while it is quiet.

       "While it is quiet" is the whole of it, and it was not being honoured.
       The backlog runs to thousands, so this query always had something to
       return, so the results turn always had work, so it took the upkeep turn
       every time it was offered one - and the hall read sits behind it. Hence
       $backlogToo: the caller says whether there is better use for the turn. */
    $st = $conn->prepare(
        "SELECT car_id, lot_no FROM cars
          WHERE $where
          ORDER BY $moment ASC
          LIMIT " . $limit);
    if (!$st) {
        return $out;
    }
    $st->bind_param('s', $jstNow);
    $st->execute();
    $r = $st->get_result();
    while ($w = $r->fetch_assoc()) { $out[] = $w; }
    $st->close();
    return $out;
}

/**
 * Mark these vehicles as still listed by the source.
 *
 * Only a stamp. It never inserts: the hall view is the source's own, but it is
 * not the view a customer reads, and taking vehicles INTO the portal from it is
 * the mistake that put 6,496 strangers in the table - see FILL_ON, which stays
 * off. A vehicle the hall view shows and we do not hold is left for the pass.
 *
 * @param array $ids car_id values
 * @return int rows stamped
 */
function touchCars($conn, array $ids) {
    $ids = array_values(array_unique(array_filter($ids, 'strlen')));
    if (!$ids) {
        return 0;
    }
    $n = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $st = $conn->prepare(
            "UPDATE cars SET last_updated = NOW() WHERE car_id IN ($marks)");
        if (!$st) {
            continue;
        }
        $st->bind_param(str_repeat('s', count($chunk)), ...$chunk);
        $st->execute();
        $n += max(0, (int) $st->affected_rows);
        $st->close();
    }
    return $n;
}

/**
 * The database's own clock.
 *
 * The stamps this is compared against are written by the database, so the
 * moment a read began has to come from the same clock. PHP's would do until the
 * two drifted, and then a reconciliation would either spare everything or take
 * off vehicles it never looked at.
 *
 * @return string 'Y-m-d H:i:s'
 */
/**
 * A breadcrumb of which turn did what, kept in the state file.
 *
 * A run is a cron job whose output goes nowhere, so when the hall read stopped
 * for twenty hours there was no way to see from outside which of the things in
 * front of it was taking its turn - only that it was not moving. Fourteen
 * entries is about two minutes of turns, which is enough to see the pattern.
 */
function actLog(&$cur_, $tag) {
    $r = (array) ($cur_['acts'] ?? array());
    $r[] = $tag . '@' . date('H:i:s');
    $cur_['acts'] = array_slice($r, -14);
}

function dbNow($conn) {
    $r = @$conn->query("SELECT NOW()");
    $row = $r ? $r->fetch_row() : null;
    return $row ? (string) $row[0] : date('Y-m-d H:i:s');
}

/**
 * How many live vehicles we hold for one hall.
 *
 * @return int the count, or -1 if it could not be asked
 */
function hallHeld($conn, $house) {
    $st = $conn->prepare(
        "SELECT COUNT(*) FROM cars
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND auction = ? AND (status IS NULL OR status <> 'removed')");
    if (!$st) {
        return -1;
    }
    $st->bind_param('s', $house);
    $st->execute();
    $n = (int) $st->get_result()->fetch_row()[0];
    $st->close();
    return $n;
}

/**
 * Retire the vehicles of one hall that a completed read of it never saw.
 *
 * `$since` is the moment the read began. Everything the read found was stamped
 * as it was found, and the pass stamps whatever it reads as well, so a row for
 * this hall still carrying a stamp from before that moment is a row neither of
 * them saw - and the read covered the whole hall.
 *
 * Oldest stamp first, and capped, so that if the read is wrong the vehicles it
 * is wrong about are the ones nothing has seen for longest. storeCars writes
 * status=VALUES(status), so anything taken off in error comes back the moment
 * the source shows it again.
 *
 * @return int rows retired
 */
function retireHallUnseen($conn, $house, $since, $limit) {
    $limit = max(1, (int) $limit);
    $st = $conn->prepare(
        "UPDATE cars SET status = 'removed', last_updated = NOW()
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND auction = ? AND (status IS NULL OR status <> 'removed')
            AND last_updated < ?
          ORDER BY last_updated ASC
          LIMIT " . $limit);
    if (!$st) {
        return 0;
    }
    $st->bind_param('ss', $house, $since);
    $st->execute();
    $n = (int) $st->affected_rows;
    $st->close();
    return max(0, $n);
}

/**
 * The halls holding stock on a later day than the soonest.
 *
 * sellingHouses() below covers the day being sold, on the reasoning that a lot
 * only leaves when it goes under the hammer. That reasoning is incomplete: a
 * seller can withdraw a lot days before its sale, and on 7 September the days
 * not yet being sold carried 2,397 of the portal's 2,744 surplus - MIRIVE
 * Aichi, selling on the 11th, was twenty over four days out.
 *
 * The old answer was that the pass catches those. The pass takes twelve to
 * twenty-five hours to go round and needs two of them to agree before it will
 * take anything off, so it does not.
 *
 * These are not counted every minute - nothing is being sold there. Two of them
 * ride along with each round of the selling halls, which brings every hall in
 * the catalogue round in about ten minutes for two requests a round.
 *
 * @return array hall names
 */
function laterHouses($conn) {
    $out = array();
    $r = @$conn->query(
        "SELECT auction FROM cars
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND auction <> '' AND (status IS NULL OR status NOT LIKE 'removed%')
            AND auction_on > (SELECT MIN(auction_on) FROM cars
                               WHERE source_section = 'japan' AND auction_on >= CURDATE()
                                 AND (status IS NULL OR status NOT LIKE 'removed%'))
          GROUP BY auction
          ORDER BY auction ASC");
    while ($r && $w = $r->fetch_row()) { $out[] = (string) $w[0]; }
    return $out;
}

/**
 * The auction houses that can be selling right now.
 *
 * A vehicle leaves the listing when its lot goes under the hammer, and a lot
 * only goes under the hammer on its own sale day. So of the thirty auction
 * houses in the catalogue, only those holding a sale on the soonest day still
 * listed can lose anything - twelve of them on 29 August, against thirty asked
 * one at a time every ten minutes.
 *
 * And which twelve is not a question for the source. It is in our own table:
 * every vehicle we hold carries its day and its hall. The list is free.
 *
 * That is what makes a sold vehicle leave in a minute instead of five. The old
 * arrangement had to DISCOVER that a house had started selling - it swept them
 * on a timer and put the movers on a watch list - so the first sale at any
 * house waited out the sweep. Asking directly skips the discovery: there is
 * nothing to find out.
 *
 * The halls on the soonest sale day, and only those. Asking all forty-nine was
 * tried on the strength of one hall a day out being 190 over - but that reading
 * came from the trim that was removing live vehicles, so the evidence was the
 * bug and not the source. Forty-nine counts a run left the pass 1.7 pages a
 * minute; twelve leaves it most of the run. A lot withdrawn days before its
 * sale is caught by the pass instead, which is what the pass is for.
 *
 * @return array hall names, soonest sale day first
 */
function sellingHouses($conn) {
    $out = array();
    $r = @$conn->query(
        "SELECT auction FROM cars
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND auction <> '' AND (status IS NULL OR status NOT LIKE 'removed%')
            AND auction_on = (SELECT MIN(auction_on) FROM cars
                               WHERE source_section = 'japan' AND auction_on >= CURDATE()
                                 AND (status IS NULL OR status NOT LIKE 'removed%'))
          GROUP BY auction
          ORDER BY COUNT(*) DESC");
    while ($r && $w = $r->fetch_row()) { $out[] = (string) $w[0]; }
    return $out;
}

/**
 * One auction house down each counting session, all at once.
 *
 * The house filter sticks to the session it is asked on - that is what once
 * left the pass reading a listing narrowed to a single hall - so these go down
 * sessions kept apart from the pass's own, and never the other way about.
 *
 * @param array $houses one hall per session, in session order
 * @return array list of array($html, $err), in the order asked
 */
function fetchHouses(array $bases, array $jars, array $houses) {
    $mh = curl_multi_init();
    $hs = array();
    foreach (array_values($houses) as $i => $house) {
        if (!isset($bases[$i])) { break; }
        $url = $bases[$i] . '?m=0&t=&mm=0&mx=9999'
             . '&start_price_from=&start_price_to=&ob=none&d='
             . '&a%5B%5D=' . rawurlencode($house) . '&p=1';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 12, CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_ENCODING => '',
            CURLOPT_COOKIEFILE => $jars[$i],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ));
        curl_multi_add_handle($mh, $ch);
        $hs[$i] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) { curl_multi_select($mh, 1.0); }
    } while ($running > 0);

    $out = array();
    foreach ($hs as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($code === 403 || ($body && stripos($body, 'Your IP address') !== false)) {
            $out[$i] = array(null, 'refused');
        } elseif ($code !== 200 || !$body) {
            $out[$i] = array(null, 'http ' . $code);
        } else {
            $out[$i] = array($body, '');
        }
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * What we hold for each auction day the source is still listing.
 *
 * @return array 'YYYY-MM-DD' => count
 */
function heldPerDay($conn) {
    $out = array();
    $r = $conn->query(
        "SELECT auction_on d, COUNT(*) n FROM cars
          WHERE source_section = 'japan' AND auction_on >= CURDATE()
            AND (status IS NULL OR status <> 'removed')
          GROUP BY d");
    while ($r && $w = $r->fetch_row()) { $out[(string) $w[0]] = (int) $w[1]; }
    return $out;
}

/**
 * The lot numbers we hold nothing at on one auction day.
 *
 * This is the whole reason the auction can now be kept up with. Asking for the
 * lot numbers we lack was tried before across all five days at once, and it
 * decayed within the hour: a lot number carries 3.24 vehicles when the days are
 * pooled, so holding Tuesday's lot 4021 made us skip Wednesday's and Friday's
 * as well, and the request came back full of vehicles we already had.
 *
 * Inside one day it carries 1.0 to 2.4 - near enough to unique that a lot we
 * hold nothing at is a lot whose vehicles are all new to us. Measured against
 * the real shortfall on 26 August: 13,205 vehicles missing, and the lots we
 * held nothing at on that day carried almost exactly that many.
 *
 * @return array lot numbers, ascending
 */
function missingLotsOnDay($conn, $day) {
    $have = array();
    $st = $conn->prepare(
        "SELECT DISTINCT CAST(lot_no AS UNSIGNED) n FROM cars
          WHERE source_section = 'japan' AND auction_on = ?
            AND (status IS NULL OR status <> 'removed')");
    if (!$st) {
        return array();
    }
    $st->bind_param('s', $day);
    $st->execute();
    $r = $st->get_result();
    while ($r && $w = $r->fetch_row()) { $have[(int) $w[0]] = true; }
    $st->close();

    $out = array();
    for ($i = 1; $i <= LOT_MAX; $i++) {
        if (!isset($have[$i])) { $out[] = $i; }
    }
    return $out;
}

/** One page of one auction day, for whichever lot numbers are asked for. */
function fetchDayLots($day, array $lots, $page) {
    if (!$lots) {
        return array(null, 'no lots');
    }
    // Trim rather than be refused. The band width is chosen to fit, but lot
    // numbers grow a digit as the range climbs and the day this stopped fitting
    // was a day of requests answered 414 and nothing read.
    $list = implode(',', $lots);
    while (strlen($list) > 4000) {
        array_pop($lots);
        $list = implode(',', $lots);
    }
    return fetchUrl('https://jpauc.com/auction/search'
        . '?d%5B%5D=' . rawurlencode($day)
        . '&lots=' . $list
        . '&submit=Search&p=' . (int) $page);
}

/**
 * How many vehicles the source says a section is holding right now.
 *
 * Read from the pulse's file, which costs one request every ten minutes to
 * keep current. Returns -1 when it has not been read yet.
 */
function sourceTotal($section) {
    static $pulse = null;
    if ($pulse === null) {
        $pulse = is_file(HARVEST_PULSE)
            ? (json_decode((string) file_get_contents(HARVEST_PULSE), true) ?: array())
            : array();
    }
    if (!isset($pulse[$section]) || !is_array($pulse[$section]) || !$pulse[$section]) {
        return -1;
    }
    return array_sum($pulse[$section]);
}

/* ----------------------------------------------------------------- source */

/**
 * One page of whatever lot numbers are asked for.
 *
 * The list does not have to be a run. `lots=` takes any comma-separated set,
 * which is what lets a request carry only the lots we are missing rather than a
 * stretch of the number line that mostly belongs to us already.
 */
function fetchLots($baseUrl, array $lots, $page) {
    if (!$lots) {
        return array(null, 'no lots');
    }
    return fetchUrl($baseUrl . '?lots=' . implode(',', $lots)
                  . '&submit=Search&p=' . (int) $page);
}

/**
 * One page from the source, with the one rule that matters: a refusal stops
 * everything rather than being retried into a ban.
 */
function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
    ));
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 403 || ($body && stripos($body, 'Your IP address') !== false)) {
        return array(null, 'refused');    // stop everything; do not retry into a ban
    }
    if ($code !== 200 || !$body) {
        return array(null, 'http ' . $code);
    }
    return array($body, '');
}

/** A run of lot numbers, as one page. Still used by the survey and the sweep. */
function fetchPage($baseUrl, $lotFrom, $page, $span = LOT_BATCH) {
    $lots = array();
    for ($i = $lotFrom; $i < $lotFrom + $span && $i <= LOT_MAX; $i++) {
        $lots[] = $i;
    }
    return fetchLots($baseUrl, $lots, $page);
}

/**
 * How many vehicles this batch holds in total, from the page's own "1 - 10 of
 * 1060" line.
 *
 * This is the number that makes the walk safe. Deciding a range had ended
 * because a page came back empty was right most of the time and catastrophic
 * the rest: one hiccup read as "finished" and the remaining pages were never
 * asked for. About a third of every auction hall went missing that way. With a
 * declared total there is nothing to infer.
 */
function declaredTotal($html) {
    if (preg_match('/([0-9,]+)\s*-\s*([0-9,]+)\s+of\s+([0-9,]+)/i', strip_tags($html), $m)) {
        return (int) str_replace(',', '', $m[3]);
    }
    return -1;   // not stated; fall back to walking until a page comes back empty
}

/* ---------------------------------------------------------------- parsing */

/** The visible lines of one cell, in order: facts are separated by <br> and
 *  wrapped in labels that only show on small screens. */
function cellLines($cell) {
    if (!$cell) {
        return array();
    }
    $doc = $cell->ownerDocument;
    $html = '';
    foreach ($cell->childNodes as $n) {
        $html .= $doc->saveHTML($n);
    }
    $html = preg_replace('~<(span|div)[^>]*class="[^"]*(mobilelabel|gridlabel)[^"]*"[^>]*>.*?</\1>~is', '', $html);
    $out = array();
    foreach (preg_split('~<br\s*/?>~i', $html) as $p) {
        $t = trim(html_entity_decode(strip_tags($p), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return $out;
}

function docOf($html) {
    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

/**
 * The two facts in one cell, in the right order.
 *
 * A cell holds a pair separated by a <br> - gearbox and mileage, engine size
 * and chassis code, auction grade and starting price. When the first of the
 * pair is blank the browser is shown an empty line, which disappears here, and
 * the survivor slides into first place. A construction machine has no gearbox,
 * so its mileage was being filed as "transmission: 4,000" - and a lot with no
 * grade would have had its starting price read as its grade.
 *
 * Position cannot tell them apart once one is gone, so $isSecond looks at the
 * value itself and says whether it belongs in the second slot.
 *
 * @param array    $lines
 * @param callable $isSecond
 * @return array   [first, second]
 */
function cellPair(array $lines, $isSecond) {
    if (count($lines) >= 2) {
        return array($lines[0], $lines[1]);
    }
    if (!$lines) {
        return array('', '');
    }
    return call_user_func($isSecond, $lines[0])
        ? array('', $lines[0])
        : array($lines[0], '');
}

function byClass(DOMXPath $xp, $node, $class) {
    $n = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]", $node);
    return $n->length ? $n->item(0) : null;
}

function pickImage(DOMXPath $xp, $row) {
    $im = $xp->query('.//img[@data-original]', $row);
    if (!$im->length) {
        return '';
    }
    return preg_replace('/&h=\d+/', '&h=320', $im->item(0)->getAttribute('data-original'));
}

/**
 * Oneprice rows: one <li> per vehicle, facts stacked inside labelled cells.
 */
function parseOneprice($html) {
    $doc = docOf($html);
    $xp = new DOMXPath($doc);
    $cars = array();

    foreach ($doc->getElementsByTagName('li') as $li) {
        if (!$xp->query('.//img[@data-original]', $li)->length || !byClass($xp, $li, 'price')) {
            continue;
        }
        $a = cellLines(byClass($xp, $li, 'auct'));
        $m = cellLines(byClass($xp, $li, 'model'));
        $c = cellLines(byClass($xp, $li, 'cond'));
        $p = cellLines(byClass($xp, $li, 'price'));
        $r = cellLines(byClass($xp, $li, 'result'));

        // Colour is the last line, never a fixed position: a lot with no
        // auction grade has one line fewer, and reading by position slid the
        // colour into the grade column on about a fifth of all rows.
        $cars[] = array(
            'day'        => $a[0] ?? '',
            'hall'       => $a[1] ?? '',
            'lot'        => $a[2] ?? '',
            'maker'      => $m[0] ?? '',
            'model'      => $m[1] ?? '',
            'modelGrade' => $m[2] ?? '',
            'cc'         => $c[0] ?? '',
            'year'       => preg_replace('/\D+/', '', $c[1] ?? ''),
            'km'         => $c[2] ?? '',
            'shift'      => $c[3] ?? '',
            'status'     => $p[0] ?? 'available',
            'price'      => $p[1] ?? '',
            'endPrice'   => '',
            'grade'      => count($r) >= 3 ? $r[1] : '',
            'color'      => $r ? $r[count($r) - 1] : '',
            'chassis'    => '',
            'time'       => '',
            'images'     => array(pickImage($xp, $li)),
        );
    }
    return $cars;
}

/**
 * Auction rows: a <tr> per vehicle, one fact per cell.
 *
 * Cells are read backwards from the status cell rather than counted from the
 * left, because the leading columns differ between the desktop table and the
 * stacked mobile view while the tail is stable.
 */
function parseAuction($html) {
    $doc = docOf($html);
    $xp = new DOMXPath($doc);
    $cars = array();

    $rows = $xp->query('//tr[.//img[@data-original]]');
    foreach ($rows as $tr) {
        $tds = array();
        foreach ($tr->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->nodeName === 'td') {
                $tds[] = $c;
            }
        }
        // status | grade+start | colour+title | trans+km | cc+chassis |
        // year+grade | maker+model | hall+lot | date
        $end = null;
        foreach ($tds as $i => $td) {
            if (strpos($td->getAttribute('class'), 'statusendprice') !== false) {
                $end = $i;
                break;
            }
        }
        if ($end === null || $end < 8) {
            continue;
        }
        // Each cell holds one or two facts, separated by a <br>. The vertical
        // bar between them and the "Year:", "KM", "Start:" labels are not text
        // at all - they sit in gridlabel and mobilelabel spans that only show
        // on small screens, and cellLines() strips them. So the bar is gone by
        // the time the value is read, and splitting on it merged every pair:
        // engine size ran into the chassis code, gearbox into the mileage.
        // The <br> is the real separator.
        $L = function ($i) use ($tds) { return cellLines($tds[$i]); };

        $dateL  = $L($end - 8);
        $hallL  = $L($end - 7);
        $makeL  = $L($end - 6);
        $yearL  = $L($end - 5);
        $ccL    = $L($end - 4);
        $transL = $L($end - 3);
        $colorL = $L($end - 2);
        $gradeL = $L($end - 1);
        $stateL = $L($end);

        // "2026-08-17 00:00:00"
        $day = '';
        $time = '';
        if (preg_match('/(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}))?/', $dateL[0] ?? '', $m)) {
            $day = $m[1];
            $time = $m[2] ?? '';
        }

        $hall = $hallL[0] ?? '';
        $lot  = $hallL[1] ?? '';

        $maker = $makeL[0] ?? '';
        $model = $makeL[1] ?? '';

        // "2024" then the trim level. A lot with no year leaves only the trim,
        // so the first line is taken as a year only when it looks like one.
        $year = '';
        $mGrade = '';
        if (isset($yearL[0]) && preg_match('/^\d{4}$/', trim($yearL[0]))) {
            $year = trim($yearL[0]);
            $mGrade = $yearL[1] ?? '';
        } else {
            $mGrade = $yearL[0] ?? '';
        }

        // engine size then chassis code: the size always carries "cc"
        list($cc, $chassis) = cellPair($ccL, function ($v) {
            return stripos($v, 'cc') === false;
        });

        // gearbox then mileage: a mileage is digits and separators, a gearbox
        // never is (AT, FAT, CVT, F6, C4 ...)
        list($shift, $km) = cellPair($transL, function ($v) {
            return (bool) preg_match('/^[\d,.\s]+$/', $v);
        });

        // Colour, then the title. The title line is always present, so a single
        // surviving line is the title and the colour is simply missing -
        // reading line one regardless filed every such car under colour "N/A".
        $color = (count($colorL) >= 2) ? $colorL[0] : '';

        // auction grade then starting price: the price carries the yen sign
        list($grade, $price) = cellPair($gradeL, function ($v) {
            return strpos($v, '¥') !== false;
        });

        $status = trim($stateL[0] ?? '') !== '' ? strtolower(trim($stateL[0])) : 'available';
        $endPrice = $stateL[1] ?? '';

        if ($day === '' || $hall === '' || $lot === '') {
            continue;
        }

        $cars[] = array(
            'day' => $day, 'hall' => $hall, 'lot' => $lot,
            'maker' => $maker, 'model' => $model, 'modelGrade' => $mGrade,
            'cc' => $cc, 'year' => $year, 'km' => $km, 'shift' => $shift,
            'status' => $status, 'price' => $price, 'endPrice' => $endPrice,
            'grade' => $grade, 'color' => $color, 'chassis' => $chassis,
            'time' => $time,
            'images' => array(pickImage($xp, $tr)),
        );
    }
    return $cars;
}

/* -------------------------------------------------------------------- sink */

/**
 * The identity of one parsed row, or null if it has none.
 *
 * Sale day + hall + lot. The lot number alone is not unique: every hall numbers
 * its own lots, so lot 1 exists many times over on any given day.
 *
 * Kept out here because two things need to agree on it - the writer, and the
 * step that decides which of our rows the source has stopped listing. If those
 * two ever computed identity differently, the second would retire the rows the
 * first had just written.
 */
function jpaucCarId(array $c) {
    $day  = trim($c['day']);
    $hall = trim($c['hall']);
    $lot  = trim($c['lot']);
    if ($day === '' || $hall === '' || $lot === '') {
        return null;
    }
    if (!DateTime::createFromFormat('Y-m-d', $day)) {
        return null;
    }
    return 'jp-' . substr(sha1($day . '|' . $hall . '|' . $lot), 0, 24);
}

/**
 * Bring every block down to the number the source says it holds.
 *
 * The survey already knows this. It asks each block of a thousand lots what the
 * source has there - a hundred requests for the whole section - and if it says
 * 300 where we hold 500, then 200 of ours have gone. The count is proof; only
 * WHICH two hundred is unknown.
 *
 * Waiting for the pass to reach that block and read it out is the exact answer
 * and it takes most of a day. In the meantime the portal offers vehicles that
 * are not for sale - 19,395 of them when this was written, a customer looking
 * at fixed-price stock and seeing a fifth of it wrongly. The count being right
 * now is worth more than knowing exactly which rows were wrong.
 *
 * So the oldest are taken. Every read stamps last_updated, so the rows we saw
 * longest ago are the likeliest to be the ones that have gone, and if one is
 * picked wrongly the next read of its block puts it straight back - storeCars
 * writes status=VALUES(status), so a vehicle the source still lists returns to
 * the portal on sight.
 *
 * Never on a block the survey reported as empty: a page that failed to parse
 * reports zero the same way a genuinely empty range does, and acting on that
 * would clear the block. Those are left to the sweep, which can tell.
 *
 * @return int rows retired
 */
function trimToSource($conn, $section, $blocks) {
    if (!$blocks) {
        return 0;
    }
    $done = 0;
    foreach ($blocks as $from => $src) {
        $src  = (int) $src;
        $from = (int) $from;
        if ($src <= 0) {
            continue;
        }
        $to = $from + SCAN_BLOCK - 1;

        $st = $conn->prepare(
            "SELECT COUNT(*) FROM cars
              WHERE source_section = ? AND CAST(lot_no AS UNSIGNED) BETWEEN ? AND ?
                AND (status IS NULL OR status <> 'removed')");
        if (!$st) {
            continue;
        }
        $st->bind_param('sii', $section, $from, $to);
        $st->execute();
        $mine = (int) $st->get_result()->fetch_row()[0];
        $st->close();

        $over = $mine - $src;
        if ($over <= 0) {
            continue;
        }
        $up = $conn->prepare(
            "UPDATE cars SET status = 'removed', last_updated = NOW()
              WHERE source_section = ? AND CAST(lot_no AS UNSIGNED) BETWEEN ? AND ?
                AND (status IS NULL OR status <> 'removed')
              ORDER BY last_updated ASC
              LIMIT " . (int) $over);
        if (!$up) {
            continue;
        }
        $up->bind_param('sii', $section, $from, $to);
        $up->execute();
        $done += $up->affected_rows;
        $up->close();
    }
    return $done;
}

/**
 * Retire everything in a section the pass just finished did not see.
 *
 * The auction learns what has gone from the sale day - the day passes and the
 * vehicles go with it. oneprice has no day, and the only other way to learn it
 * is to look at the whole section and note what was not there.
 *
 * That is what a completed pass is. Every write stamps last_updated, the pass
 * reads every block, so a row still carrying a stamp from before the pass
 * started is a row the source stopped listing while the pass was running. It
 * costs no requests at all - the reading has already happened.
 *
 * Only ever called when a pass actually reached the end. A pass abandoned
 * halfway has not looked at the blocks it never got to, and retiring on that
 * would take the whole tail of the catalogue off the portal.
 *
 * @return int rows retired
 */
function retireUnseen($conn, $section, $since) {
    $st = $conn->prepare(
        "UPDATE cars SET status = 'removed', last_updated = NOW()
          WHERE source_section = ?
            AND last_updated < ?
            AND (status IS NULL OR status <> 'removed')");
    if (!$st) {
        return 0;
    }
    $st->bind_param('ss', $section, $since);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return max(0, $n);
}

/**
 * Take out the rows the source no longer lists.
 *
 * Retiring marks a vehicle gone; it does not remove it, and the marks were
 * piling up - 213,489 dead rows against 180,450 live ones, more than half the
 * table being four weeks of finished auction days and vehicles already
 * withdrawn. None of it reaches a customer, and none of it is wanted: the
 * owner's rule is that we hold what the source is showing and nothing else.
 * It still cost every query that had to step over it, and it made the admin
 * inventory read 393,798 when the business had 180,450 cars.
 *
 * The one thing kept is a vehicle somebody has actually touched. A bid, an
 * order, an enquiry or a result pointing at a car that is no longer in the
 * table leaves that record with nothing to name, so those rows stay however
 * old they are. There are twenty-seven of them; the test costs nothing.
 *
 * Bounded per run, and run by the cron rather than by hand, so the table is
 * kept clean from here on without anybody remembering to do it.
 *
 * @return int rows removed
 */
function purgeDead($conn, $limit = 5000, $seconds = 10.0) {
    // Everything pointing at a car, from whichever tables carry a car_id. Read
    // from the schema rather than listed here: a table added later would
    // otherwise start losing the vehicles its rows name, silently.
    static $keep = null;
    if ($keep === null) {
        $keep = array();
        // Base tables only - a view carrying car_id would be read as somewhere
        // vehicles are held, and it is only the tables underneath it that are.
        $r = $conn->query(
            "SELECT c.TABLE_NAME t FROM INFORMATION_SCHEMA.COLUMNS c
               JOIN INFORMATION_SCHEMA.TABLES tb
                 ON tb.TABLE_SCHEMA = c.TABLE_SCHEMA AND tb.TABLE_NAME = c.TABLE_NAME
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'car_id'
                AND c.TABLE_NAME <> 'cars' AND tb.TABLE_TYPE = 'BASE TABLE'");
        $tables = array();
        while ($r && $w = $r->fetch_row()) { $tables[] = $w[0]; }
        foreach ($tables as $t) {
            $q = $conn->query("SELECT DISTINCT car_id FROM `$t`
                                WHERE car_id IS NOT NULL AND car_id <> ''");
            while ($q && $w = $q->fetch_row()) { $keep[$w[0]] = true; }
        }
    }

    // A finished auction day, or a row already retired. Fixed-price stock has
    // no day, so only the retiring mark takes it out - which is right: it sits
    // on a forecourt until the source drops it.
    // A retired row waits an hour before it is deleted.
    //
    // Retiring is a judgement - a pass that did not see it, a count that came up
    // short - and a judgement can be wrong. Deleting on the spot made one wrong
    // judgement unrecoverable: 42,000 vehicles retired by a bad pass ending were
    // gone from the table within the minute, and the only way back was to read
    // them from the source again over nine hours. An hour is long enough to see
    // a mistake in the numbers and put it right, and short enough that nothing
    // stale reaches a customer - the portal never shows a retired row either
    // way.
    //
    // A finished auction day is not a judgement, so it goes at once.
    $dead = "((source_section = 'japan' AND auction_on < CURDATE())
              OR ((status = 'removed' OR status LIKE 'removed%')
                  AND last_updated < NOW() - INTERVAL 1 HOUR))";

    $guard = '';
    if ($keep) {
        $esc = array();
        foreach (array_keys($keep) as $id) {
            $esc[] = "'" . $conn->real_escape_string($id) . "'";
        }
        $guard = ' AND car_id NOT IN (' . implode(',', $esc) . ')';
    }

    // Five thousand at a time, for as long as the run can spare, rather than one
    // slice per run: the backlog was 213,483 rows, which at one slice a run is
    // most of a day of nothing else being cleared. Bounded by the clock rather
    // than a row count so the table is never held for long, and so a run that
    // finds nothing to do costs one query.
    $done = 0;
    $until = microtime(true) + $seconds;
    do {
        $conn->query("DELETE FROM cars WHERE $dead$guard LIMIT " . (int) $limit);
        $n = max(0, $conn->affected_rows);
        $done += $n;
    } while ($n > 0 && microtime(true) < $until);

    return $done;
}

/**
 * Only vehicles on a sale day the source itself is offering.
 *
 * Two rows reached the portal dated 16 September at 22:30 - a day the source
 * does not list and a time no hall sells at. Their make and model did not match
 * the lot either: the portal had a MITSUBISHI DELICA for Fukuoka lot 3030 where
 * the source has a NISSAN MOCO. One row in fifteen thousand parsed wrong, which
 * is rare enough to be unfindable and visible enough to matter - it put a
 * phantom Wednesday in the customer's date filter, and the owner found it there.
 *
 * The source publishes the days it is selling and the harvester already reads
 * them into `daysKey`. Anything landing outside that list is a misread, whatever
 * produced it, so it is dropped before it is stored rather than chased after.
 *
 * @param array  $cars    parsed rows
 * @param string $daysKey comma-separated Y-m-d list the source is offering
 * @return array the rows on those days
 */
function onlyKnownDays(array $cars, $daysKey) {
    $daysKey = trim((string) $daysKey);
    if ($daysKey === '') {
        return $cars;     // nothing to check against; never drop on a guess
    }
    $ok = array_flip(array_filter(array_map('trim', explode(',', $daysKey)), 'strlen'));
    if (!$ok) {
        return $cars;
    }
    $out = array();
    foreach ($cars as $c) {
        if (isset($ok[trim((string) ($c['day'] ?? ''))])) { $out[] = $c; }
    }
    return $out;
}

/**
 * Mark our rows in one lot window that the source no longer lists.
 *
 * Called once a window of lot numbers has been read to its last page, so
 * $seenIds is the whole of what the source holds there. Anything of ours in the
 * same window that is not in it has gone - sold, or withdrawn.
 *
 * Nothing is deleted. 'removed' is the status both portal views already exclude,
 * so the row leaves the site and stays in the table.
 *
 * @param array $seenIds car ids the source returned for this window
 * @return int rows retired
 */
function retireVanished($conn, $section, $from, $to, array $seenIds) {
    $sql = "UPDATE cars SET status = 'removed', last_updated = NOW()
            WHERE source_section = ?
              AND CAST(lot_no AS UNSIGNED) BETWEEN ? AND ?
              AND status <> 'removed'";
    $types  = 'sii';
    $params = array($section, $from, $to);

    if ($seenIds) {
        $sql .= " AND car_id NOT IN (" . implode(',', array_fill(0, count($seenIds), '?')) . ")";
        $types .= str_repeat('s', count($seenIds));
        $params = array_merge($params, $seenIds);
    }

    $st = $conn->prepare($sql);
    if (!$st) {
        return 0;
    }
    $st->bind_param($types, ...$params);
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return (int) $n;
}

function storeCars($conn, array $cars, $section) {
    // Fixed price was removed from the portal on the owner's order of 13 September
    // 2026. If this harvester is ever resumed, its oneprice turn must not put the
    // section back - whatever it reads there is dropped here, unwritten.
    if (!$cars || $section === 'oneprice_mix') {
        return 0;
    }
    static $stmt = null;
    if ($stmt === null) {
        $sql = "INSERT INTO cars
          (car_id, lot_no, make, model, year, mileage, price, currency, sold_price,
           auction, auction_date, auction_time, chassis, transmission, grade, rating,
           engine_cc, color, status, images, source_url, source_section,
           last_updated, created_at)
         VALUES (?,?,?,?,?,?,?,'yen',?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
         ON DUPLICATE KEY UPDATE
           lot_no=VALUES(lot_no), make=VALUES(make), model=VALUES(model), year=VALUES(year),
           mileage=IF(VALUES(mileage) > 0, VALUES(mileage), mileage),
           price=IF(VALUES(price) > 0, VALUES(price), price),
           sold_price=IF(VALUES(sold_price) > 0, VALUES(sold_price), sold_price),
           auction=VALUES(auction), auction_date=VALUES(auction_date),
           auction_time=VALUES(auction_time), chassis=VALUES(chassis),
           transmission=VALUES(transmission), grade=VALUES(grade), rating=VALUES(rating),
           engine_cc=VALUES(engine_cc), color=VALUES(color), status=VALUES(status),
           images=IF(CHAR_LENGTH(VALUES(images)) > 2, VALUES(images), images),
           source_url=VALUES(source_url), last_updated=NOW()";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
    }

    $n = 0;
    foreach ($cars as $c) {
        // Identity is sale day + hall + lot. The lot number alone is not
        // unique: every hall numbers its own lots, so lot 1 exists many times
        // over on any given day.
        $carId = jpaucCarId($c);
        if ($carId === null) {
            continue;
        }
        $day  = trim($c['day']);
        $hall = trim($c['hall']);
        $lot  = trim($c['lot']);
        $d    = DateTime::createFromFormat('Y-m-d', $day);
        $date = $d->format('d.m.Y');
        $year    = (int) $c['year'] ?: null;
        $mileage = (int) preg_replace('/\D+/', '', $c['km']) ?: 0;
        $price   = (float) preg_replace('/[^\d.]/', '', $c['price']) ?: 0;
        $sold    = (float) preg_replace('/[^\d.]/', '', $c['endPrice']) ?: 0;
        $cc      = (int) preg_replace('/\D+/', '', $c['cc']) ?: null;
        $status  = strtolower(trim($c['status'])) ?: 'available';
        $images  = json_encode(array_values(array_filter($c['images'], 'strlen')));
        $srcUrl  = ($section === 'japan' ? 'https://jpauc.com/auction/search?lots='
                                         : 'https://jpauc.com/oneprice/search?lots=') . rawurlencode($lot);
        $time    = trim($c['time']) ?: null;
        $chassis = trim($c['chassis']) ?: null;
        $shift   = trim($c['shift']) ?: null;
        $mGrade  = trim($c['modelGrade']) ?: null;
        $aGrade  = trim($c['grade']) ?: null;
        $color   = trim($c['color']) ?: null;
        $maker   = trim($c['maker']);
        $model   = trim($c['model']);

        // The type string must line up with the arguments one for one. It did
        // not once: car_id was declared an integer, so every "jp-..." became 0
        // and sixteen thousand vehicles landed on a single row while the code
        // reported each one written.
        $stmt->bind_param(
            'ssss' . 'ii' . 'dd' . 'sss' . 'ssss' . 'i' . 'sssss',
            $carId, $lot, $maker, $model,
            $year, $mileage,
            $price, $sold,
            $hall, $date, $time,
            $chassis, $shift, $mGrade, $aGrade,
            $cc,
            $color, $status, $images, $srcUrl, $section
        );
        if ($stmt->execute()) {
            $n++;
        }
    }
    return $n;
}

/* --------------------------------------------------------------------- run */

// A caller that only wanted the parsing stops here.
if ($asLib) {
    return;
}


global $conn;

$secs = sections();
$s = stateLoad();

if (isset($opts['reset'])) {
    $which = (string) ($opts['sec'] ?? $s['cur']);
    if (!isset($secs[$which])) {
        $which = 'oneprice';
    }
    $s[$which]['lot'] = max(1, (int) ($opts['from'] ?? 1));
    $s[$which]['page'] = 1;
    $s[$which]['fillTo'] = 0;
    $s[$which]['scanNext'] = 0;      // survey again from the top
    $s[$which]['scanAt'] = '';
    $s[$which]['blocks'] = array();
    $s['cur'] = $which;
    $s['halted'] = '';
    stateSave($s);
    echo "reset: {$which} - survey dobara chalegi\n";
    exit;
}
if (isset($opts['sec']) && isset($secs[(string) $opts['sec']])) {
    $s['cur'] = (string) $opts['sec'];
}

/**
 * Read one page and print what the parser made of it. Nothing is stored and
 * the day's budget is untouched, so a change to the parsing can be checked
 * against the live source without waiting for the budget to reset - which is
 * exactly when a parsing bug is most worth checking.
 *
 *     jpauc-harvest.php?t=TOKEN&dry=52&sec=auction&page=2
 */
if (isset($opts['dry'])) {
    $which = isset($secs[(string) ($opts['sec'] ?? '')]) ? (string) $opts['sec'] : 'auction';
    $cfg = $secs[$which];
    $lot = max(1, (int) $opts['dry']);
    $pg  = max(1, (int) ($opts['page'] ?? 1));

    // `only` asks for one lot, `span` for any width - the wide ones are how a
    // cheap change check is costed: the page states its own total, so one
    // request over four hundred lots says whether anything in them moved
    // without reading a single vehicle.
    $span = isset($opts['only']) ? 1 : (int) ($opts['span'] ?? LOT_BATCH);
    $span = max(1, min(2000, $span));

    $t0 = microtime(true);
    list($html, $err) = fetchPage($cfg['url'], $lot, $pg, $span);
    $ms = (int) ((microtime(true) - $t0) * 1000);
    if ($html === null) {
        echo "read fail: {$err}\n";
        exit;
    }
    $cars = call_user_func($cfg['parse'], $html);
    printf("section %s  lots %d..%d  page %d  ->  %d gaadi  |  page kehta hai: %s  |  %d ms\n\n",
        $which, $lot, $lot + $span - 1, $pg, count($cars),
        (declaredTotal($html) >= 0 ? declaredTotal($html) : 'ginti nahi mili'), $ms);
    if (isset($opts['countonly'])) {
        exit;
    }
    foreach ($cars as $c) {
        printf("%-11s %-14s lot %-6s  %s %s\n", $c['day'], $c['hall'], $c['lot'], $c['maker'], $c['model']);
        printf("   year %-6s cc %-9s chassis %-12s trans %-6s km %-9s\n",
            $c['year'], $c['cc'], $c['chassis'], $c['shift'], $c['km']);
        printf("   modelGrade %-20s aucGrade %-6s color %-16s price %-12s [%s]\n\n",
            $c['modelGrade'], $c['grade'], $c['color'], $c['price'], $c['status']);
    }
    exit;
}

if ($s['halted'] !== '') {
    echo "HALTED: {$s['halted']}\nreset ke liye ?reset=1\n";
    exit;
}
if ($s['used'] >= DAILY_BUDGET) {
    echo "budget khatam: {$s['used']} / " . DAILY_BUDGET . " (aaj)\n";
    exit;
}

$did = 0;
$wrote = 0;
$seen = 0;
$retired = 0;

/* Clear out what the source has dropped, a bounded slice at a time.
   Before the reading rather than after: the block counts the survey compares
   against are taken from this table, and counting rows that are on their way
   out is how a block looks full when it is empty. */
$purged = purgeDead($conn);

$runStart  = microtime(true);
/* What the freshness checks may spend of a run.
   They are cheap one at a time and ruinous together: the tail read and the day
   watch come round every minute, the forty-one-house sweep every ten, and a run
   is four minutes long. Left unchecked they took every request a run had - the
   catalogue sat on page 343 for an hour while requests went out steadily and
   not one of them read a page.
   A quarter. The checks still run, a little of each sweep at a time, and the
   pass always gets the rest. */
$checkDid  = 0;
// A quarter of what a run can actually do - not a quarter of what it is allowed
// to ask for. Those are different numbers and the difference mattered: --max is
// 160, but at seven and a half seconds a request a four-minute run manages
// about thirty. A cap of forty never bound, the checks went on taking whatever
// they wanted, and the pass was spending three requests to advance one page.
$checkCap  = max(3, (int) (RUN_LIMIT / (REQ_GAP + 6.5) / 4));
$resTurns  = 0;      // turns of lot results this run - see RESULT_TURNS
/* Declared here because the tail check reads $tailTurn BEFORE the turn counter
   further down sets it. On the first turn of every run it was simply undefined:
   PHP treated that as false and wrote "Undefined variable $tailTurn" to the
   error log - 350 times across 10-11 September, once per run. Starting it at
   false is exactly what PHP was already doing, so not one turn changes; the
   log just stops filling up with it. */
$tailTurn  = false;
$upkeep    = false;
while ($did < $maxRequests && $s['used'] < DAILY_BUDGET) {
    // Whatever it is in the middle of, a run ends. The next one is a minute
    // away and starts from the same page.
    if (microtime(true) - $runStart > RUN_LIMIT) {
        echo "waqt ki hadd - agla run yahin se uthayega
";
        break;
    }
    $cur = $s['cur'];
    $cfg = $secs[$cur];
    $cur_ = &$s[$cur];

    /* Is this section running ahead of its share of what has been spent so far?
       Then the other gets the next request.

       An allowance was not enough on its own. Spending the auction's six
       thousand and only then starting on oneprice is still one section waiting
       all day for the other - the shares were right, the order was not. Judging
       the ratio after every request instead of the total at the end means both
       move together from the first minute: the auction takes three requests in
       five, oneprice two, all day.

       The two rules cannot both fire, so the turn cannot bounce: the shares sum
       to one, so a section over its share leaves the other under its own. */
    $spent = $s['auction']['used'] + $s['oneprice']['used'];
    $other = ($cur === 'auction') ? 'oneprice' : 'auction';
    $aheadOfShare = $spent > 0 && ($cur_['used'] / $spent) > sectionRatio($cur);

    if (($aheadOfShare || $cur_['used'] >= sectionShare($cur))
        && $s[$other]['used'] < sectionShare($other)
        && sectionWants($conn, $s, $secs, $other)) {
        $s['cur'] = $other;
        stateSave($s);
        continue;
    }

    /* --------------------------------------------- the auction, as it is browsed
       Both sections have two views of themselves and they do not agree, and for
       a week this read the wrong one. The front page prints 83,075 vehicles
       across its sale days; walking into the site and listing them gives 48,512.
       The difference is the vehicles that have already been sold - the front
       page counts them, the listing does not, and a vehicle drops out of the
       listing the moment it goes under the hammer.

       So the listing is not just a different number, it is the answer to the
       question the portal actually asks: what can still be bought. Matching it
       means a vehicle leaves our portal when it leaves theirs, without our
       having to learn a result or interpret a status.

       Ten vehicles a page, about 4,763 pages, so a complete pass costs 4,763
       requests against a share of 18,000 - three or four passes a day. And a
       completed pass is what makes absence knowable: everything still listed
       has just been written, so anything of ours left carrying a stamp from
       before the pass began has gone from the source. */
    if ($cur === 'auction') {
        // The wizard keeps its selection in a cookie rather than the URL, so it
        // is walked once and the address kept.
        if ($cur_['base'] === '') {
            $base = wizardBase('https://jpauc.com/auction',
                               'https://jpauc.com/auction', AUC_COOKIE);
            $did++;
            $s['used'] += 4;
            $cur_['used'] += 4;
            if (!$base) {
                stateSave($s);
                echo "auction: listing tak nahi pohanch saka\n";
                break;
            }
            $cur_['base']      = $base;
            $cur_['baseAt']    = time();
            $cur_['countBase'] = wizardBase('https://jpauc.com/auction',
                                            'https://jpauc.com/auction', AUC_COUNT_COOKIE);
            $s['used'] += 4;
            $cur_['used'] += 4;

            // The sessions beside this one are opened one to a turn, below.
            $cur_['lanes'] = array();
            $cur_['countLanes'] = array();   // walked at the same moment,
                                             // so they expire at the same one
            $cur_['page']   = max(1, (int) $cur_['page']);
            if ($cur_['passFrom'] === '') {
                $cur_['passFrom'] = date('Y-m-d H:i:s');
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
            continue;
        }

        /* The reading sessions beside the first, one to a turn.
           All of them at once cost a whole run and saved nothing: seven wizard
           walks at four requests each is longer than a run is allowed to last,
           so the run ended inside the walk, wrote no state, and the next run
           began the same walk again. It would have sat there all night.
           One a turn, saved as it opens. A run that ends halfway through keeps
           the sessions it managed to open, and the pass reads through however
           many there are - four is quicker than one, and six than four. */
        $laneCap = (int) ($cur_['laneCap'] ?? (PASS_LANES - 1));
        if ($laneCap > PASS_LANES - 1) { $laneCap = PASS_LANES - 1; }
        // Lowering PASS_LANES has to take effect now, not whenever the sessions
        // happen to be walked again: sessions the source no longer needs to
        // serve are pressure on it for nothing.
        if (count((array) $cur_['lanes']) > $laneCap) {
            $cur_['lanes'] = array_slice((array) $cur_['lanes'], 0, $laneCap);
            $cur_['laneCap'] = $laneCap;
            stateSave($s);
        }
        if (count((array) $cur_['lanes']) < $laneCap) {
            $ln = count((array) $cur_['lanes']) + 1;
            $b  = wizardBase('https://jpauc.com/auction', 'https://jpauc.com/auction',
                             __DIR__ . '/jpauc-auction-' . $ln . '.cookie');
            $did++;
            $s['used'] += 4;
            $cur_['used'] += 4;
            if ($b) {
                $cur_['lanes'][] = $b;
                echo "auction: session " . ($ln + 1) . " khul gaya\n";
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
            // Not `continue` - a session that will not open must not stop the
            // pass from reading with the ones that did.
        }

        /* A sale day added since the session was walked is not in it, and the
           listing will never say so - it simply answers for a smaller
           catalogue, which is how 1 September's 942 vehicles stayed invisible.

           The front page names the days in one request, so it is asked every
           minute rather than waiting out the half hour: a day that was not
           there last minute means the session is stale now, and it is opened
           again at once. The half hour stays as a floor under that, for
           anything the day list does not show. */
        /* While the first pass is still running, the minute checks stand back.
           Measured over half an hour of the rebuild: 468 requests went out and
           the pass advanced 144 pages - two thirds of everything was going to
           the checks, and the rebuild that should take seven hours was on
           course for sixteen.
           The checks exist to keep a finished catalogue current. There is no
           catalogue to keep current yet, so until the pass has closed once they
           run every five minutes instead of every one. */
        $warmup = ((int) $cur_['passes'] === 0);
        $every  = $warmup ? 300 : 60;

        $daysNow = '';
        if (time() - (int) ($cur_['daysAt'] ?? 0) > $every) {
            $dl = sourceDays();
            $did++;
            $checkDid++;
            $s['used']++;
            $cur_['used']++;
            $cur_['daysAt'] = time();
            if ($dl) {
                $daysNow = implode(',', array_keys($dl));
                $cur_['days'] = $dl;
            }
            stateSave($s);
        }
        $dayChange = ($daysNow !== '' && $daysNow !== (string) ($cur_['daysKey'] ?? ''));
        if ($dayChange) {
            $cur_['daysKey'] = $daysNow;
        }

        if ($dayChange || time() - (int) ($cur_['baseAt'] ?? 0) > SESSION_EVERY) {
            $cur_['base'] = '';
            stateSave($s);
            continue;
        }

        /* --------------------------------------------------- the tail check
           New vehicles are findable in seconds, and this is why.

           Every row carries the source's own id - 978,889,410 and counting -
           and the listing is in that order, ascending. Checked across the whole
           catalogue: page 1 begins at 978,639,171, the middle page sits at
           978,849,685, and the last page ends at 978,889,410. A vehicle listed
           a minute ago is on the last page, not scattered through 4,953 of
           them.

           So: read the end, walk backwards while the ids are ones we have not
           seen, stop as soon as they are. Three or four requests, every three
           minutes. What used to take a nine-hour pass to notice now takes about
           a minute, and the pass underneath is left to do what only it can -
           catch what changed in the middle. */
        /* The tail takes one turn in three, like the upkeep and the pass.

           Its own timer stopped bounding it once it became a scan that rarely
           finishes: tailAt only moves when the walk completes, so the gate
           stood open and the tail took every turn. The pass sat at page 1,021
           for ten minutes while the scan walked back fifteen hundred pages -
           and the pass is the only thing that retires a vehicle that has gone.
           A third each, and none of them can starve the others. */
        if ($tailTurn && $checkDid < $checkCap
            && time() - (int) $cur_['tailAt'] > ($warmup ? 300 : TAIL_EVERY)) {
            list($h1, $e1) = fetchListing($cur_['base'], 1, AUC_COOKIE, '&d=');
            $did++;
            $checkDid++;
            $s['used']++;
            $cur_['used']++;
            if ($e1 === 'refused') {
                $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
                stateSave($s);
                echo "RUKA: source refused. Sab band.\n";
                exit;
            }
            $tot  = ($h1 === null) ? -1 : declaredTotal($h1);
            $endP = ($tot > 0) ? (int) ceil($tot / PER_PAGE) : 0;
            // Kept for the house check below, which uses it to decide whether
            // there is anything to check for.
            if ($tot > 0) { $cur_['lastTotal'] = $tot; }

            /* Down every session at once, and as far back as it takes.

               It read one page at a time and stopped after thirty - three
               hundred vehicles, which the note above called more than an hour's
               arrivals had ever been. That held until a whole sale day arrived
               at once. 1 September came with 8,260: the tail took the newest
               three hundred and the other eight thousand waited three or four
               hours for the pass to reach them. Measured that morning, the
               portal held 5,233 of that day against the source's 8,260, and
               every other day was level.

               So the cap is gone and the walk uses the pass's own six sessions.
               It ends where it always ended - at the first page holding nothing
               newer than we have - and now that is the only thing that ends it.

               Ninety vehicles a minute becomes about two hundred and forty,
               which is all the source will give: a day's 8,260 lands in about
               half an hour instead of four. It cannot be a minute. A pipe that
               carries 240 a minute does not pass 8,260 in sixty seconds, and no
               arrangement at this end changes that. */
            $seenTop = (int) $cur_['maxId'];
            $newTop  = $seenTop;
            $fresh   = array();
            $done    = false;
            /* How many pages of nothing new end the walk.
               One was not enough. On 31 August a sale day opened with 6,181
               vehicles and the tail took 808 of them: it walked back, met a
               single page holding only ids it already had, and called that the
               end - while five thousand more sat further back, because the ids
               are not one unbroken block at the tail. Worse, the mark then
               moved to the highest id seen, so everything it had stepped over
               counted as known and the tail never looked at them again. Only
               the pass found them, hours later.
               Three pages in a row, and the mark moves only when the walk ends
               on its own - never when the clock ends it. */
            $quietPages = 0;

            // A first run has no mark to walk back to, and without one the walk
            // is the entire listing backwards. The pass is what covers that.
            $cap  = ($seenTop > 0) ? PHP_INT_MAX : TAIL_MAX;
            $back = ($seenTop > 0) ? (int) ($cur_['tailBack'] ?? 0) : 0;

            $tBases = array_merge(array($cur_['base']), (array) ($cur_['lanes'] ?? array()));
            $tJars  = array(AUC_COOKIE);
            for ($ln = 1; $ln < count($tBases); $ln++) {
                $tJars[] = __DIR__ . '/jpauc-auction-' . $ln . '.cookie';
            }

            /* Pages a sample has shown to be short, waiting to be read in
               full. Carried between runs, because a scan that finds a block of
               six hundred cannot read it inside one run. */
            $gapQ = (array) ($cur_['gapQ'] ?? array());

            $tailRead = 0;
            while (true) {
                // A walk long enough to outlast the run must not hold the lock
                // past it. Where it reached is remembered and taken up again.
                if (microtime(true) - $runStart > RUN_LIMIT) { break; }
                // Nor may it take the whole run from the pass.
                if ($tailRead >= TAIL_PAGES) { break; }

                /* Two modes. Filling comes first: a block already known to
                   be short is worth more than looking for the next one. */
                $want = array();
                $scan = empty($gapQ);
                if (!$scan) {
                    for ($i = 0; $i < count($tBases) && $gapQ; $i++) {
                        $want[] = (int) array_shift($gapQ);
                    }
                } else {
                    for ($i = 0; $i < count($tBases); $i++) {
                        $pg = $endP - $back - ($i * TAIL_STRIDE);
                        if ($pg >= 1) { $want[] = $pg; }
                    }
                    if (!$want) {
                        // Sampled all the way to the front. Start again from
                        // the end next time.
                        $done = true;
                        break;
                    }
                }
                if (!$want) { break; }

                /* The walk's own bound is TAIL_PAGES, above. It used to be
                   counted against checkCap as well - a quarter of the run,
                   eight requests - which cut the walk off after twelve pages
                   however much of its own budget was left. Two bounds on one
                   thing, and the tighter one was the accident. */
                $res = fetchLanesAt($tBases, $tJars, $want);
                $tailRead     += count($want);
                $did          += count($want);
                $s['used']    += count($want);
                $cur_['used'] += count($want);

                foreach ($res as $one) {
                    if ($one[1] === 'refused') {
                        $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
                        stateSave($s);
                        echo "RUKA: source refused. Sab band.\n";
                        exit;
                    }
                }

                /* A page is quiet when we already hold everything on it -
                   not when its ids are below the highest we have ever seen.

                   The mark was the test, and it cannot be. It is the highest id
                   the tail has reached, and a hall that publishes a sale day
                   late gets ids below it: on 4 September, page 3706 held ten
                   vehicles for the 5th with ids around 979,175,562 against a
                   mark of 979,194,422, and not one of them was in the portal.
                   The tail read that page, judged every vehicle on it already
                   known, counted the page quiet, and after three such pages
                   stopped. It could never find them; only the pass could, and
                   the pass was two thousand pages away. Twenty thousand
                   vehicles sat missing for hours with the tail walking over
                   them.

                   What we hold is a fact and the id is a guess about it. */
                $moved = 0;
                foreach ($res as $k => $one) {
                    if ($one[0] === null) { continue; }
                    $got = parseAuctionListing($one[0]);
                    if (!$got) { continue; }

                    $byId = array();
                    foreach ($got as $c) { $byId[jpaucCarId($c)] = $c; }
                    $held = heldCarIds($conn, array_keys($byId));

                    $newOnes = 0;
                    foreach ($byId as $cid => $c) {
                        if ((int) $c['srcId'] > $newTop) { $newTop = (int) $c['srcId']; }
                        if (!isset($held[$cid])) { $fresh[] = $c; $newOnes++; }
                    }
                    $moved++;

                    /* A sample holding anything we are missing means the block
                       behind it is short as well - the listing is ordered by
                       sale time, so a day that arrived late is contiguous. Its
                       pages go on the queue and the next turns read them in
                       full. A sample holding nothing new means there is nothing
                       in those nineteen pages either, and the walk moves on
                       having spent one request instead of twenty. */
                    if ($scan && $newOnes > 0 && isset($want[$k])) {
                        $at = (int) $want[$k];
                        for ($d = 1; $d < TAIL_STRIDE; $d++) {
                            $q = $at - $d;
                            if ($q >= 1) { $gapQ[] = $q; }
                        }
                    }
                }
                // Only a scan moves the walk along; filling reads pages the
                // walk has already stepped past.
                if ($scan) { $back += count($tBases) * TAIL_STRIDE; }
                if ($done) { break; }
                usleep((int) (REQ_GAP * 1000000));
            }

            /* The queue outlives the run. Bounded, because a catalogue that has
               gone badly out of step could otherwise queue every page in it and
               the list would be larger than the work it describes. */
            $cur_['gapQ'] = array_slice(array_values($gapQ), 0, 6000);

            if ($fresh) {
                $keep = onlyKnownDays($fresh, $cur_['daysKey'] ?? '');
                $seen  += count($fresh);
                $wrote += storeCars($conn, $keep, 'japan');
                echo "tail: " . count($keep) . " nayi gaari\n";
            }

            /* What the source held this minute, and what arrived. Written here
               because here is where both are already known. */
            if ($tot > 0) {
                @file_put_contents(PULSE_LOG,
                    date('Y-m-d H:i') . ' total=' . $tot
                    . ' new=' . count($fresh)
                    . ' maxid=' . $newTop . "\n",
                    FILE_APPEND);
                // A week, then the oldest go.
                if (@filesize(PULSE_LOG) > 700000) {
                    $keep = @file(PULSE_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    if ($keep) {
                        @file_put_contents(PULSE_LOG,
                            implode("\n", array_slice($keep, -10080)) . "\n");
                    }
                }
            }

            /* The mark moves only when the walk actually reached vehicles we
               already held. Moving it after a walk cut short by the clock would
               declare everything below the highest id seen to be known, and the
               ones in between - never read - would not be looked for again. */
            if ($done) {
                $cur_['maxId']    = $newTop;
                $cur_['tailBack'] = 0;
                $cur_['tailAt']   = time();
            } else {
                $cur_['tailBack'] = $back;
                echo "tail: {$back} page tak pohancha, agla run yahin se\n";
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
            continue;
        }


        /* The counting sessions, one to a turn.
           They cost four requests each to open and are only opened while there
           are fewer than the reading lanes - the day's houses are asked in
           batches of however many exist, so one is slow and six is a second. */
        if (count((array) ($cur_['countLanes'] ?? array())) < AUC_LANES - 1
            && $cur_['countBase'] !== '') {
            $ln = count((array) ($cur_['countLanes'] ?? array())) + 1;
            $b  = wizardBase('https://jpauc.com/auction', 'https://jpauc.com/auction',
                             __DIR__ . '/jpauc-count-' . $ln . '.cookie');
            $did++;
            $s['used'] += 4;
            $cur_['used'] += 4;
            if ($b) {
                $cur_['countLanes'][] = $b;
                echo "count session " . ($ln + 1) . " khul gaya\n";
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
        }

        /* The forty-one-hall sweep used to live here, and it is gone.

           It asked each hall in turn how many it held, and where our count was
           higher it removed the difference - oldest-read first, on the belief
           that a vehicle the pass has stopped finding is the one whose stamp
           stops moving. That belief is only true if the pass comes round
           quickly. It takes hours, so a vehicle still on sale carries a stamp
           as old as one that sold this morning, and the sweep took whichever
           happened to be oldest.

           Checked against the source's own lot search, ten of ten vehicles it
           had just removed were still listed. The pass put them back, the hall
           read as over again, and the count swung between the two - which is
           what the owner saw when he said the number kept moving.

           The halls are still counted, in the batch below, and a count still
           says how many a hall has lost. It no longer says which: every vehicle
           it nominates is asked after by name before anything is removed. */

        /* Six requests a batch, and never eighteen.

           The pass's pages, the halls' counts and the lot searches were put in
           one batch on the reasoning that they wait on different sessions and
           so should not wait on each other. Measured, that was wrong twice
           over:

               18 requests together, whole batch      27.5s
                 6 pages                              17.0s each
                 6 hall counts                        27.0s each
                 6 lot searches                        4.4s each

           A page alone takes seven seconds; inside that batch it took
           seventeen. The source slows everything down as the number of requests
           in flight rises, whatever sessions they are on - so concurrency, not
           the session, is what has to be held to six. And a hall count is four
           times a page's work, so putting one in a batch of pages holds the
           pages for twenty-seven seconds to save nothing.

           Each kind takes its turn instead, six at a time. A turn of pages is
           eight seconds and yields six; a turn of halls is twenty-seven and
           covers six of the day's nineteen; a turn of lot searches is four. */

        $bases = array_merge(array($cur_['base']), (array) ($cur_['lanes'] ?? array()));
        $jars  = array(AUC_COOKIE);
        for ($ln = 1; $ln < count($bases); $ln++) {
            $jars[] = __DIR__ . '/jpauc-auction-' . $ln . '.cookie';
        }
        $cBases = array_values(array_filter(array_merge(
            array($cur_['countBase']), (array) ($cur_['countLanes'] ?? array()))));
        $cJars = array(AUC_COUNT_COOKIE);
        for ($ln = 1; $ln < count($cBases); $ln++) {
            $cJars[] = __DIR__ . '/jpauc-count-' . $ln . '.cookie';
        }

        /* The fill goes first, and the counts wait for it.

           They were the other way round and the fill crawled: a full round of
           the halls is seven turns of about twenty-seven seconds, which is most
           of a four-minute run, so the fill got one turn a run - six pages -
           and ten thousand vehicles would have taken three hours.

           There is nothing for the counts to find while a fill is running that
           matters more than the fill. They exist to notice a hall that has
           moved; one already has, we are reading it, and a sold vehicle showing
           for twenty minutes longer is a smaller fault than nine thousand
           vehicles nobody can see at all. */
        /* ---- a turn of filling in a hall the pass has not reached.

           Six pages of one hall's own listing, down the counting sessions, so
           the pass's own sessions are untouched and its page cursor does not
           move. The vehicles are stored exactly as the pass stores them.

           The fill holds the run while it lasts - about twenty minutes for a
           hall of ten thousand - and the pass waits. That is the right way
           round: the pass is a backstop that comes past everything every few
           hours anyway, and a hall nobody can see at all is worth more than a
           few hundred pages of re-reading what we already have. */
/* Nothing running: take the largest gap that is waiting.

           Largest, not first found. The counts come round the halls six at a
           time, so first-found means whichever hall happened to be early in the
           list - and a hall forty-three short was read whole, for nine minutes,
           while the hall nine thousand short waited its turn behind it. A gap
           of thousands is the one a customer can see. */
/* One fill, then a full round of the counts, then the next.

           With the slack at thirty there is nearly always another hall waiting,
           and the fill sits in front of the counts - so without this the counts
           would never run again and nothing sold would ever leave the portal.
           The flag is set when a fill finishes and cleared when the counts have
           been all the way round, which is the promise that matters most: a
           vehicle that has sold stops being offered. */
        /* Every other turn is the pass's, and nothing may take it.

           The turns were simply in order of priority - counts, then the
           vehicles those counts nominated, then the pass - and the first two
           are never finished. A round of the halls is seven turns; each round
           nominates up to twelve vehicles a hall, which is fifteen more turns
           of lot searches; and by the time those are done the halls are due
           again. The pass got what was left, which was nothing: it moved
           thirty-six pages in two hours and twenty minutes, against the five
           and a half a minute it manages when it runs.

           That is the whole engine stalling. The pass is the only thing that
           reads the listing the portal is meant to match - it is what brings
           new vehicles in and, at the end of a full sweep, what takes gone ones
           out. Starved, the portal drifts away from the website in both
           directions at once, which is exactly what the owner was looking at.

           So the run alternates. Half the turns do the upkeep, half advance the
           pass, and neither can starve the other however long its queue. */
        $cur_['tick'] = ((int) ($cur_['tick'] ?? 0) + 1) % 3;
        $upkeep   = ($cur_['tick'] === 0);
        // Nothing asks the lot search any more; anything left in the state file
        // from before is a list of questions with no asker.
        if (!empty($cur_['toCheck'])) { $cur_['toCheck'] = array(); }
        $tailTurn = ($cur_['tick'] === 1);
        // tick 2 is the pass's, and nothing else may take it.

        /* ---- which hall is being read out.

           This is decided before anything else in the turn, because it asks the
           source for nothing and so must not wait for a turn of its own. It did
           wait, and it never got one: a run ends on the clock, a turn of hall
           counts takes twenty-seven seconds of it, and two of those finish a
           round and the run with it. Tokyo sat 398 over for an hour with a
           queue naming it and not a page read. */
        /* ---- a hall held too long goes back on the queue.

           Not a repair for anything below - the alternation there is that - but
           a floor under it. Whatever the reason a walk stops moving, one hall
           must not be able to hold the other forty-seven. It goes back with the
           gap it had, so the picker will take it again when it is the worst;
           nothing is removed on a walk that did not finish, so putting it back
           costs the pages it had read and nothing else. */
        if (RECON_ON && $upkeep && ($cur_['recHall'] ?? '') !== ''
            && time() - (int) ($cur_['recAt'] ?? 0) > RECON_HOLD) {
            $held = hallHeld($conn, $cur_['recHall']);
            $cur_['recQ'][$cur_['recHall']] = array(
                'gap'    => max(1, $held - (int) $cur_['recListed']),
                'listed' => (int) $cur_['recListed'],
                'pages'  => max(1, (int) ceil(((int) $cur_['recListed']) / PER_PAGE)));
            echo "hall wapis qatar mein: {$cur_['recHall']} (page {$cur_['recPage']}"
               . " par ruka tha) - kuch nahi hataya\n";
            $cur_['recHall'] = '';
            stateSave($s);
        }

        /* ---- take up the next hall that is over.

           The biggest gap first, which sounds right and is not: the biggest
           gap belongs to the biggest hall. Chubu is 1,508 over and 366 pages,
           and nothing at all comes off the portal until the last of those pages
           is read, because a vehicle unseen in a half-read hall is a vehicle
           unread, not a vehicle gone. Four hours before one row moves, with
           forty-six halls waiting.

           What the queue should be ordered by is what it costs to square each
           hall. Measured on the queue as it stands: Kantou is 448 over in 72
           pages, Gifu 442 in 79, Hokkaido 282 in 54 - six vehicles a page
           against Chubu's four, and each one finishes inside an hour so the
           portal comes down in steps instead of standing still all morning.
           Chubu is still taken; it is taken when the cheap ones are done. */
        /* recWait is kept in the state for older files and is no longer
           set; a hall follows a hall without waiting for the counts. */
        if (RECON_ON && $upkeep && ($cur_['recHall'] ?? '') === ''
            && !empty($cur_['recQ'])) {
            $pick = ''; $best = 0; $rate = -1;
            foreach ((array) $cur_['recQ'] as $hall => $q) {
                $r = (int) $q['gap'] / max(1, (int) $q['pages']);
                if ($r > $rate) {
                    $rate = $r; $best = (int) $q['gap']; $pick = $hall;
                }
            }
            if ($pick !== '') {
                $cur_['recHall']   = $pick;
                $cur_['recListed'] = (int) $cur_['recQ'][$pick]['listed'];
                $cur_['recPage']   = max(1, (int) $cur_['recQ'][$pick]['pages']);
                $cur_['recSeen']   = 0;
                $cur_['recBad']    = 0;
                $cur_['recFrom']   = dbNow($conn);
                $cur_['recAt']     = time();
                unset($cur_['recQ'][$pick]);
                echo "hall milaana shuru: {$pick} ({$best} zyada, "
                   . round($rate, 1) . " per page, {$cur_['recPage']} page ulta)\n";
            }
            stateSave($s);
            // No continue. Choosing a hall asks the source for nothing, so it
            // takes no turn; the turn goes on to the pass as it would have.
        }

        /* ---- while a hall is in hand, the upkeep turn alternates.

           Three things want the upkeep turn: the hall counts, the sale results,
           and reading a hall out of the listing. They were tried in that order
           and the hall read came last, which in practice meant never - both of
           the others always have work:

             - the counts only stand aside when a full round of the halls has
               finished, and hotAt marks the end of a round, not of a turn, so
               once the gate opens it stays open for the whole round;
             - the results turn queries a backlog thousands deep, so it always
               had rows and always took the turn.

           So the hall read starved. Hiroshima held page 51 for twenty hours,
           forty-seven halls queued behind it, and the portal drifted 3,885
           vehicles ahead of the source - vehicles jpauc had dropped and we had
           no means left of noticing.

           Priority does not work here because both of the others are endless.
           Turns do: while a hall is in hand, one upkeep turn goes to the
           supporting work and the next to the walk, and neither can starve the
           other however long its queue. It is the same fix, and the same
           reason, as the third each between the upkeep, the tail and the pass. */
        $recTurn = false;
        if (RECON_ON && $upkeep && ($cur_['recHall'] ?? '') !== '' && $cBases) {
            $cur_['recTick'] = ((int) ($cur_['recTick'] ?? 0) + 1) % 2;
            $recTurn = ($cur_['recTick'] === 0);
        }


        if (!FILL_ON) {
            $cur_['fillHouse'] = '';
            $cur_['fillQueue'] = array();
        } elseif (!empty($cur_['fillWait'])) {
            // waiting for a full round of the counts; fall through to them
        } elseif (($cur_['fillHouse'] ?? '') === '' && !empty($cur_['fillQueue'])) {
            $pick = ''; $best = -1;
            foreach ((array) $cur_['fillQueue'] as $hall => $q) {
                if ((int) $q['short'] > $best) { $best = (int) $q['short']; $pick = $hall; }
            }
            if ($pick !== '') {
                $cur_['fillHouse'] = $pick;
                $cur_['fillPage']  = 1;
                $cur_['fillQuiet'] = 0;
                $cur_['fillEnd']   = (int) $cur_['fillQueue'][$pick]['pages'];
                unset($cur_['fillQueue'][$pick]);
                echo "bharna shuru: {$pick} ({$best} kam, {$cur_['fillEnd']} page)\n";
            }
        }

        if (FILL_ON && ($cur_['fillHouse'] ?? '') !== '' && $cBases) {
            $reqs = array();
            foreach ($cBases as $i => $b) {
                $p = (int) $cur_['fillPage'] + $i;
                if ($p > (int) $cur_['fillEnd']) { break; }
                $reqs[] = array('jar' => $cJars[$i], 'p' => $p,
                                'url' => houseUrl($b, $cur_['fillHouse'], $p));
            }
            if (!$reqs) {
                $cur_['fillHouse'] = '';
                stateSave($s);
                continue;
            }
            $res = fetchMany($reqs);
            $did          += count($reqs);
            $s['used']    += count($reqs);
            $cur_['used'] += count($reqs);
            foreach ($res as $one) {
                if ($one['err'] === 'refused') {
                    $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
                    stateSave($s);
                    echo "RUKA: source refused. Sab band.\n";
                    exit;
                }
            }
            $batch = array();
            foreach ($res as $one) {
                if ($one['body'] === null) { continue; }
                foreach (parseAuctionListing($one['body']) as $c) { $batch[] = $c; }
            }
            if ($batch) {
                $seen  += count($batch);
                $wrote += storeCars($conn, onlyKnownDays($batch, $cur_['daysKey'] ?? ''), 'japan');
                $cur_['fillQuiet'] = 0;
            } else {
                // Pages that answer with nothing are the end of the hall, or a
                // dropped session. Either way there is no point walking on.
                $cur_['fillQuiet'] = (int) $cur_['fillQuiet'] + 1;
            }
            $cur_['fillPage'] = (int) $cur_['fillPage'] + count($reqs);

            if ($cur_['fillPage'] > (int) $cur_['fillEnd']
                || (int) $cur_['fillQuiet'] >= FILL_QUIET) {
echo "bhar diya: {$cur_['fillHouse']} (p" . ($cur_['fillPage'] - 1) . ")\n";
                $cur_['fillHouse'] = '';
                // let the counts have their round before the next one
                $cur_['fillWait'] = 1;
                $cur_['hotAt']    = 0;
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
            continue;
        }

        /* ---- a turn of hall counts, when they are due.
           They nominate; they never remove. What they nominate is asked after
           by name below - the count says how many a hall has lost and cannot
           say which, and acting on the count alone took live vehicles off the
           portal for hours. */
        $hotGate = $warmup ? 300
                 : ((($cur_['recHall'] ?? '') !== '') ? HOT_BUSY : HOT_EVERY);
        if ($upkeep && !$recTurn && $cBases
            && time() - (int) $cur_['hotAt'] > $hotGate) {
            actLog($cur_, 'halls');
            $selling = sellingHouses($conn);
            /* Two of the halls not selling today ride along - see
               laterHouses(). The rotation only moves when a round completes,
               so the list is the same on every turn of a round and hotIdx
               keeps meaning what it means. */
            $later = laterHouses($conn);
            $later = array_values(array_diff($later, $selling));
            if ($later) {
                $ri = (int) ($cur_['restIdx'] ?? 0);
                for ($k = 0; $k < 2 && $k < count($later); $k++) {
                    $selling[] = $later[($ri + $k) % count($later)];
                }
            }
            $cur_['hot'] = $selling;
            $from = (int) ($cur_['hotIdx'] ?? 0);
            if ($from >= count($selling)) { $from = 0; }
            $slice = array_slice($selling, $from, count($cBases));

            $reqs = array();
            foreach ($slice as $i => $house) {
                $reqs[] = array('house' => $house, 'jar' => $cJars[$i],
                                'url' => houseUrl($cBases[$i], $house));
            }
            if ($reqs) {
                $res = fetchMany($reqs);
                $did          += count($reqs);
                $s['used']    += count($reqs);
                $cur_['used'] += count($reqs);
                foreach ($res as $one) {
                    if ($one['err'] === 'refused') {
                        $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
                        stateSave($s);
                        echo "RUKA: source refused. Sab band.\n";
                        exit;
                    }
                }
                foreach ($res as $one) {
                    if ($one['body'] === null) { continue; }
                    $listed = declaredTotal($one['body']);
                    if ($listed < 0) { continue; }
                    $mq = $conn->prepare(
                        "SELECT COUNT(*) FROM cars
                          WHERE source_section = 'japan' AND auction_on >= CURDATE()
                            AND auction = ? AND (status IS NULL OR status <> 'removed')");
                    if (!$mq) { continue; }
                    $mq->bind_param('s', $one['house']);
                    $mq->execute();
                    $mineN = (int) $mq->get_result()->fetch_row()[0];
                    $mq->close();

                    /* The hall holds more than we do. Read that hall on its own
                       rather than waiting for the pass to come round to it -
                       see FILL_SLACK. One at a time, because a fill takes the
                       counting sessions and there is no sense starting a second
                       before the first has finished. */
if (FILL_ON && $listed - $mineN > FILL_SLACK) {
                        $cur_['fillQueue'][$one['house']] = array(
                            'short' => $listed - $mineN,
                            'pages' => (int) ceil($listed / PER_PAGE));
                        echo "kami: {$one['house']} - source {$listed}, hamare {$mineN}\n";
                    }

                    /* The hall lists fewer than we hold. Which of ours have
                       gone is not a question the count can answer, and not one
                       the lot search can answer either - it answers from a
                       wider set and said yes to every vehicle the listing had
                       dropped. So the hall goes on the queue to be read out of
                       the listing itself. */
                    if ($mineN - $listed > RECON_SLACK) {
                        $cur_['recQ'][$one['house']] = array(
                            'gap'    => $mineN - $listed,
                            'listed' => $listed,
                            'pages'  => max(1, (int) ceil($listed / PER_PAGE)));
                    }
                }
                // The queue is keyed by hall, so it cannot grow past the
                // number of halls and a hall queued twice simply has its gap
                // brought up to date.
            }

            $cur_['hotIdx'] = $from + max(1, count($slice));
            if ($cur_['hotIdx'] >= count($selling)) {
                $cur_['hotIdx'] = 0;
                $cur_['hotAt']  = time();   // a full turn of the halls is the minute
                // the counts have been all the way round; a fill may go again
                $cur_['fillWait'] = 0;
                // and the next hall may be read out, with a fresh gap behind it
                $cur_['recWait']  = 0;
                $cur_['restIdx']  = (int) ($cur_['restIdx'] ?? 0) + 2;
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
            continue;
        }

        /* ---- a turn of reading the results of lots that have just sold.

           This is the one-minute promise, and it costs about eighteen requests
           a minute at the busiest hour of a sale day. No session is needed - the
           lot search answers to anyone - so these go out on their own and never
           hold up a page.

           Only vehicles we already hold are written. The lot search returns
           every hall's lot of that number and some of them are strangers; a
           stranger written in from here is the same mistake as the hall fill,
           and FILL_ON is off for exactly that reason. */
        if (RESULT_ON && $upkeep && !$recTurn && $resTurns < RESULT_TURNS) {
            /* Lots that concluded in the last three hours always; the older
               backlog only when no hall is being read out. A hall read is what
               keeps the portal level with the source and it must not queue
               behind a tidy-up of this morning. */
            $due = concludedLots($conn, RESULT_BATCH * 3,
                                 ($cur_['recHall'] ?? '') === '');
            if ($due) {
                $lots = array();
                foreach ($due as $w) {
                    $lot = trim((string) $w['lot_no']);
                    if ($lot !== '' && !isset($lots[$lot])) { $lots[$lot] = true; }
                    if (count($lots) >= RESULT_BATCH) { break; }
                }
                $reqs = array();
                foreach (array_keys($lots) as $lot) {
                    $reqs[] = array('jar' => null, 'url' => lotUrl($lot));
                }
                actLog($cur_, 'natija');
                $res = fetchMany($reqs);
                $resTurns++;
                $did          += count($reqs);
                $s['used']    += count($reqs);
                $cur_['used'] += count($reqs);
                foreach ($res as $one) {
                    if ($one['err'] === 'refused') {
                        $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
                        stateSave($s);
                        echo "RUKA: source refused. Sab band.\n";
                        exit;
                    }
                }
                $byId = array();
                foreach ($res as $one) {
                    if ($one['body'] === null) { continue; }
                    foreach (parseAuctionListing($one['body']) as $c) {
                        $cid = jpaucCarId($c);
                        if ($cid !== null) { $byId[$cid] = $c; }
                    }
                }
                $mine = array();
                if ($byId) {
                    $held = heldCarIds($conn, array_keys($byId));
                    foreach ($byId as $cid => $c) {
                        if (isset($held[$cid])) { $mine[] = $c; }
                    }
                }
                $concluded = 0;
                foreach ($mine as $c) {
                    $st_ = strtolower(trim((string) $c['status']));
                    if ($st_ !== '' && strpos($st_, 'available') !== 0) { $concluded++; }
                }
                if ($mine) {
                    $seen  += count($mine);
                    $wrote += storeCars($conn,
                        onlyKnownDays($mine, $cur_['daysKey'] ?? ''), 'japan');
                }
                if ($concluded) {
                    echo "natija: {$concluded} gaari ka faisla aa gaya (" . count($reqs) . " lot)\n";
                }
                stateSave($s);
                usleep((int) (REQ_GAP * 1000000));
                continue;
            }
        }

        /* ---- a turn of reading one hall out of the listing.

           Backwards, from its last page to its first. The listing shrinks while
           it is being walked - a lot sells, it leaves, and everything behind it
           slides forward a place. A reader moving towards page one moves the
           same way the contents move, so it can meet a vehicle twice but can
           never step over one. Forwards it steps over them, and a vehicle
           stepped over looks exactly like a vehicle that has gone.

           Nothing here writes a new vehicle in. See touchCars(). */
        /* The upkeep turn, and only that one. It had the tail's as well and
           the tail went nine hours without running - see the note by the tail
           above. A third of the turns is enough: a hall of four hundred pages
           takes an hour or two of them, and there is no hall so urgent that new
           stock should wait behind it. */
        /* The tail's turn, when the tail does not want it.

           Counted over fourteen turns: the pass took nine of them, the hall
           counts three, and the hall read two. The pass had two thirds of the
           engine because the tail's turn falls through to it - the tail only
           walks when its own minute is up, and the rest of the time tick one
           lands on the pass as well.

           That is the wrong way round while a hall is in hand. Both read the
           same pages; what differs is what the reading is worth. The pass reads
           the listing forwards, which is why a vehicle has to be missing from
           two complete passes before it counts as gone - about twenty hours.
           The hall read goes backwards, which cannot step over a vehicle, so
           one read is proof and the hall is squared the moment it finishes.

           So while a hall is in hand the spare turn goes to the read, and the
           pass keeps a third of the engine as the backstop it is. When the
           queue empties there is no hall in hand and every turn goes back where
           it was - nothing here asks the source for more than it did. */
        if (RECON_ON && ($upkeep || $tailTurn)
            && ($cur_['recHall'] ?? '') !== '' && $cBases) {
            actLog($cur_, $upkeep ? 'hall' : 'hall(tail)');
            $cur_['recAt'] = time();
            $reqs = array();
            for ($i = 0; $i < count($cBases); $i++) {
                $p = (int) $cur_['recPage'] - $i;
                if ($p < 1) { break; }
                $reqs[] = array('jar' => $cJars[$i], 'p' => $p,
                                'url' => houseUrl($cBases[$i], $cur_['recHall'], $p));
            }
            if ($reqs) {
                $res = fetchMany($reqs);
                $did          += count($reqs);
                $s['used']    += count($reqs);
                $cur_['used'] += count($reqs);
                foreach ($res as $one) {
                    if ($one['err'] === 'refused') {
                        $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
                        stateSave($s);
                        echo "RUKA: source refused. Sab band.\n";
                        exit;
                    }
                }
                /* An empty page has two meanings and they are opposites.

                   The walk starts at the last page the hall had WHEN IT WAS
                   COUNTED, and a hall shrinks hard once its own sale finishes.
                   Gifu was queued holding 790 and by the time its turn came it
                   held 217 - so the walk opened at page 79 against a hall that
                   now ends at 22, read fifty-five pages of nothing, counted
                   every one of them a failed read, and would have finished by
                   refusing to remove anything at all. Fifty-five requests spent
                   to arrive at "the read cannot be trusted".

                   But an empty page still declares how many the hall holds, so
                   it says exactly what went wrong and what the truth is. A page
                   past that end is not a failure - it is the shrinking this
                   whole walk exists to find. The walk drops to the real last
                   page and carries on from there. */
                $ids     = array();
                $seenWas = (int) $cur_['recSeen'];
                $jump    = 0;
                foreach ($res as $one) {
                    if ($one['body'] === null) {
                        $cur_['recBad'] = (int) $cur_['recBad'] + 1;
                        continue;
                    }
                    $got = parseAuctionListing($one['body']);
                    if (!$got) {
                        $decl = declaredTotal($one['body']);
                        $end_ = ($decl >= 0) ? max(1, (int) ceil($decl / PER_PAGE)) : 0;
                        if ($end_ && (int) $one['p'] > $end_) {
                            $cur_['recListed'] = $decl;
                            if (!$jump || $end_ < $jump) { $jump = $end_; }
                            continue;
                        }
                        $cur_['recBad'] = (int) $cur_['recBad'] + 1;
                        continue;
                    }
                    foreach ($got as $c) {
                        $cid = jpaucCarId($c);
                        if ($cid !== null) { $ids[] = $cid; }
                    }
                }
                // What the READ found, not what we hold of it - this measures
                // whether the hall was read, and a hall we are behind on would
                // otherwise look like a hall that failed to answer.
                $cur_['recSeen'] = (int) $cur_['recSeen'] + count($ids);
                touchCars($conn, $ids);
                $cur_['recPage'] = (int) $cur_['recPage'] - count($reqs);
                if ($jump) {
                    /* Nothing is stepped over by dropping to the real end: the
                       pages in between do not exist any more. And if the walk
                       has not yet met a single vehicle, then every page it has
                       called bad was one of these - so that tally goes with
                       them, or a hall that merely shrank finishes as a hall
                       that could not be read and removes nothing. */
                    if ((int) $cur_['recPage'] > $jump) { $cur_['recPage'] = $jump; }
                    if ($seenWas === 0) { $cur_['recBad'] = 0; }
                }
            } else {
                $cur_['recPage'] = 0;
            }

            if ((int) $cur_['recPage'] < 1) {
                /* Whether the walk may remove anything depends on whether it
                   actually read the hall. A page that failed and a page that is
                   genuinely empty look the same from here, and acting on that
                   difference is how a portal loses a catalogue - it cost 42,000
                   vehicles once already. So every page must have answered, and
                   what came back must be most of what the hall declared. */
                $listedN = (int) $cur_['recListed'];
                $sawN    = (int) $cur_['recSeen'];
                $badN    = (int) $cur_['recBad'];
                $heldN   = hallHeld($conn, $cur_['recHall']);
                /* A few pages out of several hundred may fail without costing
                   the whole read - Chubu is 475 pages and refusing on three of
                   them would throw away an hour's work and change nothing. The
                   cap below is what actually keeps a short read safe, and it is
                   tied to the count rather than to the read.

                   What must hold is that most of the hall came back: a read that
                   lost a tenth of it is not a read of that hall. And the removal
                   takes the oldest stamps first, so the vehicles a failed page
                   left unseen - which the pass saw only hours ago - stand behind
                   the ones nothing has seen since they left the listing. */
                $badOk = max(2, (int) ($listedN / 500));   // one page in fifty
                if ($badN <= $badOk && $listedN > 0
                    && $sawN >= (int) ($listedN * 0.9)) {
                    $cap  = max(1, ($heldN - $listedN) + RECON_CAP);
                    $gone = retireHallUnseen($conn, $cur_['recHall'],
                                             (string) $cur_['recFrom'], $cap);
                    $retired += $gone;
                    echo "hall barabar: {$cur_['recHall']} - {$gone} hatai"
                       . " (source {$listedN}, hamare {$heldN})\n";
                } else {
                    echo "hall adhoora padha: {$cur_['recHall']}"
                       . " ({$sawN}/{$listedN}, {$badN} page fail) - kuch nahi hataya\n";
                }
                $cur_['recHall'] = '';
                /* Straight on to the next hall. There used to be a wait here
                   for a full round of the counts; it blocked the queue for
                   twenty-five minutes at a stretch and bought nothing - see the
                   note by recWait in the picker. */
                $cur_['recWait'] = 0;
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
            continue;
        }

        /* ---- a turn of the pass: pages, and nothing else in flight with them. */
        actLog($cur_, $upkeep ? 'pass(upkeep!)' : ($tailTurn ? 'pass(tail)' : 'pass'));
        $reqs = array();
        foreach ($bases as $i => $b) {
            $reqs[] = array('n' => $i, 'jar' => $jars[$i],
                            'url' => pageUrl($b, $cur_['page'] + $i));
        }
        $res = fetchMany($reqs);
        $did          += count($reqs);
        $s['used']    += count($reqs);
        $cur_['used'] += count($reqs);

        $err = '';
        foreach ($res as $one) {
            if ($one['err'] === 'refused') { $err = 'refused'; break; }
        }
        $both = array();
        foreach ($res as $one) { $both[$one['n']] = array($one['body'], $one['err']); }
        ksort($both);
        $both = array_values($both);
        $html = isset($both[0]) ? $both[0][0] : null;

        /* Reading through eight sessions at once is the owner's call and it is
           faster, but it is also the most this has ever asked of the source at
           one moment. So it gives ground on its own: two or more sessions in a
           batch failing together is not a coincidence, it is the source under
           pressure, and one session is given up for the rest of this pass.
           It can fall back to a single session that way, which is slow but
           always works. A 403 is handled above and is not this - by the time
           the source refuses outright it is too late to negotiate. */
        if ($err !== 'refused') {
            $sick = 0;
            foreach ($both as $one) {
                if ($one[0] === null) { $sick++; }
            }
            /* And it takes the ground back when the trouble passes.
               Giving way permanently makes every hiccup cost the rest of the
               pass - one did, and the cause was not even the source: three
               COUNT(*) over ninety thousand rows, asked once a minute from
               outside, were enough to time a session out. A fault that clears
               should leave nothing behind. Fifty clean batches, then one
               session back; a real refusal will simply take it away again. */
            if ($sick === 0) {
                $cur_['laneOk'] = (int) ($cur_['laneOk'] ?? 0) + 1;
                if ($cur_['laneOk'] >= 50
                    && (int) ($cur_['laneCap'] ?? 0) < PASS_LANES - 1) {
                    $cur_['laneCap'] = (int) $cur_['laneCap'] + 1;
                    $cur_['laneOk']  = 0;
                    echo "auction: sab saaf - ek session wapas ("
                        . ($cur_['laneCap'] + 1) . ")\n";
                }
            }
            if ($sick >= 2 && count((array) $cur_['lanes']) > 0) {
                $cur_['laneOk'] = 0;
                array_pop($cur_['lanes']);
                // The ceiling comes down with it, or the next turn simply opens
                // the session again and the giving-way means nothing. It lifts
                // when the pass ends and a fresh set of sessions is walked.
                $cur_['laneCap'] = count($cur_['lanes']);
                echo "auction: {$sick} session ek saath fail - ek session kam kar diya ("
                    . ($cur_['laneCap'] + 1) . " bache)\n";
                stateSave($s);
            }
        }

        if ($err === 'refused') {
            $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
            stateSave($s);
            echo "RUKA: source refused. Sab band.\n";
            exit;
        }
        if ($html === null) {
            // An expired session reads as a failure. Drop the address so the
            // next turn walks the wizard again rather than asking a listing that
            // is no longer ours - and the sessions beside it, which were opened
            // at the same moment and expire at the same one.
            $cur_['base']  = '';
            $cur_['lanes'] = array();
            stateSave($s);
            echo "read fail ({$err}) auction p{$cur_['page']}\n";
            break;
        }

        $cars = parseAuctionListing($html);
        // The second page is only kept when the first was whole: a short first
        // page means the listing has ended, and what came back for the page
        // after the end is not a page of vehicles.
        // The pages after the first are kept only while each one before them
        // came back whole: a short page means the listing has ended, and what
        // answers for the pages past the end is not vehicles.
        $more = array();
        $ahead = 0;
        if (count($cars) >= PER_PAGE) {
            for ($i = 1; $i < count($both); $i++) {
                if (!isset($both[$i]) || $both[$i][0] === null) { break; }
                $got = parseAuctionListing($both[$i][0]);
                if (!$got) { break; }
                $more = array_merge($more, $got);
                $ahead++;
                if (count($got) < PER_PAGE) { break; }
            }
        }
        if ($cars || $more) {
            // Nothing dated outside the days the source offers - onlyKnownDays().
            $keep = onlyKnownDays(array_merge($cars, $more), $cur_['daysKey'] ?? '');
            $seen  += count($cars) + count($more);
            $wrote += storeCars($conn, $keep, 'japan');
        }

        $total = declaredTotal($html);
        $last  = ($total > 0) ? (int) ceil($total / PER_PAGE) : 0;

        // A pass is finished when the page count reaches the last page the
        // listing declares, and at no other time.
        //
        // An empty page used to count as finished too, and it cost 42,000
        // vehicles: one page came back without rows - a dropped session, a bad
        // response, it does not matter which - the pass was declared complete
        // two hundred pages in, and retireUnseen took out everything the pass
        // had not reached yet. Which was nearly all of it.
        //
        // An empty page is a failed read. It is worth nothing and means nothing.
        // A pass ends at the declared last page - but only when that page count
        // is credible. A total that has collapsed to a fraction of what the
        // listing held a moment ago is a filtered or broken response, not a
        // catalogue that shrank, and acting on it retires everything the pass
        // has not reached.
        $wasEnd = (int) ($cur_['lastEnd'] ?? 0);
        $sane   = ($wasEnd === 0 || $last >= $wasEnd / 2);
        if ($last > 0 && $sane) { $cur_['lastEnd'] = $last; }
        $done = ($last > 0 && $sane && $cur_['page'] >= $last);
        if ($done) {
            /* Missing from one pass is not gone, and treating it as gone is
               why the portal sat six thousand short of the source and never
               closed the gap.

               The listing is ordered oldest first and it shrinks while the pass
               walks it: every vehicle that sells is taken out, and everything
               behind it slides back one place. A vehicle sliding from page 3456
               to 3455 while the pass is at 3455 is never read - not because it
               went anywhere, but because it moved past the reader. Measured
               across the listing tonight, whole pages of it: page 3455 held ten
               vehicles and we had none of them, page 4935 seven of ten, while
               eight other pages sampled were complete. Fourteen per cent, in
               pockets, exactly where a shifting list would lose them.

               Then this retired every one of them as sold, and the purge took
               them an hour later. The next pass read them back in, and the pass
               after that lost another set. The number could never arrive.

               So a pass is one witness, not a verdict. A vehicle has to be
               missing from two consecutive complete passes to count as gone:
               a slide loses it once and the next pass finds it, while something
               genuinely sold is never seen again. Sold vehicles still leave
               quickly by the per-house counts, which do not depend on this. */
            // The first pass under this rule has no earlier pass to stand as
            // the second witness, so it retires nothing and only adds. It is
            // the pass that carries the catalogue up to the source; taking the
            // old rule's word one last time would remove the six thousand this
            // is being run to recover. Sold vehicles still leave by house count.
            $judge = (string) ($cur_['passPrev'] ?? '');
            if ($judge !== '') {
                $gone = retireUnseen($conn, 'japan', $judge);
                if ($gone) {
                    $retired += $gone;
                }
            }

            /* The one repair - see FIX_TAG. The pass that has just ended read
               the whole listing, so a vehicle it never saw is not in the
               listing, and that is the only thing the portal is meant to hold.
               `passFrom` is still this pass's own start here; it is moved on a
               few lines below. */
            if (($cur_['fixDone'] ?? '') !== FIX_TAG) {
                $extra = retireUnseen($conn, 'japan', (string) $cur_['passFrom']);
                $cur_['fixDone'] = FIX_TAG;
                if ($extra) {
                    $retired += $extra;
                    echo "ek dafa ki safai: {$extra} gaariyan hatai gayeen\n";
                }
                stateSave($s);
            }
            $cur_['real']     = array();     // counted afresh next pass
            $cur_['page']     = 1;
            $cur_['passPrev'] = $cur_['passFrom'];
            $cur_['passFrom'] = date('Y-m-d H:i:s');
            $cur_['base']     = '';          // a fresh session for the next pass
            $cur_['laneCap']  = PASS_LANES - 1;  // and a fresh chance at full speed
            $cur_['passes']++;
        } else {
            $cur_['page'] += 1 + $ahead;
        }
        stateSave($s);
        usleep((int) (REQ_GAP * 1000000));
        continue;
    }

    /* -------------------------------------------- oneprice, as it is browsed
       The fixed-price section has two views of itself and they do not agree.
       Asked by lot number - which is what this did - it returns about 130,000
       vehicles. Walked into and listed, it returns 89,138, and that is the one
       a customer sees and the one the client says the portal must match.

       The difference was not cosmetic. We were carrying some 29,000 vehicles
       the browse view does not list, and missing the whole of GAO's 13,723,
       because the lot search never returned them at all. Neither could be seen
       from inside the old approach: both views are the source's own, and
       counting from the wrong one made the catalogue look complete.

       So the listing is read directly. Ten vehicles a page and 8,892 pages, the
       same price per vehicle as everything else here, and a complete pass costs
       8,892 requests against a share of 27,000 - three passes a day, where the
       lot walk managed less than one. Anything not seen during a pass is gone;
       that is what the pass makes knowable. */
    if ($cur === 'oneprice') {
        // The wizard holds the selection in a cookie rather than the URL, so it
        // is walked once and the address kept. Four requests, and again only if
        // the listing stops answering.
        if ($cur_['base'] === '') {
            $base = onepriceBase();
            $did++;
            $s['used'] += 4;
            $cur_['used'] += 4;
            if (!$base) {
                stateSave($s);
                echo "oneprice: listing tak nahi pohanch saka\n";
                break;
            }
            $cur_['base']     = $base;
            $cur_['page']     = max(1, (int) $cur_['page']);
            if ($cur_['passFrom'] === '') {
                $cur_['passFrom'] = date('Y-m-d H:i:s');
            }
            stateSave($s);
            usleep((int) (REQ_GAP * 1000000));
            continue;
        }

        list($html, $err) = fetchOneprice($cur_['base'], $cur_['page']);
        $did++;
        $s['used']++;
        $cur_['used']++;

        if ($err === 'refused') {
            $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
            stateSave($s);
            echo "RUKA: source refused. Sab band.\n";
            exit;
        }
        if ($html === null) {
            // A session that has expired reads as a failure. Drop the address so
            // the next turn walks the wizard again rather than asking a listing
            // that is no longer ours.
            $cur_['base'] = '';
            stateSave($s);
            echo "read fail ({$err}) oneprice p{$cur_['page']}\n";
            break;
        }

        $cars = parseOnepriceListing($html);
        if ($cars) {
            $seen  += count($cars);
            $wrote += storeCars($conn, $cars, 'oneprice_mix');
        }

        $total = declaredTotal($html);
        $last  = ($total > 0) ? (int) ceil($total / PER_PAGE) : 0;

        // Finished only at the declared last page - see the auction's note; an
        // empty page is a failed read, not the end of the listing.
        if ($last > 0 && $cur_['page'] >= $last) {
            // The listing has been read end to end. Everything the source still
            // lists has just been written, so anything of ours still carrying a
            // stamp from before the pass began has gone from the source.
            if ($cur_['passFrom'] !== '') {
                // Two passes, not one - the same shifting listing, the same
                // reason. See the auction's note above.
                $judge = (string) ($cur_['passPrev'] ?? '');
                if ($judge !== '') {
                    $gone = retireUnseen($conn, 'oneprice_mix', $judge);
                    if ($gone) {
                        $retired += $gone;
                    }
                }
            }
            $cur_['page']     = 1;
            $cur_['passPrev'] = $cur_['passFrom'];
            $cur_['passFrom'] = date('Y-m-d H:i:s');
            $cur_['base']     = '';        // a fresh session for the next pass
            $cur_['passes']++;
        } else {
            $cur_['page']++;
        }
        stateSave($s);
        usleep((int) (REQ_GAP * 1000000));
        continue;
    }

    /* ---- the auction never gets past here.

       Below this line is the lot-number survey and the walk it drives, and both
       read `/auction/search?lots=N` - the lot search. That is not the listing.
       It answers from a wider set: on 7 September MIRIVE Aichi listed 322
       vehicles for the 11th and the lot search returned 342, the extra twenty
       being vehicles the listing had dropped.

       The auction section reaches this code whenever its own loop above stops -
       on the run's clock, on the request budget, on any failed read - and once
       here it stored what the lot search gave it, with no day guard and no
       check against the listing at all. So the hall reconciliation would take a
       vehicle off because the listing does not carry it, and minutes later this
       would put it back because the lot search does. The portal sat oscillating
       around the source instead of settling on it, and climbed above it every
       time this ran.

       The rule is written down in specs/005-portal-website-sync: only the
       listing a customer sees may say a vehicle is there - for adding and for
       removing. This is the last place that broke it.

       oneprice is different and stays: its own browse view is what it walks
       above, and the survey below is how its blocks are counted. */
    if ($cur === 'auction') {
        stateSave($s);
        echo "auction: lot search ka rasta band hai - sirf listing se aati hai\n";
        break;
    }

    /* ---------------------------------------------------------- the survey
       Walking lot 1 to 100,000 in order spends most of its requests re-reading
       ranges that are already complete, and only finds what is missing when it
       happens to arrive there - days later.

       A block of a thousand lots states its own total in one request, and that
       total costs the same as a block of forty. So: ask each block what it
       holds, compare against what we hold, and go straight to the blocks that
       are short. A hundred requests survey a whole section - two and a half
       minutes - and the answer is a work list in order of need.

       The same survey is what keeps the portal current afterwards. A block
       whose count still matches has nothing new in it, and is not read at all. */
    if ($cur_['scanAt'] === '' || $cur_['scanNext'] >= 0) {
        $from = ($cur_['scanNext'] < 0 ? 0 : $cur_['scanNext']) * SCAN_BLOCK + 1;
        if ($from > LOT_MAX) {
            $cur_['scanNext'] = -1;
            $cur_['scanAt'] = date('Y-m-d H:i:s');

            // The map is fresh, so every block that is carrying more than the
            // source says can be brought down to it right now, without waiting
            // for the pass to walk there. Costs no requests - the survey has
            // already asked the question.
            $trimmed = trimToSource($conn, $cfg['section'], $cur_['blocks']);
            if ($trimmed) {
                $retired += $trimmed;
            }
            // What the source was holding when this map was drawn. If it still
            // holds that, the map is still right and repeating it would be a
            // hundred requests spent confirming nothing changed.
            $cur_['scanTotal'] = sourceTotal($cur);
            stateSave($s);
            continue;
        }

        list($html, $err) = fetchPage($cfg['url'], $from, 1, SCAN_BLOCK);
        $did++;
        $s['used']++;
        $cur_['used']++;
        if ($err === 'refused') {
            $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
            stateSave($s);
            echo "RUKA: source refused. Sab band.\n";
            exit;
        }
        if ($html === null) {
            stateSave($s);
            echo "scan fail ({$err}) {$cur} block {$from}\n";
            break;
        }
        $cur_['blocks'][(string) $from] = max(0, declaredTotal($html));
        $cur_['scanNext'] = ($cur_['scanNext'] < 0 ? 0 : $cur_['scanNext']) + 1;
        stateSave($s);
        usleep((int) (REQ_GAP * 1000000));
        continue;
    }

    /* Survey both sections before reading either.
       A survey is a hundred requests and tells us where the work is. Filling
       one section the moment its own survey lands means the other's shortfall
       stays invisible for hours - which is exactly what happened: auction was
       being filled while nobody had yet asked oneprice what it was holding. */
    $other = ($cur === 'oneprice') ? 'auction' : 'oneprice';
    if ($cur_['scanNext'] < 0 && $s[$other]['scanAt'] === '') {
        $s['cur'] = $other;
        stateSave($s);
        continue;
    }

    /* Survey again when the survey is old, even with work outstanding.
       It only re-surveyed once nothing was left to fill, and with a backlog
       that moment never came: the map sat five hours old while the source went
       from 40,789 vehicles to 77,243. Every one of those new sale days was
       invisible - the sweep was working diligently through yesterday's
       shortfall and could not see today's.

       Between blocks, never mid-block, so a half-read block is not abandoned. */
    if ($cur_['fillTo'] <= 0 && $cur_['scanNext'] < 0 && $cur_['scanAt'] !== ''
        && strtotime($cur_['scanAt']) < time() - rescanAfter($cur)) {

        // Only when the source has actually moved. A survey is a hundred
        // requests; repeating it every half hour regardless would spend six
        // thousand a day on surveying alone, out of ten - most of the budget
        // asking a question whose answer had not changed.
        //
        // The pulse already reads the source's own totals every ten minutes
        // for two requests. If the total is what it was when this map was
        // drawn, the map is still right however old it looks.
        $srcNow = sourceTotal($cur);
        $atScan = isset($cur_['scanTotal']) ? (int) $cur_['scanTotal'] : -1;

        if ($srcNow < 0 || $atScan < 0 || $srcNow !== $atScan) {
            $cur_['scanNext'] = 0;
            stateSave($s);
            continue;
        }
        // unchanged: leave the map alone and note that it was checked
        $cur_['scanAt'] = date('Y-m-d H:i:s');
        stateSave($s);
    }

    /* ------------------------------------------------- pick where to work */
    if ($cur_['fillTo'] <= 0) {
        list($need, $walkNext) = nextShortBlock(
            $conn, $cfg['section'], $cur_['blocks'], $cur_['walk'] ?? 0);

        if ($need === null && ($cur_['walk'] ?? 0) > 0) {
            // The pass reached the end of the lot numbers.
            //
            // So everything the source still lists has been read and stamped
            // during it, and whatever is left carrying an older stamp is gone
            // from the source. This is the moment - and the only moment - that
            // can be known, so it is taken here rather than guessed at
            // block by block.
            if (!empty($cur_['passFrom'])) {
                $gone = retireUnseen($conn, $cfg['section'], $cur_['passFrom']);
                if ($gone) {
                    $retired += $gone;
                }
            }
            $cur_['walk']     = 0;
            $cur_['passFrom'] = date('Y-m-d H:i:s');
            $cur_['passes']++;
            stateSave($s);
            continue;
        }
        // First pass since the state was made: mark where it began.
        if (empty($cur_['passFrom'])) {
            $cur_['passFrom'] = date('Y-m-d H:i:s');
        }
        if ($need === null) {
            // This section matches the source everywhere. Survey it again once
            // it is stale; until then give the other section the turn, and if
            // both are level stop and leave the budget for when something
            // actually arrives.
            $stale = strtotime($cur_['scanAt']) < time() - rescanAfter($cur);
            if ($stale) {
                $cur_['scanNext'] = 0;
                stateSave($s);
                continue;
            }
            $other = ($cur === 'oneprice') ? 'auction' : 'oneprice';
            $otherStale = $s[$other]['scanAt'] === ''
                || strtotime($s[$other]['scanAt']) < time() - rescanAfter($other);
            $otherNeeds = neediestBlock($conn, $secs[$other]['section'], $s[$other]['blocks']) !== null;
            if (!$otherStale && !$otherNeeds) {
                stateSave($s);
                echo "dono section website ke barabar - kuch karne ko nahi\n";
                break;
            }
            $s['cur'] = $other;
            stateSave($s);
            continue;
        }
        $cur_['lot']     = $need;
        $cur_['page']    = 1;
        $cur_['fillTo']  = $need + SCAN_BLOCK - 1;
        $cur_['walk']    = $walkNext;
        $cur_['seenIds'] = array();
        stateSave($s);
    }

    if ($cur_['lot'] > $cur_['fillTo'] || $cur_['lot'] > LOT_MAX) {
        // block finished; on to the next one that is not level
        $cur_['fillTo'] = 0;
        stateSave($s);
        continue;
    }

    // Forty lots at a time, every page of them.
    //
    // Asking only for the lot numbers we hold nothing for was tried, and it is
    // genuinely cheaper per vehicle - but it cannot reach a vehicle sitting
    // under a lot number we already hold in another hall, and it retires
    // nothing, so a section could never actually arrive. Reading the block out
    // does both, and is the only thing that ends a pass.
    list($html, $err) = fetchPage($cfg['url'], $cur_['lot'], $cur_['page']);
    $did++;
    $s['used']++;
    $cur_['used']++;

    if ($err === 'refused') {
        $s['halted'] = 'source ne mana kar diya (403) - ' . date('Y-m-d H:i');
        stateSave($s);
        echo "RUKA: source refused. Sab band.\n";
        exit;
    }
    if ($html === null) {
        // A failed read is not an empty range. Leave the cursor where it is so
        // the next run asks for the same page again.
        stateSave($s);
        echo "read fail ({$err}) {$cur} lot {$cur_['lot']} page {$cur_['page']}\n";
        break;
    }

    $cars = call_user_func($cfg['parse'], $html);
    $total = declaredTotal($html);

    if ($cars) {
        $seen += count($cars);
        $wrote += storeCars($conn, $cars, $cfg['section']);
    }

    // Everything the source has shown us for the window of lot numbers in hand.
    // A window can run to several pages, so this accumulates until the last one
    // - retiring after page 1 would retire everything on page 2.
    if (!isset($cur_['seenIds']) || !is_array($cur_['seenIds'])) {
        $cur_['seenIds'] = array();
    }
    foreach ($cars as $c) {
        $cid = jpaucCarId($c);
        if ($cid !== null) { $cur_['seenIds'][] = $cid; }
    }

    // Where the page states its own total, the last page is arithmetic rather
    // than a guess.
    $lastPage = ($total >= 0)
        ? max(1, (int) ceil($total / PER_PAGE))
        : null;

    $windowDone = ($lastPage !== null)
        ? ($cur_['page'] >= $lastPage || $total === 0)
        : (count($cars) < PER_PAGE);

    if ($windowDone) {
        // The window has been read out. Whatever we hold in this range that the
        // source did not show us has gone - sold, or withdrawn - and should
        // leave the site.
        //
        // Only when the page declared its own total. Without that number we are
        // guessing at where the listing ends, and a guess is not grounds for
        // taking a car off the portal.
        //
        // And the page saying it holds rows while we read none of them is a
        // parsing failure, not an empty range; acting on that would take live
        // cars off the portal. Leave the window; the pass comes round again.
        $parseFailed = ($total > 0 && !$cars);
        if ($total >= 0 && !$parseFailed) {
            $gone = retireVanished($conn, $cfg['section'],
                                   $cur_['lot'], $cur_['lot'] + LOT_BATCH - 1,
                                   array_unique($cur_['seenIds']));
            if ($gone) {
                $retired += $gone;
            }
        }
        $cur_['seenIds'] = array();
        $cur_['lot'] += LOT_BATCH;
        $cur_['page'] = 1;
    } else {
        $cur_['page']++;
    }

    stateSave($s);
    unset($cur_);
    usleep((int) (REQ_GAP * 1000000));
}

$s['runs']++;
$s['wrote'] += $wrote;
$s['seen'] += $seen;
stateSave($s);

$counts = array();
// Live lots only, so this line can be compared straight against the source.
// Counting last week's auctions here is how "we hold 91,290 against their
// 90,641" got read as level when the live dates were 28,598 short.
$q = $conn->query("SELECT source_section s, COUNT(*) n FROM cars
                   WHERE source_section IN ('japan','oneprice_mix')
                     AND (source_section <> 'japan' OR auction_on >= CURDATE())
                     AND (status IS NULL OR status <> 'removed')
                   GROUP BY s");
while ($q && $w = $q->fetch_assoc()) {
    $counts[$w['s']] = (int) $w['n'];
}

printf("section  %s\n", $s['cur']);
printf("requests %d  |  padhi %d  |  likhi %d\n", $did, $seen, $wrote);
foreach (array('auction' => 'japan', 'oneprice' => 'oneprice_mix') as $k => $tbl) {
    $st = $s[$k];
    $blocks = count($st['blocks']);
    $src = array_sum($st['blocks']);
    // The auction reports by day, because that is how it is worked: five
    // numbers against five numbers says exactly where it stands and what is
    // left, where a block cursor said nothing you could act on.
    if ($k === 'auction') {
        printf("auction   DB %s   |  listing page %s   |  %d chakkar pooray\n",
            number_format($counts[$tbl] ?? 0), number_format((int) $st['page']), $st['passes']);
        continue;
    }
    if (false) {
        $held = heldPerDay($conn);
        $short = 0;
        $lines = array();
        foreach ($st['days'] as $d => $n) {
            $mine = isset($held[$d]) ? $held[$d] : 0;
            // The counted figure where the day has been read, the front page's
            // guess with a ? where it has not - the two are far apart and saying
            // which is which is the difference between a report and a rumour.
            $real = isset($st['real'][$d]) ? (int) $st['real'][$d] : 0;
            $target = $real ?: (int) $n;
            $short += max(0, $target - $mine);
            $lines[] = sprintf('%s %s/%s%s', substr($d, 5),
                number_format($mine), number_format($target), $real ? '' : '?');
        }
        printf("auction   %s\n", implode('   ', $lines));
        printf("          kami %s gaadi  =  ~%s request%s\n",
            number_format($short), number_format((int) ceil($short / PER_PAGE)),
            $st['day'] !== '' ? '   |  abhi ' . substr($st['day'], 5) : '');
        continue;
    }

    if ($k === 'oneprice') {
        $pg   = (int) $st['page'];
        $pass = $st['passes'];
        printf("oneprice  DB %s   |  listing page %s   |  %d chakkar pooray
",
            number_format($counts[$tbl] ?? 0), number_format($pg), $pass);
        continue;
    }

    $gap  = neediestBlock($conn, $tbl, $st['blocks']);
    $walk = (int) ($st['walk'] ?? 0);
    printf("%-9s survey %s%s  |  DB %d / source %d  |  %s\n",
        $k,
        $st['scanNext'] >= 0 ? 'chal rahi (' . $st['scanNext'] . '/100)' : 'poori',
        $st['scanAt'] !== '' ? ' @ ' . substr($st['scanAt'], 11, 5) : '',
        $counts[$tbl] ?? 0,
        $src,
        $gap === null
            ? 'barabar (' . $st['passes'] . ' chakkar)'
            : sprintf('chakkar %d%%  |  lot %d p%d  |  %d pooray',
                      $blocks ? (int) round($walk * 100 / $blocks) : 0,
                      $st['lot'], $st['page'], $st['passes'])
    );
}
printf("mitayi    %d purani rows (DB se nikal gayin)
", $purged);
printf("hataayi   %d gaadi (source par nahi rahi)
", $retired);
printf("aaj      %d / %d requests, %d gaadi likhi\n", $s['used'], DAILY_BUDGET, $s['wrote']);
printf("hissa    auction %d / %d, oneprice %d / %d
",
    $s['auction']['used'], sectionShare('auction'),
    $s['oneprice']['used'], sectionShare('oneprice'));
