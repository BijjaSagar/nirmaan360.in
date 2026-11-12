<?php

return [
    'nav' => [
        ['place' => 'company', 'group' => 'Account', 'label' => 'My devices', 'route' => 'devices.index', 'active' => 'devices.*', 'order' => 10],
        ['place' => 'company', 'group' => 'Account', 'label' => 'Delete my account', 'route' => 'account.delete', 'active' => 'account.*', 'order' => 90],
    ],
];
