<?php

return [
    'about' => 'Fit 2.1: an estimate saved as .xlsx under a ".xls" name is read by its content, specifications and footer included.',
    'file' => [
        'sheets' => ['Estimate' => [
            ['ESTIMATE OF SAMPLE GODOWN'],
            ['Sr.No', 'DSR Code', 'Description of Item', 'Qty', 'Rate', 'Unit', 'Amount'],
            [1, 'S-4.1', 'Providing and laying sample cement concrete 1:3:6 of the following, curing complete :'],
            [null, null, 'a) Raft', 8, 4500, 'Cum', 36000],
            [null, null, 'b) Retaining wall', 3, 4500, 'Cum', 13500],
            [null, null, 'Subtotal', null, null, null, 49500],
            [null, null, 'Total', null, null, null, 49500],
        ]],
        'name' => 'sample godown.xls',
    ],
];
