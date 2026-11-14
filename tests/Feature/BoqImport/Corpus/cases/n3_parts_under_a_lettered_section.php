<?php

return [
    'about' => 'N-3 with sections: parts of item 1 in section B are B.1a and B.1b; the part codes never collide with the specification or a sibling.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['A', 'EARTH WORK'],
        [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
        ['B', 'FINISHING'],
        [1, 'Providing sample plaster on walls :'],
        [null, '(a) inside', 'Sqm', 100, 250, 25000],
        [null, '(a) inside, second coat', 'Sqm', 100, 120, 12000],
        [null, '(b) outside', 'Sqm', 80, 300, 24000],
    ],
];
