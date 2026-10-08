/* This file is part of Jeedom. Licence AGPL — voir LICENSE.
 *
 * Page de configuration d'une TV : clé, URL de l'API, et l'éditeur de pages et
 * de tuiles. Le modèle (jeetvbeModel) est la seule source : chaque saisie y est
 * recopiée, chaque changement de structure redessine l'éditeur à partir de lui,
 * et saveEqLogic() le place dans configuration.pages.
 *
 * Les pages sont chargées en AJAX : les écouteurs sont posés à la racine du
 * script, sur des éléments de la page (recréés à chaque navigation).
 */

var jeetvbeModel = []
/* Touches de couleur : couleur => id de page ('' = aucune). */
var jeetvbeKeys = {}
/* Bandeau d'infos : [{id, cmd, label, icon}], 6 au plus. */
var jeetvbeHeader = []
/* Copie depuis une autre TV : touches de couleur par nom de page, résolues à l'enregistrement. */
var jeetvbeKeysByName = null
var JEETVBE_HEADER_MAX = 6
var jeetvbeNames = { cmds: {}, scenarios: {} }

var JEETVBE_TYPE_LABELS = {
  switch: '{{Interrupteur}}', shutter: '{{Volet}}', slider: '{{Curseur}}', info: '{{Information}}', scene: '{{Scénario}}', button: '{{Bouton}}', select: '{{Liste de choix}}'
}
var JEETVBE_ICON_LABELS = {
  light: '{{Lumière}}', plug: '{{Prise}}', shutter: '{{Volet}}', thermostat: '{{Thermostat}}', temperature: '{{Température}}',
  scene: '{{Scène}}', fan: '{{Ventilateur}}', lock: '{{Serrure}}', alarm: '{{Alarme}}', camera: '{{Caméra}}', sun: '{{Soleil}}', rain: '{{Pluie}}', trash: '{{Poubelle}}', power: '{{Énergie}}', generic: '{{Générique}}'
}
var JEETVBE_ROLE_LABELS = {
  state: '{{État}}', on: '{{On}}', off: '{{Off}}', toggle: '{{Bascule}}', up: '{{Monter}}', down: '{{Descendre}}', stop: '{{Stop}}', set: '{{Régler}}', press: '{{Commande}}'
}
var JEETVBE_DEFAULT_ICON = { switch: 'light', shutter: 'shutter', slider: 'thermostat', info: 'temperature', scene: 'scene', button: 'generic', select: 'thermostat' }
/* Options fixes d'un bouton : les champs utiles au sous-type de sa commande. */
var JEETVBE_BUTTON_FIELDS = { message: ['title', 'message'], slider: ['slider'], select: ['select'], color: ['color'], other: [] }
var JEETVBE_OPTION_LABELS = { title: '{{Titre}}', message: '{{Message}}', slider: '{{Valeur}}', select: '{{Choix}}', color: '{{Couleur}}' }
var JEETVBE_OPTION_HINTS = { title: '{{facultatif}}', message: '{{texte ou JSON}}', slider: '{{nombre}}', select: '{{valeur de la liste}}', color: '#RRGGBB' }

function jeetvbeEscape(_text) {
  var holder = document.createElement('div')
  holder.textContent = (_text === null || typeof _text === 'undefined') ? '' : String(_text)
  return holder.innerHTML.replace(/"/g, '&quot;')
}

function jeetvbeMarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

function jeetvbeAjax(_action, _data, _success) {
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/jeetvbe/core/ajax/jeetvbe.ajax.php',
    data: Object.assign({ action: _action }, _data || {}),
    dataType: 'json',
    error: function (request, status, error) {
      domUtils.handleAjaxError(request, status, error)
    },
    success: function (data) {
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        return
      }
      _success(data.result)
    }
  })
}

function jeetvbeSensitive(_text) {
  var folded = String(_text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
  for (var i = 0; i < jeetvbeSensitiveWords.length; i++) {
    if (folded.indexOf(jeetvbeSensitiveWords[i]) !== -1) { return true }
  }
  return false
}

/* Une tuile du modèle, toujours complète : cmds en objet (le PHP rend [] quand
   il est vide), bornes en chaîne pour les champs. */
function jeetvbeCleanTile(_tile) {
  var tile = Object.assign({ id: '', type: 'switch', name: '', icon: 'generic', confirm: false, cmds: {}, scenario_id: null, min: null, max: null, step: null }, _tile || {})
  if (!tile.cmds || Array.isArray(tile.cmds)) { tile.cmds = {} }
  if (tile.type === 'button' && (!tile.options || typeof tile.options !== 'object' || Array.isArray(tile.options))) { tile.options = {} }
  return tile
}

/* Une page du modèle : type (tiles ou board), hidden, et pour un tableau
   des trains ses trajets [{eqLogic, title}]. */
function jeetvbeCleanPages(_pages) {
  var pages = []
  if (!Array.isArray(_pages)) { return pages }
  _pages.forEach(function (page) {
    if (!page || typeof page !== 'object') { return }
    var clean = { id: page.id || '', name: page.name || '', type: page.type === 'board' ? 'board' : 'tiles', hidden: page.hidden === true || page.hidden === 1 || page.hidden === '1',
      tiles: (Array.isArray(page.tiles) ? page.tiles : []).map(jeetvbeCleanTile) }
    if (clean.type === 'board') {
      clean.sections = (Array.isArray(page.sections) ? page.sections : []).filter(function (section) {
        return section && typeof section === 'object'
      }).map(function (section) {
        return { eqLogic: section.eqLogic ? parseInt(section.eqLogic) : null, title: section.title || '' }
      })
    }
    pages.push(clean)
  })
  return pages
}

/* Le nom lisible d'un trajet SNCB choisi. */
function jeetvbeSncbLabel(_eq) {
  return _eq.name + (_eq.route ? ' (' + _eq.route + ')' : '') + (_eq.enabled ? '' : ' — {{désactivé}}')
}

/* Les trajets d'un tableau des trains : un équipement SNCB/NMBS et un titre
   libre chacun, 1 à 3. */
function jeetvbeBoardHtml(_p, _page) {
  var where = ' data-page="' + _p + '"'
  var html = ''
  if (jeetvbeSncbEqs === null || typeof jeetvbeSncbEqs === 'undefined') {
    html += '<div class="alert alert-warning" style="margin:0 0 6px 0;">{{Le plugin SNCB/NMBS n\'est pas installé ou pas actif : le tableau affichera « horaires indisponibles ».}}</div>'
  }
  var eqs = Array.isArray(jeetvbeSncbEqs) ? jeetvbeSncbEqs : []
  _page.sections.forEach(function (section, s) {
    var sWhere = where + ' data-section="' + s + '"'
    var found = false
    var options = '<option value="">{{Choisir un trajet…}}</option>'
    eqs.forEach(function (eq) {
      var selected = (eq.id === section.eqLogic)
      if (selected) { found = true }
      options += '<option value="' + eq.id + '"' + (selected ? ' selected' : '') + '>' + jeetvbeEscape(jeetvbeSncbLabel(eq)) + '</option>'
    })
    if (section.eqLogic && !found) {
      options += '<option value="' + section.eqLogic + '" selected>#' + section.eqLogic + ' ({{introuvable}})</option>'
    }
    var route = ''
    eqs.forEach(function (eq) { if (eq.id === section.eqLogic) { route = eq.route } })
    html += '<div class="jeetvbeSection">'
    html += '<span class="jeetvbeTileId" title="{{Identifiant de la section}}">b' + (s + 1) + '</span>'
    html += '<select class="form-control input-sm' + (section.eqLogic && !found ? ' jeetvbeMissing' : '') + '" data-field="sectionEq"' + sWhere + '>' + options + '</select>'
    html += '<input class="form-control input-sm" maxlength="64" data-field="sectionTitle"' + sWhere + ' value="' + jeetvbeEscape(section.title) + '" placeholder="' + jeetvbeEscape(route || '{{Titre (défaut : le trajet)}}') + '">'
    html += '<span style="flex:1"></span>'
    html += '<a class="btn btn-default btn-xs" data-action="sectionUp"' + sWhere + (s === 0 ? ' disabled' : '') + ' title="{{Monter}}"><i class="fas fa-arrow-up"></i></a>'
    html += '<a class="btn btn-default btn-xs" data-action="sectionDown"' + sWhere + (s === _page.sections.length - 1 ? ' disabled' : '') + ' title="{{Descendre}}"><i class="fas fa-arrow-down"></i></a>'
    html += '<a class="btn btn-danger btn-xs" data-action="sectionRemove"' + sWhere + ' title="{{Retirer ce trajet}}"><i class="fas fa-trash"></i></a>'
    html += '</div>'
    if (!section.eqLogic) {
      html += '<div class="help-block jeetvbeMissing" style="margin:0 0 6px 0;"><i class="fas fa-exclamation-triangle"></i> {{Aucun trajet choisi : cette ligne ne sera pas enregistrée.}}</div>'
    }
  })
  if (_page.sections.length === 0) {
    html += '<div class="help-block jeetvbeMissing" style="margin:0 0 6px 0;"><i class="fas fa-exclamation-triangle"></i> {{Aucun trajet : ajoutez-en un (trois au plus).}}</div>'
  }
  return html
}

/* Les noms lisibles des commandes et scénarios référencés, demandés en une fois. */
function jeetvbeFetchNames(_then) {
  var cmdIds = []
  var scenarioIds = []
  jeetvbeModel.forEach(function (page) {
    page.tiles.forEach(function (tile) {
      Object.keys(tile.cmds).forEach(function (role) {
        var id = tile.cmds[role]
        if (id && !(id in jeetvbeNames.cmds)) { cmdIds.push(id) }
      })
      if (tile.scenario_id && !(tile.scenario_id in jeetvbeNames.scenarios)) { scenarioIds.push(tile.scenario_id) }
    })
  })
  jeetvbeHeader.forEach(function (item) {
    if (item.cmd && !(item.cmd in jeetvbeNames.cmds)) { cmdIds.push(item.cmd) }
  })
  if (cmdIds.length === 0 && scenarioIds.length === 0) {
    if (_then) { _then() }
    return
  }
  jeetvbeAjax('describe', { cmd_ids: JSON.stringify(cmdIds), scenario_ids: JSON.stringify(scenarioIds) }, function (result) {
    Object.keys(result.cmds || {}).forEach(function (id) { jeetvbeNames.cmds[id] = result.cmds[id] })
    Object.keys(result.scenarios || {}).forEach(function (id) { jeetvbeNames.scenarios[id] = result.scenarios[id] })
    if (_then) { _then() }
  })
}

function jeetvbeCmdLabel(_id) {
  if (!_id) { return '' }
  var known = jeetvbeNames.cmds[_id]
  if (known === null) { return '#' + _id + '# ({{introuvable}})' }
  return known ? known.human : '#' + _id + '#'
}

function jeetvbeScenarioLabel(_id) {
  if (!_id) { return '' }
  var known = jeetvbeNames.scenarios[_id]
  if (known === null) { return '#' + _id + ' ({{introuvable}})' }
  return known ? known.human : 'scénario ' + _id
}

function jeetvbeOptions(_values, _labels, _selected) {
  return _values.map(function (value) {
    return '<option value="' + value + '"' + (value === _selected ? ' selected' : '') + '>' + jeetvbeEscape(_labels[value] || value) + '</option>'
  }).join('')
}

/* Les rôles sans lesquels une tuile ne fait rien sur la TV (l'un d'eux suffit). */
var JEETVBE_REQUIRED_ROLES = { switch: ['on', 'off', 'toggle'], shutter: ['up', 'down', 'set'], slider: ['set'], info: ['state'], button: ['press'], select: ['set'] }

function jeetvbeTileIncomplete(_tile) {
  if (_tile.type === 'scene') { return !_tile.scenario_id }
  var required = JEETVBE_REQUIRED_ROLES[_tile.type] || []
  return required.length > 0 && !required.some(function (role) { return _tile.cmds[role] })
}

function jeetvbeTileHtml(_p, _t, _tile, _count) {
  var where = ' data-page="' + _p + '" data-tile="' + _t + '"'
  var html = '<div class="jeetvbeTile"' + where + '>'
  html += '<div class="jeetvbeTileRow">'
  html += '<span class="jeetvbeTileId" title="{{Identifiant de la tuile}}">' + jeetvbeEscape(_tile.id || '{{nouv.}}') + '</span>'
  html += '<input class="form-control input-sm" style="width:220px;" data-field="name"' + where + ' value="' + jeetvbeEscape(_tile.name) + '" placeholder="{{Nom}}">'
  html += '<select class="form-control input-sm" style="width:130px;" data-field="type"' + where + '>' + jeetvbeOptions(jeetvbeTypes, JEETVBE_TYPE_LABELS, _tile.type) + '</select>'
  html += '<select class="form-control input-sm" style="width:130px;" data-field="icon"' + where + '>' + jeetvbeOptions(jeetvbeIcons, JEETVBE_ICON_LABELS, _tile.icon) + '</select>'
  html += '<label class="checkbox-inline"><input type="checkbox" data-field="confirm"' + where + (_tile.confirm ? ' checked' : '') + '> {{Confirmation}}</label>'
  html += '<span style="flex:1"></span>'
  html += '<a class="btn btn-default btn-xs" data-action="tileUp"' + where + (_t === 0 ? ' disabled' : '') + ' title="{{Monter}}"><i class="fas fa-arrow-up"></i></a>'
  html += '<a class="btn btn-default btn-xs" data-action="tileDown"' + where + (_t === _count - 1 ? ' disabled' : '') + ' title="{{Descendre}}"><i class="fas fa-arrow-down"></i></a>'
  html += '<a class="btn btn-danger btn-xs" data-action="tileRemove"' + where + ' title="{{Supprimer la tuile}}"><i class="fas fa-trash"></i></a>'
  html += '</div>'

  html += '<div class="jeetvbeTileRow">'
  if (_tile.type === 'scene') {
    var scenarioMissing = _tile.scenario_id && jeetvbeNames.scenarios[_tile.scenario_id] === null
    html += '<span class="jeetvbeRole"><span class="jeetvbeRoleName">{{Scénario}}</span>'
    html += '<input class="form-control input-sm' + (scenarioMissing ? ' jeetvbeMissing' : '') + '" readonly value="' + jeetvbeEscape(jeetvbeScenarioLabel(_tile.scenario_id)) + '">'
    html += '<a class="btn btn-default btn-xs" data-action="pickScenario"' + where + ' title="{{Choisir}}"><i class="fas fa-list-alt"></i></a>'
    html += '<a class="btn btn-default btn-xs" data-action="clearScenario"' + where + ' title="{{Retirer}}"><i class="fas fa-times"></i></a></span>'
  } else {
    (jeetvbeTypeRoles[_tile.type] || []).forEach(function (role) {
      var id = _tile.cmds[role]
      var missing = id && jeetvbeNames.cmds[id] === null
      var roleWhere = where + ' data-role="' + role + '"'
      html += '<span class="jeetvbeRole"><span class="jeetvbeRoleName">' + JEETVBE_ROLE_LABELS[role] + '</span>'
      html += '<input class="form-control input-sm' + (missing ? ' jeetvbeMissing' : '') + '" readonly value="' + jeetvbeEscape(jeetvbeCmdLabel(id)) + '" title="' + jeetvbeEscape(jeetvbeCmdLabel(id)) + '">'
      html += '<a class="btn btn-default btn-xs" data-action="pickCmd"' + roleWhere + ' title="{{Choisir la commande}}"><i class="fas fa-list-alt"></i></a>'
      html += '<a class="btn btn-default btn-xs" data-action="clearCmd"' + roleWhere + ' title="{{Retirer}}"><i class="fas fa-times"></i></a></span>'
    })
  }
  html += '</div>'
  if (jeetvbeTileIncomplete(_tile)) {
    html += '<div class="jeetvbeTileRow"><span class="help-block jeetvbeMissing" style="margin:0;"><i class="fas fa-exclamation-triangle"></i> '
      + (_tile.type === 'scene' ? '{{Aucun scénario choisi : la tuile ne fera rien sur la TV.}}' : '{{Aucune commande choisie : la tuile ne fera rien sur la TV.}}') + '</span></div>'
  }

  if (_tile.type === 'button') {
    html += jeetvbeButtonOptionsHtml(where, _tile)
  }
  if (_tile.type === 'select') {
    html += '<div class="jeetvbeTileRow"><span class="help-block" style="margin:0;">{{Régler : une commande action de type liste (select). Les choix proposés sur la TV sont ceux de sa liste de valeurs, relus à chaque chargement. État facultatif.}}</span></div>'
  }
  if (_tile.type === 'shutter' || _tile.type === 'slider') {
    html += '<div class="jeetvbeTileRow jeetvbeBound">'
    ;['min', 'max', 'step'].forEach(function (field) {
      var value = (_tile[field] === null || typeof _tile[field] === 'undefined') ? '' : _tile[field]
      html += '<label>' + { min: '{{Min}}', max: '{{Max}}', step: '{{Pas}}' }[field] + '</label>'
      html += '<input type="number" step="any" class="form-control input-sm" data-field="' + field + '"' + where + ' value="' + jeetvbeEscape(value) + '" placeholder="{{auto}}">'
    })
    html += '<span class="help-block" style="margin:0;">' + (_tile.type === 'shutter'
      ? '{{Un volet n\'a de position (min/max) que s\'il a une commande « Régler ». Vide : 0–100, pas de 10.}}'
      : '{{Vide : les bornes de la commande « Régler », sinon 0–100, pas de 1. La valeur envoyée est ramenée dans [min, max].}}') + '</span>'
    html += '</div>'
  }
  html += '</div>'
  return html
}

/* Les champs d'options d'un bouton, selon le sous-type de la commande choisie. */
function jeetvbeButtonOptionsHtml(_where, _tile) {
  var id = _tile.cmds.press
  var known = id ? jeetvbeNames.cmds[id] : null
  var html = '<div class="jeetvbeTileRow jeetvbeOptions">'
  if (!id) {
    html += '<span class="help-block" style="margin:0;">{{Choisissez la commande à exécuter. L\'état est facultatif : sans lui, la TV affiche ▶.}}</span>'
  } else if (!known || !known.subType) {
    html += '<span class="help-block" style="margin:0;">{{Sous-type de la commande inconnu.}}</span>'
  } else {
    var fields = JEETVBE_BUTTON_FIELDS[known.subType] || []
    if (fields.length === 0) {
      html += '<span class="help-block" style="margin:0;">{{Cette commande ne prend pas d\'option.}}</span>'
    }
    fields.forEach(function (field) {
      var value = (_tile.options && _tile.options[field] !== undefined && _tile.options[field] !== null) ? _tile.options[field] : ''
      html += '<label>' + JEETVBE_OPTION_LABELS[field] + '</label>'
      html += '<input class="form-control input-sm" style="width:' + (field === 'message' ? '360' : '160') + 'px;" data-field="option" data-option="' + field + '"' + _where
        + ' value="' + jeetvbeEscape(value) + '" placeholder="' + jeetvbeEscape(JEETVBE_OPTION_HINTS[field]) + '">'
    })
  }
  html += '</div>'
  return html
}

/* Les listes des touches de couleur : « Aucune », les pages enregistrées
   (une page nouvelle n'a pas encore d'id) et la page dynamique des scénarios
   si un groupe est renseigné. Redessinées à chaque changement de pages. */
function jeetvbeRenderKeys() {
  var group = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="scenarioGroup"]')
  var pages = jeetvbeModel.filter(function (page) { return page.id }).map(function (page) {
    return { id: page.id, name: page.name + (page.hidden ? ' ({{cachée}})' : '') }
  })
  if (group !== null && group.value.trim() !== '') { pages.push({ id: 'scenes', name: '{{Scénarios (page automatique)}}' }) }
  document.querySelectorAll('select.jeetvbeKey').forEach(function (select) {
    var color = select.getAttribute('data-color')
    var current = jeetvbeKeys[color] || ''
    var html = '<option value="">{{Aucune}}</option>'
    var found = false
    pages.forEach(function (page) {
      if (page.id === current) { found = true }
      html += '<option value="' + jeetvbeEscape(page.id) + '"' + (page.id === current ? ' selected' : '') + '>' + jeetvbeEscape(page.name || page.id) + '</option>'
    })
    if (current !== '' && !found) {
      html += '<option value="' + jeetvbeEscape(current) + '" selected>' + jeetvbeEscape(current) + ' ({{introuvable}})</option>'
    }
    select.innerHTML = html
  })
}

/* Les lignes du bandeau, redessinées depuis jeetvbeHeader. */
function jeetvbeRenderHeader() {
  var box = document.getElementById('div_jeetvbeHeader')
  if (box === null) { return }
  var html = ''
  jeetvbeHeader.forEach(function (item, h) {
    var where = ' data-header="' + h + '"'
    var missing = item.cmd && jeetvbeNames.cmds[item.cmd] === null
    html += '<div class="jeetvbeHeaderRow">'
    html += '<span class="jeetvbeTileId" title="{{Identifiant de l\'info}}">' + jeetvbeEscape(item.id || '{{nouv.}}') + '</span>'
    html += '<input class="form-control input-sm' + (missing ? ' jeetvbeMissing' : '') + '" style="width:300px;" readonly value="' + jeetvbeEscape(jeetvbeCmdLabel(item.cmd)) + '" placeholder="{{Commande info}}">'
    html += '<a class="btn btn-default btn-xs" data-header-action="pick"' + where + ' title="{{Choisir la commande}}"><i class="fas fa-list-alt"></i></a>'
    html += '<input class="form-control input-sm" style="width:180px;" maxlength="24" data-header-field="label"' + where + ' value="' + jeetvbeEscape(item.label) + '" placeholder="{{Libellé}}">'
    html += '<select class="form-control input-sm" style="width:130px;" data-header-field="icon"' + where + '>' + jeetvbeOptions(jeetvbeIcons, JEETVBE_ICON_LABELS, item.icon) + '</select>'
    html += '<a class="btn btn-default btn-xs" data-header-action="up"' + where + (h === 0 ? ' disabled' : '') + ' title="{{Monter}}"><i class="fas fa-arrow-up"></i></a>'
    html += '<a class="btn btn-default btn-xs" data-header-action="down"' + where + (h === jeetvbeHeader.length - 1 ? ' disabled' : '') + ' title="{{Descendre}}"><i class="fas fa-arrow-down"></i></a>'
    html += '<a class="btn btn-danger btn-xs" data-header-action="remove"' + where + ' title="{{Supprimer}}"><i class="fas fa-trash"></i></a>'
    html += '</div>'
  })
  box.innerHTML = html
  var add = document.getElementById('bt_jeetvbeAddHeader')
  if (add !== null) {
    if (jeetvbeHeader.length >= JEETVBE_HEADER_MAX) { add.setAttribute('disabled', '') } else { add.removeAttribute('disabled') }
  }
}

function jeetvbeRender() {
  jeetvbeRenderKeys()
  jeetvbeRenderHeader()
  var container = document.getElementById('div_jeetvbePages')
  if (container === null) { return }
  if (jeetvbeModel.length === 0) {
    container.innerHTML = '<div class="alert alert-warning">{{Aucune page. Ajoutez-en une, ou générez-les depuis les types génériques.}}</div>'
    return
  }
  var html = ''
  jeetvbeModel.forEach(function (page, p) {
    var where = ' data-page="' + p + '"'
    html += '<div class="jeetvbePage">'
    html += '<div class="jeetvbePageHead">'
    html += '<span class="jeetvbeTileId" title="{{Identifiant de la page}}">' + jeetvbeEscape(page.id || '{{nouv.}}') + '</span>'
    var board = page.type === 'board'
    html += '<input class="form-control input-sm" data-field="pageName"' + where + ' value="' + jeetvbeEscape(page.name) + '" placeholder="{{Nom de la page}}">'
    html += '<select class="form-control input-sm" data-field="pageType"' + where + ' title="{{Type de page}}">'
      + '<option value="tiles"' + (board ? '' : ' selected') + '>{{Tuiles}}</option>'
      + '<option value="board"' + (board ? ' selected' : '') + '>{{Tableau des trains}}</option></select>'
    html += '<label class="checkbox-inline" title="{{Ni onglet ni navigation sur la TV : seules la commande Afficher et une touche de couleur l\'ouvrent}}"><input type="checkbox" data-field="pageHidden"' + where + (page.hidden ? ' checked' : '') + '> {{Cachée}}</label>'
    html += board
      ? '<span class="label label-default">' + page.sections.length + ' {{trajet(s)}}</span>'
      : '<span class="label label-default">' + page.tiles.length + ' {{tuile(s)}}</span>'
    html += '<span style="flex:1"></span>'
    html += board
      ? '<a class="btn btn-success btn-xs" data-action="sectionAdd"' + where + (page.sections.length >= jeetvbeBoardSectionsMax ? ' disabled' : '') + '><i class="fas fa-plus"></i> {{Trajet}}</a>'
      : '<a class="btn btn-success btn-xs" data-action="tileAdd"' + where + '><i class="fas fa-plus"></i> {{Tuile}}</a>'
    html += '<a class="btn btn-default btn-xs" data-action="pageUp"' + where + (p === 0 ? ' disabled' : '') + ' title="{{Monter la page}}"><i class="fas fa-arrow-up"></i></a>'
    html += '<a class="btn btn-default btn-xs" data-action="pageDown"' + where + (p === jeetvbeModel.length - 1 ? ' disabled' : '') + ' title="{{Descendre la page}}"><i class="fas fa-arrow-down"></i></a>'
    html += '<a class="btn btn-danger btn-xs" data-action="pageRemove"' + where + ' title="{{Supprimer la page}}"><i class="fas fa-trash"></i></a>'
    html += '</div>'
    if (board) {
      html += jeetvbeBoardHtml(p, page)
    } else {
      page.tiles.forEach(function (tile, t) {
        html += jeetvbeTileHtml(p, t, tile, page.tiles.length)
      })
    }
    html += '</div>'
  })
  container.innerHTML = html
}

function jeetvbeRenderObjects() {
  var box = document.getElementById('div_jeetvbeObjects')
  if (box === null) { return }
  box.innerHTML = jeetvbeObjects.map(function (object) {
    return '<label class="checkbox-inline"><input type="checkbox" class="jeetvbeObject" value="' + object.id + '"> '
      + '&nbsp;'.repeat(object.depth * 2) + jeetvbeEscape(object.name) + '</label>'
  }).join('')
}

function jeetvbeSwap(_list, _a, _b) {
  if (_a < 0 || _b < 0 || _a >= _list.length || _b >= _list.length) { return }
  var keep = _list[_a]
  _list[_a] = _list[_b]
  _list[_b] = keep
}

/* ============================================================ ÉQUIPEMENT */

function printEqLogic(_eqLogic) {
  var configuration = init(_eqLogic.configuration, {})
  /* « Toutes les TV » : pas une TV, seulement ses commandes. */
  var broadcastEq = _eqLogic.logicalId === 'broadcast' || configuration.role === 'broadcast'
  document.querySelectorAll('.jeetvbeTvOnly').forEach(function (_el) { _el.style.display = broadcastEq ? 'none' : '' })
  var broadcastInfo = document.getElementById('div_jeetvbeBroadcastInfo')
  if (broadcastInfo !== null) { broadcastInfo.style.display = broadcastEq ? '' : 'none' }
  /* Option cochée par défaut tant qu'elle n'a jamais été enregistrée. */
  var receive = document.getElementById('cb_jeetvbeBroadcast')
  if (receive !== null && (configuration.broadcast === undefined || configuration.broadcast === null || configuration.broadcast === '')) { receive.checked = true }
  jeetvbeModel = jeetvbeCleanPages(configuration.pages)
  jeetvbeHeader = (Array.isArray(configuration.header) ? configuration.header : []).filter(function (item) {
    return item && typeof item === 'object'
  }).map(function (item) {
    return { id: item.id || '', cmd: item.cmd || null, label: item.label || '', icon: item.icon || 'generic' }
  })
  /* Jamais enregistrées : rouge = première page. */
  jeetvbeKeys = {}
  if (configuration.keys === undefined || configuration.keys === null || configuration.keys === '') {
    if (jeetvbeModel.length > 0 && jeetvbeModel[0].id) { jeetvbeKeys.red = jeetvbeModel[0].id }
  } else if (typeof configuration.keys === 'object') {
    ['red', 'green', 'yellow', 'blue'].forEach(function (color) {
      if (configuration.keys[color]) { jeetvbeKeys[color] = String(configuration.keys[color]) }
    })
  }
  var url = document.getElementById('span_jeetvbeApiUrl')
  if (url !== null) { url.textContent = jeetvbeApiUrl }
  var preview = document.getElementById('pre_jeetvbePreview')
  if (preview !== null) { preview.style.display = 'none'; preview.textContent = '' }
  jeetvbeRenderObjects()
  jeetvbeRender()
  jeetvbeFetchNames(jeetvbeRender)
  jeetvbeShowStatus(_eqLogic)
  jeetvbePrintStatusBar(configuration)
  jeetvbeLoadVideos(_eqLogic)
  jeetvbeKeysByName = null
  jeetvbeLoadCopySources(_eqLogic)
}

/* Les autres TV, pour « Copier depuis cette TV ». */
function jeetvbeLoadCopySources(_eqLogic) {
  var box = document.getElementById('span_jeetvbeCopy')
  if (box === null) { return }
  box.style.display = 'none'
  if (!isset(_eqLogic.id) || _eqLogic.id == '') { return }
  jeetvbeAjax('otherTvs', { id: _eqLogic.id }, function (_list) {
    document.getElementById('sel_jeetvbeCopySource').innerHTML = (_list || []).map(function (_tv) {
      return '<option value="' + _tv.id + '">' + jeetvbeEscape(_tv.name) + '</option>'
    }).join('')
    box.style.display = (_list && _list.length > 0) ? '' : 'none'
  })
}

/* Version de l'application et dernier appel : ce que la TV a signalé. */
function jeetvbeShowStatus(_eqLogic) {
  var version = document.getElementById('span_jeetvbeAppVersion')
  var seen = document.getElementById('span_jeetvbeLastSeen')
  if (version === null || seen === null) { return }
  version.textContent = '-'
  seen.textContent = '-'
  if (!isset(_eqLogic.id) || _eqLogic.id == '') { return }
  jeetvbeAjax('status', { id: _eqLogic.id }, function (status) {
    version.textContent = status.appVersion ? status.appVersion : '{{inconnue}}'
    var screens = document.getElementById('span_jeetvbeScreensOn')
    if (screens !== null) {
      screens.innerHTML = (status.screensOn === '')
        ? '<span class="label label-success">{{oui}}</span>'
        : '<span class="label label-default">{{non}}</span> ' + jeetvbeEscape(status.screensOn)
    }
    var orders = document.querySelector('#table_jeetvbeOrders tbody')
    if (orders !== null) {
      orders.innerHTML = (status.orders && status.orders.length > 0) ? status.orders.map(function (_h) {
        var o = _h.order || {}
        var detail = Object.keys(o).filter(function (_k) { return _k !== 'id' && _k !== 'type' }).map(function (_k) {
          return _k + '=' + (typeof o[_k] === 'object' ? JSON.stringify(o[_k]) : String(o[_k]))
        }).join(', ')
        return '<tr><td style="white-space:nowrap;">' + jeetvbeEscape(_h.at) + '</td><td><b>' + jeetvbeEscape(o.type || '') + '</b></td><td>' + jeetvbeEscape(detail) + '</td></tr>'
      }).join('') : '<tr><td class="text-muted">{{aucun}}</td></tr>'
    }
    if (!status.lastSeen) {
      seen.textContent = '{{jamais}}'
      return
    }
    seen.innerHTML = jeetvbeEscape(status.lastSeen) + ' ' + (status.online == 1
      ? '<span class="label label-success">{{en ligne}}</span>'
      : '<span class="label label-default">{{hors ligne}}</span>')
  })
}

function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  _eqLogic.configuration.pages = jeetvbeModel
  var keys = {}
  ;['red', 'green', 'yellow', 'blue'].forEach(function (color) { keys[color] = jeetvbeKeys[color] || '' })
  _eqLogic.configuration.keys = keys
  /* Une ligne sans commande n'est pas gardée (le plugin l'écarterait). */
  _eqLogic.configuration.header = jeetvbeHeader.filter(function (item) { return item.cmd })
  if (jeetvbeKeysByName !== null) { _eqLogic.configuration.keysByName = jeetvbeKeysByName }
  _eqLogic.configuration.statusBar = jeetvbeReadStatusBar()
  _eqLogic.configuration.indicators = jeetvbeIndRead()
  return _eqLogic
}

/* ============================================================ BARRE D'ÉTAT */

function jeetvbeEl(_id) { return document.getElementById(_id) }

function jeetvbeCurrentId() {
  var field = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return field ? field.value : ''
}

function jeetvbePrintStatusBar(_configuration) {
  var bar = (_configuration.statusBar && typeof _configuration.statusBar === 'object' && !Array.isArray(_configuration.statusBar)) ? _configuration.statusBar : {}
  if (jeetvbeEl('cb_jeetvbeBarEnabled') === null) { return }
  jeetvbeEl('cb_jeetvbeBarEnabled').checked = String(bar.enabled) === '1'
  jeetvbeEl('sel_jeetvbeBarCorner').value = (jeetvbeBarCorners.indexOf(bar.corner) !== -1) ? bar.corner : 'bottom_start'
  jeetvbeEl('cb_jeetvbeBarClock').checked = (bar.clock === undefined || bar.clock === null || bar.clock === '') ? true : String(bar.clock) === '1'
  jeetvbeEl('in_jeetvbeBarOpacity').value = (bar.opacity === undefined || bar.opacity === null || bar.opacity === '') ? 85 : bar.opacity
  var root = jeetvbeEl('div_jeetvbeIndicators')
  root.innerHTML = ''
  ;(Array.isArray(_configuration.indicators) ? _configuration.indicators : []).forEach(function (_indicator) { jeetvbeIndAdd(_indicator) })
  jeetvbeEl('div_jeetvbeIndErrors').style.display = 'none'
  jeetvbeEl('pre_jeetvbeStatusPreview').style.display = 'none'
  jeetvbeLoadImportSources()
}

function jeetvbeReadStatusBar() {
  if (jeetvbeEl('cb_jeetvbeBarEnabled') === null) { return {} }
  var opacity = parseInt(jeetvbeEl('in_jeetvbeBarOpacity').value)
  return {
    enabled: jeetvbeEl('cb_jeetvbeBarEnabled').checked ? 1 : 0,
    corner: jeetvbeEl('sel_jeetvbeBarCorner').value,
    clock: jeetvbeEl('cb_jeetvbeBarClock').checked ? 1 : 0,
    opacity: isNaN(opacity) ? 85 : opacity
  }
}

/* Les champs qui n'ont de sens que pour un choix : data-if="clé=valeur".
   Cachés, ils gardent leur valeur. */
function jeetvbeIndToggle(_panel) {
  _panel.querySelectorAll('.jtvIndIf').forEach(function (_el) {
    var rule = _el.getAttribute('data-if').split('=')
    var field = _panel.querySelector('.jtvIndAttr[data-key="' + rule[0] + '"]')
    _el.style.display = (field !== null && field.value === rule[1]) ? '' : 'none'
  })
}

function jeetvbeIndAddCond(_panel, _condition) {
  var template = jeetvbeEl('tpl_jeetvbeIndCond')
  var body = _panel.querySelector('.jtvIndConds tbody')
  if (template === null || body === null) { return }
  var row = template.content.querySelector('tr').cloneNode(true)
  var condition = _condition || {}
  row.querySelectorAll('.jtvCondAttr').forEach(function (_field) {
    var key = _field.getAttribute('data-key')
    if (condition[key] !== undefined && condition[key] !== null) { _field.value = condition[key] }
  })
  body.appendChild(row)
}

function jeetvbeIndAdd(_indicator) {
  var template = jeetvbeEl('tpl_jeetvbeInd')
  var root = jeetvbeEl('div_jeetvbeIndicators')
  if (template === null || root === null) { return null }
  var panel = template.content.firstElementChild.cloneNode(true)
  var indicator = _indicator || {}
  panel.querySelectorAll('.jtvIndAttr').forEach(function (_field) {
    var key = _field.getAttribute('data-key')
    if (_field.type === 'checkbox') {
      _field.checked = indicator[key] === undefined || String(indicator[key]) !== '0'
    } else if (indicator[key] !== undefined && indicator[key] !== null) {
      _field.value = indicator[key]
    }
  })
  ;(Array.isArray(indicator.conditions) ? indicator.conditions : []).forEach(function (_c) { jeetvbeIndAddCond(panel, _c) })
  root.appendChild(panel)
  jeetvbeIndToggle(panel)
  return panel
}

function jeetvbeIndRead() {
  var list = []
  document.querySelectorAll('#div_jeetvbeIndicators .jeetvbeInd').forEach(function (_panel) {
    var indicator = {}
    _panel.querySelectorAll('.jtvIndAttr').forEach(function (_field) {
      indicator[_field.getAttribute('data-key')] = (_field.type === 'checkbox') ? (_field.checked ? 1 : 0) : _field.value
    })
    indicator.conditions = []
    _panel.querySelectorAll('.jtvIndCond').forEach(function (_row) {
      var condition = {}
      _row.querySelectorAll('.jtvCondAttr').forEach(function (_field) { condition[_field.getAttribute('data-key')] = _field.value })
      if (String(condition.cmd || '').trim() !== '') { indicator.conditions.push(condition) }
    })
    list.push(indicator)
  })
  return list
}

/* Une commande info : le champ reçoit son nom lisible, que le cœur convertit
   en #id# à l'enregistrement. */
function jeetvbeIndPick(_button) {
  var group = _button.closest('.input-group')
  var field = (group === null) ? null : group.querySelector('input')
  if (field === null) { return }
  jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (_result) {
    if (!_result || !_result.human) { return }
    field.value = _result.human
    jeetvbeMarkModified()
  })
}

/* Équipements TvOverlay dont on peut importer les indicateurs. */
function jeetvbeLoadImportSources() {
  var box = jeetvbeEl('span_jeetvbeImport')
  if (box === null) { return }
  jeetvbeAjax('tvOverlayCandidates', {}, function (_list) {
    var select = jeetvbeEl('sel_jeetvbeImportSource')
    select.innerHTML = (_list || []).map(function (_eq) {
      return '<option value="' + _eq.id + '">' + jeetvbeEscape(_eq.name) + ' (' + _eq.count + ' {{indicateur(s)}})</option>'
    }).join('')
    box.style.display = (_list && _list.length > 0) ? '' : 'none'
  })
}

/* ========================================================= SOURCES VIDÉO */

function jeetvbeRenderVideos(_list) {
  var body = document.querySelector('#table_jeetvbeVideos tbody')
  if (body === null) { return }
  if (!_list || _list.length === 0) {
    body.innerHTML = '<tr><td class="text-muted">{{Aucune source vidéo.}}</td></tr>'
    return
  }
  body.innerHTML = _list.map(function (_source) {
    return '<tr><td style="width:150px;"><b>' + jeetvbeEscape(_source.name) + '</b></td><td><code>' + jeetvbeEscape(_source.url) + '</code></td>'
      + '<td style="width:40px;"><a class="btn btn-danger btn-xs jeetvbeVideoRemove" data-name="' + jeetvbeEscape(_source.name) + '" title="{{Supprimer}}"><i class="fas fa-trash"></i></a></td></tr>'
  }).join('')
}

function jeetvbeLoadVideos(_eqLogic) {
  jeetvbeRenderVideos([])
  var url = jeetvbeEl('in_jeetvbeVideoUrl')
  if (url !== null) { url.value = '' }
  if (!isset(_eqLogic.id) || _eqLogic.id == '') { return }
  jeetvbeAjax('videoSources', { id: _eqLogic.id }, jeetvbeRenderVideos)
}

/* =============================================== ÉCOUTEURS : barre, vidéo */

var jeetvbeStatusTab = jeetvbeEl('statustab')
if (jeetvbeStatusTab !== null) {
  jeetvbeStatusTab.addEventListener('click', function (event) {
    var target = event.target
    var el
    if (target.closest('#bt_jeetvbeIndAdd') !== null) {
      var panel = jeetvbeIndAdd({ enable: 1, visibility: 'always', text_mode: 'none', icon_mode: 'fixed', shape: 'circle', expiration: '12h' })
      if (panel) { panel.scrollIntoView({ block: 'nearest' }) }
      jeetvbeMarkModified()
      return
    }
    if ((el = target.closest('.jtvIndRemove')) !== null) {
      el.closest('.jeetvbeInd').remove()
      jeetvbeMarkModified()
      return
    }
    if ((el = target.closest('.jtvIndUp')) !== null) {
      var up = el.closest('.jeetvbeInd')
      if (up.previousElementSibling) { up.parentNode.insertBefore(up, up.previousElementSibling); jeetvbeMarkModified() }
      return
    }
    if ((el = target.closest('.jtvIndDown')) !== null) {
      var down = el.closest('.jeetvbeInd')
      if (down.nextElementSibling) { down.parentNode.insertBefore(down.nextElementSibling, down); jeetvbeMarkModified() }
      return
    }
    if ((el = target.closest('.jtvIndCondAdd')) !== null) {
      jeetvbeIndAddCond(el.closest('.jeetvbeInd'), { operator: '==', value: '1' })
      jeetvbeMarkModified()
      return
    }
    if ((el = target.closest('.jtvIndCondRemove')) !== null) {
      el.closest('.jtvIndCond').remove()
      jeetvbeMarkModified()
      return
    }
    if ((el = target.closest('.jtvIndPick')) !== null) {
      jeetvbeIndPick(el)
      return
    }
    if (target.closest('#bt_jeetvbeImport') !== null) {
      var id = jeetvbeCurrentId()
      var source = jeetvbeEl('sel_jeetvbeImportSource').value
      if (!id || !source) { return }
      if (jeetvbeIndRead().length > 0 && !confirm('{{Remplacer les indicateurs de cette TV par ceux de TvOverlay ?}}')) { return }
      jeetvbeAjax('importTvOverlay', { id: id, source: source }, function (_list) {
        jeetvbeEl('div_jeetvbeIndicators').innerHTML = ''
        ;(_list || []).forEach(function (_indicator) { jeetvbeIndAdd(_indicator) })
        jeetvbeMarkModified()
        jeedomUtils.showAlert({ message: (_list || []).length + ' {{indicateur(s) importé(s). Relisez, puis sauvegardez. TvOverlay n\'est pas modifié.}}', level: 'success' })
      })
      return
    }
    if (target.closest('#bt_jeetvbeStatusPreview') !== null) {
      var pre = jeetvbeEl('pre_jeetvbeStatusPreview')
      if (pre.style.display !== 'none') { pre.style.display = 'none'; return }
      if (!jeetvbeCurrentId()) { return }
      jeetvbeAjax('statusPreview', { id: jeetvbeCurrentId() }, function (_result) {
        var errors = jeetvbeEl('div_jeetvbeIndErrors')
        errors.innerHTML = (_result.errors || []).map(jeetvbeEscape).join('<br>')
        errors.style.display = (_result.errors && _result.errors.length > 0) ? '' : 'none'
        pre.textContent = (_result.status === null) ? '{{Barre désactivée.}}' : JSON.stringify(_result.status, null, 2)
        pre.style.display = ''
      })
    }
  })
  var jeetvbeIndChanged = function (event) {
    var field = event.target
    if (field && field.classList && (field.classList.contains('jtvIndAttr') || field.classList.contains('jtvCondAttr'))) {
      var panel = field.closest('.jeetvbeInd')
      if (panel !== null && event.type === 'change') { jeetvbeIndToggle(panel) }
      jeetvbeMarkModified()
    }
    if (field && field.id && ['cb_jeetvbeBarEnabled', 'sel_jeetvbeBarCorner', 'cb_jeetvbeBarClock', 'in_jeetvbeBarOpacity'].indexOf(field.id) !== -1) {
      jeetvbeMarkModified()
    }
  }
  jeetvbeStatusTab.addEventListener('change', jeetvbeIndChanged)
  jeetvbeStatusTab.addEventListener('input', jeetvbeIndChanged)
}

document.getElementById('bt_jeetvbeCopy')?.addEventListener('click', function () {
  var id = jeetvbeCurrentId()
  var source = document.getElementById('sel_jeetvbeCopySource').value
  if (!id || !source) { return }
  if (!confirm('{{Remplacer les pages, le bandeau, les touches de couleur, la barre d\'état et ses indicateurs de cette TV par ceux de la TV choisie ? (rien n\'est enregistré avant « Sauvegarder »)}}')) { return }
  jeetvbeAjax('copyFromTv', { id: id, source: source }, function (_copy) {
    jeetvbeModel = jeetvbeCleanPages(_copy.pages)
    jeetvbeHeader = (_copy.header || []).map(function (item) { return { id: '', cmd: item.cmd, label: item.label || '', icon: item.icon || 'generic' } })
    jeetvbeKeysByName = _copy.keysByName || {}
    jeetvbeKeys = {}
    jeetvbePrintStatusBar({ statusBar: _copy.statusBar, indicators: _copy.indicators })
    jeetvbeMarkModified()
    jeetvbeRender()
    jeetvbeFetchNames(jeetvbeRender)
    jeedomUtils.showAlert({ message: '{{Copie chargée. Relisez, puis sauvegardez : les touches de couleur suivront les pages par leur nom.}}', level: 'success' })
  })
})

document.getElementById('bt_jeetvbeVideoSave')?.addEventListener('click', function () {
  var id = jeetvbeCurrentId()
  if (!id) {
    jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord la TV.}}', level: 'warning' })
    return
  }
  var name = jeetvbeEl('in_jeetvbeVideoName')
  var url = jeetvbeEl('in_jeetvbeVideoUrl')
  jeetvbeAjax('saveVideoSource', { id: id, name: name.value, url: url.value }, function (_list) {
    url.value = ''
    name.value = ''
    jeetvbeRenderVideos(_list)
    jeedomUtils.showAlert({ message: '{{Source vidéo enregistrée.}}', level: 'success' })
  })
})

document.getElementById('table_jeetvbeVideos')?.addEventListener('click', function (event) {
  var button = event.target.closest('.jeetvbeVideoRemove')
  if (button === null) { return }
  var name = button.getAttribute('data-name')
  if (!confirm('{{Supprimer la source vidéo}} « ' + name + ' » ?')) { return }
  jeetvbeAjax('removeVideoSource', { id: jeetvbeCurrentId(), name: name }, jeetvbeRenderVideos)
})

/* Le tableau standard des commandes. .cmdAttr[data-l1key="id"] est
   indispensable : sans lui, chaque enregistrement recréerait les commandes
   (historique perdu, scénarios cassés). Ligne créée en DOM : insertAdjacentHTML
   sur une table crée un <tbody> par insertion. */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) { var _cmd = { configuration: {} } }
  if (!isset(_cmd.configuration)) { _cmd.configuration = {} }
  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<input class="cmdAttr form-control input-sm" data-l1key="name" placeholder="{{Nom}}">'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="logicalId" style="font-family:monospace;"></span></td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>'
  }
  tr += '</td>'
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ============================================================ ÉCOUTEURS */

var jeetvbePagesBox = document.getElementById('div_jeetvbePages')

if (jeetvbePagesBox !== null) {
  var jeetvbeOnField = function (event) {
    var field = event.target.getAttribute('data-field')
    if (field === null) { return }
    var p = parseInt(event.target.getAttribute('data-page'))
    var page = jeetvbeModel[p]
    if (!page) { return }
    if (field === 'pageName') {
      page.name = event.target.value
      jeetvbeMarkModified()
      /* Le nouveau nom dans les listes des touches de couleur. */
      if (event.type === 'change') { jeetvbeRenderKeys() }
      return
    }
    if (event.type !== 'change' && (field === 'pageType' || field === 'pageHidden' || field === 'sectionEq')) { return }
    if (field === 'pageHidden') {
      page.hidden = event.target.checked
      jeetvbeMarkModified()
      jeetvbeRenderKeys()
      return
    }
    if (field === 'pageType') {
      var type = event.target.value === 'board' ? 'board' : 'tiles'
      /* Un tableau n'a pas de tuile : celles de la page partent, après accord. */
      if (type === 'board' && page.tiles.length > 0 && !confirm('{{Un tableau des trains n\'a pas de tuile : les tuiles de cette page seront retirées à l\'enregistrement. Continuer ?}}')) {
        event.target.value = page.type
        return
      }
      page.type = type
      if (type === 'board') {
        page.tiles = []
        if (!Array.isArray(page.sections)) { page.sections = [] }
        if (page.sections.length === 0) { page.sections.push({ eqLogic: null, title: '' }) }
      } else {
        delete page.sections
      }
      jeetvbeMarkModified()
      jeetvbeRender()
      return
    }
    if (field === 'sectionEq' || field === 'sectionTitle') {
      var section = (page.sections || [])[parseInt(event.target.getAttribute('data-section'))]
      if (!section) { return }
      if (field === 'sectionTitle') {
        section.title = event.target.value.substring(0, 64)
        jeetvbeMarkModified()
        return
      }
      section.eqLogic = event.target.value ? parseInt(event.target.value) : null
      jeetvbeMarkModified()
      jeetvbeRender()
      return
    }
    var tile = page.tiles[parseInt(event.target.getAttribute('data-tile'))]
    if (!tile) { return }
    if (field === 'confirm') {
      tile.confirm = event.target.checked
    } else if (field === 'min' || field === 'max' || field === 'step') {
      tile[field] = (event.target.value === '') ? null : parseFloat(event.target.value)
    } else if (field === 'option') {
      if (!tile.options || Array.isArray(tile.options)) { tile.options = {} }
      tile.options[event.target.getAttribute('data-option')] = event.target.value
    } else if (field === 'name') {
      tile.name = event.target.value
    } else if (field === 'icon') {
      tile.icon = event.target.value
    } else if (field === 'type' && event.type === 'change') {
      tile.type = event.target.value
      /* Les commandes des rôles que le nouveau type n'a pas partent : elles
         resteraient enregistrées sans être visibles dans l'éditeur. */
      var keep = jeetvbeTypeRoles[tile.type] || []
      Object.keys(tile.cmds).forEach(function (role) {
        if (keep.indexOf(role) === -1) { delete tile.cmds[role] }
      })
      if (tile.type === 'button' && (!tile.options || Array.isArray(tile.options))) { tile.options = {} }
      if (tile.icon === 'generic' || !tile.icon) { tile.icon = JEETVBE_DEFAULT_ICON[tile.type] || 'generic' }
      jeetvbeRender()
    }
    jeetvbeMarkModified()
  }
  jeetvbePagesBox.addEventListener('input', jeetvbeOnField)
  jeetvbePagesBox.addEventListener('change', jeetvbeOnField)

  jeetvbePagesBox.addEventListener('click', function (event) {
    var button = event.target.closest('[data-action]')
    if (button === null || button.hasAttribute('disabled')) { return }
    var action = button.getAttribute('data-action')
    var p = parseInt(button.getAttribute('data-page'))
    var t = parseInt(button.getAttribute('data-tile'))
    var page = jeetvbeModel[p]
    if (!page) { return }
    var tile = isNaN(t) ? null : page.tiles[t]

    var s = parseInt(button.getAttribute('data-section'))
    if (action === 'sectionAdd' && page.type === 'board' && page.sections.length < jeetvbeBoardSectionsMax) { page.sections.push({ eqLogic: null, title: '' }) }
    if (action === 'sectionUp') { jeetvbeSwap(page.sections, s, s - 1) }
    if (action === 'sectionDown') { jeetvbeSwap(page.sections, s, s + 1) }
    if (action === 'sectionRemove') { page.sections.splice(s, 1) }
    if (action === 'pageUp') { jeetvbeSwap(jeetvbeModel, p, p - 1) }
    if (action === 'pageDown') { jeetvbeSwap(jeetvbeModel, p, p + 1) }
    if (action === 'pageRemove') {
      if (page.tiles.length > 0 && !confirm('{{Supprimer cette page et ses tuiles ?}}')) { return }
      jeetvbeModel.splice(p, 1)
    }
    if (action === 'tileAdd') { page.tiles.push(jeetvbeCleanTile({ type: 'switch', icon: 'light', name: '' })) }
    if (action === 'tileUp') { jeetvbeSwap(page.tiles, t, t - 1) }
    if (action === 'tileDown') { jeetvbeSwap(page.tiles, t, t + 1) }
    if (action === 'tileRemove') { page.tiles.splice(t, 1) }
    if (action === 'clearCmd' && tile) { delete tile.cmds[button.getAttribute('data-role')] }
    if (action === 'clearScenario' && tile) { tile.scenario_id = null }

    if (action === 'pickCmd' && tile) {
      var role = button.getAttribute('data-role')
      var filter = { type: (role === 'state') ? 'info' : 'action' }
      if (tile.type === 'select' && role === 'set') { filter.subType = 'select' }
      jeedom.cmd.getSelectModal({ cmd: filter }, function (result) {
        if (!result || !result.cmd || !result.cmd.id) { return }
        tile.cmds[role] = parseInt(result.cmd.id)
        jeetvbeNames.cmds[result.cmd.id] = { human: result.human, type: result.cmd.type, subType: result.cmd.subType }
        /* Confirmation cochée d'office pour un nom sensible ; jamais décochée. */
        if (jeetvbeSensitive(result.human) || jeetvbeSensitive(tile.name)) { tile.confirm = true }
        jeetvbeMarkModified()
        jeetvbeRender()
      })
      return
    }
    if (action === 'pickScenario' && tile) {
      jeedom.scenario.getSelectModal({}, function (result) {
        if (!result || !result.id) { return }
        tile.scenario_id = parseInt(result.id)
        jeetvbeNames.scenarios[result.id] = { human: result.human }
        if (jeetvbeSensitive(result.human)) { tile.confirm = true }
        jeetvbeMarkModified()
        jeetvbeRender()
      })
      return
    }
    jeetvbeMarkModified()
    jeetvbeRender()
  })
}

document.querySelectorAll('select.jeetvbeKey').forEach(function (select) {
  select.addEventListener('change', function () {
    jeetvbeKeys[select.getAttribute('data-color')] = select.value
    /* Une touche choisie à la main l'emporte sur celles d'une copie. */
    jeetvbeKeysByName = null
    jeetvbeMarkModified()
  })
})
document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="scenarioGroup"]')?.addEventListener('change', jeetvbeRenderKeys)

document.getElementById('bt_jeetvbeAddHeader')?.addEventListener('click', function (event) {
  if (jeetvbeHeader.length >= JEETVBE_HEADER_MAX) { return }
  jeetvbeHeader.push({ id: '', cmd: null, label: '', icon: 'generic' })
  jeetvbeMarkModified()
  jeetvbeRenderHeader()
})

var jeetvbeHeaderBox = document.getElementById('div_jeetvbeHeader')
if (jeetvbeHeaderBox !== null) {
  var jeetvbeOnHeaderField = function (event) {
    var field = event.target.getAttribute('data-header-field')
    var item = jeetvbeHeader[parseInt(event.target.getAttribute('data-header'))]
    if (field === null || !item) { return }
    item[field] = (field === 'label') ? event.target.value.substring(0, 24) : event.target.value
    jeetvbeMarkModified()
  }
  jeetvbeHeaderBox.addEventListener('input', jeetvbeOnHeaderField)
  jeetvbeHeaderBox.addEventListener('change', jeetvbeOnHeaderField)
  jeetvbeHeaderBox.addEventListener('click', function (event) {
    var button = event.target.closest('[data-header-action]')
    if (button === null || button.hasAttribute('disabled')) { return }
    var h = parseInt(button.getAttribute('data-header'))
    var item = jeetvbeHeader[h]
    if (!item) { return }
    var action = button.getAttribute('data-header-action')
    if (action === 'pick') {
      jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
        if (!result || !result.cmd || !result.cmd.id) { return }
        item.cmd = parseInt(result.cmd.id)
        jeetvbeNames.cmds[result.cmd.id] = { human: result.human, type: result.cmd.type, subType: result.cmd.subType }
        /* Libellé vide : le nom de la commande, ramené à 24 caractères. */
        if (!item.label) {
          var parts = String(result.human).replace(/^#|#$/g, '').split('][')
          item.label = parts[parts.length - 1].replace(/[\[\]]/g, '').substring(0, 24)
        }
        jeetvbeMarkModified()
        jeetvbeRenderHeader()
      })
      return
    }
    if (action === 'up') { jeetvbeSwap(jeetvbeHeader, h, h - 1) }
    if (action === 'down') { jeetvbeSwap(jeetvbeHeader, h, h + 1) }
    if (action === 'remove') { jeetvbeHeader.splice(h, 1) }
    jeetvbeMarkModified()
    jeetvbeRenderHeader()
  })
}

document.getElementById('bt_jeetvbeAddPage')?.addEventListener('click', function () {
  jeetvbeModel.push({ id: '', name: '{{Nouvelle page}}', type: 'tiles', hidden: false, tiles: [] })
  jeetvbeMarkModified()
  jeetvbeRender()
})

document.getElementById('bt_jeetvbeShowGenerate')?.addEventListener('click', function () {
  document.getElementById('div_jeetvbeGenerate').style.display = ''
})
document.getElementById('bt_jeetvbeHideGenerate')?.addEventListener('click', function () {
  document.getElementById('div_jeetvbeGenerate').style.display = 'none'
})

document.getElementById('bt_jeetvbeGenerate')?.addEventListener('click', function () {
  var ids = []
  document.querySelectorAll('#div_jeetvbeObjects .jeetvbeObject').forEach(function (box) {
    if (box.checked) { ids.push(parseInt(box.value)) }
  })
  if (ids.length === 0) {
    jeedomUtils.showAlert({ message: '{{Cochez au moins un objet.}}', level: 'warning' })
    return
  }
  var mode = document.querySelector('input[name="jeetvbeMode"]:checked')
  jeetvbeAjax('generate', { object_ids: JSON.stringify(ids), mode: mode ? mode.value : 'type' }, function (pages) {
    var added = jeetvbeCleanPages(pages)
    var tiles = 0
    added.forEach(function (page) { tiles += page.tiles.length; jeetvbeModel.push(page) })
    jeetvbeMarkModified()
    document.getElementById('div_jeetvbeGenerate').style.display = 'none'
    jeedomUtils.showAlert({ message: added.length + ' {{page(s) et}} ' + tiles + ' {{tuile(s) ajoutées. Relisez, puis sauvegardez.}}', level: 'success' })
    jeetvbeRender()
    jeetvbeFetchNames(jeetvbeRender)
  })
})

document.getElementById('bt_jeetvbeRegenerate')?.addEventListener('click', function () {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]').value
  if (!id) {
    jeedomUtils.showAlert({ message: '{{Enregistrez d\'abord la TV : la clé est générée à l\'enregistrement.}}', level: 'warning' })
    return
  }
  if (!confirm('{{Régénérer la clé ? La TV qui utilise l\'ancienne sera coupée immédiatement.}}')) { return }
  jeetvbeAjax('regenerateToken', { id: id }, function (result) {
    document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="token"]').value = result.token
    jeedomUtils.showAlert({ message: '{{Nouvelle clé enregistrée.}}', level: 'success' })
  })
})

document.getElementById('bt_jeetvbePreview')?.addEventListener('click', function () {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]').value
  var pre = document.getElementById('pre_jeetvbePreview')
  if (pre.style.display !== 'none') { pre.style.display = 'none'; return }
  if (!id) { return }
  jeetvbeAjax('preview', { id: id }, function (layout) {
    pre.textContent = JSON.stringify(layout, null, 2)
    pre.style.display = ''
  })
})
