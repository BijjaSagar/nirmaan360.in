<?php

return [
    'about' => 'An amount that is not quantity times rate is listed and never corrected; one rounded to the rupee is not.',
    'rows' => [
        ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'],
        [1, 'Sample line that adds up', 'cum', 10, 100, 1000],
        [2, 'Sample line whose amount is wrong', 'cum', 10, 100, 1500],
        [3, 'Sample line rounded to the rupee', 'cum', 3.333, 100, 333],
    ],
];
