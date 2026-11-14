<?php

return [
    'about' => 'A tender schedule in its own layout under an office title: item codes, quantities with commas, a QRO line, a lettered section.',
    'file' => ['sheets' => ['Sheet1' => [
        ['OFFICE OF THE EXAMPLE ENGINEER'],
        ['Schedule B - Bill of Quantities'],
        [],
        ['S.No.', 'Item Code', 'Description of Work', 'Qty', 'Unit', 'Rate (Rs.)', 'Amount (Rs.)'],
        ['A', null, 'ROAD WORK'],
        [1, 'R-2.1', 'Sample granular sub-base with close graded material', '1,250', 'Cum', null, null],
        [2, 'R-3.4', 'Sample bituminous macadam 50 mm thick', '3,400.5', 'Sqm', null, null],
        [3, 'R-9.9', 'Sample road marking with hot thermoplastic paint', 'QRO', 'Sqm', null, null],
    ]], 'name' => 'schedule-b.xls'],
];
