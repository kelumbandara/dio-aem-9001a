<?php

return [
    'menu' => ['orders' => 'Purchase orders', 'receipts' => 'Goods receipts', 'bills' => 'Supplier bills', 'returns' => 'Purchase returns'],
    'titles' => ['orders' => 'Purchase orders', 'receipts' => 'Goods receipts', 'bills' => 'Supplier bills', 'returns' => 'Purchase returns'],
    'new_order' => 'New purchase order',
    'empty' => [
        'orders' => 'No purchase orders yet.',
        'receipts' => 'No goods receipts yet. Receive goods from an approved purchase order.',
        'bills' => 'No supplier bills yet. Enter a bill from an approved purchase order.',
        'returns' => 'No returns yet.',
    ],

    'common' => [
        'unit_price_incl' => 'Unit price (incl. VAT)', 'net_amount' => 'Net amount',
        'search' => 'Search', 'search_hint' => 'Document number', 'status' => 'Status', 'all' => 'All', 'filter' => 'Filter', 'document' => 'Document',
        'supplier' => 'Supplier', 'date' => 'Date', 'total' => 'Total', 'lines' => 'Lines', 'item' => 'Item', 'qty' => 'Qty', 'unit' => 'Unit', 'unit_price' => 'Unit price (excl. VAT)',
        'discount' => 'Disc. %', 'tax' => 'Tax code', 'amount' => 'Amount', 'subtotal' => 'Net value', 'vat' => 'VAT', 'save' => 'Save', 'cancel' => 'Cancel', 'edit' => 'Edit', 'remove' => 'Remove',
        'reason' => 'Reason', 'back' => 'Back', 'received' => 'Received', 'returned' => 'Returned', 'billed' => 'Billed', 'warehouse' => 'Warehouse', 'unit_cost' => 'Unit cost', 'value' => 'Value',
    ],

    'status' => [
        'draft' => 'Draft', 'pending_approval' => 'Waiting for approval', 'approved' => 'Approved', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'posted' => 'Posted', 'reversed' => 'Reversed',
    ],

    'form' => [
        'lines_hint_incl' => 'Supplier prices include VAT. VAT is taken out of each line total; leave the price empty to use the supplier’s price list.',
        'intro' => 'What you are buying from a supplier. Prices are without VAT; input VAT is added per line from the item’s tax code. Submitting the order sends it for approval if your company has an approval rule for purchase orders, otherwise it is approved at once. Only an approved order can receive goods or be billed.',
        'deliver_to' => 'Deliver to', 'expected_date' => 'Expected by', 'supplier_ref' => 'Supplier quotation / reference', 'terms_days' => 'Payment terms (days)', 'terms_hint' => 'Empty = the supplier’s terms',
        'notes' => 'Notes', 'lines_hint' => 'Leave the price empty to use the supplier’s price list. Prices are per unit, before VAT.', 'base' => 'base', 'price_list' => 'price list', 'item_default' => 'item default',
        'description' => 'Description (optional)', 'line_n' => 'Line :n', 'add_line' => 'Add line',
    ],

    'actions' => [
        'submit' => 'Submit for approval', 'withdraw' => 'Withdraw (back to draft)', 'receive' => 'Receive goods', 'bill' => 'Enter supplier bill',
        'return' => 'Return goods / debit note', 'reverse' => 'Reverse receipt', 'cancel_order' => 'Cancel order',
    ],

    'order' => [
        'waiting' => 'Waiting for the approvers. You can withdraw it to change it.',
        'print' => 'Print / PDF',
        'receive_help' => 'Quantities go into the order’s warehouse at the order’s price and appear as “goods received, not yet invoiced” until the bill is entered. Lot-tracked items need a lot number.',
        'dn_ref' => 'Supplier delivery note no.', 'lot' => 'Lot no.', 'expiry' => 'Expiry date',
        'bill_help' => 'You can bill what has been received and not yet billed. A price more than :pct % away from the order needs someone allowed to accept the difference.',
        'invoice_no' => 'Supplier’s invoice no.', 'due' => 'Due date', 'due_hint' => 'Empty = bill date + payment terms', 'as_ordered' => 'as ordered',
        'override_reason' => 'Accept a price difference? Reason', 'override_hint' => 'Only needed when the price is outside the tolerance',
        'return_help' => 'The goods leave the warehouse. If the goods were already billed, choose the bill: a debit note is posted for the same quantities and the quantity can be billed again when replaced.',
        'credit_bill' => 'Credit this bill', 'not_billed' => 'Not billed yet — goods only', 'can_return' => 'up to :qty', 'no_debit_note' => 'No bill was credited: only the goods went back.', 'line' => 'Order line',
    ],

    'bill' => ['variance' => 'Price variance', 'overridden' => 'Price difference accepted: :reason', 'goods_received' => 'Goods received'],

    'print' => [
        'line_total_incl' => 'Line total (incl. VAT)', 'included' => 'included',
        'title' => 'Purchase order', 'print' => 'Print', 'tax_id' => 'VAT / TIN', 'order_no' => 'Order no.', 'date' => 'Date', 'supplier' => 'Supplier', 'supplier_tax_id' => 'Supplier VAT / TIN',
        'deliver_to' => 'Deliver to', 'terms' => 'Payment terms', 'net_days' => 'Net :days days', 'due_on_receipt' => 'On receipt', 'description' => 'Description',
        'prepared_by' => 'Prepared by', 'authorised_by' => 'Authorised by', 'computer_generated' => 'This purchase order is computer-generated.',
    ],

    'flash' => [
        'saved' => ':document saved.', 'submitted' => ':document sent for approval.', 'approved' => ':document approved.', 'withdrawn' => ':document is a draft again.', 'cancelled' => ':document cancelled.',
        'received' => 'Goods receipt :document posted.', 'billed' => 'Supplier bill :document posted.', 'returned' => 'Return :document posted.', 'receipt_reversed' => 'Receipt :document reversed.',
    ],

    'errors' => ['pick_lines' => 'Enter a quantity for at least one line.'],
];
