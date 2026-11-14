<?php

return [
    'about' => 'N-1 with dotted numbers: 1.2 with a unit and nothing else, then 1.3, is an item to be priced.',
    'rows' => [
        ['Item No.', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        ['1', 'EARTH WORK'],
        ['1.1', 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
        ['1.2', 'Sample dewatering of the pits', 'Job', null, null, null],
        ['1.3', 'Sample filling in plinth', 'Cum', 40, 150, 6000],
        [null, 'Total', null, null, null, 26800],
    ],
];
