<?php

namespace Wexample\SymfonyAccountingBe\Service\Listing;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;

/**
 * The annual listing of Belgian VAT-registered customers (listing clients /
 * klantenlisting): per customer, the year's taxable base and VAT, credit notes
 * deducted, when the base reaches 250 €.
 */
class BeClientListingProvider
{
    public const int THRESHOLD = 25000;

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    /**
     * @return list<array{vatNumber: string, name: string, base: int, vat: int}>
     */
    public function build(
        Ledger $ledger,
        int $year
    ): array {
        $rows = [];

        foreach ($this->invoiceRepository->findForPeriod($ledger, new DateTimeImmutable($year.'-01-01'), new DateTimeImmutable($year.'-12-31')) as $invoice) {
            $party = $invoice->getParty();
            $vatNumber = (string) ($invoice->getPartySnapshot()['vatNumber'] ?? $party?->getVatNumber());

            if (! $invoice->isSale() || ! $invoice->getType()->isAccountable() || ! $invoice->getStatus()->isEmitted() || ! str_starts_with($vatNumber, 'BE')) {
                continue;
            }

            $sign = $invoice->isCreditNote() ? -1 : 1;
            $breakdown = $invoice->calcPriceBreakdown();
            $rows[$vatNumber] ??= ['vatNumber' => $vatNumber, 'name' => $party?->getName() ?? '', 'base' => 0, 'vat' => 0];
            $rows[$vatNumber]['base'] += $sign * $breakdown->getNet();
            $rows[$vatNumber]['vat'] += $sign * $breakdown->getVat();
        }

        ksort($rows);

        return array_values(array_filter($rows, fn (array $row) => $row['base'] >= self::THRESHOLD));
    }
}
