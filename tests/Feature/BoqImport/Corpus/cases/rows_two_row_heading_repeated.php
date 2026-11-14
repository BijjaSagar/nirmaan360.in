<?php

$heading = [['Sr.', 'Description', 'Unit', 'Quantity', 'Rate', 'Amount'], ['No.', 'of Item', null, null, '(Rs.)', '(Rs.)']];
$rows = [['Sample Builders'], ['Example Road'], [], ...$heading];

for ($i = 1; $i <= 8; $i++) {
    if ($i === 4 || $i === 7) {
        array_push($rows, ...$heading);
    }

    $rows[] = [$i, 'Invented item number '.$i.' of the work', 'Cum', $i, 100 + $i, $i * (100 + $i)];
}

return [
    'about' => 'A heading on two rows under a letterhead, printed again at page breaks: both rows are the heading, and no line is put in a section called "of Item".',
    'rows' => $rows,
];
