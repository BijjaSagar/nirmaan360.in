<?php

return [
    'about' => 'A subtotal, then GST, a round-off and a labour cess as amounts, then the grand total: adjustments, shown and not imported.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample excavation', 'Cum', 80, 260, 20800],
        [2, 'Sample PCC', 'Cum', 4, 16207, 64828],
        [null, 'Sub Total', null, null, null, 85628],
        [null, 'Add GST', null, null, null, 15413.04],
        [null, 'Round off', null, null, null, -0.04],
        [null, 'Labour welfare cess', null, null, null, 856.28],
        [null, 'Grand Total', null, null, null, 101897.28],
    ],
];
