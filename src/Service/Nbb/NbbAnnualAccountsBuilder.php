<?php

namespace Wexample\SymfonyAccountingBe\Service\Nbb;

use Wexample\SymfonyAccounting\Class\TrialBalance;
use Wexample\SymfonyAccounting\Class\TrialBalanceRow;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Repository\EntryLineRepository;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyAccounting\Service\Report\FinancialStatementService;

/**
 * The rubrics of a Belgian company's annual accounts, as the National Bank's
 * Central Balance Sheet Office wants them (micro and abridged models), in cents:
 *
 * - balance sheet and income statement, by the report definition
 *   `nbb_annual_accounts`;
 * - the fixed asset notes (8029 to 8555): what was there at the start, acquired,
 *   disposed of, depreciated, from the opening entries and the year's movements on
 *   class 2 — a fourth digit 9 is depreciation or write-down, 8 a revaluation
 *   surplus, and on financial assets 1 an amount not called up;
 * - the previous year: computed the same way when its books are here, else its
 *   balance sheet from this year's opening entries.
 */
class NbbAnnualAccountsBuilder
{
    /** Notes groups: balance sheet rubric, its account prefixes, and the codes of each line. */
    private const array GROUPS = [
        '21' => [
            'prefixes' => ['21'],
            'value' => ['8059P', '8029', '8039', '8059'],
            'depreciation' => ['8129P', '8079', '8109', '8129'],
        ],
        '22/27' => [
            'prefixes' => ['22', '23', '24', '25', '26', '27'],
            'value' => ['8199P', '8169', '8179', '8199'],
            'surplus' => ['8259P', '8219', '8239', '8259'],
            'depreciation' => ['8329P', '8279', '8309', '8329'],
        ],
        '28' => [
            'prefixes' => ['28'],
            'value' => ['8395P', '8365', '8375', '8395'],
            'surplus' => ['8455P', '8415', '8435', '8455'],
            'depreciation' => ['8525P', '8475', '8505', '8525'],
            'uncalled' => ['8555P', '8545', null, '8555'],
        ],
    ];

    public function __construct(
        private readonly FinancialStatementService $statementService,
        private readonly EntryLineRepository $lineRepository,
        private readonly FiscalYearRepository $fiscalYearRepository,
    ) {
    }

    /**
     * @return array{current: array<string, int>, previous: array<string, int>}
     */
    public function build(FiscalYear $fiscalYear): array
    {
        $ledger = $fiscalYear->getLedger();
        $current = $this->rubrics($fiscalYear, $this->lineRepository->sumByAccount($ledger, fiscalYear: $fiscalYear));
        $previousYear = $this->fiscalYearRepository->findPrevious($fiscalYear);
        $opening = $this->lineRepository->sumByAccount($ledger, fiscalYear: $fiscalYear, opening: true);

        // Without last year's books, its balance sheet is what this year opened with.
        $previous = $previousYear
            ? $this->rubrics($previousYear, $this->lineRepository->sumByAccount($ledger, fiscalYear: $previousYear))
            : $this->rubrics($fiscalYear, $opening);

        [$notes, $openingNotes] = $this->notes($opening, $this->lineRepository->sumByAccount($ledger, fiscalYear: $fiscalYear, opening: false));

        return ['current' => $current + $notes, 'previous' => $previous + $openingNotes];
    }

    /**
     * @param array<string, array{debit: int, credit: int}> $sums
     * @return array<string, int>
     */
    private function rubrics(
        FiscalYear $fiscalYear,
        array $sums
    ): array {
        $rows = [];
        foreach ($sums as $number => $sum) {
            $rows[(string) $number] = new TrialBalanceRow((string) $number, '', $sum['debit'], $sum['credit']);
        }

        $result = $this->statementService->compute(
            $this->statementService->getDefinition($fiscalYear, 'nbb_annual_accounts'),
            new TrialBalance($rows),
        );

        return array_map(fn (array $line) => $line['amount'], $result->lines);
    }

    /**
     * The fixed asset notes of the year, and their opening values (the "P" codes,
     * reported in the previous year's column).
     *
     * @param array<string, array{debit: int, credit: int}> $opening
     * @param array<string, array{debit: int, credit: int}> $movements
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function notes(
        array $opening,
        array $movements
    ): array {
        $current = [];
        $previous = [];

        foreach (self::GROUPS as $group) {
            $totals = [];

            foreach (array_unique([...array_keys($opening), ...array_keys($movements)]) as $number) {
                $number = (string) $number;
                if (! $this->inGroup($number, $group['prefixes'])) {
                    continue;
                }

                $kind = $this->kind($number);
                $totals[$kind]['opening'] = ($totals[$kind]['opening'] ?? 0) + ($opening[$number]['debit'] ?? 0) - ($opening[$number]['credit'] ?? 0);
                $totals[$kind]['debit'] = ($totals[$kind]['debit'] ?? 0) + ($movements[$number]['debit'] ?? 0);
                $totals[$kind]['credit'] = ($totals[$kind]['credit'] ?? 0) + ($movements[$number]['credit'] ?? 0);
            }

            if ([] === array_filter($totals, fn (array $total) => 0 !== $total['opening'] || 0 !== $total['debit'] || 0 !== $total['credit'])) {
                continue;
            }

            foreach (['value', 'surplus', 'depreciation', 'uncalled'] as $kind) {
                if (! isset($group[$kind])) {
                    continue;
                }

                [$startCode, $increaseCode, $decreaseCode, $endCode] = $group[$kind];
                $total = $totals[$kind] ?? ['opening' => 0, 'debit' => 0, 'credit' => 0];
                // Acquisition values sit on the debit side, the others on the credit side.
                $side = 'value' === $kind ? 1 : -1;
                $start = $side * $total['opening'];
                $increase = 'value' === $kind ? $total['debit'] : $total['credit'];
                $decrease = 'value' === $kind ? $total['credit'] : $total['debit'];

                $previous[$startCode] = $start;

                if (null === $decreaseCode) {
                    // Amounts not called up move both ways: one signed mutation.
                    $current[$increaseCode] = $increase - $decrease;
                } else {
                    $current[$increaseCode] = $increase;
                    $current[$decreaseCode] = $decrease;
                }

                $current[$endCode] = $start + $increase - $decrease;
            }
        }

        return [$current, $previous];
    }

    /**
     * @param list<string> $prefixes
     */
    private function inGroup(
        string $number,
        array $prefixes
    ): bool {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($number, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function kind(string $number): string
    {
        return match (true) {
            '9' === ($number[3] ?? null) => 'depreciation',
            '8' === ($number[3] ?? null) => 'surplus',
            str_starts_with($number, '28') && '1' === ($number[3] ?? null) => 'uncalled',
            default => 'value',
        };
    }
}
