<?php

namespace Wexample\SymfonyAccountingBe\Service\Listing;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Repository\InvoiceRepository;

/**
 * The intra-Community listing (relevé intracommunautaire / intracommunautaire
 * opgave) of a period: per customer VAT number and code (L goods, S services),
 * the amount of supplies to other EU countries, credit notes deducted.
 */
class BeIntraEuListingProvider
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
    ) {
    }

    /**
     * @return list<array{vatNumber: string, code: string, amount: int}>
     */
    public function build(
        Ledger $ledger,
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): array {
        $rows = [];

        foreach ($this->invoiceRepository->findForPeriod($ledger, $from, $to) as $invoice) {
            if (! $invoice->isSale() || ! $invoice->getType()->isAccountable() || ! $invoice->getStatus()->isEmitted()) {
                continue;
            }

            $vatNumber = (string) ($invoice->getPartySnapshot()['vatNumber'] ?? $invoice->getParty()?->getVatNumber());
            $sign = $invoice->isCreditNote() ? -1 : 1;

            foreach ($invoice->getItems() as $item) {
                $code = match ($item->getVatKind()) {
                    VatKind::IntraEuGoods => 'L',
                    VatKind::IntraEuServices => 'S',
                    default => null,
                };

                if (! $code) {
                    continue;
                }

                $key = $vatNumber.'|'.$code;
                $rows[$key] ??= ['vatNumber' => $vatNumber, 'code' => $code, 'amount' => 0];
                $rows[$key]['amount'] += $sign * $item->calcPriceNet();
            }
        }

        ksort($rows);

        return array_values(array_filter($rows, fn (array $row) => 0 !== $row['amount']));
    }
}
