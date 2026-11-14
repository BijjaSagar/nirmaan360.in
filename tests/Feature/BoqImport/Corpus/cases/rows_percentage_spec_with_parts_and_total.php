<?php

return [
    'about' => 'A numbered specification naming a percentage and ending ":" with lettered parts is a specification, not an adjustment; a total after it.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Brick masonry sample work', 'cum', 40, 5000, 200000],
        [2, 'Plaster with 2% waterproof compound:'],
        [null, 'a) Inside walls', 'sqm', 100, 250, 25000],
        [null, 'b) Outside walls', 'sqm', 80, 300, 24000],
        [null, 'Total', null, null, null, 249000],
    ],
];
