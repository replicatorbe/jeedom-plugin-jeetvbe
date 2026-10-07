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
var JEETVBE_HEADER_MAX = 6
var jeetvbeNames = { cmds: {}, scenarios: {} }

var JEETVBE_TYPE_LABELS = {
  switch: '{{Interrupteur}}', shutter: '{{Volet}}', slider: '{{Curseur}}', info: '{{Information}}', scene: '{{Scénario}}', button: '{{Bouton}}'
}
var JEETVBE_ICON_LABELS = {
  light: '{{Lumière}}', plug: '{{Prise}}', shutter: '{{Volet}}', thermostat: '{{Thermostat}}', temperature: '{{Température}}',
  scene: '{{Scène}}', fan: '{{Ventilateur}}', lock: '{{Serrure}}', alarm: '{{Alarme}}', camera: '{{Caméra}}', sun: '{{Soleil}}', rain: '{{Pluie}}', trash: '{{Poubelle}}', power: '{{Énergie}}', generic: '{{Générique}}'
}
var JEETVBE_ROLE_LABELS = {
  state: '{{État}}', on: '{{On}}', off: '{{Off}}', toggle: '{{Bascule}}', up: '{{Monter}}', down: '{{Descendre}}', stop: '{{Stop}}', set: '{{Régler}}', press: '{{Commande}}'
}
var JEETVBE_DEFAULT_ICON = { switch: 'light', shutter: 'shutter', slider: 'thermostat', info: 'temperature', scene: 'scene', button: 'generic' }
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

function jeetvbeCleanPages(_pages) {
  var pages = []
  if (!Array.isArray(_pages)) { return pages }
  _pages.forEach(function (page) {
    if (!page || typeof page !== 'object') { return }
    pages.push({ id: page.id || '', name: page.name || '', tiles: (Array.isArray(page.tiles) ? page.tiles : []).map(jeetvbeCleanTile) })
  })
  return pages
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

  if (_tile.type === 'button') {
    html += jeetvbeButtonOptionsHtml(where, _tile)
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
  var pages = jeetvbeModel.filter(function (page) { return page.id }).map(function (page) { return { id: page.id, name: page.name } })
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
    html += '<input class="form-control input-sm" data-field="pageName"' + where + ' value="' + jeetvbeEscape(page.name) + '" placeholder="{{Nom de la page}}">'
    html += '<span class="label label-default">' + page.tiles.length + ' {{tuile(s)}}</span>'
    html += '<span style="flex:1"></span>'
    html += '<a class="btn btn-success btn-xs" data-action="tileAdd"' + where + '><i class="fas fa-plus"></i> {{Tuile}}</a>'
    html += '<a class="btn btn-default btn-xs" data-action="pageUp"' + where + (p === 0 ? ' disabled' : '') + ' title="{{Monter la page}}"><i class="fas fa-arrow-up"></i></a>'
    html += '<a class="btn btn-default btn-xs" data-action="pageDown"' + where + (p === jeetvbeModel.length - 1 ? ' disabled' : '') + ' title="{{Descendre la page}}"><i class="fas fa-arrow-down"></i></a>'
    html += '<a class="btn btn-danger btn-xs" data-action="pageRemove"' + where + ' title="{{Supprimer la page}}"><i class="fas fa-trash"></i></a>'
    html += '</div>'
    page.tiles.forEach(function (tile, t) {
      html += jeetvbeTileHtml(p, t, tile, page.tiles.length)
    })
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
  return _eqLogic
}

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
      jeedom.cmd.getSelectModal({ cmd: { type: (role === 'state') ? 'info' : 'action' } }, function (result) {
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
  jeetvbeModel.push({ id: '', name: '{{Nouvelle page}}', tiles: [] })
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
