<?php
/**
 * SBK Auction Portal - Core Functions
 */

// ============================================================================
// AUTHENTICATION FUNCTIONS
// ============================================================================

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['client_id']) && !empty($_SESSION['client_id']);
}

/**
 * Is someone signed in through the staff door? (administrator or agent)
 * @return bool
 */
function isAdmin() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

// ============================================================================
// STAFF ROLES AND PERMISSIONS
// ============================================================================

/**
 * Every permission the admin can tick on or off, grouped for the Roles screen.
 *
 * The keys live here rather than in the database because the code that enforces
 * them lives here too — they must be versioned together. Only the grants (which
 * role has which key) are stored.
 *
 * @return array group label => [ key => what it lets the holder do ]
 */
function permissionCatalogue() {
    return array(
        'Auctions' => array(
            'portal.view'        => 'Browse the customer-facing auction pages',
            'lots.view_sold'     => 'See sold lots and past auctions (customers never do)',
            'vehicles.view'      => 'Open the Vehicles screen in the panel',
        ),
        'Bids' => array(
            'bids.view'          => 'See customer bids',
            'bids.status'        => 'Change a bid\'s status',
            'bids.note'          => 'Leave a note on a bid',
            'bids.view_all'      => 'See EVERYONE\'s bids, not only their own',
            'bids.export'        => 'Download accepted bids as a spreadsheet',
            'bids.delete'        => 'Remove a bid for good',
        ),
        /* There is no "Live chat" group here any more, on purpose. The chat
           belongs to the WordPress website now (the owner's instruction,
           2026-09-10): who may answer is a WordPress role, and who may read
           every conversation is a WordPress administrator. A tick box on THIS
           screen would decide nothing at all — worse than no box, because it
           looks as if it does. */
        'Enquiries' => array(
            'enquiries.view'     => 'See customer enquiries',
            'enquiries.reply'    => 'Write a reply to an enquiry',
            'enquiries.status'   => 'Change an enquiry\'s status, including closing it',
        ),
        'Orders' => array(
            'orders.view'        => 'See orders',
            'orders.status'      => 'Change an order\'s status',
        ),
        'Customers' => array(
            'clients.view'       => 'See the customer list',
        ),
        'Staff' => array(
            'staff.view'         => 'See the staff list',
            'staff.create'       => 'Add a new staff login',
            'staff.edit'         => 'Change a person\'s role, or switch their login on and off',
            'staff.password'     => 'Set a new password for someone',
            'staff.delete'       => 'Remove a staff login for good',
        ),
        'Roles' => array(
            'roles.view'         => 'See the roles and what each may do',
            'roles.create'       => 'Add a role',
            'roles.rename'       => 'Rename a role',
            'roles.delete'       => 'Remove a role',
            'roles.permissions'  => 'Change what a role is allowed to do',
        ),
    );
}

/**
 * Keys that used to exist, and what they mean now.
 *
 * Permissions were per screen: one key let you open Bids and another let you do
 * everything on it. They are per action now - change a status, leave a note,
 * set a password - so that a role can be allowed one and not the other.
 *
 * Roles already granted the old keys keep exactly the access they had: an old
 * key still counts as every new key it was standing in for. Nothing has to be
 * re-ticked, and nobody silently loses a screen they were using this morning.
 *
 * @return array old key => list of keys it now stands for
 */
function permissionAliases() {
    return array(
        /* Nor bids.view_all. Every role that could open the Bids screen used to
           see every bid on it, so switching this on by default would have kept
           that - and the owner's instruction is the opposite: whoever places a
           bid sees their own and nobody else's, staff included, until somebody
           is deliberately given the wider view. Losing sight of other people's
           bids is the point of the change, not a casualty of it.

           Neither of the other new keys is stood in for by the old bids.manage.
           Deleting is final, and the spreadsheet carries the customer's address
           and telephone number - more than the Bids screen itself shows. The
           rule these aliases keep is that nobody silently LOSES access they were
           using; it has never been that anybody gains access they never had. An
           administrator ticks these on for a role that should have them. */
        'bids.manage'      => array('bids.status', 'bids.note'),
        'enquiries.reply'  => array('enquiries.reply', 'enquiries.status'),
        'orders.manage'    => array('orders.status'),
        // staff.delete is deliberately not here. The rule above is that nobody
        // silently loses access they were using; it is not that anybody gains
        // access they never had, and deleting a login is the one act on this
        // screen with nothing behind it. A role that held the old staff.manage
        // keeps everything it could do; an administrator ticks delete on for it.
        'staff.manage'     => array('staff.view', 'staff.create', 'staff.edit', 'staff.password'),
        'roles.manage'     => array('roles.view', 'roles.create', 'roles.rename',
                                    'roles.delete', 'roles.permissions'),
    );
}

/**
 * Flat list of every valid permission key.
 * @return array
 */
function allPermissionKeys() {
    $out = array();
    foreach (permissionCatalogue() as $keys) {
        foreach ($keys as $k => $_) {
            $out[] = $k;
        }
    }
    return $out;
}

/**
 * The signed-in staff member's row, or null.
 * @return array|null
 */
function currentStaff() {
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (!isAdmin()) {
        return $cache = null;
    }
    global $conn;
    $stmt = $conn->prepare("
        SELECT a.id, a.username, a.name, a.email, a.is_active,
               r.id AS role_id, r.name AS role, r.label AS role_label, r.is_system
        FROM admins a
        LEFT JOIN roles r ON r.id = a.role_id
        WHERE a.id = ? AND a.is_active = 1
        LIMIT 1
    ");
    $stmt->bind_param('i', $_SESSION['admin_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache = ($row ?: null);
}

/**
 * The permissions granted to a role.
 * @param int $role_id
 * @return array
 */
function rolePermissions($role_id) {
    global $conn;
    $out = array();
    $stmt = $conn->prepare("SELECT permission FROM role_permissions WHERE role_id = ?");
    $stmt->bind_param('i', $role_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[] = $row['permission'];
    }
    $stmt->close();
    return $out;
}

/**
 * Write down that a staff member did something.
 *
 * Nothing recorded who acted. The schema had an `assigned_to` column on
 * enquiries that no code ever wrote to, and bids and orders did not even have
 * that - so "which of my people answered this customer" had no answer, and the
 * Overview could show how many bids existed but not who had touched one.
 *
 * Called from the places that already decide whether an action is allowed, so
 * the log records exactly what the permission check let through - one is not
 * able to drift from the other.
 *
 * A failed write is swallowed. Losing a line of history is a smaller harm than
 * refusing a customer's reply because the diary was full.
 *
 * @param string $action what was done, e.g. 'bid.status'
 * @param string $subject what it was done to, e.g. 'BID-2026-045950'
 * @param int|null $subject_id the row it happened to
 */
function logStaffAction($action, $subject = '', $subject_id = null) {
    global $conn;
    $staff = currentStaff();
    if (!$staff) {
        return;
    }
    $st = @$conn->prepare(
        "INSERT INTO staff_activity (staff_id, action, subject, subject_id, at)
         VALUES (?, ?, ?, ?, NOW())");
    if (!$st) {
        return;
    }
    $id  = (int) $staff['id'];
    $sid = $subject_id === null ? null : (int) $subject_id;
    $st->bind_param('issi', $id, $action, $subject, $sid);
    @$st->execute();
    $st->close();
}

/**
 * May the signed-in staff member do this?
 *
 * The administrator role is deliberately absolute — it is the role that hands
 * out permissions, so it can never lock itself out of doing so.
 *
 * @param string $permission key from permissionCatalogue()
 * @return bool
 */
function staffCan($permission) {
    static $granted = null;
    $staff = currentStaff();
    if (!$staff) {
        return false;
    }
    if (($staff['role'] ?? '') === 'admin') {
        return true;
    }
    if ($granted === null) {
        $granted = expandPermissions(
            $staff['role_id'] ? rolePermissions((int) $staff['role_id']) : array()
        );
    }
    return in_array($permission, $granted, true);
}

/**
 * Whose bids may the signed-in member of staff see?
 *
 * The owner's rule, in one sentence: a bid belongs to whoever placed it, and
 * that is who sees it. The administrator sees everything, as it does with every
 * other permission; anybody else sees everything only if their role has been
 * given bids.view_all on purpose.
 *
 * Returned as an id to filter by rather than a yes/no, so every screen that
 * lists, counts, exports, edits or deletes a bid narrows the same way and none
 * of them can forget to. A staff member with no id of their own gets 0, which
 * matches no row - an empty list is the safe answer to "who are you".
 *
 * @return int|null null for everybody's bids; otherwise only bids this person placed
 */
function bidScopeStaffId() {
    if (staffCan('bids.view_all')) {
        return null;
    }
    $me = currentStaff();
    return $me ? (int) $me['id'] : 0;
}

/**
 * Turn a role's stored grants into the keys the code actually asks about.
 *
 * A role ticked before permissions were split per action holds keys that no
 * longer appear in the catalogue. Expanding them here, in one place, means the
 * rest of the code only ever reasons about keys that exist today - and means
 * this can be exercised on its own, which a session-dependent staffCan() cannot.
 *
 * @param array $granted keys as stored against the role
 * @return array keys as the catalogue names them
 */
function expandPermissions(array $granted) {
    $aliases = permissionAliases();
    foreach ($granted as $held) {
        if (isset($aliases[$held])) {
            $granted = array_merge($granted, $aliases[$held]);
        }
    }
    return array_values(array_unique($granted));
}

/**
 * Gate an admin-panel page on a permission.
 * @param string $permission
 */
function requirePermission($permission) {
    requireAdmin();
    if (!staffCan($permission)) {
        header("Location: " . SITE_URL . "admin/dashboard.php?denied=" . urlencode($permission));
        exit;
    }
}

/**
 * Gate a portal page.
 *
 * A signed-in admin passes too: staff must be able to walk the portal exactly
 * as a customer sees it without keeping a second customer account. Only a
 * visitor with neither session is sent to the customer login.
 */
function requireLogin() {
    if (isLoggedIn() || isAdmin()) {
        return;
    }
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header("Location: " . SITE_URL . "login.php");
    exit;
}

/**
 * Gate a page that only makes sense for a customer account — the dashboard,
 * bidding, enquiries. An admin browsing the portal has no client row, so it is
 * sent to its own panel rather than being handed a broken page.
 */
function requireClient() {
    if (isLoggedIn()) {
        return;
    }
    if (isAdmin()) {
        header("Location: " . SITE_URL . "admin/dashboard.php");
        exit;
    }
    requireLogin();
}

/**
 * Require admin - redirect if not admin
 */
function requireAdmin() {
    if (!isAdmin()) {
        header("Location: " . SITE_URL . "admin/login.php");
        exit;
    }
}

/**
 * Get current logged-in client data
 * @return array|null
 */
function getClient() {
    if (!isLoggedIn()) {
        return null;
    }

    global $conn;
    $client_id = intval($_SESSION['client_id']);

    $stmt = $conn->prepare("SELECT id, name, email, phone, address, created_at FROM clients WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();
    $stmt->close();

    return $client;
}

/**
 * Login client
 * @param string $email
 * @param string $password
 * @return bool
 */
function loginClient($email, $password) {
    global $conn;

    $email = trim($email);
    $stmt = $conn->prepare("SELECT id, name, password_hash FROM clients WHERE email = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        return false;
    }

    $client = $result->fetch_assoc();
    $stmt->close();

    // Verify password
    if (!password_verify($password, $client['password_hash'])) {
        return false;
    }

    // Set session
    $_SESSION['client_id'] = $client['id'];
    $_SESSION['client_name'] = $client['name'];
    $_SESSION['client_email'] = $email;

    // Update last login
    $stmt = $conn->prepare("UPDATE clients SET updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $client['id']);
    $stmt->execute();
    $stmt->close();

    return true;
}

/**
 * Register new client
 * @param array $data
 * @return array
 */
function registerClient($data) {
    global $conn;

    $errors = array();

    // Validate
    if (empty($data['name'])) $errors[] = "Name is required";
    if (empty($data['email'])) $errors[] = "Email is required";
    if (empty($data['phone'])) $errors[] = "Phone is required";
    if (empty($data['password'])) $errors[] = "Password is required";
    if (strlen($data['password']) < 6) $errors[] = "Password must be at least 6 characters";
    if ($data['password'] !== $data['password_confirm']) $errors[] = "Passwords do not match";

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }

    // Check if email exists
    $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $data['email']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "Email already registered";
    }
    $stmt->close();

    if (!empty($errors)) {
        return array('success' => false, 'errors' => $errors);
    }

    // Hash password
    $password_hash = password_hash($data['password'], PASSWORD_BCRYPT);

    // Insert client
    $stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, password_hash, is_active)
        VALUES (?, ?, ?, ?, 1)
    ");
    $stmt->bind_param("ssss", $data['name'], $data['email'], $data['phone'], $password_hash);

    if (!$stmt->execute()) {
        return array('success' => false, 'errors' => array("Registration failed. Please try again."));
    }

    $client_id = $stmt->insert_id;
    $stmt->close();

    // Auto-login
    $_SESSION['client_id'] = $client_id;
    $_SESSION['client_name'] = $data['name'];
    $_SESSION['client_email'] = $data['email'];

    return array('success' => true, 'client_id' => $client_id);
}

/**
 * Logout client
 */
function logoutClient() {
    session_destroy();
    header("Location: " . SITE_URL);
    exit;
}

// ============================================================================
// CAR FUNCTIONS
// ============================================================================

/**
 * Get all active cars with pagination and filters
 * @param int $page
 * @param int $per_page
 * @param array $filters
 * @return array
 */
/**
 * Sort orders the list columns can request. Whitelisted so a column header can
 * never inject SQL. Lots with no price or mileage sort last rather than first,
 * which is what a buyer expects.
 */
/**
 * Is this email address already on another staff login?
 *
 * The address is a way in - the sign-in accepts it as well as the username - so
 * it has to name one account and no more. It stayed optional and unchecked for
 * a long time, which is why the sign-in only trusts an address that turns up
 * once.
 *
 * @param mysqli   $conn
 * @param string   $email
 * @param int|null $exceptId the account being edited, which may keep its own
 * @return bool
 */
function staffEmailTaken($conn, $email, $exceptId = null) {
    $email = trim((string) $email);
    if ($email === '') {
        return false;
    }
    $sql = "SELECT id FROM admins WHERE email = ?";
    if ($exceptId !== null) {
        $sql .= " AND id <> " . (int) $exceptId;
    }
    $st = $conn->prepare($sql . " LIMIT 1");
    if (!$st) {
        return false;
    }
    $st->bind_param('s', $email);
    $st->execute();
    $hit = (bool) $st->get_result()->fetch_row();
    $st->close();
    return $hit;
}

function carSortOptions() {
    /* The default order: what a buyer can still bid on, soonest first.

       It used to be `last_updated DESC` - whichever vehicles the harvester had
       most recently written. That is not an order at all, it is an accident of
       the sync, and it had two consequences the owner reported as broken
       images.

       The harvester spends much of its time re-checking the day's concluded
       sales, so those were the rows it had touched last, so those filled the
       front page - twenty of twenty, every one already sold and unbiddable. And
       the source withdraws a vehicle's photographs once its sale has run,
       keeping only a small preview, so the front page was also the one page
       where almost nothing had more than a single picture. Measured: 20 of 20
       rows Closed, 38 image requests 404, one photograph each. Ordered this way
       instead, the first twelve rows are all still to be sold and all twelve
       carry the full three.

       It was also unstable. Opening a lot and pressing back re-ran the query
       and returned a different set of vehicles, which is what "the images break
       when I come back" was: not the pictures failing, the list underneath them
       changing. A sale time does not move, so this order does not either.

       The moment is assembled rather than stored: the feed gives the day as a
       date and the time as Japan's clock in a separate column. Japan's "now" is
       worked out in PHP because CONVERT_TZ needs timezone tables this host does
       not carry; it is a formatted timestamp, never anything a visitor typed. */
    $jstNow = date_create('now', timezone_open('Asia/Tokyo'))->format('Y-m-d H:i:s');
    $moment = "CONCAT(c.auction_on, ' ', "
            . "COALESCE(NULLIF(TRIM(BOTH ']' FROM TRIM(BOTH '[' FROM c.auction_time)), ''), '09:00:00'))";
    $unsold = "($moment >= '" . $jstNow . "')";

    return array(
        // still to be sold, soonest first; then the concluded, latest first
        'newest'       => "$unsold DESC, CASE WHEN $unsold THEN $moment END ASC, $moment DESC",
        'lot_asc'      => 'CAST(c.lot_no AS UNSIGNED) ASC',
        'lot_desc'     => 'CAST(c.lot_no AS UNSIGNED) DESC',
        'date_asc'     => "STR_TO_DATE(c.auction_date,'%d.%m.%Y') ASC",
        'date_desc'    => "STR_TO_DATE(c.auction_date,'%d.%m.%Y') DESC",
        'year_new'     => 'c.year DESC',
        'year_old'     => 'c.year ASC',
        'cc_low'       => 'c.engine_cc = 0, c.engine_cc ASC',
        'cc_high'      => 'c.engine_cc DESC',
        'mileage'      => 'c.mileage = 0, c.mileage ASC',
        'mileage_high' => 'c.mileage DESC',
        'cond_high'    => 'CAST(c.rating AS DECIMAL(3,1)) DESC',
        'cond_low'     => 'CAST(c.rating AS DECIMAL(3,1)) = 0, CAST(c.rating AS DECIMAL(3,1)) ASC',
        'price_low'    => 'c.price = 0, c.price ASC',
        'price_high'   => 'c.price DESC',
        'sold_low'     => 'c.sold_price = 0, c.sold_price ASC',
        'sold_high'    => 'c.sold_price DESC',
    );
}

/**
 * The multi-select filters, mapped to their column.
 * Keeping this in one place is what lets the facet panel and the result query
 * stay in step — add a filter here and both follow.
 */
function facetColumns() {
    $cols = array(
        // The reference portal leads with these two, and rightly: a buyer picks
        // a sale day and a hall before anything else, because that is what they
        // can actually attend.
        'auction_on'   => 'auction_on',
        'auction'      => 'auction',
        'chassis'      => 'chassis',
        'rating'       => 'rating',
        'color'        => 'color',
        'status'       => 'status',
        'transmission' => 'transmission',
    );
    // Everyone keeps the status filter: the list now carries concluded lots
    // alongside live ones, exactly as the source site does, so a customer needs
    // to be able to narrow to the ones still open for bidding.
    return $cols;
}

/**
 * May this viewer open a lot that is no longer for sale?
 *
 * Only that - one lot, reached by its own link. It used to widen the listing
 * too, and the portal then answered a signed-in administrator with 93,822
 * auction lots where a customer got 51,438: the same page disagreeing with
 * itself depending on who was looking, so nobody could check the site against
 * the source without first asking whose eyes they were using.
 *
 * The lists, the counts, the make and hall facets and the range sliders are all
 * on one scope now, for everybody. This is left for the case it was always
 * good for: a staff member following a link to a sold lot should read it rather
 * than be bounced to the front page. Letting one lot through distorts no count.
 *
 * @return bool
 */
function viewerSeesPastLots() {
    return staffCan('lots.view_sold');
}

/**
 * SQL for "this lot is still to be auctioned".
 *
 * `auction_on` is a persistent generated column holding the feed's dd.mm.yyyy
 * string as a real DATE, so this is index-backed rather than a scan.
 *
 * @param string $alias table alias in the surrounding query
 * @return string
 */
/**
 * SQL for "this vehicle can still be bought".
 *
 * Named statuses were excluded one at a time - NOT IN ('removed','cancelled') -
 * and the list never matched the data. The feed writes 'cancel', 'cancel -' and
 * 'removed -'; none of those three spellings is in the list, so 153 rows were
 * being shown as if for sale. 'sold' was never in the list at all, and 6,373
 * vehicles that had already gone under the hammer were sitting in the auction
 * list with their result beside them.
 *
 * So the test is turned around. Rather than name what to hide - a list that can
 * only ever be as complete as the last spelling somebody noticed - name what to
 * show. A vehicle is for sale when the feed says it is available and not
 * otherwise; everything else, whatever it is called, is not for sale.
 *
 * @param string $alias table alias in the surrounding query
 * @return string
 */
function sellableSql($alias = 'c') {
    return "($alias.status IS NULL OR $alias.status LIKE 'available%')";
}

function currentLotsSql($alias = 'c') {
    // Auction lots only. Fixed-price stock used to share this table; it is gone
    // from the portal (13 September 2026) and this still names the section so
    // no such row could ever reach the auction list.
    //
    // A lot that has gone under the hammer used to stay in this list, showing
    // its result instead of a bid box, on the reasoning that our listing should
    // read the same as the source's. The client's instruction is the opposite
    // and is the one that counts: only what can still be bought belongs here.
    // 6,373 sold vehicles were being shown when that was measured.
    //
    // The cutoff was a day looser than this, to cover our clock running behind
    // Japan's. A day was too much: the source drops yesterday's lots around
    // midday Japan time, and we went on showing them until the following
    // midnight. On 19 August that was 28,228 cars in the list that nobody could
    // buy - and it hid how far behind we were, because the dead rows made the
    // total look level with the source when the live dates were 28,598 short.
    //
    // CURDATE() matches what the source does to within a few hours. Our clock
    // is nine hours behind Japan's, so we let go of a day about three hours
    // before the source does; showing a lot that has gone is the worse of the
    // two errors, because it is the one a customer can act on.
    //
    // Nothing is deleted - these rows stay for the archive screen that is still
    // to be built. They are only kept out of the auction list.
    return "$alias.source_section = 'japan'"
         . " AND " . sellableSql($alias)
         . " AND $alias.auction_on >= CURDATE()";
}

/**
 * Can this lot still be bid on? Narrower than currentLotsSql(): the list shows
 * concluded lots so it matches the source, but only a lot still awaiting its
 * auction may be bid on.
 *
 * @param array $car
 * @return bool
 */
function lotIsBiddable($car) {
    /* A lot takes a bid while it still has no result and its sale day has not
       ended in Japan. Since 11 September 2026 that is the WHOLE sale day, on
       the owner's instruction that bids be open everywhere — see
       lotBiddingClosed() for what that traded away, and lotSaleMayHaveRun()
       for the warning the page shows instead of refusing. */
    return strtolower(trim((string) ($car['status'] ?? ''))) === 'available'
        && !lotBiddingClosed($car);
}

/**
 * The feed's dd.mm.yyyy as the short form the source site shows: "08-Aug".
 *
 * @param string|null $d
 * @return string
 */
function shortAuctionDate($d) {
    $t = DateTime::createFromFormat('d.m.Y', trim((string) $d));
    return $t ? $t->format('d-M') : (trim((string) $d) ?: '—');
}

/**
 * The sale day with the year it is actually in.
 *
 * The detail page's headline read `08-Sep, 2012` - the auction day from one
 * column and the VEHICLE's year from another, run together with a comma. Every
 * reader takes that as the eighth of September 2012. The sale is in 2026, and
 * the car's year already has a row of its own three lines below.
 *
 * @param string $d the feed's dd.mm.yyyy
 * @return string  e.g. `08-Sep, 2026`
 */
function longAuctionDate($d) {
    $t = DateTime::createFromFormat('d.m.Y', trim((string) $d));
    return $t ? $t->format('d-M, Y') : (trim((string) $d) ?: '—');
}

/**
 * When this lot goes under the hammer, as a unix timestamp, or null if the feed
 * gave no usable day.
 *
 * The feed states the day and time in Japan's clock and never says so, and our
 * server runs on neither Japan's zone nor the buyer's — so the moment is built
 * explicitly in Asia/Tokyo. The page then counts down from it in the buyer's
 * own local time, which is what a bidder actually needs to see.
 *
 * @param array $car
 * @return int|null
 */
function auctionEndsAt($car) {
    $day = trim((string) ($car['auction_date'] ?? ''));
    if ($day === '') {
        return null;
    }
    /* The feed writes the time three ways - `15:43`, `15:43:00`, and inside
       square brackets - and this accepted only the first. Every lot carrying
       seconds therefore fell through to nine in the morning, so a car selling
       at four in the afternoon was shown as closed from nine, and the
       countdown beside it counted to the wrong moment. It read `Auction Time
       15:43:00  Closed` at two in the afternoon, which is how it was found. */
    $time = trim((string) ($car['auction_time'] ?? ''), " \t[]");
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)
        && !((int) $m[1] === 0 && (int) $m[2] === 0)) {
        $time = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    } else {
        // No hall sells at midnight. `00:00` is the feed saying it has not
        // published a time, not a time; the halls open in the morning.
        $time = '09:00';
    }
    $t = DateTime::createFromFormat('d.m.Y H:i', $day . ' ' . $time,
                                    new DateTimeZone('Asia/Tokyo'));
    return $t ? $t->getTimestamp() : null;
}

/**
 * The lots either side of this one, so a buyer can page through a make without
 * going back to the list.
 *
 * Ordered by lot number, which is how a hall lists them and how a buyer reads
 * them — id order would jump about, since ids follow whatever order the sync
 * happened to import in.
 *
 * @param array $car
 * @return array{prev:?int,next:?int}
 */
function getLotNeighbours($car) {
    global $conn;

    $make = (string) ($car['make'] ?? '');
    $lot  = (string) ($car['lot_no'] ?? '');
    if ($make === '' || $lot === '') {
        return array('prev' => null, 'next' => null);
    }
    $scope = currentLotsSql('c') . ' AND c.make = ?';

    $out = array('prev' => null, 'next' => null);
    foreach (array('prev' => array('<', 'DESC'), 'next' => array('>', 'ASC')) as $k => $d) {
        $sql = "SELECT c.id FROM cars c WHERE $scope AND c.lot_no {$d[0]} ? "
             . "ORDER BY c.lot_no {$d[1]} LIMIT 1";
        $st = $conn->prepare($sql);
        if (!$st) {
            continue;
        }
        $st->bind_param('ss', $make, $lot);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $out[$k] = (int) $row['id'];
        }
    }
    return $out;
}

/**
 * Every picture JPAuc holds for a lot, worked out rather than fetched.
 *
 * The listing hands back one photograph per vehicle. Its address, though, is
 * built from the sale day, the auction house and the lot - and ends in
 * "number=1". Asking for number=2, 3 and so on returns the rest of the set:
 * twelve on a typical lot, the last of them the inspection sheet. They were
 * always there; the listing simply only linked the first.
 *
 * So the gallery costs nothing. No page is read, no request is spent - the
 * addresses are derived from the one we already stored, and the buyer's own
 * browser fetches them straight from the picture host.
 *
 * Any that do not exist come back empty and are hidden by the page rather than
 * probed for here, which would cost a request per picture per lot.
 *
 * @param array $car
 * @param int   $max how many to offer
 * @return array
 */
function jpaucImageSet($car, $max = 12) {
    $stored = $car['images'] ?? '';
    if (is_string($stored)) {
        $stored = json_decode($stored, true) ?: array();
    }
    $first = $stored[0] ?? '';
    if (!is_string($first) || strpos($first, 'aleado.com') === false) {
        return array();      // not a JPAuc lot
    }
    $q = parse_url($first, PHP_URL_QUERY);
    parse_str((string) $q, $p);
    if (empty($p['date']) || !isset($p['auct']) || !isset($p['bid'])) {
        return array();
    }
    $url = function ($n, $h) use ($p) {
        return 'https://p3.aleado.com/pic/?system=auto'
             . '&date=' . rawurlencode($p['date'])
             . '&auct=' . rawurlencode($p['auct'])
             . '&bid='  . rawurlencode($p['bid'])
             . '&number=' . $n . '&h=' . $h;
    };
    $out = array();
    for ($n = 1; $n <= $max; $n++) {
        $out[] = $url($n, 640);
    }
    return $out;
}

/* ----------------------------------------------------- USS photographs, via PB

   The picture host gives USS halls - Nagoya, HAA Kobe, Kyushu, Osaka and the
   rest, about four lots in ten - nothing but a 100x75 preview at the usual
   address: the full photographs are USS members' only. Pacific Boeki fetches
   them on request (`/api/v1/auction/lot/images` - "processing", then a list),
   and the list it returns is public: the auction sheet first (1000x1000), then
   the photographs (1024x768). jpauc never had them either.

   api/pb-photos.php asks for a lot's set the first time somebody opens that car
   and keeps the answer here, outside the web root; every later view, the zip and
   the PDF use the kept copy and ask nobody. */

/** Pacific Boeki's own id for a lot we hold from it, from its source link. */
function pbLotId($car) {
    return preg_match('~pacificboeki\.jp/auction#(\d+)$~', (string) ($car['source_url'] ?? ''), $m) ? $m[1] : '';
}

/** A USS lot whose photographs Pacific Boeki can fetch. */
function isUssLot($car) {
    return stripos(trim((string) ($car['auction'] ?? '')), 'USS') === 0 && pbLotId($car) !== '';
}

function ussPhotoDir() {
    return dirname(__DIR__, 2) . '/pb-harvest/photos';
}

/**
 * The kept USS set for a lot: array('sheet' => url|null, 'photos' => urls), or
 * null when nobody has opened the car yet (or the copy is over a week old).
 */
function ussPhotoSet($car) {
    if (!isUssLot($car)) {
        return null;
    }
    $f = ussPhotoDir() . '/' . pbLotId($car) . '.json';
    if (!is_file($f) || filemtime($f) < time() - 7 * 86400) {
        return null;
    }
    $j = json_decode((string) @file_get_contents($f), true);
    return (is_array($j) && !empty($j['photos'])) ? $j : null;
}

/**
 * The auction sheet for a JPAuc lot.
 *
 * It sits at number=0, before the photographs rather than after them - which is
 * why looking for it at the end of the set found only another picture of a
 * bumper. It is square where a photograph is four by three (800x800 against
 * 1024x768), which is how it was finally told apart.
 *
 * Returned whether or not the lot has one; a lot without answers with the
 * host's small placeholder tile, and the page drops it on sight.
 *
 * @param array $car
 * @return string|null
 */
function jpaucSheetUrl($car) {
    $set = jpaucImageSet($car, 1);
    if (!$set) {
        return null;
    }
    return preg_replace('/number=\d+/', 'number=0', $set[0]);
}

/**
 * The same picture at full size, for opening on click.
 *
 * The listing asks the image host for a height, and the host answers with an
 * image scaled to it - `h=320` returns 426x320. Zooming was pointing at that
 * same small file and stretching it, so clicking a photograph produced a
 * blurry picture barely larger than the thumbnail. Drop the height and the
 * host sends the original, 1024x768.
 *
 * @param string $url
 * @return string
 */
function fullSizeImage($url) {
    $url = (string) $url;
    if ($url === '') {
        return '';
    }
    return preg_replace('/[?&]h=\d+/', '', $url);
}

/**
 * SQL for "this is fixed-price stock" - which the portal no longer has.
 *
 * Fixed price was removed from the portal on the owner's order of 13 September
 * 2026: the page, the menu links, the desk's tiles and filter, and the rows
 * themselves. This matches nothing, so anything that still asks gets no rows
 * rather than an error.
 *
 * @param string $alias
 * @return string
 */
function fixedPriceSql($alias = 'c') {
    return '0 = 1';
}

/**
 * The same test as currentLotsSql(), applied to a row already in hand.
 *
 * @param array $car
 * @return bool
 */
function lotIsCurrent($car) {
    // The same turn-around as sellableSql(): say what counts as for sale rather
    // than trying to name every word the feed uses for gone. If these two ever
    // disagreed, a vehicle would be missing from the list but reachable by its
    // own link, or the reverse.
    $s = strtolower(trim((string) ($car['status'] ?? '')));
    return $s === '' || strpos($s, 'available') === 0;
}

/**
 * Has this lot's auction day passed? Used to close bidding, never to decide
 * what the list shows — see currentLotsSql() for why the list carries no date
 * cutoff. A day of slack absorbs the thirteen hours between our clock and
 * Japan's, so a lot selling today is never refused as if it were yesterday's.
 *
 * @param array $car
 * @return bool
 */
function lotDayHasPassed($car) {
    $day = $car['auction_on'] ?? null;
    if (!$day) {
        // row fetched without the generated column — parse the feed's string
        $d = DateTime::createFromFormat('d.m.Y', trim((string) ($car['auction_date'] ?? '')));
        if (!$d) {
            return false;
        }
        $day = $d->format('Y-m-d');
    }
    return $day < date('Y-m-d', strtotime('-1 day'));
}

/**
 * The vehicles a whole chassis number belongs to, as SQL over a chassis column.
 *
 * A chassis number is the model code and a serial - NHP10-2054321 - and no source
 * gives us the number, only the code (jpauc never did; Pacific Boeki's own lot
 * detail holds the code alone), so the code is looked for INSIDE what was typed.
 * Pacific Boeki writes some codes with the type-approval prefix in front -
 * QDF-KDY221, 2RG-FEAV0: 1,157 lots on 14 September, trucks mostly - while the
 * number on the document is KDY221-1234567, so the part after the prefix is
 * tried as well. Without it those lots could never be found by their number.
 *
 * Used by the customer list (lot box, chassis number box) and the admin
 * Vehicles search, so the three read a number the same way.
 *
 * @param string $col the chassis column, e.g. "c.chassis"
 * @return array{0:string,1:int} the SQL, and how many times to bind the typed term
 */
function chassisNumberSql($col) {
    $tail = "SUBSTRING_INDEX($col, '-', -1)";
    return array("((CHAR_LENGTH($col) >= 3 AND ? LIKE CONCAT('%', $col, '%'))"
               . " OR ($col LIKE '%-%' AND CHAR_LENGTH($tail) >= 3 AND ? LIKE CONCAT('%', $tail, '%')))", 2);
}

/**
 * A chassis code or number, split into its model code and its serial.
 *
 *   NHP10-2054321       -> NHP10, 2054321
 *   QDF-KDY221          -> KDY221, ''       (Pacific Boeki's prefixed code)
 *   QDF-KDY221-1234567  -> KDY221, 1234567
 *   NHP10               -> NHP10, ''
 *
 * A last part made only of digits is the serial. A first part of two or three
 * characters in front of a longer code is a type-approval prefix (QDF, 2RG, BA),
 * which is not part of the number printed on a document, so the code after it is
 * the model. Otherwise the first part is the model and the rest its serial, as a
 * buyer types it. The same reading as chassisParts() in site.js.
 *
 * @param string $s
 * @return array{0:string,1:string}
 */
function chassisParts($s) {
    $parts = array_values(array_filter(preg_split('/[\s\-]+/', strtoupper(trim((string) $s))), 'strlen'));
    $serial = '';
    if (count($parts) > 1 && ctype_digit((string) end($parts))) {
        $serial = (string) array_pop($parts);
    }
    if (!$parts) {
        return array('', $serial);
    }
    if (count($parts) > 1 && strlen($parts[0]) <= 3 && strlen((string) end($parts)) >= 4) {
        $model = (string) end($parts);
    } elseif (count($parts) > 1 && $serial === '') {
        $model  = $parts[0];
        $serial = implode('', array_slice($parts, 1));
    } else {
        $model = (string) end($parts);
    }
    return array(preg_replace('/[^A-Z0-9]/', '', $model), preg_replace('/[^A-Z0-9]/', '', $serial));
}

/**
 * Turn the request's filters into a WHERE clause plus bound parameters.
 *
 * @param array  $filters
 * @param string $skip  omit this facet's own condition — used when counting
 *                      that facet's options, so the user can still see (and
 *                      switch to) the other values in it
 * @return array{0:string,1:array,2:string}
 */
function buildCarFilter($filters, $skip = '') {
    // Staff see the whole auction archive, but still only the auctions:
    // fixed-price stock is a different product with its own view.
    // The same scope for everybody. Staff used to be given a wider one here, so
    // the customer site showed a signed-in administrator 93,822 auction lots
    // where a customer saw 51,438 - the portal disagreeing with itself
    // depending on who was looking, which is no way to check it against the
    // source. Staff who want the archive have the Vehicles screen, which has a
    // section filter and a status filter and does not pretend to be the shop.
    $where  = array(currentLotsSql('c'));
    $params = array();
    $types  = '';

    if (!empty($filters['make'])) {
        $where[]  = "c.make = ?";
        $params[] = $filters['make'];
        $types   .= 's';
    }
    if (!empty($filters['model'])) {
        $where[]  = "c.model LIKE ?";
        $params[] = '%' . $filters['model'] . '%';
        $types   .= 's';
    }
    if (!empty($filters['lot'])) {
        // The box is labelled "Lot Number or Chassis Model" and hinted "Comma
        // separate to search multiple lot no", and did neither: it matched one
        // lot number exactly and nothing else. Typing a chassis code returned
        // an empty list, and so did two lot numbers with a comma between them.
        //
        // A term is tried as a lot number and as a chassis code, because a
        // buyer holding "MH34S" and a buyer holding "4189" both type into this
        // one box and neither should have to know which it is. The lot has to
        // match exactly - lot 44 is not lot 444 - while a chassis code is
        // matched loosely, since the trade quotes them with and without their
        // suffixes.
        $terms = array_filter(array_map('trim', explode(',', $filters['lot'])), 'strlen');
        $parts = array();
        foreach (array_slice($terms, 0, 25) as $t) {
            $parts[]  = "c.lot_no = ?";
            $params[] = $t;
            $types   .= 's';

            $parts[]  = "c.chassis LIKE ?";
            $params[] = '%' . $t . '%';
            $types   .= 's';

            /* A whole chassis number, pasted from a document.

               A Japanese chassis number is the model code and a serial -
               NHP10-2054321, sometimes written without the dash. The feed
               carries only the model code, because jpauc does not publish the
               number: the field is there on its own detail page but empty, and
               the box beside it is a lookup a buyer types INTO, not a value it
               shows. So the whole number matched nothing at all and the search
               came back empty, which reads as a broken search rather than as
               data we were never given.

               Turning the comparison around finds the car's model inside what
               was typed, whichever way the number is written, and costs nothing
               on an ordinary search because it only applies to a term long
               enough to be a whole number. It cannot single out one vehicle -
               nothing can, without the number - but it lands the buyer on the
               right model instead of on nothing. */
            if (strlen($t) >= 8) {
                list($sql, $binds) = chassisNumberSql('c.chassis');
                $parts[] = $sql;
                for ($i = 0; $i < $binds; $i++) {
                    $params[] = $t;
                    $types   .= 's';
                }
            }
        }
        if ($parts) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    /* The chassis code on its own. The lot box takes one too, because it
       always did and links exist that rely on it, but this is the box named
       after the job. */
    if (!empty($filters['chassis_model'])) {
        $terms = array_filter(array_map('trim', explode(',', $filters['chassis_model'])), 'strlen');
        $parts = array();
        foreach (array_slice($terms, 0, 25) as $t) {
            $parts[]  = "c.chassis LIKE ?";
            $params[] = '%' . $t . '%';
            $types   .= 's';
        }
        if ($parts) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    /* A whole chassis number - NHP10-2054321, or the same without its dash.

       It cannot name one vehicle, because the feed does not carry chassis
       numbers: jpauc's own detail page has the field and leaves it empty, and
       the box beside it is a lookup a buyer types INTO. Checked on six vehicles
       across four halls; empty every time.

       What it can do is find the model the number begins with, which is the
       part the feed does carry. The comparison is turned around - the vehicle's
       code found inside what was typed - so it works whether or not the number
       is written with its dash, and a code under three characters is left out
       so nothing matches by accident. */
    if (!empty($filters['chassis_no'])) {
        $terms = array_filter(array_map('trim', explode(',', $filters['chassis_no'])), 'strlen');
        $parts = array();
        foreach (array_slice($terms, 0, 25) as $t) {
            list($sql, $binds) = chassisNumberSql('c.chassis');
            $parts[] = $sql;
            for ($i = 0; $i < $binds; $i++) {
                $params[] = $t;
                $types   .= 's';
            }

            // And the plain reading, for somebody who types only the model part.
            $parts[]  = "c.chassis LIKE ?";
            $params[] = '%' . $t . '%';
            $types   .= 's';
        }
        if ($parts) {
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }

    // numeric ranges
    $ranges = array(
        'year_min'    => array('c.year >= ?',    'i'),
        'year_max'    => array('c.year <= ?',    'i'),
        'mileage_min' => array('c.mileage >= ?', 'i'),
        'mileage_max' => array('c.mileage <= ?', 'i'),
        'cc_min'      => array('c.engine_cc >= ?', 'i'),
        'cc_max'      => array('c.engine_cc <= ?', 'i'),
        'price_min'   => array('c.price >= ?',   'd'),
        'price_max'   => array('c.price <= ?',   'd'),
    );
    foreach ($ranges as $key => $spec) {
        if (isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== null) {
            $where[]  = $spec[0];
            $params[] = ($spec[1] === 'i') ? intval($filters[$key]) : floatval($filters[$key]);
            $types   .= $spec[1];
        }
    }

    // multi-select facets
    foreach (facetColumns() as $key => $column) {
        if ($key === $skip) {
            continue;
        }
        $vals = $filters[$key] ?? array();
        if (is_string($vals)) {
            $vals = ($vals === '') ? array() : array($vals);
        }
        $vals = array_values(array_filter(array_map('strval', (array) $vals), 'strlen'));
        if (!$vals) {
            continue;
        }
        $where[]  = "c.$column IN (" . implode(',', array_fill(0, count($vals), '?')) . ")";
        foreach ($vals as $v) {
            $params[] = $v;
            $types   .= 's';
        }
    }

    return array(implode(' AND ', $where), $params, $types);
}

/**
 * Count the available options for each facet, given everything else the user
 * has already chosen. This is what makes the panel narrow as they filter.
 *
 * @param array $filters
 * @return array facet key => [ ['value'=>..,'n'=>..], .. ]
 */
/**
 * A short-lived answer, kept on disk.
 *
 * The auction list costs 1.7 seconds of database before a single card is drawn,
 * and 1.3 of those are the sidebar: seven GROUP BYs over fifty-seven thousand
 * live rows for the halls, the days, the grades, the colours and the rest, plus
 * the make list, plus the ends of the sliders. Every visitor paid for all of it,
 * and every one of them got the same answer.
 *
 * They are counts beside a tick-box, not the catalogue itself. A minute of age
 * on "Kinki (1,349)" costs a buyer nothing; the number they actually act on -
 * the total, and the twenty cards - is read fresh every time and is not cached
 * here at all.
 *
 * In the system temp directory rather than under the document root, because
 * nothing here needs to be reachable over HTTP. The write goes to a private
 * name and is renamed into place, so a reader never opens a half-written file.
 *
 * @param string $key  what is being remembered, already including its inputs
 * @param int    $ttl  seconds before it is read again
 */
function cacheGet($key, $ttl) {
    $f = sys_get_temp_dir() . '/sbk-' . preg_replace('/[^a-z0-9_\-]/i', '', $key) . '.cache';
    if (!is_file($f) || (time() - (int) @filemtime($f)) > $ttl) {
        return null;
    }
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') {
        return null;
    }
    $v = @unserialize($raw);
    return ($v === false) ? null : $v;
}

/**
 * How many past auction results the portal holds - THE one place that counts them.
 *
 * Counted afresh every time, deliberately, and this is the whole reason the
 * figure agrees with itself across the portal.
 *
 * It was cached for a minute at first, to spare a table heading for 1.2 million
 * rows. But the Statistics page counted its own heading live while the dashboard
 * tiles read the cache, and during the backfill the table gains some six hundred
 * rows a minute - so the two disagreed by up to six hundred, which is exactly
 * what the owner saw. The auction count beside it has never disagreed with itself
 * anywhere, and the reason is that nothing caches it: every screen asks the same
 * question at the moment it draws.
 *
 * The cost was measured before choosing: COUNT(*) here is 115 ms against 607,000
 * rows, where the auction's own live count is 90 ms - the same order, and this is
 * asked for once per twenty-second poll. Mixing a cached path with a live one is
 * what breaks agreement; if this ever has to be cached again, EVERY reader must
 * be moved onto the cache in the same change, `statistics.php?count=1` included.
 *
 * Returns 0 before the table exists, so a dashboard never breaks over it.
 *
 * @return int
 */
function statsCount() {
    global $conn;
    $n = 0;
    if ($r = @$conn->query("SHOW TABLES LIKE 'car_stats'")) {
        if ($r->num_rows && $q = @$conn->query("SELECT COUNT(*) FROM car_stats")) {
            $n = (int) $q->fetch_row()[0];
        }
    }
    return $n;
}

/** @see cacheGet() */
function cachePut($key, $value) {
    $f = sys_get_temp_dir() . '/sbk-' . preg_replace('/[^a-z0-9_\-]/i', '', $key) . '.cache';
    $t = $f . '.' . getmypid();
    if (@file_put_contents($t, serialize($value)) !== false) {
        if (!@rename($t, $f)) { @unlink($t); }
    }
}

/**
 * One key for a set of filters.
 *
 * Sorting and paging change what is shown, never what is counted, so they are
 * left out - otherwise page two of the same search would miss the cache that
 * page one just filled.
 */
function filterCacheKey($prefix, $filters) {
    unset($filters['sort'], $filters['page'], $filters['per_page']);
    ksort($filters);
    return $prefix . '-' . md5(serialize($filters));
}

function getFacets($filters) {
    global $conn;

    $ck = filterCacheKey('facets', $filters);
    $hit = cacheGet($ck, 120);
    if (is_array($hit)) {
        return $hit;
    }

    $out = array();

    foreach (facetColumns() as $key => $column) {
        list($where, $params, $types) = buildCarFilter($filters, $key);

        // Sale days read as a calendar, so they go in date order. Everything
        // else is a list to pick from, where the commonest options are the
        // ones worth putting first.
        $order = ($key === 'auction_on') ? 'v ASC' : 'n DESC, v ASC';

        $sql = "SELECT c.$column AS v, COUNT(*) AS n
                FROM cars c
                WHERE $where AND c.$column IS NOT NULL AND c.$column <> ''
                GROUP BY c.$column
                ORDER BY $order
                LIMIT 60";
        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($key === 'rating') {
            $rows = orderAuctionGrades($rows);
        }
        $out[$key] = $rows;
    }
    cachePut($ck, $out);
    return $out;
}

/**
 * Put the inspection grades in the order the trade reads them.
 *
 * The feed hands back thirty-odd distinct values because it passes through
 * whatever the inspector wrote, including a good deal of noise — "????", "??",
 * "*", "99". Sorted by how often each appears, a buyer got 3.5 next to R next
 * to "***" with no sense to it. These are a scale: S is a new car, 6 down to 1
 * is condition, R means repaired. So they are listed as a scale, best first,
 * and the noise goes last rather than being thrown away — a lot carrying an
 * odd mark still has to be findable.
 *
 * @param array $rows
 * @return array
 */
function orderAuctionGrades($rows) {
    static $scale = array('S', '6', '5', '4.5', '4', '3.5', '3', '2', '1',
                          'RA', 'RB', 'R', 'R1', 'R2', 'N', 'W', 'X', '0', '-', '***');
    $rank = array_flip($scale);
    usort($rows, function ($a, $b) use ($rank) {
        $ia = isset($rank[$a['v']]) ? $rank[$a['v']] : 900;
        $ib = isset($rank[$b['v']]) ? $rank[$b['v']] : 900;
        if ($ia !== $ib) {
            return $ia - $ib;
        }
        return $b['n'] - $a['n'];   // both unknown: commonest first
    });
    return $rows;
}

/**
 * The year / mileage / engine bounds inside the current result set, so the
 * range inputs can show sensible limits instead of guesses.
 *
 * @param array $filters
 * @return array
 */
function getRangeBounds($filters) {
    global $conn;

    $ck = filterCacheKey('bounds', $filters);
    $hit = cacheGet($ck, 120);
    if (is_array($hit)) {
        return $hit;
    }

    list($where, $params, $types) = buildCarFilter($filters);
    $sql = "SELECT MIN(NULLIF(c.year,0)) y0, MAX(c.year) y1,
                   MIN(NULLIF(c.mileage,0)) m0, MAX(c.mileage) m1,
                   MIN(NULLIF(c.engine_cc,0)) e0, MAX(c.engine_cc) e1
            FROM cars c WHERE $where";
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $out = array(
        'year_min'    => (int) ($row['y0'] ?? 0),
        'year_max'    => (int) ($row['y1'] ?? 0),
        'mileage_min' => (int) ($row['m0'] ?? 0),
        'mileage_max' => (int) ($row['m1'] ?? 0),
        'cc_min'      => (int) ($row['e0'] ?? 0),
        'cc_max'      => (int) ($row['e1'] ?? 0),
    );
    cachePut($ck, $out);
    return $out;
}

function getActiveCars($page = 1, $per_page = 12, $filters = array()) {
    global $conn;

    $page = max(1, intval($page));
    $offset = ($page - 1) * $per_page;

    list($where_clause, $params, $types) = buildCarFilter($filters);

    // sorting (whitelisted — never interpolate user input into SQL)
    $sorts = carSortOptions();
    $sort_key = isset($filters['sort']) && isset($sorts[$filters['sort']]) ? $filters['sort'] : 'newest';
    $order_by = $sorts[$sort_key];

    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM cars c WHERE $where_clause";
    $stmt = $conn->prepare($count_query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // Get cars
    $query = "
        SELECT
            id, car_id, lot_no, make, model, year, mileage, price, currency,
            status, images, auction_sheet, last_updated,
            auction, auction_date, auction_time, chassis, transmission, grade, rating,
            engine_cc, engine_hp, equipment, load_capacity, price_history,
            color, avg_price, sold_price, source_url, source_section
        FROM cars c
        WHERE $where_clause
        ORDER BY $order_by
        LIMIT ? OFFSET ?
    ";

    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $cars = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Parse images
    foreach ($cars as &$car) {
        $car['images'] = !empty($car['images']) ? json_decode($car['images'], true) : array();
    }

    return array(
        'cars' => $cars,
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page)
    );
}

/**
 * Distinct makes with their inventory counts (for the filter dropdown).
 * Cached per-request.
 * @return array [ ['make'=>..,'n'=>..], .. ]
 */
function getMakes() {
    // keyed on the viewer: staff and customers get different counts, and a
    // single request must never serve one from the other's cache
    static $cache = array();
    $key = 'all';   // one scope now, so one bucket
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    /* The static above only saves the second call inside one request. The list
       is the same for everybody and moves a make at a time, so it is also kept
       on disk for five minutes - see cacheGet(). */
    $hit = cacheGet('makes', 300);
    if (is_array($hit)) {
        $cache[$key] = $hit;
        return $hit;
    }
    global $conn;
    $rows = array();
    // same visibility rule as the list, so the counts on the welcome page match
    // what the visitor will actually find when they click through
    // One scope, whoever is asking - see buildCarFilter(). A facet counted over
    // a wider set than the list it filters offers the reader choices that
    // return nothing.
    $scope = currentLotsSql('cars');
    $res = $conn->query("
        SELECT make, COUNT(*) AS n
        FROM cars
        WHERE $scope AND make <> ''
        GROUP BY make
        ORDER BY make ASC
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $cache[$key] = $rows;
    cachePut('makes', $rows);
    return $rows;
}

/**
 * The Japanese marques the feed lists first, in the order the trade expects.
 * Everything else is shown after these.
 */
function japaneseMakes() {
    return array('TOYOTA', 'NISSAN', 'MAZDA', 'MITSUBISHI', 'HONDA', 'SUZUKI',
                 'SUBARU', 'ISUZU', 'DAIHATSU', 'MITSUOKA', 'LEXUS', 'HINO');
}

/**
 * Makes split into the Japanese group and the rest, for the welcome page.
 * @return array{japanese: array, other: array, total: int}
 */
function getMakesGrouped() {
    $jp = japaneseMakes();
    $japanese = array();
    $other = array();
    $total = 0;

    // keep the Japanese group in trade order rather than alphabetical
    $byName = array();
    foreach (getMakes() as $m) {
        $byName[strtoupper($m['make'])] = $m;
        $total += (int) $m['n'];
    }
    foreach ($jp as $name) {
        if (isset($byName[$name])) {
            $japanese[] = $byName[$name];
            unset($byName[$name]);
        }
    }
    foreach ($byName as $m) {
        $other[] = $m;
    }
    return array('japanese' => $japanese, 'other' => $other, 'total' => $total);
}

/**
 * Models of one make, grouped by first letter, each with its count.
 * @param string $make
 * @return array letter => [ ['model'=>..,'n'=>..], .. ]
 */
function getModelsByLetter($make) {
    global $conn;
    $out = array();
    // One scope, whoever is asking - see buildCarFilter(). A facet counted over
    // a wider set than the list it filters offers the reader choices that
    // return nothing.
    $scope = currentLotsSql('cars');
    $stmt = $conn->prepare("
        SELECT model, COUNT(*) AS n
        FROM cars
        WHERE make = ? AND $scope AND model <> ''
        GROUP BY model
        ORDER BY model ASC
    ");
    $stmt->bind_param('s', $make);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $first = strtoupper(mb_substr($row['model'], 0, 1));
        if (!preg_match('/[A-Z0-9]/', $first)) {
            $first = '#';
        }
        $out[$first][] = $row;
    }
    $stmt->close();
    ksort($out);
    return $out;
}

/**
 * Headline inventory numbers for the masthead.
 * @return array
 */
function getInventoryStats() {
    global $conn;
    $out = array('total' => 0, 'available' => 0, 'makes' => 0, 'auctions' => 0);
    // "available" here means what a customer can actually act on: still to be
    // auctioned. Counting every 'available' row would include lots whose
    // auction day has already gone, which is where the headline number used to
    // disagree with the source.
    $current = currentLotsSql('cars');
    $scope   = $current;
    $res = $conn->query("
        SELECT
            COUNT(*) AS total,
            SUM($current) AS available,
            COUNT(DISTINCT make) AS makes,
            COUNT(DISTINCT auction) AS auctions
        FROM cars
        WHERE $scope
    ");
    if ($res && ($row = $res->fetch_assoc())) {
        $out['total']     = intval($row['total']);
        $out['available'] = intval($row['available']);
        $out['makes']     = intval($row['makes']);
        $out['auctions']  = intval($row['auctions']);
    }
    return $out;
}

/**
 * Get single car by row id (from a URL) or by feed car_id (from a bid/enquiry).
 *
 * The row-id branch is only allowed to fire when the input is all digits —
 * feed keys such as "12345abc" would otherwise intval() down to 12345 and
 * silently return a completely different vehicle.
 *
 * @param int|string $car_id
 * @return array|null
 */
function getCarById($car_id) {
    global $conn;

    $row_id = ctype_digit((string) $car_id) ? (int) $car_id : 0;
    $key    = (string) $car_id;

    $stmt = $conn->prepare("
        SELECT id, car_id, lot_no, make, model, year, mileage, price, currency,
               status, images, auction_sheet, last_updated, created_at,
               auction, auction_date, auction_on, auction_time, chassis, transmission, grade, rating,
               engine_cc, engine_hp, equipment, load_capacity, price_history,
               color, avg_price, sold_price, source_url
        FROM cars
        WHERE (? <> 0 AND id = ?) OR car_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("iis", $row_id, $row_id, $key);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        return null;
    }

    $car = $result->fetch_assoc();
    $stmt->close();

    // Parse images
    $car['images'] = !empty($car['images']) ? json_decode($car['images'], true) : array();

    return $car;
}

// ============================================================================
// ORDER FUNCTIONS
// ============================================================================

/**
 * Create new order
 * @param int $client_id
 * @param int $car_id
 * @return array
 */
function createOrder($client_id, $car_id) {
    global $conn;

    // Get car
    $car = getCarById($car_id);
    if (!$car) {
        return array('success' => false, 'message' => 'Car not found');
    }

    // Generate order number
    $order_number = "ORD-" . date('Y') . "-" . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);

    // Save car details as JSON
    $car_details = json_encode(array(
        'make' => $car['make'],
        'model' => $car['model'],
        'year' => $car['year'],
        'price' => $car['price'],
        'mileage' => $car['mileage']
    ));

    // Insert order
    $stmt = $conn->prepare("
        INSERT INTO orders (order_number, client_id, car_id, car_details, make, model, year, amount, currency, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
    ");
    $stmt->bind_param("sisssssds", $order_number, $client_id, $car['car_id'], $car_details, $car['make'], $car['model'], $car['year'], $car['price'], $car['currency']);

    if (!$stmt->execute()) {
        return array('success' => false, 'message' => 'Failed to create order');
    }

    $order_id = $stmt->insert_id;
    $stmt->close();

    return array('success' => true, 'order_id' => $order_id, 'order_number' => $order_number);
}

/**
 * Get client orders
 * @param int $client_id
 * @return array
 */
function getClientOrders($client_id) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT id, order_number, car_id, make, model, year, amount, currency,
               status, order_date, updated_at
        FROM orders
        WHERE client_id = ?
        ORDER BY order_date DESC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $orders;
}

/**
 * Get all orders for admin
 * @return array
 */
function getAllOrders() {
    global $conn;

    $stmt = $conn->prepare("
        SELECT o.id, o.order_number, o.client_id, c.name, c.email,
               o.car_id, o.make, o.model, o.year, o.amount, o.currency,
               o.status, o.order_date, o.updated_at
        FROM orders o
        JOIN clients c ON o.client_id = c.id
        ORDER BY o.order_date DESC
    ");
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $orders;
}

/**
 * Update order status
 * @param int $order_id
 * @param string $status
 * @return bool
 */
function updateOrderStatus($order_id, $status) {
    global $conn;

    $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

// ============================================================================
// INQUIRY FUNCTIONS
// ============================================================================

/**
 * Create inquiry
 * @param int $client_id
 * @param string $car_id
 * @param string $message
 * @return array
 */
function createInquiry($client_id, $car_id, $message) {
    global $conn;

    $car = getCarById($car_id);
    if (!$car) {
        return array('success' => false, 'message' => 'Car not found');
    }

    $inquiry_number = "INQ-" . date('Y') . "-" . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);

    $stmt = $conn->prepare("
        INSERT INTO inquiries (inquiry_number, client_id, car_id, make, model, message, status, priority)
        VALUES (?, ?, ?, ?, ?, ?, 'new', 'normal')
    ");
    // s inquiry_number, i client_id, s car_id, s make, s model, s message
    $stmt->bind_param("sissss", $inquiry_number, $client_id, $car['car_id'], $car['make'], $car['model'], $message);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        error_log('createInquiry failed: ' . $err);
        return array('success' => false, 'message' => 'Failed to create inquiry');
    }
    $stmt->close();

    return array('success' => true, 'inquiry_number' => $inquiry_number);
}

/**
 * Get client inquiries
 * @param int $client_id
 * @return array
 */
function getClientInquiries($client_id) {
    global $conn;

    $stmt = $conn->prepare("
        SELECT id, inquiry_number, car_id, make, model, message, response,
               status, priority, created_at, responded_at
        FROM inquiries
        WHERE client_id = ?
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $inquiries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $inquiries;
}

/**
 * Every enquiry, for the admin panel.
 * @param string $status optional filter
 * @return array
 */
function getAllInquiries($status = '') {
    global $conn;
    $sql = "
        SELECT i.*, cl.name AS client_name, cl.email AS client_email,
               c.id AS car_row_id, c.lot_no
        FROM inquiries i
        JOIN clients cl ON cl.id = i.client_id
        LEFT JOIN cars c ON c.car_id = i.car_id
    ";
    if ($status !== '') {
        $sql .= " WHERE i.status = ? ";
    }
    $sql .= " ORDER BY i.created_at DESC";

    $stmt = $conn->prepare($sql);
    if ($status !== '') {
        $stmt->bind_param('s', $status);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Answer an enquiry and/or move it along.
 * The reply shows on the client's dashboard.
 *
 * @param int    $inquiry_id
 * @param string $status
 * @param string $response
 * @return bool
 */
/**
 * One enquiry's current status and reply, or null.
 *
 * Read before a save so the page can tell which fields a submission is actually
 * moving. Permissions are per action now, and "did this change" is the only way
 * to know which action is being attempted.
 *
 * @param int $id
 * @return array|null
 */
function inquiryRow($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT status, COALESCE(response, '') AS response FROM inquiries WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function updateInquiry($inquiry_id, $status, $response = '') {
    global $conn;
    // 'new' is not in this list on purpose. Nobody sets an enquiry back to
    // never-been-looked-at; it leaves that state the moment somebody opens it
    // and there is no honest way back.
    $allowed = array('open', 'answered', 'closed');
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    if (trim($response) !== '') {
        $stmt = $conn->prepare("
            UPDATE inquiries
            SET status = ?, response = ?, responded_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param('ssi', $status, $response, $inquiry_id);
    } else {
        $stmt = $conn->prepare("UPDATE inquiries SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $status, $inquiry_id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ============================================================================
// BIDDING
// ============================================================================

/**
 * A suggested window for a bid - NOT ENFORCED ANY MORE.
 *
 * It used to be the rule: floor = the lot's start price, ceiling = the market
 * average or 1.5x the start price, and placeBid() refused anything outside it.
 * The owner's instruction of 12 September 2026 is that bidding has no limit -
 * "koi kitni ki bhi mare, unlimited" - so nothing refuses a bid on this any
 * more and it is no longer shown. Kept only so a future screen that wants a
 * guide price has one to ask for.
 *
 * @param array $car
 * @return array{min: float, max: float, basis: string}
 */
function bidRange($car) {
    $start = (float) ($car['price'] ?? 0);
    $avg   = (float) ($car['avg_price'] ?? 0);

    if ($start <= 0) {
        // no published start price — fall back to the market average alone
        if ($avg > 0) {
            return array('min' => round($avg * 0.6), 'max' => round($avg * 1.2),
                         'basis' => 'market average');
        }
        return array('min' => 0.0, 'max' => 0.0, 'basis' => 'on request');
    }

    $max = ($avg > $start) ? $avg : $start * 1.5;
    return array('min' => round($start), 'max' => round($max),
                 'basis' => ($avg > $start) ? 'start price to market average'
                                            : 'start price + 50%');
}

/**
 * Record a bid. Any amount above zero - there is no window (see bidRange()).
 *
 * @param int   $client_id
 * @param array $car
 * @param mixed $amount raw user input
 * @return array{success: bool, message?: string, bid_number?: string}
 */
function placeBid($client_id, $car, $amount, $staff_id = null) {
    global $conn;

    // Concluded lots stay in the list so it reads the same as the source site.
    // They must still be refused here — the page hides the form, but the post
    // can be replayed.
    if (!lotIsBiddable($car)) {
        return array('success' => false,
                     'message' => 'This lot is no longer in the auction — bidding is closed.');
    }

    $raw = str_replace(array(',', ' ', '¥'), '', (string) $amount);
    if ($raw === '' || !is_numeric($raw)) {
        return array('success' => false, 'message' => 'Enter your bid as a number.');
    }
    $amount = (float) $raw;
    if ($amount <= 0) {
        return array('success' => false, 'message' => 'Enter a bid above zero.');
    }

    /* No window. The owner, 12 September 2026: a bid is whatever the bidder
       wants it to be - below the start price or far above it - and the desk
       judges it. The only ceiling left is the column's own: `amount` is
       DECIMAL(12,2), and a number past it would fail the insert with a message
       that says nothing useful. */
    if ($amount >= 10000000000) {
        return array('success' => false, 'message' => 'That amount is too large to record. Please check the number.');
    }
    $noMin = null;
    $noMax = null;      // the window columns stay, empty: there is no window

    $bid_number = 'BID-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

    /* One of the two is set and the other is null. A customer bid names a
       client; a bid placed from inside the panel names the member of staff who
       placed it, so it can be shown back to them and to nobody else. */
    $client_id = ($staff_id === null) ? (int) $client_id : null;
    $staff_id  = ($staff_id === null) ? null : (int) $staff_id;

    $stmt = $conn->prepare("
        INSERT INTO bids
          (bid_number, client_id, staff_id, car_id, make, model, year, lot_no,
           amount, currency, min_allowed, max_allowed, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'placed')
    ");
    $year = $car['year'] !== null ? (int) $car['year'] : null;
    $currency = $car['currency'] ?: 'yen';
    $stmt->bind_param(
        // s bid_number, i client_id, i staff_id, s car_id, s make, s model,
        // i year, s lot_no, d amount, s currency, d min, d max
        'siisssisdsdd',
        $bid_number, $client_id, $staff_id, $car['car_id'], $car['make'],
        $car['model'], $year, $car['lot_no'], $amount, $currency,
        $noMin, $noMax
    );
    if (!$stmt->execute()) {
        $stmt->close();
        return array('success' => false, 'message' => 'Could not record your bid. Please try again.');
    }
    $stmt->close();

    return array('success' => true, 'bid_number' => $bid_number, 'amount' => $amount);
}

/**
 * Bids placed by one client.
 * @param int $client_id
 * @return array
 */
function getClientBids($client_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT b.*, c.id AS car_row_id
        FROM bids b
        LEFT JOIN cars c ON c.car_id = b.car_id
        WHERE b.client_id = ?
        ORDER BY b.placed_at DESC
    ");
    $stmt->bind_param('i', $client_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Every bid, for the admin panel.
 * @param string $status optional filter
 * @return array
 */
function bidStatuses() {
    /* One list, used by the screen AND by the spreadsheet. Two copies of a
       whitelist is how two screens quietly stop agreeing about what is valid. */
    return array('placed', 'under review', 'accepted', 'rejected', 'won', 'lost');
}

/**
 * The day a bids screen is showing, as Y-m-d.
 *
 * Today unless a real date was asked for — a mangled or empty link shows the
 * desk its own day rather than an empty screen. PHP and MySQL are both on UTC
 * on this host, so the day this picks is the day the "Placed" column prints.
 *
 * @param string $raw
 * @return string
 */
function bidDay($raw) {
    $raw = trim((string) $raw);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $t = strtotime($raw);
        if ($t !== false && date('Y-m-d', $t) === $raw) {
            return $raw;
        }
    }
    return date('Y-m-d');
}

/** The day after $day, so a range can be half-open. */
function bidDayEnd($day) {
    return date('Y-m-d', strtotime($day . ' +1 day'));
}

function getAllBids($status = '', $onlyStaff = null, $day = '') {
    global $conn;
    /* Both joins are LEFT now. A bid carries a client_id or a staff_id and
       never both, so an inner join to clients dropped every staff bid on the
       floor - silently, which is the worst way to lose a row. */
    $sql = "
        SELECT b.*,
               COALESCE(cl.name, ad.name, ad.username, 'Unknown') AS client_name,
               COALESCE(cl.email, ad.email, ad.username, '')      AS client_email,
               ad.name AS staff_name,
               c.id AS car_row_id
        FROM bids b
        LEFT JOIN clients cl ON cl.id = b.client_id
        LEFT JOIN admins  ad ON ad.id = b.staff_id
        LEFT JOIN cars    c  ON c.car_id = b.car_id
    ";
    $where = array();
    $params = array();
    $types = '';
    if ($status !== '') {
        $where[] = 'b.status = ?';
        $params[] = $status;
        $types .= 's';
    }
    if ($onlyStaff !== null) {
        $where[] = 'b.staff_id = ?';
        $params[] = (int) $onlyStaff;
        $types .= 'i';
    }
    /* ONE day, which is how the desk reads this screen since 17 September 2026:
       today's bids on opening, an older day when the date box is changed. A
       half-open range rather than DATE(b.placed_at) = ?, so an index on
       placed_at would still be used - there is none today, and one day there
       will be. */
    if ($day !== '') {
        $where[] = 'b.placed_at >= ? AND b.placed_at < ?';
        $params[] = $day . ' 00:00:00';
        $params[] = bidDayEnd($day) . ' 00:00:00';
        $types .= 'ss';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY b.placed_at DESC";

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Move a bid through review.
 * @param int    $bid_id
 * @param string $status
 * @param string $note
 * @return bool
 */
/**
 * Remove one bid for good.
 *
 * Nothing else points at a bid - no order is raised from it and no history is
 * kept of it - so there is nothing to tidy up afterwards and nothing to orphan.
 * It is final, which is why it sits behind its own permission and behind a
 * question on the screen.
 *
 * @param int $id
 * @return bool true if a row was actually removed
 */
function deleteBid($id, $onlyStaff = null) {
    global $conn;
    /* The scope goes into the statement, not into an `if` in front of it. A
       screen that only lists a person's own bids still receives whatever id is
       posted to it, and the check that matters is the one the database makes. */
    if ($onlyStaff !== null) {
        $st = $conn->prepare("DELETE FROM bids WHERE id = ? AND staff_id = ?");
        if (!$st) {
            return false;
        }
        $only = (int) $onlyStaff;
        $st->bind_param('ii', $id, $only);
    } else {
        $st = $conn->prepare("DELETE FROM bids WHERE id = ?");
        if (!$st) {
            return false;
        }
        $st->bind_param('i', $id);
    }
    $st->execute();
    $n = $st->affected_rows;
    $st->close();
    return $n > 0;
}

/**
 * Accepted bids, each with everything known about the vehicle and the customer.
 *
 * The Bids screen shows eight columns because eight is what fits. This is what
 * the desk actually works from - the lot and its chassis code, what the hall
 * graded it, what it starts at and what was bid, and the customer's full
 * address - so it selects widely and leaves the choosing to the sheet.
 *
 * Accepted only, and that is the whole point of it: the owner's instruction is
 * that a bid reaches the spreadsheet when, and only when, the desk has accepted
 * it. Every other status stays on the screen.
 *
 * @return array
 */
function bidsFull($onlyStaff = null, $day = '', $status = 'accepted') {
    global $conn;
    $sql = "
        SELECT b.id, b.bid_number, b.car_id, b.amount, b.currency,
               b.min_allowed, b.max_allowed, b.status, b.admin_note,
               b.placed_at, b.updated_at,
               b.make AS bid_make, b.model AS bid_model, b.year AS bid_year,
               b.lot_no AS bid_lot,
               cl.id AS client_id, cl.name AS client_name, cl.email AS client_email,
               cl.phone AS client_phone, cl.address, cl.city, cl.state,
               cl.postal_code, cl.country, cl.created_at AS client_since,
               ad.name AS staff_name,
               c.id AS car_row_id, c.lot_no AS car_lot, c.make AS car_make,
               c.model AS car_model, c.year AS car_year, c.chassis, c.mileage,
               c.engine_cc, c.transmission, c.color, c.grade AS model_grade,
               c.rating AS auction_grade, c.auction AS auction_hall,
               c.auction_on, c.auction_time, c.price AS start_price,
               c.sold_price, c.auction_sheet, c.source_url, c.equipment
          FROM bids b
     LEFT JOIN clients cl ON cl.id = b.client_id
     LEFT JOIN admins  ad ON ad.id = b.staff_id
     LEFT JOIN cars    c  ON c.car_id = b.car_id
         WHERE 1 = 1";
    /* The spreadsheet takes what the screen is showing - the owner's rule:
       "whatever filter is applied, the Excel will follow that same filter".
       Both values are already narrowed by the caller (bidStatuses(), bidDay()),
       and both are escaped again here, because a query that is only safe when
       its caller behaves is not safe. */
    if ($status !== '') {
        $sql .= " AND b.status = '" . $conn->real_escape_string($status) . "'";
    }
    if ($day !== '') {
        $sql .= " AND b.placed_at >= '" . $conn->real_escape_string($day) . " 00:00:00'"
              . " AND b.placed_at <  '" . $conn->real_escape_string(bidDayEnd($day)) . " 00:00:00'";
    }
    if ($onlyStaff !== null) {
        $sql .= ' AND b.staff_id = ' . (int) $onlyStaff;
    }
    $sql .= " ORDER BY b.placed_at DESC";
    $res = $conn->query($sql);
    $out = array();
    while ($res && $row = $res->fetch_assoc()) {
        // A staff bid has no customer behind it; the sheet's customer columns
        // name the person who placed it instead of standing empty.
        if (empty($row['client_name']) && !empty($row['staff_name'])) {
            $row['client_name'] = $row['staff_name'] . ' (staff)';
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Every accepted bid, whatever day it was placed. Kept so nothing that used to
 * ask for exactly that has to change its mind.
 *
 * @param int|null $onlyStaff
 * @return array
 */
function acceptedBidsFull($onlyStaff = null) {
    return bidsFull($onlyStaff, '', 'accepted');
}

/**
 * One bid's current status and note, or null. See inquiryRow().
 *
 * @param int $id
 * @return array|null
 */
function bidRow($id) {
    global $conn;
    // client_id and staff_id come too: every screen that changes a bid has to
    // be able to ask whose it is before it does anything to it.
    $stmt = $conn->prepare("SELECT status, COALESCE(admin_note, '') AS admin_note,
                                   client_id, staff_id
                              FROM bids WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function updateBidStatus($bid_id, $status, $note = '') {
    global $conn;
    $allowed = array('placed', 'under review', 'accepted', 'rejected', 'won', 'lost');
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $stmt = $conn->prepare("UPDATE bids SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ssi', $status, $note, $bid_id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Sanitize output
 * @param string $text
 * @return string
 */
function sanitize($text) {
    // cast first: PHP 8.1+ deprecates passing null to htmlspecialchars()
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/**
 * Format price
 * @param float $price
 * @param string $currency
 * @return string
 */
function formatPrice($price, $currency = 'JPY') {
    // the auction feed stores 'yen'; older rows may say 'JPY'
    $c = strtoupper(trim((string) $currency));
    if ($c === '' || $c === 'JPY' || $c === 'YEN' || $c === '¥') {
        return '¥' . number_format($price, 0);
    }
    if ($c === 'USD') {
        return '$' . number_format($price, 2);
    }
    return $c . ' ' . number_format($price, 2);
}

/**
 * Format date
 * @param string $date
 * @return string
 */
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

/**
 * Image CDN host used by the auction feed.
 */
define('CAR_IMG_HOST', 'https://8.ajes.com/imgs/');

/**
 * Build a photo URL from a stored image reference.
 *
 * The photo CDN only honours ONE usable variant: &w=320. It still answers
 * &h=50, but for a 320px key it returns a 50x50 "NO FOTO" placeholder tile
 * instead of a real thumbnail — so every small thumbnail on the site (admin
 * grid, list rows, related cars) came up blank while the big detail photo,
 * which already used &w=320, was fine. Any other size (&h=120, &w=80…) is a
 * hard error. So we always request &w=320 and let the browser scale it down
 * with CSS to whatever the thumbnail box needs.
 *
 * @param string $ref  stored photo key
 * @param int    $w    kept for call-site clarity; the CDN ignores it
 * @return string
 */
function carImageUrl($ref, $w = 320) {
    $ref = trim((string) $ref);
    if (strlen($ref) < 6) {
        return '';
    }
    // already a full URL (older rows)
    if (strpos($ref, 'http') === 0) {
        return $ref;
    }
    // Always 320. The CDN is particular about this parameter - &h=50 comes back
    // as a "NO FOTO" placeholder tile, and asking for a larger width returned
    // nothing at all, which blanked every image on the site. 320 is the one
    // value known to serve the real picture, so it is not a default to be
    // overridden; the $w argument is kept only so existing callers still work.
    return CAR_IMG_HOST . rawurlencode($ref) . '&w=320';
}

/**
 * All photo URLs for a car.
 * @param array $car
 * @param int   $w
 * @return array
 */
function getCarImages($car, $w = 400) {
    $out = array();
    if (empty($car['images'])) {
        return $out;
    }
    $refs = $car['images'];
    if (is_string($refs)) {
        $refs = json_decode($refs, true) ?: array();
    }
    if (!is_array($refs)) {
        return $out;
    }
    foreach ($refs as $ref) {
        // tolerate both plain keys and {url:...} shapes
        if (is_array($ref)) {
            $ref = $ref['url'] ?? '';
        }
        $url = carImageUrl($ref, $w);
        if ($url !== '') {
            $out[] = $url;
        }
    }
    return $out;
}

/**
 * Primary photo for a car ('' when the car has none).
 * @param array $car
 * @param int   $w
 * @return string
 */
function getCarImage($car, $w = 400) {
    $imgs = getCarImages($car, $w);
    return $imgs ? $imgs[0] : '';
}

/**
 * CSS modifier for an auction status.
 * @param string $status
 * @return string
 */
function statusClass($status) {
    $s = strtolower(trim((string) $status));
    if ($s === 'sold' || $s === 'sold by nego') return 'is-sold';
    if ($s === 'not sold') return 'is-unsold';
    if ($s === 'cancelled' || $s === 'cancel' || $s === 'removed' || $s === 'withdrawn') return 'is-gone';
    return 'is-live';
}

/**
 * Human label for an auction status.
 * @param string $status
 * @return string
 */
function statusLabel($status) {
    $s = strtolower(trim((string) $status));
    if ($s === 'available') return 'Available';
    if ($s === 'sold by nego') return 'Sold (nego)';
    return ucfirst($s ?: 'Available');
}

/**
 * The same, for a lot whose sale moment has already passed.
 *
 * A vehicle carries 'available' until the source publishes its result, and the
 * source can take hours over it. Until then the page said `Auction Time
 * 15:43:00  Closed` on one line and `Status  Available` on the next - the clock
 * and the status contradicting each other, and the status being the one a buyer
 * would act on.
 *
 * 'Available' before the hammer is a fact. After it, it is only the absence of
 * news, and it should read as that.
 *
 * @param array $car a full row - needs auction_date and auction_time
 * @return string
 */
function lotHammerFallen($car) {
    $day = trim((string) ($car['auction_date'] ?? ''));
    if ($day === '') {
        return false;
    }
    $tz   = new DateTimeZone('Asia/Tokyo');
    $time = trim((string) ($car['auction_time'] ?? ''), " \t[]");

    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)
        && !((int) $m[1] === 0 && (int) $m[2] === 0)) {
        $t = DateTime::createFromFormat('d.m.Y H:i',
             $day . ' ' . sprintf('%02d:%02d', (int) $m[1], (int) $m[2]), $tz);
        return $t && $t->getTimestamp() < time();
    }

    /* No time published. auctionEndsAt() guesses nine in the morning so the
       countdown has something to count to, but a guess must not be allowed to
       tell a buyer a lot is over: a hall listing a lot without a time may well
       sell it in the afternoon. Only the day being finished settles it. */
    $t = DateTime::createFromFormat('d.m.Y H:i', $day . ' 23:59', $tz);
    return $t && $t->getTimestamp() < time();
}

/**
 * May a bid still be taken on this lot?
 *
 * OPEN FOR THE WHOLE SALE DAY — the owner's instruction of 11 September 2026:
 * "sab bids open kar do, admin side, client side, sab jagah". Bids are to be
 * placeable from every screen on every lot the portal is still listing; whether
 * a bid can actually be carried into the hall is the desk's call, made when the
 * bid reaches them — it is not the portal's to refuse in advance.
 *
 * What it replaced, so the next person knows what was traded away: bidding used
 * to close at the lot's own sale time, and for the 59% of lots whose hall
 * publishes no time (477 in 813, measured 9-11 September) at the very START of
 * the sale day in Japan. That was chosen earlier to keep a bid off a car already
 * sold. The owner has now chosen the other side of that trade. The page still
 * says so plainly when a lot's time has passed or its hall gives none — see
 * lotSaleMayHaveRun() — so a buyer is told, not stopped.
 *
 * Only once the whole sale day is over in Japan does it close: by then the lot
 * has certainly been through the hall and has left the portal's list anyway
 * (see currentLotsSql()). A lot from three days ago opened from an old link or
 * the admin's Vehicles screen must not take a bid.
 *
 * @param array $car needs auction_date
 * @return bool
 */
function lotBiddingClosed($car) {
    $day = trim((string) ($car['auction_date'] ?? ''));
    if ($day === '') {
        return false;
    }
    $t = DateTime::createFromFormat('d.m.Y H:i', $day . ' 23:59', new DateTimeZone('Asia/Tokyo'));
    return $t && $t->getTimestamp() < time();
}

/**
 * Has this lot's sale possibly already happened, even though bidding is open?
 *
 * This is the rule that used to CLOSE bidding, kept now only to WARN: the
 * published time has passed, or the hall publishes no time and its sale day has
 * begun. The bid box stays open and tells the buyer the desk will confirm.
 *
 * @param array $car needs auction_date and auction_time
 * @return bool
 */
function lotSaleMayHaveRun($car) {
    $day = trim((string) ($car['auction_date'] ?? ''));
    if ($day === '') {
        return false;
    }
    $tz   = new DateTimeZone('Asia/Tokyo');
    $time = trim((string) ($car['auction_time'] ?? ''), " \t[]");

    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)
        && !((int) $m[1] === 0 && (int) $m[2] === 0)) {
        $t = DateTime::createFromFormat('d.m.Y H:i',
             $day . ' ' . sprintf('%02d:%02d', (int) $m[1], (int) $m[2]), $tz);
        return $t && $t->getTimestamp() < time();
    }
    $t = DateTime::createFromFormat('d.m.Y H:i', $day . ' 00:00', $tz);
    return $t && $t->getTimestamp() <= time();
}

function lotStatusLabel($car) {
    $s = strtolower(trim((string) ($car['status'] ?? '')));
    if ($s !== '' && $s !== 'available') {
        return statusLabel($s);
    }
    return lotHammerFallen($car) ? 'Awaiting result' : statusLabel($s);
}

/** The colour that goes with lotStatusLabel(). */
function lotStatusClass($car) {
    $s = strtolower(trim((string) ($car['status'] ?? '')));
    if ($s !== '' && $s !== 'available') {
        return statusClass($s);
    }
    return lotHammerFallen($car) ? 'is-gone' : statusClass($s);
}

/**
 * Generate unique filename
 * @return string
 */
function generateFilename() {
    return uniqid('sbk_') . '_' . time();
}

?>
