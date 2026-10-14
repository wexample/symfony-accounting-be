<?php

namespace Wexample\SymfonyAccountingBe\Tests\Integration;

use DateTimeImmutable;
use Wexample\SymfonyAccounting\Class\EntryDraft;
use Wexample\SymfonyAccounting\Enum\JournalType;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearClosingService;
use Wexample\SymfonyAccounting\Service\Ledger\FiscalYearService;
use Wexample\SymfonyAccounting\Service\Ledger\PostingService;
use Wexample\SymfonyAccountingBe\Service\Nbb\NbbAnnualAccountsBuilder;
use Wexample\SymfonyAccountingBe\Service\Nbb\NbbXbrlWriter;

class NbbAnnualAccountsTest extends AbstractBeTestCase
{
    private function year(): array
    {
        $ledger = $this->createLedger();
        $ledger->getOrganization()->setName('Atelier Test')->setLegalForm('SRL')->setAddress($this->address('Rue Neuve 12 bte 3', '1000', 'Bruxelles', 'BE'));
        $ledger->setSettings([...$ledger->getSettings(), 'be_nbb' => ['court' => 'm31', 'last_deed_date' => '2020-01-15']]);

        $posting = $this->service(PostingService::class);
        // Capital paid in, equipment bought then depreciated.
        $posting->postDraft($ledger, (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable('2026-01-02'), 'Capital'))->add('550', 1860000)->add('100', -1860000));
        $posting->postDraft($ledger, (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable('2026-01-05'), 'Laptop'))->add('2400', 300000)->add('550', -300000));
        $posting->postDraft($ledger, (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable('2026-12-31'), 'Depreciation'))->add('6302', 100000)->add('2409', -100000));

        $this->emitSale($ledger, $this->createParty($ledger), 500000, '2026-03-01');
        $this->emitPurchase($ledger, $this->createParty($ledger, 'Fournisseur', customer: false, supplier: true), 80000, '2026-04-01');
        $this->em()->flush();

        return [$ledger, $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2026-06-01'))];
    }

    public function testTheRubricsHoldTheNbbArithmetic(): void
    {
        [, $fiscalYear] = $this->year();
        ['current' => $rubrics] = $this->service(NbbAnnualAccountsBuilder::class)->build($fiscalYear);

        $this->assertSame($rubrics['20/58'], $rubrics['10/49']);
        $this->assertSame(1860000, $rubrics['10']);
        // 5 000 of sales, 800 of services, 1 000 of depreciation.
        $this->assertSame(500000 - 80000, $rubrics['9900']);
        $this->assertSame(500000 - 80000 - 100000, $rubrics['9905']);
        $this->assertSame($rubrics['9905'], $rubrics['14']);
        // The notes agree with the balance sheet and the income statement.
        $this->assertSame(300000, $rubrics['8169']);
        $this->assertSame(100000, $rubrics['8279']);
        $this->assertSame($rubrics['22/27'], $rubrics['8199'] + ($rubrics['8259'] ?? 0) - $rubrics['8329']);
        $this->assertSame($rubrics['630'], $rubrics['8279']);
    }

    public function testTheInstanceCarriesTheRubricsInTheTaxonomysDimensions(): void
    {
        [, $fiscalYear] = $this->year();
        $xbrl = $this->service(NbbXbrlWriter::class)->write($fiscalYear, new DateTimeImmutable('2027-06-01'));

        if ($dump = getenv('NBB_XBRL_DUMP')) {
            file_put_contents($dump, $xbrl);
        }

        $document = simplexml_load_string($xbrl);
        $document->registerXPathNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $document->registerXPathNamespace('link', 'http://www.xbrl.org/2003/linkbase');
        $document->registerXPathNamespace('met', 'http://www.nbb.be/be/fr/cbso/dict/met');

        $this->assertSame(['0123456749'], array_unique(array_map('strval', $document->xpath('//xbrli:identifier'))));
        $this->assertStringEndsWith('/mod/m07/m07-f.xsd', (string) $document->xpath('//link:schemaRef/@xlink:href')[0]);
        $this->assertContains('Atelier Test', array_map('strval', $document->xpath('//met:str2')));
        $this->assertContains('18600.00', array_map('strval', $document->xpath('//met:am1')));
        $this->assertStringContainsString('lgf:m610', $xbrl);
        $this->assertStringContainsString('pcd:m1000', $xbrl);
    }

    public function testASecondYearReportsThePreviousOneAndTheOpeningValues(): void
    {
        [$ledger, $first] = $this->year();
        $this->service(FiscalYearClosingService::class)->close($first, force: true);
        $second = $this->service(FiscalYearService::class)->getForDate($ledger, new DateTimeImmutable('2027-06-01'));
        $this->service(PostingService::class)->postDraft($ledger, (new EntryDraft(JournalType::Miscellaneous, new DateTimeImmutable('2027-12-31'), 'Depreciation'))->add('6302', 100000)->add('2409', -100000));
        $this->emitSale($ledger, $this->createParty($ledger, 'Client 2027'), 200000, '2027-03-01');

        ['current' => $current, 'previous' => $previous] = $this->service(NbbAnnualAccountsBuilder::class)->build($second);

        $this->assertSame($current['20/58'], $current['10/49']);
        $this->assertSame($previous['20/58'], $previous['10/49']);
        $this->assertSame(300000, $previous['8199P']);
        $this->assertSame(100000, $previous['8329P']);
        $this->assertSame(200000, $current['8329']);
        // Last year's result, carried, is this year's 14P.
        $this->assertSame($previous['9905'], $current['14P']);

        $xbrl = $this->service(NbbXbrlWriter::class)->write($second, new DateTimeImmutable('2028-06-01'));
        if ($dump = getenv('NBB_XBRL_DUMP')) {
            file_put_contents(str_replace('.xbrl', '-second.xbrl', $dump), $xbrl);
        }
        $this->assertStringContainsString('2026-12-31', $xbrl);
    }

    public function testTheCourtIsRequired(): void
    {
        [$ledger, $fiscalYear] = $this->year();
        $ledger->setSettings([...$ledger->getSettings(), 'be_nbb' => ['last_deed_date' => '2020-01-15']]);

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage('be_nbb.court');

        $this->service(NbbXbrlWriter::class)->write($fiscalYear, new DateTimeImmutable('2027-06-01'));
    }
}
