# symfony-accounting-be

Version: 4.0.1

## Chart and journals

`be_pcmn` is loaded by default, with the journals VEN, ACH, FIN, CAI, OD and OUV. Roles: 400/440, 700, 604 and 61, 550, 451 (VAT due) and 411 (VAT to recover), 4510 for self-assessed VAT, 4512/4112 for the settled balance and credit, 46 for deposits. The result of a closed year is carried to 140/141; classes 69/79 (appropriations) are neither result nor balance sheet.

## Invoices and payments

Each sale gets a structured communication at emission (`+++202/6000/01206+++`, built from the invoice number), printed on the document and in its EPC QR payload. When a CODA or CAMT line carries it, the reference matcher settles the invoice at once. Turn it off with the setting `be_structured_communication: false`.

Mentions: franchise regime, autoliquidation (art. 21 § 2 CTVA), intra-EU supplies (39bis), export (39), exemption (44), credit notes, and on bills the late-payment terms (law of 2 August 2002, 40 € flat fee; set `late_penalty_rate` to the legal rate of the semester).

## Returns and listings

`BeVatReturnForm` fills grids 01–03, 44–49, 54–64, 71–72, 81–88 and 91 from the core VAT return; credit notes go to their own grids. Purchases split by account: 60 → 81, class 2 → 83, the rest → 82. Self-assessed purchases: services from the EU in 88 (tax in 55), services from outside the EU and the cocontractant regime (construction works) in 87 (tax in 56). A sale under the cocontractant regime goes to 45, with its mention.

`BeClientListingProvider` gives the annual listing (Belgian VAT-registered customers from 250 €), `BeIntraEuListingProvider` the intra-Community listing by VAT number and code (L, S).

## Bank files

`coda` reads CODA v2: movements of detail 0000 only (globalised details are not counted twice), structured communications, counterparty name and IBAN, the new balance.

## Annual accounts for the National Bank (XBRL)

`NbbXbrlWriter::write($fiscalYear, $approvalDate, 'micro'|'abridged')` produces the XBRL instance the NBB's Central Balance Sheet Office takes in, for a company with capital. It contains:
- the identification: enterprise number, periods, name, legal form, address, court, latest deed, approval;
- the balance sheet, the income statement and the appropriation;
- the fixed asset notes (8029 to 8555).

`NbbAnnualAccountsBuilder` computes the rubrics:
- the balance sheet and the income statement come from the report definition `nbb_annual_accounts`, whose formulas are the ones the NBB checks;
- the notes come from the opening entries and the year's movements on class 2. A fourth digit 9 is depreciation, 8 a revaluation surplus, and on 28 a 1 is an amount not called up;
- the previous year comes from its own books, or else from this year's opening entries.

The year's result is carried forward (14), since its appropriation is decided after.

Each rubric is reported with the concept and the dimension members of the NBB taxonomy, generated in `Resources/data/nbb/datapoints.php` by `bin/nbb-datapoints.py` from the taxonomy archive. When the NBB publishes a new version, run the script on the new archive.

Ledger settings, under `be_nbb`:
- `court`: the enterprise court, a member of the NBB list, such as `m31` for Brussels, French-speaking;
- `last_deed_date`: the filing date of the latest deed;
- `legal_form`: an NBB code, when the ledger's legal form is not SRL/BV, SA/NV or SC/CV.

Check an instance before filing with Arelle (`pip install arelle-release`), against the official taxonomy package:

```bash
arelleCmdLine --packages nbb-cbso-26.0.15.zip -f accounts.xbrl -v --formula run --formulaUnsatisfiedAsser
```

Not produced: administrators (s.02), the other notes, the social balance sheet. The NBB application asks for them when the company has them.

## Table of Contents

- [Chart and journals](#chart-and-journals)
- [Invoices and payments](#invoices-and-payments)
- [Returns and listings](#returns-and-listings)
- [Bank files](#bank-files)
- [Annual accounts for the National Bank (XBRL)](#annual-accounts-for-the-national-bank-xbrl)
- [Architecture](#architecture)
- [Integration in the Suite](#integration-in-the-suite)
- [Dependencies](#dependencies)
- [Versioning & Compatibility Policy](#versioning--compatibility-policy)
- [License](#license)
- [About us](#about-us)
- [Migration Notes](#migration-notes)

## Architecture

src/Service/BeJurisdiction.php extends the core `AbstractJurisdiction` and reads its chart from src/Resources/data/charts/be_pcmn.csv and its layouts (NBB abbreviated schema) from src/Resources/data/reports. src/Helper/BeIdentityHelper.php and src/Helper/StructuredCommunicationHelper.php hold the mod-97 rules.

The CODA parser, the VAT form and the listings plug into the core extension points; the listings read emitted invoices, the VAT form reads the core `VatReturn`, whose per-account bases and reversed amounts (credit notes) exist for it.

The PCMN dataset was written from the official structure, not imported from an official file: an accountant should check it, and the account roles chosen for VAT settlement (4512, 4112, 4113).

## Integration in the Suite

This package is part of the Wexample Suite — a collection of high-quality, modular tools designed to work seamlessly together across multiple languages and environments.

### Related Packages

The suite includes packages for configuration management, file handling, prompts, and more. Each package can be used independently or as part of the integrated suite.

Visit the [Wexample Suite documentation](https://docs.wexample.com) for the complete package ecosystem.

## Dependencies

- php: >=8.5
- wexample/symfony-accounting: >=6.0.0
- wexample/symfony-helpers: >=16.0.0

## Versioning & Compatibility Policy

Wexample packages follow **Semantic Versioning** (SemVer):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backward compatible
- **PATCH**: Bug fixes, backward compatible

We maintain backward compatibility within major versions and provide clear migration guides for breaking changes.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

Free to use in both personal and commercial projects.

## About us

[Wexample](https://wexample.com) stands as a cornerstone of the digital ecosystem — a collective of seasoned engineers, researchers, and creators driven by a relentless pursuit of technological excellence. More than a media platform, it has grown into a vibrant community where innovation meets craftsmanship, and where every line of code reflects a commitment to clarity, durability, and shared intelligence.

This packages suite embodies this spirit. Trusted by professionals and enthusiasts alike, it delivers a consistent, high-quality foundation for modern development — open, elegant, and battle-tested. Its reputation is built on years of collaboration, refinement, and rigorous attention to detail, making it a natural choice for those who demand both robustness and beauty in their tools.

Wexample cultivates a culture of mastery. Each package, each contribution carries the mark of a community that values precision, ethics, and innovation — a community proud to shape the future of digital craftsmanship.

## Migration Notes

When upgrading between major versions, refer to the migration guides in the documentation.

Breaking changes are clearly documented with upgrade paths and examples.
