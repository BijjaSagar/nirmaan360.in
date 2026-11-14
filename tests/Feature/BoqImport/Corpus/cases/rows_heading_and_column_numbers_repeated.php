<?php

$heading = ['Sr. No.', 'Description of Item', 'Unit', 'Qty', 'Rate', 'Amount'];
$rows = [$heading, [1, 2, 3, 4, 5, 6]];
$sum = 0;

for ($i = 1; $i <= 12; $i++) {
    if ($i === 5 || $i === 9) {
        array_push($rows, [null, 'Carried over', null, null, null, $sum], $heading, [1, 2, 3, 4, 5, 6], [null, 'Brought forward', null, null, null, $sum]);
    }

    $rows[] = [$i, 'Sample item '.$i, 'nos', $i % 5 + 1, 100 + $i, ($i % 5 + 1) * (100 + $i)];
    $sum += ($i % 5 + 1) * (100 + $i);
}

$rows[] = [null, 'Total', null, null, null, $sum];

return [
    'about' => 'The heading and its column numbers printed again at every page break, with carried and brought forward amounts around them.',
    'rows' => $rows,
];
