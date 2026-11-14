<?php

return [
    'about' => 'Fit 2.1: an estimate whose footer has a subtotal, a percentage below the schedule for the area, two services as percentages with their amounts, the total and "SAY Rs ... lakh": none of the footer is a line, and the totals reconcile.',
    'rows' => [
        ['Sr.No', 'DSR Code', 'Description of Item', 'Qty', 'Rate', 'Unit', 'Amount'],
        ['A', null, 'FOUNDATION'],
        [1, 'S-2.1', 'Sample digging of trenches in ordinary soil', 40, 250, 'Cum', 10000],
        ['B', null, 'FRAME'],
        [2, 'S-5.3', 'Sample RCC M25 in the frame', 12, 7500, 'Cum', 90000],
        [3, 'S-5.9', 'Sample HYSD bars for the frame', 2500, 40, 'Kg', 100000],
        [null, null, 'Subtotal', null, null, null, 200000],
        [null, null, 'Deduct 4% below the schedule for the town area', null, null, null, -8000],
        [null, null, 'Water supply works 2.5%', null, null, null, 5000],
        [null, null, 'Drainage works 2.5%', null, null, null, 5000],
        [null, null, 'Total', null, null, null, 202000],
        [null, null, 'SAY Rs 2.02 lakh only'],
    ],
];
