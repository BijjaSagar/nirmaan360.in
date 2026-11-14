<?php

return [
    'about' => 'Research 3(e)/(f): page lines between items (a signature with the page number, "Page 4 of 9", "P a g e | 5", a note) are left out and never become a heading over the items after them.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
        [null, 'Contractor Page 2 of 9 Director'],
        [2, 'Sample PCC 1:4:8', 'Cum', 12, 5400, 64800],
        [null, 'Page 4 of 9'],
        [null, 'P a g e | 5'],
        [3, 'Sample brick masonry', 'Cum', 20, 5000, 100000],
        [null, 'Note :- Rates include carriage up to 1 km.'],
        [4, 'Sample plaster', 'Sqm', 100, 250, 25000],
    ],
];
