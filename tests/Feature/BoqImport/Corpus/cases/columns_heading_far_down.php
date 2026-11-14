<?php

$rows = [['GENERAL NOTES']];

for ($note = 1; $note <= 45; $note++) {
    $rows[] = [$note.'. Sample general condition number '.$note.' applies to all items of this schedule.'];
}

return [
    'about' => 'The heading below 45 rows of general notes is still found.',
    'rows' => [...$rows, ['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount'], ['1', 'Sample excavation', 'Cum', 80, 260, 20800], ['2', 'Sample PCC', 'Cum', 12, 5400, 64800]],
];
