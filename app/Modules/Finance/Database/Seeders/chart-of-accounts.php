<?php

/**
 * Starting chart of accounts: Sky Smart Technology (Pvt) Ltd's chart from FrontAccounting (printed 2026-10-11),
 * with the corrections listed in docs/architecture/finance.md §2 — marked here with "changed" / "added".
 *
 * Shape: group code => [name, class, accounts: [code, name, subtype?, note?]]
 */
return [
    // ---------------------------------------------------------------- assets
    '01' => ['CAPITAL ASSET', 'asset', [
        ['1001', 'LAND'], ['1002', 'BUILDING'], ['1003', 'PLANT & MACHINERY'], ['1004', 'TOOLS'], ['1005', 'FURNITURE & FITTINGS'],
        ['1006', 'OFFICE EQUIPMENT'], ['1007', 'IT EQUIPMENT'], ['1008', 'SOLAR SYSTEM'],
        ['1050', 'PROVISION FOR DEPRECIATION - BUILDING'], ['1051', 'PROVISION FOR DEPRECIATION - PLANT & MACHINERY'],
        ['1052', 'PROVISION FOR DEPRECIATION - TOOLS'], ['1053', 'PROVISION FOR DEPRECIATION - FURNITURE & FITTINGS'],
        ['1054', 'PROVISION FOR DEPRECIATION - OFFICE EQUIPMENT'], ['1055', 'PROVISION FOR DEPRECIATION - IT EQUIPMENT'],
        ['1056', 'PROVISION FOR DEPRECIATION - SOLAR SYSTEM'],
    ]],
    '02' => ['CURRENT ASSETS', 'asset', [
        ['1100', 'INVENTORY', 'inventory'], ['1105', 'STOCKS OF WORK IN PROGRESS', 'wip'],
        ['1110', 'LOAN - STAFF RECOVERABLES LOAN', null, 'changed: spelling'], ['1111', 'LOAN - SHORT TERM LOAN RECEIVABLES'],
        ['1125', 'TRADE DEBTORS', 'receivable'],
        ['1126', 'VAT INPUT RECEIVABLE', null, 'added: input VAT on purchases'],
        ['1150', 'BANK OF CEYLON- USD-0090608044', 'bank'], ['1155', 'BANK OF CEYLON- LKR- 0089695095', 'bank'],
        ['1160', 'PETTY CASH - SKY', 'cash'], ['1161', 'PETTY CASH - MNT', 'cash'], ['1162', 'PETTY CASH - IT', 'cash'], ['1165', 'CASH IN HAND', 'cash'],
    ]],
    // ---------------------------------------------------------------- liabilities
    '03' => ['LONG TERM LIABILITIES', 'liability', [
        ['1400', 'LOAN - LONG TERM LOAN PAYABLE'], ['1410', 'LOAN - LONG TERM LOAN PAYABLE DIRECTOR ACC - MRS.NADEESHA'],
        ['1420', 'LOAN - CUSTOMER ( MAS / HID / BDX )'], ['1440', 'DIRECTOR CURRENT ACC - MR.KELUM BANDARA'], ['1450', 'DIRECTOR CURRENT ACC - MRS.NADEESHA THARANGANI'],
    ]],
    '04' => ['CURRENT LIABILITIES', 'liability', [
        ['1500', 'TRADE CREDITORS', 'payable'], ['1505', 'LOAN - SHORT TERM LOAN PAYABLE'],
        ['1510', 'GOODS RECEIVED NOT INVOICED', null, 'added: stock received before the supplier bill'],
        ['1515', 'ETF PAYABLE 3%'], ['1516', 'EPF PAYABLE 20%'], ['1517', 'NET SALARY PAYABLE'], ['1518', 'ACCOUNTING FEE PAYABLE'], ['1519', 'AUDIT FEE PAYABLE'],
        ['1520', 'ACCRUED EXPENSE - TELEPHONE'], ['1521', 'TAX PAYABLE'], ['1522', 'WATER PAYABLE'], ['1523', 'STAFF PAYABLE'],
        ['1524', 'VAT PAYABLE (OUTPUT)', null, 'added: VAT on sales'], ['1525', 'SSCL PAYABLE', null, 'added: SSCL on sales'], ['1526', 'NBT PAYABLE', null, 'added: NBT on sales'],
    ]],
    // ---------------------------------------------------------------- equity (the printed chart filed these under Liabilities; changed)
    '05' => ['SHARE CAPITAL', 'equity', [['1210', 'COMMON SHARES']]],
    '06' => ['RETAINED EARNINGS', 'equity', [
        ['1300', 'INVESTMENT - MR.KELUM BANDARA'], ['1301', 'INVESTMENT - MRS.NADEESHA SENANAYAKA'],
        ['1330', 'RETAINED EARNINGS - PRIOR YEARS', 'retained_earnings'],
        ['1340', 'OPENING BALANCE EQUITY', null, 'added: opening stock and balances'],
        ['2490', 'YEAR PROFIT / LOSS', 'year_result', 'changed: moved here from the expense groups, it is an equity account'],
    ]],
    // ---------------------------------------------------------------- income
    '07' => ['SALES REVENUE', 'income', [['2000', 'SALES']]],
    '08' => ['OTHER REVENUE', 'income', [
        ['2100', 'DISCOUNTS GIVEN'], ['2101', 'LOAN INTEREST RECEIVED'], ['2102', 'FOREIGN EXCHANGE GAIN'], ['2103', 'SHIPPING & HANDLING REVENUE'],
        ['2104', 'PROMPT PAYMENT DISCOUNTS'], ['2105', 'BANK INTEREST RECEIVED'],
        ['2205', 'DISCOUNT RECEIVED', null, 'changed: moved here from cost of goods sold'],
    ]],
    // ---------------------------------------------------------------- costs
    '09' => ['COST OF GOODS SOLD', 'expense', [
        ['2200', 'OPENING STOCK'], ['2201', 'PURCHASES'],
        ['2206', 'COST OF SALES', null, 'added: cost of stock sold, posted when stock is issued'],
        ['2207', 'STOCK ADJUSTMENTS & WRITE-OFFS', null, 'added: stock counts, damage, expiry, scrap'],
        ['2202', 'ELECTRICITY'], ['2204', 'BATA'], ['2213', 'EQUIPMENT MAINTENANCE'],
        ['2214', 'PURCHASE PRICE VARIENCE (DUPLICATE - USE 2217)', null, 'changed: duplicate of 2217, deactivated'],
        ['2215', 'DEPRECIATION- PLANT & MACHINERY'], ['2216', 'DEPRECIATION- TOOLS'], ['2217', 'PURCHASE PRICE VARIANCE'],
        ['2218', 'TEMPORARY WORKERS SALARY'], ['2230', 'GROSS SALARY - PRODUCTION'], ['2231', 'GROSS SALARY - CASUAL'],
        ['2240', 'OVERTIME - PRODUCTION'], ['2241', 'OVERTIME - CASUAL'], ['2250', 'ALLOWANCES - PRODUCTION'], ['2251', 'ALLOWANCES - CASUAL'],
        ['2260', 'EPF- PRODUCTION 12%'], ['2261', 'EPF - CASUAL 12%'], ['2262', 'EPF 12% SURCHARGE FEE'],
        ['2270', 'ETF - PRODUCTION 3%'], ['2271', 'ETF - CASUAL 3%'], ['2272', 'ETF 3% SURCHARGE FEE'],
    ]],
    '10' => ['DISTRIBUTION COST', 'expense', [
        ['2203', 'MARKETING', null, 'changed: moved here from cost of goods sold'],
        ['2310', 'MOTOR VEHICLE MAINTENANCE'], ['2311', 'TRAVELING & TRANSPORT'], ['2312', 'COURIER CHARGES'], ['2313', 'BAD DEBTS'],
        ['2314', 'VEHICLE FUEL'], ['2315', 'SALES COMMISSION'], ['2316', 'BOI PASS'], ['2317', 'SHIPPING CHARGES'],
    ]],
    '11' => ['GENERAL & ADMINISTRATIVE COST', 'expense', [
        ['2400', 'DEPRECIATION- BUILDING'], ['2401', 'DEPRECIATION- OFFICE EQUIPMENT'], ['2402', 'DEPRECIATION- IT EQUIPMENT'],
        ['2403', 'DEPRECIATION- FURNITURE & FITTINGS'], ['2404', 'DEPRECIATION - SOLAR SYSTEM'], ['2405', 'WORKMENS INSURANCE'],
        ['2406', 'WATER'], ['2407', 'PRINTING & STATIONERY'], ['2408', 'OFFICE EQUIPMENT MAINTENANCE', null, 'changed: spelling'], ['2409', 'BUILDING MAINTENANCE'],
        ['2410', 'CHARITY DONATIONS', null, 'changed: spelling'], ['2411', 'TELEPHONE'], ['2412', 'OTHER'], ['2413', 'LOSS ON ASSET DISPOSAL ACCOUNT'],
        ['2414', 'STAFF WELFARE'], ['2415', 'ACCOUNTING CHARGES'], ['2416', 'AUDIT FEE'], ['2417', 'TAX EXPENSE'], ['2418', 'TELECOMMUNICATION EXPENSE'],
        ['2419', 'LOAN RECEIVABLE WRITE-OFF'], ['2420', 'GROSS SALARY- MANAGEMENT'], ['2421', 'GROSS SALARY- DIRECTOR'],
        ['2430', 'OVERTIME - MANAGEMENT'], ['2431', 'OVERTIME - DIRECTOR'], ['2440', 'ALLOWANCES - MANAGEMENT'], ['2441', 'ALLOWANCES - DIRECTOR'],
        ['2450', 'EPF - MANAGEMENT 12%'], ['2460', 'ETF - MANAGEMENT 3%'],
    ]],
    '12' => ['FINANCE COST', 'expense', [
        ['2500', 'LOAN INTEREST CHARGES'], ['2501', 'OVER DRAFT INTEREST'], ['2502', 'BANK CHARGES'],
        ['2503', 'FOREIGN EXCHANGE LOSS', null, 'added: pairs with 2102'],
    ]],
];
