# Jeedom TV (`jeetvbe`)

[![Tests](https://github.com/replicatorbe/jeedom-plugin-jeetvbe/actions/workflows/tests.yml/badge.svg?branch=beta)](https://github.com/replicatorbe/jeedom-plugin-jeetvbe/actions/workflows/tests.yml)

Plugin Jeedom qui expose à une application Android TV des pages de tuiles
(lumières, prises, volets, consignes, températures, scénarios), pilotables à la
télécommande. Un équipement = une TV, avec sa propre clé.

- Contrat d'API entre le plugin et l'application : [`docs/api.md`](docs/api.md).
  Il fait foi pour les deux côtés ; toute évolution change d'abord ce fichier.
- Documentation : [`docs/fr_FR/index.md`](docs/fr_FR/index.md).

## En images

L'application TV [Jeedom TV](https://github.com/replicatorbe/JeedomTvGoogleBE)
affiche les pages servies par ce plugin (captures avec une maison de démonstration,
données fictives) :

![Pages et bandeau d'infos](https://raw.githubusercontent.com/replicatorbe/JeedomTvGoogleBE/main/docs/captures/01-lumieres.png)

| Par-dessus la télé | Question d'un scénario (bloc « Demander ») avec photo |
|---|---|
| ![Panneau](https://raw.githubusercontent.com/replicatorbe/JeedomTvGoogleBE/main/docs/captures/07-panneau-par-dessus-la-tele.jpg) | ![Sonnette](https://raw.githubusercontent.com/replicatorbe/JeedomTvGoogleBE/main/docs/captures/08-sonnette-avec-photo.jpg) |

D'autres captures dans le [README de l'application](https://github.com/replicatorbe/JeedomTvGoogleBE#en-images).

## Organisation

| Fichier | Rôle |
|---|---|
| `core/class/jeetvbeLayout.class.php` | Logique pure, sans Jeedom : normalisation des pages, révision, layout, résolution des actions, bornage, fusion des changements, génération depuis les types génériques. |
| `core/class/jeetvbe.class.php` | L'équipement (une TV) : clé, lecture des commandes, exécution, attente longue. |
| `core/php/api.php` | Le point d'entrée de la TV. |
| `core/ajax/jeetvbe.ajax.php` | Régénération de la clé, génération, noms lisibles, aperçu. |
| `desktop/` | Page de configuration et éditeur de pages. |
| `core/i18n/en_US.json` | Traduction anglaise (page, JavaScript, ajax, commandes). |
| `tests/run.php` | Jeu d'essai hors ligne de la logique pure. |
| `tools/make-icon.php` | Fabrique `plugin_info/jeetvbe_icon.png`. |

## Développement

```bash
php tests/run.php                                         # hors ligne
/home/smug/dev/tools/test-all.sh /home/smug/dev/jeedom-plugin-jeetvbe
/home/smug/dev/tools/deploy-plugin.sh                     # après chaque modification
```

Licence AGPL — sMug (Jérôme Fafchamps).
