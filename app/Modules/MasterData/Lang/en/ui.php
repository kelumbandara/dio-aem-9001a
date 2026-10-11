<?php

return [
    'menu' => ['items' => 'Items', 'customers' => 'Customers', 'suppliers' => 'Suppliers', 'price_lists' => 'Price lists', 'reference' => 'Reference data', 'import' => 'Import'],

    'flash' => [
        'saved' => 'Saved.',
        'awaiting_approval' => 'This change needs approval first. Request :document has been sent; nothing has changed yet.',
    ],

    'common' => [
        'search' => 'Search', 'show' => 'Show', 'filter' => 'Filter', 'status' => 'Status',
        'status_active' => 'Active', 'status_inactive' => 'Inactive', 'status_all' => 'All',
        'active' => 'Active', 'inactive' => 'Inactive', 'yes' => 'Yes', 'no' => 'No',
        'code' => 'Code', 'name' => 'Name', 'auto_code' => 'Leave blank to number it automatically.',
        'save' => 'Save', 'cancel' => 'Cancel', 'edit' => 'Edit', 'back' => 'Back', 'remove' => 'Remove',
    ],

    'items' => [
        'title' => 'Items', 'new' => 'New item', 'edit_title' => 'Edit :code', 'empty' => 'No items found.',
        'search_hint' => 'Code, name or barcode',
        'category' => 'Category', 'type' => 'Type', 'base_uom' => 'Base unit', 'description' => 'Description',
        'types' => ['stock' => 'Stock item', 'service' => 'Service', 'non_stock' => 'Non-stock'],
        'tracking' => 'Tracking', 'tracking_none' => 'None', 'tracking_lot' => 'By lot / batch',
        'shelf_life' => 'Shelf life (days)', 'barcode' => 'Barcode', 'tax_code' => 'Default tax',
        'is_sellable' => 'Can be sold', 'is_purchasable' => 'Can be purchased', 'is_active' => 'Active',
        'sale_purchase' => 'Used for', 'days' => ':count days',
        'locked_note' => 'The code, type, tracking and base unit cannot be changed after creation. To correct one, deactivate this item and create a new one.',
        'alt_units' => 'Other units', 'alt_units_hint' => 'Units this item is also bought or sold in, and how many base units each contains (for example 1 BOX = 12 PCS).',
        'add_unit' => 'Add unit', 'factor' => 'Base units per unit', 'factor_in' => 'Contains (:uom)', 'unit' => 'Unit',
        'for_purchase' => 'Purchase', 'for_sale' => 'Sale', 'used_for' => 'Used for', 'no_alt_units' => 'Only the base unit is used.',
    ],

    'parties' => [
        'customers' => ['title' => 'Customers', 'new' => 'New customer', 'empty' => 'No customers found.'],
        'suppliers' => ['title' => 'Suppliers', 'new' => 'New supplier', 'empty' => 'No suppliers found.'],
        'edit_title' => 'Edit :code', 'search_hint' => 'Code, name, tax ID or email',
        'legal_name' => 'Legal name', 'kind' => 'Type', 'kinds' => ['company' => 'Company', 'individual' => 'Individual'],
        'tax_id' => 'Tax ID (VAT/TIN)', 'business_reg_no' => 'Business registration no.', 'nic' => 'NIC', 'phone' => 'Phone', 'email' => 'Email', 'website' => 'Website',
        'trading' => 'Trading terms', 'currency' => 'Currency', 'payment_terms' => 'Payment terms', 'price_list' => 'Price list',
        'credit_limit' => 'Credit limit (LKR)', 'credit_limit_hint' => 'Leave blank for no limit.', 'lead_time' => 'Lead time (days)', 'notes' => 'Notes',
        'contacts' => 'Contacts', 'add_contact' => 'Add contact', 'job_title' => 'Job title', 'primary' => 'Primary', 'no_contacts' => 'No contacts.',
        'addresses' => 'Addresses', 'add_address' => 'Add address', 'no_addresses' => 'No addresses.', 'default' => 'Default',
        'address_types' => ['billing' => 'Billing', 'shipping' => 'Shipping', 'other' => 'Other'],
        'line1' => 'Address line 1', 'line2' => 'Address line 2', 'city' => 'City', 'district' => 'District', 'postal_code' => 'Postal code',
    ],

    'bank' => [
        'title' => 'Bank accounts', 'intro' => 'Account numbers are shown masked. Adding or changing an account may need approval before it takes effect.',
        'bank' => 'Bank', 'branch' => 'Branch', 'account_name' => 'Account holder', 'account_no' => 'Account number',
        'add' => 'Add account', 'deactivate' => 'Deactivate', 'empty' => 'No bank accounts.',
    ],

    'price_lists' => [
        'title' => 'Price lists', 'new' => 'New price list', 'edit_title' => 'Edit :code', 'empty' => 'No price lists yet.',
        'type' => 'Type', 'types' => ['all' => 'All', 'sales' => 'Sales', 'purchase' => 'Purchase'],
        'valid' => 'Valid', 'valid_from' => 'Valid from', 'valid_to' => 'Valid to',
        'tax_inclusive' => 'Prices include tax', 'is_default' => 'Default list for its type', 'is_active' => 'Active',
        'incl_tax' => 'prices include tax', 'excl_tax' => 'prices exclude tax',
        'item' => 'Item', 'min_qty' => 'From quantity', 'unit_price' => 'Unit price', 'no_prices' => 'No prices in this list yet.',
    ],

    'prices' => [
        'change' => 'Set prices', 'apply' => 'Apply prices',
        'format' => 'One price per line: item code, price, then optionally the unit and the minimum quantity. A line with only an item code removes its price.',
        'nothing' => 'Enter at least one price.', 'too_many' => 'At most 1000 lines at a time.', 'applied' => ':count price line(s) applied.',
    ],

    'reference' => [
        'title' => 'Reference data',
        'kinds' => ['units-of-measure' => 'Units', 'item-categories' => 'Categories', 'currencies' => 'Currencies', 'tax-codes' => 'Tax codes', 'payment-terms' => 'Payment terms'],
        'cols' => ['code' => 'Code', 'name' => 'Name', 'parent' => 'Parent', 'is_active' => 'Status', 'symbol' => 'Symbol', 'decimals' => 'Decimals', 'is_base' => 'Base currency', 'days' => 'Days', 'kind' => 'Kind', 'current_rate' => 'Current rate %'],
        'fields' => ['code' => 'Code', 'name' => 'Name', 'parent' => 'Parent category code', 'is_active' => 'Active', 'symbol' => 'Symbol', 'decimals' => 'Decimals', 'days' => 'Days', 'kind' => 'Kind', 'notes' => 'Notes'],
        'empty' => 'Nothing here yet.', 'add' => 'Add', 'edit' => 'Edit :code', 'rates' => 'Rates',
        'rates_of' => 'Rates of :code', 'base_note' => 'The base currency is always 1; it has no exchange rates.',
        'effective_on' => 'Effective on', 'rate_per' => '1 :code in LKR', 'source' => 'Source', 'add_rate' => 'Add rate', 'no_rates' => 'No rates yet.',
        'from' => 'From', 'to' => 'To', 'percent' => 'Rate %',
        'tax_hint' => 'A new open-ended rate ends the previous one the day before it starts. Rates that already started cannot be removed.',
    ],

    'import' => [
        'title' => 'Import', 'intro' => 'Load items, customers or suppliers from a CSV file. Rows are matched by code: a known code is updated, a blank or new code creates a record. Check the file first; nothing is saved until you choose to import, and if any row has a problem nothing is saved at all.',
        'kind' => 'What to import', 'kinds' => ['items' => 'Items', 'customers' => 'Customers', 'suppliers' => 'Suppliers'],
        'file' => 'CSV file', 'file_hint' => 'First row = column names. UTF-8, up to 2 MB and 5000 rows.',
        'mode_check' => 'Check only (nothing is saved)', 'mode_import' => 'Import', 'run' => 'Run', 'templates' => 'Download a template',
        'done' => 'Import complete', 'problems' => 'The file has problems', 'check_ok' => 'The file is ready to import',
        'summary' => ':rows row(s): :created to create, :updated to update.', 'nothing_saved' => 'Nothing was saved. Fix the rows below and try again.',
        'check_hint' => 'This was a check only. Choose Import to save it.',
        'line' => 'Line', 'column' => 'Column', 'problem' => 'Problem', 'first_200' => 'Showing the first 200 of :total problems.',
        'columns' => 'Columns', 'rules' => 'Blank cells keep the current value when updating. Use yes/no for the is_* columns. Related records are given by code (for example category GENERAL, unit PCS, tax code VAT).',
    ],
];
