<?php

return [
    'about' => 'Fit 2.2: part totals, then a summary block repeating each part\'s total, the total of the summary and a tax line: no summary row becomes a line and nothing is counted twice.',
    'rows' => [
        ['Item No.', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['A', 'CIVIL'],
        ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
        ['2', 'Sample PCC', 'Cum', 12, 5400, 64800],
        [null, 'Total of Part A', null, null, null, 85600],
        ['B', 'ELECTRICAL'],
        ['3', 'Sample point wiring', 'Point', 40, 900, 36000],
        [null, 'Total of Part B', null, null, null, 36000],
        [null, 'SUMMARY'],
        ['A', 'Civil works', null, null, null, 85600],
        ['B', 'Electrical works', null, null, null, 36000],
        [null, 'Total', null, null, null, 121600],
        [null, 'GST @ 18%', null, null, null, 21888],
        [null, 'Grand Total', null, null, null, 143488],
    ],
];
