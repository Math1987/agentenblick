# Démo Agentenblick — déroulé de cinq minutes

Le banc local contient du trafic de test. Le tableau de bord en ligne indique explicitement **Demonstration data** ; ses installations synthétiques n’entrent jamais dans les comparaisons réelles.

1. Ouvrir l’administration du banc sur `http://127.0.0.1:18080/admin`, puis **Marketing > AI agents**. Montrer la part observée et la séparation des preuves : signature, IP, DNS et identité déclarée non vérifiée. Un user-agent GPTBot seul ne prouve pas une identité.
2. Dans **Agents**, filtrer les opérateurs du kit et exporter les agrégats CSV. Dans **Funnel / Journeys / Failures**, montrer les étapes réellement observées, les rattachements provisoires et les erreurs. Une commande ne prouve pas un paiement.
3. Dans **Health**, expliquer les règles robots, les contradictions et les statuts d’endpoints. Les domaines HTTP locaux sont volontairement non éligibles aux sondes publiques ; les preuves des scénarios mis en scène figurent dans le rapport du lot 5. L’auto-test part de l’IP du serveur.
4. Dans **Benchmark**, montrer l’état désactivé et l’aperçu exact avant tout consentement. Le seuil local est de dix sessions par métrique ; la publication exige cinq installations éligibles. Ne pas activer la participation simplement pour présenter cet écran.
5. Ouvrir [shopware.aithos.app](https://demo.shopware.aithos.app) avec le compte administrateur autorisé. Montrer les cohortes avec/sans Agentic Commerce, les parcours, les cellules non publiées et la méthodologie. L’accès partenaire sera préparé si la démonstration suscite de l’intérêt.

La détection des agents non déclarés reste **expérimentale et désactivée**. Le test figé détecte 11 profils scriptés sur 13 ; les objectifs de 90 % et de moins de 2 % de faux positifs humains ne sont pas validés. La qualification des performances en production reste ouverte : la série partielle du lot 6 dépasse le budget de traitement après réponse et a été interrompue par la saturation du Mac. Aucun succès de publication au Shopware Store n’est annoncé.
