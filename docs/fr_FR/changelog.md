# Changelog Jeedom TV

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
