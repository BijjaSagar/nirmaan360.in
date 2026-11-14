<?php

return [
    'about' => 'Research 3(f): the data sheet of a percentage-rate template is very hidden and the visible sheet only asks for macros: the hidden sheet is read (macros are never run); the portal\'s "Item Code / Make" is not a schedule code; a numbered GST-difference row is an adjustment, not work.',
    'file' => [
        'sheets' => [
            'Macros' => [['Please Enable Macros to View BoQ information']],
            'BoQ1' => [
                ['Percentage BoQ'],
                ['BoQ_Ver3.1', 'Percentage', 'Normal', 'INR Only', 'INR'],
                ['Select', 'At Par', 'Excess (+)', 'Less (-)'],
                ['Tender Inviting Authority: Sample Engineer'],
                ['Name of Work: Sample repairs to the example hall'],
                ['Sl. No.', 'Item Description', 'Item Code / Make', 'Quantity', 'Units', 'Estimated Rate in Rs. P', 'TOTAL AMOUNT With Taxes in Rs. P'],
                [1.0, 'Sample earth work in excavation', 'BI01010001001', 120, 'cum', 210, 25200],
                [2.0, 'Sample brick work in cement mortar', 'BI01010001002', 30, 'cum', 6000, 180000],
                [3.0, 'Add GST difference @12% on DSR 2026', 'BI01010001003', 1.0, 'unit', 12, 12],
                ['Total in Figures', null, null, null, null, null, 205212],
            ],
        ],
        'states' => ['BoQ1' => 'veryHidden'],
    ],
];
