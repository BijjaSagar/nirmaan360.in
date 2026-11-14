<?php

return [
    'about' => 'A two-row heading in merged cells: "Rate" over "(Rs.)".',
    'rows' => [
        ['S.No.', 'Description of Work', 'Unit', 'Quantity', 'Rate', 'Amount'],
        [null, null, null, null, '(Rs.)', 'in Rs.'],
        [1, 'Brick masonry in cement mortar 1:6', 'cum', 12.5, 5200, 65000],
        [2, 'Plaster 12 mm thick in cement mortar 1:4', 'sqm', 300, 240, 72000],
    ],
    'merges' => ['A1:A2', 'B1:B2', 'C1:C2', 'D1:D2'],
];
