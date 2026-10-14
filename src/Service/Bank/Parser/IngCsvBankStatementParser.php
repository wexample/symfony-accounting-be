<?php

namespace Wexample\SymfonyAccountingBe\Service\Bank\Parser;

use Wexample\SymfonyAccounting\Class\ParsedStatement;
use Wexample\SymfonyAccounting\Class\ParsedTransaction;
use Wexample\SymfonyAccounting\Service\Bank\Parser\AbstractBankStatementParser;
use Wexample\SymfonyAccountingBe\Helper\StructuredCommunicationHelper;

/**
 * ING Belgium, the CSV of Home'Bank: "Numéro de compte;Nom du compte;Compte
 * contrepartie;Numéro de mouvement;Date comptable;Date valeur;Montant;Devise;
 * Libellés;Détails du mouvement;Message", signed amounts like "-1,90".
 *
 * The movement number starts again each year: with the year of the booking
 * date, it identifies the line. The communication sits in the label (debits,
 * "Communication: ***103/0753/07838***") or in the details (credits,
 * "Communication : 20260429-01687"), before "Info personnelle"; a structured
 * one is normalized to "+++…+++". The label drops what only repeats the
 * moment of the transfer or the card number.
 */
class IngCsvBankStatementParser extends AbstractBankStatementParser
{
    private const string HEADER = 'Numéro de compte;Nom du compte;Compte contrepartie;Numéro de mouvement';

    public function getKey(): string
    {
        return 'be_ing_csv';
    }

    public function getLabel(): string
    {
        return 'ING Belgique (CSV)';
    }

    public function supports(
        string $content,
        ?string $filename = null
    ): bool {
        return str_starts_with($this->toUtf8($this->removeBom($content)), self::HEADER);
    }

    public function parse(
        string $content,
        array $options = []
    ): ParsedStatement {
        $lines = preg_split('/\R/', $this->toUtf8($this->removeBom($content)));
        $header = str_getcsv((string) array_shift($lines), ';', '"', '\\');
        $statement = new ParsedStatement();

        foreach ($lines as $line) {
            if ('' === trim($line)) {
                continue;
            }

            $row = array_combine($header, array_pad(str_getcsv($line, ';', '"', '\\'), count($header), ''));
            $statement->iban ??= preg_replace('/\s+/', '', $row['Numéro de compte']) ?: null;
            $statement->currencyCode ??= $row['Devise'] ?: null;

            $date = $this->parseDate($row['Date comptable'], 'd/m/Y');
            $label = $this->cleanLabel($row['Libellés']);
            $details = $this->cleanLabel($row['Détails du mouvement']);

            $statement->addTransaction(new ParsedTransaction(
                date: $date,
                amount: $this->parseAmount($row['Montant'], $row['Devise'] ?: 'EUR'),
                label: $this->shortenLabel($label) ?: 'Opération bancaire',
                externalId: '' === $row['Numéro de mouvement'] ? null : $date->format('Y').'-'.$row['Numéro de mouvement'],
                valueDate: '' === $row['Date valeur'] ? null : $this->parseDate($row['Date valeur'], 'd/m/Y'),
                counterpartyName: preg_match('/\b(?:De|Vers): (.+?) - [A-Z]{2}\d{2}/u', $label, $matches) ? trim($matches[1]) : null,
                counterpartyIban: preg_replace('/\s+/', '', $row['Compte contrepartie']) ?: null,
                reference: $this->communication($label.' '.$details.' '.$row['Message']),
            ));
        }

        return $statement;
    }

    private function communication(string $text): ?string
    {
        if (! preg_match('/Communication\s*:\s*(.+?)(?:\s+Info personnelle|$)/u', $text, $matches)) {
            return null;
        }

        return StructuredCommunicationHelper::extract($matches[1])[0] ?? trim($matches[1]);
    }

    private function shortenLabel(string $label): string
    {
        return $this->cleanLabel(preg_replace([
            '/\s*Info personnelle:.*$/u',
            '/\s*Instantané le \d\d\/\d\d - [\d:]+/u',
            '/\s*Numéro de carte [\dX ]+$/u',
        ], '', $label));
    }
}
