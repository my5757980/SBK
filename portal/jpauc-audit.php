<?php
/**
 * Hold the source up against the catalogue and report where they differ.
 *
 * "Some cars have everything and some are missing half of it" is answerable
 * two ways and they need telling apart: the source never published the field,
 * or the reading dropped it. Counting empty columns cannot tell you which -
 * only reading the same vehicles from both sides can.
 *
 * So this fetches real pages, parses them with the harvester's own parser,
 * finds each vehicle in the catalogue by the same day + hall + lot it is
 * stored under, and lists every field where the two disagree.
 *
 *     jpauc-audit.php?t=TOKEN&sec=auction&lot=4000&pages=3
 */

define('JPAUC_HARVEST_LIB', 1);          // parser only: no token, no budget, no writing
require_once __DIR__ . '/jpauc-harvest.php';

if (!hash_equals(HARVEST_TOKEN, (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(280);

global $conn;

$secs   = sections();
$which  = isset($secs[(string) ($_GET['sec'] ?? '')]) ? (string) $_GET['sec'] : 'auction';
$cfg    = $secs[$which];
$lotFrom = max(1, (int) ($_GET['lot'] ?? 1));
$pages   = max(1, min(6, (int) ($_GET['pages'] ?? 2)));

/** What the catalogue holds for one vehicle, or null. */
function stored($conn, $day, $hall, $lot) {
    $id = 'jp-' . substr(sha1($day . '|' . $hall . '|' . $lot), 0, 24);
    $st = $conn->prepare("SELECT year, chassis, transmission, engine_cc, mileage,
                                 color, rating, grade, price, last_updated
                          FROM cars WHERE car_id = ? LIMIT 1");
    $st->bind_param('s', $id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row;
}

/** The source's text for a field, reduced to what the database would hold. */
function asStored($field, $raw) {
    $raw = trim((string) $raw);
    switch ($field) {
        case 'year':
        case 'engine_cc':
        case 'mileage':
        case 'price':
            $n = (int) preg_replace('/\D+/', '', $raw);
            return $n ?: 0;
        default:
            return $raw;
    }
}

$fields = array(
    'year'         => 'year',
    'chassis'      => 'chassis',
    'transmission' => 'shift',
    'engine_cc'    => 'cc',
    'mileage'      => 'km',
    'color'        => 'color',
    'rating'       => 'grade',        // auction grade
    'grade'        => 'modelGrade',   // model grade
    'price'        => 'price',
);

$seen = 0;
$missing = array();     // source had it, we do not
$differs = array();     // both have it, they disagree
$notStored = 0;         // vehicle not in the catalogue at all
$lines = array();

for ($p = 1; $p <= $pages; $p++) {
    list($html, $err) = fetchPage($cfg['url'], $lotFrom, $p);
    if ($html === null) {
        echo "read fail: {$err}\n";
        break;
    }
    $cars = call_user_func($cfg['parse'], $html);
    if (!$cars) {
        break;
    }

    foreach ($cars as $c) {
        $seen++;
        $row = stored($conn, $c['day'], $c['hall'], $c['lot']);
        if (!$row) {
            $notStored++;
            continue;
        }
        $bad = array();
        foreach ($fields as $col => $key) {
            $src = asStored($col, $c[$key]);
            $db  = in_array($col, array('year', 'engine_cc', 'mileage', 'price'), true)
                 ? (int) $row[$col] : trim((string) $row[$col]);

            $srcEmpty = ($src === '' || $src === 0 || $src === 'N/A');
            $dbEmpty  = ($db === '' || $db === 0);

            if (!$srcEmpty && $dbEmpty) {
                $missing[$col] = ($missing[$col] ?? 0) + 1;
                $bad[] = $col . ': source[' . $src . '] db[GHAYAB]';
            } elseif (!$srcEmpty && !$dbEmpty && (string) $src !== (string) $db) {
                $differs[$col] = ($differs[$col] ?? 0) + 1;
                $bad[] = $col . ': source[' . $src . '] db[' . $db . ']';
            }
        }
        if ($bad && count($lines) < 12) {
            $lines[] = sprintf("  %s %s lot %s  %s %s\n     %s",
                $c['day'], $c['hall'], $c['lot'], $c['maker'], $c['model'], implode('  |  ', $bad));
        }
    }
    sleep(1);
}

printf("=== %s, lots %d..%d, %d page ===\n", $which, $lotFrom, $lotFrom + LOT_BATCH - 1, $pages);
printf("source par gaadiyan   : %d\n", $seen);
printf("catalogue mein nahi   : %d\n\n", $notStored);

echo "--- source ke paas hai, hamare paas GHAYAB ---\n";
if ($missing) { arsort($missing); foreach ($missing as $k => $n) printf("  %-14s %5d\n", $k, $n); }
else { echo "  koi nahi\n"; }

echo "\n--- dono ke paas hai magar FARQ hai ---\n";
if ($differs) { arsort($differs); foreach ($differs as $k => $n) printf("  %-14s %5d\n", $k, $n); }
else { echo "  koi nahi\n"; }

if ($lines) {
    echo "\n--- misalain ---\n" . implode("\n", $lines) . "\n";
}
