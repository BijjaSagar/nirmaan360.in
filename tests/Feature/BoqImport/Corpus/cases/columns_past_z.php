<?php

$heading = ['Sr No', 'Description', 'Unit'];

for ($floor = 1; $floor <= 22; $floor++) {
    $heading[] = 'Floor '.$floor;
}

$rows = [[...$heading, 'Total Qty', 'Rate', 'Amount']];

foreach ([1 => 100, 2 => 200, 3 => 300] as $item => $rate) {
    $rows[] = [$item, 'Sample item '.$item, 'cum', ...array_fill(0, 22, $item), 22 * $item, $rate, 22 * $item * $rate];
}

return [
    'about' => 'A measurement-style sheet with a column per floor: the total quantity, rate and amount past column Z are read.',
    'file' => ['sheets' => ['Sheet1' => $rows]],
];
