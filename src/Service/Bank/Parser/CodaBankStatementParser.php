<?php

namespace Wexample\SymfonyAccountingBe\Service\Bank\Parser;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\ParsedBalance;
use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Service\Bank\Parser\AbstractBankStatementParser;
use Wexample\SymfonyAccountingBe\Helper\StructuredCommunicationHelper;

/**
 * CODA (COded statement of Account), the format of the Belgian banks (Febelfin,
 * version 2): records of 128 characters, fixed positions.
 *
 * - 0: header; 1: old balance; 8: new balance; 9: trailer;
 * - 21: a movement (sign, amount with 3 decimals, dates, communication,
 *   structured when its type flag is 1); 22 and 23 continue it with the
 *   communication, the counterparty's BIC, account and name;
 * - 3x: information records, ignored.
 *
 * Only movements of detail number 0000 are kept: other ones break down a
 * globalised amount already counted.
 */
class CodaBankStatementParser extends AbstractBankStatementParser
{
    public function getKey(): string
    {
        return 'coda';
    }

    public function getLabel(): string
    {
        return 'CODA (Belgian banks)';
    }

    public function supports(
        string $content,
        ?string $filename = null
    ): bool {
        $first = strtok($content, "\r\n") ?: '';

        return str_starts_with($first, '0000') && strlen(rtrim($first, "\r\n")) >= 120;
    }

    public function parse(
        string $content,
        array $options = []
    ): ParsedStatement {
        $statement = new ParsedStatement();
        /** @var array<string, array>|null $current */
        $current = null;
        $movements = [];

        foreach (preg_split('/\r\n|\n|\r/', $this->toUtf8($content)) as $line) {
            if (strlen($line) < 2) {
                continue;
            }

            $line = str_pad($line, 128);
            $type = $line[0];

            if ('1' === $type) {
                $structure = $line[1];
                $account = trim(substr($line, 5, 37));
                if (in_array($structure, ['2', '3'], true)) {
                    // IBAN followed by the currency.
                    $statement->iban = trim(substr($account, 0, 34));
                    $statement->currencyCode = trim(substr($line, 39, 3)) ?: null;
                }
            } elseif ('2' === $type && '1' === $line[1]) {
                if ('0000' !== substr($line, 6, 4)) {
                    $current = null;

                    continue;
                }

                $structured = '1' === $line[61];
                $communication = substr($line, 62, 53);
                $reference = null;

                if ($structured && '101' === substr($communication, 0, 3)) {
                    $digits = substr($communication, 3, 12);
                    $reference = StructuredCommunicationHelper::isValid($digits) ? StructuredCommunicationHelper::format($digits) : $digits;
                    $communication = '';
                }

                $movements[] = [
                    'externalId' => trim(substr($line, 10, 21)),
                    'amount' => ('1' === $line[31] ? -1 : 1) * $this->codaAmount(substr($line, 32, 15)),
                    'valueDate' => $this->codaDate(substr($line, 47, 6)),
                    'transactionCode' => substr($line, 53, 8),
                    'communication' => rtrim($communication),
                    'reference' => $reference,
                    'date' => $this->codaDate(substr($line, 115, 6)),
                    'name' => null,
                    'iban' => null,
                ];
                $current = array_key_last($movements);
            } elseif ('2' === $type && '2' === $line[1] && null !== $current) {
                $movements[$current]['communication'] .= rtrim(substr($line, 10, 53));
            } elseif ('2' === $type && '3' === $line[1] && null !== $current) {
                $movements[$current]['iban'] = trim(substr(trim(substr($line, 10, 37)), 0, 34)) ?: null;
                $movements[$current]['name'] = trim(substr($line, 47, 35)) ?: null;
                $movements[$current]['communication'] .= rtrim(substr($line, 82, 43));
            } elseif ('8' === $type) {
                $statement->addBalance(new ParsedBalance(
                    $this->codaDate(substr($line, 57, 6)),
                    ('1' === $line[41] ? -1 : 1) * $this->codaAmount(substr($line, 42, 15))
                ));
            }
        }

        foreach ($movements as $movement) {
            $label = trim(implode(' ', array_filter([
                $movement['name'],
                $this->cleanLabel($movement['communication']),
                $movement['reference'],
            ])));

            $statement->addTransaction(new ParsedTransaction(
                date: $movement['date'],
                amount: $movement['amount'],
                label: '' === $label ? 'Opération bancaire' : $label,
                externalId: '' === $movement['externalId'] ? null : $movement['externalId'],
                valueDate: $movement['valueDate'],
                counterpartyName: $movement['name'],
                counterpartyIban: $movement['iban'],
                reference: $movement['reference'],
                metadata: ['coda_transaction_code' => $movement['transactionCode']],
            ));
        }

        return $statement;
    }

    /**
     * Fifteen digits, the last three being decimals: 000000000012500 → 1250 cents.
     */
    private function codaAmount(string $digits): int
    {
        return intdiv((int) $digits, 10);
    }

    private function codaDate(string $ddmmyy): DateTimeImmutable
    {
        return $this->parseDate($ddmmyy, 'dmy');
    }
}
