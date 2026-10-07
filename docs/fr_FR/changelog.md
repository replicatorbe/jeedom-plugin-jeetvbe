# Changelog Jeedom TV

## 1.1 — 07/10/2026

- **Toutes les TV** : équipement de diffusion créé par le plugin (sans clé,
  sans pages), avec Message, Notifier (JSON), Retirer une notification,
  Indicateur (JSON) et Retirer un indicateur. Notifications et retraits vers
  les TV en ligne et écran allumé ; indicateurs temporaires vers toutes les TV.
  Sources vidéo résolues par TV (sans la source : notification sans vidéo),
  images copiées par TV, nombre de TV atteintes au journal.
- Option par TV **Recevoir les diffusions** (cochée par défaut).
- Pas de Question à toutes les TV (elle ne pourrait pas être retirée des
  autres TV après la première réponse).

## 1.0 — 07/10/2026

Jeedom TV remplace TvOverlay.

- **Barre d'état** par-dessus toutes les applications : heure et indicateurs,
  coin, horloge, opacité réglés par TV, servie dans `layout.status` et
  `changes.status` (état complet, seulement quand elle change ; hors
  révision). Indicateurs automatiques au modèle `auto_fixed` de TvOverlay
  (conditions OU/ET, texte fixe ou issu d'une commande avec arrondi et
  suffixe, icône fixe ou issue d'une commande avec repli, couleurs, forme),
  calculés par le plugin : listener sur les commandes citées, empreinte pour
  n'envoyer qu'un vrai changement, verrou par TV, recalcul de sûreté au cron.
  Importation depuis un équipement TvOverlay (bouton, ou
  `jeetvbe::importTvOverlayIndicators()`), sans rien y modifier.
- **Indicateur (JSON)** (format TvOverlay) : indicateurs temporaires gardés
  par TV, expiration en secondes, durée `1y2w3d4h5m6s` ou date epoch,
  `visible:false` ; **Retirer un indicateur** (un indicateur automatique
  reste retiré tant que son contenu ne change pas).
- **Notifications riches** : **Notifier (JSON)** au format TvOverlay
  (message en texte ou en objet, `#id#` remplacés) → `notify` avec `tag`,
  `icon`/`iconColor` (mdi), `corner`, `duration`, `image` (chemin, adresse du
  réseau local téléchargée, ou base64, copiée comme les autres images) et
  `video` ; **Retirer une notification** → ordre `dismiss`.
- **Sources vidéo** nommées par TV, adresses jamais affichées ni journalisées
  en clair ; `[video=<nom>]` dans Message et Question, `video` dans le JSON ;
  `ask.video`.
- L'`id` d'un ordre est toujours l'entier croissant de la file, même si
  l'ordre fourni en portait un.

## 0.9.1 — 07/10/2026

Revue de code : corrections, sans changement du contrat.

- **Ordres** : seule la requête `changes` la plus récente d'une TV prend les
  ordres. Une attente abandonnée par la TV (boucle relancée, coupure réseau)
  pouvait prendre l'ordre suivant et l'écrire dans une connexion fermée.
- **TV dupliquée** : la copie reçoit sa propre clé (« Dupliquer » recopiait la
  clé, et deux TV la partageaient). La clé d'une TV en service ne change pas.
- **TV supprimée** : sa file d'ordres, sa question en attente, son compteur
  d'ordres et ses images sont supprimés avec elle.
- **Commande `Afficher Scénarios`** : gardée tant qu'un groupe est réglé, même
  si aucun scénario du groupe n'est actif lors d'un enregistrement (elle était
  supprimée, puis recréée avec un autre id). Noms des commandes `Afficher`
  comparés sans casse ni accents, comme le fait la base (« Écran » et
  « Ecran » ne se gênent plus) ; une commande supprimée avec sa page mais
  encore utilisée est signalée au journal.
- **Images** : la purge n'efface plus une image en cours de copie (fichier
  sans description depuis moins de 60 s).
- **API** : corps JSON de 64 Ko au plus, objet JSON exigé pour `exec`,
  `state` et `answer` ; une erreur PHP ou SQL pendant `exec` n'est plus
  détaillée à la TV (journal seulement) ; le journal ne cite plus le début
  d'une clé refusée ; `X-Content-Type-Options: nosniff` sur les images.
- **Éditeur** : tuile sans commande signalée ; changer le type d'une tuile
  retire les commandes des rôles qu'il n'a pas ; listes des touches de couleur
  à jour après renommage d'une page ; noms d'objets échappés.
- **Mise à jour du plugin** : une TV qui refuse l'enregistrement n'empêche plus
  la mise à jour des autres.

## 0.9 — 07/10/2026

- **Tuile `select` (liste de choix)** : une commande action de type liste
  (rôle Régler) et son état facultatif. Les choix (`choices`) viennent de la
  liste de valeurs de la commande (`valeur|Libellé;…`), relue à chaque
  chargement et comprise dans la révision. `exec set` avec une valeur absente
  de la liste : 422 ; sinon la commande reçoit `select`.
- **Génération** : `THERMOSTAT_SET_MODE` donne une tuile `select`
  « <nom> · Mode » (état `THERMOSTAT_MODE`), page « Chauffage et clim ».
- **Durée des messages** : `[durée=<s>]` dans le titre ou le message de la
  commande `Message` (3 à 120 s, retiré du texte) donne `duration` à l'ordre
  `notify` ; combinable avec `[image=…]`. `Question` retire le marqueur sans
  en tenir compte.

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
