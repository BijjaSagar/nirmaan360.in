<?php

$heading = ['Sr No', 'Description', 'Unit', 'Rate'];

for ($floor = 1; $floor <= 48; $floor++) {
    $heading[] = 'Floor '.$floor;
}

$rows = [[...$heading, 'Total Qty', 'Amount']];

foreach ([1 => 100, 2 => 200] as $item => $rate) {
    $rows[] = [$item, 'Sample item '.$item, 'cum', $rate, ...array_fill(0, 48, $item), 48 * $item, 48 * $item * $rate];
}

return [
    'about' => 'B-18: the rate is read but the total quantity lies past column AZ: refused, saying so, never read as rate-only lines.',
    'file' => ['sheets' => ['Sheet1' => $rows]],
];
