# Jeedom TV (`jeetvbe`)

Control the house from an Android TV with the remote. The plugin exposes to the
**Jeedom TV** app pages of tiles (lights, plugs, shutters, set-points,
temperatures, scenarios, buttons). The full documentation is in French:
[docs/fr_FR/index.md](../fr_FR/index.md); the API contract is
[docs/api.md](../api.md).

## Quick start

1. *Plugins → Multimedia → Jeedom TV*, **Add a TV**, name it, **Save**: the key
   is generated on save.
2. **Pages and tiles** tab: **Generate from generic types**, check the rooms,
   choose grouping by type or by room, **Generate pages**, review, **Save**.
3. In the app, enter the **API URL** and the **key** shown on the TV tab.

## Main features

- One device per TV, each with its own key; the TV only ever names tiles,
  never Jeedom command ids.
- Pages generated from generic types (lights with brightness slider, shutters,
  thermostat set-points, temperatures, plugs), editable afterwards.
- Optional automatic "Scénarios" page listing the active scenarios of a group.
- "Button" tiles running any action command with fixed options (for example
  a "Cameras" page on the CameraOnTv "Afficher <camera>" commands).
- Choice-list tiles (air-conditioning or thermostat mode…), generated from
  `THERMOSTAT_SET_MODE`.
- Info banner: up to 6 info commands (outdoor temperature, bin collection,
  solar power, alarm…) always shown at the top of the TV screen, updated live.
- Colour keys: each remote colour key opens a chosen page, on top of any app,
  once the app's accessibility service has been enabled (TV settings →
  Accessibility, or adb).
- Commands to drive the TV from scenarios: show a page, message, question
  (scenario "Ask" block), quit; info commands online, visible, screen on,
  displayed page, app version.
- Live updates by long polling, no daemon.
