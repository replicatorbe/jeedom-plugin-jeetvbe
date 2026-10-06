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

## Pages et tuiles

Chaque page a un nom ; chaque tuile a :

| Champ | Rôle |
|---|---|
| Nom | Affiché sur la tuile. |
| Type | `switch` (interrupteur), `shutter` (volet), `slider` (curseur), `info`, `scene` (scénario). |
| Icône | `light`, `plug`, `shutter`, `thermostat`, `temperature`, `scene`, `fan`, `lock`, `alarm`, `generic`. |
| Confirmation | La TV demande confirmation avant toute action. |
| Commandes | Des rôles, chacun choisi avec le sélecteur de commande de Jeedom. |
| Scénario | Pour une tuile `scene`, choisi avec le sélecteur de scénario. |
| Min, max, pas | Pour `shutter` (position) et `slider`. |

Rôles utiles par type :

| Type | Rôles | Actions de la TV |
|---|---|---|
| `switch` | État, On, Off, Bascule | `on`, `off`, `toggle` |
| `shutter` | État, Monter, Descendre, Stop, Régler | `up`, `down`, `stop`, `set` |
| `slider` | État, Régler | `set` |
| `info` | État | aucune |
| `scene` | (scénario) | `run` |

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

## Ordres de Jeedom vers la TV

Le plugin crée sur chaque TV des commandes utilisables dans les scénarios :

| Commande | Type | Effet |
|---|---|---|
| `Afficher <page>` (une par page) | action | Affiche la page, puis revient à l'écran précédent après la durée par défaut. |
| `Afficher page` | action / message | Titre : id ou nom de la page (casse ignorée). Message : durée en secondes (vide = durée par défaut, `0` = sans retour). |
| `Message` | action / message | Bandeau d'environ 8 s sur la TV (si l'application est visible). Titre facultatif. |
| `Quitter` | action | L'application passe en arrière-plan. |
| `Question` | action / message | Question à choix, pour le bloc « Demander » des scénarios (voir plus bas). |
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
| `ENERGY_*` (état, on, off) | `switch`, icône prise |
| `FLAP_*` | `shutter` ; position si `FLAP_SLIDER` (0–100, pas de 10), état si `FLAP_STATE` ou `FLAP_BSO_STATE` |
| `THERMOSTAT_SET_SETPOINT` + `THERMOSTAT_SETPOINT` | `slider` « Consigne … », bornes de la commande sinon 15–25, pas de 0,5 |
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
| `GET layout` | Pages, tuiles, valeurs actuelles et révision. |
| `POST exec` | `{"tile": "t2", "action": "set", "value": 40}` |
| `GET changes&since=<curseur>` | Attente longue (25 s au plus) des changements de valeur et des ordres de Jeedom (`commands`). |
| `POST state` | `{"visible": true, "screenOn": true, "page": "p2"}` : état de la TV. |
| `POST answer` | `{"ask": "<jeton>", "answer": "Oui"}` : réponse à une question. |

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
