# Jeedom TV (`jeetvbe`)

Le plugin expose à l'application Android TV **Jeedom TV** des pages de tuiles
(lumières, prises, volets, consignes, températures, scénarios) qu'on pilote à
la télécommande.

**Un équipement = une TV.** Chaque TV a sa propre clé et ses propres pages. La
TV ne connaît aucun identifiant de commande Jeedom : elle désigne une tuile, et
le plugin vérifie que la tuile lui appartient avant d'en déduire la commande.
Une clé volée ne pilote donc que ce qui est affiché sur cette TV.

## Mise en route

1. *Plugins → Multimédia → Jeedom TV*, **Ajouter une TV**, donner un nom
   (« TV salon »), **Sauvegarder** : la clé est générée à l'enregistrement.
2. Onglet **Pages et tuiles** : **Générer depuis les types génériques**, cocher
   les pièces voulues, **Générer les pages**, relire, **Sauvegarder**.
3. Dans l'application, saisir l'**URL de l'API** et la **clé** affichées dans
   le cadre « Ce qu'il faut donner à la TV ».

## État de la TV

L'onglet TV affiche la **version de l'application** signalée par la TV et
l'heure de son **dernier appel**, avec l'indication en ligne ou hors ligne
(hors ligne après 60 s sans nouvelle).

## La clé

32 caractères hexadécimaux, propre à la TV. **Régénérer** l'enregistre
aussitôt : la TV qui utilisait l'ancienne est refusée (HTTP 401) jusqu'à ce
qu'on lui donne la nouvelle. Une TV **désactivée** est refusée de la même façon.
Une TV créée par **Dupliquer** reçoit sa propre clé : deux TV ne partagent
jamais une clé.

## Pages et tuiles

Chaque page a un nom ; chaque tuile a :

| Champ | Rôle |
|---|---|
| Nom | Affiché sur la tuile. |
| Type | `switch` (interrupteur), `shutter` (volet), `slider` (curseur), `info`, `scene` (scénario), `button` (bouton), `select` (liste de choix). |
| Icône | `light`, `plug`, `shutter`, `thermostat`, `temperature`, `scene`, `fan`, `lock`, `alarm`, `camera`, `sun`, `rain`, `trash`, `power`, `generic`. |
| Confirmation | La TV demande confirmation avant toute action. |
| Commandes | Des rôles, chacun choisi avec le sélecteur de commande de Jeedom. |
| Scénario | Pour une tuile `scene`, choisi avec le sélecteur de scénario. |
| Min, max, pas | Pour `shutter` (position) et `slider`. |
| Options | Pour un `button` : titre, message, valeur… passés à sa commande. |

Rôles utiles par type :

| Type | Rôles | Actions de la TV |
|---|---|---|
| `switch` | État, On, Off, Bascule | `on`, `off`, `toggle` |
| `shutter` | État, Monter, Descendre, Stop, Régler | `up`, `down`, `stop`, `set` |
| `slider` | État, Régler | `set` |
| `info` | État | aucune |
| `scene` | (scénario) | `run` |
| `button` | Commande, État (facultatif) | `press` |
| `select` | Régler (action liste), État (facultatif) | `set` (une valeur de la liste) |

- **État** est la commande info dont la valeur s'affiche (et se met à jour en
  direct sur la TV).
- `toggle` sans commande Bascule : le plugin choisit On ou Off d'après l'état.
- Un volet n'a de **position** (min/max, action `set`) que s'il a une commande
  **Régler**. Sans elle (volets rfxcom, par exemple), la tuile n'a que
  Monter/Descendre/Stop, et sans commande État sa valeur est vide.
- Bornes vides : celles de la commande Régler, sinon 0–100 (pas de 10 pour un
  volet, de 1 pour un curseur). La valeur envoyée par la TV est ramenée dans
  [min, max].
- Les identifiants `p1`, `t1`… sont attribués à l'enregistrement et ne changent
  plus : la TV s'en sert pour désigner une tuile.
- Toute modification des pages change la **révision** : la TV recharge
  d'elle-même son affichage.

## Boutons

Une tuile **Bouton** (`button`) exécute **une commande action** de n'importe
quel équipement, avec des options fixes enregistrées sur la tuile : c'est
l'équivalent d'une action de scénario, posée sur la TV.

- **Commande** (obligatoire) : la commande action à exécuter, choisie avec le
  sélecteur de commande de Jeedom.
- **État** (facultatif) : une commande info dont la valeur s'affiche sur la
  tuile. Sans elle, la TV affiche ▶, comme pour un scénario.
- **Options** : les champs utiles au sous-type de la commande choisie
  apparaissent sous la tuile.

| Sous-type de la commande | Options passées |
|---|---|
| `message` | Titre et message (vides s'ils ne sont pas renseignés) |
| `slider` | Valeur |
| `select` | Choix |
| `color` | Couleur (`#RRGGBB`) |
| `other` | aucune |

Les options restent dans Jeedom : la TV ne reçoit que le nom, l'icône et la
valeur de l'état, et ne peut demander que `press`. La confirmation se règle
comme pour les autres tuiles, et se coche d'office si le nom de la commande ou
de la tuile contient portail, garage, verrou, alarme ou panique.

### Exemple : une page « Caméras »

Le plugin CameraOnTv crée une commande **Afficher <caméra>** (action / other)
par caméra. Une page de boutons les met sous la main :

1. Onglet **Pages et tuiles** : **Ajouter une page**, nommée « Caméras ».
2. Pour chaque caméra, **+ Tuile**, type **Bouton**, icône **Caméra**, nom
   « Portier », et en **Commande** `[Salon][Caméras TV][Afficher INTERCOM]`.
   Pas d'option : une commande `other` n'en prend pas. En **État**, on peut
   mettre `[Salon][Caméras TV][Caméra affichée]` pour voir la caméra à l'écran.
3. Pour régler la durée, utiliser plutôt la commande **Afficher caméra**
   (action / message) avec le message `{"camera":"INTERCOM","duration":60}`.
4. **Sauvegarder**. La TV recharge ses pages d'elle-même.

## Listes de choix

Une tuile **Liste de choix** (`select`) pilote une commande action de sous-type
**liste** (`select`) : mode d'une clim, source de chauffe, vitesse de
ventilation…

- **Régler** (obligatoire) : la commande liste, choisie avec le sélecteur de
  commande filtré sur les actions de type liste.
- **État** (facultatif) : l'info qui donne le choix en cours.
- Les **choix** sont ceux de la liste de valeurs de la commande
  (`valeur|Libellé;valeur|Libellé…`), dans l'ordre. Un élément sans `|` sert
  à la fois de valeur et de libellé ; un élément vide est ignoré.
- La liste est **relue à chaque chargement** des pages : si l'autre plugin la
  modifie, la TV voit les nouveaux choix. La liste entre dans la révision :
  une liste modifiée fait recharger la TV d'elle-même.
- Sur la TV, OK ouvre le choix, ◀ ▶ (ou ▲ ▼) le parcourent, OK l'envoie,
  Retour annule. Une valeur absente de la liste est refusée (422).

### Exemple : le mode de la clim

La climatisation de la salle à manger a une commande **Mode** (action /
liste, `auto|Auto;cold|Froid;wet|Déshumidification;heat|Chauffage;fan|Ventilation`)
et une info **État mode**. Une tuile Liste de choix « Salle à manger · Mode
clim », icône Thermostat, Régler = `[Salle à manger][Climatisation][Mode]`,
État = `[Salle à manger][Climatisation][État mode]` affiche « Froid » et
propose les cinq modes. La génération depuis les types génériques la crée
d'elle-même (voir plus bas).

## Bandeau d'infos

Onglet TV, cadre **Bandeau d'infos** : jusqu'à **6** infos de la maison que la
TV affiche en permanence en haut de l'écran des pages et du panneau en
superposition, mises à jour en direct.

Chaque ligne a :

- une **commande info** (sélecteur de commande de Jeedom, infos seulement) ;
- un **libellé** court, 24 caractères au plus (rempli d'office avec le nom de
  la commande s'il est vide) ;
- une **icône**, dans la même liste que les tuiles.

Les lignes se réordonnent avec les flèches et se suppriment avec la corbeille.
Elles reçoivent un identifiant `h1`, `h2`… qui ne change plus, et le numéro
d'une ligne supprimée n'est jamais réattribué. La valeur et l'unité sont celles
de la commande, comme pour une tuile `info`. Une commande supprimée retire
l'élément du bandeau, sans erreur. Modifier le bandeau change la révision : la
TV le recharge d'elle-même. Le bandeau ne s'actionne pas.

### Exemple

| Commande info | Libellé | Icône |
|---|---|---|
| `[Jardin][Station météo][Température]` | Extérieur | Température |
| `[Maison][Collecte des déchets][Prochaine collecte]` | Poubelles | Poubelle |
| `[Garage][Onduleur solaire][Puissance]` | Solaire | Soleil |
| `[Maison][Alarme][Mode]` | Alarme | Alarme |

La TV affiche alors par exemple « Extérieur 17 °C · Poubelles demain : Déchets
organiques · Solaire 2283 W · Alarme Absent ».

## Touches de couleur

Onglet TV, cadre **Touches de couleur** : une liste par touche (rouge, vert,
jaune, bleu), avec « Aucune » et les pages de la TV, page « Scénarios »
automatique comprise si un groupe est renseigné. Tant que rien n'a été
enregistré, la liste propose rouge = première page.

- La touche fonctionne **par-dessus n'importe quelle application** (un film,
  la chaîne TV, YouTube…) : elle ouvre le panneau de Jeedom TV sur la page
  choisie ; la même touche, ou Retour, le referme. Dans l'application, elle
  affiche directement la page.
- Il faut pour cela activer **une fois** le service d'accessibilité de
  l'application Jeedom TV : dans les réglages de la TV (*Paramètres →
  Accessibilité → Jeedom TV*), ou par adb :
  `adb shell settings put secure enabled_accessibility_services be.jeedomtv/<service>`
  puis `adb shell settings put secure accessibility_enabled 1` (le nom exact
  du service est donné par la documentation de l'application). Sans ce
  service, les touches n'agissent que dans l'application.
- Une page supprimée libère sa touche, sans erreur. Changer une touche change
  la révision : la TV prend le réglage en compte d'elle-même.
- Sans aucun réglage enregistré, la TV ouvre la première page avec la touche
  rouge, et les autres touches sont inactives.

## Barre d'état

Onglet **Barre d'état** : une petite barre permanente, affichée par la TV
**par-dessus toutes les applications** (un film, la chaîne TV…), avec l'heure
et des **indicateurs** : météo, lampe allumée, alarme armée, porte
déverrouillée, poubelles… Elle remplace l'horloge et les indicateurs de
TvOverlay. Le plugin calcule tout ; la TV ne fait qu'afficher.

Réglages : **Afficher la barre** (désactivée par défaut), **coin** (en bas à
gauche par défaut), **horloge**, **opacité** (0 à 100 %, 0 = masquée).

### Indicateurs automatiques

Même modèle, mêmes champs et même comportement que les « indicateurs
automatiques » du plugin TvOverlay (clé `auto_fixed`) :

- **Actif**, **id** (obligatoire, unique) et **nom**.
- **Visibilité** : *Toujours*, ou *Visible si…* des conditions
  `commande opérateur valeur` (`==`, `!=`, `>`, `>=`, `<`, `<=`), combinées
  par *au moins une* (OU) ou *toutes* (ET). Deux nombres se comparent en
  nombres (`1.0 == 1`) ; sinon `==` et `!=` comparent le texte sans tenir
  compte de la casse, et les autres opérateurs sont faux. Une commande sans
  valeur rend sa condition fausse, même avec `!=`.
- **Texte** : aucun, fixe, ou la valeur d'une commande, arrondie à N
  décimales (virgule décimale) et suivie d'un suffixe (`°`). Pas de suffixe
  seul tant que la commande n'a pas de valeur.
- **Icône** : un nom [Material Design Icons](https://pictogrammers.com/library/mdi/)
  (`mdi:lightbulb`), fixe ou publié par une commande (`weather-rainy` devient
  `mdi:weather-rainy`) ; l'icône fixe sert de repli.
- **Couleurs** de l'icône, du texte, de la bordure et du fond (`#RRGGBB` ou
  `#AARRGGBB`), **forme** (cercle, arrondie, rectangle). Sans couleur : icône
  et texte blancs, ni bordure ni fond ; sans forme : cercle.
- Les flèches changent l'ordre d'affichage.

Le champ `expiration` des indicateurs TvOverlay est conservé tel quel mais sans
effet : la barre est recalculée en permanence, il n'y a rien à renouveler.

### Fonctionnement

- Un **listener** suit les commandes citées par les indicateurs actifs
  (reconstruit à chaque enregistrement) et recalcule aussitôt la barre. Elle
  n'est envoyée à la TV **que si ce qui est affiché change** : `21,6` puis
  `21,8`, arrondis tous deux à `22°`, ne réveillent pas la TV.
- La TV reçoit la barre complète dans `layout`, puis dans `changes` à chaque
  changement, dans la demi-seconde.
- Un verrou par TV met en file les recalculs simultanés.
- Le cron de la minute recalcule aussi la barre, par sûreté.
- Un indicateur mal décrit (id manquant ou en double, « Visible si » sans
  condition, commande non choisie) n'est pas affiché ; **Aperçu de la barre
  enregistrée** dit pourquoi, et le journal le signale à l'enregistrement.

### Indicateurs temporaires et retrait à la main

- **Indicateur (JSON)**, au format TvOverlay : `id` (obligatoire),
  `message` (texte), `icon`, `iconColor`, `messageColor`, `borderColor`,
  `backgroundColor`, `shape`, `expiration` (secondes `90`, durée `30m`,
  `1d2h`, `1y2w3d4h5m6s`, ou date epoch ; absente = jusqu'au retrait),
  `visible` (`false` retire). Gardés par TV (ils survivent à un redémarrage),
  ajoutés après les indicateurs automatiques ; un temporaire qui porte l'id
  d'un indicateur automatique prend sa place. Retirés à l'expiration (aussitôt
  si la TV attend des changements, sinon au cron de la minute).

  ```json
  {"id":"lessive","icon":"mdi:washing-machine","message":"Fini","iconColor":"#2196f3","expiration":"30m"}
  ```
- **Retirer un indicateur** (message = `id`) : un temporaire disparaît ; un
  indicateur automatique **reste retiré tant que ce qu'il affiche ne change
  pas**, puis revient (la lampe éteinte puis rallumée, la température qui
  passe de 22° à 23°).

### Importer depuis TvOverlay

**Importer depuis TvOverlay** (onglet Barre d'état, visible si le plugin
TvOverlay a des équipements) recopie dans l'éditeur les indicateurs
automatiques d'un équipement TvOverlay ; relire, puis **Sauvegarder**. Rien
n'est modifié côté TvOverlay. Par script (scénario, bloc Code, ou
`php` en ligne de commande) :

```php
jeetvbe::importTvOverlayIndicators(<id de la TV Jeedom TV>, <id de l'équipement TvOverlay>);
```

La fonction remplace la liste de la TV, l'enregistre et rend les indicateurs
importés. Avec un troisième argument `false`, elle rend seulement la liste,
sans rien enregistrer.

## Notifications riches

**Notifier (JSON)** reprend le format JSON de TvOverlay. Le message est un
objet JSON, écrit en texte ou reçu en objet : un formulaire de Jeedom change un
texte qui commence par `{` en objet, et le plugin hygeabe envoie le message
ainsi. Dans un objet, les `#id#` de commande sont remplacés par leur valeur.

| Champ TvOverlay | Sur la TV |
|---|---|
| `title`, `message` | Titre et texte du bandeau. |
| `id` | Identifiant de la notification (`tag` dans le contrat) : une notification de même `id` remplace celle affichée ; **Retirer une notification** la retire. |
| `smallIcon`, sinon `largeIcon` (`mdi:…`) | Icône à gauche du titre, quand il n'y a ni image ni vidéo. |
| `smallIconColor` | Couleur de cette icône. |
| `image`, sinon `largeIcon` / `smallIcon` s'ils sont une image | Image du bandeau : chemin d'un fichier de Jeedom, adresse `http(s)` **du réseau local** (téléchargée par le plugin, 5 s et 5 Mo au plus, sans redirection), ou base64. Elle est copiée comme les autres images jointes ; une adresse hors du réseau local est refusée et le bandeau part sans image. |
| `video` | Nom d'une **source vidéo** de la TV, ou adresse complète `rtsp://`, `http(s)://…m3u8` : flux joué en direct, sans le son, dans le bandeau. Un nom inconnu fait échouer la commande. |
| `corner` | `top_end` (défaut), `top_start`, `bottom_end`, `bottom_start`. |
| `duration` | Durée du bandeau, ramenée entre 3 et 120 s. |
| `source` | Ignoré (pas d'équivalent sur la TV). |

```json
{"id":"sonnette","title":"On sonne","message":"Porte d'entrée","video":"portier","smallIcon":"mdi:bell","duration":30}
```

**Retirer une notification** (message = `id`) envoie l'ordre `dismiss` : le
bandeau disparaît aussitôt s'il est encore affiché.

**Message** accepte aussi le marqueur `[video=<nom>]`, comme `[image=…]` et
`[durée=<s>]` ; **Question** aussi (la vidéo remplace la photo à gauche de la
question) :

```
Message : On sonne au portail [video=portail] [durée=30]
Demander  Question : On sonne. Ouvrir ? [video=portier] [image=#[Devant maison][Portier][Fichier image]#]
```

## Sources vidéo

Onglet TV, cadre **Sources vidéo** : un **nom** par flux de caméra (lettres,
chiffres, `_`, `-`, `.`) et son **adresse complète**, identifiants compris
(`rtsp://utilisateur:motdepasse@192.168.0.50:554/…`). Saisie une seule fois,
l'adresse n'est plus jamais affichée en clair : la page ne montre que sa forme
masquée (`rtsp://***@192.168.0.50:554/…`), et les journaux du plugin aussi.
Une source s'enregistre aussitôt (sans **Sauvegarder**) ; le même nom remplace
l'adresse.

Utilisez toujours le **nom** dans les scénarios et les JSON : Jeedom écrit les
paramètres des commandes exécutées dans son journal `event`, et une adresse
écrite en clair dans un scénario s'y retrouverait.

## Migration depuis TvOverlay

Jeedom TV fait désormais tout ce que faisait TvOverlay : bandeaux riches avec
image ou vidéo, indicateurs, horloge, par-dessus les autres applications.

1. **Barre d'état** : *Importer depuis TvOverlay*, relire, cocher *Afficher la
   barre*, régler coin, horloge et opacité, **Sauvegarder**.
2. **Sources vidéo** : déclarer chaque caméra par son nom.
3. **Scénarios et plugins** : remplacer les commandes de l'équipement TvOverlay
   par celles de la TV Jeedom TV (tableau ci-dessous) ; dans les JSON,
   remplacer les adresses `rtsp://…` par le nom de la source. Les messages
   JSON se reprennent tels quels.
4. Quand tout est passé : désactiver l'équipement TvOverlay.

| TvOverlay (`tvoverlaybe`) | Jeedom TV (`jeetvbe`) | logicalId |
|---|---|---|
| Notifier (titre, message) | **Message** | `notify` |
| Notifier (JSON) | **Notifier (JSON)** | `notify_json` |
| Retirer une notification | **Retirer une notification** | `dismiss` |
| Indicateur (JSON) | **Indicateur (JSON)** | `fixed_json` |
| Retirer un indicateur | **Retirer un indicateur** | `fixed_remove` |
| Indicateurs automatiques (`auto_fixed`) | Onglet **Barre d'état** (`indicators`) | — |
| Horloge, Régler l'horloge | Réglage *Horloge* de la barre | — |
| Coin de l'overlay | Coin de la barre ; `corner` de chaque notification | — |
| En ligne, Écran allumé | **En ligne**, **Écran allumé** | `online`, `screen` |
| Retirer tous les indicateurs, Activer/Suspendre les notifications, Afficher/Masquer les indicateurs, Fond, Durée des notifications, Indicateurs affichés, Rafraîchir | Pas d'équivalent (opacité 0 masque la barre ; `duration` par notification) | — |

La migration des scénarios et des plugins (dahua, hygeabe, presencium) se fera
par un script, après accord : ce plugin ne touche ni à TvOverlay ni à vos
scénarios.

## Page « Scénarios » automatique

Option **Groupe de scénarios** (onglet TV) : par exemple `Ambiances`. Si elle
est renseignée, la TV reçoit à la fin de ses pages une page **Scénarios**
(id `scenes`) avec une tuile par scénario **actif** de ce groupe, triée par
nom (id de tuile `s<id du scénario>`). Vide : pas de page.

- La page suit le groupe sans réenregistrer la TV : ajouter un scénario au
  groupe, le renommer, l'activer ou le désactiver change la révision, et la
  TV recharge son affichage d'elle-même.
- **Confirmation** demandée sur la TV si la description du scénario contient
  `[confirmer]`, ou si son nom contient portail, garage, verrou, alarme ou
  panique.
- Si une page manuelle s'appelle déjà « Scénarios », la page automatique
  s'appelle « Ambiances ».
- La commande **Afficher Scénarios** (logicalId `show_scenes`) est créée à
  l'enregistrement quand l'option est renseignée.
- Lancer une tuile vérifie que le scénario est toujours dans le groupe et
  actif : sinon la TV reçoit 404 (hors groupe) ou 422 (désactivé).

## Ordres de Jeedom vers la TV

Le plugin crée sur chaque TV des commandes utilisables dans les scénarios :

| Commande | Type | Effet |
|---|---|---|
| `Afficher <page>` (une par page) | action | Affiche la page, puis revient à l'écran précédent après la durée par défaut. |
| `Afficher page` | action / message | Titre : id ou nom de la page (casse ignorée). Message : durée en secondes (vide = durée par défaut, `0` = sans retour). |
| `Message` | action / message | Bandeau d'environ 8 s sur la TV (si l'application est visible), ou de la durée donnée par `[durée=<s>]`. Titre facultatif. |
| `Quitter` | action | L'application passe en arrière-plan. |
| `Question` | action / message | Question à choix, pour le bloc « Demander » des scénarios (voir plus bas). |
| `Notifier (JSON)` | action / message | Message = objet JSON au format TvOverlay → bandeau riche (voir « Notifications riches »). |
| `Indicateur (JSON)` | action / message | Message = objet JSON au format TvOverlay → indicateur temporaire de la barre d'état. |
| `Retirer une notification` | action / message | Message = `id` de la notification → la retire de l'écran. |
| `Retirer un indicateur` | action / message | Message = `id` de l'indicateur → le retire de la barre. |
| `En ligne` | info binaire | 1 si la TV a appelé l'API dans les 60 dernières secondes. |
| `Visible` | info binaire | L'application est au premier plan. |
| `Écran allumé` | info binaire | L'écran n'est pas en veille. |
| `Page affichée` | info texte | Nom de la page à l'écran (vide hors des pages). |
| `Version app` | info texte | Version de l'application Jeedom TV installée sur la TV. |

- **Durée d'affichage par défaut** (onglet TV) : 30 s par défaut, 0 = sans retour.
  Le retour n'a pas lieu si quelqu'un a touché la télécommande entre-temps.
- Les commandes `Afficher <page>` suivent les pages : créées, renommées ou
  supprimées à l'enregistrement (logicalId `show_<id de page>`). Un id de page
  n'est jamais réattribué : une commande utilisée dans un scénario ne finit
  jamais par afficher une autre page.
- Un ordre est livré une seule fois. S'il n'a pas été reçu au bout de **60 s**
  (TV éteinte, réseau coupé), il est abandonné : la TV n'affichera pas une page
  périmée à son réveil.
- La TV doit être allumée et l'application configurée pour recevoir les ordres.

## Questions de Jeedom (bloc « Demander »)

Chaque TV porte une commande **Question**. Dans un scénario, le bloc
**Demander** l'utilise pour poser une question à choix sur la TV et attendre la
réponse :

1. Ajouter un bloc **Demander** (action « Demander » dans un bloc Action).
2. **Question** : le texte affiché, par exemple `Fermer les volets du salon ?`.
3. **Réponses** : les choix séparés par `;`, par exemple `Oui;Non`.
4. **Variable** : le nom de la variable qui recevra la réponse, par exemple `reponse_tv`.
5. **Commande** : `[Salon][TV salon][Question]`.
6. **Délai** : le temps d'attente en secondes, par exemple `60`.

Puis, dans la suite du scénario :

```
SI variable(reponse_tv) == "Oui"
ALORS [Automatisme][Volets SUD (séjour)][Fermer]
```

Sur la TV, une boîte de dialogue s'ouvre (même par-dessus un film) : ◀ ▶
choisissent une réponse, OK l'envoie, Retour ferme sans répondre. Sans
réponse dans le délai, la variable vaut « Aucune réponse ».

- La TV doit être allumée et l'application configurée : une question qui n'a
  pas pu être livrée avant la fin de son délai (60 s au plus) est abandonnée.
- Une seule question à la fois par TV : une nouvelle remplace la précédente.
- La réponse libre (`*` dans les réponses) n'est pas proposable à la
  télécommande : seules les réponses listées sont proposées.
- Exécutée hors d'un bloc Demander (sans réponses), la commande Question se
  comporte comme **Message**.

## Durée d'un message

Le bandeau de la commande **Message** reste environ 8 s à l'écran. Un marqueur
**`[durée=<s>]`** dans le titre ou le message en fixe la durée, de 3 à 120 s
(une valeur hors de ces bornes y est ramenée). Le marqueur est retiré du texte
affiché, et se combine avec `[image=…]` :

```
Message : Lave-linge terminé [durée=20]
Message : On sonne au portail [durée=45] [image=#[Devant maison][Portier][Fichier image]#]
```

La commande **Question** ignore ce marqueur (la question a son propre délai),
mais le retire aussi du texte.

## Images jointes (photo du portier…)

Les commandes **Message** et **Question** peuvent joindre une image, affichée
par la TV avec le texte. Trois façons, la première trouvée l'emporte :

1. `[image=<chemin>]` dans le titre ou le message, en général avec une
   commande info qui donne le chemin d'une photo :
   `[image=#[Devant maison][Portier][Fichier image]#]`. Jeedom remplace la
   commande par son chemin avant l'exécution ; le marqueur est retiré du
   texte affiché.
2. L'option `files` (pièces jointes, comme l'action « Rapport ») : le premier
   fichier image de la liste.
3. Un titre de la forme `title=Sonnette | files=/chemin/photo.jpg` (la même
   que pour d'autres plugins de notification) : `title=` donne le titre.

Exemple, scénario déclenché par la sonnette :

```
[Devant maison][Portier][Prendre une photo]
attendre 2 s
Demander  Question : On sonne au portail. Ouvrir ? [image=#[Devant maison][Portier][Fichier image]#]
          Réponses : Ignorer;Ouvrir
          Variable : reponse_portail
          Commande : [Salon][TV salon][Question]
          Délai    : 45
SI variable(reponse_portail) == "Ouvrir"
ALORS …
```

- L'image est **copiée** au moment de l'ordre : la photo suivante ne la
  remplace pas. Elle est supprimée à l'expiration de l'ordre, au plus tôt
  5 minutes après ; seule la TV qui a reçu l'ordre peut la télécharger.
- Seuls les fichiers **JPEG ou PNG de 5 Mo au plus**, situés sous la racine de
  Jeedom (`/var/www/html`) ou son dossier temporaire, sont acceptés (le type
  est vérifié d'après le contenu). Sinon l'ordre part **sans image**, et la
  raison est écrite dans le journal du plugin (niveau avertissement).
- Les images vivent dans `data/images/<id de la TV>/`, fermé au navigateur :
  le seul accès est l'API de la TV.

## Génération depuis les types génériques

On coche des objets (pièces) ; leurs équipements **activés** donnent des tuiles,
regroupées au choix :

- **par type** (par défaut) : une page par type, dans cet ordre, les pages
  vides omises — **Lumières** (`LIGHT_*`), **Volets** (`FLAP_*`), **Chauffage
  et clim** (consignes de thermostat, et les interrupteurs des équipements qui
  portent un type `THERMOSTAT_*`, comme une clim), **Températures**, **Prises**
  (`ENERGY_*`), **Scénarios**. Chaque tuile est nommée « Pièce · Nom », sans
  répéter la pièce quand le nom la contient déjà (« Plafond salon » dans
  Salon devient « Salon · Plafond »). Les tuiles sont rangées par pièce, dans
  l'ordre des objets de Jeedom, puis par nom ;
- **par pièce** : une page par objet coché, tuiles rangées par type.

Correspondances :

| Types génériques | Tuile |
|---|---|
| `LIGHT_*` (état, on, off, bascule) | `switch`, icône lumière |
| `LIGHT_SLIDER` avec `LIGHT_BRIGHTNESS` (ou un `LIGHT_STATE` numérique) | en plus, `slider` « <nom> (luminosité) », bornes de la commande (sinon 0–100), pas de 10, en % |
| `ENERGY_*` (état, on, off) | `switch`, icône prise |
| `FLAP_*` | `shutter` ; position si `FLAP_SLIDER` (0–100, pas de 10), état si `FLAP_STATE` ou `FLAP_BSO_STATE` |
| `THERMOSTAT_SET_SETPOINT` + `THERMOSTAT_SETPOINT` | `slider` « Consigne … », bornes de la commande sinon 15–25, pas de 0,5 |
| `THERMOSTAT_SET_MODE` (action liste), état `THERMOSTAT_MODE` | `select` « <nom> · Mode », icône thermostat (page « Chauffage et clim ») |
| `TEMPERATURE`, `THERMOSTAT_TEMPERATURE`, `THERMOSTAT_TEMPERATURE_OUTDOOR` | `info`, icône température |

- Une `TEMPERATURE` portée par un équipement qui a déjà une tuile actionnable
  (la « température retenue » d'un groupe de volets) n'est pas reprise.
- **Confirmation cochée d'office** si l'équipement porte un type `LOCK_*`,
  `ALARM_*`, `GB_*` ou `GARAGE_*`, ou si son nom contient portail, garage,
  verrou, alarme ou panique. Dans l'éditeur, choisir une commande ou un
  scénario dont le nom contient l'un de ces mots coche aussi la case.
- Les pages générées s'ajoutent à la suite des pages existantes : rien n'est
  enregistré avant **Sauvegarder**.
- La génération ne propose pas encore de tuiles de scénario : la page
  Scénarios n'apparaît que si on en ajoute à la main.
- **Ids après une régénération** : une tuile qui pilote exactement les mêmes
  commandes qu'une tuile déjà enregistrée reprend son id ; une tuile nouvelle
  reçoit un numéro jamais attribué sur cette TV. Une TV qui n'a pas encore
  rechargé son layout ne peut donc pas actionner, par un ancien id, un autre
  équipement que celui qu'elle affiche.

## API

Le contrat complet entre le plugin et l'application est
[`docs/api.md`](../api.md). En résumé :

```
http://<jeedom>/plugins/jeetvbe/core/php/api.php?action=<action>
En-tête : X-JEETVBE-KEY: <clé>   (repli : paramètre key=)
```

| Action | Rôle |
|---|---|
| `GET ping` | Vérifie la clé. |
| `GET layout` | Pages, tuiles, valeurs actuelles, révision, touches de couleur (`keys`), bandeau (`header`) et barre d'état (`status`). |
| `POST exec` | `{"tile": "t2", "action": "set", "value": 40}` ; `press` pour un bouton. |
| `GET changes&since=<curseur>` | Attente longue (25 s au plus) des changements de valeur, des ordres de Jeedom (`commands`) et de la barre d'état (`status`, complète, seulement quand elle change). |
| `POST state` | `{"visible": true, "screenOn": true, "page": "p2"}` : état de la TV. |
| `POST answer` | `{"ask": "<jeton>", "answer": "Oui"}` : réponse à une question. |
| `GET image&id=<id>` | Image jointe à un ordre `notify` ou `ask`. |

Essai rapide :

```bash
curl -H 'X-JEETVBE-KEY: <clé>' 'http://<jeedom>/plugins/jeetvbe/core/php/api.php?action=layout'
```

`changes` ne retient aucune session et n'empêche pas les autres requêtes de la
TV : un `ping` ou un `exec` lancé pendant l'attente répond aussitôt.

## Dépannage

- **401** : clé absente, inconnue (régénérée ?) ou TV désactivée.
- **404 Tuile inconnue** : la TV affiche un layout périmé ; elle doit le recharger.
- **422** : action non permise sur ce type de tuile, ou commande manquante
  (par exemple `set` sur un volet sans position).
- Le journal du plugin (`jeetvbe`, niveau info) trace chaque action exécutée,
  avec la tuile et la commande Jeedom choisie.
