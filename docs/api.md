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
| `id` | string | Stable tant que la configuration ne change pas. Unique pour la TV (pas seulement dans la page). |
| `type` | string | `switch`, `shutter`, `slider`, `info`, `scene`. Un type inconnu doit être affiché comme `info` par la TV. |
| `name` | string | Peut prendre la forme « Pièce · Nom » (séparateur ` · `, pages par type) : la TV affiche alors la pièce en petit au-dessus du nom. |
| `icon` | string | `light`, `plug`, `shutter`, `thermostat`, `temperature`, `scene`, `fan`, `lock`, `alarm`, `generic`. Inconnu → `generic`. |
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
{"visible": true, "screenOn": true, "page": "p2"}
```

- `visible` : l'application est au premier plan (sinon la TV affiche une autre application).
- `screenOn` : l'écran de la TV est allumé (sinon veille).
- `page` : id de la page affichée, `null` hors écran des pages (configuration, chargement).

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

Configuration de l'équipement : « Durée d'affichage par défaut » en secondes (défaut 30 ; 0 = sans retour).
Les commandes `Afficher <page>` suivent les pages : créées, renommées ou supprimées à l'enregistrement.

### Transport

Les ordres sont mis en file sur l'équipement et livrés dans `commands` de la réponse `changes`
suivante (une seule fois ; la file est vidée à la livraison). Un ordre non livré au bout de
**60 s** est abandonné : une TV éteinte ne doit pas afficher une page périmée à son réveil.

| `type` | Champs | Effet sur la TV |
|---|---|---|
| `show` | `page` (id), `duration` (s, 0 = sans retour) | Affiche la page (sélection sur la première tuile), passe au premier plan si besoin. Après `duration`, retour à l'écran ou à l'application précédente, sauf si l'utilisateur a touché la télécommande entre-temps. |
| `notify` | `title` (peut être vide), `message` | Bandeau d'environ 8 s si l'application est visible ; ignoré sinon. |
| `exit` | — | L'application passe en arrière-plan (retour au programme TV). |

`id` : entier croissant par TV ; la TV ignore un `id` déjà traité. Un `type` inconnu est ignoré.

La TV garde la boucle `changes` active en arrière-plan (service au premier plan), tant
qu'elle est configurée, pour recevoir les ordres même pendant un film.
