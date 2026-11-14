<?php

return [
    'about' => 'Fit 2.2: part 1 ends with "Grand Total", the same label as the sheet\'s last total: reading goes on through parts 2 and 3, the first is a part total, and the last total is checked against every line.',
    'rows' => [
        ['Item No.', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['PART 1', 'CIVIL WORKS'],
        ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
        ['2', 'Sample PCC', 'Cum', 12, 5400, 64800],
        [null, 'Grand Total', null, null, null, 85600],
        ['PART 2', 'PLUMBING'],
        ['3', 'Sample CPVC pipe', 'Rmt', 100, 240, 24000],
        [null, 'Total of Part 2', null, null, null, 24000],
        ['PART 3', 'ELECTRICAL'],
        ['4', 'Sample point wiring', 'Point', 40, 900, 36000],
        [null, 'Total of Part 3', null, null, null, 36000],
        [null, 'Grand Total', null, null, null, 145600],
    ],
];
