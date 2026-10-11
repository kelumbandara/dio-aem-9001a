<?php

return [
    'menu' => ['stock' => 'Stock', 'documents' => 'Stock documents', 'lots' => 'Lots & expiry', 'reorder' => 'Reorder', 'warehouses' => 'Warehouses'],

    'flash' => [
        'saved' => 'Saved.',
        'posted' => 'Posted :document.',
        'reversed' => ':document has been reversed.',
        'awaiting_approval' => 'This adjustment needs approval first. Request :document has been sent; stock has not changed yet.',
    ],

    'common' => [
        'search' => 'Search', 'filter' => 'Filter', 'all' => 'All', 'status' => 'Status', 'type' => 'Type', 'date' => 'Date', 'from' => 'From', 'to' => 'To',
        'active' => 'Active', 'inactive' => 'Inactive', 'code' => 'Code', 'name' => 'Name', 'item' => 'Item', 'warehouse' => 'Warehouse',
        'location' => 'Location', 'lot' => 'Lot', 'save' => 'Save', 'cancel' => 'Cancel', 'edit' => 'Edit', 'back' => 'Back', 'remove' => 'Remove',
    ],

    'stock' => [
        'title' => 'Stock on hand', 'ledger' => 'Stock ledger', 'search_hint' => 'Item code, name or barcode', 'only_below' => 'Only items below their minimum',
        'on_hand' => 'On hand', 'reserved' => 'Reserved', 'available' => 'Available', 'avg_cost' => 'Average cost', 'value' => 'Value', 'qty' => 'Quantity',
        'empty' => 'No stock found.', 'where' => 'Where it is', 'none_here' => 'No stock of this item anywhere.', 'recent' => 'Recent movements',
        'full_ledger' => 'Full ledger of this item', 'no_movements' => 'No movements.',
    ],

    'documents' => [
        'title' => 'Stock documents', 'document' => 'Document', 'reason' => 'Reason', 'reference' => 'Reference', 'notes' => 'Notes', 'empty' => 'No documents yet.',
        'search_hint' => 'Document number or reference',
        'types' => ['receipt' => 'Receipt', 'issue' => 'Issue', 'transfer' => 'Transfer', 'adjustment' => 'Adjustment'],
        'status' => ['posted' => 'Posted', 'reversed' => 'Reversed'],
        'reasons' => [
            'purchase' => 'Purchase', 'production' => 'Production', 'return' => 'Return', 'opening' => 'Opening stock', 'other' => 'Other',
            'sale' => 'Sale', 'sample' => 'Sample', 'scrap' => 'Scrap', 'transfer' => 'Transfer',
            'count' => 'Stock count', 'damage' => 'Damage', 'expiry' => 'Expiry', 'loss' => 'Loss', 'found' => 'Found', 'reversal' => 'Reversal',
        ],
        'new_receipt' => 'Receive stock', 'new_issue' => 'Issue stock', 'new_transfer' => 'Transfer stock', 'new_adjustment' => 'Adjust stock',
        'intro_receipt' => 'Add stock to a warehouse (for example goods received, or opening balances). Each line needs the cost of one unit.',
        'intro_issue' => 'Take stock out of a warehouse. Leave the lot blank to use the earliest-expiring lots first.',
        'intro_transfer' => 'Move stock from one warehouse or location to another. The cost does not change.',
        'intro_adjustment' => 'Correct stock for a count, damage, expiry or loss. This may need approval before it takes effect.',
        'lines' => 'Lines', 'add_line' => 'Add line', 'line_n' => 'Line :n', 'post' => 'Post', 'unit' => 'Unit', 'base' => 'base', 'optional' => 'optional',
        'from' => 'From', 'to' => 'To', 'to_location' => 'To location', 'direction' => 'Direction', 'dir_in' => 'Add (found)', 'dir_out' => 'Remove (loss)',
        'unit_cost' => 'Cost per unit (LKR)', 'cost_avg' => 'blank = average', 'lot_new' => 'lot number', 'lot_fefo' => 'blank = earliest expiry', 'by_lot' => 'by lot', 'note' => 'Note',
        'reverses' => 'reverses', 'reversed_by' => 'reversed by', 'posted_by' => 'Posted by', 'posted_at' => 'Posted at',
        'reverse' => 'Reverse this document', 'reverse_hint' => 'Posts an opposite document at the original quantities and values. It only works if the stock is still there.', 'reverse_note' => 'Why', 'reverse_confirm' => 'Reverse this document?',
    ],

    'lots' => [
        'title' => 'Lots and expiry', 'expiry' => 'Expiry', 'received' => 'Received', 'expired' => 'Expired', 'days_left' => ':count day(s) left', 'empty' => 'No lots with stock.',
        'filter_all' => 'All lots', 'filter_expired' => 'Expired or expiring today', 'filter_d30' => 'Within 30 days', 'filter_d90' => 'Within 90 days',
    ],

    'reorder' => [
        'title' => 'Reorder levels', 'below' => 'Below the minimum now', 'rules' => 'Rules', 'min' => 'Minimum', 'order_qty' => 'Order quantity', 'company' => 'All warehouses',
        'none_below' => 'Nothing is below its minimum.', 'no_rules' => 'No reorder rules yet.', 'add' => 'Add rule', 'base_unit_note' => 'Quantities are in the item\'s base unit.',
    ],

    'warehouses' => [
        'title' => 'Warehouses', 'new' => 'New warehouse', 'edit_title' => 'Edit :code', 'empty' => 'No warehouses yet.', 'branch' => 'Branch / site', 'no_branch' => 'No branch',
        'locations' => 'Locations', 'not_used' => 'not used', 'default' => 'Default', 'add_location' => 'Add location', 'activate' => 'Activate', 'deactivate' => 'Deactivate',
        'uses_locations' => 'Keep stock by location (rack / bin)',
        'uses_locations_hint' => 'Off: all stock of this warehouse is kept in its single default location. Turn on to track racks or bins.',
    ],
];
