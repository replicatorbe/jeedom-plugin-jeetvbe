<?php
/* This file is part of Jeedom. Licence AGPL — voir LICENSE. */
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('jeetvbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/* Ce dont l'éditeur a besoin, transmis depuis la classe : une seule source
   pour les types, icônes et rôles du contrat. */
sendVarToJS('jeetvbeApiUrl', jeetvbe::apiUrl());
sendVarToJS('jeetvbeTypes', jeetvbeLayout::TYPES);
sendVarToJS('jeetvbeIcons', jeetvbeLayout::ICONS);
sendVarToJS('jeetvbeTypeRoles', jeetvbeLayout::TYPE_ROLES);
sendVarToJS('jeetvbeSensitiveWords', jeetvbeLayout::SENSITIVE_WORDS);
$jeetvbeObjects = array();
foreach (jeeObject::buildTree(null, false) as $object) {
	$jeetvbeObjects[] = array(
		'id'    => (int) $object->getId(),
		'name'  => $object->getName(),
		'depth' => (int) $object->getConfiguration('parentNumber', 0),
	);
}
sendVarToJS('jeetvbeObjects', $jeetvbeObjects);
$jeetvbeGroups = array();
foreach (scenario::listGroup() as $jeetvbeGroup) {
	if (isset($jeetvbeGroup['group']) && $jeetvbeGroup['group'] !== '' && $jeetvbeGroup['group'] !== null) {
		$jeetvbeGroups[] = $jeetvbeGroup['group'];
	}
}
?>

<style>
	.jeetvbePage {
		border: 1px solid var(--btnEq-default-color);
		border-radius: var(--border-radius);
		padding: 8px 10px;
		margin-bottom: 15px;
	}
	.jeetvbePageHead {
		display: flex;
		gap: 6px;
		align-items: center;
		margin-bottom: 8px;
	}
	.jeetvbePageHead input { max-width: 320px; }
	.jeetvbeTile {
		border-left: 3px solid var(--al-info-color, #3a87ad);
		padding: 6px 8px;
		margin: 0 0 8px 0;
		background: var(--bg-color, transparent);
	}
	.jeetvbeTileRow {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
		align-items: center;
		margin-bottom: 4px;
	}
	.jeetvbeTileRow label { margin: 0; font-weight: normal; }
	.jeetvbeTileId { font-family: monospace; opacity: .6; min-width: 40px; }
	.jeetvbeRole { display: inline-flex; align-items: center; gap: 3px; min-width: 330px; }
	.jeetvbeRole .jeetvbeRoleName { width: 52px; text-align: right; opacity: .8; }
	.jeetvbeRole input { width: 250px; }
	.jeetvbeBound input { width: 80px; }
	.jeetvbeMissing { color: var(--al-danger-color, #d9534f); }
	#div_jeetvbeGenerate .checkbox-inline { margin-left: 0; margin-right: 12px; }
</style>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter une TV}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-tv"></i> {{Mes TV}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">{{Aucune TV. Cliquez sur « Ajouter une TV », enregistrez, puis reportez l\'URL et la clé dans l\'application Jeedom TV.}}</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<i class="fas fa-tv" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-default btn-sm eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tv"></i><span class="hidden-xs"> {{TV}}</span></a></li>
			<li role="presentation"><a href="#pagestab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-th"></i><span class="hidden-xs"> {{Pages et tuiles}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ================================ TV ================================ -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-7">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom de la TV}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{TV salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Options}}</label>
								<div class="col-sm-8">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
									<span class="help-block" style="margin:0;">{{Une TV désactivée est refusée par l'API (401) : elle ne lit ni ne pilote plus rien.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-play-circle"></i> {{Scénarios}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Groupe de scénarios}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="scenarioGroup" list="list_jeetvbeGroups" placeholder="{{vide = désactivé}}">
									<datalist id="list_jeetvbeGroups">
										<?php foreach ($jeetvbeGroups as $jeetvbeGroup) { echo '<option value="' . htmlspecialchars($jeetvbeGroup, ENT_QUOTES, 'UTF-8') . '">'; } ?>
									</datalist>
								</div>
								<div class="col-sm-5">
									<span class="help-block" style="margin:0;">{{Ajoute à la fin une page « Scénarios » avec une tuile par scénario actif de ce groupe, triée par nom, tenue à jour sans réenregistrer. Confirmation demandée si la description du scénario contient [confirmer], ou si son nom contient portail, garage, verrou, alarme ou panique.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-heartbeat"></i> {{État de la TV}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Version de l'application}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_jeetvbeAppVersion">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Vue pour la dernière fois}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_jeetvbeLastSeen">-</span></div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-bullhorn"></i> {{Ordres de Jeedom vers la TV}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Durée d'affichage par défaut (s)}}</label>
								<div class="col-sm-2">
									<input type="number" min="0" max="86400" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="showDuration" placeholder="30">
								</div>
								<div class="col-sm-7">
									<span class="help-block" style="margin:0;">{{Durée pendant laquelle une page demandée par « Afficher … » reste affichée avant le retour à l'écran précédent (sauf si on a touché la télécommande). 0 = sans retour. Un ordre non reçu par la TV dans les 60 s est abandonné.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-key"></i> {{Ce qu'il faut donner à la TV}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{URL de l'API}}</label>
								<div class="col-sm-9">
									<code id="span_jeetvbeApiUrl" style="word-break:break-all;"></code>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Clé}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="token" readonly placeholder="{{générée à l'enregistrement}}" style="font-family:monospace;">
								</div>
								<div class="col-sm-3">
									<a class="btn btn-warning btn-sm" id="bt_jeetvbeRegenerate"><i class="fas fa-sync"></i> {{Régénérer}}</a>
								</div>
							</div>
							<div class="form-group">
								<div class="col-sm-offset-3 col-sm-9">
									<span class="help-block" style="margin:0;">{{La clé est propre à cette TV et ne donne accès qu'aux tuiles de ses pages. Régénérer la clé coupe immédiatement la TV qui utilisait l'ancienne.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- =========================== PAGES ET TUILES =========================== -->
			<div role="tabpanel" class="tab-pane" id="pagestab">
				<br>
				<div style="margin-bottom:10px;">
					<a class="btn btn-sm btn-success" id="bt_jeetvbeAddPage"><i class="fas fa-plus-circle"></i> {{Ajouter une page}}</a>
					<a class="btn btn-sm btn-primary" id="bt_jeetvbeShowGenerate"><i class="fas fa-magic"></i> {{Générer depuis les types génériques}}</a>
					<a class="btn btn-sm btn-default" id="bt_jeetvbePreview"><i class="fas fa-code"></i> {{Aperçu du layout enregistré}}</a>
				</div>
				<div id="div_jeetvbeGenerate" class="alert alert-info" style="display:none;">
					{{Tuiles déduites des types génériques des équipements activés des objets cochés (lumières, volets, consignes, températures, prises). Les pages sont ajoutées à la suite, à relire avant d'enregistrer.}}
					<div style="margin:8px 0;">
						<b>{{Regrouper}}</b>
						<label class="radio-inline"><input type="radio" name="jeetvbeMode" value="type" checked> {{par type (Lumières, Volets, Chauffage et clim, Températures, Prises), tuiles nommées « Pièce · Nom »}}</label>
						<label class="radio-inline"><input type="radio" name="jeetvbeMode" value="room"> {{par pièce (une page par objet)}}</label>
					</div>
					<div id="div_jeetvbeObjects" style="margin:8px 0;"></div>
					<a class="btn btn-sm btn-success" id="bt_jeetvbeGenerate"><i class="fas fa-check"></i> {{Générer les pages}}</a>
					<a class="btn btn-sm btn-default" id="bt_jeetvbeHideGenerate"><i class="fas fa-times"></i> {{Fermer}}</a>
				</div>
				<pre id="pre_jeetvbePreview" style="display:none;max-height:400px;overflow:auto;"></pre>
				<div id="div_jeetvbePages"></div>
				<span class="help-block">{{Les identifiants (p1, t1…) sont attribués à l'enregistrement et restent stables : la TV les utilise pour désigner une tuile. Les modifications ne sont envoyées à la TV qu'après « Sauvegarder ».}}</span>
			</div>

			<!-- ============================== COMMANDES ============================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<span class="help-block">{{Les commandes sont créées et tenues à jour par le plugin à chaque enregistrement : une commande « Afficher <page> » par page, renommée ou supprimée avec elle.}}</span>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:180px;">{{Type}}</th>
								<th style="width:160px;">{{Logical ID}}</th>
								<th style="width:250px;">{{Paramètres}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'jeetvbe', 'js', 'jeetvbe'); ?>
