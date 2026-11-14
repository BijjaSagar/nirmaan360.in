<?php

return [
    'about' => 'A second table beside the first is not dropped unseen: its rate column stops the import until the person chooses.',
    'file' => ['sheets' => ['Sheet1' => [
        ['CIVIL', null, null, null, null, null, null, 'ELECTRICAL'],
        ['Sr', 'Description', 'Unit', 'Qty', 'Rate', 'Amount', null, 'Sr', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample excavation', 'Cum', 80, 260, 20800, null, 1, 'Sample wiring point', 'Pt', 40, 900, 36000],
        [2, 'Sample PCC', 'Cum', 20, 5500, 110000, null, 2, 'Sample DB 8 way', 'Nos', 2, 6500, 13000],
        [3, 'Sample brickwork', 'Cum', 40, 5730, 229200, null, 3, 'Sample LED fitting', 'Nos', 30, 1200, 36000],
        [null, 'Total', null, null, null, 360000, null, null, 'Total', null, null, null, 85000],
    ]]],
];
