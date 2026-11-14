<?php

return [
    'about' => 'A quantity or rate formula saved with no result is refused, never read as 0.',
    'file' => [
        'sheets' => ['BOQ' => [
            ['Sr. No.', 'Description of Item', 'Unit', 'Qty', 'Rate', 'Amount'],
            [1, 'Sample brick masonry', 'cum', '=4*10', 5000, '=D2*E2'],
            [2, 'Sample plaster', 'sqm', 120, '=200+50', '=D3*E3'],
        ]],
        'formula_results' => false,
    ],
];
