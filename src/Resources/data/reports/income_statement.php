<?php

/*
 * Belgian income statement, abbreviated schema of the National Bank. Classes 69
 * and 79 (appropriations) are not part of the result.
 */
return [
    'key' => 'income_statement',
    'label' => 'report.income_statement',
    'lines' => [
        ['key' => '70', 'label' => 'report.be.turnover', 'accounts' => ['70'], 'sign' => -1, 'level' => 1],
        ['key' => '71_72', 'label' => 'report.be.production', 'accounts' => ['71', '72'], 'sign' => -1, 'level' => 1],
        ['key' => '74', 'label' => 'report.be.other_operating_income', 'accounts' => ['74'], 'sign' => -1, 'level' => 1],
        ['key' => '76A', 'label' => 'report.be.non_recurring_operating_income', 'accounts' => ['760', '7620', '7630', '764', '765', '766', '767', '768', '769'], 'sign' => -1, 'level' => 1],
        ['key' => '60', 'label' => 'report.be.supplies_goods', 'accounts' => ['60'], 'level' => 1],
        ['key' => '61', 'label' => 'report.be.services', 'accounts' => ['61'], 'level' => 1],
        ['key' => '62', 'label' => 'report.be.remuneration', 'accounts' => ['62'], 'level' => 1],
        ['key' => '630', 'label' => 'report.be.depreciation', 'accounts' => ['630'], 'level' => 1],
        ['key' => '631_4', 'label' => 'report.be.write_downs', 'accounts' => ['631', '632', '633', '634'], 'level' => 1],
        ['key' => '635_8', 'label' => 'report.be.provisions', 'accounts' => ['635', '636', '637', '638'], 'level' => 1],
        ['key' => '640_8', 'label' => 'report.be.other_operating_charges', 'accounts' => ['64'], 'level' => 1],
        ['key' => '66A', 'label' => 'report.be.non_recurring_operating_charges', 'accounts' => ['660', '661', '662', '663', '664', '665', '666', '667', '6690'], 'level' => 1],
        ['key' => 'operating_result', 'label' => 'report.be.operating_result', 'formula' => '70+71_72+74+76A-60-61-62-630-631_4-635_8-640_8-66A'],
        ['key' => '75_76B', 'label' => 'report.be.financial_income', 'accounts' => ['75', '76'], 'sign' => -1, 'level' => 1],
        ['key' => '65_66B', 'label' => 'report.be.financial_charges', 'accounts' => ['65', '66'], 'level' => 1],
        ['key' => 'result_before_tax', 'label' => 'report.be.result_before_tax', 'formula' => 'operating_result+75_76B-65_66B'],
        ['key' => '780', 'label' => 'report.be.deferred_taxes_withdrawal', 'accounts' => ['78'], 'sign' => -1, 'level' => 1],
        ['key' => '680', 'label' => 'report.be.deferred_taxes_transfer', 'accounts' => ['68'], 'level' => 1],
        ['key' => '67_77', 'label' => 'report.be.income_taxes', 'accounts' => ['67', '77'], 'level' => 1],
        ['key' => 'income', 'label' => 'report.income', 'formula' => '70+71_72+74+76A+75_76B+780'],
        ['key' => 'charges', 'label' => 'report.charges', 'formula' => '60+61+62+630+631_4+635_8+640_8+66A+65_66B+680+67_77'],
        ['key' => 'result', 'label' => 'report.result', 'formula' => 'result_before_tax+780-680-67_77'],
    ],
];
