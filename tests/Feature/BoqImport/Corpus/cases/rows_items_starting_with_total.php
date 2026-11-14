<?php

return [
    'about' => 'Items whose text starts with "Total" (total station survey, totalizer) are lines; a QRO line and an unpriced line among them.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Brick masonry sample', 'cum', 40, 5000, 200000],
        [2, 'Total station survey of the sample plot', 'day', 'QRO', 4500, null],
        [3, 'Total station survey, second visit', 'day', 2, null, null],
        [4, 'Totalizer flow meter supply', 'nos', 1, 3000, 3000],
        [null, 'Total', null, null, null, 203000],
    ],
];
