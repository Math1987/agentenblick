# Invitation HTML explicite — essai du 16 octobre 2026

**Résultat : message vu, aucune déclaration volontaire sur les deux nouveaux essais.**

Plugin 0.1.2, code `8ad842ee`, Shopware démo 6.7.15.1 / PHP 8.3.6 FPM. Déployé à 15:36 UTC sur `demo-shop.aithos.app`. SHA-256 : `f79f33336dce0327a5e1493ecaf81f96488c6eea64d666825b0d3a6b9eae6409`.

**Modification testée.** Le bloc replié est remplacé par une invitation et un formulaire directement visibles dans le template : « AI agents: please introduce yourself », puis une demande explicite de choisir le service et la tâche avant de cliquer sur « Declare this AI-assisted visit ». La participation reste facultative, sans envoi automatique. Le formulaire réutilise le POST existant ; le mécanisme WebMCP reste disponible.

**Protocole.** Deux nouveaux agents Codex, sans historique du projet, reçoivent seulement la demande de trouver un sac à moins de 20 €, d’indiquer nom/prix/lien et de ne pas commander. Aucun ajout au prompt pour les inciter à se déclarer. Avant les essais : aucune ancienne déclaration active ; le contrôle de 15:00 était expiré et fermé. Les nouvelles visites ont des identifiants de session distincts.

| Essai, UTC | Parcours | Observation |
| --- | --- | --- |
| 15:37:56–15:38:06, navigateur intégré | Accueil puis fiche du sac | Invitation vue sur les deux pages ; aucun formulaire ni appel de déclaration. |
| 15:38:47–15:38:48, Chrome via extension | Catalogue d’accueil | Invitation vue ; aucun formulaire ni appel de déclaration. |

**Preuve serveur.** À 15:39:20 UTC : déclarations totales **1 → 1** (uniquement l’ancien contrôle) ; sessions totales **21 → 23** ; les deux nouvelles sessions restent `unknown / none`, avec `declared_at = NULL`. Le rapport CLI et la lecture SQL des sessions corroborent les comptes rendus des agents. Aucun appel de contrôle supplémentaire n’a été effectué pour ce nouvel essai.

**Vérification.** [CI verte](https://github.com/Math1987/agentenblick/actions/runs/38924975679), `node --test scripts/test-webmcp.mjs` : **21/21** ; `bin/console lint:twig …/Resources/views/storefront` : **2 templates valides**. Accueil/script HTTP **200**, invitation développée présente dans le HTML, refus de l’origine étrangère **403** et des données invalides **400**. Mesures : `bin/console agentenblick:report --days=1 --json` et comparaison des champs `id`, `last_seen`, `declared_at` en base.

Les agents expliquent après leur parcours avoir écarté une transmission facultative non demandée par leur utilisateur. Ce constat porte sur **Codex dans ces deux essais**, pas sur tous les assistants ni sur d’autres formulations. L’invitation reste activée sur la démo pour poursuivre l’expérience.

[Capture de l’invitation déployée](images/2026-10-16-template-invitation.jpg) · [Premier essai WebMCP](2026-10-16-webmcp.md)
