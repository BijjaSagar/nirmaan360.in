<?php

return [
    'nav' => [
        ['place' => 'project', 'label' => 'Selfie attendance', 'route' => 'notify.selfie.index', 'active' => 'notify.selfie.*', 'can' => 'attendance.manage'],
        ['place' => 'company', 'group' => 'Account', 'label' => 'Notification settings', 'route' => 'notify.preferences', 'active' => 'notify.preferences*', 'order' => 10],
        ['place' => 'company', 'group' => 'Account', 'label' => 'Announcements', 'route' => 'notify.announcements', 'active' => 'notify.announcements*', 'can' => 'settings.manage', 'order' => 15],
        ['place' => 'company', 'group' => 'Account', 'label' => 'Company data', 'route' => 'notify.data.index', 'active' => 'notify.data.*', 'can' => 'settings.manage', 'order' => 20],
    ],

    // Push to the mobile app through Firebase Cloud Messaging. See App\Services\Notify\PushDispatcher.
    'push' => [
        // Optional. Base64 of the Firebase service account JSON, used only while no file is saved under
        // Platform admin > Integrations. A file saved there wins, as every saved setting does.
        'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),

        // Quiet hours are kept on this clock for everyone. Each person can change the times or switch them off.
        'timezone' => 'Asia/Kolkata',
        'quiet_hours' => ['from' => '22:00', 'until' => '07:00'],

        // The same thing is not pushed to the same person twice inside this many hours.
        'dedupe_hours' => 24,

        // A push that cannot reach a phone in this many hours is dropped by Firebase instead of arriving late.
        'ttl_hours' => 48,

        // A person keeps at most this many phones. The one unseen for longest goes first.
        'max_devices' => 10,

        // A phone the app has not registered from in this many days is forgotten. Firebase expires tokens at 270.
        'stale_device_days' => 270,
    ],
];
