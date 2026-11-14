<?php

return [
    'about' => 'Research 3(e): a recovery with a negative rate and a credit are taken off, never lines; a "(+) @" provision, seigniorage, a profit percentage, an escalation and a rounding row are adjustments; a numbered provisional sum and a non-schedule item are lines, flagged.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
        [2, 'Provisional sum for sample electrical connection', 'LS', 1, 30000, 30000],
        [3, 'Sample shifting of a pole (Non SOR Item)', 'Each', 2, 4000, 8000],
        [4, 'Recovery of sample bricks from dismantling', 'Nos', 500, -2, -1000],
        [null, 'Credit for taking away of useful material obtained from above', 'LS'],
        [null, 'Provision for Contingency (+) @', null, null, null, 2000],
        [null, 'Seigniorage Charges', null, null, null, 1500],
        [null, 'Add OHC & Contractor\'s Profit 10%', null, null, null, 5880],
        [null, 'Escalation and Tender Premium @5%', null, null, null, 2940],
        [null, 'Miscellaneous and Rounding off', null, null, null, 12],
    ],
];
