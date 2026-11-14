<?php

return [
    'about' => 'A section, two lines, a subtotal that does not match, and a signature in another column.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['A', 'SAMPLE WORKS'],
        [1, 'Sample work one', 'cum', 10, 100, 1000],
        [2, 'Sample work two', 'cum', 5, 100, 500],
        [null, 'Sub Total', null, null, null, 9000],
        [null, null, 'Signed: Sample Engineer'],
    ],
];
