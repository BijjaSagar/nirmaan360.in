<?php

return [
    'about' => 'Roman sections numbering their items from 1 each, an "Estimated cost of ..." part total in the middle, a grand total and a signature below it.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['I', 'BUILDING WORK'],
        [1, 'Sample footing concrete', 'Cum', 10, 5000, 50000],
        [null, 'Estimated cost of Building Work', null, null, null, 50000],
        ['II', 'COMPOUND WALL'],
        [1, 'Sample wall masonry', 'Cum', 20, 4000, 80000],
        [null, 'Grand Total', null, null, null, 130000],
        [null, 'Prepared by: Sample Engineer'],
    ],
];
