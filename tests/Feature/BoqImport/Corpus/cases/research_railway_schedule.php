<?php

return [
    'about' => 'Research 2.1 and 3(b)/(c): a railway schedule priced on its standard schedule: "Items" holding six-digit codes as text (zeros kept) is the code column; serials 1, 1(a), 1(b); a non-schedule NS item flagged; quantity after rate; a page footer with a signature and page number between items.',
    'rows' => [
        ['No.', 'Items', 'Description', 'Unit', 'Rate (Including 18% GST)', 'Estimated Quantity', 'Amount'],
        ['1', '011010', 'Sample earthwork in embankment with good earth', 'Cum', 180, 1000, 180000],
        ['2', '021010', 'Sample exploratory drilling in all soils :'],
        ['2(a)', '021011', '(a) Up to 10 m depth', 'Metre', 900, 40, 36000],
        ['2(b)', '021012', '(b) Beyond 10 m depth', 'Metre', 1100, 20, 22000],
        [null, null, 'Signature of tenderer (s) with seal Page 3'],
        ['3', 'NS1', 'Sample shifting of signal cables', 'Each', 25000, 2, 50000],
        [null, null, 'Total (Including GST)', null, null, null, 288000],
        [null, null, 'Grand Total Schedule Amount (Including 18% GST)', null, null, null, 288000],
    ],
];
