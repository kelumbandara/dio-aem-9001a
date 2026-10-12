<?php

return [
    'menu' => ['accounts' => 'Chart of accounts', 'journals' => 'Journals', 'receivables' => 'Receivables', 'payables' => 'Payables', 'reports' => 'Financial reports', 'periods' => 'Fiscal periods', 'setup' => 'Finance setup'],

    'flash' => [
        'saved' => 'Saved.', 'posted' => 'Posted :document.', 'reversed' => ':document has been reversed.',
        'awaiting_approval' => 'This needs approval first. Request :document has been sent; nothing has been posted yet.',
    ],

    'common' => [
        'search' => 'Search', 'filter' => 'Filter', 'all' => 'All', 'status' => 'Status', 'date' => 'Date', 'from' => 'From', 'to' => 'To', 'code' => 'Code', 'name' => 'Name',
        'description' => 'Description', 'amount' => 'Amount', 'total' => 'Total', 'active' => 'Active', 'inactive' => 'Inactive', 'save' => 'Save', 'cancel' => 'Cancel',
        'edit' => 'Edit', 'back' => 'Back', 'remove' => 'Remove',
    ],

    'classes' => ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Costs and expenses'],

    'accounts' => [
        'title' => 'Chart of accounts', 'show_active' => 'Active accounts', 'show_all' => 'All accounts', 'new' => 'New account', 'edit_title' => 'Edit :code', 'balance' => 'Balance (debit − credit)',
        'balance_note' => 'A negative balance is a credit balance. Click an account code to see its postings.', 'class' => 'Class', 'group' => 'Group', 'notes' => 'Notes',
        'is_group' => 'This is a group (a heading that holds accounts; nothing is posted to it)',
        'locked_note' => 'Once an account has postings its code, class and kind cannot change. An account used for automatic posting or holding a balance cannot be deactivated.',
    ],

    'journals' => [
        'title' => 'Journals', 'search_hint' => 'Number, description or reference', 'kind' => 'Kind', 'number' => 'Journal no.', 'reference' => 'Reference', 'new' => 'New manual journal', 'empty' => 'No journals found.',
        'kinds' => ['manual' => 'Manual', 'system' => 'Automatic', 'reversal' => 'Reversal', 'opening' => 'Opening'],
        'status' => ['posted' => 'Posted', 'reversed' => 'Reversed'],
        'intro' => 'Record an entry by hand: debits must equal credits. Receivables, payables and inventory cannot be posted by hand; they come from invoices, receipts, payments and stock documents.',
        'lines' => 'Lines', 'account' => 'Account', 'debit' => 'Debit', 'credit' => 'Credit', 'add_line' => 'Add line', 'balanced' => 'Balanced', 'difference' => 'Difference', 'post' => 'Post journal',
        'reverses' => 'reverses', 'reversed_by' => 'reversed by', 'posted_by' => 'Posted by', 'automatic' => 'Automatic',
        'reverse' => 'Reverse this journal', 'reverse_confirm' => 'Reverse this journal?', 'reverse_hint' => 'Posts an opposite journal on today\'s date. The original stays, marked reversed.', 'reverse_note' => 'Why',
    ],

    'receivables' => [
        'title' => 'Receivables', 'settlements' => 'Receipts', 'new_settlement' => 'Record a receipt', 'party' => 'Customer', 'party_code' => 'Customer code', 'documents' => 'Invoices owed to you', 'no_settlements' => 'No receipts yet.',
    ],
    'payables' => [
        'title' => 'Payables', 'settlements' => 'Payments', 'new_settlement' => 'Record a payment', 'party' => 'Supplier', 'party_code' => 'Supplier code', 'documents' => 'Bills you owe', 'no_settlements' => 'No payments yet.',
    ],

    'sub' => [
        'open' => 'Open items', 'total_open' => 'Total outstanding', 'on_account' => 'Received/paid, not allocated', 'control' => 'Control account', 'difference' => 'Difference to ledger',
        'ageing' => 'Ageing (by how overdue)', 'bucket_current' => 'Not due', 'bucket_d1_30' => '1–30 days', 'bucket_d31_60' => '31–60 days', 'bucket_d61_90' => '61–90 days', 'bucket_d90_plus' => 'Over 90 days', 'bucket_total' => 'Total',
        'nothing_owed' => 'Nothing is outstanding.', 'document' => 'Document', 'due' => 'Due', 'open_amount' => 'Open', 'overdue' => ':days days overdue', 'method' => 'Method', 'unallocated' => 'Not allocated',
        'find_open' => 'Find open documents', 'bank' => 'Bank / cash account', 'settle' => 'Settle documents', 'settle_hint' => 'Enter how much of each document this settles. Money not allocated stays on account and can be allocated later.',
        'nothing_open_party' => 'This party has nothing outstanding.', 'pick_party' => 'Enter a party code above to see what is outstanding.', 'settle_now' => 'Settle now', 'settled' => 'Documents settled',
        'on_account_only' => 'Nothing allocated: the money is on account.', 'allocate_rest' => 'Allocate the rest', 'allocate' => 'Allocate',
        'reverse' => 'Reverse this document', 'reverse_hint' => 'Reverses the journal and gives the settled amounts back to the documents.', 'balance' => 'Balance owed',
    ],

    'methods' => ['cash' => 'Cash', 'cheque' => 'Cheque', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'other' => 'Other', 'credit_note' => 'Credit note', 'debit_note' => 'Debit note'],

    'reports' => [
        'title' => 'Financial reports',
        'trial_balance' => 'Trial balance', 'trial_balance_hint' => 'Every account with its debit or credit balance; the totals must agree.',
        'general_ledger' => 'General ledger', 'general_ledger_hint' => 'All postings of one account with a running balance.',
        'profit_loss' => 'Profit and loss', 'profit_loss_hint' => 'Income less costs for a period.',
        'balance_sheet' => 'Balance sheet', 'balance_sheet_hint' => 'What the company owns and owes at a date.',
        'opening' => 'Brought forward', 'closing' => 'Closing balance', 'movement' => 'Movement', 'empty' => 'No postings yet.', 'balance_sign' => 'Balance is debit minus credit; a negative balance is a credit.',
        'pick_account' => 'Choose an account to see its postings.', 'total_income' => 'Total income', 'total_expense' => 'Total costs', 'net_profit' => 'Net profit', 'net_loss' => 'Net loss', 'as_at' => 'As at',
        'assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity', 'total_assets' => 'Total assets', 'total_liabilities' => 'Total liabilities', 'total_equity' => 'Total equity',
        'earlier_profit' => 'Profit of earlier years', 'year_profit' => 'Profit this year (from :from)', 'balanced' => 'The balance sheet balances.', 'out_of_balance' => 'The balance sheet does not balance. Please run finance:reconcile.',
    ],

    'periods' => [
        'title' => 'Fiscal periods', 'intro' => 'Postings are only accepted in open periods. Close a month when its books are final; reopen it if you must correct something; lock it to make it permanent.',
        'year' => 'Fiscal year :name', 'period' => 'Period', 'status' => ['open' => 'Open', 'closed' => 'Closed', 'locked' => 'Locked'], 'close' => 'Close', 'reopen' => 'Reopen', 'lock' => 'Lock',
        'lock_confirm' => 'Locking is permanent. Lock this period?', 'new_year' => 'Start year of the new fiscal year (starts 1 April)', 'create_year' => 'Create fiscal year',
        'done_close' => ':period is closed.', 'done_reopen' => ':period is open again.', 'done_lock' => ':period is locked.',
    ],

    'setup' => [
        'title' => 'Finance setup', 'mapping' => 'Account mapping (posting rules)', 'mapping_hint' => 'Which account each automatic posting uses. Stock movements, invoices, receipts and payments follow these.',
        'used_for' => 'Used for', 'banks' => 'Bank and cash accounts', 'account_no' => 'Account number', 'currency' => 'Currency', 'foreign_note' => 'Receipts and payments in foreign currency are not supported yet.',
        'deactivate' => 'Deactivate', 'activate' => 'Activate', 'ledger_account' => 'Ledger account code', 'ledger_hint' => 'An asset account of the chart', 'kind' => 'Kind', 'kind_bank' => 'Bank', 'kind_cash' => 'Cash',
        'bank_name' => 'Bank', 'add_bank' => 'Add bank/cash account',
    ],

    'mapping' => [
        'inventory' => 'Inventory (stock value)', 'wip' => 'Work in progress', 'grni' => 'Goods received, not yet invoiced', 'receivable_control' => 'Receivables control (customers)', 'payable_control' => 'Payables control (suppliers)',
        'sales_revenue' => 'Sales revenue', 'cogs' => 'Cost of sales', 'stock_adjustment' => 'Stock adjustments and write-offs', 'sample_expense' => 'Samples given away', 'purchase_expense' => 'Purchases of services and non-stock items', 'purchase_variance' => 'Purchase price variance',
        'discount_given' => 'Discounts given', 'discount_received' => 'Discounts received', 'vat_output' => 'VAT payable (on sales)', 'sscl_payable' => 'SSCL payable (on sales)', 'nbt_payable' => 'NBT payable (on sales)',
        'vat_input' => 'VAT receivable (on purchases)', 'opening_balance_equity' => 'Opening balance equity', 'retained_earnings' => 'Retained earnings', 'year_result' => 'Year profit / loss', 'fx_gain' => 'Foreign exchange gain', 'fx_loss' => 'Foreign exchange loss',
    ],
];
