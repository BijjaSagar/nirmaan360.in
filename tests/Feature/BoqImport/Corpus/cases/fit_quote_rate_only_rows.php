<?php

return [
    'about' => 'Fit 2.2: QRO rows with no formula, and words with no figure in the quantity cell ("As reqd.", "Nil"), are rate-only lines; nothing is refused for them, and no quantity is made up. A figure among words is still refused.',
    'rows' => [
        ['Item No.', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['1', 'Sample pipes 110 mm', 'Rmt', 120, 450, 54000],
        ['2', 'Sample bends 110 mm', 'Nos', 'QRO', 300, null],
        ['3', 'Sample tees 110 mm', 'Nos', 'Q.R.O.', 350, 0],
        ['4', 'Sample cutting of old pipes', 'Rmt', 'As reqd.', 80, null],
        ['5', 'Sample end caps', 'Nos', 'Nil', 60, null],
        ['6', 'Sample valves', 'Nos', 'about 10', 1200, 12000],
    ],
];
