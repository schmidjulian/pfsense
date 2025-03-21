<?php
/*
 * system_groupmanager.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2025 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2005 Paul Taylor <paultaylor@winn-dixie.com>
 * Copyright (c) 2008 Shrew Soft Inc
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

##|+PRIV
##|*IDENT=page-system-groupmanager
##|*NAME=System: Group Manager
##|*DESCR=Allow access to the 'System: Group Manager' page.
##|*WARN=standard-warning-root
##|*MATCH=system_groupmanager.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("pfsense-utils.inc");

$logging_level = LOG_WARNING;
$logging_prefix = gettext("Local User Database");

$id = is_numericint($_REQUEST['groupid']) ? $_REQUEST['groupid'] : null;
$act = (isset($_REQUEST['act']) ? $_REQUEST['act'] : '');

$dup = null;

if ($act == 'dup') {
	$dup = $id;
	$act = 'edit';
}

function cpusercmp($a, $b) {
	return strcasecmp($a['name'], $b['name']);
}

function admin_groups_sort() {
	$group_config = config_get_path('system/group');

	if (!is_array($group_config)) {
		return;
	}

	usort($group_config, "cpusercmp");
	config_set_path("system/group", $group_config);
}

/*
 * Check user privileges to test if the user is allowed to make changes.
 * Otherwise users can end up in an inconsistent state where some changes are
 * performed and others denied. See https://redmine.pfsense.org/issues/9259
 */
phpsession_begin();
$guiuser = getUserEntry($_SESSION['Username']);
$guiuser = $guiuser['item'];
$read_only = (is_array($guiuser) && userHasPrivilege($guiuser, "user-config-readonly"));
phpsession_end();

if (!empty($_POST) && $read_only) {
	$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
}

if (($_POST['act'] == "delgroup") && !$read_only) {

	if (!isset($id) || !isset($_REQUEST['groupname']) ||
	    (config_get_path("system/group/{$id}") === null) ||
	    ($_REQUEST['groupname'] != config_get_path("system/group/{$id}/name"))) {
		pfSenseHeader("system_groupmanager.php");
		exit;
	}

	local_group_del(config_get_path("system/group/{$id}"));
	$groupdeleted = config_get_path("system/group/{$id}/name");
	config_del_path("system/group/{$id}");
	/*
	 * Reindex the array to avoid operating on an incorrect index
	 * https://redmine.pfsense.org/issues/7733
	 */
	config_set_path("system/group", array_values(config_get_path('system/group', [])));

	$savemsg = sprintf(gettext("Successfully deleted group: %s"),
	    $groupdeleted);
	write_config($savemsg);
	syslog($logging_level, "{$logging_prefix}: {$savemsg}");
}

if (($_POST['act'] == "delpriv") && !$read_only && ($dup === null)) {

	if (!isset($id) || (config_get_path("system/group/{$id}") === null)) {
		pfSenseHeader("system_groupmanager.php");
		exit;
	}

	$privdeleted = array_get_path($priv_list, (config_get_path("system/group/{$id}/priv/{$_REQUEST['privid']}") . "/name"));
	config_del_path("system/group/{$id}/priv/{$_REQUEST['privid']}");

	foreach (config_get_path("system/group/{$id}/member", []) as $uid) {
		$user = getUserEntryByUID($uid);
		$user = $user['item'];
		if ($user) {
			local_user_set($user);
		}
	}

	$savemsg = sprintf(gettext("Removed Privilege \"%s\" from group %s"),
	    $privdeleted, config_get_path("system/group/{$id}/name"));
	write_config($savemsg);
	syslog($logging_level, "{$logging_prefix}: {$savemsg}");

	$act = "edit";
}

if ($act == "edit") {
	if (isset($id)) {
		$this_group = config_get_path("system/group/{$id}");
		if ($dup === null) {
			$pconfig['name'] = $this_group['name'];
			$pconfig['gid'] = $this_group['gid'];
			$pconfig['gtype'] = empty($this_group['scope'])
			    ? "local" : $this_group['scope'];
		} else {
			$pconfig['gtype'] = ($this_group['scope'] == 'system')
			    ? "local" : $this_group['scope'];
		}
		$pconfig['priv'] = $this_group['priv'];
		$pconfig['description'] = $this_group['description'];
		$pconfig['members'] = $this_group['member'];
	}
}

if (isset($_POST['dellall_x']) && !$read_only) {

	$del_groups = $_POST['delete_check'];
	$deleted_groups = array();

	if (!empty($del_groups)) {
		foreach ($del_groups as $groupid) {
			$this_group = config_get_path("system/group/{$groupid}");
			if (isset($this_group) &&
			    $this_group['scope'] != "system") {
				$deleted_groups[] = $this_group['name'];
				local_group_del($this_group);
				config_del_path("system/group/{$groupid}");
			}
		}

		$savemsg = sprintf(gettext("Successfully deleted %s: %s"),
		    (count($deleted_groups) == 1)
		    ? gettext("group") : gettext("groups"),
		    implode(', ', $deleted_groups));
		/*
		 * Reindex the array to avoid operating on an incorrect index
		 * https://redmine.pfsense.org/issues/7733
		 */
		config_set_path("system/group", array_values(config_get_path('system/group', [])));
		write_config($savemsg);
		syslog($logging_level, "{$logging_prefix}: {$savemsg}");
	}
}

if (isset($_POST['save']) && !$read_only) {
	unset($input_errors);
	$pconfig = $_POST;

	/* input validation */
	$reqdfields = explode(" ", "groupname");
	$reqdfieldsn = array(gettext("Group Name"));

	do_input_validation($_POST, $reqdfields, $reqdfieldsn, $input_errors);

	if ($_POST['gtype'] != "remote") {
		if (preg_match("/[^a-zA-Z0-9\.\-_]/", $_POST['groupname'])) {
			$input_errors[] = sprintf(gettext(
			    "The (%s) group name contains invalid characters."),
			    $_POST['gtype']);
		}
		if (strlen($_POST['groupname']) > 16) {
			$input_errors[] = gettext(
			    "The group name is longer than 16 characters.");
		}
	} else {
		if (preg_match("/[^a-zA-Z0-9\.\- _]/", $_POST['groupname'])) {
			$input_errors[] = sprintf(gettext(
			    "The (%s) group name contains invalid characters."),
			    $_POST['gtype']);
		}
	}

	/* Check the POSTed members to ensure they are valid and exist */
	if (is_array($_POST['members'])) {
		foreach ($_POST['members'] as $newmember) {
			if (!is_numeric($newmember) ||
			    empty(getUserEntryByUID($newmember))) {
				$input_errors[] = gettext("One or more " .
				    "invalid group members was submitted.");
			}
		}
	}

	if (!$input_errors && !(isset($id) && config_get_path("system/group/{$id}"))) {
		/* make sure there are no dupes */
		foreach (config_get_path('system/group', []) as $group) {
			if ($group['name'] == $_POST['groupname']) {
				$input_errors[] = gettext("Another entry " .
				    "with the same group name already exists.");
				break;
			}
		}
	}

	if (!$input_errors) {
		$group = array();
		if (isset($id) && config_get_path("system/group/{$id}")) {
			$group = config_get_path("system/group/{$id}");
		}

		$group['name'] = $_POST['groupname'];
		$group['description'] = $_POST['description'];
		$group['scope'] = $_POST['gtype'];

		if (empty($_POST['members'])) {
			unset($group['member']);
		} else if ($group['gid'] != 1998) { // all group
			$group['member'] = $_POST['members'];
		}

		if (isset($id) && config_get_path("system/group/{$id}")) {
			config_set_path("system/group/{$id}", $group);
		} else {
			$nextgid = config_get_path('system/nextgid');
			$group['gid'] = $nextgid++;
			config_set_path('system/nextgid', $nextgid);
			if ($_POST['dup']) {
				$group['priv'] = config_get_path("system/group/{$_POST['dup']}/priv");
			}
			config_set_path('system/group/', $group);
		}

		admin_groups_sort();

		local_group_set($group);

		/*
		 * Refresh users in this group since their privileges may have
		 * changed.
		 */
		if (is_array($group['member'])) {
			foreach (config_get_path('system/user', []) as $idx => $user) {
				if (in_array($user['uid'], $group['member'])) {
					local_user_set($user);
					config_set_path("system/user/{$idx}", $user);
				}
			}
		}

		/* Sort it alphabetically */
		$group_config = config_get_path('system/group', []);
		usort($group_config, function($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		config_set_path('system/group', $group_config);

		$savemsg = sprintf(gettext("Successfully %s group %s"),
		    (strlen($id) > 0) ? gettext("edited") : gettext("created"),
		    $group['name']);
		write_config($savemsg);
		syslog($logging_level, "{$logging_prefix}: {$savemsg}");

		header("Location: system_groupmanager.php");
		exit;
	}

	$pconfig['name'] = $_POST['groupname'];
}

function build_priv_table() {
	global $id, $read_only, $dup;

	$privhtml = '<div class="table-responsive">';
	$privhtml .=	'<table class="table table-striped table-hover table-condensed">';
	$privhtml .=		'<thead>';
	$privhtml .=			'<tr>';
	$privhtml .=				'<th>' . gettext('Name') . '</th>';
	$privhtml .=				'<th>' . gettext('Description') . '</th>';
	$privhtml .=				'<th>' . gettext('Action') . '</th>';
	$privhtml .=			'</tr>';
	$privhtml .=		'</thead>';
	$privhtml .=		'<tbody>';

	$user_has_root_priv = false;

	if (isset($id)) {
		foreach (get_user_privdesc(config_get_path("system/group/{$id}")) as $i => $priv) {
			$privhtml .=		'<tr>';
			$privhtml .=			'<td>' . htmlspecialchars($priv['name']) . '</td>';
			$privhtml .=			'<td>' . htmlspecialchars($priv['descr']);
			if (isset($priv['warn']) && ($priv['warn'] == 'standard-warning-root')) {
				$privhtml .=			' ' . gettext('(admin privilege)');
				$user_has_root_priv = true;
			}
			$privhtml .=			'</td>';
			if (!$read_only && ($dup === null)) {
				$privhtml .=			'<td><a class="fa-solid fa-trash-can" title="' . gettext('Delete Privilege') . '"	href="system_groupmanager.php?act=delpriv&amp;groupid=' . $id . '&amp;privid=' . $i . '" usepost></a></td>';
			}
			$privhtml .=		'</tr>';
		}
	}

	if ($user_has_root_priv) {
		$privhtml .=		'<tr>';
		$privhtml .=			'<td colspan="2">';
		$privhtml .=				'<b>' . gettext('Security notice: Users in this group effectively have administrator-level access') . '</b>';
		$privhtml .=			'</td>';
		$privhtml .=			'<td>';
		$privhtml .=			'</td>';
		$privhtml .=		'</tr>';

	}

	$privhtml .=		'</tbody>';
	$privhtml .=	'</table>';
	$privhtml .= '</div>';

	$privhtml .= '<nav class="action-buttons">';
	if (!$read_only && ($dup === null)) {
		$privhtml .=	'<a href="system_groupmanager_addprivs.php?groupid=' . $id . '" class="btn btn-success"><i class="fa-solid fa-plus icon-embed-btn"></i>' . gettext("Add") . '</a>';
	}
	$privhtml .= '</nav>';

	return($privhtml);
}

$pgtitle = array(gettext("System"), gettext("User Manager"), gettext("Groups"));
$pglinks = array("", "system_usermanager.php", "system_groupmanager.php");

if ($act == "new" || $act == "edit") {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

$tab_array = array();
$tab_array[] = array(gettext("Users"), false, "system_usermanager.php");
$tab_array[] = array(gettext("Groups"), true, "system_groupmanager.php");
$tab_array[] = array(gettext("Settings"), false, "system_usermanager_settings.php");
$tab_array[] = array(gettext("Change Password"), false, "system_usermanager_passwordmg.php");
$tab_array[] = array(gettext("Authentication Servers"), false, "system_authservers.php");
display_top_tabs($tab_array);

if (!($act == "new" || $act == "edit")) {
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Groups')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-condensed sortable-theme-bootstrap table-rowdblclickedit" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Group name")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Member Count")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach (config_get_path('system/group', []) as $i => $group):
		if ($group["name"] == "all") {
			$groupcount = count(config_get_path('system/user', []));
		} elseif (is_array($group['member'])) {
			$groupcount = count($group['member']);
		} else {
			$groupcount = 0;
		}
?>
					<tr>
						<td>
							<?=htmlspecialchars($group['name'])?>
						</td>
						<td>
							<?=htmlspecialchars($group['description'])?>
						</td>
						<td>
							<?=$groupcount?>
						</td>
						<td>
							<a class="fa-solid fa-pencil" title="<?=gettext("Edit group"); ?>" href="?act=edit&amp;groupid=<?=$i?>"></a>
							<a class="fa-regular fa-clone" title="<?=gettext("Copy group"); ?>" href="?act=dup&amp;groupid=<?=$i?>"></a>
							<?php if (($group['scope'] != "system") && !$read_only): ?>
								<a class="fa-solid fa-trash-can"	title="<?=gettext("Delete group")?>" href="?act=delgroup&amp;groupid=<?=$i?>&amp;groupname=<?=$group['name']?>" usepost></a>
							<?php endif;?>
						</td>
					</tr>
<?php
	endforeach;
?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<?php if (!$read_only): ?>
	<a href="?act=new" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>
	<?php endif; ?>
</nav>
<?php
	include('foot.inc');
	exit;
}

$form = new Form;
$form->setAction('system_groupmanager.php?act=edit');
if ($dup === null) {
	$form->addGlobal(new Form_Input(
		'groupid',
		null,
		'hidden',
		$id
	));
} else {
	$form->addGlobal(new Form_Input(
		'dup',
		null,
		'hidden',
		$dup
	));
}

if (isset($id) && config_get_path("system/group/{$id}")) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));

	$form->addGlobal(new Form_Input(
		'gid',
		null,
		'hidden',
		$pconfig['gid']
	));
}

$section = new Form_Section('Group Properties');

$section->addInput($input = new Form_Input(
	'groupname',
	'*Group name',
	'text',
	$pconfig['name']
));

if ($pconfig['gtype'] == "system") {
	$input->setReadonly();

	$section->addInput(new Form_Input(
		'gtype',
		'*Scope',
		'text',
		$pconfig['gtype']
	))->setReadonly();
} else {
	$section->addInput(new Form_Select(
		'gtype',
		'*Scope',
		$pconfig['gtype'],
		["local" => gettext("Local"), "remote" => gettext("Remote")]
	))->setHelp("<span class=\"text-danger\">Warning: Changing this " .
	    "setting may affect the local groups file, in which case a " .
	    "reboot may be required for the changes to take effect.</span>");
}

$section->addInput(new Form_Input(
	'description',
	'Description',
	'text',
	$pconfig['description']
))->setHelp('Group description, for administrative information only');

$form->add($section);

/* all users group */
if ($pconfig['gid'] != 1998) {
	/* Group membership */
	$group = new Form_Group('Group membership');

	/*
	 * Make a list of all the groups configured on the system, and a list of
	 * those which this user is a member of
	 */
	$systemGroups = array();
	$usersGroups = array();

	foreach (config_get_path('system/user', []) as $user) {
		if (is_array($pconfig['members']) && in_array($user['uid'],
		    $pconfig['members'])) {
			/* Add it to the user's list */
			$usersGroups[ $user['uid'] ] = $user['name'];
		} else {
			/* Add it to the 'not a member of' list */
			$systemGroups[ $user['uid'] ] = $user['name'];
		}
	}

	$group->add(new Form_Select(
		'notmembers',
		null,
		array_combine((array)$pconfig['groups'],
		    (array)$pconfig['groups']),
		$systemGroups,
		true
	))->setHelp('Not members');

	$group->add(new Form_Select(
		'members',
		null,
		array_combine((array)$pconfig['groups'],
		    (array)$pconfig['groups']),
		$usersGroups,
		true
	))->setHelp('Members');

	$section->add($group);

	$group = new Form_Group('');

	$group->add(new Form_Button(
		'movetoenabled',
		'Move to "Members"',
		null,
		'fa-solid fa-angle-double-right'
	))->setAttribute('type','button')->removeClass('btn-primary')->addClass(
	    'btn-info btn-sm');

	$group->add(new Form_Button(
		'movetodisabled',
		'Move to "Not members',
		null,
		'fa-solid fa-angle-double-left'
	))->setAttribute('type','button')->removeClass('btn-primary')->addClass(
	    'btn-info btn-sm');

	$group->setHelp(
	    'Hold down CTRL (PC)/COMMAND (Mac) key to select multiple items.');
	$section->add($group);

}

if (isset($pconfig['gid']) || ($dup !== null)) {
	$section = new Form_Section('Assigned Privileges');

	$section->addInput(new Form_StaticText(
		null,
		build_priv_table()
	));


	$form->add($section);
}

print $form;
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// On click . .
	$("#movetodisabled").click(function() {
		moveOptions($('[name="members[]"] option'),
		    $('[name="notmembers[]"]'));
	});

	$("#movetoenabled").click(function() {
		moveOptions($('[name="notmembers[]"] option'),
		    $('[name="members[]"]'));
	});

	// On submit mark all the user's groups as "selected"
	$('form').submit(function() {
		AllServers($('[name="members[]"] option'), true);
	});
});
//]]>
</script>
<?php
include('foot.inc');
