<?php

return [
    'about' => 'A government schedule (a real BIFF .xls) under a "Name of Work" line, with Sl. No. as floats and the amount in words as a remark.',
    'file' => [
        'sheets' => ['BoQ1' => [
            ['Name of Work: Sample drain repair'],
            ['Sl. No.', 'Item Description', 'Quantity', 'Units', 'Estimated Rate', 'TOTAL AMOUNT Without Taxes', 'TOTAL AMOUNT In Words'],
            [1.00, 'Sample earthwork in excavation by mechanical means in all kinds of soil', 1200, 'Cum', 180, 216000, 'INR Two Lakh Sixteen Thousand Only'],
            [2.00, 'Sample cement concrete 1:2:4 in drain bed including curing', 80, 'Cum', 6450, 516000, 'INR Five Lakh Sixteen Thousand Only'],
            [3.00, 'Sample stone pitching on drain sides with cement mortar', 600, 'Sqm', 260, 156000, 'INR One Lakh Fifty Six Thousand Only'],
        ]],
        'writer' => 'Xls',
    ],
];
