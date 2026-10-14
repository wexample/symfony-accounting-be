<?php

namespace Wexample\SymfonyAccountingBe\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Wexample\SymfonyAccountingBe\Service\Bank\Parser\IngCsvBankStatementParser;

class IngCsvParserTest extends TestCase
{
    public static function sample(): string
    {
        return "\u{FEFF}".implode("\n", [
            'Numéro de compte;Nom du compte;Compte contrepartie;Numéro de mouvement;Date comptable;Date valeur;Montant;Devise;Libellés;Détails du mouvement;Message',
            'BE68539007547034;Société Test;;1;01/01/2026;31/12/2025;-1,90;EUR;Décompte de frais n° 1 Pièce justificative en annexe;   Décompte de frais au 31/12/2025   ;',
            'BE68539007547034;Société Test;BE71096123456769;2;30/01/2026;30/01/2026;4235,00;EUR;Virement instantané en euros De: CLIENT A SA - BE71096123456769 Instantané le 30/01 - 10:00:00;Virement instantané en euros De: CLIENT A SA IBAN: BE71096123456769 Communication : 20260129-01674 Info personnelle: 1707625730;',
            'BE68539007547034;Société Test;BE41679200364210;3;21/07/2026;21/07/2026;-2205,00;EUR;Virement instantané en euros: Home\'Bank Vers: SPF Finances - BE41679200364210 Instantané le 21/07 - 19:04:55 Communication: ***103/0753/07838*** Info personnelle: 9c72;;',
            'BE68539007547034;Société Test;;4;24/03/2026;24/03/2026;-75,67;EUR;Paiement Bancontact 24/03/26 - 8h20 - FOD Justitie 1000 - Brussel - BEL 670006061481 Numéro de carte 5229 62XX XXXX 6580;;',
        ]);
    }

    public function testParse(): void
    {
        $parser = new IngCsvBankStatementParser();
        $this->assertTrue($parser->supports(self::sample()));

        $statement = $parser->parse(self::sample());

        $this->assertSame('BE68539007547034', $statement->iban);
        $this->assertSame('EUR', $statement->currencyCode);
        $this->assertCount(4, $statement->transactions);
        [$fees, $credit, $tax, $card] = $statement->transactions;

        $this->assertSame(-190, $fees->amount);
        $this->assertSame('2026-1', $fees->externalId);
        $this->assertSame('2025-12-31', $fees->valueDate->format('Y-m-d'));
        $this->assertNull($fees->reference);

        $this->assertSame(423500, $credit->amount);
        $this->assertSame('2026-01-30', $credit->date->format('Y-m-d'));
        $this->assertSame('CLIENT A SA', $credit->counterpartyName);
        $this->assertSame('BE71096123456769', $credit->counterpartyIban);
        $this->assertSame('20260129-01674', $credit->reference);
        $this->assertSame('Virement instantané en euros De: CLIENT A SA - BE71096123456769', $credit->label);

        $this->assertSame(-220500, $tax->amount);
        $this->assertSame('SPF Finances', $tax->counterpartyName);
        $this->assertSame('+++103/0753/07838+++', $tax->reference);

        $this->assertSame('Paiement Bancontact 24/03/26 - 8h20 - FOD Justitie 1000 - Brussel - BEL 670006061481', $card->label);
    }

    public function testRefusesOtherCsv(): void
    {
        $this->assertFalse((new IngCsvBankStatementParser())->supports("Date;Libellé;Montant\n01/01/2026;Test;1,00"));
    }
}
