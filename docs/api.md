# API Jeedom TV (plugin `jeetvbe`, schéma 1)

Contrat entre l'application Android TV `be.jeedomtv` et le plugin Jeedom `jeetvbe`.
Ce document fait foi pour les deux côtés : toute évolution change d'abord ce fichier
(copie identique dans les deux dépôts), puis le code.

## Principes

- La TV ne connaît **jamais un id de commande Jeedom**. Elle voit des pages et des tuiles ;
  le plugin traduit chaque action de tuile en commande Jeedom, après avoir vérifié que la
  tuile appartient bien à cette TV. Une clé volée ne pilote que ce qui est affiché.
- **Un équipement `jeetvbe` = une TV.** Chaque équipement a sa propre clé (`token`),
  générée à la création, affichée sur sa page de configuration, régénérable.
- HTTP en clair sur le réseau local (comme les autres clients LAN de la maison).

## Point d'accès

```
http://<jeedom>/plugins/jeetvbe/core/php/api.php?action=<action>
```

Authentification : en-tête `X-JEETVBE-KEY: <token>` (repli : paramètre `key=`).
La clé désigne la TV : pas d'autre paramètre d'identification.
L'équipement doit être activé.

Toutes les réponses sont du JSON UTF-8 (`Content-Type: application/json`).

### Erreurs

| HTTP | Cas | Corps |
|---|---|---|
| 401 | Clé absente, inconnue ou équipement désactivé | `{"error": "Clé invalide"}` |
| 400 | Action inconnue, JSON invalide, paramètre manquant | `{"error": "…"}` |
| 404 | Tuile inconnue pour cette TV | `{"error": "Tuile inconnue"}` |
| 422 | Action non permise sur ce type de tuile | `{"error": "…"}` |
| 500 | Erreur Jeedom pendant l'exécution | `{"error": "…"}` |

Le message `error` est en français, affichable tel quel à l'écran.

## `GET ?action=ping`

Vérifie la clé. Utilisé par l'écran de configuration de la TV.

```json
{"ok": true, "schema": 1, "tv": {"id": 612, "name": "TV salon"}, "jeedom": "4.6.1", "plugin": "0.1"}
```

## `GET ?action=layout`

Pages et tuiles de la TV, avec les valeurs actuelles.

```json
{
  "schema": 1,
  "revision": "9f2c1a",
  "pages": [
    {
      "id": "p1",
      "name": "Salon",
      "tiles": [
        {"id": "t1", "type": "switch", "name": "Plafond salon", "icon": "light", "confirm": false,
         "value": "1", "unit": ""},
        {"id": "t2", "type": "shutter", "name": "Volets SUD séjour", "icon": "shutter", "confirm": false,
         "value": "100", "unit": "%", "min": 0, "max": 100, "step": 10},
        {"id": "t3", "type": "shutter", "name": "volet 4", "icon": "shutter", "confirm": false,
         "value": null, "unit": ""},
        {"id": "t4", "type": "slider", "name": "Consigne salon", "icon": "thermostat", "confirm": false,
         "value": "20.5", "unit": "°C", "min": 15, "max": 25, "step": 0.5},
        {"id": "t5", "type": "info", "name": "Température salon", "icon": "temperature", "confirm": false,
         "value": "24", "unit": "°C"},
        {"id": "t6", "type": "scene", "name": "Bonne nuit", "icon": "scene", "confirm": true,
         "value": null, "unit": ""}
      ]
    }
  ]
}
```

Champs d'une tuile :

| Champ | Type | Remarque |
|---|---|---|
| `id` | string | Stable tant que la configuration ne change pas. Unique pour la TV (pas seulement dans la page). Opaque pour la TV (`t12`, `s34`…). |
| `type` | string | `switch`, `shutter`, `slider`, `info`, `scene`, `button`, `select`. Un type inconnu doit être affiché comme `info` par la TV. |
| `name` | string | Peut prendre la forme « Pièce · Nom » (séparateur ` · `, pages par type) : la TV affiche alors la pièce en petit au-dessus du nom. |
| `icon` | string | `light`, `plug`, `shutter`, `thermostat`, `temperature`, `scene`, `fan`, `lock`, `alarm`, `camera`, `sun`, `rain`, `trash`, `power`, `generic`. Inconnu → `generic`. |
| `confirm` | bool | La TV demande une confirmation avant toute action. |
| `value` | string ou null | Valeur brute de la commande info liée ; `null` si la tuile n'a pas de retour d'état (volet rfxcom, scénario). |
| `unit` | string | Peut être vide. |
| `min`, `max`, `step` | number | Présents seulement pour `shutter` (position) et `slider`. Absents pour un volet sans position. |

Interprétation de `value` :

- `switch` : `"1"` ou tout nombre > 0 = allumé, `"0"` = éteint.
- `shutter` : position 0 (fermé) à 100 (ouvert), ou état binaire 0/1 selon l'équipement.
- `slider`, `info` : valeur à afficher avec `unit`.

`revision` change dès que la configuration des pages change dans Jeedom (hash de la
configuration). La TV recharge alors le layout.

## `POST ?action=exec`

Corps JSON :

```json
{"tile": "t2", "action": "set", "value": 40}
```

Actions par type :

| Type | Actions |
|---|---|
| `switch` | `on`, `off`, `toggle` |
| `shutter` | `up`, `down`, `stop`, `set` (avec `value`, seulement si la tuile a `min`/`max`) |
| `slider` | `set` (avec `value`, ramenée par le plugin dans [`min`, `max`]) |
| `scene` | `run` |
| `button` | `press` |
| `select` | `set` (avec `value` : une des `choices[].value`, chaîne) |
| `info` | aucune (422) |

`toggle` sans commande toggle côté Jeedom : le plugin choisit `on` ou `off` d'après la valeur.

Réponse : `{"ok": true, "value": "<nouvelle valeur ou null>"}`. `value` est la valeur
lue juste après l'exécution ; elle peut ne pas encore refléter l'effet (l'équipement
répond parfois en différé) : la vérité arrive par `changes`.

La confirmation (`confirm: true`) est une affaire d'interface : le plugin ne l'exige pas.

## `GET ?action=changes&since=<curseur>`

Attente longue : la réponse arrive dès qu'une valeur d'une tuile de cette TV change,
ou après **25 s** sans changement (réponse vide). Le client utilise un délai de lecture
HTTP d'au moins 35 s et relance aussitôt avec le nouveau curseur.

- `since` : curseur opaque (nombre décimal), renvoyé par l'appel précédent.
  Absent ou `0` au premier appel : la réponse est immédiate, sans changement, et donne le curseur de départ.

```json
{"since": 1791364425.381, "revision": "9f2c1a",
 "changes": [{"tile": "t1", "value": "0"}, {"tile": "t4", "value": "21"}],
 "commands": [{"id": 17, "type": "show", "page": "p2", "duration": 30}]}
```

- `commands` : ordres de Jeedom pour la TV (voir « Commandes Jeedom → TV »). Toujours présent,
  éventuellement vide. Un ordre mis en file réveille l'attente aussitôt.

- Plusieurs changements d'une même tuile sont fusionnés (dernière valeur).
- Si `revision` diffère de celle du layout affiché, la TV recharge le layout.
- Après une erreur réseau, la TV recharge le layout (des changements ont pu être perdus),
  puis reprend `changes` avec `since` absent.

## `POST ?action=state`

La TV signale son état à Jeedom, à chaque changement (et au moins une fois après chaque
démarrage ou reconnexion). Corps JSON, tous les champs facultatifs :

```json
{"visible": true, "screenOn": true, "page": "p2", "appVersion": "0.4.0"}
```

- `visible` : l'application est au premier plan (sinon la TV affiche une autre application).
- `screenOn` : l'écran de la TV est allumé (sinon veille).
- `page` : id de la page affichée, `null` hors écran des pages (configuration, chargement).
- `appVersion` : version de l'application (`versionName`), envoyée avec chaque état ; le plugin la range dans l'info `Version app`.
- Présence humaine et propriété de l'allumage (`inUse`, `wokeBy`…) : voir « Présence humaine et allumage ».

Réponse : `{"ok": true}`. Le plugin met à jour les commandes info de l'équipement.

## Commandes Jeedom → TV

### Côté Jeedom (équipement de la TV)

Le plugin crée et tient à jour sur chaque équipement TV :

| Commande | Type | Effet |
|---|---|---|
| `Afficher <nom de page>` (une par page) | action / other | Ordre `show` vers cette page, avec la durée configurée sur l'équipement |
| `Afficher page` | action / message | Titre = id ou nom de page (insensible à la casse) ; message = durée en s (vide = durée configurée, `0` = sans retour) |
| `Message` | action / message | Ordre `notify` (titre facultatif, message) |
| `Quitter` | action / other | Ordre `exit` |
| `En ligne` | info / binary | 1 si la TV a appelé l'API dans les 60 dernières secondes |
| `Visible` | info / binary | Dernier `visible` reçu |
| `Écran allumé` | info / binary | Dernier `screenOn` reçu |
| `Page affichée` | info / string | Nom de la dernière page reçue (vide si `null`) |
| `Version app` | info / string | Dernier `appVersion` reçu |

Configuration de l'équipement : « Durée d'affichage par défaut » en secondes (défaut 30 ; 0 = sans retour).
Les commandes `Afficher <page>` suivent les pages : créées, renommées ou supprimées à l'enregistrement.

### Transport

Les ordres sont mis en file sur l'équipement et livrés dans `commands` de la réponse `changes`
suivante (une seule fois ; la file est vidée à la livraison). Un ordre non livré au bout de
**60 s** est abandonné : une TV éteinte ne doit pas afficher une page périmée à son réveil.

| `type` | Champs | Effet sur la TV |
|---|---|---|
| `show` | `page` (id), `duration` (s, 0 = sans retour) | Affiche la page (sélection sur la première tuile), passe au premier plan si besoin. Après `duration`, retour à l'écran ou à l'application précédente, sauf si l'utilisateur a touché la télécommande entre-temps. |
| `notify` | `title` (peut être vide), `message` | Notification d'environ 8 s (ou `duration`) : dans l'application si elle est visible, dans le panneau s'il est ouvert, sinon par-dessus la vidéo (permission « afficher par-dessus » accordée) ; perdue seulement sans cette permission, application cachée. Une seule à la fois : les suivantes attendent leur tour (voir « Notifications riches »). |
| `exit` | — | L'application passe en arrière-plan (retour au programme TV). |
| `dismiss` | `target` (identifiant de notification, le `tag` du `notify`) | Retire tout de suite la notification portant ce `tag`, affichée ou en attente (sans effet sinon). |
| `ask` | `ask` (jeton), `title` (peut être vide), `message`, `answers` (liste, au moins une), `timeout` (s) | Question à choix : boîte de dialogue au premier plan (par-dessus la vidéo si l'application est cachée). ◀ ▶ choisissent une réponse, OK l'envoie (`POST ?action=answer`), Retour ferme sans répondre. Compte à rebours ; fermeture d'elle-même à la fin de `timeout`. Une nouvelle question remplace la précédente. |
| `ask_close` | `ask` (jeton), `answer` (facultatif), `by` (facultatif, nom de la TV) | Ferme tout de suite la question portant ce jeton si elle est affichée (sans effet sinon, ni sur une autre question). Avec `answer`, affiche brièvement « Réponse donnée sur <by> : <answer> » à la place de la question. |

`id` : entier croissant par TV ; la TV ignore un `id` déjà traité. Un `type` inconnu est ignoré.
`show`, `notify` et `ask` acceptent aussi `wake`, `show` accepte `mode`, `fallback` et `sleepAfter`, et deux
ordres `wake` et `sleep` existent : voir « Présence humaine et allumage ».

La TV garde la boucle `changes` active en arrière-plan (service au premier plan), tant
qu'elle est configurée, pour recevoir les ordres même pendant un film.

## Questions de Jeedom (bloc « Demander » des scénarios)

Chaque TV porte une commande action / message **`Question`**. Le bloc **Demander**
d'un scénario Jeedom l'exécute avec la question, les réponses possibles (`Oui;Non`)
et un délai ; le scénario attend la réponse (ou « Aucune réponse » à la fin du délai).

- Le plugin met en file un ordre `ask` avec un **jeton** aléatoire propre à cette question,
  et retient (jeton, commande, réponses, fin du délai) pour cette TV.
- La commande `Question` exécutée hors d'un bloc Demander (sans réponses) se comporte
  comme `Message`.
- L'ordre `ask` est abandonné s'il n'est pas livré avant la fin de son délai (et au plus 60 s).

## `POST ?action=answer`

```json
{"ask": "<jeton>", "answer": "Oui"}
```

| HTTP | Cas |
|---|---|
| 200 `{"ok": true}` | Réponse transmise au scénario. |
| 404 | Jeton inconnu pour cette TV, question expirée ou déjà répondue. La TV affiche « Question expirée ». |
| 422 | Réponse absente de la liste proposée. |

Le plugin transmet la réponse au cœur (`cmd::askResponse`), qui la refuse lui-même hors
délai ou hors liste. Une réponse ne vaut que pour la TV qui a reçu la question.

## Question à plusieurs TV (`Toutes les TV`)

La commande `Question` de l'équipement **Toutes les TV** pose la même question, avec le **même jeton**,
à chaque TV qui reçoit les diffusions et dont l'écran est allumé.

- La **première** réponse valable (`POST ?action=answer` de n'importe laquelle de ces TV) est transmise
  au scénario ; le plugin met alors en file, pour **chacune des autres** TV, un ordre
  `{"type":"ask_close","ask":"<jeton>","answer":"Ouvrir","by":"TV salon"}`.
- Une réponse arrivée ensuite d'une autre TV reçoit **409** (déjà répondue) : la TV affiche
  « Déjà répondu » puis ferme la question.
- Retour (fermer sans répondre) sur une TV ne ferme pas la question sur les autres.
- Si aucune TV n'est allumée, la commande ne met rien en file et le scénario reçoit « Aucune réponse »
  à la fin du délai, comme d'habitude.

| HTTP (`answer`) | Cas supplémentaire |
|---|---|
| 409 | Question à plusieurs TV déjà répondue depuis une autre TV. |

## Images jointes (`notify` et `ask`)

Un ordre `notify` ou `ask` peut porter un champ facultatif **`image`** : identifiant opaque
d'une image que la TV télécharge par `GET ?action=image`. Absent ou `null` : pas d'image.

```json
{"id": 42, "type": "ask", "ask": "…", "title": "", "message": "On sonne au portail. Ouvrir ?",
 "answers": ["Ignorer", "Ouvrir"], "timeout": 45, "image": "a3f9c2…"}
```

### Côté Jeedom

Les commandes `Message` et `Question` acceptent une image de trois façons (la première trouvée) :

1. **`[image=<chemin>]`** dans le titre ou le message, typiquement
   `[image=#[Devant maison][Portier][Fichier image]#]` (Jeedom remplace la commande par son
   chemin avant l'exécution). Le marqueur est retiré du texte affiché.
2. **`$_options['files']`** (convention Jeedom des pièces jointes, action « Rapport ») : le
   premier fichier image de la liste.
3. **`files=<chemin>[,<chemin>…]`** dans le titre, convention `title=… | files=…` : le premier
   fichier image ; `title=` donne le titre.

Le plugin **copie** l'image au moment de l'ordre (une photo suivante ne la remplace pas),
dans un dossier propre à la TV, et la supprime à l'expiration de l'ordre (au plus tôt
5 minutes après sa création). Seuls les fichiers JPEG ou PNG de 5 Mo au plus, situés sous
la racine de Jeedom ou son dossier temporaire, sont acceptés ; sinon l'ordre part sans image.

## `GET ?action=image&id=<identifiant>`

Renvoie l'image (`Content-Type: image/jpeg` ou `image/png`). L'identifiant ne vaut que pour
la TV qui a reçu l'ordre.

| HTTP | Cas |
|---|---|
| 200 | L'image |
| 404 | Identifiant inconnu pour cette TV, ou image expirée |

## Tuile `button`

Un bouton exécute **une commande action Jeedom** choisie dans le plugin (rôle `press`),
avec des options fixes facultatives enregistrées sur la tuile côté plugin (`title`,
`message`, `slider`, `select`, `color`). Exemple : « Afficher caméra » de CameraOnTv avec
le message `{"camera":"INTERCOM","duration":60}`. Les options ne sont jamais envoyées à la TV.

- Action de la TV : `press` (POST `exec` sans `value`).
- `value` : celle de la commande info du rôle facultatif `state`, sinon `null`.
- Affichage TV : comme `scene` (« ▶ » quand `value` est `null`).

## Touches de couleur (raccourcis télécommande)

La réponse de `layout` porte un champ facultatif **`keys`** : la page à ouvrir pour chaque
touche de couleur de la télécommande. Les couleurs absentes ou sans page sont inactives.

```json
"keys": {"red": "p1", "green": "scenes", "yellow": "p2", "blue": "p6"}
```

- Clés possibles : `red`, `green`, `yellow`, `blue`. Valeur : un `id` de page présent dans `pages`.
- Réglées par TV dans le plugin (une liste de pages par couleur). Un changement modifie `revision`.
- Côté TV, la touche fonctionne **partout** (y compris par-dessus une autre application, grâce à
  un service d'accessibilité) : elle ouvre le panneau sur cette page ; la même touche, ou Retour,
  le referme. Dans l'application ouverte, elle affiche directement la page.
- `keys` absent : la touche rouge ouvre la première page, les autres sont inactives.

## Bandeau d'infos (`header`)

La réponse de `layout` porte un champ facultatif **`header`** : jusqu'à **6** infos de la maison
que la TV affiche en permanence en haut de l'écran des pages et du panneau en superposition.

```json
"header": [
  {"id": "h1", "label": "Extérieur", "icon": "temperature", "value": "17", "unit": "°C"},
  {"id": "h2", "label": "Poubelles", "icon": "trash", "value": "demain : Déchets organiques", "unit": ""},
  {"id": "h3", "label": "Solaire", "icon": "sun", "value": "2283", "unit": "W"}
]
```

- Chaque élément vient d'une commande **info** choisie dans le plugin (par TV), avec un libellé
  court et une icône (même liste que les tuiles ; inconnue → `generic`).
- `id` : unique pour la TV, distinct des ids de tuiles (forme `h1`, `h2`… recommandée, opaque pour la TV).
- `value` / `unit` : comme une tuile `info` (`value` peut être `null`).
- **`changes`** livre aussi les changements de ces éléments, dans la même liste
  (`{"tile": "h1", "value": "18"}`) : la TV met à jour l'élément dont l'`id` correspond.
- Un changement de la configuration du bandeau modifie `revision`. Absent ou vide : pas de bandeau.
- Aucune action possible sur un élément du bandeau (`exec` → 404).

## Tuile `select` (liste de choix)

Pour un mode de clim, une source de chauffe, une ventilation… : une commande action de
sous-type `select` (rôle `set`) et, facultative, l'info de son état (rôle `state`).

```json
{"id": "t50", "type": "select", "name": "Salle à manger · Mode clim", "icon": "thermostat",
 "confirm": false, "value": "cold", "unit": "",
 "choices": [{"value": "auto", "label": "Auto"}, {"value": "cold", "label": "Froid"},
             {"value": "heat", "label": "Chauffage"}]}
```

- `choices` : tirés de la liste de la commande (`listValue` « valeur|Libellé;… »), dans l'ordre.
- `value` : valeur brute de l'état ; la TV affiche le libellé du choix correspondant (sinon la valeur).
- TV : OK ouvre le choix (◀ ▶ ou ▲ ▼ parcourent, OK envoie `set` avec la valeur choisie, Retour annule).
- `exec` `set` avec une valeur absente de `choices` → 422.

## Durée d'affichage d'un message (`notify`)

L'ordre `notify` peut porter un champ facultatif **`duration`** (secondes, 3 à 120) : durée du
bandeau sur la TV. Absent : durée par défaut de la TV (environ 8 s).

Côté Jeedom, la commande `Message` lit un marqueur **`[durée=<s>]`** dans le titre ou le message
(retiré du texte affiché), comme `[image=…]`.

## Icônes Material Design (`mdi:`)

Partout où le contrat parle d'icône pour la barre d'état et les notifications, la valeur peut être
un nom **Material Design Icons** préfixé : `mdi:weather-rainy`, `mdi:lightbulb`, `mdi:gate-open`…
La TV embarque la police MDI et dessine l'icône correspondante ; un nom inconnu donne une icône
générique. Une valeur sans préfixe `mdi:` est lue comme `mdi:<valeur>`.

## Barre d'état (`status`)

Petite barre permanente, **affichée par-dessus toutes les applications** (fenêtre ni focusable ni
tactile), avec l'heure et des indicateurs. Elle remplace l'horloge et les « indicateurs » de
TvOverlay. Le plugin calcule tout ; la TV ne fait qu'afficher.

`layout` porte un champ facultatif **`status`** :

```json
"status": {
  "corner": "bottom_start",
  "clock": true,
  "opacity": 85,
  "items": [
    {"id": "meteo", "icon": "mdi:weather-rainy", "text": "18°", "iconColor": "#FFFFFF",
     "textColor": "#FFFFFF", "borderColor": "#FFFFFF", "backgroundColor": "#00000000", "shape": "rounded"},
    {"id": "porte", "icon": "mdi:lock-open-variant", "text": "", "iconColor": "#FFA726",
     "textColor": "#FFFFFF", "borderColor": "#FFA726", "backgroundColor": "#00000000", "shape": "circle"}
  ]
}
```

- `corner` : `bottom_start` (défaut), `bottom_end`, `top_start`, `top_end`. `clock` : afficher l'heure.
  `opacity` : 0–100, opacité de la barre (0 = barre masquée).
- `items` : dans l'ordre d'affichage. `text` peut être vide (icône seule). Couleurs `#RRGGBB` ou
  `#AARRGGBB`. `shape` : `circle`, `rounded`, `rectangular`.
- Absent ou `null` : pas de barre. `items` vide avec `clock` : l'heure seule.
- **`changes`** porte un champ facultatif `status` (même forme, **état complet**) dès que la barre
  change ; la TV le remplace tel quel. La barre **n'entre pas** dans `revision` (elle change souvent).
- Dans `changes`, **`"status": null`** signifie que la barre est **retirée** (désactivée côté
  Jeedom), alors qu'un champ `status` **absent** signifie qu'elle est **inchangée**.

## Notifications riches (`notify` étendu)

En plus de `title`, `message`, `image`, `duration`, l'ordre `notify` accepte, tous facultatifs :

L'`id` de l'ordre reste l'entier croissant de « Transport » (un `id` déjà traité est ignoré) :
l'identifiant de la notification voyage donc à part, dans **`tag`**. Par tolérance, la TV lit aussi
un `id` **texte** non numérique comme `tag` (l'ordre n'a alors pas d'`id` d'ordre).


| Champ | Effet |
|---|---|
| `tag` | Identifiant de la notification (texte, l'`id` TvOverlay) : un nouveau `notify` avec le même `tag` **remplace** celui affiché, ou prend la place de celui qui attend ; `dismiss` (`target` = ce `tag`) le retire, affiché ou en attente. |
| `icon`, `iconColor` | Icône `mdi:` (et sa couleur) dans une pastille à gauche du titre : en grand sur la carte texte, en petit sur le texte incrusté de la carte image ou vidéo. Sans `icon`, la carte texte montre une cloche. |
| `corner` | `top_end` (défaut), `top_start`, `bottom_end`, `bottom_start` : position du bandeau par-dessus une autre application. |
| `video` | URL d'un flux vidéo (`rtsp://`, `http(s)://…m3u8`) joué **en direct, sans le son**, dans une petite fenêtre du bandeau ; l'`image`, si présente, sert d'attente et de repli. |

**File d'attente.** Une notification qui arrive pendant qu'une autre est affichée attend son tour ;
chacune reste affichée toute sa durée, comptée depuis son affichage réel. Trois au plus attendent :
au-delà, la plus ancienne en attente est abandonnée, comme celle qui a attendu plus que sa propre
durée et plus de 30 s. Une notification affichée change de surface avec l'écran (application,
panneau, superposition) en gardant son temps restant. Les questions (`ask`) ne passent pas par
cette file. Une image qui ne se télécharge pas laisse la carte texte.

## Vidéo dans une question (`ask.video`)

L'ordre `ask` accepte aussi `video` (même forme) : la vidéo en direct remplace la photo à gauche de la
question ; l'`image`, si présente, sert d'attente et de repli.

## Côté Jeedom : compatibilité TvOverlay

Le plugin crée sur chaque TV, en plus de `Message` et `Question` :

| Commande | Type | Effet |
|---|---|---|
| `Notifier (JSON)` | action / message | Message = objet JSON **au format TvOverlay** (`id`, `title`, `message`, `image`, `largeIcon`, `smallIcon`, `smallIconColor`, `video`, `corner`, `duration`, `source`) → ordre `notify` |
| `Indicateur (JSON)` | action / message | Objet JSON **au format TvOverlay** (`id` obligatoire, `message`, `icon`, `iconColor`, `messageColor`, `borderColor`, `backgroundColor`, `shape`, `expiration`, `visible`) → indicateur temporaire ajouté à la barre jusqu'à son expiration (`visible:false` le retire) |
| `Retirer une notification` | action / message | Message = `id` → ordre `dismiss` |
| `Retirer un indicateur` | action / message | Message = `id` |

**Sources vidéo.** Le plugin garde une liste de sources nommées (nom → URL complète, identifiants
compris), saisie une seule fois. Partout où une vidéo est attendue (`video` du JSON, marqueur
`[video=<nom>]` dans `Message` et `Question`), un **nom** de source est remplacé par son URL ; une URL
complète reste acceptée. Les URL ne sont jamais écrites dans les journaux.

## Page « tableau des trains » (`board`)

Un écran plein, à part des pages de tuiles : les prochains départs d'un ou deux trajets SNCB,
avec retard, suppression et voie, comme le tableau d'une gare. Le plugin le remplit à partir des
trajets du plugin **SNCB/NMBS** (`sncbnmbs`, fonction `board()`) : ni le plugin TV ni la TV
n'interrogent iRail.

Une page de `layout` porte deux champs facultatifs :

| Champ | Type | Remarque |
|---|---|---|
| `type` | string | `tiles` (défaut, champ absent) ou `board`. Un type inconnu : la TV ignore la page. |
| `hidden` | bool | `true` : la page n'apparaît pas dans les onglets ni dans la navigation ◀ ▶ ; seuls `show` et `keys` l'ouvrent. Absent = `false`. Vaut pour tout type de page. |

Une page `board` a `tiles` vide et un champ **`board`** :

```json
{"id": "p7", "name": "Trains", "type": "board", "hidden": true, "tiles": [],
 "board": {
   "sections": [
     {"id": "b1", "title": "Soignies → Bruxelles", "day": "", "updated": "07:12",
      "notes": ["Grève nationale le 12/10"],
      "trains": [
        {"time": "07:09", "real": "07:13", "delay": 4, "vehicle": "IC 1706", "direction": "Tongres",
         "platform": "1", "platformChanged": false, "transfers": 0, "status": "delayed", "next": true},
        {"time": "07:38", "real": "07:38", "delay": 0, "vehicle": "IC 3707",
         "direction": "Brussels Airport - Zaventem", "platform": "", "platformChanged": false,
         "transfers": 0, "status": "canceled", "next": false}
      ]},
     {"id": "b2", "title": "Braine-le-Comte → Soignies", "day": "Demain", "updated": "19:21",
      "notes": [], "trains": []}
   ]
 }}
```

- `sections` : 1 à 3, dans l'ordre d'affichage. Chacune vient d'un équipement `sncbnmbs` choisi dans
  le plugin, avec un titre libre (défaut : le trajet du plugin SNCB).
- `day` : vide si les trains sont ceux d'aujourd'hui, sinon le jour affiché (`Demain`, `12/10`) ; la
  TV l'affiche à côté du titre. Le plugin ne garde que **les trains d'un seul jour** (le créneau en cours
  ou le prochain) et **retire ceux déjà partis** (`left`, ou heure réelle passée de plus d'une minute).
- `updated` : heure (`HH:MM`) de la dernière lecture réussie d'iRail par le plugin SNCB ; vide si inconnue.
- `notes` : textes affichés sous la section, au plus 2 : d'abord un avertissement si les horaires sont
  périmés (« Horaires non relus depuis 07:02 ») ou jamais lus, puis les perturbations du réseau qui
  concernent le trajet et les alertes du prochain train.
- `trains` : au plus 6. `time` heure prévue, `real` heure réelle, `delay` retard au départ en minutes
  (entier ≥ 0), `platform` vide si inconnue, `platformChanged` voie inhabituelle, `transfers` nombre de
  correspondances, `next` prochain train à prendre (au plus un par section, jamais un train supprimé).
- `status` : `ontime`, `slight` (retard sous le seuil du trajet), `delayed` (retard au seuil ou plus),
  `canceled`. Inconnu → `ontime`.
- `trains` vide : la TV écrit « Aucun train ». Équipement SNCB absent ou désactivé : section avec
  `trains` vide et une `notes` qui l'explique.
- Le texte vient d'iRail : la TV l'affiche tel quel, sans l'interpréter.

**Météo (`weather`).** Facultatif : la météo du matin en tête du tableau (« faut-il un parapluie ? »),
lue par le plugin dans le cache du plugin **Météo Belgique IRM** (`meteobelgiqueirm`, fonction
`tvWeather()`) ; ni le plugin TV ni la TV n'interrogent l'IRM. Absent : pas de météo (aucun équipement
IRM choisi pour la page).

```json
"weather": {"city": "Soignies", "temp": 14, "icon": "mdi:weather-cloudy", "condition": "Couvert",
            "tmin": 7, "tmax": 21, "rain": "Risque de précipitations dans 2h40",
            "warning": "Vigilance jaune — Brouillard", "warningLevel": 1, "updated": "06:40", "note": "",
            "hours": [{"h": "09", "icon": "mdi:weather-cloudy", "temp": 15, "rain": 0},
                      {"h": "12", "icon": "mdi:weather-cloudy", "temp": 18, "rain": 30},
                      {"h": "15", "icon": "mdi:weather-rainy", "temp": 20, "rain": 90}]}
```

- `temp` : température extérieure observée (°C, nombre ou `null`). `icon` (`mdi:…`, vide si inconnue) et
  `condition` : le temps qu'il fait. `tmin` / `tmax` : températures prévues du jour (nombre ou `null`).
- `rain` : la pluie à venir en une phrase, telle que l'IRM la donne ; vide s'il n'y a rien à signaler.
- `warning` : vigilance en cours (libellé), vide sinon ; `warningLevel` 0 (aucune) à 3 (rouge).
- `hours` : 0 à 6 colonnes pour le reste de la journée (ou les heures à venir, le soir), dans l'ordre :
  `h` heure de début (`HH`), `icon`, `temp` (°C ou `null`), `rain` risque de pluie en % (0 à 100 ou
  `null`), le pire de l'intervalle couvert par la colonne.
- `updated` : heure (`HH:MM`) de la dernière lecture réussie de l'IRM ; vide si inconnue.
- `note` : vide, ou un avertissement à afficher : données non relues depuis plus d'une heure
  (« Météo non relue depuis 06:40 »), équipement IRM supprimé ou désactivé, plugin IRM absent. Avec
  une `note` seule, les autres champs peuvent manquer ; tout champ absent vaut vide ou `null`.
- Textes : au plus 120 caractères, sans caractère de contrôle.

**Mises à jour.** Le contenu de `board` (trains et météo) **n'entre pas** dans `revision` (il change
chaque minute) ; seule la configuration de la page (sections choisies, titres, équipement météo,
`hidden`) y entre. `changes` porte un
champ facultatif **`boards`** : objet `{<id de page>: <board complet>}` pour chaque page `board` dont le
contenu a changé ; la TV remplace le tableau tel quel. Absent : aucun tableau n'a changé. Le plugin
recalcule les tableaux au moins une fois par minute pendant l'attente longue.

**Côté TV.** Écran plein façon tableau de gare : pour chaque section, son titre (et `day`), puis une
ligne par train — heure, train, direction, état (« à l'heure », « +4 min » avec l'heure réelle,
« Supprimé » barré), voie (en couleur d'alerte si `platformChanged`) ; ▶ devant le prochain train ;
« 1 corresp. » si `transfers` > 0. En bas : « vérifié à `updated` » et l'heure. Avec `weather`,
une bande de deux lignes en tête : le temps actuel, `tmin`/`tmax`, `rain` (en bleu, avec un parapluie)
et `warning` (en couleur d'alerte), puis une case par colonne de `hours`, le risque de pluie en bleu sous
la température (marqué à partir de 50 %) ; `note` en couleur d'alerte. Faute de place, la TV montre moins de trains par section. Aucune action :
Retour ferme l'écran (retour à l'écran ou à l'application précédente, comme la fin de `show`).
Ouvert par `show` : toujours **l'application elle-même au premier plan**, en plein écran (jamais le
panneau par-dessus la vidéo), et à la fin de `duration` retour à l'application d'avant. Une touche de
couleur qui vise la page l'ouvre, elle, dans le panneau. À la mise en veille de l'écran, le tableau se
ferme (retour à l'écran ou à l'application d'avant).

**Côté Jeedom.** La commande `Afficher <nom de page>` existe aussi pour une page cachée
(`Afficher Trains`) ; c'est elle qu'on appelle depuis un scénario ou l'API HTTP de Jeedom.
`exec` sur une page `board` → 404 (elle n'a pas de tuile).

## Présence humaine et allumage (centre d'info)

But : que Jeedom puisse allumer la TV pour une info, puis l'éteindre, **sans jamais déranger
ni éteindre quelqu'un qui la regarde**. La TV est seule à savoir, à la seconde, si un humain s'en
sert : c'est elle qui le mesure et le remonte, et c'est elle qui choisit la forme d'un ordre au
moment de l'afficher (un ordre met jusqu'à 25 s à arriver). Le plugin, lui, décide s'il a le droit
de réveiller la TV (présence, mode de l'alarme, heure).

Tout est facultatif et additif : une application sans ces champs reste conforme (le plugin les
ignore), un plugin sans ces champs n'envoie que des ordres au comportement actuel.

### Signaux mesurés par la TV

| Signal | Moyen côté Android | Octroi (une fois, par ADB) |
|---|---|---|
| Touche de la télécommande | `AccessibilityService` qui **observe** les touches (`flagRequestFilterKeyEvents`) : aucune touche n'est consommée pour la présence ; seules les touches de couleur associées à une page restent captées (voir « Touches de couleur ») | `settings put secure enabled_accessibility_services …` |
| Application au premier plan | le même service (`TYPE_WINDOW_STATE_CHANGED`), à défaut `UsageStatsManager` | `appops set be.jeedomtv GET_USAGE_STATS allow` |
| Média en lecture | `MediaSessionManager.getActiveSessions` (`PlaybackState.STATE_PLAYING`) via un `NotificationListenerService` ; repli `AudioManager.isMusicActive()` | `cmd notification allow_listener …` |
| Écran de veille | `ACTION_DREAMING_STARTED` / `STOPPED` | — |
| Écran | `PowerManager.isInteractive`, `ACTION_SCREEN_ON` / `OFF` | — |

Un signal dont la permission manque est déclaré absent (`capabilities`), jamais deviné.

### `state` : champs ajoutés

```json
{"screenOn": true, "inUse": true, "idleSeconds": 12, "foreground": "com.netflix.ninja",
 "playing": true, "dreaming": false, "wokeBy": "jeedom", "humanTookOver": true,
 "capabilities": {"keys": true, "foreground": true, "media": true, "wake": true, "sleep": true}}
```

- `foreground` : paquet de l'application au premier plan (`be.jeedomtv` pour nous), `null` si inconnu.
- `playing` : une session média est en lecture ; sans accès aux sessions (`capabilities.media = false`),
  repli sur « du son sort » (`AudioManager.isMusicActive()`), moins précis. `null` si inconnu.
- `dreaming` : l'écran de veille (Ambiant) est actif.
- `idleSeconds` : secondes écoulées depuis la dernière touche de télécommande, au moment de l'envoi ;
  sans touche depuis l'allumage de l'écran, depuis cet allumage. `null` **seulement** si les touches ne
  sont pas observables (`capabilities.keys = false`). Le plugin en déduit l'heure de la dernière touche
  (heure de réception − `idleSeconds`).
- `inUse` : **un humain se sert de la TV**. Calculé par la TV :
  `screenOn` et non `dreaming`, et au moins l'un de :
  - une touche depuis moins de **10 min** ;
  - `playing` ;
  - au premier plan, une application autre que `be.jeedomtv`, le lanceur (`com.google.android.apps.tv.launcherx`
    et les lanceurs du fabricant) et l'écran de veille — une chaîne TV, une entrée HDMI ou une application
    en pause comptent donc comme « en usage ».
  Écran éteint : `false`. **Exception :** `wokeBy = jeedom` et `humanTookOver = false` → `false`, quelle que
  soit l'application revenue au premier plan au réveil (sur un téléviseur, l'application de l'entrée TV
  revient souvent d'elle-même : personne ne la regarde pour autant).
- `wokeBy` : qui a allumé l'écran la dernière fois — `jeedom`, `human`, ou `null` (écran éteint, ou inconnu
  car l'application a démarré écran allumé). Voir « Propriété de l'allumage ».
- `humanTookOver` : depuis un allumage par Jeedom, un humain a pris la main (touche, ou **début** de
  lecture d'un média). Un changement d'application seul ne compte pas : au réveil, le téléviseur rouvre
  souvent de lui-même l'application de l'entrée TV. Reste `true` jusqu'à la prochaine mise en veille.
  `false` si `wokeBy` vaut `human` ou `null` (sans objet).
- `capabilities` : ce que la TV sait réellement mesurer ou faire, selon les permissions accordées :
  `keys`, `foreground`, `media` (signaux), `wake` (allumer l'écran), `sleep` (le mettre en veille).
  Envoyé au démarrage et à chaque changement. `sleep` n'est `true` que si un moyen est réellement
  disponible (administrateur de l'appareil actif **et** `android.software.device_admin` déclaré), et
  repasse à `false` dès qu'une mise en veille a échoué (voir `lastSleep`), jusqu'au redémarrage de
  l'application. Le plugin passe alors par un autre chemin.
- `lastSleep` : `{"id": <id de l'ordre sleep>, "result": "done" | "refused" | "failed"}`, envoyé dès que
  l'ordre `sleep` est traité. `refused` : règle d'or fausse (sans `force`). `failed` : la mise en veille a
  été tentée mais l'écran est encore allumé 5 s après.

**Quand envoyer.** À chaque changement de `inUse`, `foreground`, `playing`, `dreaming`, `wokeBy`,
`humanTookOver` ou `capabilities` ; à la première touche après au moins 60 s sans touche ; et, tant que
l'écran est allumé et que des touches arrivent, au plus une fois par minute. Jamais à chaque touche.

### Propriété de l'allumage

La TV tient une petite machine à états, remise à zéro à chaque mise en veille de l'écran :

1. L'écran s'allume **parce que l'application l'a réveillé** (ordre avec `wake`) → `wokeBy = jeedom`,
   `humanTookOver = false`.
2. L'écran s'allume sans demande de l'application (télécommande, CEC, Google Home) → `wokeBy = human`…
   (la touche Marche qui a allumé l'écran n'est pas vue par l'application : c'est voulu, la règle 3 en
   tient compte)
3. … **sauf** si un ordre avec `wake` arrive dans les **60 s** qui suivent cet allumage, sans aucune touche
   entre-temps : l'allumage est alors attribué à Jeedom (`wokeBy = jeedom`). C'est le cas où le plugin a
   réveillé la TV par un autre chemin (veille profonde, application injoignable) puis envoie l'ordre.
4. Après `wokeBy = jeedom`, la première touche ou le premier début de lecture d'un média →
   `humanTookOver = true`. Les 2 s qui suivent un réveil sont
   ignorées (événements parasites du CEC).

**Règle d'or : la TV ne se met en veille d'elle-même que si `wokeBy = jeedom` et `humanTookOver = false`,
et qu'aucune question (`ask`) n'est affichée.**

### Ordres : champs ajoutés

| Ordre | Champ | Effet |
|---|---|---|
| `show` | `mode` : `full` (défaut) ou `auto` | `full` : comportement actuel. `auto` : comme `full` si la TV n'est **pas** `inUse` au moment de l'afficher ; sinon **aucun changement d'application** : la TV montre à la place une notification discrète (non focalisable) faite de `fallback`, pendant `fallback.duration` (défaut 15 s). |
| `show` | `fallback` : `{title, message, icon, duration}` | Contenu de la version discrète (`mode: auto`). Absent : titre = nom de la page, sans message. |
| `show`, `notify`, `ask` | `wake` : booléen (défaut `false`) | Écran éteint ou en veille : la TV l'allume (`setTurnScreenOn`, `FLAG_TURN_SCREEN_ON`), puis exécute l'ordre ; voir « Propriété de l'allumage ». Écran déjà allumé : sans effet. Sans permission `wake` : l'ordre est exécuté s'il peut l'être, l'état le dit. |
| `show` | `sleepAfter` : booléen (défaut `false`) | À la fin de `duration` : si la règle d'or est vraie, mise en veille au lieu du retour à l'application d'avant ; sinon retour normal. |

Nouveaux ordres :

| `type` | Champs | Effet |
|---|---|---|
| `wake` | — | Allume l'écran (comme `wake: true`) sans rien afficher d'autre. |
| `sleep` | `force` : booléen (défaut `false`) | Met l'écran en veille **si la règle d'or est vraie** ; sinon ne fait rien (et le dit dans l'état suivant). `force: true` : veille sans condition (« Je pars »). Moyen : `DevicePolicyManager.lockNow()` (administrateur de l'appareil), repli `AccessibilityService.performGlobalAction(GLOBAL_ACTION_LOCK_SCREEN)`. |

Un ordre `wake` n'atteint la TV que si l'application tourne et que le réseau est actif en veille ; en
veille profonde, c'est au plugin de réveiller la TV par un autre chemin, puis d'envoyer son ordre
(règle 3).

### Côté Jeedom

Commandes de l'équipement TV :

| Commande | Type | Effet |
|---|---|---|
| `Info maison` | action / message | Une info de la maison (voir ci-dessous). |
| `Retirer info` | action / message | Message = clé de l'info : bandeau et indicateur retirés (`dismiss`) ; sa page encore à l'écran est quittée (`exit`) si personne n'a pris la main ; la TV est rendue à la veille si Jeedom l'avait allumée pour elle. |
| `Allumer l’écran` | action / other | Ordre `wake` ; TV injoignable ou sans permission `wake` : équipement Google TV associé (`Allumer`), puis `wake`. |
| `Éteindre si libre` | action / other | Ordre `sleep` (règle d'or appliquée par la TV) ; la TV ne sait pas se mettre en veille : équipement Google TV (`Éteindre`), seulement si la TV est joignable, observe les touches (`capabilities.keys`) et que la règle d'or est vraie sur son dernier état — sinon rien (dans le doute, on n'éteint pas). `lastSleep.result = failed` : repli sur l'équipement Google TV. |
| `Éteindre` | action / other | Pareil, avec `force`, sans condition ; aucun moyen d'éteindre : erreur. |
| `En usage`, `Reprise en main`, `Lecture en cours` | info / binary | Derniers `inUse`, `humanTookOver`, `playing` reçus. |
| `Allumée par`, `Application au premier plan` | info / string | Derniers `wokeBy`, `foreground` reçus (vide si `null`). |
| `Infos actives` | info / numeric | Nombre d'infos encore valables. |

**Info maison.** Titre et message libres ; réglages en marqueurs dans le message, retirés du texte :

| Marqueur | Défaut | Rôle |
|---|---|---|
| `[priorité=urgente\|importante\|info]` | `info` | `urgente` : allume la TV, même en heures calmes ; `importante` : l'allume si la condition de la maison est vraie et hors heures calmes ; `info` : ne l'allume jamais, attend qu'elle soit allumée. |
| `[clé=…]` | tirée du titre | Une info de même clé remplace la précédente ; identique, elle est seulement prolongée (rien n'est réaffiché). |
| `[pendant=<min>]` ou `[jusqu'à=HH:MM]` | 10 min | Validité, au plus 24 h. |
| `[page=<nom ou id>]` | — | Avec page : `show` en `mode: auto` (plein écran si la TV est libre, sinon bandeau `fallback`), durée = validité restante. Sans page : `notify` étiqueté `info-<clé>`, plus un indicateur temporaire de la barre d'état jusqu'à la fin de validité. |

Réglages de l'équipement : équipement Google TV associé (`powerEq`), condition pour allumer (expression
Jeedom, noms lisibles acceptés, vide = toujours vraie), heures calmes. Un champ de présence illisible est
ignoré (avertissement au journal), jamais une raison de refuser l'état. Une info en attente retente sa
chance chaque minute (fin des heures calmes, condition devenue vraie) et dès que la TV signale son écran
allumé. `[jusqu'à=HH:MM]` déjà passé vise le lendemain. Une info montrée en réveillant l'écran rend la TV à la
veille (`Éteindre si libre`) quand plus aucune info n'est active. Une info en attente (écran éteint,
réveil non permis) part dès que la TV signale son écran allumé. Les scénarios existants ne changent pas :
sans `Info maison`, le plugin n'allume ni n'éteint jamais rien de lui-même.

### Installation (permissions)

L'application fournit un script ADB unique qui accorde, puis vérifie, toutes les permissions ci-dessus
plus l'affichage par-dessus (`SYSTEM_ALERT_WINDOW`), l'administrateur de l'appareil et l'exemption
d'économie d'énergie (`dumpsys deviceidle whitelist +be.jeedomtv`). À relancer après chaque
`adb install -r`, qui peut réinitialiser les autorisations. L'écran de configuration de la TV affiche
l'état de chaque permission.
