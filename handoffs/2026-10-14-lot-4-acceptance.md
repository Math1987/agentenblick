# Lot 4 — Heuristiques expérimentales, 14 octobre 2026

**Statut : livré expérimental désactivé ; critères quantitatifs du lot 4 non validés.**
Shopware 6.7.15.0, PHP 8.2.34, MySQL 8.0.46, PHPUnit 11.5.56.
Banc isolé `agentenblick-shop`, sans ancien plugin. Score v1, seuil 60/100.

| Contrôle | Commande / preuve | Résultat vérifié |
|---|---|---|
| Évaluation après gel des poids | `python3 scripts/acceptance-heuristics.py`, 12:22:19–12:23:38 UTC | 146 requêtes HTTP 200, exactement 17 sessions ; empreinte du score local et déployé identique au gel |
| Détection des scripts | 13 profils distincts, dont deux avec UA navigateur générique | 11 vrais positifs, 2 faux négatifs : **84,6 %**, objectif 90 % non atteint |
| Références synthétiques | Chrome, Firefox, Safari et navigateur privé simulés | 0 faux positif sur 4 ; aucune session humaine réelle de référence, objectif humain <2 % **non validé** |
| Priorité et minimisation | Tests unitaires et MySQL | Agents déclarés/vérifiés prioritaires ; uniquement faits booléens et compteurs bornés ; promotion sous verrou ; historique humain antérieur non reconstruit ; cadence saturée corrigée et testée |
| Vérifications PHP/UI | PHPUnit, PHPStan niveau 8, PHP CS Fixer, tests administration | 173 tests unitaires / 633 assertions ; 46 tests MySQL / 693 assertions ; 32 tests UI ; PHPStan et formatage sans erreur |
| Navigateur réel, expérimentation OFF | Pilotage CUA ; `python3 scripts/collect-browser-demo.py` | Recherche, produit à 24 €, panier, invité et confirmation checkout observés ; 41 requêtes / 4 sessions, routes SEO correctes après correction ; aucune commande |
| Confidentialité et restauration | Collecteur puis `python3 scripts/setup-browser-demo.py cleanup` | Aucun email, adresse de test ou IP brute retrouvé dans les colonnes analytiques ; 2 produits et 1 invité supprimés ; configuration restaurée ; heuristiques **OFF** après les deux scénarios |

Les deux faux négatifs sont les scripts `generic-browser-fast` et
`generic-browser-polite`. Le premier présentait des en-têtes navigateur cohérents
et 14 requêtes espacées de 128–229 ms : les deux signaux de cadence et l’absence
de référent ne suffisent pas à atteindre 60. Le second utilisait des intervalles
variés de 688–2 177 ms et un référent. Aucun cookie de session n’a été offert dans
ces profils HTTP : le signal « cookie ignoré » reste donc neutre. Son déclenchement
conditionnel est couvert par les tests PHP, sans prétendre l’avoir observé ici.
**Aucun poids n’a été ajusté après la mesure.**

Le signal réseau embarque seulement 80 plages AWS EC2 `eu-west-3`, issues du
[catalogue officiel AWS](https://docs.aws.amazon.com/vpc/latest/userguide/aws-ip-ranges.html)
du 14 octobre. C’est une couverture partielle de réseaux d’hébergement, pas une
identification générale des ASN ou VPN ; ce signal vaut 5 points et reste neutre
pour une IP de CDN. Aucun appel réseau n’intervient pendant la capture.

Dans le navigateur, le refus des conditions générales a été bloqué en JavaScript
avant l’envoi : l’URL modifiée par `history.pushState` ne prouve pas une requête.
Aucun échec formulaire serveur n’est donc revendiqué ; paiement externe inconnu.
Les poids gelés et ce petit jeu synthétique ne constituent pas un calibrage humain.

Preuves : [mesure et matrice](evidence/lot-4-heuristics.json),
[parcours navigateur minimisé](evidence/lot-4-browser.json),
[checkout](evidence/lot-4-browser-checkout.jpg), [mode désactivé](evidence/lot-4-disabled.jpg).
