# Lot 1b — Administration native, 14 octobre 2026

**Résultat : accepté par les contrôles d'exécution autorisés.** Shopware 6.7.15.0,
PHP 8.2.34, MySQL 8.0.46, PHPUnit 11.5.56, Shopware CLI 0.18.5.

| Contrôle | Commande / preuve | Résultat |
|---|---|---|
| Code PHP | `scripts/check.sh` dans le banc | 79 tests, 238 assertions ; PHPStan niveau 8 et formatage sans erreur |
| Base et ACL | `AGENTENBLICK_DB_TEST=1 …phpunit … tests/Integration` + tests unitaires ACL natifs | 12 tests, 237 assertions ; lecteur autorisé, écriture réservée à l'éditeur, approbation versionnée |
| Compilation admin | `shopware-cli extension build . --no-interaction` | esbuild autonome, manifeste Vite et ressources héritées générés |
| API réelle / CLI / CSV | `python3 scripts/acceptance-admin.py` | 11 requêtes, 9 sessions, 8 sessions d'agents ; 4 agents / 4 lignes CSV ; mêmes totaux CLI |
| Accès et cache | Même script HTTP | Anonyme 401, filtre invalide 400, réponses `no-store` |
| Interface réelle | Navigateur du banc, largeurs 456 et 1280 px | Vue d'ensemble, agents, preuves, filtre 7/30 jours ; captures ci-dessous |

Les agrégats et le diagnostic proxy sont communs à la CLI et à l'admin. Le CSV
neutralise les formules et n'expose aucun détail humain. Une approbation de plages
devenue obsolète est rejetée ; mise à jour et approbation utilisent un verrou SQL.

Adaptations du banc : secret de démo renforcé, entités internes de fixtures
Shopware exclues du schéma admin en environnement `test`, ressources installées
en mode `prod` pour éviter le système de fichiers en mémoire des tests. Aucun
de ces ajustements n'est nécessaire dans le plugin distribué.

Preuves : [chiffres HTTP](evidence/lot-1b-admin.json),
[vue d'ensemble](evidence/lot-1b-overview.jpg), [agents](evidence/lot-1b-agents.jpg).
Les mesures de performance finales et la matrice Shopware 6.6 restent au lot 6.
