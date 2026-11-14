<?php

return [
    'about' => 'N-1: "2 | Item deleted" with nothing else, between numbered items, is a note; the lines after it keep no section.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
        [2, 'Item deleted'],
        [3, 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
        [4, 'Sample brick masonry', 'Cum', 20, 5000, 100000],
        [5, 'Deleted', 'Sqm', null, null, null],
        [6, 'Sample plaster', 'Sqm', 100, 250, 25000],
    ],
];
