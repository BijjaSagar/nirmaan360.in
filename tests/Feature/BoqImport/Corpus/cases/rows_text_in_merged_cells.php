<?php

return [
    'about' => 'An item\'s serial and text merged down over the rows to the one with its figures: the text belongs to that row.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['1', 'Providing and laying sample vitrified tiles of approved make on floor, complete'],
        [],
        [null, null, 'Sqm', 150, 1200, 180000],
        ['2', 'Sample skirting tiles 100 mm high', 'Rmt', 200, 300, 60000],
        ['3', 'Sample granite sill'],
        [null, null, 'Rmt', 40, 1500, 60000],
        [null, 'Total', null, null, null, 300000],
    ],
    'merges' => ['A2:A4', 'B2:B4', 'A6:A7', 'B6:B7'],
];
