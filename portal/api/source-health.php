<?php
/**
 * The two ID signals as JSON, so a page left open turns red (or green again)
 * without anyone reloading it. Staff only - see includes/source-health.php.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/source-health.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(array('error' => 'staff only'));
    exit;
}
if (function_exists('session_write_close')) {
    session_write_close();
}
echo json_encode(sourceHealthAll());
