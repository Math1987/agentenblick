# Lot 3 — Parcours et échecs, 14 octobre 2026

**Résultat : accepté par les contrôles d’exécution autorisés.**
Shopware 6.7.15.0, PHP 8.2.34, MySQL 8.0.46, PHPUnit 11.5.56.
Banc vierge `agentenblick-shop`, catalogue et proxy de test, sans ancien plugin.

| Contrôle | Commande / preuve | Résultat vérifié |
|---|---|---|
| Parcours réel | `python3 scripts/acceptance-journeys.py`, 11:38:55–11:39:17 UTC | 5 requêtes HTTP dans une session vérifiée par IP : recherche → produit épuisé → produit disponible → panier → commande |
| Échecs métier | Même script, événements Shopware réels | Recherche vide et rupture détectées ; UUID de commande Shopware effectivement lié à la session |
| Clôture et abandons | `agentenblick:journey close`, deux exécutions | 3 sessions propres au test vieillies de 31 min ; panier et checkout abandonnés ; aucune attribution d’abandon à la commande ; contribution inchangée au second passage |
| Minimisation | API authentifiée et contrôle SQL | 17 colonnes texte contrôlées, aucun terme de recherche, email ou token conservé ; API anonyme refusée (401) |
| Isolation | Nettoyage du même script | 2 produits, invité et commande synthétiques supprimés ; réglages restaurés ; emails désactivés pendant le test, aucun appel à un paiement externe |
| Vérifications PHP/UI | PHPUnit, PHPStan niveau 8, PHP CS Fixer, tests administration | 158 tests unitaires / 549 assertions, dont 33 de sécurité / 124 assertions ; 37 tests MySQL / 539 assertions ; 21 tests UI ; PHPStan et formatage sans erreur ; régression concurrente REPEATABLE READ sur deux connexions validée |
| Administration réelle | Vue entonnoir, chronologie et échecs | Entonnoir, chronologie de commande et échecs vérifiés visuellement ; captures conservées |
| Benchmark v2 | `python3 scripts/acceptance-benchmark.py` | 0 paquet avant consentement (contrôle positif : 18) ; consentement de-DE v2 ; 3 documents HTTPS acceptés, retry identique, seuils k10, SHA256 CLI/API/historique identiques, retrait 204 et restauration complète |

Les étapes absentes ne sont jamais déduites : la commande Store API ci-dessus
n’invente aucune visite préalable du checkout. Un cache sans événement métier
laisse son résultat inconnu. L’UUID commande seul est permis sur les requêtes
non humaines ; aucun client, adresse, corps ou montant individuel n’est conservé.
Le paiement externe reste **inconnu** : créer la commande ne prouve pas qu’un
paiement a été traité. Les formulaires transmis en interne avec réponse HTTP 200,
les erreurs et la reclassification après clôture sont couverts par les tests PHP ;
le formulaire HTML n’a pas encore été exercé par ce scénario Store API.

Le benchmark utilise le **schéma 2**, avec consentement v2 explicitement renouvelé
avant tout nouvel envoi ; les documents gelés restent identiques. Les parts
`verified`/`declared` excluent les résultats inconnus et gardent les seuils locaux
k10 et distants k5. Le service cloud v2 a été fusionné et déployé via la
[PR benchmark #3](https://github.com/Math1987/agentenblick-benchmark/pull/3).

Dashboard cloud v2 vérifié : nouvelles métriques publiées à k5 ; `declared.search_empty` supprimé sous le seuil. Le parcours complet par navigateur HTML reste réservé au kit final et n’est pas présenté ici comme validé.

Preuves sans identifiant de session ni de commande :
[parcours HTTP](evidence/lot-3-journeys.json), [benchmark natif v2](evidence/lot-3-benchmark.json),
[entonnoir](evidence/lot-3-funnel.jpg), [chronologie](evidence/lot-3-journey.jpg), [échecs](evidence/lot-3-failures.jpg),
[dashboard cloud](evidence/lot-3-dashboard.jpg).
