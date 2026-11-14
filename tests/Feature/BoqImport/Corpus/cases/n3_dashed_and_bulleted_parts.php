<?php

return [
    'about' => 'N-3: dashed and bulleted parts get the number and their place, made up here: 2(1), 2(2); never "2-" or "2-.1".',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 20800],
        [2, 'Providing and laying sample reinforced concrete M20 in :'],
        [null, '- Footings', 'Cum', 10, 6000, 60000],
        [null, '- Columns', 'Cum', 4, 7000, 28000],
        [null, '– Beams', 'Cum', 3, 7200, 21600],
        [3, 'Providing sample flooring in :'],
        [null, '• Living room', 'Sqm', 30, 900, 27000],
        [null, '• Bed room', 'Sqm', 20, 900, 18000],
    ],
];
