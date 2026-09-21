<?php
/**
 * SBK Auction — staff
 *
 * This was a landing page offering a choice between adding somebody and looking
 * somebody up. The choice is in the sidebar now, where every other destination
 * already is, so the page has nothing left to do - it only forwards, so that an
 * old link or a typed URL still arrives somewhere real.
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';

requirePermission('staff.view');

header('Location: staff-list.php');
exit;
