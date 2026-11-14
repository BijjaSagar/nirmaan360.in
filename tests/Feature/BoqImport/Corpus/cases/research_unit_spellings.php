<?php

$units = [
    'One Cubic Metre', 'Cu.M.', 'M³', '1 Cum (One cubic metre)', 'per Sqm', 'Sq. Mt', 'Square Meter', 'R.Mt.', 'Running / Metre', 'Metre', 'Each', 'No.', 'Kg.', 'Kgs',
    'M.T.', 'Qtl', 'Litre', 'L', 'Brass', 'Per Brass', 'Sft', 'CFT.', 'Rft', 'L.S.', 'each job', 'each set', 'Each cut', 'Point', 'घन मीटर', 'प्रति घन मीटर', 'वर्ग मीटर',
    'संख्या', 'किलोग्राम', 'टन', 'क्विंटल', 'लिटर', 'नग', 'घ.मी.', 'चौ.मी.', 'E a c h', 'unit', 'q', 'Smt',
];
$rows = [['Sr No', 'Description', 'Unit', 'Qty', 'Rate', 'Amount']];

foreach ($units as $index => $unit) {
    $rows[] = [$index + 1, 'Sample item number '.($index + 1), $unit, 2, 50, 100];
}

return [
    'about' => 'Research 3(d): unit spellings from schedules across India read to one unit each, local-language words included; brass stays brass; spellings no schedule prints are read and listed; "unit", "q" and "Smt" are kept as written and listed to check.',
    'rows' => $rows,
];
