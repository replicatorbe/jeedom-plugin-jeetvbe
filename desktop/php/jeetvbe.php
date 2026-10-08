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
sendVarToJS('jeetvbeBarCorners', jeetvbeOverlay::BAR_CORNERS);
/* Trajets du plugin SNCB/NMBS pour les tableaux des trains. Toujours un
   tableau : sendVarToJS() rend null en chaîne vide, qui ne se distingue pas
   d'une liste vide côté JS. */
$jeetvbeSncbEqs = jeetvbe::sncbEqLogics();
sendVarToJS('jeetvbeSncb', array('available' => $jeetvbeSncbEqs !== null, 'eqs' => ($jeetvbeSncbEqs === null) ? array() : $jeetvbeSncbEqs));
sendVarToJS('jeetvbeBoardSectionsMax', jeetvbeLayout::BOARD_SECTIONS_MAX);
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
	.jeetvbePageHead select { width: auto; }
	.jeetvbeSection { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 0 0 6px 0; padding: 4px 8px; border-left: 3px solid var(--al-warning-color, #f0ad4e); }
	.jeetvbeSection select { width: 340px; }
	.jeetvbeSection input { width: 280px; }
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
	.jeetvbeInd { margin-bottom: 10px; }
	.jeetvbeInd .panel-body .form-group { margin-bottom: 6px; }
	#table_jeetvbeVideos td { vertical-align: middle; }
	.jeetvbeHeaderRow { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 4px; }
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
			<li role="presentation" class="jeetvbeTvOnly"><a href="#pagestab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-th"></i><span class="hidden-xs"> {{Pages et tuiles}}</span></a></li>
			<li role="presentation" class="jeetvbeTvOnly"><a href="#statustab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-grip-lines"></i><span class="hidden-xs"> {{Barre d'état}}</span></a></li>
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
											echo '<option value="' . (int) $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', (int) $object->getConfiguration('parentNumber')) . htmlspecialchars($object->getName(), ENT_QUOTES, 'UTF-8') . '</option>';
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
							<div class="form-group jeetvbeTvOnly">
								<label class="col-sm-3 control-label">{{Diffusions}}</label>
								<div class="col-sm-8">
									<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="broadcast" id="cb_jeetvbeBroadcast">{{Recevoir les diffusions (Toutes les TV)}}</label>
									<span class="help-block" style="margin:0;">{{Les commandes de l'équipement « Toutes les TV » atteignent cette TV : notifications si elle est en ligne et écran allumé, indicateurs temporaires toujours. Décochez pour une TV de test.}}</span>
								</div>
							</div>
						</fieldset>
						<div class="alert alert-info" id="div_jeetvbeBroadcastInfo" style="display:none;">{{« Toutes les TV » n'est pas une TV : ses commandes (Message, Notifier (JSON), Retirer une notification, Indicateur (JSON), Retirer un indicateur, Question) sont rejouées sur chaque TV qui reçoit les diffusions. Une notification ne part que vers les TV en ligne et écran allumé ; un indicateur temporaire vers toutes. Les sources vidéo sont celles de chaque TV : une TV qui n'a pas la source reçoit la notification sans vidéo. Question (bloc Demander) : posée à toutes les TV allumées, la première réponse l'emporte et la question se ferme sur les autres.}}</div>
						<fieldset class="jeetvbeTvOnly">
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
						<fieldset class="jeetvbeTvOnly">
							<legend><i class="fas fa-palette"></i> {{Touches de couleur}}</legend>
							<?php foreach (array('red' => '{{Rouge}}', 'green' => '{{Vert}}', 'yellow' => '{{Jaune}}', 'blue' => '{{Bleu}}') as $jeetvbeColor => $jeetvbeColorName) { ?>
							<div class="form-group">
								<label class="col-sm-3 control-label"><?php echo $jeetvbeColorName; ?></label>
								<div class="col-sm-4">
									<select class="form-control jeetvbeKey" data-color="<?php echo $jeetvbeColor; ?>"></select>
								</div>
							</div>
							<?php } ?>
							<div class="form-group">
								<div class="col-sm-offset-3 col-sm-9">
									<span class="help-block" style="margin:0;">{{La touche de couleur de la télécommande ouvre la page choisie, par-dessus n'importe quelle application (service d'accessibilité de l'application à activer une fois). La même touche, ou Retour, la referme. Une page supprimée libère sa touche. Une page qui vient d'être ajoutée n'apparaît dans ces listes qu'après « Sauvegarder ».}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset class="jeetvbeTvOnly">
							<legend><i class="fas fa-stream"></i> {{Bandeau d'infos}}</legend>
							<div class="form-group">
								<div class="col-sm-offset-3 col-sm-9">
									<div id="div_jeetvbeHeader"></div>
									<a class="btn btn-success btn-xs" id="bt_jeetvbeAddHeader"><i class="fas fa-plus"></i> {{Ajouter une info}}</a>
									<span class="help-block" style="margin:0;">{{Jusqu'à 6 infos affichées en permanence en haut de l'écran de la TV (pages et panneau), mises à jour en direct : une commande info, un libellé court (24 caractères au plus) et une icône.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset class="jeetvbeTvOnly">
							<legend><i class="fas fa-video"></i> {{Sources vidéo}}</legend>
							<div class="form-group">
								<div class="col-sm-offset-3 col-sm-9">
									<table class="table table-condensed" id="table_jeetvbeVideos" style="margin-bottom:5px;"><tbody></tbody></table>
									<div class="form-inline">
										<input type="text" class="form-control input-sm" id="in_jeetvbeVideoName" placeholder="{{Nom, ex. portier}}" maxlength="32" style="width:150px;" autocomplete="off">
										<input type="password" class="form-control input-sm" id="in_jeetvbeVideoUrl" placeholder="rtsp://utilisateur:motdepasse@adresse/flux" style="width:360px;" autocomplete="new-password">
										<a class="btn btn-success btn-sm" id="bt_jeetvbeVideoSave"><i class="fas fa-check"></i> {{Enregistrer la source}}</a>
									</div>
									<span class="help-block" style="margin:0;">{{Un nom pour chaque flux de caméra (RTSP, HLS) : [video=portier] dans Message ou Question, ou "video":"portier" dans Notifier (JSON), joue ce flux sur la TV. L'adresse complète, identifiants compris, est gardée par le plugin et n'est plus jamais affichée ni écrite au journal. Une source est enregistrée tout de suite (sans « Sauvegarder »). Même nom : l'adresse est remplacée.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset class="jeetvbeTvOnly">
							<legend><i class="fas fa-heartbeat"></i> {{État de la TV}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Version de l'application}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_jeetvbeAppVersion">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Vue pour la dernière fois}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_jeetvbeLastSeen">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Compte dans « TV allumées »}}</label>
								<div class="col-sm-9"><span class="form-control-static" id="span_jeetvbeScreensOn">-</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Derniers ordres}}</label>
								<div class="col-sm-9"><table class="table table-condensed" id="table_jeetvbeOrders" style="margin:0;"><tbody></tbody></table></div>
							</div>
						</fieldset>
						<fieldset class="jeetvbeTvOnly">
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
						<fieldset class="jeetvbeTvOnly">
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
					<span id="span_jeetvbeCopy" style="display:none;">
						&nbsp;<select class="form-control input-sm" id="sel_jeetvbeCopySource" style="display:inline-block;width:auto;"></select>
						<a class="btn btn-sm btn-default" id="bt_jeetvbeCopy" title="{{Pages, bandeau, touches de couleur, barre d'état et indicateurs ; jamais la clé, les sources vidéo, l'option de diffusion ni le nom}}"><i class="fas fa-copy"></i> {{Copier depuis cette TV}}</a>
					</span>
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
				<span class="help-block">{{Une page « Tableau des trains » affiche les prochains départs d'un à trois trajets du plugin SNCB/NMBS, comme le tableau d'une gare. Une page « cachée » n'apparaît ni dans les onglets ni dans la navigation de la TV : seules sa commande « Afficher » (par exemple « Afficher Trains ») et une touche de couleur l'ouvrent.}}</span>
			</div>

			<!-- ============================ BARRE D'ÉTAT ============================= -->
			<!-- Réglages et indicateurs lus par saveEqLogic() et rangés dans
			     configuration.statusBar et configuration.indicators. -->
			<div role="tabpanel" class="tab-pane" id="statustab">
				<br>
				<form class="form-horizontal">
					<fieldset>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Barre d'état}}</label>
							<div class="col-sm-9">
								<label class="checkbox-inline"><input type="checkbox" id="cb_jeetvbeBarEnabled"> {{Afficher la barre sur la TV, par-dessus toutes les applications}}</label>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Coin}}</label>
							<div class="col-sm-3">
								<select class="form-control" id="sel_jeetvbeBarCorner">
									<option value="bottom_start">{{En bas à gauche}}</option>
									<option value="bottom_end">{{En bas à droite}}</option>
									<option value="top_start">{{En haut à gauche}}</option>
									<option value="top_end">{{En haut à droite}}</option>
								</select>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Horloge}}</label>
							<div class="col-sm-9">
								<label class="checkbox-inline"><input type="checkbox" id="cb_jeetvbeBarClock"> {{Afficher l'heure}}</label>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Opacité (%)}}</label>
							<div class="col-sm-2">
								<input type="number" min="0" max="100" step="5" class="form-control" id="in_jeetvbeBarOpacity" placeholder="85">
							</div>
							<div class="col-sm-7"><span class="help-block" style="margin:0;">{{0 = barre masquée.}}</span></div>
						</div>
					</fieldset>
				</form>
				<div class="alert alert-info">
					{{Des indicateurs qui s'affichent et se retirent seuls, d'après des commandes info, sans scénario (même modèle que les indicateurs automatiques du plugin TvOverlay). Le plugin recalcule la barre à chaque changement d'une commande citée, et ne l'envoie à la TV que si ce qui est affiché change. « Indicateur (JSON) » y ajoute des indicateurs temporaires, « Retirer un indicateur » les retire.}}
				</div>
				<div style="margin-bottom:10px;">
					<a class="btn btn-success btn-sm" id="bt_jeetvbeIndAdd"><i class="fas fa-plus-circle"></i> {{Ajouter un indicateur}}</a>
					<span id="span_jeetvbeImport" style="display:none;">
						&nbsp;<select class="form-control input-sm" id="sel_jeetvbeImportSource" style="display:inline-block;width:auto;"></select>
						<a class="btn btn-default btn-sm" id="bt_jeetvbeImport"><i class="fas fa-file-import"></i> {{Importer depuis TvOverlay}}</a>
					</span>
					<a class="btn btn-default btn-sm" id="bt_jeetvbeStatusPreview"><i class="fas fa-code"></i> {{Aperçu de la barre enregistrée}}</a>
				</div>
				<div id="div_jeetvbeIndErrors" class="alert alert-warning" style="display:none;"></div>
				<pre id="pre_jeetvbeStatusPreview" style="display:none;max-height:400px;overflow:auto;"></pre>
				<div id="div_jeetvbeIndicators"></div>
				<template id="tpl_jeetvbeInd">
					<div class="panel panel-default jeetvbeInd">
						<div class="panel-heading">
							<div class="form-inline">
								<label class="checkbox-inline" title="{{Actif}}"><input type="checkbox" class="jtvIndAttr" data-key="enable" checked> {{Actif}}</label>
								&nbsp;
								<input type="text" class="form-control input-sm jtvIndAttr" data-key="id" placeholder="{{id (obligatoire), ex. meteo}}" style="width:170px;">
								<input type="text" class="form-control input-sm jtvIndAttr" data-key="name" placeholder="{{Nom, ex. Météo}}" style="width:220px;">
								<input type="hidden" class="jtvIndAttr" data-key="expiration">
								<span class="pull-right">
									<a class="btn btn-default btn-sm jtvIndUp" title="{{Monter}}"><i class="fas fa-arrow-up"></i></a>
									<a class="btn btn-default btn-sm jtvIndDown" title="{{Descendre}}"><i class="fas fa-arrow-down"></i></a>
									<a class="btn btn-danger btn-sm jtvIndRemove" title="{{Supprimer cet indicateur}}"><i class="fas fa-minus-circle"></i></a>
								</span>
							</div>
						</div>
						<div class="panel-body form-horizontal">
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Visibilité}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm jtvIndAttr" data-key="visibility">
										<option value="always">{{Toujours}}</option>
										<option value="conditions">{{Visible si…}}</option>
									</select>
								</div>
								<div class="col-sm-4 jtvIndIf" data-if="visibility=conditions">
									<select class="form-control input-sm jtvIndAttr" data-key="combine">
										<option value="any">{{au moins une condition (OU)}}</option>
										<option value="all">{{toutes les conditions (ET)}}</option>
									</select>
								</div>
							</div>
							<div class="form-group jtvIndIf" data-if="visibility=conditions">
								<div class="col-sm-offset-2 col-sm-10">
									<table class="table table-condensed jtvIndConds" style="margin-bottom:5px;"><tbody></tbody></table>
									<a class="btn btn-default btn-xs jtvIndCondAdd"><i class="fas fa-plus"></i> {{Ajouter une condition}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Texte}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm jtvIndAttr" data-key="text_mode">
										<option value="none">{{Aucun}}</option>
										<option value="fixed">{{Fixe}}</option>
										<option value="cmd">{{Valeur d'une commande}}</option>
									</select>
								</div>
								<div class="col-sm-4 jtvIndIf" data-if="text_mode=fixed">
									<input type="text" class="form-control input-sm jtvIndAttr" data-key="text" placeholder="{{Salon}}">
								</div>
								<div class="col-sm-4 jtvIndIf" data-if="text_mode=cmd">
									<div class="input-group">
										<input type="text" class="form-control input-sm roundedLeft jtvIndAttr" data-key="text_cmd" placeholder="#[Maison][Météo][Température]#">
										<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight jtvIndPick" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>
									</div>
								</div>
								<div class="col-sm-3 jtvIndIf" data-if="text_mode=cmd">
									<div class="input-group">
										<input type="number" min="0" max="6" class="form-control input-sm roundedLeft jtvIndAttr" data-key="decimals" placeholder="{{décimales}}" title="{{Arrondi : nombre de décimales (vide = valeur telle quelle)}}">
										<input type="text" class="form-control input-sm roundedRight jtvIndAttr" data-key="suffix" placeholder="{{suffixe, ex. °}}" title="{{Ajouté après la valeur}}">
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Icône}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm jtvIndAttr" data-key="icon_mode">
										<option value="fixed">{{Fixe}}</option>
										<option value="cmd">{{Valeur d'une commande}}</option>
									</select>
								</div>
								<div class="col-sm-4 jtvIndIf" data-if="icon_mode=cmd">
									<div class="input-group">
										<input type="text" class="form-control input-sm roundedLeft jtvIndAttr" data-key="icon_cmd" placeholder="#[Maison][Météo][Icône]#">
										<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight jtvIndPick" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>
									</div>
								</div>
								<div class="col-sm-3">
									<input type="text" class="form-control input-sm jtvIndAttr" data-key="icon" placeholder="mdi:lightbulb" title="{{Icône Material Design fixe ; avec une commande, icône de repli tant qu'elle n'a rien publié}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Couleurs}}</label>
								<div class="col-sm-10">
									<div class="form-inline">
										<input type="text" class="form-control input-sm jtvIndAttr" data-key="iconColor" placeholder="{{icône #ff9800}}" style="width:130px;">
										<input type="text" class="form-control input-sm jtvIndAttr" data-key="messageColor" placeholder="{{texte #ffffff}}" style="width:130px;">
										<input type="text" class="form-control input-sm jtvIndAttr" data-key="borderColor" placeholder="{{bordure #ff9800}}" style="width:130px;">
										<input type="text" class="form-control input-sm jtvIndAttr" data-key="backgroundColor" placeholder="{{fond #66000000}}" style="width:130px;">
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Forme}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm jtvIndAttr" data-key="shape">
										<option value="">{{Par défaut (cercle)}}</option>
										<option value="circle">{{Cercle}}</option>
										<option value="rounded">{{Arrondie}}</option>
										<option value="rectangular">{{Rectangle}}</option>
									</select>
								</div>
							</div>
						</div>
					</div>
				</template>
				<template id="tpl_jeetvbeIndCond">
					<table><tbody><tr class="jtvIndCond">
						<td>
							<div class="input-group">
								<input type="text" class="form-control input-sm roundedLeft jtvCondAttr" data-key="cmd" placeholder="#[Salon][Lampe][Etat]#">
								<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight jtvIndPick" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>
							</div>
						</td>
						<td style="width:90px;">
							<select class="form-control input-sm jtvCondAttr" data-key="operator">
								<option value="==">==</option>
								<option value="!=">!=</option>
								<option value="&gt;">&gt;</option>
								<option value="&gt;=">&gt;=</option>
								<option value="&lt;">&lt;</option>
								<option value="&lt;=">&lt;=</option>
							</select>
						</td>
						<td style="width:140px;"><input type="text" class="form-control input-sm jtvCondAttr" data-key="value" placeholder="1"></td>
						<td style="width:40px;"><a class="btn btn-danger btn-sm jtvIndCondRemove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a></td>
					</tr></tbody></table>
				</template>
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
