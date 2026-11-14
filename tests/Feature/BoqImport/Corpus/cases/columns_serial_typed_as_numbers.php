<?php

$rows = [['Item No.', 'Description', 'Unit', 'Qty', 'Rate', 'Amount']];

foreach ([1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.10, 1.11, 1.12] as $index => $serial) {
    $rows[] = [$serial, 'Sample item '.($index + 1), 'nos', 1, 10, 10];
}

return [
    'about' => 'Item numbers typed as numbers and shown with two decimals: 1.10 is not 1.1.',
    'file' => ['sheets' => ['Sheet1' => $rows], 'formats' => ['Sheet1' => ['A2:A13' => '0.00']]],
];
