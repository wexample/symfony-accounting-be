<?php

namespace Wexample\SymfonyAccountingBe\Tests\Integration;

use Wexample\SymfonyAccounting\Service\Document\UblInvoiceBuilder;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyMoney\Enum\PriceUnit;

class PeppolTest extends AbstractBeTestCase
{
    private function xpath(string $xml, string $path): array
    {
        $document = simplexml_load_string($xml);
        $document->registerXPathNamespace('cbc', UblInvoiceBuilder::NS_CBC);
        $document->registerXPathNamespace('cac', UblInvoiceBuilder::NS_CAC);

        return array_map('strval', $document->xpath($path));
    }

    public function testInvoice(): void
    {
        $ledger = $this->createLedger();
        $ledger->setPostalAddress('Rue Neuve 1')->setPostCode('1000')->setCity('Bruxelles');
        $bank = $this->createBankAccount($ledger)->setIban('BE68539007547034')->setBic('GKCCBEBB');
        $ledger->setDefaultBankAccount($bank);
        $party = $this->createParty($ledger, 'Client SA', 'BE', 'BE0417497106');
        $party->setLegalIdentifier('0417.497.106')->setPostalAddress('Avenue Louise 10')->setPostCode('1050')->setCity('Ixelles');

        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, party: $party, title: 'PO-77');
        $factory->addItem($invoice, 'Consulting', 50000, 150);
        $factory->addItem($invoice, 'Book', 2000, 300, vatRate: 600, goods: true);
        $invoice->setPriceDiscount(1000, PriceUnit::Money);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($invoice);

        $xml = $this->service(UblInvoiceBuilder::class)->build($invoice);

        $this->assertSame(['BIL-2026-0001'], $this->xpath($xml, '/*/cbc:ID'));
        $this->assertSame(['380'], $this->xpath($xml, '//cbc:InvoiceTypeCode'));
        $this->assertSame(['0123456749'], $this->xpath($xml, '//cac:AccountingSupplierParty//cbc:EndpointID'));
        $this->assertSame(['0208'], $this->xpath($xml, '//cac:AccountingSupplierParty//cbc:EndpointID/@schemeID'));
        $this->assertSame(['0417497106'], $this->xpath($xml, '//cac:AccountingCustomerParty//cbc:EndpointID'));
        $this->assertSame(['PO-77'], $this->xpath($xml, '//cbc:BuyerReference'));
        $this->assertSame([$invoice->getPaymentReference()], $this->xpath($xml, '//cac:PaymentMeans/cbc:PaymentID'));
        $this->assertSame(['1.5', '3'], $this->xpath($xml, '//cac:InvoiceLine/cbc:InvoicedQuantity'));

        $lineTotal = (float) $this->xpath($xml, '//cbc:LineExtensionAmount')[0];
        $allowance = (float) $this->xpath($xml, '//cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount')[0];
        $taxExclusive = (float) $this->xpath($xml, '//cbc:TaxExclusiveAmount')[0];
        $tax = (float) $this->xpath($xml, '/*/cac:TaxTotal/cbc:TaxAmount')[0];
        $taxInclusive = (float) $this->xpath($xml, '//cbc:TaxInclusiveAmount')[0];

        // EN 16931 totals: BR-CO-13, BR-CO-15.
        $this->assertSame(810.00, $lineTotal);
        $this->assertEqualsWithDelta($lineTotal - $allowance, $taxExclusive, 0.001);
        $this->assertEqualsWithDelta($taxExclusive + $tax, $taxInclusive, 0.001);
        $this->assertSame((string) ($invoice->calcPriceFinal() / 100), rtrim(rtrim($this->xpath($xml, '//cbc:PayableAmount')[0], '0'), '.'));

        $allowances = array_sum(array_map('floatval', $this->xpath($xml, '/*/cac:AllowanceCharge/cbc:Amount')));
        $this->assertEqualsWithDelta(10.00, $allowances, 0.001);
        $this->assertSame(['S', 'S'], $this->xpath($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'));
        $this->assertSame(['6.00', '21.00'], $this->xpath($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:Percent'));
    }

    public function testReverseChargeCreditNote(): void
    {
        $ledger = $this->createLedger();
        $party = $this->createParty($ledger, 'Dutch BV', 'NL', 'NL123456789B01');
        $bill = $this->emitSale($ledger, $party, 100000);
        $credit = $this->service(InvoiceFactory::class)->createCreditNote($bill);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($credit);

        $xml = $this->service(UblInvoiceBuilder::class)->build($credit);

        $this->assertStringContainsString('<CreditNote', $xml);
        $this->assertSame(['381'], $this->xpath($xml, '//cbc:CreditNoteTypeCode'));
        $this->assertSame(['AE'], $this->xpath($xml, '//cac:TaxSubtotal/cac:TaxCategory/cbc:ID'));
        $this->assertSame(['VATEX-EU-AE'], $this->xpath($xml, '//cbc:TaxExemptionReasonCode'));
        $this->assertSame(['9944'], $this->xpath($xml, '//cac:AccountingCustomerParty//cbc:EndpointID/@schemeID'));
        $this->assertSame(['NL123456789B01'], $this->xpath($xml, '//cac:AccountingCustomerParty//cbc:EndpointID'));
    }

    public function testReceivedInvoiceBecomesAPurchase(): void
    {
        // The seller's books.
        $seller = $this->createLedger();
        $seller->setName('Seller SRL')->setPostalAddress('Rue Haute 5')->setPostCode('4000')->setCity('Liège');
        $sellerBank = $this->createBankAccount($seller)->setIban('BE68539007547034');
        $seller->setDefaultBankAccount($sellerBank);
        $buyerInSellerBooks = $this->createParty($seller, 'Buyer SA', 'BE', 'BE0417497106');
        $factory = $this->service(InvoiceFactory::class);
        $sale = $factory->create($seller, party: $buyerInSellerBooks);
        $factory->addItem($sale, 'Design', 40000, 250)->setPriceDiscount(1000, PriceUnit::Percent);
        $factory->addItem($sale, 'Printing', 5000, 400, vatRate: 600, goods: true);
        $sale->setPriceDiscount(2000, PriceUnit::Money);
        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($sale);
        $xml = $this->service(UblInvoiceBuilder::class)->build($sale);

        // The buyer's books receive it.
        $buyer = $this->service(\Wexample\SymfonyAccounting\Service\Ledger\LedgerService::class)->create('Buyer SA', 'BE', fiscalYearStart: new \DateTimeImmutable('2026-01-01'));
        $result = $this->service(\Wexample\SymfonyAccounting\Service\Document\UblInvoiceReader::class)->read($buyer, $xml);
        $purchase = $result->invoice;

        $this->assertSame([], $result->warnings);
        $this->assertTrue($result->partyCreated);
        $this->assertSame('Seller SRL', $purchase->getParty()->getName());
        $this->assertSame('BE68539007547034', $purchase->getParty()->getIban());
        $this->assertSame($sale->getNumber(), $purchase->getNumber());
        $this->assertSame($sale->getPaymentReference(), $purchase->getPaymentReference());
        $this->assertSame($sale->calcPriceFinal(), $purchase->calcPriceFinal());

        $this->em()->flush();
        $this->service(InvoiceEmissionService::class)->emit($purchase);
        $this->assertSame($sale->calcPriceFinal(), $this->entryOf($purchase)->getLines()->last()->getCredit());
    }
}
