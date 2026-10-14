# Agentenblick — acceptation proposée du lot 1a · 14 octobre 2026

**Livré pour validation ; lot 1b non commencé.** Plugin `AithosAgentenblick` 0.1.0,
MIT, [PR #1](https://github.com/Math1987/agentenblick/pull/1). Banc neuf
`dockware/shopware:6.7.15.0`, PHP-FPM 8.2.34, MySQL 8.0.46, Symfony 7.4.20,
Node 24.21.0 ; données de démo, AithosAgentTrust absent. Le nettoyage du Mac a
permis de reprendre ; les fichiers incomplets du banc ont été restaurés depuis Git.

| Commande / contrôle | Résultat vérifié |
|---|---|
| `python3 scripts/setup-bench.py` | Préparation reproductible, migrations et 3 tâches enregistrées. Sonde réelle : terminaison de **2 001 ms**, client médian **17,6 ms** en production sans debug. Debug actif échoue comme prévu ; sonde désactivée ensuite. |
| `bash scripts/check.sh` dans le plugin | **61 tests, 179 assertions** ; PHPStan **niveau 8 : zéro erreur** ; php-cs-fixer : zéro correction ; lint PHP et scripts réussi. |
| `AGENTENBLICK_DB_TEST=1 …/phpunit -c phpunit.xml tests/Integration` | **8 tests, 192 assertions** : concurrence, ordre de terminaison, preuve des sessions, purge, clé quotidienne, reprise sans double comptage. |
| `python3 scripts/acceptance.py` | Kit initial : **11 requêtes, 9 sessions** ; 2 signatures vérifiées, 1 IP vérifiée, 7 déclarations non vérifiées, 1 requête humaine sans ligne détaillée. Canaux résolus aussi sur cache hit. |
| Proxy et CDN | IP locale autorisée vérifiée ; IP hors plage et en-têtes forgés non vérifiés. Avertissement CLI sur majorité CDN, disparition dans une nouvelle fenêtre conforme. Catalogue de test refusé hors `test`. |
| Annuaire indisponible / minimisation | Une signature sûre revérifiée après reprise, compteurs inchangés ; URL signée avec query string laissée non vérifiée. **0 marqueur sensible dans 36 colonnes textuelles**. |
| Pannes et concurrence HTTP | Stockage du plugin indisponible et catalogue corrompu : HTTP 200, même corps de 76 558 octets et même SHA-256. **30 requêtes concurrentes conservées, 1 session**. |
| `python3 scripts/verify-offline.py` | **0 paquet sortant** DNS/HTTP(S), 0 appel à l’annuaire local sur 12 requêtes et commandes ; contrôle positif : 24 paquets. |
| `python3 scripts/measure-overhead.py` | 4 phases off/on, **200 mesures** après chauffe. Médiane **89,67 → 90,96 ms**, soit **+1,29 ms / +1,43 %** ; p95 124,37 → 120,82 ms. Tous les corps et statuts identiques. |
| Plages et CLI | 10 listes officielles rafraîchies ; URLs canoniques corrigées sans autoriser les redirections. Schémas JSON catalogue/rapport valides ; `verify-request` restitue `verified_ip` pour la fixture locale. |

**Captures et preuves :** [rapport CLI](evidence/2026-10-14-report.txt),
[rapport JSON](evidence/2026-10-14-report.json),
[acceptation HTTP/SQL](evidence/2026-10-14-acceptance.json),
[qualité](evidence/2026-10-14-checks.log),
[surcoût](evidence/2026-10-14-overhead.json),
[réseau](evidence/2026-10-14-offline.json). Le banc est remis au kit initial
de 11 requêtes pour `agentenblick:report --days=1`.

**Limites de cette validation :** Shopware 6.7 testé ; 6.6 reste à qualifier avant
publication. Mesure locale séquentielle, pas un test de capacité ; la variation
du p95 ne prouve pas un gain. Les caches externes restent invisibles ; certaines
routes du cache interne restent génériques. La priorité à la minimisation exclut
les reprises nécessitant URL privée, query string ou nonce opaque. L’admin et sa
validation des changements IP >30 % appartiennent au lot 1b, après accord explicite.
