# Jeedom TV changelog

The detailed changelog is in French: [docs/fr_FR/changelog.md](../fr_FR/changelog.md).

## 1.1

- "Toutes les TV" (all TVs) broadcast device, created by the plugin: Message,
  Notifier (JSON), Retirer une notification, Indicateur (JSON), Retirer un
  indicateur replayed on every TV. Notifications only reach TVs that are
  online with the screen on; temporary indicators reach every TV. Video
  sources are resolved per TV.
- Per-TV option "Recevoir les diffusions" (receive broadcasts), on by default.
- No broadcast Question.

## 1.0

Jeedom TV replaces TvOverlay.

- Status bar on top of any app (clock, indicators), computed by the plugin:
  automatic indicators with TvOverlay's `auto_fixed` model (import from a
  TvOverlay device, or `jeetvbe::importTvOverlayIndicators()`), temporary
  indicators from "Indicateur (JSON)", "Retirer un indicateur".
- Rich notifications: "Notifier (JSON)" in TvOverlay's format (tag, mdi icon,
  corner, duration, image, video), "Retirer une notification" (dismiss).
- Named video sources per TV, masked addresses; `[video=<name>]` in Message
  and Question.

## 0.9.1

Code review fixes, no contract change: only the most recent `changes` request
of a TV takes the orders (an abandoned long poll could swallow one); a
duplicated TV gets its own key; a deleted TV takes its queue, pending question
and images with it; the `Afficher Scénarios` command is kept while a scenario
group is set; `Afficher` command names compared without case or accents; image
purge no longer removes an image being copied; bounded JSON bodies and no
internal error details or key fragments in API answers and logs; editor flags
tiles without a command and drops hidden roles on type change.

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
