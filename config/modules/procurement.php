<?php

return [
    'feature' => 'procurement',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [
        'pm' => ['procurement.*', 'indent.*'],
        'qs' => ['procurement.view', 'procurement.masters'],
        'engineer' => ['procurement.view', 'procurement.stock', 'indent.create'],
        'store' => ['procurement.view', 'procurement.stock', 'procurement.alerts', 'indent.create'],
        'accounts' => ['procurement.view', 'procurement.purchase'],
        'viewer' => ['procurement.view'],
    ],
    // Used until the company has its own approval rule for doc_type "indent".
    'indent_approval_levels' => ['pm'],
    'nav' => [
        ['place' => 'company', 'group' => 'Procurement', 'label' => 'Materials', 'route' => 'procurement.materials.index', 'active' => 'procurement.materials.*', 'can' => 'procurement.view', 'order' => 10],
        ['place' => 'project', 'label' => 'Stores & stock', 'route' => 'procurement.stock.index', 'active' => 'procurement.stock.*', 'can' => 'procurement.view', 'order' => 10],
        ['place' => 'project', 'label' => 'Indents', 'route' => 'procurement.indents.index', 'active' => 'procurement.indents.*', 'can' => 'procurement.view', 'order' => 20],
        ['place' => 'project', 'label' => 'RFQs', 'route' => 'procurement.rfqs.index', 'active' => 'procurement.rfqs.*', 'can' => 'procurement.view', 'order' => 30],
        ['place' => 'project', 'label' => 'Purchase orders', 'route' => 'procurement.purchase-orders.index', 'active' => 'procurement.purchase-orders.*', 'can' => 'procurement.view', 'order' => 40],
        ['place' => 'project', 'label' => 'Goods receipts', 'route' => 'procurement.grns.index', 'active' => 'procurement.grns.*', 'can' => 'procurement.view', 'order' => 50],
        ['place' => 'project', 'label' => 'Material issues', 'route' => 'procurement.issues.index', 'active' => 'procurement.issues.*', 'can' => 'procurement.view', 'order' => 60],
        ['place' => 'project', 'label' => 'Site transfers', 'route' => 'procurement.transfers.index', 'active' => 'procurement.transfers.*', 'can' => 'procurement.view', 'order' => 70],
        ['place' => 'project', 'label' => 'Material returns', 'route' => 'procurement.returns.index', 'active' => 'procurement.returns.*', 'can' => 'procurement.view', 'order' => 80],
        ['place' => 'project', 'label' => 'Reconciliation', 'route' => 'procurement.reconciliation.index', 'active' => 'procurement.reconciliation.*', 'can' => 'procurement.view', 'order' => 90],
    ],
];
