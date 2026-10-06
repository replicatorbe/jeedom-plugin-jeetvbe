# Changelog Jeedom TV

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
