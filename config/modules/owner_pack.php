<?php

use App\Models\ClientQuotation;
use App\Models\OwnerUpdate;
use App\Services\OwnerPack\DescriptionTranslation;
use App\Services\OwnerPack\OwnerUpdateWording;
use App\Services\OwnerPack\QuotationWording;

/*
 * Home-owner pack: a quotation with a payment schedule and a weekly update that a contractor gives
 * a client, each shared as a link the contractor sends himself. Every figure is worked out by code
 * from the company's own records, and the app sends nothing to a client. On the AI plan a model may
 * write the two sentences of each that state no figure, and translate a quotation's line descriptions
 * for a person to accept (text_calls below); every document stands complete without it.
 */
return [
    'feature' => 'owner_pack',
    'plans' => ['contractor', 'business', 'enterprise'],

    // A quotation is nothing but money, so every quotation route also needs money.view (config/modules/site_control.php).
    // owner_pack.progress prepares a weekly update. A site engineer holds it and may prepare one that carries no money;
    // the payment position, the approval and the link need money.view as well.
    'permissions' => [
        'pm' => ['owner_pack.*'],
        'accounts' => ['owner_pack.view'],
        'qs' => ['owner_pack.view'],
        'engineer' => ['owner_pack.progress'],
    ],

    // Offered only to someone who may open the page: a custom role can hold owner_pack.view without money.view.
    'nav' => [
        ['place' => 'project', 'group' => 'Home owner', 'label' => 'Quotations', 'route' => 'quotations.index', 'active' => 'quotations.*', 'can' => ['owner_pack.view', 'money.view'], 'order' => 10],
        ['place' => 'project', 'group' => 'Home owner', 'label' => 'Weekly updates', 'route' => 'owner-updates.index', 'active' => 'owner-updates.*', 'can' => 'owner_pack.progress', 'order' => 20],
    ],
    'icons' => [],

    // Days a shared link stays open. The date is kept on the row, so a link can also be withdrawn sooner.
    'share_link_days' => 14,

    // Limits that keep a quotation a document a person can check line by line.
    'max_lines' => 200,
    'max_stages' => 24,

    // A weekly update: photos a person may pick, work rows it lists (the rest are counted, not dropped
    // silently), and the longest edge of a photo as it is drawn again for the client.
    'max_photos' => 12,
    'max_work_lines' => 40,
    'photo_edge' => 1600,
    // The largest stored photo the server opens to draw it again, in pixels. Opening one takes about
    // four bytes of memory a pixel, so this follows the memory a web request may use.
    'photo_max_pixels' => 17_000_000,

    // The company's own folder for what this module draws from its records: the copy of each photo a shared
    // weekly update has shown (App\Services\OwnerPack\SharedPhoto::COPIES). No table row names those files,
    // so the folder is deleted whole when the company is erased.
    'purge_directories' => ['org-{org}/owner-pack'],

    /*
     * The calls this module may make to the AI model, on the ai_agents feature. The model, its limits and the
     * daily budgets of each are in config/modules/document_reading.php under "purposes" and "budgets".
     * - wording: the AI writes the opening and the closing of a quotation's cover note or of a weekly update.
     * - translate: the AI translates the descriptions of a draft quotation's lines into its language, Hindi or
     *   Marathi. Each translation waits on its line until a person accepts it.
     */
    'text_calls' => [
        'wording' => [ClientQuotation::class => QuotationWording::class, OwnerUpdate::class => OwnerUpdateWording::class],
        DescriptionTranslation::PURPOSE => [ClientQuotation::class => DescriptionTranslation::class],
    ],

    /*
     * How much one translation request sends: the lines that wait for a translation, at most "most_lines" of them,
     * and only descriptions up to "description_chars" characters (a longer one prints in its own words).
     * The whole answer comes back in one piece, so the lines also stop once their translations would fill more than
     * "answer_share" of what the translate purpose may write (its max_tokens in config/modules/document_reading.php);
     * the rest is room for the model's thinking. A translation is planned as "growth" times as long as its
     * description, at the tokens a character the readers price text at. Lines that do not fit go with the next request.
     */
    'translation' => [
        'most_lines' => 20,
        'description_chars' => 1000,
        'answer_share' => '0.6',
        'growth' => '1.5',
    ],
];
