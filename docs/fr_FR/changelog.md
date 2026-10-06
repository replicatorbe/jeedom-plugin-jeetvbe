# Changelog Jeedom TV

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
