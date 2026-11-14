<?php

return [
    'about' => 'Quantity cells written with their unit ("12.5 Cum", "6 Nos"), a lump sum ("L.S.", "1 Job") and a rate with Indian commas, in a sheet with no unit column.',
    'rows' => [
        ['Sr No', 'Description', 'Quantity', 'Rate', 'Amount'],
        [1, 'Sample brickwork', '12.5 Cum', 6100, 76250],
        [2, 'Sample plaster', '400 Sqm', 230, null],
        [3, 'Sample doors', '6 Nos', '8,000', null],
        [4, 'Sample grill', '250 Kg', 95, 23750],
        [5, 'Sample lump work', 'L.S.', 25000, null],
        [6, 'Sample fixing', '1 Job', 4000, null],
    ],
];
