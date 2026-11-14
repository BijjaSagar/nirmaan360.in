<?php

$heading = ['Sr No', 'Description', 'Unit'];

for ($floor = 1; $floor <= 49; $floor++) {
    $heading[] = 'Floor '.$floor;
}

$rows = [[...$heading, 'Total Qty', 'Rate', 'Amount']];

foreach ([1 => 100, 2 => 200] as $item => $rate) {
    $rows[] = [$item, 'Sample item '.$item, 'cum', ...array_fill(0, 49, $item), 49 * $item, $rate, 49 * $item * $rate];
}

return [
    'about' => 'B-18: the quantity, rate and amount lie past column AZ; no heading is found in A to AZ, and the page says those columns were cut off.',
    'file' => ['sheets' => ['Sheet1' => $rows]],
];
