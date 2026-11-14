<?php

return [
    'about' => 'N-1 must not undo this: a sheet that numbers every row, a specification\'s parts too ("1 | code | long specification", then "2 | code | Dado:", "3 | code | Dado in wash areas:"): numbered parts ending in a colon stay parts of the specification and carry its text; a numbered row past it in other words is the next item.',
    'rows' => [
        ['Sr.No', 'DSR Code', 'Description of Item', 'Qty', 'Rate', 'Unit', 'Amount'],
        ['I', null, 'TILE WORK :'],
        [1, 'S-14.1', 'Providing and fixing sample glazed wall tiles of approved shade on walls, set in cement mortar and pointed, complete as directed'],
        [2, 'S-14.2', 'Dado:', 70, 640, 'Sqm', 44800],
        [3, 'S-14.3', 'Dado in wash areas:', 15, 690, 'Sqm', 10350],
        [4, 'S-14.9', 'Providing sample marble sill 20 mm thick over the window walls, complete', 12, 1150, 'Sqm', 13800],
        [5, null, 'Coving:', 9, 410, 'Sqm', 3690],
    ],
];
