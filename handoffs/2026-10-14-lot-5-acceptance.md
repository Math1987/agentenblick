# Lot 5 — Santé et découvrabilité, 14 octobre 2026

**Lot validé sur le banc isolé ; interface contrôlée et fixtures retirées.**
Shopware 6.7.15.0, PHP 8.2.34, MySQL 8.0.46, backend Health `761416c`, interface `fe5f718`.
Fixture isolée dans `agentenblick-shop`.

| Contrôle | Commande / preuve | Résultat vérifié |
|---|---|---|
| Sondes HTTP explicites | `python3 scripts/acceptance-health-http.py --keep-for-browser`, 12:56:11–12:56:35 UTC | 8 ressources et 3 autotests, soit 11 vrais échanges HTTP ; aucune requête ni session analytique créée par les sondes |
| Ressources et limites | Snapshot API authentifié | robots parsé, llms disponible, agents.md absent (404), UCP en erreur (500) ; OPTIONS MCP 200 avec protocole explicitement non validé |
| Contradictions robots | 7 requêtes visiteurs, une session | 2 « autorisé mais bloqué » (403 et 429), 1 « interdit mais observé », 1 erreur UCP 500 et 1 erreur MCP GET 500 |
| Après llms.txt | Réponse llms 200 achevée puis requête de recherche | 1 session avec activité ultérieure ; seule l’étape recherche est comptée, aucune lecture ni causalité déduite |
| Exclusion à usage unique | Capacité authentique, rejeu, en-tête fixe arbitraire | Premier appel exclu ; rejeu et faux en-tête capturés |
| Minimisation | Contrôle SQL après les échanges | 44 colonnes texte/JSON contrôlées ; aucun email, terme de recherche, token de contexte, capacité brute ou faux en-tête de test retrouvé |
| Mode hors ligne | API de sonde, API de lecture, `agentenblick:health --domain=…` | Sonde refusée (503), lecture du snapshot conservée (200), CLI locale disponible ; aucun appel HTTP supplémentaire vers la fixture |
| Vérifications PHP | PHPUnit, PHPStan niveau 8, PHP CS Fixer | 185 tests unitaires / 697 assertions ; 57 tests MySQL / 973 assertions ; analyse statique et formatage sans erreur |
| Administration | 62 tests JavaScript, compilation Vue, contrôle visuel à 456 px | Contradictions, règles robots et autotests 403/429/200 lisibles sans débordement |
| Compatibilité 6.6 | `python3 scripts/acceptance-compat-sw66.py --final-health=fe5f718` | Shopware 6.6.10.29 : 10 tables, selftest/CLI valides, API Santé 401 anonyme / 200 admin, domaine HTTP local refusé (404) |

Le transport mappe l’origine fictive exacte vers HTTP loopback et PHP-FPM.
`PublicNetworkClient` reste inchangé ; le DNS et l’IP publics sont des données de
fixture. **Ce scénario ne valide pas le DNS ni TLS d’une boutique publique.** Les
protections SSRF et limites de réponse sont vérifiées séparément par les tests PHP.

Le listener CORS de Shopware répond à OPTIONS avant le routeur de fixture : son
200 ne prouve pas le fonctionnement de MCP. L’erreur MCP 500 ci-dessus provient
d’un GET réellement observé. Les autotests utilisent l’IP du serveur, pas celles des opérateurs. Google-Extended reste un jeton
de politique robots, jamais une identité.

Nettoyage vérifié : domaine/canal, observations propres, vhost et entrée hosts
retirés ; configuration restaurée et total des requêtes préexistantes inchangé.

Preuves : [HTTP Health](evidence/lot-5-health-http.json),
[Health 6.6](evidence/lot-6-health-sw66.json),
[capture des constats](evidence/lot-5-health-findings.jpg),
[capture des autotests](evidence/lot-5-health-autotest.jpg).
