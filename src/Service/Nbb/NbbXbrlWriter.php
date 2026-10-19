<?php

namespace Wexample\SymfonyAccountingBe\Service\Nbb;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use Wexample\SymfonyAccounting\Entity\FiscalYear;
use Wexample\SymfonyAccounting\Entity\Ledger;
use Wexample\SymfonyAccounting\Exception\AccountingException;
use Wexample\SymfonyAccounting\Repository\FiscalYearRepository;
use Wexample\SymfonyMoney\Helper\MoneyHelper;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A Belgian company's annual accounts as the XBRL instance the National Bank's
 * Central Balance Sheet Office takes in, micro or abridged model with capital:
 * identification, balance sheet, income statement, appropriation and fixed asset
 * notes. Each rubric is reported with the concept and the dimension members the
 * NBB taxonomy gives it (Resources/data/nbb/datapoints.php, generated from the
 * taxonomy by bin/nbb-datapoints.py).
 *
 * Read from the ledger: its name, enterprise number, legal form, address. From its
 * settings: `be_nbb.court` (enterprise court, a member of the NBB list such as
 * `m31` for Brussels, French-speaking), `be_nbb.last_deed_date` (filing date of
 * the latest deed), and `be_nbb.legal_form` when the form is not one this writer
 * recognizes. The approval date of the general meeting is given at writing.
 *
 * Filing goes through the NBB's application; validating the instance against the
 * taxonomy's checks before is what Arelle does (see the package's knowledge).
 */
class NbbXbrlWriter implements ResetInterface
{
    public const string MODEL_MICRO = 'micro';
    public const string MODEL_ABRIDGED = 'abridged';

    public const string SCHEME = 'http://www.fgov.be';

    private const string NS_XBRLI = 'http://www.xbrl.org/2003/instance';
    private const string NS_XBRLDI = 'http://xbrl.org/2006/xbrldi';
    private const string NS_DICT = 'http://www.nbb.be/be/fr/cbso/dict/';

    /** NBB legal forms (lgf list 2) of the usual forms, by their abbreviations. */
    private const array LEGAL_FORMS = [
        'SRL' => 'm610', 'BV' => 'm610',
        'SA' => 'm014', 'NV' => 'm014',
        'SC' => 'm706', 'CV' => 'm706',
    ];

    private const array IDENTIFICATION = [
        'enterpriseNumber' => ['met:str2', ['dim:bas' => 'bas:m26', 'dim:psn' => 'psn:m1', 'dim:qlt' => 'qlt:m1', 'dim:part' => 'part:m2']],
        'yearStart' => ['met:dte1', ['dim:part' => 'part:m2', 'dim:mmt' => 'mmt:m1', 'dim:bas' => 'bas:m27', 'dim:prd' => 'prd:m1']],
        'yearEnd' => ['met:dte1', ['dim:part' => 'part:m2', 'dim:mmt' => 'mmt:m2', 'dim:bas' => 'bas:m27', 'dim:prd' => 'prd:m1']],
        'previousStart' => ['met:dte1', ['dim:part' => 'part:m2', 'dim:mmt' => 'mmt:m1', 'dim:bas' => 'bas:m27', 'dim:prd' => 'prd:m2']],
        'previousEnd' => ['met:dte1', ['dim:part' => 'part:m2', 'dim:mmt' => 'mmt:m2', 'dim:bas' => 'bas:m27', 'dim:prd' => 'prd:m2']],
        'approval' => ['met:dte1', ['dim:evt' => 'evt:m1', 'dim:bas' => 'bas:m27', 'dim:part' => 'part:m2']],
        'name' => ['met:str2', ['dim:bas' => 'bas:m29', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2']],
        'legalForm' => ['lgf-enum:list2', ['dim:bas' => 'bas:m30', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2']],
        'street' => ['met:str2', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m1']],
        'number' => ['met:str2', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m2']],
        'box' => ['met:str2', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m3']],
        'postalCode' => ['pcd-enum:list1', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m4']],
        'otherPostalCode' => ['met:str2', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m4']],
        'otherCity' => ['met:str2', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m5']],
        'country' => ['cty-enum:list1', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m6']],
        'court' => ['cct-enum:list1', ['dim:bas' => 'bas:m32', 'dim:part' => 'part:m2']],
        'lastDeed' => ['met:dte1', ['dim:bas' => 'bas:m27', 'dim:evt' => 'evt:m2', 'dim:part' => 'part:m2']],
        'email' => ['met:str2', ['dim:bas' => 'bas:m31', 'dim:psn' => 'psn:m1', 'dim:part' => 'part:m2', 'dim:ctc' => 'ctc:m7']],
    ];

    /** @var array<string, string> Context key → id. */
    private array $contexts = [];

    private ?array $datapoints = null;

    public function __construct(
        private readonly NbbAnnualAccountsBuilder $builder,
        private readonly FiscalYearRepository $fiscalYearRepository,
    ) {
    }

    /**
     * The contexts of the filing being written, not of the next one.
     */
    public function reset(): void
    {
        $this->contexts = [];
    }

    public function getTaxonomyVersion(): string
    {
        return $this->datapoints()['taxonomy'];
    }

    public function write(
        FiscalYear $fiscalYear,
        DateTimeImmutable $approvalDate,
        string $model = self::MODEL_MICRO
    ): string {
        $ledger = $fiscalYear->getLedger();
        $datapoints = $this->datapoints();
        $entryPoint = $datapoints['entryPoints'][$model] ?? throw new AccountingException(sprintf('Unknown NBB model "%s": micro or abridged.', $model));
        $enterpriseNumber = $this->enterpriseNumber($ledger);
        $values = $this->builder->build($fiscalYear);

        $this->contexts = [];
        $facts = [];
        foreach ($this->identification($ledger, $fiscalYear, $approvalDate) as $key => $value) {
            [$concept, $dimensions] = self::IDENTIFICATION[$key];
            $facts[] = [$concept, $dimensions, $value, false];
        }

        foreach (['current' => 'prd:m1', 'previous' => 'prd:m2'] as $year => $period) {
            foreach ($values[$year] as $code => $amount) {
                $datapoint = $datapoints[$year][$code] ?? null;

                // Zero is not reported: the NBB reads an absent rubric as zero.
                if (null === $datapoint || 0 === $amount) {
                    continue;
                }

                $concept = $datapoint['concept'];
                unset($datapoint['concept']);
                $facts[] = [$concept, $datapoint + ['dim:prd' => $period], MoneyHelper::toDecimal($amount, 'EUR'), true];
            }
        }

        return $this->render($entryPoint, $enterpriseNumber, $fiscalYear->getDateEnd(), $facts);
    }

    /**
     * @return array<string, string>
     */
    private function identification(
        Ledger $ledger,
        FiscalYear $fiscalYear,
        DateTimeImmutable $approvalDate
    ): array {
        $settings = $ledger->getSetting('be_nbb', []);
        $address = $ledger->getAddress();
        [$street, $number, $box] = $this->splitStreet((string) strtok((string) $address?->getPostalAddress(), "\r\n"));
        $postalCode = (string) $address?->getPostCode();
        $knownPostalCode = in_array($postalCode, explode(',', $this->datapoints()['postalCodes']), true);
        $country = $ledger->getCountry()?->getIsoAlpha2Code() ?? 'BE';
        $previousYear = $this->fiscalYearRepository->findPrevious($fiscalYear);

        $identification = [
            'enterpriseNumber' => $this->enterpriseNumber($ledger),
            'yearStart' => $fiscalYear->getDateStart()->format('Y-m-d'),
            'yearEnd' => $fiscalYear->getDateEnd()->format('Y-m-d'),
            // A first year has no previous one.
            'previousStart' => $previousYear?->getDateStart()->format('Y-m-d'),
            'previousEnd' => $previousYear?->getDateEnd()->format('Y-m-d'),
            'approval' => $approvalDate->format('Y-m-d'),
            'name' => $ledger->getName(),
            'legalForm' => 'lgf:'.($settings['legal_form'] ?? self::LEGAL_FORMS[strtoupper((string) $ledger->getLegalForm())] ?? throw new AccountingException(sprintf('Legal form "%s" unknown to the NBB list: set be_nbb.legal_form.', $ledger->getLegalForm()))),
            'street' => $street,
            'number' => $number,
            'box' => $box,
            'country' => 'cty:m'.$country,
            'court' => 'cct:'.($settings['court'] ?? throw new AccountingException('The enterprise court is required: set be_nbb.court.')),
            'lastDeed' => $settings['last_deed_date'] ?? throw new AccountingException('The filing date of the latest deed is required: set be_nbb.last_deed_date.'),
            'email' => $ledger->getEmail(),
        ];

        if ($knownPostalCode) {
            $identification['postalCode'] = 'pcd:m'.$postalCode;
        } else {
            $identification['otherPostalCode'] = $postalCode;
            $identification['otherCity'] = (string) $address?->getCity();
        }

        return array_filter($identification, fn (?string $value) => null !== $value && '' !== trim($value));
    }

    /**
     * "Rue Neuve 12 bte 3" → street, number, box.
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function splitStreet(string $line): array
    {
        if (preg_match('/^(.*?)[\s,]+(\d+[a-zA-Z]?)(?:[\s,]+(?:bte|bus|boîte|box|b)\.?\s*(\S+))?$/iu', trim($line), $parts)) {
            return [$parts[1], $parts[2], $parts[3] ?? null];
        }

        return [trim($line), null, null];
    }

    private function enterpriseNumber(Ledger $ledger): string
    {
        $number = preg_replace('/\D/', '', (string) $ledger->getLegalIdentifier());

        if (10 !== strlen($number)) {
            throw new AccountingException('The ledger needs its enterprise number, ten digits.');
        }

        return $number;
    }

    /**
     * @param list<array{0: string, 1: array<string, string>, 2: string, 3: bool}> $facts
     */
    private function render(
        string $entryPoint,
        string $enterpriseNumber,
        DateTimeImmutable $instant,
        array $facts
    ): string {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $root = $xml->appendChild($xml->createElementNS(self::NS_XBRLI, 'xbrli:xbrl'));
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:link', 'http://www.xbrl.org/2003/linkbase');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xlink', 'http://www.w3.org/1999/xlink');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xbrldi', self::NS_XBRLDI);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:iso4217', 'http://www.xbrl.org/2003/iso4217');
        $major = implode('.', array_slice(explode('.', $this->getTaxonomyVersion()), 0, 2));

        $prefixes = [];
        foreach ($facts as [$concept, $dimensions, $value, $monetary]) {
            $prefixes[strstr($concept, ':', true)] = true;
            foreach ($dimensions as $dimension => $member) {
                $prefixes[strstr($member, ':', true)] = true;
            }
            if (! $monetary && preg_match('/^([a-z]+):m/', $value, $member)) {
                $prefixes[$member[1]] = true;
            }
        }
        $prefixes['dim'] = true;

        foreach (array_keys($prefixes) as $prefix) {
            $namespace = match (true) {
                'met' === $prefix, 'dim' === $prefix => self::NS_DICT.$prefix,
                str_ends_with($prefix, '-enum') => 'http://www.nbb.be/be/fr/cbso/'.$major.'/enum/'.$prefix,
                default => self::NS_DICT.'dom/'.$prefix,
            };
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:'.$prefix, $namespace);
        }

        $schemaRef = $root->appendChild($xml->createElementNS('http://www.xbrl.org/2003/linkbase', 'link:schemaRef'));
        $schemaRef->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:type', 'simple');
        $schemaRef->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', $entryPoint);

        $contextAnchor = $xml->createComment(' contexts ');
        $root->appendChild($contextAnchor);

        $unit = $xml->createElementNS(self::NS_XBRLI, 'xbrli:unit');
        $unit->setAttribute('id', 'EUR');
        $unit->appendChild($xml->createElementNS(self::NS_XBRLI, 'xbrli:measure', 'iso4217:EUR'));
        $root->appendChild($unit);

        foreach ($facts as [$concept, $dimensions, $value, $monetary]) {
            $namespace = $root->lookupNamespaceURI(strstr($concept, ':', true));
            $fact = $root->appendChild($xml->createElementNS($namespace, $concept));
            $fact->appendChild($xml->createTextNode($value));
            $fact->setAttribute('contextRef', $this->context($root, $contextAnchor, $enterpriseNumber, $instant, $dimensions));

            if ($monetary) {
                $fact->setAttribute('unitRef', 'EUR');
                $fact->setAttribute('decimals', 'INF');
            }
        }

        $root->removeChild($contextAnchor);

        return $xml->saveXML();
    }

    /**
     * @param array<string, string> $dimensions
     */
    private function context(
        DOMElement $root,
        \DOMNode $anchor,
        string $enterpriseNumber,
        DateTimeImmutable $instant,
        array $dimensions
    ): string {
        ksort($dimensions);
        $key = json_encode($dimensions);

        if (isset($this->contexts[$key])) {
            return $this->contexts[$key];
        }

        $xml = $root->ownerDocument;
        $id = 'c'.(count($this->contexts) + 1);
        $context = $xml->createElementNS(self::NS_XBRLI, 'xbrli:context');
        $context->setAttribute('id', $id);
        $entity = $context->appendChild($xml->createElementNS(self::NS_XBRLI, 'xbrli:entity'));
        $identifier = $entity->appendChild($xml->createElementNS(self::NS_XBRLI, 'xbrli:identifier', $enterpriseNumber));
        $identifier->setAttribute('scheme', self::SCHEME);
        $period = $context->appendChild($xml->createElementNS(self::NS_XBRLI, 'xbrli:period'));
        $period->appendChild($xml->createElementNS(self::NS_XBRLI, 'xbrli:instant', $instant->format('Y-m-d')));
        $scenario = $context->appendChild($xml->createElementNS(self::NS_XBRLI, 'xbrli:scenario'));

        foreach ($dimensions as $dimension => $member) {
            $explicit = $scenario->appendChild($xml->createElementNS(self::NS_XBRLDI, 'xbrldi:explicitMember', $member));
            $explicit->setAttribute('dimension', $dimension);
        }

        $root->insertBefore($context, $anchor);

        return $this->contexts[$key] = $id;
    }

    private function datapoints(): array
    {
        return $this->datapoints ??= require __DIR__.'/../../Resources/data/nbb/datapoints.php';
    }
}
