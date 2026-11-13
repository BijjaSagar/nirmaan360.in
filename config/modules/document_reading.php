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
     * The module's own screens need the plan that has the AI agents. The plans themselves are named
     * once, in config/modules/agents.php, so no "plans" list is repeated here.
     */
    'feature' => 'ai_agents',

    // Owners and admins only: settings.manage is theirs alone.
    'nav' => [
        ['place' => 'company', 'group' => 'AI', 'label' => 'AI document readers', 'route' => 'ai-documents.edit', 'active' => 'ai-documents.*', 'can' => 'settings.manage', 'order' => 40],
    ],

    /*
     * Folders that hold a company's files and nothing else. When a company is erased, each one is
     * deleted whole, so a file that no table row points at goes too. "{org}" stands for the company's
     * id and must be part of every entry; an entry without it is ignored.
     */
    'purge_directories' => ['org-{org}/document-reads'],

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

    /*
     * Each reader call, by kind and pass: the model, the most it may write, how hard it thinks and
     * how long the HTTP call may take. A feature module registers the class for its kind in its own
     * config file; the figures stay here so they can be read and changed together.
     *
     * max_tokens, timeout and assumed_tokens_per_second belong together: a call must be able to
     * write max_tokens inside 80 percent of its timeout at the assumed speed. A test holds that.
     *
     * typical_output and typical_seconds are assumptions used for the estimate until 50 pages of
     * that kind and pass have been read; after that the median of the last 200 is used. "then"
     * names the passes that normally follow on the same page, so a quote counts them before their
     * rows exist. "stronger" is the model a person may ask for on a second try.
     *
     * "queue" names the lane a kind's page jobs wait in (see "queues" below): a slip or a bill is
     * read while someone waits at a form, a BOQ or a tender runs for a long time in the background.
     * "notify" says whether the person who started a read is told when it finishes or pauses; a
     * read that takes seconds is watched on the form itself.
     *
     * The next four are for the shared upload, start and status routes. "picture_pass" is the pass
     * an uploaded page picture is read with. "feature" is the plan feature of the module the kind
     * belongs to, checked beside the AI feature. "own_only" keeps a read to the person who started
     * it and to owners and admins: a slip or a bill is one person's work at a form. "keeps_source"
     * lets the original PDF be stored beside the page pictures, for the record; it is never parsed.
     */
    'kinds' => [
        'boq' => ['queue' => 'pages', 'notify' => true, 'picture_pass' => 'rows', 'feature' => 'reports', 'own_only' => false, 'keeps_source' => true, 'passes' => [
            'rows' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 9000, 'effort' => 'low', 'timeout' => 240, 'typical_output' => 4500, 'typical_seconds' => 75, 'then' => ['amounts'], 'stronger' => 'claude-opus-5-5'],
            'amounts' => ['model' => 'claude-haiku-5-5', 'max_tokens' => 4000, 'effort' => 'low', 'timeout' => 120, 'typical_output' => 1200, 'typical_seconds' => 20, 'stronger' => 'claude-sonnet-5-5'],
        ]],
        'slip' => ['queue' => 'interactive', 'notify' => false, 'picture_pass' => 'main', 'feature' => 'site_control', 'own_only' => true, 'keeps_source' => false, 'passes' => [
            'main' => ['model' => 'claude-haiku-5-5', 'max_tokens' => 2000, 'effort' => 'low', 'timeout' => 90, 'typical_output' => 700, 'typical_seconds' => 15, 'stronger' => 'claude-sonnet-5-5'],
            'recheck' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 2000, 'effort' => 'low', 'timeout' => 90, 'typical_output' => 700, 'typical_seconds' => 15],
        ]],
        'bill' => ['queue' => 'interactive', 'notify' => false, 'picture_pass' => 'main', 'feature' => 'site_control', 'own_only' => true, 'keeps_source' => false, 'passes' => [
            'main' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 4000, 'effort' => 'low', 'timeout' => 120, 'typical_output' => 1500, 'typical_seconds' => 30],
            'recheck' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 4000, 'effort' => 'low', 'timeout' => 120, 'typical_output' => 1500, 'typical_seconds' => 30],
        ]],
        'tender' => ['queue' => 'pages', 'notify' => true, 'picture_pass' => 'image', 'feature' => 'crm', 'own_only' => false, 'keeps_source' => false, 'passes' => [
            'text' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 8000, 'effort' => 'low', 'timeout' => 240, 'typical_output' => 2500, 'typical_seconds' => 60],
            'image' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 6000, 'effort' => 'low', 'timeout' => 180, 'typical_output' => 1500, 'typical_seconds' => 40],
        ]],
    ],

    /* Text calls, by purpose. The same three figures belong together as for the kinds above. */
    'purposes' => [
        'wording' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 2000, 'effort' => 'low', 'timeout' => 90],
        'hindrance_groups' => ['model' => 'claude-sonnet-5-5', 'max_tokens' => 3000, 'effort' => 'low', 'timeout' => 90],
        'rate_match' => ['model' => 'claude-haiku-5-5', 'max_tokens' => 3000, 'effort' => 'low', 'timeout' => 90],
        'translate' => ['model' => 'claude-haiku-5-5', 'max_tokens' => 3000, 'effort' => 'low', 'timeout' => 90],
        'voice' => ['model' => 'claude-haiku-5-5', 'max_tokens' => 3000, 'effort' => 'low', 'timeout' => 90],
    ],

    // An assumption: no speed is published or measured yet. The admin's dry run shows the real figure.
    'assumed_tokens_per_second' => 50,

    // Tokens of a call's fixed instructions and form, until they are counted once with a saved key.
    'prompt_tokens' => 1200,

    // A text page whose tokens were not counted beforehand is priced at this many tokens a character.
    'text_tokens_per_character' => '1',

    // The estimate switches from the assumed figures to the median of the last "window" read pages once "minimum" exist.
    'typical' => ['window' => 200, 'minimum' => 50, 'cache_seconds' => 600],

    // Pages of one read that may be with the model at the same time.
    'parallel_pages' => 1,

    /*
     * The queue each lane of jobs is sent to. "default" is the queue the worker already listens to,
     * so nothing needs a new worker. The platform settings ai_queue_pages, ai_queue_interactive and
     * ai_queue_prepare replace a name without a deploy; set one only after a worker listens to it,
     * because a job sent to a queue with no worker never runs. docs/AI_READERS.md has the commands.
     */
    'queues' => ['pages' => 'default', 'interactive' => 'default', 'prepare' => 'default'],

    // A read that was finished but never confirmed or discarded is closed after this many days.
    'abandon_days' => 14,

    // Page pictures, extracted text and what the reader returned are deleted this many days after a read ends.
    'keep_days' => 30,

    /*
     * What one read may cost. The offered limit is the estimate times cap_factor, rounded up to a
     * whole credit, and never above the worst case or the company's maximum. A read whose estimate
     * times floor_factor does not fit the maximum or the free balance is not started as it is.
     * An owner may lower the maximum for the company; nobody may raise it above this figure.
     */
    'max_credits_per_read' => '650',
    'cap_factor' => '1.6',
    'floor_factor' => '1.2',

    // Someone who is not an owner or admin may not push open holds above this share of the wallet.
    'hold_share' => '0.50',

    // The owners are told when the free balance drops below this many credits.
    'low_balance_credits' => '100',

    /*
     * Credits a day (Asia/Kolkata), counted from what was charged today plus what is on hold.
     * A feature is a reader kind or a text purpose. One that is not listed has no budget of its own:
     * BOQ, bill and tender reads are bounded by the per-read limit and the hold share instead.
     * "total" covers every feature together and is off until an owner sets it. Owners and admins
     * change any of these for their company on the settings screen.
     */
    'budgets' => [
        'user' => ['slip' => '30', 'voice' => '20', 'wording' => '30', 'rate_match' => '20', 'hindrance_groups' => '20', 'translate' => '20'],
        'company' => ['slip' => '150', 'voice' => '100', 'wording' => '100', 'rate_match' => '60', 'hindrance_groups' => '60', 'translate' => '60', 'total' => null],
    ],

    // Owners are told once a day at each of these percentages of a company budget.
    'budget_notice_percents' => [50, 80, 100],

    // Unusable answers (pages and text calls together) a company may cause in 24 hours before its reads stop.
    'failed_pages_per_day' => 40,

    // Provider cost in US dollars a day that a company may cause without being charged for it.
    'absorbed_usd_per_day' => ['paying' => '2.00', 'trial' => '0.50'],

    // A company on a trial gets this much for the whole trial, unless the platform admin lifts it.
    'trial' => ['pages' => 20, 'text_calls' => 30, 'max_credits_per_read' => '60'],

    /*
     * Platform limits on provider cost, in US dollars. daily_usd, month_share and trial_daily_usd
     * are the defaults of the platform settings ai_reader_daily_usd, ai_reader_month_share and
     * ai_trial_daily_usd. The monthly limit is month_share of ai_tier_monthly_cap_usd, which has no
     * default: the platform admin types the cap shown in the provider's console.
     */
    'ceilings' => ['daily_usd' => '25', 'month_share' => '0.60', 'trial_daily_usd' => '10', 'cache_seconds' => 60],

    // No single company may use more than this share of the daily limit.
    'company_share_of_ceiling' => '0.40',

    // The platform admins are told at these percentages of a limit, and again when it stops the readers.
    'ceiling_notice_percents' => [50, 80],

    /*
     * Where the browser finds what pdf.js needs to draw scanned pages: the decoders for fax, JBIG2
     * and JPEG 2000 pictures, the character maps, the standard fonts and the colour profile. The
     * build copies them from the installed pdfjs-dist into this folder under public/ (see
     * vite.config.js). The version here must be the installed one; a test compares the two.
     */
    'pdfjs_assets' => 'pdfjs-assets/6.5.438',

    // The most one upload request may carry, in kilobytes: a page picture, and the original PDF kept for the record.
    'upload' => ['page_kb' => 6144, 'source_kb' => 10240],

    // A page whose answer was cut off can be read again as two halves. Each half is this much of the page, in parts per thousand, so the two overlap.
    'half_page_share' => 550,

    // "Read this page again" is offered this many times for a page the reader answered unusably.
    'manual_retries' => 2,

    // A read that is running, has sent nothing and was started this many seconds ago is reported as not started by the server yet.
    'stalled_seconds' => 180,
];
