<?php

use App\Models\DprVoiceDraft;
use App\Services\VoiceDpr\VoiceDprOffer;
use App\Services\VoiceDpr\VoiceDraftCall;
use App\Services\VoiceDpr\VoiceDraftRetention;

/*
 * Voice DPR, the server side. The phone turns the supervisor's speech into text on the phone itself
 * and sends only that text. The server asks the model to sort the words into the fields of a DPR,
 * then checks every number against the exact words it came from, and returns a proposal. It never
 * writes a DPR: the phone fills its own form after the person has checked each field, and sends the
 * DPR through its outbox as always. See docs/MODULES.md.
 *
 * The module has no plan feature, permission or menu entry of its own. Its routes ask for the site
 * feature, the AI feature and dpr.create, and the one that sends words for agents.use as well.
 *
 * The Marathi and Hindi words below are a starting list and must be read by a native reader before
 * voice is offered to a company. Add a spelling here; never teach the code a rule.
 */
return [
    // Handled for this module's own record only, so another feature can share the purpose.
    'text_calls' => ['voice' => [DprVoiceDraft::class => VoiceDraftCall::class]],

    // Blanks the words and the proposal once they are past their time.
    'retention' => [VoiceDraftRetention::class],

    // What the field app asks before it shows the Speak control.
    'reader_offers' => ['voice_dpr' => VoiceDprOffer::class],

    /*
     * While true, words are taken only from the two recognisers the app's own code runs on the phone
     * itself. The server cannot prove what the phone did; this only refuses an app that says otherwise.
     */
    'on_device_only' => true,
    'engines' => ['android_on_device', 'ios_on_device'],

    // The languages the phone listens in, and the name the model is told.
    'locales' => ['mr-IN' => 'Marathi', 'hi-IN' => 'Hindi', 'en-IN' => 'English'],

    // The most characters of words one report may carry.
    'transcript_max' => 6000,

    /*
     * The BOQ lines offered to the model, as refs B1, B2 and so on: lines whose code or description
     * shares a word of at least min_letters letters with what was said, then lines used in the
     * project's DPRs of the last recent_days days. Never a rate. A word in Devanagari needs
     * min_letters_devanagari: its vowel signs are not counted as letters, so भिंत, वीट and छत have two.
     * Units, size words and number words never pick a line, on either side.
     */
    'context' => ['items' => 40, 'recent_days' => 14, 'description_chars' => 120, 'min_letters' => 3, 'min_letters_devanagari' => 2],

    // Words that say nothing about which BOQ line is meant, so they never pick one, said or written in the line.
    'context_stop_words' => [
        'and', 'are', 'all', 'also', 'but', 'done', 'for', 'from', 'had', 'has', 'have', 'not', 'our', 'site',
        'that', 'the', 'this', 'today', 'was', 'were', 'will', 'with', 'work', 'yesterday',
        'आज', 'आणि', 'आता', 'आम्ही', 'आहे', 'आहेत', 'उद्या', 'कर', 'करणे', 'करत', 'काम', 'काल', 'केलं', 'केला', 'केली', 'केले',
        'जण', 'झालं', 'झाला', 'झाली', 'झाले', 'तर', 'नाही', 'पण', 'मग', 'मजुरी', 'मध्ये', 'वर', 'सह', 'सहित', 'साठी',
        'साहित्य', 'होतं', 'होता', 'होती', 'होते',
        'और', 'करके', 'करना', 'करने', 'कल', 'किया', 'गई', 'गए', 'गया', 'तक', 'नहीं', 'पर', 'मजदूरी', 'रहा', 'रही', 'रहे',
        'लिए', 'सामग्री', 'हम', 'हमने', 'हुआ', 'हुई', 'हुए',
    ],

    /*
     * What may follow a two-letter Devanagari word inside a longer word that still names it, on either side
     * (said, or written in the line): vowel signs, then at most one word for the work done with it
     * (context_heads: वीटकाम, रंगकाम, खडीकरण), then vowel signs and at most one case ending (context_endings:
     * भिंतीचं, भिंतीला, छतावरील, ईंटों). Any other longer word is a different word: "पर" is not in "परवा",
     * nor "तार" in "तारीख".
     */
    'context_heads' => ['करण', 'काम'],

    'context_endings' => ['चं', 'चा', 'ची', 'चे', 'च्या', 'त', 'तील', 'ना', 'नी', 'ने', 'मधील', 'मध्ये', 'ला', 'वर', 'वरील', 'साठी'],

    // How many words may stand between a number and the trade or unit it is tied to.
    'anchor_words' => 6,

    // The most rows of workers and of work one proposal may hold.
    'max_rows' => 40,

    // Days after which the words, the proposal and the checks are blanked.
    'keep_days' => ['unlinked' => 30, 'linked' => 90],

    /*
     * Number words, by language: the word, compared in lower case, and its value. English is always
     * understood beside the language the phone listened in, because many people say figures in it.
     */
    'number_words' => [
        'en' => [
            'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7',
            'eight' => '8', 'nine' => '9', 'ten' => '10', 'eleven' => '11', 'twelve' => '12', 'thirteen' => '13', 'fourteen' => '14',
            'fifteen' => '15', 'sixteen' => '16', 'seventeen' => '17', 'eighteen' => '18', 'nineteen' => '19', 'twenty' => '20',
            'thirty' => '30', 'forty' => '40', 'fifty' => '50', 'sixty' => '60', 'seventy' => '70', 'eighty' => '80', 'ninety' => '90',
        ],
        'hi' => [
            'शून्य' => '0', 'एक' => '1', 'दो' => '2', 'तीन' => '3', 'चार' => '4', 'पाँच' => '5', 'पांच' => '5', 'छह' => '6', 'छः' => '6',
            'छे' => '6', 'सात' => '7', 'आठ' => '8', 'नौ' => '9', 'दस' => '10', 'ग्यारह' => '11', 'बारह' => '12', 'तेरह' => '13',
            'चौदह' => '14', 'पंद्रह' => '15', 'पन्द्रह' => '15', 'सोलह' => '16', 'सत्रह' => '17', 'अठारह' => '18', 'उन्नीस' => '19',
            'बीस' => '20', 'इक्कीस' => '21', 'बाईस' => '22', 'तेईस' => '23', 'चौबीस' => '24', 'पच्चीस' => '25', 'छब्बीस' => '26',
            'सत्ताईस' => '27', 'अट्ठाईस' => '28', 'उनतीस' => '29', 'तीस' => '30', 'इकतीस' => '31', 'इकत्तीस' => '31', 'बत्तीस' => '32',
            'तैंतीस' => '33', 'चौंतीस' => '34', 'पैंतीस' => '35', 'छत्तीस' => '36', 'सैंतीस' => '37', 'अड़तीस' => '38',
            'उनतालीस' => '39', 'चालीस' => '40', 'इकतालीस' => '41', 'बयालीस' => '42', 'तैंतालीस' => '43', 'चवालीस' => '44',
            'पैंतालीस' => '45', 'छियालीस' => '46', 'सैंतालीस' => '47', 'अड़तालीस' => '48', 'उनचास' => '49', 'पचास' => '50',
            'इक्यावन' => '51', 'बावन' => '52', 'तिरेपन' => '53', 'तिरपन' => '53', 'चौवन' => '54', 'पचपन' => '55', 'छप्पन' => '56',
            'सत्तावन' => '57', 'अट्ठावन' => '58', 'उनसठ' => '59', 'साठ' => '60', 'इकसठ' => '61', 'बासठ' => '62', 'तिरसठ' => '63',
            'चौंसठ' => '64', 'पैंसठ' => '65', 'छियासठ' => '66', 'सड़सठ' => '67', 'अड़सठ' => '68', 'उनहत्तर' => '69', 'सत्तर' => '70',
            'इकहत्तर' => '71', 'बहत्तर' => '72', 'तिहत्तर' => '73', 'चौहत्तर' => '74', 'पचहत्तर' => '75', 'छिहत्तर' => '76',
            'सतहत्तर' => '77', 'अठहत्तर' => '78', 'उन्यासी' => '79', 'उनासी' => '79', 'अस्सी' => '80', 'इक्यासी' => '81', 'बयासी' => '82',
            'तिरासी' => '83', 'चौरासी' => '84', 'पचासी' => '85', 'छियासी' => '86', 'सत्तासी' => '87', 'अट्ठासी' => '88', 'नवासी' => '89',
            'नब्बे' => '90', 'इक्यानबे' => '91', 'बानबे' => '92', 'तिरानबे' => '93', 'चौरानबे' => '94', 'पचानबे' => '95', 'छियानबे' => '96',
            'सत्तानबे' => '97', 'अट्ठानबे' => '98', 'निन्यानबे' => '99',
        ],
        'mr' => [
            'शून्य' => '0', 'एक' => '1', 'दोन' => '2', 'तीन' => '3', 'चार' => '4', 'पाच' => '5', 'सहा' => '6', 'सात' => '7', 'आठ' => '8',
            'नऊ' => '9', 'दहा' => '10', 'अकरा' => '11', 'बारा' => '12', 'तेरा' => '13', 'चौदा' => '14', 'पंधरा' => '15', 'सोळा' => '16',
            'सतरा' => '17', 'अठरा' => '18', 'एकोणीस' => '19', 'वीस' => '20', 'एकवीस' => '21', 'बावीस' => '22', 'तेवीस' => '23',
            'चोवीस' => '24', 'पंचवीस' => '25', 'सव्वीस' => '26', 'सत्तावीस' => '27', 'अठ्ठावीस' => '28', 'एकोणतीस' => '29', 'तीस' => '30',
            'एकतीस' => '31', 'बत्तीस' => '32', 'तेहेतीस' => '33', 'तेहतीस' => '33', 'चौतीस' => '34', 'पस्तीस' => '35', 'छत्तीस' => '36',
            'सदतीस' => '37', 'अडतीस' => '38', 'एकोणचाळीस' => '39', 'चाळीस' => '40', 'एक्केचाळीस' => '41', 'बेचाळीस' => '42',
            'त्रेचाळीस' => '43', 'चव्वेचाळीस' => '44', 'पंचेचाळीस' => '45', 'सेहेचाळीस' => '46', 'सत्तेचाळीस' => '47',
            'अठ्ठेचाळीस' => '48', 'एकोणपन्नास' => '49', 'पन्नास' => '50', 'एक्कावन्न' => '51', 'बावन्न' => '52', 'त्रेपन्न' => '53',
            'चोपन्न' => '54', 'पंचावन्न' => '55', 'छप्पन्न' => '56', 'सत्तावन्न' => '57', 'अठ्ठावन्न' => '58', 'एकोणसाठ' => '59',
            'साठ' => '60', 'एकसष्ठ' => '61', 'बासष्ठ' => '62', 'त्रेसष्ठ' => '63', 'चौसष्ठ' => '64', 'पासष्ठ' => '65', 'सहासष्ठ' => '66',
            'सदुसष्ठ' => '67', 'अडुसष्ठ' => '68', 'एकोणसत्तर' => '69', 'सत्तर' => '70', 'एकाहत्तर' => '71', 'बाहत्तर' => '72',
            'त्र्याहत्तर' => '73', 'चौऱ्याहत्तर' => '74', 'पंच्याहत्तर' => '75', 'शहात्तर' => '76', 'सत्याहत्तर' => '77',
            'अठ्ठ्याहत्तर' => '78', 'एकोणऐंशी' => '79', 'ऐंशी' => '80', 'एक्क्याऐंशी' => '81', 'ब्याऐंशी' => '82', 'त्र्याऐंशी' => '83',
            'चौऱ्याऐंशी' => '84', 'पंच्याऐंशी' => '85', 'शहाऐंशी' => '86', 'सत्त्याऐंशी' => '87', 'अठ्ठ्याऐंशी' => '88',
            'एकोणनव्वद' => '89', 'नव्वद' => '90', 'एक्क्याण्णव' => '91', 'ब्याण्णव' => '92', 'त्र्याण्णव' => '93', 'चौऱ्याण्णव' => '94',
            'पंच्याण्णव' => '95', 'शहाण्णव' => '96', 'सत्त्याण्णव' => '97', 'अठ्ठ्याण्णव' => '98', 'नव्याण्णव' => '99', 'शंभर' => '100',
            // How a group of people is counted in speech: "दोघे गवंडी" is two masons.
            'दोघे' => '2', 'दोघं' => '2', 'तिघे' => '3', 'तिघं' => '3', 'चौघे' => '4', 'चौघं' => '4',
        ],
    ],

    /*
     * Hundreds and thousands. A Marathi "शे" may also be written on to the number before it, as in
     * "दोनशे" or "दीडशे".
     */
    'scale_words' => [
        'en' => ['hundred' => '100', 'thousand' => '1000'],
        'hi' => ['सौ' => '100', 'हज़ार' => '1000', 'हजार' => '1000'],
        'mr' => ['शे' => '100', 'हजार' => '1000', 'हज़ार' => '1000'],
    ],

    /*
     * Fractions said on their own, or before a hundred or a thousand: "दीड", "डेढ़ सौ", "half". In
     * English a fraction below one may also end a number: "two and a half", "one and a quarter".
     */
    'fraction_words' => [
        'en' => ['half' => '0.5', 'quarter' => '0.25'],
        'hi' => ['आधा' => '0.5', 'आधी' => '0.5', 'आधे' => '0.5', 'पाव' => '0.25', 'पौन' => '0.75', 'डेढ़' => '1.5', 'डेढ' => '1.5', 'ढाई' => '2.5'],
        'mr' => ['अर्धा' => '0.5', 'अर्धी' => '0.5', 'अर्धे' => '0.5', 'पाव' => '0.25', 'पाऊण' => '0.75', 'पाउण' => '0.75', 'दीड' => '1.5', 'अडीच' => '2.5'],
    ],

    /*
     * Words said before a whole number that add to it or take from it: "साडे बारा" is 12.5, "सव्वा
     * दोन" 2.25, "पावणे तीन" 2.75. "single" is the value of the word said alone, where it has one.
     */
    'fraction_prefixes' => [
        'en' => [],
        'hi' => [
            'साढ़े' => ['add' => '0.5'], 'साढे' => ['add' => '0.5'],
            'सवा' => ['add' => '0.25', 'single' => '1.25'],
            'पौने' => ['add' => '-0.25'],
        ],
        'mr' => [
            'साडे' => ['add' => '0.5'],
            'सव्वा' => ['add' => '0.25', 'single' => '1.25'],
            'पावणे' => ['add' => '-0.25'],
        ],
    ],

    // Words that put a decimal point in a number: "twelve point five", "बारा पूर्णांक पाच".
    'decimal_words' => ['en' => ['point'], 'hi' => ['दशमलव'], 'mr' => ['पूर्णांक']],

    /*
     * A number followed by one of these is a size or a grade, not a count or a quantity: "12 mm",
     * "53 grade". Compared in lower case, as the next word after the number.
     */
    'size_words' => [
        'mm', 'm.m.', 'millimeter', 'millimetre', 'millimeters', 'millimetres', 'inch', 'inches', 'grade',
        'मिमी', 'मि.मी.', 'मि.मी', 'मिलीमीटर', 'मिलिमीटर', 'एमएम', 'एम.एम.', 'सूत', 'सुत', 'इंच', 'ग्रेड',
    ],

    // A number after one of these is a concrete or steel grade: "M 25", "Fe 500".
    'grade_words' => ['m', 'fe', 'grade', 'एम', 'एफई', 'ग्रेड'],

    // A number followed by one of these is a time of day: "9 वाजता", "10 बजे".
    'clock_words' => [
        "o'clock", 'o’clock', 'oclock', 'am', 'pm', 'a.m.', 'p.m.',
        'वाजता', 'वाजले', 'वाजेपर्यंत', 'वाजल्यापासून', 'वाजून', 'वाजे', 'बजे', 'बजकर', 'बजेतक',
    ],

    /*
     * Words that make a number beside them a count or a quantity, for the check of the hindrances and
     * the summary the model writes. Unit spellings (modules.document_reading.unit_words) and the trade
     * list are counted as well.
     */
    'quantity_words' => [
        'people', 'persons', 'workers', 'labour', 'labourers', 'trucks', 'truck', 'trips', 'brass',
        'जण', 'जणं', 'लोक', 'मजूर', 'कामगार', 'ट्रक', 'ट्रिप', 'फेऱ्या', 'ब्रास', 'गवंडी', 'हेल्पर', 'सुतार',
        'लोग', 'मजदूर', 'मज़दूर', 'मिस्त्री', 'बेलदार', 'कारीगर', 'चक्कर',
    ],
];
