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
