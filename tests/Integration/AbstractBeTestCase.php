<?php

namespace Wexample\SymfonyAccountingBe\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Wexample\SymfonyAccounting\Entity\BankAccount;
use Wexample\SymfonyAccounting\Entity\BankTransaction;
use Wexample\SymfonyAccounting\Entity\Invoice;
use Wexample\SymfonyAccounting\Entity\JournalEntry;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Entity\Party;
use Wexample\SymfonyAccounting\Enum\InvoiceDirection;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceAccountingService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceEmissionService;
use Wexample\SymfonyAccounting\Service\Invoice\InvoiceFactory;
use Wexample\SymfonyAccounting\Service\Ledger\LedgerService;
use Wexample\SymfonyAccounting\Service\Ledger\PartyService;
use Wexample\SymfonyGeo\Entity\Country;

abstract class AbstractBeTestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = $this->em();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    /**
     * The country row of an ISO code, created on first use.
     */
    protected function country(string $code): Country
    {
        $repository = $this->em()->getRepository(Country::class);
        $country = $repository->findOneBy(['isoAlpha2Code' => $code]);

        if (! $country) {
            $country = (new Country())
                ->setIsoAlpha2Code($code)
                ->setIsoAlpha3Code($code.'X')
                ->setIsoNumericCode(str_pad((string) (ord($code[0]) * 3 + ord($code[1])), 3, '0', STR_PAD_LEFT))
                ->setName($code);
            $this->em()->persist($country);
            $this->em()->flush();
        }

        return $country;
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine.orm.entity_manager');
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function service(string $id): object
    {
        return static::getContainer()->get($id);
    }

    protected function createLedger(string $start = '2026-01-01'): Ledger
    {
        $ledger = $this->service(LedgerService::class)->create('Société Test', $this->country('BE'), fiscalYearStart: new DateTimeImmutable($start));
        $ledger->setLegalIdentifier('0123.456.749')->setVatNumber('BE0123456749')->setSettings(['default_vat_rate' => 2100]);
        $this->em()->flush();

        return $ledger;
    }

    protected function createParty(
        Ledger $ledger,
        string $name = 'Client A',
        ?string $countryCode = null,
        ?string $vatNumber = null,
        bool $customer = true,
        bool $supplier = false,
    ): Party {
        $party = $this->service(PartyService::class)->create($ledger, $name, $customer, $supplier, $countryCode ? $this->country($countryCode) : null);
        $party->setVatNumber($vatNumber);
        $this->em()->flush();

        return $party;
    }

    protected function emitSale(
        Ledger $ledger,
        Party $party,
        int $unitPrice = 10000,
        string $date = '2026-03-01',
        ?int $vatRate = null,
    ): Invoice {
        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, party: $party, dateInvoice: new DateTimeImmutable($date));
        $factory->addItem($invoice, 'Prestation', $unitPrice, vatRate: $vatRate);
        $this->em()->flush();

        return $this->service(InvoiceEmissionService::class)->emit($invoice);
    }

    protected function emitPurchase(
        Ledger $ledger,
        Party $supplier,
        int $unitPrice,
        string $date = '2026-03-01',
        ?int $vatRate = null,
    ): Invoice {
        $factory = $this->service(InvoiceFactory::class);
        $invoice = $factory->create($ledger, direction: InvoiceDirection::Purchase, party: $supplier, dateInvoice: new DateTimeImmutable($date))
            ->setNumber('F-'.uniqid());
        $factory->addItem($invoice, 'Achat', $unitPrice, vatRate: $vatRate);
        $this->em()->flush();

        return $this->service(InvoiceEmissionService::class)->emit($invoice);
    }

    protected function createBankAccount(Ledger $ledger): BankAccount
    {
        $bank = (new BankAccount())->setLedger($ledger)->setLabel('Banque')->setAccountNumber('550');
        $this->em()->persist($bank);
        $this->em()->flush();

        return $bank;
    }

    protected function createTransaction(
        BankAccount $bank,
        int $amount,
        string $date = '2026-03-15',
        string $label = 'Ligne'
    ): BankTransaction {
        $transaction = (new BankTransaction())->setBankAccount($bank)->setAmount($amount)
            ->setDate(new DateTimeImmutable($date))->setLabel($label)->refreshFingerprint();
        $this->em()->persist($transaction);
        $this->em()->flush();

        return $transaction;
    }

    protected function entryOf(Invoice $invoice): ?JournalEntry
    {
        return $this->service(InvoiceAccountingService::class)->findEntry($invoice);
    }

    /**
     * @return list<string>
     */
    protected function describeLines(JournalEntry $entry): array
    {
        $lines = [];

        foreach ($entry->getLines() as $line) {
            $lines[] = $line->getAccount()->getNumber().':'.$line->getDebit().'/'.$line->getCredit();
        }

        return $lines;
    }
}
