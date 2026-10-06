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
var jeetvbeNames = { cmds: {}, scenarios: {} }

var JEETVBE_TYPE_LABELS = {
  switch: '{{Interrupteur}}', shutter: '{{Volet}}', slider: '{{Curseur}}', info: '{{Information}}', scene: '{{Scénario}}'
}
var JEETVBE_ICON_LABELS = {
  light: '{{Lumière}}', plug: '{{Prise}}', shutter: '{{Volet}}', thermostat: '{{Thermostat}}', temperature: '{{Température}}',
  scene: '{{Scène}}', fan: '{{Ventilateur}}', lock: '{{Serrure}}', alarm: '{{Alarme}}', generic: '{{Générique}}'
}
var JEETVBE_ROLE_LABELS = {
  state: '{{État}}', on: '{{On}}', off: '{{Off}}', toggle: '{{Bascule}}', up: '{{Monter}}', down: '{{Descendre}}', stop: '{{Stop}}', set: '{{Régler}}'
}
var JEETVBE_DEFAULT_ICON = { switch: 'light', shutter: 'shutter', slider: 'thermostat', info: 'temperature', scene: 'scene' }

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

function jeetvbeRender() {
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
  var url = document.getElementById('span_jeetvbeApiUrl')
  if (url !== null) { url.textContent = jeetvbeApiUrl }
  var preview = document.getElementById('pre_jeetvbePreview')
  if (preview !== null) { preview.style.display = 'none'; preview.textContent = '' }
  jeetvbeRenderObjects()
  jeetvbeRender()
  jeetvbeFetchNames(jeetvbeRender)
}

function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  _eqLogic.configuration.pages = jeetvbeModel
  return _eqLogic
}

/* L'équipement n'a aucune commande : rien à afficher. */
function addCmdToTable(_cmd) {
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
    } else if (field === 'name') {
      tile.name = event.target.value
    } else if (field === 'icon') {
      tile.icon = event.target.value
    } else if (field === 'type' && event.type === 'change') {
      tile.type = event.target.value
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
        jeetvbeNames.cmds[result.cmd.id] = { human: result.human }
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
  jeetvbeAjax('generate', { object_ids: JSON.stringify(ids) }, function (pages) {
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
