<?php

namespace Wexample\SymfonyAccountingBe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyAccountingBe\Service\Bank\Parser\CodaBankStatementParser;

class CodaParserTest extends TestCase
{
    /**
     * Writes values at 1-based CODA positions on a 128-character record.
     *
     * @param array<int, string> $fields Start position → value.
     */
    public static function record(array $fields): string
    {
        $line = str_repeat(' ', 128);

        foreach ($fields as $position => $value) {
            $line = substr_replace($line, $value, $position - 1, strlen($value));
        }

        return $line;
    }

    public static function sample(string $communication = '202600001206'): string
    {
        return implode("\n", [
            self::record([1 => '0000003102600005        00000000000000BANQUE TEST                 GKCCBEBB   00123456749 00000                                       2']),
            self::record([1 => '1', 2 => '2', 3 => '001', 6 => 'BE68539007547034', 40 => 'EUR', 43 => '0', 44 => '000000001000000', 59 => '010326', 65 => 'SOCIETE TEST']),
            self::record([1 => '21', 3 => '0001', 7 => '0000', 11 => 'REF0000000000000001AA', 32 => '0', 33 => '000000000121000', 48 => '100326', 54 => '00150000', 62 => '1', 63 => '101'.$communication, 116 => '100326', 122 => '001', 126 => '1', 128 => '0']),
            self::record([1 => '22', 3 => '0001', 7 => '0000', 99 => 'BBRUBEBB', 126 => '1']),
            self::record([1 => '23', 3 => '0001', 7 => '0000', 11 => 'BE71096123456769                  EUR', 48 => 'CLIENT A', 126 => '0']),
            self::record([1 => '21', 3 => '0002', 7 => '0000', 11 => 'REF0000000000000002AA', 32 => '1', 33 => '000000000020500', 48 => '150326', 54 => '00350000', 62 => '0', 63 => 'FRAIS DE GESTION', 116 => '150326', 122 => '001', 126 => '0']),
            self::record([1 => '21', 3 => '0002', 7 => '0001', 11 => 'REF0000000000000002AA', 32 => '1', 33 => '000000000010000', 48 => '150326', 63 => 'DETAIL IGNORED', 116 => '150326']),
            self::record([1 => '8', 2 => '001', 5 => 'BE68539007547034', 42 => '0', 43 => '000000001100500', 58 => '310326']),
            self::record([1 => '9', 17 => '000000000020500', 32 => '000000000121000', 128 => '2']),
        ]);
    }

    public function testParse(): void
    {
        $parser = new CodaBankStatementParser();
        $this->assertTrue($parser->supports(self::sample()));

        $statement = $parser->parse(self::sample());

        $this->assertSame('BE68539007547034', $statement->iban);
        $this->assertCount(2, $statement->transactions);
        [$credit, $debit] = $statement->transactions;

        $this->assertSame(12100, $credit->amount);
        $this->assertSame('2026-03-10', $credit->date->format('Y-m-d'));
        $this->assertSame('+++202/6000/01206+++', $credit->reference);
        $this->assertSame('CLIENT A', $credit->counterpartyName);
        $this->assertSame('BE71096123456769', $credit->counterpartyIban);
        $this->assertSame('REF0000000000000001AA', $credit->externalId);
        $this->assertSame(-2050, $debit->amount);
        $this->assertSame('FRAIS DE GESTION', $debit->label);
        $this->assertSame(110050, $statement->balances[0]->balance);
        $this->assertSame('2026-03-31', $statement->balances[0]->date->format('Y-m-d'));
    }
}
