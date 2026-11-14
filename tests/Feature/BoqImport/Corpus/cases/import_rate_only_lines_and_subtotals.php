<?php

return [
    'about' => 'QRO, "Rate only" and a blank quantity with a rate are rate-only lines; a subtotal, a "Total c/f" and a grand total.',
    'rows' => [
        ['Item No.', 'Description', 'Unit', 'Qty', 'Rate (Rs.)', 'Amount (Rs.)'],
        ['1', 'Supply of sample pipes 110 mm', 'Rmt', 120, 450, 54000],
        ['2', 'Extra over for bends', 'Nos', 'QRO', 300, null],
        ['3', 'Dismantling old fittings', 'Job', 'Rate only', 1500, null],
        ['4', 'Testing charges', 'LS', null, 2500, null],
        [null, 'Sub Total', null, null, null, 54000],
        ['5', 'Providing sample valves', 'Nos', 10, 1200, 12000],
        [null, 'Total c/f', null, null, null, 66000],
        [null, 'Grand Total', null, null, null, 66000],
    ],
];
