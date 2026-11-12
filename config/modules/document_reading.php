<?php

/*
 * Reading scanned and photographed documents. This file starts with the word lists the code parsers
 * use; later parts of the module add their own keys.
 *
 * The Marathi and Hindi words below are a starting list and must be read by a native reader before
 * any reader is offered to a company. Add a spelling here; never teach the parser a rule.
 */
return [
    /*
     * How a unit is printed, onto how the app writes it. The app's own spellings (config
     * buildos.units) are always known and need no line. Compared in lower case without dots or
     * spaces, so "Cu. M." is "cum". The English spellings are the ones the estimate import already
     * accepts, which is also why "sqft" and "m" are here although the default unit list lacks them.
     * A unit that is not on this list is kept as printed and marked unknown.
     */
    'unit_words' => [
        'cum' => ['cumt', 'cumtr', 'cubm', 'm3', 'cubicmetre', 'cubicmeter', 'घमी', 'घनमी', 'घनमीटर'],
        'sqm' => ['sqmt', 'sqmtr', 'm2', 'squaremetre', 'squaremeter', 'चौमी', 'चौरसमीटर', 'वमी', 'वर्गमी', 'वर्गमीटर'],
        'sqft' => ['sft', 'squarefoot', 'squarefeet', 'चौफू', 'चौफूट', 'चौरसफूट', 'वफु', 'वर्गफुट', 'वर्गफीट', 'वर्गफ़ुट', 'वर्गफ़ीट'],
        'rmt' => ['rm', 'rmtr', 'lm', 'runningmetre', 'runningmeter', 'रमी', 'रनिंगमीटर'],
        'm' => ['mtr', 'metre', 'meter', 'मी', 'मीटर'],
        'nos' => ['no', 'each', 'number', 'numbers', 'नग', 'संख्या', 'अदद'],
        'kg' => ['kgs', 'kilogram', 'kilograms', 'किग्रॅ', 'किग्रॅम', 'किलो', 'किलोग्रॅम', 'किग्रा', 'किलोग्राम'],
        'MT' => ['mt', 'ton', 'tons', 'tonne', 'tonnes', 'metricton', 'metrictonne', 'मेटन', 'मेट्रिकटन', 'मीट्रिकटन', 'टन'],
        'ltr' => ['ltrs', 'lit', 'litre', 'litres', 'liter', 'liters', 'लि', 'लिटर', 'लीटर'],
        'bag' => ['bags', 'बॅग', 'पोते', 'पोती', 'गोणी', 'बैग', 'बोरी'],
        'LS' => ['lumpsum', 'एकरकमी', 'एकमुश्त'],
    ],

    /*
     * Month names a printed date may carry, by month number. English, then Marathi, then Hindi.
     * Compared in lower case.
     */
    'month_words' => [
        1 => ['january', 'jan', 'जानेवारी', 'जनवरी'],
        2 => ['february', 'feb', 'फेब्रुवारी', 'फरवरी', 'फ़रवरी'],
        3 => ['march', 'mar', 'मार्च'],
        4 => ['april', 'apr', 'एप्रिल', 'अप्रैल'],
        5 => ['may', 'मे', 'मई'],
        6 => ['june', 'jun', 'जून'],
        7 => ['july', 'jul', 'जुलै', 'जुलाई'],
        8 => ['august', 'aug', 'ऑगस्ट', 'अगस्त'],
        9 => ['september', 'sept', 'sep', 'सप्टेंबर', 'सितंबर', 'सितम्बर'],
        10 => ['october', 'oct', 'ऑक्टोबर', 'अक्टूबर', 'अक्तूबर'],
        11 => ['november', 'nov', 'नोव्हेंबर', 'नवंबर', 'नवम्बर'],
        12 => ['december', 'dec', 'डिसेंबर', 'दिसंबर', 'दिसम्बर'],
    ],
];
