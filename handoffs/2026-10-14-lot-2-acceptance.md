# Lot 2 — Benchmark facultatif, 14 octobre 2026

**Résultat : accepté par les contrôles d'exécution autorisés.** Shopware 6.7.15.0,
PHP 8.2.34, MySQL 8.0.46, PHPUnit 11.5.56, Shopware CLI 0.18.5.
Service HTTPS en production : https://shopware.aithos.app ; démonstration sur le
compte de Mathieu, accès de Juan différé selon sa demande.

| Contrôle | Commande / preuve | Résultat |
|---|---|---|
| Code PHP | PHPUnit, PHPStan niveau 8, PHP CS Fixer | 112 tests unitaires / 366 assertions ; 23 tests MySQL / 322 assertions ; analyse et formatage valides |
| Administration | `shopware-cli extension build .`, `npm test --prefix src/Resources/app/administration` | Build réussi ; 10 tests de garde-fous UI |
| Confidentialité avant consentement | `python3 scripts/acceptance-benchmark.py` | 0 paquet sortant pour aperçu/actions désactivées ; contrôle positif : 14 paquets |
| Seuil local et aperçu exact | Même script, 3 canaux synthétiques | Dénominateurs 9/10 vérifiés ; mêmes octets et SHA256 dans CLI, API, historique et envoi |
| Envoi réel HTTPS | Client PHP natif → service AWS | Consentement de-DE v1 requis ; 3 documents acceptés ; retry identique sans doublon |
| Retrait et restauration | Même script | Arrêt local préalable, DELETE confirmé 204 ; données du banc restaurées et installations de test retirées |
| Pannes et accès | Tests MySQL + ACL natives | Offline géré prioritaire, verrou concurrent, refus permanent non bloquant, reprise 13 semaines, factory de désinstallation ; aucune clé en réponse admin |
| Interface réelle | Navigateur, 456 et 1280 px | Démo explicitement signalée ; consentement non coché ; activation bloquée puis déverrouillée par la case ; aperçu local, journal, comparaison explicite |

L'identifiant d'installation et ceux des canaux sont aléatoires ; le secret reste
chiffré localement. Le schéma n'accepte aucune donnée par visiteur. Un retrait
en attente conserve la clé nécessaire à sa reprise. Une identité réelle copiée
dans le banc de test ne peut ni envoyer ni supprimer les données de production.

Le service a passé ses 47 tests et sa validation HTTPS réelle (seuil k5, retrait
5→4 immédiatement masqué, concurrence, HMAC et refus des champs interdits).
Le workflow GitHub OIDC a déployé avec succès. Le rapport et les preuves du
service sont dans le dépôt privé `agentenblick-benchmark/handoffs`.

Preuves : [client natif](evidence/lot-2-native-benchmark.json),
[consentement natif](evidence/lot-2-native-consent.jpg).
