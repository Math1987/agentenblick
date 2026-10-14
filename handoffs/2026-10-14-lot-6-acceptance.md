# Lot 6 — Qualification de la démo, 14 octobre 2026

**Périmètre : préversion 0.1.0 et démo sur le compte de Mathieu ; publication Store différée.**
Shopware 6.6.10.29 et 6.7.15.0, PHP-FPM 8.2.34, MySQL 8.0.46, debug désactivé.
Les bases de qualification sont isolées du banc de démonstration et de tout marchand.

| Contrôle | Commande / preuve | Résultat |
|---|---|---|
| Cycle 6.6 | `scripts/acceptance-compat-sw66.py --release=5ce57bb --zip=… --sha256=… --reset-baseline` | Migration 6→10 tables, observations conservées, migrations idempotentes ; 8 API : 401 anonyme / 200 admin ; 154 checksums xxh128 conformes |
| Cycle 6.7 | `python3 scripts/acceptance-lifecycle-sw67.py run --zip=releases/AithosAgentenblick-0.1.0.zip` | Mêmes étapes ; désactivation sans capture ; réinstallation 0.1.0 : 161 fichiers identiques au ZIP |
| Désinstallation | Avec puis sans conservation, sur les deux versions | Données et compteurs conservés avec l’option ; sinon 0 table, 0 réglage et 0 fichier d’erreurs propre au plugin ; fichier tiers préservé |
| Tests et CI | PHPUnit, PHPStan niveau 8, PHP CS Fixer, Node/Vue | 191 tests unitaires / 713 assertions ; 64 intégrations / 1 167 assertions ; 62 tests JS ; matrices 6.6/6.7 et administration vertes |
| Interface | Contrôles navigateur réels | Vue d’ensemble et Santé en 6.6 ; constats/autotests en 6.7 à 456 px ; détection expérimentale désactivée |
| Service déployé | Workflow benchmark `38562379595`, commit `9faf6b4` | HTTPS/JS/santé 200 ; démo sans authentification 401 ; origine API directe 403 ; aucune cellule réelle publiée |

Les cycles utilisent le candidat `8880f61c…8475a5`. La preuve du paquet publié
rapproche son contenu exécutable de ce candidat ; seules les finitions de
documentation et de métadonnées sont autorisées à différer.

**Performance : qualification incomplète.** `python3 scripts/measure-phases.py`
a mesuré 1 000 requêtes actives et 1 000 inactives, toutes 200 avec un contenu
identique, avant l’arrêt automatique pour manque d’espace disque. Sur cette
série partielle, le p95 des méthodes instrumentées vaut 0,230 ms avant réponse
et 31,937 ms après réponse, au-dessus de l’objectif de 5 ms. Le premier chiffre
exclut la construction des services et ne valide donc pas le budget complet de
0,5 ms. Quatre sessions synthétiques réchauffées, concurrence 1 et clés en cache
ne représentent pas une charge marchande. La pression mémoire/disque interdit
une attribution fiable du ralentissement. Sources, catalogue, cache de clés et
activation du plugin ont été restaurés et vérifiés. La démo est livrable ; la
qualification production reste ouverte.

**Limites conservées.** Les preuves de mode hors ligne et de minimisation sont
dans les rapports des lots 1, 2 et 5. La sonde Santé HTTP du lot 5 utilise un
transport de fixture et ne valide pas le DNS/TLS public d’un marchand.
L’expérience de détection retrouve 11/13 scripts ; aucun taux de faux positifs
humains n’est établi. Elle reste désactivée, conformément au choix de démo.
Le contrôle Store, le retrait distant encore en attente lors d’une désinstallation,
le compte éditeur et les revues de marque/confidentialité restent à traiter avant
soumission. Aucun agrément Store ni déploiement sur une boutique marchande n’est annoncé.

Preuves : [cycle 6.6](evidence/lot-6-backend-sw66.json),
[cycle 6.7](evidence/lot-6-lifecycle-sw67.json),
[interface 6.6](evidence/lot-6-ui-sw66.jpg),
[Santé 6.7](evidence/lot-5-health-findings.jpg),
[service](evidence/lot-6-cloud-smoke.json),
[démo](evidence/lot-6-cloud-demo.jpg),
[performance partielle](evidence/lot-6-performance-partial-low-disk.json),
[paquet](evidence/lot-6-release.json),
[CI du code exécuté](https://github.com/Math1987/agentenblick/actions/runs/38574285992).
