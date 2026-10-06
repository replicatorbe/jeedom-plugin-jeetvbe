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
| `name` | string | |
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
 "changes": [{"tile": "t1", "value": "0"}, {"tile": "t4", "value": "21"}]}
```

- Plusieurs changements d'une même tuile sont fusionnés (dernière valeur).
- Si `revision` diffère de celle du layout affiché, la TV recharge le layout.
- Après une erreur réseau, la TV recharge le layout (des changements ont pu être perdus),
  puis reprend `changes` avec `since` absent.
