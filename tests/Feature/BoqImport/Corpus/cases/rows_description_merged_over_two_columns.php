<?php

return [
    'about' => '"Description" merged over two columns, the item in the first and its lettered parts in the second.',
    'rows' => [
        ['Sr No', 'Description', null, 'Unit', 'Qty', 'Rate', 'Amount'],
        ['1', 'Providing sample cement plaster in CM 1:4 on walls'],
        [null, null, '(a) 12 mm thick inside', 'Sqm', 600, 230, 138000],
        [null, null, '(b) 20 mm thick outside', 'Sqm', 300, 280, 84000],
        ['2', 'Sample painting with acrylic emulsion', null, 'Sqm', 900, 120, 108000],
    ],
    'merges' => ['B1:C1'],
];
