## Chart and journals

`be_pcmn` is loaded by default, with the journals VEN, ACH, FIN, CAI, OD and OUV. Roles: 400/440, 700, 604 and 61, 550, 451 (VAT due) and 411 (VAT to recover), 4510 for self-assessed VAT, 4512/4112 for the settled balance and credit, 46 for deposits. The result of a closed year is carried to 140/141; classes 69/79 (appropriations) are neither result nor balance sheet.

## Invoices and payments

Each sale gets a structured communication at emission (`+++202/6000/01206+++`, built from the invoice number), printed on the document and in its EPC QR payload. When a CODA or CAMT line carries it, the reference matcher settles the invoice at once. Turn it off with the setting `be_structured_communication: false`.

Mentions: franchise regime, autoliquidation (art. 21 § 2 CTVA), intra-EU supplies (39bis), export (39), exemption (44), credit notes, and on bills the late-payment terms (law of 2 August 2002, 40 € flat fee; set `late_penalty_rate` to the legal rate of the semester).

## Returns and listings

`BeVatReturnForm` fills grids 01–03, 44–49, 54–64, 71–72, 81–88 and 91 from the core VAT return; credit notes go to their own grids. Purchases split by account: 60 → 81, class 2 → 83, the rest → 82. Reverse-charged services all land in 88: move those bought outside the EU to 87 by hand for now.

`BeClientListingProvider` gives the annual listing (Belgian VAT-registered customers from 250 €), `BeIntraEuListingProvider` the intra-Community listing by VAT number and code (L, S).

## Bank files

`coda` reads CODA v2: movements of detail 0000 only (globalised details are not counted twice), structured communications, counterparty name and IBAN, the new balance.
