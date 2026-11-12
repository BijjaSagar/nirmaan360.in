<?php

/*
| Word lists and limits for App\Services\Wording.
|
| Letters, owner updates and explanations are put together from sentences the code writes (they carry
| every figure, date and name) and figure-free sentences a model may write around them. The lists here
| decide when a sentence from the model is refused because it states a figure in words.
|
| How an entry is matched
| - 'word'       the whole word, in any letter case.
| - 'stem*'      any word that starts with the stem. Used where a language adds endings to the word.
| - 'two words'  the words one after the other, whatever stands between them ("per cent", "per-cent").
| Devanagari is compared without the nukta and with chandrabindu read as anusvara, so हज़ार and हजार,
| पाँच and पांच are the same word here.
|
| Every list is checked in every language, because a Hindi letter can carry an English or a Marathi word.
|
| These lists were written by the engineers. A native reader of Hindi and of Marathi has to review them
| before any model wording is shown to a customer.
*/

return [

    // Longest text a model may put into one free sentence block. Longer text is cut at a word.
    'free_slot_max_chars' => 1200,

    // Month names used when a date is printed. Digits stay ASCII in every language.
    'months' => [
        'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'hi' => [1 => 'जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'],
        'mr' => [1 => 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'],
    ],

    // A month name in a model sentence is refused. "March", "Mar", "May" and "August" are handled below.
    'month_names' => [
        'en' => [
            'january', 'jan', 'february', 'feb', 'april', 'apr', 'june', 'jun', 'july', 'jul', 'aug',
            'september', 'sept', 'sep', 'october', 'oct', 'november', 'nov', 'december', 'dec',
        ],
        'hi' => [
            'जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'सितम्बर',
            'अक्टूबर', 'अक्तूबर', 'नवंबर', 'नवम्बर', 'दिसंबर', 'दिसम्बर',
        ],
        'mr' => [
            'जानेवारी*', 'फेब्रुवारी*', 'मार्च*', 'एप्रिल*', 'जुलै*', 'ऑगस्ट*', 'सप्टेंबर*', 'ऑक्टोबर*', 'नोव्हेंबर*', 'डिसेंबर*',
            // "जून*" would also refuse जूनियर, and "मे*" would refuse मेहनत, so their endings are listed.
            'जून', 'जूनमध्ये', 'जूनपर्यंत', 'जूनपासून', 'जूनअखेर', 'जूनला', 'जूनच्या',
            'मे', 'मेमध्ये', 'मेपर्यंत', 'मेपासून', 'मेअखेर',
        ],
    ],

    // English month names that are also everyday words ("you may", "march ahead", "your august office"):
    // refused only when written with a capital letter.
    'month_names_capital_only' => ['March', 'Mar', 'May', 'August'],

    // "May we request ..." opens many letters, so a capital "May" as the first word of a sentence, or right after
    // a comma ("Dear Sir, May we ..."), is let through.
    'month_names_not_first_in_sentence' => ['May'],

    // In a Hindi text "मे" is nearly always "में" typed without its dot, not the Marathi month.
    'month_names_not_in' => [
        'hi' => ['मे'],
    ],

    // A weekday states a date as much as a month does.
    'weekday_names' => [
        'en' => [
            'monday', 'mondays', 'tuesday', 'tuesdays', 'wednesday', 'wednesdays', 'thursday', 'thursdays',
            'friday', 'fridays', 'saturday', 'saturdays', 'sunday', 'sundays',
        ],
        'hi' => ['सोमवार*', 'मंगलवार*', 'बुधवार*', 'गुरुवार*', 'बृहस्पतिवार*', 'वीरवार*', 'शुक्रवार*', 'शनिवार*', 'रविवार*', 'इतवार*'],
        'mr' => ['सोमवार*', 'मंगळवार*', 'बुधवार*', 'गुरुवार*', 'शुक्रवार*', 'शनिवार*', 'रविवार*'],
    ],

    // "%" and the words for it are refused wherever they stand.
    'percent_words' => [
        'en' => ['percent', 'percentage', 'percentages', 'pct', 'per cent', 'per centum'],
        'hi' => ['प्रतिशत', 'फ़ीसदी', 'फ़ीसद', 'परसेंट', 'पर्सेंट'],
        // Whole words only: "टक्क*" would also refuse टक्कर.
        'mr' => [
            'टक्के', 'टक्का', 'टक्क्यांनी', 'टक्क्यांची', 'टक्क्यांचा', 'टक्क्यांचे', 'टक्क्यांपर्यंत', 'टक्क्यांहून',
            'टक्क्यांपेक्षा', 'टक्केवारी', 'टक्केवारीने', 'शेकडा',
        ],
    ],

    // Short forms that stand for a currency mark. The marks themselves (₹, $ and so on) are refused by code.
    'currency_words' => ['rs', 'inr', 'रु', 'रू'],

    // Words that state a figure all by themselves and have no everyday other meaning.
    'figure_words' => [
        'en' => ['fortnight', 'fortnights', 'fortnightly', 'twice', 'thrice'],
        'hi' => ['पखवाड़*', 'दुगुना', 'दुगुनी', 'दुगना', 'दोगुना', 'दोगुनी', 'तिगुना', 'तिगुनी'],
        'mr' => ['पंधरवड*', 'दुप्पट', 'तिप्पट', 'दुपटीने', 'तिपटीने'],
    ],

    /*
    | A number word is refused only when it stands within two words of a quantity word.
    | Alone, "one", "एक" and "दो" (also a verb) are everyday words.
    */
    'number_words' => [
        'en' => [
            'zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen',
            'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety',
            'hundred', 'hundreds', 'thousand', 'thousands', 'lakh', 'lakhs', 'lac', 'lacs', 'crore', 'crores',
            'million', 'millions', 'billion', 'billions', 'half', 'dozen', 'dozens', 'couple',
        ],
        'hi' => [
            'शून्य', 'एक', 'दो', 'तीन', 'चार', 'पाँच', 'छह', 'छः', 'छे', 'सात', 'आठ', 'नौ', 'दस',
            'ग्यारह', 'बारह', 'तेरह', 'चौदह', 'पंद्रह', 'पन्द्रह', 'सोलह', 'सत्रह', 'अठारह', 'उन्नीस', 'बीस',
            'इक्कीस', 'बाईस', 'तेईस', 'चौबीस', 'पच्चीस', 'छब्बीस', 'सत्ताईस', 'अट्ठाईस', 'उनतीस', 'उनत्तीस', 'तीस',
            'इकतीस', 'इकत्तीस', 'बत्तीस', 'तैंतीस', 'चौंतीस', 'पैंतीस', 'छत्तीस', 'सैंतीस', 'अड़तीस', 'उनतालीस', 'चालीस',
            'इकतालीस', 'बयालीस', 'तैंतालीस', 'चौवालीस', 'चवालीस', 'पैंतालीस', 'छियालीस', 'सैंतालीस', 'अड़तालीस', 'उनचास', 'पचास',
            'इक्यावन', 'बावन', 'तिरपन', 'चौवन', 'पचपन', 'छप्पन', 'सत्तावन', 'अट्ठावन', 'उनसठ', 'साठ',
            'इकसठ', 'बासठ', 'तिरसठ', 'चौंसठ', 'पैंसठ', 'छियासठ', 'सड़सठ', 'अड़सठ', 'उनहत्तर', 'सत्तर',
            'इकहत्तर', 'बहत्तर', 'तिहत्तर', 'चौहत्तर', 'पचहत्तर', 'छिहत्तर', 'सतहत्तर', 'अठहत्तर', 'उनासी', 'अस्सी',
            'इक्यासी', 'बयासी', 'तिरासी', 'चौरासी', 'पचासी', 'छियासी', 'सत्तासी', 'अट्ठासी', 'नवासी', 'नब्बे',
            'इक्यानवे', 'बानवे', 'तिरानवे', 'चौरानवे', 'पचानवे', 'छियानवे', 'सत्तानवे', 'अट्ठानवे', 'निन्यानवे',
            'सौ', 'सैकड़ों', 'हज़ार', 'हज़ारों', 'लाख', 'लाखों', 'करोड़', 'करोड़ों',
            'आधा', 'आधे', 'आधी', 'डेढ़', 'ढाई', 'पौने', 'सवा', 'साढ़े', 'दोनों', 'तीनों', 'चारों', 'दर्जन',
        ],
        'mr' => [
            'शून्य', 'एक', 'एका', 'दोन', 'तीन', 'चार', 'पाच', 'सहा', 'सात', 'आठ', 'नऊ', 'दहा',
            'अकरा', 'बारा', 'तेरा', 'चौदा', 'पंधरा', 'सोळा', 'सतरा', 'अठरा', 'एकोणीस', 'वीस',
            'एकवीस', 'बावीस', 'तेवीस', 'चोवीस', 'पंचवीस', 'सव्वीस', 'सत्तावीस', 'अठ्ठावीस', 'एकोणतीस', 'तीस',
            'एकतीस', 'बत्तीस', 'तेहतीस', 'चौतीस', 'पस्तीस', 'छत्तीस', 'सदतीस', 'अडतीस', 'एकोणचाळीस', 'चाळीस',
            'एकेचाळीस', 'एक्केचाळीस', 'बेचाळीस', 'त्रेचाळीस', 'चव्वेचाळीस', 'पंचेचाळीस', 'सेहेचाळीस', 'सत्तेचाळीस', 'अठ्ठेचाळीस', 'एकोणपन्नास', 'पन्नास',
            'एक्कावन्न', 'बावन्न', 'त्रेपन्न', 'चोपन्न', 'पंचावन्न', 'छप्पन्न', 'सत्तावन्न', 'अठ्ठावन्न', 'एकोणसाठ', 'साठ',
            'एकसष्ट', 'बासष्ट', 'त्रेसष्ट', 'चौसष्ट', 'पासष्ट', 'सहासष्ट', 'सदुसष्ट', 'अडुसष्ट', 'एकोणसत्तर', 'सत्तर',
            'एकाहत्तर', 'बाहत्तर', 'त्र्याहत्तर', 'चौऱ्याहत्तर', 'पंचाहत्तर', 'शहात्तर', 'सत्याहत्तर', 'सत्त्याहत्तर', 'अठ्ठ्याहत्तर', 'एकोणऐंशी', 'ऐंशी',
            'एक्याऐंशी', 'ब्याऐंशी', 'त्र्याऐंशी', 'चौऱ्याऐंशी', 'पंच्याऐंशी', 'शहाऐंशी', 'सत्त्याऐंशी', 'अठ्ठ्याऐंशी', 'एकोणनव्वद', 'नव्वद',
            'एक्याण्णव', 'ब्याण्णव', 'त्र्याण्णव', 'चौऱ्याण्णव', 'पंचाण्णव', 'शहाण्णव', 'सत्त्याण्णव', 'अठ्ठ्याण्णव', 'नव्याण्णव',
            'शंभर', 'शेकडो', 'दोनशे', 'तीनशे', 'चारशे', 'पाचशे', 'सहाशे', 'सातशे', 'आठशे', 'नऊशे',
            'हजार', 'हजारो', 'लाख', 'लाखो', 'कोटी', 'कोट्यवधी',
            'अर्धा', 'अर्धे', 'अर्धी', 'दीड', 'अडीच', 'साडे', 'सव्वा', 'पाऊण', 'पावणे', 'दोन्ही', 'तिन्ही', 'डझन',
        ],
    ],

    // "a week" and "an hour" state one week and one hour, just as "एक आठवडा" does. Refused when the article stands right before the unit.
    'unit_articles' => ['a', 'an'],
    'article_units' => ['day', 'week', 'month', 'year', 'hour'],

    // Units of time, money and measure. "percent" is refused by its own rule above.
    'quantity_words' => [
        'en' => [
            'day', 'days', 'week', 'weeks', 'month', 'months', 'year', 'years', 'hour', 'hours', 'minute', 'minutes',
            'rupee', 'rupees', 'paisa', 'paise', 'lakh', 'lakhs', 'lac', 'lacs', 'crore', 'crores',
            'hundred', 'hundreds', 'thousand', 'thousands', 'million', 'millions',
            'metre', 'metres', 'meter', 'meters', 'foot', 'feet', 'ft', 'inch', 'inches', 'mm', 'cm', 'km',
            'kilometre', 'kilometres', 'kilometer', 'kilometers', 'sq', 'sqm', 'sqft', 'cum', 'cft', 'rmt', 'cubic', 'square',
            'kg', 'kgs', 'kilogram', 'kilograms', 'kilo', 'kilos', 'gram', 'grams', 'tonne', 'tonnes', 'ton', 'tons', 'mt',
            'quintal', 'quintals', 'litre', 'litres', 'liter', 'liters', 'bag', 'bags', 'brass', 'nos',
            'unit', 'units', 'piece', 'pieces', 'pcs', 'truck', 'trucks', 'trip', 'trips', 'load', 'loads',
            'acre', 'acres', 'hectare', 'hectares', 'guntha', 'gunthas',
        ],
        'hi' => [
            'दिन*', 'दिवस*', 'हफ़्त*', 'सप्ताह*', 'महीन*', 'माह', 'मास', 'साल*', 'वर्ष', 'वर्षों', 'बरस', 'बरसों',
            'घंट*', 'घण्ट*', 'मिनट*', 'रुपय*', 'रुपए', 'पैस*', 'लाख*', 'करोड़*', 'हज़ार*', 'सौ', 'सैकड़*',
            'मीटर*', 'फुट', 'फ़ुट', 'फीट', 'फ़ीट', 'इंच*', 'किलो*', 'ग्राम', 'टन', 'क्विंटल', 'कुंतल', 'लीटर*',
            'बोरी', 'बोरियां', 'बोरियों', 'बोरे', 'बैग', 'ब्रास', 'नग', 'घन', 'वर्ग', 'एकड़', 'हेक्टेयर', 'ट्रक*', 'खेप*',
        ],
        'mr' => [
            'दिवस*', 'आठवड*', 'महिन*', 'साल', 'वर्ष', 'वर्षे', 'वर्षांत', 'वर्षात', 'वर्षांनी', 'वर्षांचा', 'वर्षांची', 'वर्षांचे',
            'वर्षांपूर्वी', 'वर्षांपासून', 'वर्षभर', 'तास*', 'मिनिट*', 'रुपय*', 'पैस*', 'लाख*', 'कोटी*', 'हजार*', 'शंभर', 'शेकड*',
            'मीटर*', 'मिटर*', 'फूट', 'फुट', 'इंच*', 'किलो*', 'ग्रॅम', 'टन', 'क्विंटल', 'लिटर*', 'लीटर*',
            'पोती', 'पोते', 'गोणी', 'गोण्या', 'बॅग*', 'ब्रास', 'नग', 'घन', 'घनमीटर', 'चौरस', 'एकर', 'हेक्टर', 'गुंठ*', 'ट्रक*', 'खेप*',
        ],
    ],

];
