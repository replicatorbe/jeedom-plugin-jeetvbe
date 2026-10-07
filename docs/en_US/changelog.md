# Jeedom TV changelog

The detailed changelog is in French: [docs/fr_FR/changelog.md](../fr_FR/changelog.md).

## 0.9

- "select" tile (choice list) on a list action command, choices read from its
  list values on every load; thermostat/air-conditioning mode generated from
  `THERMOSTAT_SET_MODE`.
- `[durée=<s>]` marker in the `Message` command sets how long the TV banner
  stays (3 to 120 s).

## 0.8

- Info banner: up to 6 info commands per TV (label, icon) always shown at the
  top of the TV screen, served in `header` of the layout and updated live.
- New icons `sun`, `rain`, `trash`, `power`.

## 0.7

- "button" tile: runs a chosen action command with fixed options (title,
  message, value, choice, colour) passed according to the command subtype;
  optional state.
- "camera" icon.
- Colour keys: one page per remote colour key, set on the TV tab and served in
  `keys` of the layout; they work on top of any app once the app's
  accessibility service is enabled.

## 0.6

- Images attached to the `Message` and `Question` commands.

## 0.5

- Automatic "Scénarios" page from a scenario group.
- Brightness slider for dimmable lights at generation.
- "Version app" info, filled by the TV.
- English translation of the configuration page, Jeedom CI workflow.
