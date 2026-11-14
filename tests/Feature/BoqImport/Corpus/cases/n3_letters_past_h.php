<?php

$rows = [['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], [7, 'Providing sample PVC pipes of the following diameters :']];

foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'] as $index => $letter) {
    $rows[] = [null, '('.$letter.') '.(20 + 5 * $index).' mm dia', 'Rmt', 10, 100 + 10 * $index, 10 * (100 + 10 * $index)];
}

return [
    'about' => 'N-3: in a run of lettered parts, (i) after (h) is the letter i, not the roman one: 7h, 7i, 7j.',
    'rows' => $rows,
];
