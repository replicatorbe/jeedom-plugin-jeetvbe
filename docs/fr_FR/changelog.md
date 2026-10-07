# Changelog Jeedom TV

## 0.8 — 07/10/2026

- **Bandeau d'infos** : jusqu'à 6 infos par TV (commande info, libellé de
  24 caractères au plus, icône), réglées dans l'onglet TV, réordonnables.
  Servies dans `header` du layout (valeur et unité comme une tuile `info`,
  commande disparue retirée), mises à jour par `changes` (`{"tile": "h1", …}`),
  comprises dans la révision. Ids `h<n>` stables, jamais réattribués ;
  `exec` sur un élément du bandeau : 404.
- **Icônes `sun`, `rain`, `trash`, `power`**, pour les tuiles et le bandeau.

## 0.7 — 07/10/2026

- **Tuile `button`** : exécute une commande action choisie (rôle Commande),
  avec des options fixes enregistrées sur la tuile (titre, message, valeur,
  choix, couleur) ; seules celles du sous-type de la commande sont passées.
  État facultatif ; la TV ne reçoit ni les options ni les commandes, et ne peut
  demander que `press` (toute autre action : 422). Premier usage : une page
  « Caméras » sur les commandes « Afficher <caméra> » de CameraOnTv.
- **Icône `camera`**.
- **Touches de couleur** : une page par touche (rouge, vert, jaune, bleu),
  réglée dans l'onglet TV et servie dans `keys` du layout (pages existantes
  seulement ; la révision en tient compte). Côté TV, la touche agit par-dessus
  n'importe quelle application, une fois le service d'accessibilité activé.

## 0.6 — 07/10/2026

- **Images jointes** aux commandes `Message` et `Question` : `[image=<chemin>]`
  dans le titre ou le message, l'option `files` (convention de l'action
  Rapport) ou un titre `title=… | files=…`. L'image est copiée au moment de
  l'ordre et servie à la seule TV concernée par `GET ?action=image`, pendant
  la durée de l'ordre (5 minutes au moins). JPEG ou PNG de 5 Mo au plus, situés
  sous la racine de Jeedom ou son dossier temporaire ; sinon l'ordre part sans
  image et la raison va au journal du plugin.
- Premier usage : la photo du portier dans la question de la sonnette.

## 0.5 — 07/10/2026

- **Page « Scénarios » automatique** : option « Groupe de scénarios » de la
  TV. Une tuile par scénario actif du groupe (id `s<id>`), triée par nom, à la
  fin des pages ; la page suit le groupe sans réenregistrer (la révision
  change) ; confirmation si la description contient `[confirmer]` ou si le nom
  est sensible ; commande `Afficher Scénarios`.
- **Lumières variables** : à la génération, un curseur « <nom> (luminosité) »
  en plus de l'interrupteur pour les lumières qui ont `LIGHT_SLIDER` et une
  info de luminosité.
- **Version app** : nouvelle info remplie par `appVersion` de `POST state` ;
  l'onglet TV affiche la version et le dernier appel de la TV.
- Prêt pour le Market : workflow de contrôle Jeedom, traduction anglaise de
  la page de configuration, documentation anglaise résumée.

## 0.4 — 07/10/2026

Questions de Jeedom à la TV, branchées sur le bloc « Demander » des scénarios
(contrat `docs/api.md`, sections « Questions de Jeedom » et « POST ?action=answer »).

- Nouvelle commande `Question` (action / message) sur chaque TV. Exécutée par
  un bloc Demander, elle envoie à la TV un ordre `ask` (jeton, message,
  réponses, délai) ; hors bloc Demander, elle se comporte comme `Message`.
- Nouvelle action `POST answer` : la TV renvoie le jeton et la réponse
  choisie ; le plugin la transmet au cœur, et le scénario reprend avec elle.
  Jeton d'une autre question ou d'une autre TV, question expirée ou déjà
  répondue : 404. Réponse hors liste : 422.
- Une question non livrée est abandonnée à la fin de son délai (60 s au plus).

## 0.3 — 06/10/2026

Ordres de Jeedom vers la TV (contrat `docs/api.md`, section « Commandes Jeedom → TV »).

- Commandes de l'équipement, créées et tenues à jour à chaque enregistrement :
  `Afficher <page>` (une par page, logicalId `show_<id de page>`, renommée ou
  supprimée avec la page), `Afficher page`, `Message`, `Quitter`, et les infos
  `En ligne`, `Visible`, `Écran allumé`, `Page affichée`.
- Les ordres sont mis en file et livrés une seule fois dans `commands` de la
  réponse `changes`, qu'ils réveillent en moins d'une seconde ; un ordre non
  livré au bout de 60 s est abandonné.
- Nouvelle action `POST state` : la TV signale si elle est visible, si
  l'écran est allumé et la page affichée.
- `En ligne` passe à 1 à chaque appel de la TV, et à 0 après 60 s de silence.
- Réglage « Durée d'affichage par défaut » (30 s ; 0 = sans retour).
- Un id de page n'est plus jamais réattribué à une autre page : une commande
  `Afficher <page>` utilisée dans un scénario ne désigne jamais une autre page.

## 0.2 — 06/10/2026

- Génération **par type** (nouveau mode par défaut) : pages Lumières, Volets,
  Chauffage et clim, Températures, Prises, Scénarios, dans cet ordre et sans
  page vide ; tuiles nommées « Pièce · Nom » sans répéter la pièce, rangées
  par pièce (ordre des objets de Jeedom) puis par nom. Le mode **par pièce**
  reste disponible.
- Les ids de tuile survivent à une régénération : une tuile inchangée garde
  son id, une nouvelle reçoit un numéro jamais servi. Un exec envoyé par une
  TV au layout périmé ne peut plus viser un autre équipement.
- Les valeurs des tuiles sont lues dans le cache des commandes info.

## 0.1 — 06/10/2026

Première version (MVP), contrat d'API schéma 1 (`docs/api.md`).

- Un équipement par TV, avec sa clé de 32 caractères hexadécimaux, régénérable.
- API `ping`, `layout`, `exec` et `changes` (attente longue de 25 s, sans
  session retenue).
- Éditeur de pages et de tuiles : ajout, suppression, réordonnancement ; rôles
  de commande et scénario choisis avec les sélecteurs de Jeedom.
- Génération de pages par pièce depuis les types génériques (lumières, prises,
  volets, consignes de thermostat, températures), avec confirmation cochée
  d'office pour les équipements sensibles.
