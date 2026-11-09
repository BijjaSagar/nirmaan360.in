<?php

/*
 * The public home page story: material, contractor payment and time against the BOQ.
 * Not a product module, so no feature, plans or permissions. It only adds the line
 * icons the page draws, and its text lives in lang/modules/site_pitch.
 */
return [
    'icons' => [
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'cement' => '<path d="M9 3.5h6v3H9z"/><path d="M7.5 6.5h9l2.5 12.5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1z"/><path d="M8.5 12h7"/>',
        'truck' => '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'scale' => '<path d="M12 4v16M7 20h10M4 8h16"/><path d="m6 8-3 6.5a3 3 0 0 0 6 0zM18 8l-3 6.5a3 3 0 0 0 6 0z"/>',
        'drop' => '<path d="M12 3.5s6 6.5 6 10.5a6 6 0 0 1-12 0c0-4 6-10.5 6-10.5z"/>',
        'bolt' => '<path d="M13 3 5 13.5h6L10.5 21 19 10.5h-6z"/>',
        'floors' => '<rect x="5" y="3" width="14" height="18" rx="1"/><path d="M5 9h14M5 15h14M12 3v18"/>',
    ],
];
