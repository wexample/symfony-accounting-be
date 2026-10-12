# TVA BE : grille 87, services achetés hors UE

Opened: 2026-10-12
Author: agent:main

`BeVatReturnForm` met tous les services autoliquidés en grille 88 (UE). Ceux achetés hors UE vont en 87, et doivent aujourd'hui être déplacés à la main.

## À faire

- Distinguer UE et hors UE à partir du pays du fournisseur, soit `Party` → `Country` → l'appartenance à l'UE. Le garder sur la ligne de TVA, ou le relire au calcul.
- Grille 87 pour les services hors UE, 88 pour l'UE. Les grilles 55 et 56 suivent.
- Test : un fournisseur suisse et un fournisseur néerlandais dans la même période.

## Fait (2026-10-12)

`VatKind::ImportedServices` (services achetés hors UE) est distinct de `ReverseCharge` (UE). Grille 87 et 56 pour ces services, 88 et 55 pour l'UE. Test : `BeAccountingTest::testSelfAssessedServicesAndTheCocontractantRegime`.
