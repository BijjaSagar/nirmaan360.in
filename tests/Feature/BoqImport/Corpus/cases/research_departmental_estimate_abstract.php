<?php

return [
    'about' => 'Research 2.1 and 3(f): a departmental estimate in abstract form under a heading block (Annexure, N/W, Estimate ID); integer chapters; a parent ending ":" with dotted children; 21.3 typed as a number between 21.29 and 21.31 read as 21.30; "Sub‐Total" with a Unicode hyphen; Total, the GST correction-factor line and Say.',
    'file' => [
        'sheets' => ['Abstract' => [
            ['Annexure A for BOQ'],
            ['Original Estimate'],
            ['N/W Sample hostel block, Example campus'],
            ['Estimate ID : 0000-SAMPLE'],
            ['SLNo', 'Description', 'Qty', 'Unit', 'Rate', 'Amount'],
            [1, 'EARTH WORK'],
            ['1.1', 'Sample earth work in excavation by mechanical means in all kinds of soil :'],
            ['1.1.1', 'All kinds of soil', 120, 'cum', 210, 25200],
            ['1.1.2', 'Ordinary rock', 30, 'cum', 480, 14400],
            ['1.2', 'Sample filling available earth in trenches', 60, 'cum', 150, 9000],
            [null, 'Sub‐Total', null, null, null, 48600],
            [21, 'MISCELLANEOUS'],
            [21.29, 'Sample cleaning of the site', 1, 'job', 4000, 4000],
            [21.3, 'Sample signage boards', 6, 'each', 1500, 9000],
            [21.31, 'Sample bricks of second class', 2, '1000 Nos', 7000, 14000],
            [null, 'Sub‐Total', null, null, null, 27000],
            [null, 'Total', null, null, null, 75600],
            [null, 'Modified Estimated Cost after using correction factor on DSR 2026 on account of GST @ 1.10 * 75600 = 83160', null, null, null, 83160],
            [null, 'Say', null, null, null, 83200],
        ]],
        'formats' => ['Abstract' => ['A13:A15' => 'General']],
    ],
];
