<?php

namespace Wexample\SymfonyAccountingBe\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Enum\InvoiceStatus;
use Wexample\SymfonyAccounting\Repository\AccountRepository;
use Wexample\SymfonyAccounting\Service\Bank\BankImportService;
use Wexample\SymfonyAccounting\Service\Bank\MatchingService;
use Wexample\SymfonyAccounting\Service\Document\InvoiceDocumentDataBuilder;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Jurisdiction\JurisdictionRegistry;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearClosingService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyAccounting\Service\Report\FinancialStatementService;
use Wexample\SymfonyAccounting\Service\Vat\VatReturnService;
use Wexample\SymfonyAccountingBe\Service\Listing\BeClientListingProvider;
use Wexample\SymfonyAccountingBe\Service\Listing\BeIntraEuListingProvider;
use Wexample\SymfonyAccountingBe\Tests\Unit\CodaParserTest;

class BeAccountingTest extends AbstractBeTestCase
{
    public function testPcmnAndSaleBooking(): void
    {
        $ledger = $this->createLedger();
        $accounts = $this->service(AccountRepository::class)->findIndexedByNumber($ledger);
        $this->assertSame('Clients', $accounts['400']->getLabel());
        $this->assertTrue($accounts['400']->isLettrable());

        $invoice = $this->emitSale($ledger, $this->createParty($ledger));
        $this->assertSame(['700:0/10000', '451:0/2100', '400:12100/0'], $this->describeLines($this->entryOf($invoice)));
        $this->assertSame('VEN', $this->entryOf($invoice)->getJournal()->getCode());

        $jurisdiction = $this->service(JurisdictionRegistry::class)->forLedger($ledger);
        $keys = array_map(fn ($m) => $m->key, $jurisdiction->getInvoiceMentions($invoice));
        $this->assertSame(['mention.be.payment'], $keys);
        $this->assertSame('2409', $jurisdiction->getDepreciationAccountNumber('2400'));
    }

    public function testStructuredCommunicationIsPrintedAndMatched(): void
    {
        $ledger = $this->createLedger();
        $invoice = $this->emitSale($ledger, $this->createParty($ledger));

        $reference = \Wexample\SymfonyAccountingBe\Helper\StructuredCommunicationHelper::generate('20260001');
        $this->assertSame('BIL-2026-0001', $invoice->getNumber());
        $this->assertSame($reference, $invoice->getPaymentReference());

        $data = $this->service(InvoiceDocumentDataBuilder::class)->build($invoice, 'fr');
        $this->assertSame($reference, $data['payment']['reference']);
        // The enterprise number is part of the VAT number: printed once.
        $this->assertNull($data['issuer']['legalIdentifier']);
        $this->assertSame('BE0123456749', $data['issuer']['vatNumber']);

        $bank = $this->createBankAccount($ledger);
        $result = $this->service(BankImportService::class)->importContent($bank, CodaParserTest::sample(preg_replace('/\D/', '', $reference)));
        $this->assertSame(2, $result->count());

        $this->service(MatchingService::class)->run($ledger);
        $this->assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        $this->assertSame('reference', $invoice->getAllocations()->first()->getOrigin());
    }

    public function testQuarterlyVatReturn(): void
    {
        $ledger = $this->createLedger();
        $customer = $this->createParty($ledger);
        $factory = $this->service(InvoiceFactory::class);

        $bill = $this->emitSale($ledger, $customer, 100000, '2026-01-10');
        $this->emitSale($ledger, $this->createParty($ledger, 'Libraire'), 20000, '2026-01-11', 600);
        $this->emitSale($ledger, $this->createParty($ledger, 'Client NL', 'NL', 'NL123456789B01'), 50000, '2026-02-01');
        $credit = $factory->createCreditNote($bill, 10000, 'Remise');
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($credit, new DateTimeImmutable('2026-02-15'));

        $supplier = $this->createParty($ledger, 'Fournisseur', customer: false, supplier: true);
        $this->emitPurchase($ledger, $supplier, 30000, '2026-02-20');
        $goods = $factory->create($ledger, direction: \Wexample\SymfonyAccounting\Enum\InvoiceDirection::Purchase, party: $supplier, dateInvoice: new DateTimeImmutable('2026-03-01'))->setNumber('M-1');
        $factory->addItem($goods, 'Marchandises', 40000, goods: true);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($goods);

        $vat = $this->service(VatReturnService::class);
        $grids = $vat->fillForms($vat->compute($ledger, new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-03-31')))['be_periodic'];

        $this->assertSame(100000, $grids['03']);
        $this->assertSame(20000, $grids['01']);
        $this->assertSame(50000, $grids['44']);
        $this->assertSame(10000, $grids['49']);
        $this->assertSame(21000 + 1200, $grids['54']);
        $this->assertSame(2100, $grids['64']);
        $this->assertSame(40000, $grids['81']);
        $this->assertSame(30000, $grids['82']);
        $this->assertSame(6300 + 8400, $grids['59']);
        $this->assertSame(22200 - 2100 - 14700, $grids['71']);
        $this->assertSame(0, $grids['72']);
    }

    public function testListings(): void
    {
        $ledger = $this->createLedger();
        $this->emitSale($ledger, $this->createParty($ledger, 'Client BE', 'BE', 'BE0123456749'), 30000, '2026-04-01');
        $this->emitSale($ledger, $this->createParty($ledger, 'Petit client', 'BE', 'BE0417497106'), 10000, '2026-05-01');
        $this->emitSale($ledger, $this->createParty($ledger, 'Client NL', 'NL', 'NL123456789B01'), 50000, '2026-05-10');

        $listing = $this->service(BeClientListingProvider::class)->build($ledger, 2026);
        $this->assertSame([['vatNumber' => 'BE0123456749', 'name' => 'Client BE', 'base' => 30000, 'vat' => 6300]], $listing);

        $intra = $this->service(BeIntraEuListingProvider::class)->build($ledger, new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2026-06-30'));
        $this->assertSame([['vatNumber' => 'NL123456789B01', 'code' => 'S', 'amount' => 50000]], $intra);
    }

    public function testStatementsAndClosing(): void
    {
        $ledger = $this->createLedger();
        $this->emitSale($ledger, $this->createParty($ledger), 100000, '2026-02-01');
        $this->emitPurchase($ledger, $this->createParty($ledger, 'Fournisseur', customer: false, supplier: true), 30000, '2026-03-01');
        $fiscalYear = $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2026-06-01'));

        $income = $this->service(FinancialStatementService::class)->build($fiscalYear, 'income_statement');
        $this->assertSame(100000, $income->get('70'));
        $this->assertSame(30000, $income->get('61'));
        $this->assertSame(70000, $income->get('result'));

        $sheet = $this->service(FinancialStatementService::class)->build($fiscalYear, 'balance_sheet');
        $this->assertSame($sheet->get('assets'), $sheet->get('liabilities'));

        $this->service(FiscalYearClosingService::class)->close($fiscalYear, force: true);
        $this->assertSame(70000, $fiscalYear->getResult());
    }
}
