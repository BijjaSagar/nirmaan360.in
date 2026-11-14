<?php

return [
    'about' => 'Research 3(c): codes are text: 011010 typed as text and 11010 shown as 011010 keep their zero, 21.30 shown with two decimals keeps its zero, 13.48A keeps its letter, "17.28.1 ." and "A 9. 80" lose their stray dot and space, a Unicode hyphen becomes "-", and a serial 1.0 is 1.',
    'file' => [
        'sheets' => ['Sheet1' => [
            ['Sl No', 'Code', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1.0, '011010', 'Sample earthwork in embankment', 'Cum', 100, 180, 18000],
            [2.0, 11020, 'Sample earthwork in cutting', 'Cum', 50, 200, 10000],
            [3.0, 21.3, 'Sample signage boards', 'Each', 6, 1500, 9000],
            [4.0, '13.48A', 'Sample textured paint', 'Sqm', 100, 150, 15000],
            [5.0, '17.28.1 .', 'Sample PVC water tank', 'Litre', 1000, 9, 9000],
            [6.0, 'A 9. 80', 'Sample hill area item', 'Sqm', 10, 500, 5000],
            [7.0, 'WG‐PW/PW', 'Sample point wiring', 'Point', 20, 900, 18000],
        ]],
        'formats' => ['Sheet1' => ['B3' => '000000', 'B4' => '0.00']],
    ],
];
