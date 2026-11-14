<?php

$text = "अ.क्र.\tकामाचा तपशील\tएकक\tपरिमाण\tदर\tरक्कम\r\n1\tपायासाठी खोदकाम करणे\tघ.मी.\t80\t260\t20800\r\n2\tसाधे काँक्रीट टाकणे\tघ.मी.\t12\t5400\t64800\r\n";

return [
    'about' => 'A UTF-16 tab-separated text file in Marathi, without its byte-order mark.',
    'file' => ['raw' => mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'), 'writer' => 'Csv'],
];
