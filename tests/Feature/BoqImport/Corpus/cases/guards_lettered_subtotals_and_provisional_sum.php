<?php

return [
    'about' => '"A | Sub Total (A)" is a subtotal; a lettered provisional sum with nothing under it after the last section is a line of its own.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['A', 'EARTH WORK'],
        ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
        ['2', 'Sample PCC', 'Cum', 12, 5400, 64800],
        ['A', 'Sub Total (A)', null, null, null, 85600],
        ['B', 'MASONRY'],
        ['3', 'Sample brickwork', 'Cum', 45, 6100, 274500],
        ['B', 'Sub Total (B)', null, null, null, 274500],
        ['C', 'Provisional sum for electrical works', null, null, null, 500000],
        [null, 'Grand Total', null, null, null, 860100],
    ],
];
