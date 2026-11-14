<?php

$analysis = [['RATE ANALYSIS'], ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount']];

for ($i = 1; $i <= 12; $i++) {
    $analysis[] = [$i, 'Sample material or labour '.$i, 'nos', 1, 10 * $i, 10 * $i];
}

return [
    'about' => 'Research 3(f): a rate analysis sheet with more priced rows than the abstract is not picked; the abstract is.',
    'file' => ['sheets' => [
        'Rate Analysis' => $analysis,
        'Abstract' => [['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], [1, 'Sample excavation', 'Cum', 80, 260, 20800], [2, 'Sample PCC', 'Cum', 12, 5400, 64800]],
    ]],
];
