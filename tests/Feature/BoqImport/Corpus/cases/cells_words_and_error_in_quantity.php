<?php

return [
    'about' => 'Words ("approx 10") or a spreadsheet error in a quantity cell stop that row; nothing is read as 0 or worked out from the amount.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample work A', 'cum', 'approx 10', 100, 1000],
        [2, 'Sample work B', 'cum', '#REF!', 500, null],
        [3, 'Sample work C', 'cum', 5, 100, 500],
    ],
];
