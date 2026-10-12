# Comptes annuels BNB (XBRL)

Opened: 2026-10-12
Author: agent:main

Les sociétés belges déposent leurs comptes annuels à la Centrale des bilans de la BNB, au format XBRL : modèle abrégé, complet ou micro selon la taille.

## À faire

- Les rubriques du modèle (bilan, compte de résultat, annexes chiffrées) tirées de la balance PCMN, par une `ReportDefinition` par modèle.
- Générer le XBRL selon la taxonomie de la BNB (millésime à suivre), et le valider avec l'outil de la BNB.
- Les annexes non chiffrées (identité, administrateurs, bilan social) : à saisir ou à reprendre du dossier.
- Le dépôt reste manuel, sur le portail de la BNB.

## Fait (2026-10-12)

- `NbbXbrlWriter` produit l'instance XBRL du modèle micro (m07) ou abrégé (m01), société à capital, taxonomie BNB 26.0.15. Elle contient :
  - l'identification ;
  - le bilan, le compte de résultat et l'affectation ;
  - l'annexe des immobilisations.
- `NbbAnnualAccountsBuilder` calcule les rubriques (définition `nbb_annual_accounts`, formules de la BNB). `bin/nbb-datapoints.py` génère les points de données depuis l'archive de la taxonomie.
- Validé avec Arelle contre le paquet officiel de la taxonomie et ses contrôles :
  - 1re année : 73 assertions satisfaites, aucune non satisfaite, en modèle micro comme en abrégé ;
  - 2e année (colonne de l'exercice précédent, valeurs d'ouverture des annexes) : 76 satisfaites. La seule non satisfaite (« date de début dans le futur ») vient des dates de test.
- Au passage :
  - le cœur accepte « / » dans les clés de formules d'état ;
  - `sumByAccount()` sait séparer les à-nouveaux.

Pas fait :
- les administrateurs (s.02) ;
- les autres annexes ;
- le bilan social ;
- le modèle complet ;
- les sociétés sans capital ;
- le dépôt : il passe par l'application de la BNB.
