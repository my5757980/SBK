<?php
/**
 * Shared admin chrome. Set $page_title and $active before including.
 */
if (!function_exists('assetV')) {
    function assetV($path) {
        $full = dirname(__DIR__) . '/' . ltrim($path, '/');
        return '../' . $path . '?v=' . (is_file($full) ? filemtime($full) : time());
    }
}
$page_title = isset($page_title) ? $page_title : 'Admin';
$active     = isset($active) ? $active : '';

/**
 * One small line drawing per section.
 *
 * Drawn here rather than pulled from an icon font: nine glyphs is a few hundred
 * bytes inline against a webfont that blocks the first paint and arrives after
 * the words it belongs to. They inherit the link's colour, so the active item's
 * icon turns with its label rather than staying a different shade.
 *
 * All one weight, all on the same 24 grid. Mixing filled and outline marks in
 * one column is what makes a sidebar look assembled from three sources.
 */
function navIcon($k) {
    $p = array(
        'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'cars'        => '<path d="M5 17h14M4 17v-4l2-5h12l2 5v4"/><circle cx="7.5" cy="17" r="1.6"/><circle cx="16.5" cy="17" r="1.6"/><path d="M6 12h12"/>',
        'bids'        => '<path d="M13 5 8 10M15 7l-5 5M6 12l6 6M4 20h9"/><path d="M12.5 4.5 16 8"/>',
        'inquiries'   => '<path d="M4 5h16v11H9l-5 4z"/>',
        'orders'      => '<path d="M4 8l8-4 8 4v8l-8 4-8-4z"/><path d="M4 8l8 4 8-4M12 12v8"/>',
        'clients'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><path d="M16 5.5a3.2 3.2 0 0 1 0 6M18 20c0-2.6-1-4.4-2.5-5.4"/>',
        'staff'       => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.5 16.5c.6-1.6 1.9-2.4 3.5-2.4s2.9.8 3.5 2.4M15 10h4M15 14h3"/>',
        'roles'       => '<path d="M12 3l7 3v5.5c0 4.2-2.9 7.7-7 9.5-4.1-1.8-7-5.3-7-9.5V6z"/>',
        'permissions' => '<circle cx="8" cy="12" r="3.5"/><path d="M11.5 12H21M18 12v3.5M15 12v2.5"/>',
        'logout'      => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M9 8l-4 4 4 4M5 12h10"/>',
    );
    if (!isset($p[$k])) { return ''; }
    return '<svg class="nav-i" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $p[$k] . '</svg>';
}

/* The sidebar in two parts. Everything above the line is the day's work;
   everything below it is setting the place up, which is done rarely and by
   fewer people. They were one undivided list of nine, where Vehicles and
   Permissions sat next to each other as though they were the same kind of
   errand. Each entry still names the permission that reveals it, so a role
   only ever sees what it may actually open. */
$nav_groups = array(
    'The desk' => array(
        'dashboard' => array('Overview',  'dashboard.php',  null),
        'cars'      => array('Vehicles',  'cars.php',       'vehicles.view'),
        'bids'      => array('Bids',      'bids.php',       'bids.view'),
        'inquiries' => array('Enquiries', 'inquiries.php',  'enquiries.view'),
        'orders'    => array('Orders',    'orders.php',     'orders.view'),
        'clients'   => array('Clients',   'clients.php',    'clients.view'),
    ),
    'Setting up' => array(
        // A fourth element makes the entry a menu rather than a link. Staff
        // is two jobs - adding somebody and looking somebody up - done at
        // different times by different people, and they were behind a landing
        // page that existed only to offer the choice. The choice belongs in
        // the sidebar, where every other destination already is.
        'staff'       => array('Staff',       'staff-list.php',  'staff.view', array(
            array('Create Staff', 'staff-create.php', 'staff.create'),
            array('List Staff',   'staff-list.php',   'staff.view'),
        )),
        'roles'       => array('Roles',       'roles.php',       'roles.view'),
        'permissions' => array('Permissions', 'permissions.php', 'roles.view'),
    ),
);

/* What is waiting. A number beside Bids and Enquiries is the difference between
   a menu and a place of work - the desk can see there is something to do
   without opening each screen to find out. Only counted for a role allowed to
   see it: a count is information, and a hidden section should not leak one. */
// Which file is being viewed, so a sub-item can mark itself open. Pages set
// $active for the section; the sub-item is finer than that and needs no page
// to declare anything.
$nav_self = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

global $conn;
$nav_badge = array();
if (staffCan('bids.view')) {
    // The same narrowing the Bids screen uses. A count is information too: a
    // person who may not see other people's bids may not be told how many
    // there are - see bidScopeStaffId().
    $bidScope = bidScopeStaffId();
    $r = ($bidScope === null)
        ? $conn->query("SELECT COUNT(*) c FROM bids WHERE status = 'placed'")
        : $conn->query("SELECT COUNT(*) c FROM bids WHERE status = 'placed'
                          AND staff_id = " . (int) $bidScope);
    $nav_badge['bids'] = $r ? (int) $r->fetch_row()[0] : 0;
}
if (staffCan('enquiries.view')) {
    $r = $conn->query("SELECT COUNT(*) c FROM inquiries WHERE status = 'new'");
    $nav_badge['inquiries'] = $r ? (int) $r->fetch_row()[0] : 0;
}

$me = currentStaff();
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo sanitize($page_title); ?> — <?php echo SITE_NAME; ?></title>
<link rel="icon" type="image/png" href="<?php echo assetV('assets/img/favicon.png'); ?>">
<link rel="stylesheet" href="<?php echo assetV('assets/css/style.css'); ?>">
</head>
<body class="admin-body">

<header class="topbar">
  <div class="container">
    <a href="dashboard.php" class="brand">
      <img src="<?php echo assetV('assets/img/sbk-logo.png'); ?>" alt="SBK Global Auto Trading">
      <span class="brand-sub"><?php echo sanitize($me['role_label'] ?? 'Administration'); ?></span>
    </a>
    <nav class="topnav">
      <?php // The portal's own sections, from the desk.
            //
            // There was one link here, "View portal", and it went to the
            // auctions. Staff answering a question about what a model has sold
            // for had to land on the auctions and
            // find their way across - two clicks and a page load to reach a
            // place the customer reaches in one. The customer's top bar carries
            // both; so does this one. (Fixed price was the third, until the
            // owner had it removed on 13 September 2026.)
            //
            // New tab, as the single link always did: the desk is a place of
            // work and following a link out of it should not close it. ?>
      <?php if (staffCan('portal.view')): ?>
        <a href="../welcome.php"     target="_blank" rel="noopener">Auctions</a>
        <a href="../statistics.php"  target="_blank" rel="noopener">Statistics</a>
      <?php endif; ?>
      <span class="nav-user"><?php echo sanitize($_SESSION['admin_name'] ?? 'Admin'); ?></span>
      <a href="logout.php">Sign out</a>
    </nav>
  </div>
</header>

<div class="admin-shell">
  <aside class="admin-nav">
    <?php foreach ($nav_groups as $groupName => $items): ?>
      <?php
        $visible = array();
        foreach ($items as $k => $it) {
            if ($it[2] === null || staffCan($it[2])) { $visible[$k] = $it; }
        }
        if (!$visible) { continue; }   // a group nobody may open is not a heading
      ?>
      <div class="admin-nav-label"><?php echo sanitize($groupName); ?></div>
      <?php foreach ($visible as $key => $item): ?>
        <?php
          // A sub-item the role may not open is not shown, and an entry left
          // with no sub-items anybody may open falls back to being a link.
          $kids = array();
          foreach ((array) (isset($item[3]) ? $item[3] : array()) as $kid) {
              if ($kid[2] === null || staffCan($kid[2])) { $kids[] = $kid; }
          }
        ?>
        <?php if ($kids): ?>
          <details class="nav-drop"<?php echo $active === $key ? ' open' : ''; ?>>
            <summary class="<?php echo $active === $key ? 'on' : ''; ?>">
              <?php echo navIcon($key); ?>
              <span class="nav-t"><?php echo sanitize($item[0]); ?></span>
              <svg class="nav-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                   aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </summary>
            <div class="nav-kids">
              <?php foreach ($kids as $kid): ?>
                <a href="<?php echo $kid[1]; ?>"
                   class="<?php echo $nav_self === $kid[1] ? 'on' : ''; ?>">
                  <span class="nav-t"><?php echo sanitize($kid[0]); ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </details>
        <?php else: ?>
          <a href="<?php echo $item[1]; ?>" class="<?php echo $active === $key ? 'on' : ''; ?>">
            <?php echo navIcon($key); ?>
            <span class="nav-t"><?php echo sanitize($item[0]); ?></span>
            <?php if (!empty($nav_badge[$key])): ?>
              <span class="nav-b"><?php echo (int) $nav_badge[$key]; ?></span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="admin-nav-foot">
      <a href="logout.php">
        <?php echo navIcon('logout'); ?>
        <span class="nav-t">Sign out</span>
      </a>
    </div>
  </aside>

  <main class="admin-main">
