<?php

return [
    'about' => 'Research 3(d): a unit for many ("100 Sqm", "1000 Nos", "PER TEN SQUARE (metre)", "Dozen") is kept as its own field and shown: the rate is divided where the amount says the quantity is in single units, kept where the amount says the quantity is counted in hundreds, and divided with a note where no amount says.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample whitewash on walls', '100 Sqm', 900, 450, 4050],
        [2, 'Sample bricks of first class', '1000 Nos', 12000, 8000, 96000],
        [3, 'Sample distemper on ceiling', 'PER TEN SQUARE (metre)', 200, 300, 6000],
        [4, 'Sample door stoppers', 'Dozen', 24, 600, 1200],
        [5, 'Sample painting of grills', '100 Sqm', 3, 2500, 7500],
        [6, 'Sample cleaning of drains', '100 Rmt', 450, 1200, null],
    ],
];
