# Agentenblick — lot 1a, état au 14 octobre 2026

**Statut : en cours, non accepté.** Le disque système macOS est saturé (117 Mo
libres au diagnostic, puis erreur « no space left on device »). Docker ne répond
plus ; aucun nettoyage global ni redémarrage des autres services n'a été effectué.
Le code et les preuves disponibles sont conservés sur Math17. Le lot 1b n'a pas démarré.

| Vérification exécutée | Résultat |
|---|---|
| Banc neuf | `dockware/shopware:6.7.15.0`, PHP-FPM 8.2.34, Node 24.21.0, 16 produits de démonstration. AithosAgentTrust absent ; plugin exemple désactivé. |
| `python3 scripts/measure-terminate.py`, debug actif | Échec : délai de terminaison de 2 s, surcoût client médian de 1 991,8 ms. |
| Même mesure, `APP_ENV=prod`, `APP_DEBUG=0` | Réussite : délai de terminaison de 2 s, réponse client médiane de 15,7 ms. Mesures JSON dans `handoffs/evidence/`. |
| `php /var/www/html/vendor/bin/phpunit` dans le plugin | 11 tests, 44 assertions réussis : vecteurs de signature et minimisation. Résultat antérieur aux derniers ajouts. |
| `node scripts/agentenblick-traffic.mjs` dans le banc, via proxy local | 11 réponses HTTP 200 ; contenu identique entre profils. Rapport : 2 requêtes `verified_signature`, 1 `verified_ip`, 7 `declared_unverified`, 1 humaine. Faux GPTBot, IP hors plage, en-têtes forgés, signatures altérée et expirée non vérifiés. |
| `agentenblick:report --json` | Chaîne de capture et rapport opérationnelle ; visites du cache interne incluses via décoration du noyau externe. |

**Travail écrit mais pas entièrement validé :** migrations, collecte et stockage,
catalogue embarqué, Web Bot Auth, cache des clés, DNS inverse, rafraîchissement IP,
diagnostic proxy, purge, tâches planifiées et commandes CLI. Une correction
préserve les tokens réellement reçus avant leur modification par Shopware ; sa
revérification a été interrompue par la panne Docker/MySQL.

**Avant acceptation :** reprendre les essais sur disque disponible ; terminer la
revérification différée (arbitrage confidentialité demandé), le rattachement du
canal sur cache hit et les compteurs humains ; valider SQL, pannes, rétention,
mode hors ligne, performance, PHPStan 8 et formatage. Produire ensuite le rapport
d'acceptation d'une page. Les captures de CLI seront ajoutées après reprise ; ce
rapport d'avancement ne vaut pas acceptation.
