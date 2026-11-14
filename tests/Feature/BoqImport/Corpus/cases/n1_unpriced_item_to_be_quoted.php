<?php

return [
    'about' => 'N-1: a numbered item with its unit but no quantity and no rate, followed by the next number, is an item to be priced, never a heading that renames the lines after it.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
        [2, 'Sample railing in mild steel, to be quoted', 'Rmt', null, null, null],
        [3, 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
        [4, 'Sample brick masonry', 'Cum', 20, 5000, 100000],
        [null, 'Total', null, null, null, 185600],
    ],
];
