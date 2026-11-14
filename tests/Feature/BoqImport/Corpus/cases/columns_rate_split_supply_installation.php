<?php

return [
    'about' => 'A rate split into supply and installation under merged headings: two rate columns wait for the person; nothing is read as one part.',
    'file' => [
        'sheets' => ['Sheet1' => [
            ['Sr No', 'Description', 'Unit', 'Qty', 'Rate (Rs.)', null, 'Amount (Rs.)', null, null],
            [null, null, null, null, 'Supply', 'Installation', 'Supply', 'Installation', 'Total'],
            [1, 'Sample 25 mm CPVC pipe', 'Rmt', 100, 180, 60, 18000, 6000, 24000],
            [2, 'Sample ball valve 25 mm', 'Nos', 10, 900, 150, 9000, 1500, 10500],
            [3, 'Sample wash basin', 'Nos', 4, 3500, 800, 14000, 3200, 17200],
            [null, 'Total', null, null, null, null, 41000, 10700, 51700],
        ]],
        'merges' => ['Sheet1' => ['A1:A2', 'B1:B2', 'C1:C2', 'D1:D2', 'E1:F1', 'G1:I1']],
    ],
];
