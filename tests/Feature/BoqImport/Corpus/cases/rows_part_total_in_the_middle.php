<?php

return [
    'about' => 'A part total in the middle ("Total Amount of Part A") is not the end of the sheet; lettered sections, a specification with lettered parts, a grand total.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['A', 'CIVIL WORKS'],
        ['1', 'Sample excavation', 'Cum', 80, 260, 20800],
        ['2', 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
        [null, 'Total Amount of Part A', null, null, null, 85600],
        ['B', 'FINISHING WORKS'],
        ['3', 'Providing and applying sample plaster in cement mortar 1:4 on walls of the sample building, complete'],
        [null, '(a) 12 mm thick on inside walls', 'Sqm', 600, 230, 138000],
        [null, '(b) 20 mm thick on outside walls', 'Sqm', 300, 280, 84000],
        ['4', 'Sample painting', 'Sqm', 900, 120, 108000],
        [null, 'Total Amount of Part B', null, null, null, 330000],
        [null, 'Grand Total (A+B)', null, null, null, 415600],
    ],
];
