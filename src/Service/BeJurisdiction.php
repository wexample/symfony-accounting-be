<?php

namespace Wexample\SymfonyAccountingBe\Service;

use Wexample\SymfonyAccounting\Class\ChartAccountDefinition;
use Wexample\SymfonyAccounting\Class\LatePenaltyPolicy;
use Wexample\SymfonyAccounting\Class\LegalMention;
use Wexample\SymfonyAccounting\Class\ReportDefinition;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Enum\AccountNature;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Enum\InvoiceType;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Enum\VatKind;
use Wexample\SymfonyAccounting\Service\Jurisdiction\AbstractJurisdiction;
use Wexample\SymfonyAccountingBe\Helper\BeIdentityHelper;
use Wexample\SymfonyAccountingBe\Helper\StructuredCommunicationHelper;

/**
 * Belgian law and practice: the PCMN, VAT rates, the mentions of the VAT code
 * (CTVA) and of the law of 2 August 2002 on late payments, the structured
 * communication banks carry back.
 *
 * Legal texts here must be confirmed by the owner's accountant before use.
 *
 * Ledger settings read here: `late_penalty_rate` (basis points per year: the
 * legal rate for commercial transactions, ECB rate + 8 points, set each
 * semester), `late_penalty_flat_fee` (cents), `be_structured_communication`
 * (bool, true by default).
 */
class BeJurisdiction extends AbstractJurisdiction
{
    public const string DATASET_PCMN = 'be_pcmn';

    /** @var list<ChartAccountDefinition>|null */
    private ?array $chart = null;

    public function getCountryCode(): ?string
    {
        return 'BE';
    }

    public function getLegalIdentifierLabel(): string
    {
        return 'identity.be.enterprise_number';
    }

    public function getVatNumberLabel(): string
    {
        return 'identity.be.vat_number';
    }

    public function isLegalIdentifierIncludedInVatNumber(): bool
    {
        return true;
    }

    public function isLegalIdentifierValid(string $identifier): bool
    {
        return BeIdentityHelper::isValidEnterpriseNumber($identifier);
    }

    public function isVatNumberValid(string $vatNumber): bool
    {
        return str_starts_with(strtoupper(trim($vatNumber)), 'BE')
            ? BeIdentityHelper::isValidVatNumber($vatNumber)
            : parent::isVatNumberValid($vatNumber);
    }

    public function getVatRates(): array
    {
        return [2100, 1200, 600, 0];
    }

    public function getChartDatasets(): array
    {
        return [self::DATASET_PCMN];
    }

    public function getChartAccounts(string $dataset): iterable
    {
        if (self::DATASET_PCMN !== $dataset) {
            return [];
        }

        return $this->chart ??= $this->readChart(__DIR__.'/../Resources/data/charts/be_pcmn.csv');
    }

    protected function getAccountNumbers(): array
    {
        return [
            'customers' => '400',
            'customer_advances' => '46',
            'suppliers' => '440',
            'sales_goods' => '700',
            'sales_services' => '700',
            'purchases' => '604',
            'purchases_services' => '61',
            'bank' => '550',
            'cash' => '570',
            'internal_transfer' => '580',
            'payment_provider' => '5501',
            'bank_fees' => '657',
            'vat_collected' => '451',
            'vat_collected_pending' => '4519',
            'vat_deductible' => '411',
            'vat_deductible_pending' => '4119',
            'vat_deductible_assets' => '411',
            'vat_self_assessed' => '4510',
            'vat_payable' => '4512',
            'vat_credit' => '4112',
            'vat_deposits' => '4113',
            'doubtful_customers' => '407',
            'bad_debts' => '642',
            'late_penalties_income' => '751',
            'depreciation_expense' => '630',
            'asset_disposal_value' => '663',
            'prepaid_expenses' => '490',
            'deferred_income' => '493',
            'rounding_gain' => '743',
            'rounding_loss' => '643',
            'result_profit' => '140',
            'result_loss' => '141',
            'retained_earnings' => '133',
            'retained_losses' => '141',
            'owner_account' => '100',
            'suspense' => '499',
        ];
    }

    /**
     * PCMN: depreciation booked on the asset's own group, sub-account 9
     * ("amortissements actés"): 2400 → 2409, 22 → 229.
     */
    public function getDepreciationAccountNumber(string $assetAccountNumber): string
    {
        return substr($assetAccountNumber, 0, 3).'9';
    }

    /**
     * Classes 1 to 5 are carried forward; 69 and 79 (appropriations) are neither
     * balance sheet nor result.
     */
    public function isIncomeAccount(string $accountNumber): ?bool
    {
        if (str_starts_with($accountNumber, '69') || str_starts_with($accountNumber, '79')) {
            return null;
        }

        return parent::isIncomeAccount($accountNumber);
    }

    public function getDefaultJournals(): array
    {
        return [
            'VEN' => ['label' => 'Ventes', 'type' => JournalType::Sales],
            'ACH' => ['label' => 'Achats', 'type' => JournalType::Purchases],
            'FIN' => ['label' => 'Financier', 'type' => JournalType::Bank],
            'CAI' => ['label' => 'Caisse', 'type' => JournalType::Cash],
            'OD' => ['label' => 'Opérations diverses', 'type' => JournalType::Miscellaneous],
            'OUV' => ['label' => 'Ouverture', 'type' => JournalType::Opening],
        ];
    }

    public function getInvoiceMentions(Invoice $invoice): array
    {
        if (InvoiceDirection::Sale !== $invoice->getDirection()) {
            return [];
        }

        $mentions = [];

        foreach ($invoice->getVatKinds() as $kind) {
            $key = match ($kind) {
                VatKind::Franchise => 'mention.be.vat.franchise',
                VatKind::IntraEuServices => 'mention.be.vat.reverse_charge',
                VatKind::IntraEuGoods => 'mention.be.vat.intra_eu_goods',
                VatKind::Export => 'mention.be.vat.export',
                VatKind::Exempt => 'mention.be.vat.exempt',
                default => null,
            };

            if ($key) {
                $mentions[] = new LegalMention($key, LegalMention::PLACEMENT_VAT);
            }
        }

        if (in_array($invoice->getType(), [InvoiceType::Bill, InvoiceType::Penalty], true)) {
            $policy = $this->getLatePenaltyPolicy($invoice->getLedger());
            $mentions[] = new LegalMention('mention.be.payment', LegalMention::PLACEMENT_PAYMENT, [
                'days' => $invoice->getPaymentTermDays(),
                'flat_fee' => $policy->flatFee / 100,
            ]);
        }

        if ($invoice->isCreditNote()) {
            // CTVA art. 53, §2 / AR n° 1 art. 5: credit notes refer to the invoice they correct.
            $mentions[] = new LegalMention('mention.be.credit_note', LegalMention::PLACEMENT_HEADER);
        }

        return $mentions;
    }

    public function getLatePenaltyPolicy(Ledger $ledger): LatePenaltyPolicy
    {
        return new LatePenaltyPolicy(
            mode: $ledger->getSetting('late_penalty_mode', LatePenaltyPolicy::MODE_ANNUAL),
            rate: (int) $ledger->getSetting('late_penalty_rate', 1200),
            flatFee: (int) $ledger->getSetting('late_penalty_flat_fee', 4000),
        );
    }

    /**
     * A structured communication built from the invoice number's digits (year and
     * sequence), so it stays the same whenever it is printed again.
     */
    public function generatePaymentReference(Invoice $invoice): ?string
    {
        if (! $invoice->getLedger()->getSetting('be_structured_communication', true) || ! $invoice->getNumber()) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $invoice->getNumber());

        return '' === $digits ? null : StructuredCommunicationHelper::generate($digits);
    }

    public function extractPaymentReferences(string $label): array
    {
        return StructuredCommunicationHelper::extract($label);
    }

    public function getReportDefinitions(): array
    {
        return [
            ReportDefinition::fromArray(require __DIR__.'/../Resources/data/reports/balance_sheet.php'),
            ReportDefinition::fromArray(require __DIR__.'/../Resources/data/reports/income_statement.php'),
        ];
    }

    /**
     * @return list<ChartAccountDefinition>
     */
    private function readChart(string $path): array
    {
        $accounts = [];
        $handle = fopen($path, 'r');
        fgetcsv($handle, null, ';', '"', '');

        while (false !== ($row = fgetcsv($handle, null, ';', '"', ''))) {
            [$number, $label, $nature, $lettrable] = $row;
            $accounts[] = new ChartAccountDefinition($number, $label, AccountNature::from($nature), '1' === $lettrable);
        }

        fclose($handle);

        return $accounts;
    }
}
