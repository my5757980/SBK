<?php
/**
 * The marks a Japanese auction inspector writes on the sheet, and what each
 * one means.
 *
 * Every lot page carries this because the sheet is the only honest account of
 * a car's condition, and it is written in a shorthand a buyer outside Japan
 * has no way to read. The codes are the industry's, not the feed's — they do
 * not change and they come from no data source, so they are simply stated here.
 */
$inspectionMarks = array(
    array('A1', 'Small Scratch'),
    array('A2', 'Scratch'),
    array('A3', 'Big Scratch'),
    array('E1', 'Few Dimples'),
    array('E2', 'Several Dimples'),
    array('E3', 'Many Dimples'),
    array('U1', 'Small Dent'),
    array('U2', 'Dent'),
    array('U3', 'Big Dent'),
    array('B1', 'Distortion on (radiator) core support or back panel (approximately size of a thumb)'),
    array('B2', 'Big Distortion on (radiator) core support or back panel'),
    array('Y1', 'Small Hole or Crack'),
    array('Y2', 'Hole or Crack'),
    array('Y3', 'Big Hole or Crack'),
    array('W1', 'Repair Mark / Wave (hardly detectable)'),
    array('W2', 'Repair Mark / Wave'),
    array('S1', 'Rust'),
    array('S2', 'Heavy Rust'),
    array('C1', 'Corrosion'),
    array('C2', 'Heavy Corrosion'),
    array('X',  'Need to be replaced'),
    array('XX', 'Replaced'),
    array('X1', 'Small Crack on Windshield (approximately 1cm)'),
    array('R',  'Repaired Crack on Windshield'),
    array('RX', 'Repaired Crack on Windshield (needs to be replaced)'),
);
?>
<section class="pb-legend">
  <h2>Inspection Sheet Description</h2>
  <div class="pb-legend-grid">
    <?php foreach ($inspectionMarks as $m): ?>
      <div class="pb-mark">
        <span class="c"><?php echo sanitize($m[0]); ?></span>
        <span class="d"><?php echo sanitize($m[1]); ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>
