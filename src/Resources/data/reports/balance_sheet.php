<?php

/*
 * Belgian balance sheet, abbreviated schema of the National Bank (schéma abrégé),
 * with its section codes as keys where they exist. Accounts go to the first line
 * whose rules take them; "D"/"C" restrict to debit/credit balances.
 */
return [
    'key' => 'balance_sheet',
    'label' => 'report.balance_sheet',
    'lines' => [
        ['key' => '20', 'label' => 'report.be.formation_expenses', 'accounts' => ['20'], 'level' => 1],
        ['key' => '21', 'label' => 'report.be.intangible_assets', 'accounts' => ['21'], 'level' => 1],
        ['key' => '22_27', 'label' => 'report.be.tangible_assets', 'accounts' => ['22', '23', '24', '25', '26', '27'], 'level' => 1],
        ['key' => '28', 'label' => 'report.be.financial_assets', 'accounts' => ['28'], 'level' => 1],
        ['key' => 'fixed_assets', 'label' => 'report.fixed_assets', 'formula' => '21+22_27+28'],
        ['key' => '29', 'label' => 'report.be.receivables_over_one_year', 'accounts' => ['29'], 'level' => 1],
        ['key' => '3', 'label' => 'report.stocks', 'accounts' => ['3'], 'level' => 1],
        ['key' => '40_41', 'label' => 'report.be.receivables_within_one_year', 'accounts' => ['40', '41D', '42D', '43D', '44D', '45D', '46D', '47D', '48D'], 'level' => 1],
        ['key' => '50_53', 'label' => 'report.be.investments', 'accounts' => ['50', '51', '52', '53'], 'level' => 1],
        ['key' => '54_58', 'label' => 'report.cash', 'accounts' => ['54', '55D', '56D', '57', '58D'], 'level' => 1],
        ['key' => '490_1', 'label' => 'report.be.deferred_charges', 'accounts' => ['490', '491', '499D'], 'level' => 1],
        ['key' => 'current_assets', 'label' => 'report.be.current_assets', 'formula' => '29+3+40_41+50_53+54_58+490_1'],
        ['key' => 'assets', 'label' => 'report.assets', 'formula' => '20+fixed_assets+current_assets'],

        ['key' => '10', 'label' => 'report.be.capital', 'accounts' => ['10'], 'sign' => -1, 'level' => 1],
        ['key' => '11', 'label' => 'report.be.share_premium', 'accounts' => ['11'], 'sign' => -1, 'level' => 1],
        ['key' => '12', 'label' => 'report.be.revaluation', 'accounts' => ['12'], 'sign' => -1, 'level' => 1],
        ['key' => '13', 'label' => 'report.be.reserves', 'accounts' => ['13'], 'sign' => -1, 'level' => 1],
        ['key' => '14', 'label' => 'report.be.retained_result', 'accounts' => ['14'], 'sign' => -1, 'level' => 1],
        ['key' => 'year_result', 'label' => 'report.year_result', 'accounts' => ['6', '7'], 'sign' => -1, 'level' => 1],
        ['key' => '15', 'label' => 'report.be.capital_grants', 'accounts' => ['15'], 'sign' => -1, 'level' => 1],
        ['key' => 'equity', 'label' => 'report.equity', 'formula' => '10+11+12+13+14+year_result+15'],
        ['key' => '16', 'label' => 'report.provisions', 'accounts' => ['16'], 'sign' => -1, 'level' => 1],
        ['key' => '17', 'label' => 'report.be.debts_over_one_year', 'accounts' => ['17', '19'], 'sign' => -1, 'level' => 1],
        ['key' => '42_48', 'label' => 'report.be.debts_within_one_year', 'accounts' => ['4C', '55C', '56C', '58C', '1'], 'sign' => -1, 'level' => 1],
        ['key' => '492_3', 'label' => 'report.be.accrued_charges', 'accounts' => ['492', '493', '499C'], 'sign' => -1, 'level' => 1],
        ['key' => 'debts', 'label' => 'report.debts', 'formula' => '17+42_48+492_3'],
        ['key' => 'liabilities', 'label' => 'report.liabilities', 'formula' => 'equity+16+debts'],
    ],
];
