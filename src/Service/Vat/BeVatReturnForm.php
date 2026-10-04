<?php

namespace Wexample\SymfonyAccountingBe\Service\Vat;

use Wexample\SymfonyAccounting\Class\VatReturn;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Interface\VatReturnFormInterface;

/**
 * The Belgian periodic VAT return (déclaration périodique / periodieke aangifte),
 * monthly or quarterly, by grid. Credit notes go to their own grids (48, 49, 84,
 * 85, 63, 64) rather than being deducted, as the form wants.
 *
 * Purchases are split by account: 60 → grid 81 (goods), 2 → 83 (investments),
 * any other charge → 82 (services and miscellaneous goods). Reverse-charged
 * services all go to grid 88 (from the EU): those bought outside the EU belong
 * in 87 and must be moved by hand until the party's country is tracked per line.
 */
class BeVatReturnForm implements VatReturnFormInterface
{
    public const array RATE_GRIDS = [600 => '01', 1200 => '02', 2100 => '03'];

    public function getKey(): string
    {
        return 'be_periodic';
    }

    public function supports(Ledger $ledger): bool
    {
        return 'BE' === strtoupper((string) $ledger->getCountryCode()) && $ledger->isVatSubject();
    }

    public function fill(VatReturn $vatReturn): array
    {
        $sale = InvoiceDirection::Sale;
        $purchase = InvoiceDirection::Purchase;
        $grids = [];

        foreach (self::RATE_GRIDS as $rate => $grid) {
            $line = $vatReturn->sum($sale, VatKind::Domestic, $rate);
            $grids[$grid] = $line['base'] + $line['baseReversed'];
        }

        $intraServices = $vatReturn->sum($sale, VatKind::IntraEuServices);
        $intraGoods = $vatReturn->sum($sale, VatKind::IntraEuGoods);
        $exempt = $vatReturn->sum($sale, [VatKind::Export, VatKind::Exempt, VatKind::OutOfScope]);
        $domesticSales = $vatReturn->sum($sale, VatKind::Domestic);

        $grids['44'] = $intraServices['base'] + $intraServices['baseReversed'];
        $grids['46'] = $intraGoods['base'] + $intraGoods['baseReversed'];
        $grids['47'] = $exempt['base'] + $exempt['baseReversed'];
        $grids['48'] = $intraServices['baseReversed'] + $intraGoods['baseReversed'];
        $grids['49'] = $domesticSales['baseReversed'] + $exempt['baseReversed'];

        $purchases = $vatReturn->sum($purchase, VatKind::Domestic);
        $selfAssessedGoods = $vatReturn->sum($purchase, VatKind::IntraEuAcquisition);
        $selfAssessedServices = $vatReturn->sum($purchase, VatKind::ReverseCharge);

        $grids['81'] = $vatReturn->sum($purchase, null, null, ['60'])['base'];
        $grids['83'] = $vatReturn->sum($purchase, null, null, ['2'])['base'];
        $grids['82'] = $vatReturn->sum($purchase)['base'] - $grids['81'] - $grids['83'];
        $grids['84'] = $selfAssessedGoods['baseReversed'] + $selfAssessedServices['baseReversed'];
        $grids['85'] = $purchases['baseReversed'];
        $grids['86'] = $selfAssessedGoods['base'] + $selfAssessedGoods['baseReversed'];
        $grids['87'] = 0;
        $grids['88'] = $selfAssessedServices['base'] + $selfAssessedServices['baseReversed'];

        $grids['54'] = $domesticSales['due'] + $domesticSales['dueReversed'];
        $grids['55'] = $selfAssessedGoods['due'] + $selfAssessedServices['due'];
        $grids['56'] = 0;
        $grids['57'] = 0;
        $grids['61'] = 0;
        $grids['63'] = $vatReturn->sum($purchase)['deductibleReversed'];
        $grids['59'] = $vatReturn->sum($purchase)['deductible'] + $grids['63'];
        $grids['62'] = 0;
        $grids['64'] = $domesticSales['dueReversed'];

        $due = $grids['54'] + $grids['55'] + $grids['56'] + $grids['57'] + $grids['61'] + $grids['63'];
        $deductible = $grids['59'] + $grids['62'] + $grids['64'] + $vatReturn->previousCredit;
        $grids['71'] = max(0, $due - $deductible);
        $grids['72'] = max(0, $deductible - $due);
        $grids['91'] = $vatReturn->deposits;

        return $grids;
    }
}
