<?php

return [
    'about' => 'Research 2.2: a state schedule of rates (a rate book): chapter column on every row, SSR item numbers as text (1.10 is not 1.1), a reference column, units written "One Cubic Metre", "Per Test" and "1 MT", and a completed rate and a labour rate, of which only one can be read.',
    'file' => ['sheets' => ['SSR' => [
        ['Sr. No.', 'Chapter', 'SSR Item No.', 'Reference No.', 'Description of the item', 'Additional Specification', 'Unit', 'Completed Rate for 2026-27 excluding GST In Rs.', 'Labour Rate for 2026-27 excluding GST In Rs.'],
        [1, 'Road Survey and DPR', '1.09', 'BDF 5', 'Sample survey of the road with total station', 'As directed by Engineer in charge', 'Per Km', 9000, 3000],
        [2, 'Road Survey and DPR', '1.10', 'BDF 6', 'Sample soil test of the subgrade', null, 'Per Test', 1200, 400],
        [3, 'Earth Work', '2.01', 'MORTH 301', 'Sample excavation in soil by mechanical means', null, 'One Cubic Metre', 180, 60],
        [4, 'Steel Work', '15.146', 'MORTH 1600', 'Sample structural steel in trusses', null, '1 MT', 98000, 21000],
    ]]],
];
