<?php

return [
    'about' => 'Research 3(f): a heading on two rows ("Rate" over "In figure" and "In Words") with a column-letter row under it ("(A) (B) ... F=(D*E)"): the rate in figures is read, the rate in words is a remark.',
    'rows' => [
        ['Sr. No.', 'Description of Item', 'Unit', 'Qty', 'Rate', null, 'Amount (Rs.)'],
        [null, null, null, null, 'In figure', 'In Words', null],
        ['A', 'B', 'C', 'D', 'E', 'F', 'F=(D*E)'],
        [1, 'Sample excavation in soft soil', 'Cum', 80, 260, 'Two hundred sixty only', 20800],
        [2, 'Sample PCC 1:4:8', 'Cum', 12, 5400, 'Five thousand four hundred only', 64800],
    ],
    'merges' => ['E1:F1', 'A1:A2', 'B1:B2', 'C1:C2', 'D1:D2', 'G1:G2'],
];
