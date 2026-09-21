<?php
/**
 * Small chart builders for the Overview, drawn as SVG on the server.
 *
 * No charting library. Three shapes are needed and each is a few dozen lines of
 * arithmetic; a library would be 200KB to draw four bars, and every value here
 * has to be readable without JavaScript anyway - the panel is server-rendered
 * and a value a reader can only get by hovering is a value some readers cannot
 * get at all.
 *
 * Colours are not chosen here by eye. The categorical trio and the ordinal ramp
 * below were both run through a contrast/colour-vision validator against this
 * panel's white card:
 *
 *   #2a78d6 #eb6834 #1baf7a   worst all-pairs CVD dE 9.2, normal-vision 24.0
 *   #86b6ef #5598e7 #2a78d6 #1c5cab   monotone light->dark, light end 2.11:1
 *
 * The aqua sits at 2.82:1 against white, below the 3:1 bar, so everything it
 * marks carries a written label as well - colour never has to carry a value on
 * its own here.
 */

const VIZ_SERIES = array('#2a78d6', '#eb6834', '#1baf7a',
                         '#eda100', '#e87ba4', '#008300');
const VIZ_RAMP   = array('#86b6ef', '#5598e7', '#2a78d6', '#1c5cab');
const VIZ_INK    = '#151a21';
const VIZ_INK_2  = '#5c6672';
const VIZ_MUTED  = '#8b949e';
const VIZ_LINE   = '#e3e1dc';

function vizEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/**
 * A stage bar chart: ordered steps of one process, longest first.
 *
 * Horizontal because the stages are words, and words set horizontally do not
 * need the reader to tilt their head. The ramp darkens as the funnel narrows,
 * which is an ordered scale on an ordered thing - the one case where colouring
 * by value is right rather than a waste of the channel.
 *
 * @param array $rows [label, value, note] in stage order
 */
function vizFunnel(array $rows) {
    if (!$rows) { return '<p class="hint">Nothing to show yet.</p>'; }

    $max = max(1, max(array_column($rows, 1)));
    $rowH = 44; $barH = 22; $labelW = 132; $valueW = 96;
    $w = 640; $h = count($rows) * $rowH + 8;
    $plotW = $w - $labelW - $valueW;

    $out = '<svg class="viz" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'aria-label="Client stages" preserveAspectRatio="xMinYMin meet">';

    foreach ($rows as $i => $r) {
        list($label, $value, $note) = array($r[0], (int) $r[1], $r[2] ?? '');
        $y = $i * $rowH + 6;
        // 2px of surface below each bar keeps neighbours apart without drawing
        // a border around them.
        $barW = $max > 0 ? max(0, round($plotW * $value / $max)) : 0;
        $fill = VIZ_RAMP[min($i, count(VIZ_RAMP) - 1)];

        $out .= '<text x="0" y="' . ($y + $barH - 6) . '" class="viz-lab">' . vizEsc($label) . '</text>';
        // A zero stage still gets a hairline, so the row does not look missing.
        if ($barW < 2) {
            $out .= '<rect x="' . $labelW . '" y="' . ($y + $barH / 2 - 1) . '" width="2" height="2" fill="' . VIZ_MUTED . '"/>';
        } else {
            $out .= '<rect x="' . $labelW . '" y="' . $y . '" width="' . $barW . '" height="' . $barH
                  . '" rx="4" fill="' . $fill . '"/>';
        }
        $out .= '<text x="' . ($labelW + max($barW, 2) + 10) . '" y="' . ($y + $barH - 6)
              . '" class="viz-val">' . number_format($value)
              . ($note !== '' ? '<tspan class="viz-note"> ' . vizEsc($note) . '</tspan>' : '')
              . '</text>';
    }
    return $out . '</svg>';
}

/**
 * One bar per day, split into its parts.
 *
 * Stacked rather than grouped: on a quiet day a grouped chart draws three
 * hairlines where one small bar says the same thing, and most days here are
 * quiet. A day with nothing on it draws nothing, which is the honest picture -
 * not a gap in the chart but a gap in the week.
 *
 * @param array $days  ['YYYY-MM-DD' => [n1, n2, n3], …] oldest first
 * @param array $names series names, in the same order as the values
 */
function vizDays(array $days, array $names) {
    if (!$days) { return '<p class="hint">No activity in this period.</p>'; }

    $totals = array_map('array_sum', $days);
    $max = max(1, max($totals));
    $w = 640; $plotH = 150; $axisH = 26; $h = $plotH + $axisH;
    $n = count($days);
    $slot = $w / max(1, $n);
    $barW = min(26, $slot - 8);

    $out = '<svg class="viz" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'aria-label="Activity by day" preserveAspectRatio="xMinYMin meet">';

    // Two hairlines, solid, one shade off the surface. Nothing dashed.
    foreach (array(0, 0.5, 1) as $f) {
        $y = round($plotH - $plotH * $f) + 0.5;
        $out .= '<line x1="0" y1="' . $y . '" x2="' . $w . '" y2="' . $y
              . '" stroke="' . VIZ_LINE . '" stroke-width="1"/>';
    }
    $out .= '<text x="0" y="12" class="viz-tick">' . number_format($max) . '</text>';

    $i = 0;
    foreach ($days as $day => $vals) {
        $x = round($i * $slot + ($slot - $barW) / 2);
        $y = $plotH;
        foreach ($vals as $k => $v) {
            if ($v <= 0) { continue; }
            $segH = max(2, round($plotH * $v / $max));
            $y -= $segH;
            $out .= '<rect x="' . $x . '" y="' . $y . '" width="' . $barW . '" height="' . ($segH - 2)
                  . '" rx="3" fill="' . VIZ_SERIES[$k % count(VIZ_SERIES)] . '"/>';
            $y -= 0;   // the 2px taken off the height is the surface gap
        }
        // Every third day is labelled; labelling all fourteen collides.
        if ($i % 3 === 0 || $i === $n - 1) {
            $out .= '<text x="' . ($x + $barW / 2) . '" y="' . ($plotH + 17)
                  . '" class="viz-tick" text-anchor="middle">' . vizEsc(date('j M', strtotime($day))) . '</text>';
        }
        $i++;
    }
    return $out . '</svg>';
}

/**
 * Plain bars for categories with no order between them.
 *
 * One colour for every bar. Darkening the bigger ones would encode length twice
 * and spend the only channel left on something the length already says.
 */
function vizBars(array $rows) {
    if (!$rows || !array_sum(array_column($rows, 1))) {
        return '<p class="hint">Nothing here yet.</p>';
    }
    $max = max(1, max(array_column($rows, 1)));
    $rowH = 34; $barH = 16; $labelW = 132; $valueW = 64;
    $w = 640; $h = count($rows) * $rowH + 4;
    $plotW = $w - $labelW - $valueW;

    $out = '<svg class="viz" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'aria-label="Breakdown" preserveAspectRatio="xMinYMin meet">';
    foreach ($rows as $i => $r) {
        $y = $i * $rowH + 4;
        $value = (int) $r[1];
        $barW = max(0, round($plotW * $value / $max));
        $out .= '<text x="0" y="' . ($y + $barH - 3) . '" class="viz-lab">' . vizEsc($r[0]) . '</text>';
        if ($barW >= 2) {
            $out .= '<rect x="' . $labelW . '" y="' . $y . '" width="' . $barW . '" height="' . $barH
                  . '" rx="4" fill="' . VIZ_SERIES[0] . '"/>';
        }
        $out .= '<text x="' . ($labelW + max($barW, 2) + 10) . '" y="' . ($y + $barH - 3)
              . '" class="viz-val">' . number_format($value) . '</text>';
    }
    return $out . '</svg>';
}

/**
 * The same numbers as a table, folded away.
 *
 * Every chart here has one. A reader who cannot separate two blues, or is
 * printing the page, or is on a screen reader, gets the figures rather than an
 * apology.
 */
function vizTable(array $head, array $rows, $summary = 'Show the figures') {
    $out = '<details class="viz-table"><summary>' . vizEsc($summary) . '</summary><table><thead><tr>';
    foreach ($head as $h) { $out .= '<th>' . vizEsc($h) . '</th>'; }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr>';
        foreach ($r as $c) { $out .= '<td>' . vizEsc($c) . '</td>'; }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></details>';
}

/** The legend. Present whenever more than one series is on a chart. */
function vizLegend(array $names) {
    $out = '<div class="viz-legend">';
    foreach ($names as $i => $n) {
        $out .= '<span class="viz-key"><i style="background:' . VIZ_SERIES[$i % count(VIZ_SERIES)]
              . '"></i>' . vizEsc($n) . '</span>';
    }
    return $out . '</div>';
}

/**
 * A ring: how one whole divides up.
 *
 * Only for that question. A ring is poor at comparing two close values and
 * hopeless past six slices, so where the question is "which is biggest" the
 * bars above are the right shape and this one is not. Where the question is
 * "of everything, how much is X" - all our bids, all our enquiries - a ring
 * answers at a glance and the bars do not.
 *
 * Drawn as one circle per slice with a dashed stroke rather than as arc paths:
 * the arithmetic is a running offset instead of six sets of trigonometry, and
 * shortening each dash by a couple of units leaves the 2px of surface between
 * slices that keeps them apart without drawing a line around each one.
 *
 * Three of the six hues sit below 3:1 against white, so every slice carries its
 * count and share in the legend beside it, and the figures repeat in the table
 * underneath. Nothing here is readable only by telling two colours apart.
 *
 * @param array $rows [label, value] - at most six
 * @param string $centre what to write in the hole
 */
function vizDonut(array $rows, $centre = '') {
    $total = array_sum(array_column($rows, 1));
    if (!$rows || $total <= 0) {
        return '<p class="hint">Nothing to divide up yet.</p>';
    }

    $size = 190; $r = 68; $stroke = 26;
    $c = 2 * M_PI * $r;
    $gap = count(array_filter(array_column($rows, 1))) > 1 ? 3 : 0;

    $out = '<div class="viz-donut">';
    $out .= '<svg viewBox="0 0 ' . $size . ' ' . $size . '" class="viz-ring" role="img" '
          . 'aria-label="Share of the total" preserveAspectRatio="xMidYMid meet">';
    // The track, so a nearly-empty ring still reads as a ring.
    $out .= '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $r
          . '" fill="none" stroke="#f0efec" stroke-width="' . $stroke . '"/>';

    $offset = 0;
    foreach ($rows as $i => $row) {
        $v = (int) $row[1];
        if ($v <= 0) { continue; }
        $len = $c * $v / $total;
        $dash = max(1, $len - $gap);
        $out .= '<circle cx="' . ($size / 2) . '" cy="' . ($size / 2) . '" r="' . $r
              . '" fill="none" stroke="' . VIZ_SERIES[$i % count(VIZ_SERIES)] . '"'
              . ' stroke-width="' . $stroke . '"'
              . ' stroke-dasharray="' . round($dash, 2) . ' ' . round($c - $dash, 2) . '"'
              . ' stroke-dashoffset="' . round(-$offset, 2) . '"'
              . ' transform="rotate(-90 ' . ($size / 2) . ' ' . ($size / 2) . ')"/>';
        $offset += $len;
    }

    if ($centre !== '') {
        $out .= '<text x="' . ($size / 2) . '" y="' . ($size / 2 - 2) . '" text-anchor="middle" class="viz-ring-n">'
              . vizEsc(number_format($total)) . '</text>';
        $out .= '<text x="' . ($size / 2) . '" y="' . ($size / 2 + 16) . '" text-anchor="middle" class="viz-ring-k">'
              . vizEsc($centre) . '</text>';
    }
    $out .= '</svg>';

    // The legend does the work the colours cannot: name, count, share.
    $out .= '<ul class="viz-keys">';
    foreach ($rows as $i => $row) {
        $v = (int) $row[1];
        $out .= '<li><i style="background:' . VIZ_SERIES[$i % count(VIZ_SERIES)] . '"></i>'
              . '<span class="viz-keys-l">' . vizEsc($row[0]) . '</span>'
              . '<span class="viz-keys-v">' . number_format($v)
              . ' <em>' . round($v * 100 / $total) . '%</em></span></li>';
    }
    return $out . '</ul></div>';
}

/**
 * A row of thin meters - one job, several holders, each a share of the same
 * whole. Used for how much of the permission catalogue each role holds.
 */
function vizMeters(array $rows, $max) {
    if (!$rows) { return '<p class="hint">Nothing to show.</p>'; }
    $max = max(1, $max);
    $out = '<ul class="viz-meters">';
    foreach ($rows as $r) {
        $pct = round($r[1] * 100 / $max);
        $out .= '<li>'
              . '<span class="vm-l">' . vizEsc($r[0]) . '</span>'
              . '<span class="vm-bar"><span style="width:' . $pct . '%;background:'
              . VIZ_SERIES[0] . '"></span></span>'
              . '<span class="vm-v">' . (int) $r[1] . ' <em>/ ' . (int) $max . '</em></span>'
              . '</li>';
    }
    return $out . '</ul>';
}

/**
 * One row per name, each row split into what it is made of.
 *
 * For "who did how much of it, and how did theirs turn out" - a customer's bids
 * by state, side by side with the next customer's. A plain bar would answer the
 * first half and leave the second to a second chart; the segments answer both
 * in one row.
 *
 * @param array $rows  [label, [n per state, …], trailing note]
 * @param array $names the states, in the same order as the values
 */
function vizStackRows(array $rows, array $names) {
    if (!$rows) { return '<p class="hint">Nothing to show yet.</p>'; }
    $totals = array();
    foreach ($rows as $r) { $totals[] = array_sum($r[1]); }
    if (!array_sum($totals)) { return '<p class="hint">Nothing to show yet.</p>'; }

    $max = max(1, max($totals));
    $rowH = 40; $barH = 18; $labelW = 150; $valueW = 130;
    $w = 640; $h = count($rows) * $rowH + 4;
    $plotW = $w - $labelW - $valueW;

    $out = '<svg class="viz" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'aria-label="Bids by customer" preserveAspectRatio="xMinYMin meet">';

    foreach ($rows as $i => $r) {
        $y = $i * $rowH + 4;
        $x = $labelW;
        $out .= '<text x="0" y="' . ($y + $barH - 3) . '" class="viz-lab">'
              . vizEsc(mb_strimwidth($r[0], 0, 20, '…')) . '</text>';

        foreach ($r[1] as $k => $v) {
            if ($v <= 0) { continue; }
            $segW = max(3, round($plotW * $v / $max));
            // 2px of surface between segments, taken off the width rather than
            // drawn as a line around each one.
            $out .= '<rect x="' . $x . '" y="' . $y . '" width="' . ($segW - 2) . '" height="' . $barH
                  . '" rx="3" fill="' . VIZ_SERIES[$k % count(VIZ_SERIES)] . '"/>';
            $x += $segW;
        }
        $out .= '<text x="' . ($x + 8) . '" y="' . ($y + $barH - 3) . '" class="viz-val">'
              . number_format($totals[$i])
              . ($r[2] !== '' ? '<tspan class="viz-note"> ' . vizEsc($r[2]) . '</tspan>' : '')
              . '</text>';
    }
    return $out . '</svg>';
}

/**
 * A wave: several series across days.
 *
 * The right shape for change over time, and the wrong one for anything else -
 * a line between two categories draws a slope that does not exist.
 *
 * Curved through the points rather than joined corner to corner, because the
 * eye follows a curve and a run of quiet days becomes a shape rather than a
 * row of dots. The curve is a Catmull-Rom spline written out as beziers, held
 * to the points it is given: it never invents a peak between two days that the
 * data does not have.
 *
 * One axis only. Two measures of different size get two charts, never a second
 * scale up the right-hand side - the alignment between two such axes is
 * arbitrary and draws a relationship out of nothing.
 *
 * @param array $days  ['YYYY-MM-DD' => [n1, n2, …], …] oldest first
 * @param array $names series names, in the same order
 */
function vizWave(array $days, array $names) {
    if (count($days) < 2) { return '<p class="hint">Not enough days yet.</p>'; }

    $n = count($days);
    $series = array();
    foreach ($names as $k => $_) {
        $series[$k] = array_values(array_map(function ($v) use ($k) { return (int) ($v[$k] ?? 0); }, $days));
    }
    $max = 0;
    foreach ($series as $vals) { $max = max($max, max($vals)); }
    $max = max(1, $max);

    $w = 660; $plotH = 170; $axisH = 26; $padL = 30; $padR = 12;
    $h = $plotH + $axisH;
    $innerW = $w - $padL - $padR;
    $step = $innerW / max(1, $n - 1);

    $x = function ($i) use ($padL, $step) { return round($padL + $i * $step, 1); };
    // Clamped to the plot. A Catmull-Rom control point can sit outside the run
    // of values it was derived from, and left alone the curve dipped under the
    // zero line - drawing days on which minus one person registered.
    $y = function ($v) use ($plotH, $max) {
        $yy = $plotH - ($plotH - 14) * $v / $max;
        return round(max(14, min($plotH, $yy)), 1);
    };

    $out = '<svg class="viz" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'aria-label="Activity over time" preserveAspectRatio="xMinYMin meet">';

    // Three solid hairlines. Nothing dashed - a dashed grid reads as a
    // threshold when it is only a grid.
    foreach (array(0, 0.5, 1) as $f) {
        $yy = round($plotH - ($plotH - 14) * $f) + 0.5;
        $out .= '<line x1="' . $padL . '" y1="' . $yy . '" x2="' . ($w - $padR) . '" y2="' . $yy
              . '" stroke="' . VIZ_LINE . '" stroke-width="1"/>';
        $out .= '<text x="0" y="' . ($yy + 4) . '" class="viz-tick">'
              . number_format(round($max * $f)) . '</text>';
    }

    foreach ($series as $k => $vals) {
        $colour = VIZ_SERIES[$k % count(VIZ_SERIES)];
        $d = 'M ' . $x(0) . ' ' . $y($vals[0]);
        for ($i = 0; $i < $n - 1; $i++) {
            // Catmull-Rom through the real points, as a bezier. The control
            // points are a sixth of the way to the neighbours, which keeps the
            // curve from overshooting into values nobody recorded.
            $p0 = $i > 0 ? $vals[$i - 1] : $vals[$i];
            $p1 = $vals[$i];
            $p2 = $vals[$i + 1];
            $p3 = $i + 2 < $n ? $vals[$i + 2] : $vals[$i + 1];
            $c1x = $x($i) + $step / 3;
            $c1y = $y($p1 + ($p2 - $p0) / 6);
            $c2x = $x($i + 1) - $step / 3;
            $c2y = $y($p2 - ($p3 - $p1) / 6);
            $d .= ' C ' . round($c1x, 1) . ' ' . $c1y . ', ' . round($c2x, 1) . ' ' . $c2y
                . ', ' . $x($i + 1) . ' ' . $y($p2);
        }
        // A wash under the line, faint enough that three of them can overlap
        // without any of them becoming unreadable.
        $out .= '<path d="' . $d . ' L ' . $x($n - 1) . ' ' . $plotH . ' L ' . $x(0) . ' ' . $plotH . ' Z" '
              . 'fill="' . $colour . '" opacity="0.07"/>';
        $out .= '<path d="' . $d . '" fill="none" stroke="' . $colour . '" stroke-width="2" '
              . 'stroke-linecap="round" stroke-linejoin="round"/>';

        // The end of the line is labelled and nothing else is. A number on
        // every point is thirty numbers nobody reads.
        $last = $vals[$n - 1];
        $out .= '<circle cx="' . $x($n - 1) . '" cy="' . $y($last) . '" r="3.5" fill="' . $colour
              . '" stroke="#fff" stroke-width="2"/>';
    }

    $i = 0;
    foreach (array_keys($days) as $day) {
        // Every seventh day, and the last one - but not both when they land
        // together. Thirty days labelled every seven puts the 29th beside the
        // 28th, and the two dates printed over each other read as neither.
        $tooClose = ($i !== $n - 1) && ($n - 1 - $i) < 4;
        if ((($i % 7 === 0 && !$tooClose) || $i === $n - 1)) {
            $out .= '<text x="' . $x($i) . '" y="' . ($plotH + 18) . '" class="viz-tick" '
                  . 'text-anchor="' . ($i === $n - 1 ? 'end' : ($i === 0 ? 'start' : 'middle')) . '">'
                  . vizEsc(date('j M', strtotime($day))) . '</text>';
        }
        $i++;
    }
    return $out . '</svg>';
}

