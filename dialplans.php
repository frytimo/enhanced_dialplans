<?php

/*
 * FusionPBX
 * Version: MPL 1.1
 *
 * The contents of this file are subject to the Mozilla Public License Version
 * 1.1 (the "License"); you may not use this file except in compliance with
 * the License. You may obtain a copy of the License at
 * http://www.mozilla.org/MPL/
 *
 * Software distributed under the License is distributed on an "AS IS" basis,
 * WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
 * for the specific language governing rights and limitations under the
 * License.
 *
 * The Original Code is FusionPBX
 *
 * The Initial Developer of the Original Code is
 * Mark J Crane <markjcrane@fusionpbx.com>
 * Portions created by the Initial Developer are Copyright (C) 2008-2026
 * the Initial Developer. All Rights Reserved.
 *
 * Contributor(s):
 * Mark J Crane <markjcrane@fusionpbx.com>
 */

// includes files
global $settings, $domain_uuid, $database, $url;
require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/paging.php";

// check permissions
if (!(permission_exists('dialplan_view') || permission_exists('inbound_route_view') || permission_exists('outbound_route_view'))) {
	echo "access denied";
	exit;
}

function dialplan_normalize_name($name): string {
	return preg_replace('/[^a-z0-9]/', '', strtolower((string) $name));
}

function dialplan_normalize_xml($xml): string {
	$xml = str_replace(["\r\n", "\r"], "\n", (string) $xml);
	$xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
	$xml = preg_replace('/>\s+</', '><', $xml);
	return trim($xml);
}

function dialplan_build_original_file_map($dialplan_directory): array {
	$file_map = [];
	$paths = glob($dialplan_directory . '/*.xml') ?: [];
	foreach ($paths as $path) {
		$filename = basename($path);
		if (preg_match('/^(\d+)_([^\.]+)\.xml$/', $filename, $matches)) {
			$order = (int) $matches[1];
			$file_map[$order][] = [
				'path' => $path,
				'name' => $matches[2],
				'name_normalized' => dialplan_normalize_name($matches[2]),
			];
		}
	}
	return $file_map;
}

function dialplan_find_original_file($dialplan_order, $dialplan_name, $file_map): ?string {
	$order = (int) $dialplan_order;
	if (empty($file_map[$order]) || !is_array($file_map[$order])) {
		return null;
	}

	$candidates = $file_map[$order];
	if (count($candidates) === 1) {
		return $candidates[0]['path'];
	}

	$name_normalized = dialplan_normalize_name($dialplan_name);
	if ($name_normalized !== '') {
		foreach ($candidates as $candidate) {
			if ($candidate['name_normalized'] === $name_normalized) {
				return $candidate['path'];
			}
		}
		foreach ($candidates as $candidate) {
			if (strpos($candidate['name_normalized'], $name_normalized) !== false || strpos($name_normalized, $candidate['name_normalized']) !== false) {
				return $candidate['path'];
			}
		}
	}

	return $candidates[0]['path'];
}

$has_dialplan_add          = permission_exists('dialplan_add');
$has_dialplan_all          = permission_exists('dialplan_all');
$has_dialplan_context      = permission_exists('dialplan_context');
$has_dialplan_delete       = permission_exists('dialplan_delete');
$has_dialplan_edit         = permission_exists('dialplan_edit');
$has_dialplan_global       = permission_exists('dialplan_global');
$has_dialplan_xml          = permission_exists('dialplan_xml');
$has_domain_select         = permission_exists('domain_select');
$has_fifo_add              = permission_exists('fifo_add');
$has_fifo_delete           = permission_exists('fifo_delete');
$has_fifo_edit             = permission_exists('fifo_edit');
$has_inbound_route_add     = permission_exists('inbound_route_add');
$has_inbound_route_copy    = permission_exists('inbound_route_copy');
$has_inbound_route_delete  = permission_exists('inbound_route_delete');
$has_inbound_route_edit    = permission_exists('inbound_route_edit');
$has_outbound_route_add    = permission_exists('outbound_route_add');
$has_outbound_route_copy   = permission_exists('outbound_route_copy');
$has_outbound_route_delete = permission_exists('outbound_route_delete');
$has_outbound_route_edit   = permission_exists('outbound_route_edit');
$has_time_condition_add    = permission_exists('time_condition_add');
$has_time_condition_delete = permission_exists('time_condition_delete');
$has_time_condition_edit   = permission_exists('time_condition_edit');

if (!class_exists('url')) {
	require_once "resources/classes/url.php";
}

$url = url::from_request($settings);

// add multi-lingual support
$text = new text()->get();

// drop app uuid from the query if not from specific apps
$allowed_app_uuids = [
	'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4',  // inbound routes
	'8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3',  // outbound routes
	'16589224-c876-aeb3-f59f-523a1c0801f7',  // fifo queues
	'4b821450-926b-175a-af93-a03c441818b1',  // time conditions
];

// Remove prohibited apps
$url->add_query_filter(function (string $key, mixed $value, callable $next) use ($allowed_app_uuids) {
	if ($key === 'app_uuid' && !in_array($value, $allowed_app_uuids)) {
		return null;
	}
	return $next($key, $value);
});

// Check if app_uuid is set and valid, if not redirect to dialplans.php without app_uuid
$app_uuid = $url->get('app_uuid', '');
if (!empty($app_uuid) && is_uuid($app_uuid) && !in_array($app_uuid, $allowed_app_uuids)) {
	// redirect without the invalid app_uuid (avoid loop from url::from_request() preserving it)
	header('Location: dialplans.php');
	exit;
}

// get posted data
$action    = $url->post('action', '');
$dialplans = $url->post('dialplans', []);
$order_by  = $url->get('order_by', '');
$order     = $url->get('order', '');
$context   = $url->get('context', ''); // for use in the search form and links
$search    = $url->get('search', '');
$show      = $url->get('show', '');
$app_uuid  = $url->get('app_uuid', '');

$has_restore_action = (
	($app_uuid == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && $has_inbound_route_edit) ||
	($app_uuid == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && $has_outbound_route_edit) ||
	($app_uuid == "16589224-c876-aeb3-f59f-523a1c0801f7" && $has_fifo_edit) ||
	($app_uuid == "4b821450-926b-175a-af93-a03c441818b1" && $has_time_condition_edit) ||
	$has_dialplan_edit
);

// process the http post data by action
if (!empty($action) && is_array($dialplans) && @sizeof($dialplans) != 0) {
	// process action
	switch ($action) {
		case 'copy':
			if ($has_dialplan_add) {
				$obj = new dialplan;
				$obj->copy($dialplans);
			}
			break;
		case 'toggle':
			if ($has_dialplan_edit) {
				$obj = new dialplan;
				$obj->toggle($dialplans);
			}
			break;
		case 'restore_original':
			if ($has_restore_action) {
				$dialplan_directory = dirname(__DIR__) . "/dialplans/resources/switch/conf/dialplan";
				$file_map = dialplan_build_original_file_map($dialplan_directory);
				$restored_contexts = [];
				$restored_count = 0;

				foreach ($dialplans as $dialplan) {
					if (empty($dialplan['checked'])) {
						continue;
					}
					if (empty($dialplan['uuid']) || !is_uuid($dialplan['uuid'])) {
						continue;
					}

					$sql = "select dialplan_uuid, dialplan_context, dialplan_order, dialplan_name from v_dialplans ";
					$sql .= "where dialplan_uuid = :dialplan_uuid ";
					$sql .= "and (domain_uuid = :domain_uuid ";
					if ($has_dialplan_global) {
						$sql .= "or domain_uuid is null ";
					}
					$sql .= ") ";
					$parameters = [];
					$parameters['dialplan_uuid'] = $dialplan['uuid'];
					$parameters['domain_uuid'] = $domain_uuid;
					$row = $database->select($sql, $parameters, 'row');
					unset($sql, $parameters);

					if (!is_array($row) || @sizeof($row) == 0) {
						continue;
					}

					$original_file = dialplan_find_original_file($row['dialplan_order'], $row['dialplan_name'], $file_map);
					if (empty($original_file) || !is_file($original_file) || !is_readable($original_file)) {
						continue;
					}

					$original_xml = file_get_contents($original_file);
					if ($original_xml === false) {
						continue;
					}

					$array['dialplans'][] = [
						'dialplan_uuid' => $row['dialplan_uuid'],
						'dialplan_xml' => $original_xml,
					];
					$restored_contexts[] = $row['dialplan_context'];
					$restored_count++;
				}

				if (!empty($array['dialplans'])) {
					$database->save($array);
					unset($array);

					$cache = new cache;
					foreach (array_unique($restored_contexts) as $restored_context) {
						if ($restored_context == "\${domain_name}" || $restored_context == "global") {
							$restored_context = "*";
						}
						$cache->delete("dialplan:" . $restored_context);
					}
				}

				if ($restored_count > 0) {
					message::add('Restored ' . $restored_count . ' dialplan XML ' . ($restored_count == 1 ? 'entry' : 'entries') . ' from original files.');
				}
				else {
					message::add('No matching original XML file was found for the selected dialplan entries.', 'negative');
				}
			}
			break;
		case 'delete':
			if ($has_dialplan_delete) {
				$obj = new dialplan;
				$obj->delete($dialplans);
			}
			break;
	}

	// redirect keeps all the params in the url automatically
	url::redirect(dialplan::LIST_PAGE);
	exit;
}

// get order and order by and sanitize the values
$order_by = $url->get('order_by', '');
$order    = $url->get('order', '');

// make sure all dialplans with context of public have the inbound route app_uuid
if (!empty($app_uuid) && $app_uuid == 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4') {
	$sql  = "update v_dialplans set ";
	$sql .= "app_uuid = 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4' ";
	$sql .= "where dialplan_context = 'public' ";
	$sql .= "and app_uuid is null; ";
	$database->execute($sql);
	unset($sql);
}

// set from session variables
$button_icon_add      = $settings->get('theme', 'button_icon_add',    '');
$button_icon_all      = $settings->get('theme', 'button_icon_all',    '');
$button_icon_copy     = $settings->get('theme', 'button_icon_copy',   '');
$button_icon_edit     = $settings->get('theme', 'button_icon_edit',   '');
$button_icon_reset    = $settings->get('theme', 'button_icon_reset',  '');
$button_icon_toggle   = $settings->get('theme', 'button_icon_toggle', '');
$button_icon_delete   = $settings->get('theme', 'button_icon_delete', '');
$button_icon_search   = $settings->get('theme', 'button_icon_search', '');
$list_row_edit_button = $settings->get('theme', 'list_row_edit_button', false);

// get the number of rows in the dialplan
$sql = "select count(dialplan_uuid) from v_dialplans ";
if ($show == "all" && $has_dialplan_all) {
	$sql .= "where true ";
} else {
	$sql .= "where (domain_uuid = :domain_uuid ";
	$sql .= "or domain_uuid is null ";
	$sql .= ") ";
	$parameters['domain_uuid'] = $domain_uuid;
}
if (empty($app_uuid)) {
	// hide inbound routes
	$sql .= "and (app_uuid is null or app_uuid <> 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4') ";
	$sql .= "and dialplan_context <> 'public' ";
	// hide outbound routes
	// $sql .= "and (app_uuid is null or app_uuid <> '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3') ";
	if (!empty($context)) {
		$sql                           .= "and dialplan_context = :dialplan_context ";
		$parameters['dialplan_context'] = $context;
	}
} else {
	if ($app_uuid == 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4') {
		$sql .= "and (app_uuid = :app_uuid or dialplan_context = 'public') ";
	} else {
		$sql .= "and app_uuid = :app_uuid ";
	}
	$parameters['app_uuid'] = $app_uuid;
	if (!empty($context)) {
		$sql                           .= "and dialplan_context = :dialplan_context ";
		$parameters['dialplan_context'] = $context;
	}
}
if (!empty($search)) {
	$search = strtolower($search);
	$sql   .= "and (";
	$sql   .= " 	lower(dialplan_context) like :search ";
	$sql   .= " 	or lower(dialplan_name) like :search ";
	$sql   .= " 	or lower(dialplan_number) like :search ";
	$sql   .= " 	or lower(dialplan_description) like :search ";
	if (is_numeric($search)) {
		$sql                         .= " 	or dialplan_order = :search_numeric ";
		$parameters['search_numeric'] = $search;
	}
	$sql                 .= ") ";
	$parameters['search'] = '%' . $search . '%';
}
$num_rows = $database->select($sql, $parameters ?? null, 'column');

$url->set_total_rows($num_rows);
$rows_per_page        = $url->get_rows_per_page();
$offset               = $url->offset();
$paging_controls      = url::html_paging_controls($url);
$paging_controls_mini = url::html_paging_mini_controls($url);

// get the list of dialplans
$sql  = "
	SELECT
		domain_uuid,
		dialplan_uuid,
		app_uuid,
		hostname,
		dialplan_context,
		dialplan_name,
		dialplan_number,
		dialplan_destination,
		dialplan_continue,
		dialplan_xml,
		dialplan_order,
		dialplan_enabled,
		dialplan_description
	FROM
		v_dialplans
";
if ($show == "all" && $has_dialplan_all) {
	$sql .= "where true ";
} else {
	$sql .= "where (";
	$sql .= "	domain_uuid = :domain_uuid ";
	$sql .= "	or domain_uuid is null ";
	$sql .= ") ";
	$parameters['domain_uuid'] = $domain_uuid;
}
if (!is_uuid($app_uuid)) {
	// hide inbound routes
	$sql .= "and (app_uuid is null or app_uuid <> 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4') ";
	$sql .= "and dialplan_context <> 'public' ";
	// hide outbound routes
	// $sql .= "and (app_uuid is null or app_uuid <> '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3') ";
	if (!empty($context)) {
		$sql                           .= "and dialplan_context = :dialplan_context ";
		$parameters['dialplan_context'] = $context;
	}
} else {
	if ($app_uuid == 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4') {
		$sql .= "and (app_uuid = :app_uuid or dialplan_context = 'public') ";
	} else {
		$sql .= "and app_uuid = :app_uuid ";
	}
	$parameters['app_uuid'] = $app_uuid;
	if (!empty($context)) {
		$sql                           .= "and dialplan_context = :dialplan_context ";
		$parameters['dialplan_context'] = $context;
	}
}
if (!empty($search)) {
	$sql .= "and (";
	$sql .= "	lower(dialplan_context) like :search ";
	$sql .= "	or lower(dialplan_name) like :search ";
	$sql .= "	or lower(dialplan_number) like :search ";
	$sql .= "	or lower(dialplan_description) like :search ";
	if (is_numeric($search)) {
		$sql                         .= " 	or dialplan_order = :search_numeric ";
		$parameters['search_numeric'] = $search;
	}
	$sql                 .= ") ";
	$parameters['search'] = '%' . $search . '%';
}
if (!empty($order_by)) {
	if ($order_by == 'dialplan_name' || $order_by == 'dialplan_description') {
		$sql .= 'order by lower(' . $order_by . ') ' . $order . ' ';
	} else {
		$sql .= order_by($order_by, $order);
	}
} else {
	$sql .= "order by dialplan_order asc, lower(dialplan_name) asc ";
}
$sql      .= limit_offset($rows_per_page, $offset);
$dialplans = $database->select($sql, $parameters ?? null, 'all');
unset($sql, $parameters);

// compare current dialplans with original app XML files
$original_dialplan_directory = dirname(__DIR__) . "/dialplans/resources/switch/conf/dialplan";
$original_file_map = dialplan_build_original_file_map($original_dialplan_directory);
$original_xml_cache = [];
$all_original_status_match = !empty($dialplans);

// UI-managed dialplan apps do not use static baseline XML files.
$original_compare_excluded_app_uuids = [
	'a6a7c4c5-340a-43ce-bcbc-2ed9bab8659d', // bridges
	'9ed63276-e085-4897-839c-4f2e36d92d6c', // call block
	'efc11f6b-ed73-9955-4d4d-3a1bed75a056', // call broadcast
	'95788e50-9500-079e-2807-fd530b0ea370', // call centers
	'4a085c51-7635-ff03-f67b-86e834422848', // call detail records (xml cdr)
	'b1b70f85-6b42-429b-8c5a-60c8b02b7d14', // call flows
	'19806921-e8ed-dcff-b325-dd3e5da4959d', // call forward
	'56165644-598d-4ed8-be01-d960bcb8ffed', // call recordings
	'8d083f5a-f726-42a8-9ffa-8d28f848f10e', // conference centers
	'e1ad84a2-79e1-450c-a5b1-7507a043e048', // conference controls
	'c33e2c2a-847f-44c1-8c0d-310df5d65ba9', // conference profiles
	'b81412e8-7253-91f4-e48e-42fc2c9a38d9', // conferences
	'24108154-4ac3-1db6-1551-4731703a4440', // fax
	'a5788e9b-58bc-bd1b-df59-fff5d51253ab', // ivr menus
	'f5210fba-337d-4e05-86b6-7a2fd9dc7c42', // follow me
	'5c6f597c-9b78-11e4-89d3-123b93f75cba', // phrases
	'16589224-c876-aeb3-f59f-523a1c0801f7', // queues (fifo)
	'83913217-c7a2-9e90-925d-a866eb40b60e', // recordings
	'1d61fb65-1eec-bc73-a6ee-a6203b4fe6f2', // ring groups
	'4b821450-926b-175a-af93-a03c441818b1', // time conditions
	'b523c2d2-64cd-46f1-9520-ca4b4098e044', // voicemails
];

if (!empty($dialplans)) {
	foreach ($dialplans as $x => $row) {
		if (in_array(($row['app_uuid'] ?? ''), $original_compare_excluded_app_uuids, true)) {
			$dialplans[$x]['original_xml_status'] = 'missing';
			continue;
		}

		$original_file = dialplan_find_original_file($row['dialplan_order'], $row['dialplan_name'], $original_file_map);
		$dialplans[$x]['original_xml_status'] = 'missing';

		if (!empty($original_file) && is_file($original_file) && is_readable($original_file)) {
			if (!isset($original_xml_cache[$original_file])) {
				$original_xml_cache[$original_file] = file_get_contents($original_file);
			}
			if ($original_xml_cache[$original_file] !== false) {
				$original_xml = dialplan_normalize_xml($original_xml_cache[$original_file]);
				$current_xml = dialplan_normalize_xml($row['dialplan_xml'] ?? '');
				$dialplans[$x]['original_xml_status'] = ($original_xml === $current_xml) ? 'match' : 'diff';
			}
		}

		// N/A ('missing') counts as match for the header-state indicator.
		if ($dialplans[$x]['original_xml_status'] === 'diff') {
			$all_original_status_match = false;
		}
	}
}

// get the list of all dialplan contexts
$sql  = "select dc.* from ( ";
$sql .= "select distinct dialplan_context from v_dialplans ";
if ($show == "all" && $has_dialplan_all) {
	$sql .= "where true ";
} else {
	$sql                      .= "where (domain_uuid = :domain_uuid or domain_uuid is null) ";
	$parameters['domain_uuid'] = $domain_uuid;
}
if (!is_uuid($app_uuid)) {
	// hide inbound routes
	$sql .= "and (app_uuid is null or app_uuid <> 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4') ";
	$sql .= "and dialplan_context <> 'public' ";
} else {
	$sql                   .= "and (app_uuid = :app_uuid " . ($app_uuid == 'c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4' ? "or dialplan_context = 'public'" : null) . ") ";
	$parameters['app_uuid'] = $app_uuid;
}
$sql .= ") as dc ";
$rows = $database->select($sql, $parameters ?? null, 'all');
if (is_array($rows) && @sizeof($rows) != 0) {
	foreach ($rows as $row) {
		// reverse the array's (string) values in preparation to sort
		$dialplan_contexts[] = strrev($row['dialplan_context']);
	}
	// sort the reversed context values, now grouping them by the domain
	sort($dialplan_contexts, SORT_NATURAL);
	// create new array
	foreach ($dialplan_contexts as $dialplan_context) {
		// if no subcontext (doesn't contain '@'), create new key in array with a null value
		if (!substr_count($dialplan_context, '@') || strrev($dialplan_context) == 'global' || strrev($dialplan_context) == 'public') {
			$array[strrev($dialplan_context)] = null;
		}
		// subcontext (contains '@'), create new key in array, and place subcontext in subarray
		else {
			$dialplan_context_parts                      = explode('@', $dialplan_context);
			$array[strrev($dialplan_context_parts[0])][] = strrev($dialplan_context_parts[1]);
		}
	}
	// sort array by key (domain)
	ksort($array, SORT_NATURAL);
	// move global and public to beginning of array
	if (array_key_exists('global', $array)) {
		unset($array['global']);
		$array = array_merge(['global' => null], $array);
	}
	if (array_key_exists('public', $array)) {
		unset($array['public']);
		$array = array_merge(['public' => null], $array);
	}
	$dialplan_contexts = $array;
	unset($dialplan_context, $array, $dialplan_context_parts);
}
unset($sql, $parameters, $rows, $row);

// create token
$object = new token;
$token  = $object->create($_SERVER['PHP_SELF']);

// page title, header and description per app_uuid
switch ($app_uuid) {
	case "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4":
		$page_title       = $text['title-inbound_routes'];
		$page_header      = $text['header-inbound_routes'];
		$page_description = $text['description-inbound_routes'];
		break;
	case "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3":
		$page_title       = $text['title-outbound_routes'];
		$page_header      = $text['header-outbound_routes'];
		$page_description = $text['description-outbound_routes'];
		break;
	case "16589224-c876-aeb3-f59f-523a1c0801f7":
		$page_title       = $text['title-queues'];
		$page_header      = $text['header-queues'];
		$page_description = $text['description-queues'];
		break;
	case "4b821450-926b-175a-af93-a03c441818b1":
		$page_title       = $text['title-time_conditions'];
		$page_header      = $text['header-time_conditions'];
		$page_description = $text['description-time_conditions'];
		break;
	default:
		$page_title       = $text['title-dialplan_manager'];
		$page_header      = $text['header-dialplan_manager'];
		$page_description = $text['description-dialplan_manager' . ($has_dialplan_edit ? '-superadmin' : '')];
}

// compute permission flags used throughout the rendering
$has_show_copy   = (
	($app_uuid == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && $has_inbound_route_copy) ||
	($app_uuid == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && $has_outbound_route_copy) ||
	($app_uuid == "16589224-c876-aeb3-f59f-523a1c0801f7" && $has_fifo_add) ||
	($app_uuid == "4b821450-926b-175a-af93-a03c441818b1" && $has_time_condition_add) ||
	$has_dialplan_add
);
$has_show_toggle = (
	($app_uuid == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && $has_inbound_route_edit) ||
	($app_uuid == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && $has_outbound_route_edit) ||
	($app_uuid == "16589224-c876-aeb3-f59f-523a1c0801f7" && $has_fifo_edit) ||
	($app_uuid == "4b821450-926b-175a-af93-a03c441818b1" && $has_time_condition_edit) ||
	$has_dialplan_edit
);
$has_show_delete = (
	($app_uuid == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && $has_inbound_route_delete) ||
	($app_uuid == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && $has_outbound_route_delete) ||
	($app_uuid == "16589224-c876-aeb3-f59f-523a1c0801f7" && $has_fifo_delete) ||
	($app_uuid == "4b821450-926b-175a-af93-a03c441818b1" && $has_time_condition_delete) ||
	$has_dialplan_delete
);
$show_checkbox   = (
	(!is_uuid($app_uuid) && ($has_dialplan_add || $has_dialplan_edit || $has_dialplan_delete)) ||
	($app_uuid == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && ($has_inbound_route_copy || $has_inbound_route_edit || $has_inbound_route_delete)) ||
	($app_uuid == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && ($has_outbound_route_copy || $has_outbound_route_edit || $has_outbound_route_delete)) ||
	($app_uuid == "16589224-c876-aeb3-f59f-523a1c0801f7" && ($has_fifo_add || $has_fifo_edit || $has_fifo_delete)) ||
	($app_uuid == "4b821450-926b-175a-af93-a03c441818b1" && ($has_time_condition_add || $has_time_condition_edit || $has_time_condition_delete))
);
$has_edit_column = $list_row_edit_button && $has_show_toggle;

// build the add button url
$button_add_url = '';
if ($app_uuid == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && $has_inbound_route_add) {
	$button_add_url = PROJECT_PATH . "/app/dialplan_inbound/dialplan_inbound_add.php";
} else if ($app_uuid == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && $has_outbound_route_add) {
	$button_add_url = PROJECT_PATH . "/app/dialplan_outbound/dialplan_outbound_add.php";
} else if ($app_uuid == "16589224-c876-aeb3-f59f-523a1c0801f7" && $has_fifo_add) {
	$button_add_url = PROJECT_PATH . "/app/fifo/fifo_add.php";
} else if ($app_uuid == "4b821450-926b-175a-af93-a03c441818b1" && $has_time_condition_add) {
	$button_add_url = PROJECT_PATH . "/app/time_conditions/time_condition_edit.php";
} else if ($has_dialplan_add) {
	$button_add_url = PROJECT_PATH . "/app/enhanced_dialplans/dialplan_edit.php";
}

// build the action bar buttons
$btn_add = '';
if (!empty($button_add_url)) {
	$btn_add = button::create(['type' => 'button', 'label' => $text['button-add'], 'icon' => $button_icon_add, 'id' => 'btn_add', 'link' => $button_add_url]);
}
$btn_copy = '';
if (!empty($dialplans) && $has_show_copy) {
	$btn_copy = button::create(['type' => 'button', 'label' => $text['button-copy'], 'icon' => $button_icon_copy, 'id' => 'btn_copy', 'name' => 'btn_copy', 'style' => 'display: none;', 'onclick' => "modal_open('modal-copy','btn_copy');"]);
}
$btn_toggle = '';
if (!empty($dialplans) && $has_show_toggle) {
	$btn_toggle = button::create(['type' => 'button', 'label' => $text['button-toggle'], 'icon' => $button_icon_toggle, 'id' => 'btn_toggle', 'name' => 'btn_toggle', 'style' => 'display: none;', 'onclick' => "modal_open('modal-toggle','btn_toggle');"]);
}
$btn_delete = '';
if (!empty($dialplans) && $has_show_delete) {
	$btn_delete = button::create(['type' => 'button', 'label' => $text['button-delete'], 'icon' => $button_icon_delete, 'id' => 'btn_delete', 'name' => 'btn_delete', 'style' => 'display: none;', 'onclick' => "modal_open('modal-delete','btn_delete');"]);
}
$btn_restore = '';
if (!empty($dialplans) && $has_show_toggle) {
	$btn_restore = button::create([
		'type' => 'button',
		'label' => 'Restore',
		'icon' => ($button_icon_reset !== '' ? $button_icon_reset : 'undo'),
		'id' => 'btn_restore',
		'name' => 'btn_restore',
		'class' => '+revealed',
		'style' => 'display: none;',
		'onclick' => "list_action_set('restore_original'); list_form_submit('form_list');",
	]);
}
$btn_xml = '';
if ($has_dialplan_xml) {
	$xml_link = PROJECT_PATH . '/app/enhanced_dialplans/dialplan_xml.php';
	$xml_params = [];
	if (is_uuid($app_uuid)) {
		$xml_params[] = 'app_uuid=' . urlencode($app_uuid);
	}
	if (!empty($context)) {
		$xml_params[] = 'context=' . urlencode($context);
	}
	if (!empty($xml_params)) {
		$xml_link .= '?' . implode('&', $xml_params);
	}
	$btn_xml = button::create(['type' => 'button', 'label' => $text['button-xml'], 'icon' => 'code', 'style' => 'margin-left: 3px;', 'link' => $xml_link]);
}
$show_all_params = [];
if (!empty($app_uuid)) {
	$show_all_params[] = "app_uuid=" . urlencode($app_uuid);
}
if (!empty($context)) {
	$show_all_params[] = "context=" . urlencode($context);
}
if (!empty($search)) {
	$show_all_params[] = "search=" . urlencode($search);
}
if (!empty($order_by)) {
	$show_all_params[] = "order_by=" . urlencode($order_by);
}
if (!empty($order)) {
	$show_all_params[] = "order=" . urlencode($order);
}
$btn_show_all = '';
if ($has_dialplan_all && $show !== 'all') {
	$btn_show_all = button::create(['type' => 'button', 'label' => $text['button-show_all'], 'icon' => $button_icon_all, 'link' => '?show=all' . (!empty($show_all_params) ? '&' . implode('&', $show_all_params) : null)]);
}
unset($show_all_params);
$btn_search = button::create(['label' => $text['button-search'], 'icon' => $button_icon_search, 'type' => 'submit', 'id' => 'btn_search']);

// build the modals
$modal_copy = '';
if (!empty($dialplans) && $has_show_copy) {
	$modal_copy = modal::create(['id' => 'modal-copy', 'type' => 'copy', 'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'id' => 'btn_copy', 'style' => 'float: right; margin-left: 15px;', 'collapse' => 'never', 'onclick' => "modal_close(); list_action_set('copy'); list_form_submit('form_list');"])]);
}
$modal_toggle = '';
if (!empty($dialplans) && $has_show_toggle) {
	$modal_toggle = modal::create(['id' => 'modal-toggle', 'type' => 'toggle', 'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'id' => 'btn_toggle', 'style' => 'float: right; margin-left: 15px;', 'collapse' => 'never', 'onclick' => "modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
}
$modal_delete = '';
if (!empty($dialplans) && $has_show_delete) {
	$modal_delete = modal::create(['id' => 'modal-delete', 'type' => 'delete', 'actions' => button::create(['type' => 'button', 'label' => $text['button-continue'], 'icon' => 'check', 'id' => 'btn_delete', 'style' => 'float: right; margin-left: 15px;', 'collapse' => 'never', 'onclick' => "modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
}

// build the context selector
$context_selector = '';
if ($has_dialplan_context) {
	$ctx_max_width     = (empty($context) || $context == 'global') ? '80px' : '140px';
	$context_selector  = "<select name='context' id='context' class='formfld' style='max-width: " . $ctx_max_width . "; margin-left: 18px;' onchange=\"$('#form_search').submit();\">\n";
	$context_selector .= "<option value='' " . (!$context ? "selected='selected'" : null) . " disabled='disabled'>" . $text['label-context'] . "...</option>\n";
	$context_selector .= "<option value=''></option>\n";
	if (!empty($dialplan_contexts) && is_array($dialplan_contexts)) {
		foreach ($dialplan_contexts as $dialplan_context => $dialplan_subcontexts) {
			if (is_array($dialplan_subcontexts) && @sizeof($dialplan_subcontexts) != 0) {
				$context_selector .= "<option value='" . $dialplan_context . "' " . ($context == $dialplan_context ? "selected='selected'" : null) . ">" . escape($dialplan_context) . "</option>\n";
				foreach ($dialplan_subcontexts as $dialplan_subcontext) {
					$context_selector .= "<option value='" . $dialplan_subcontext . "@" . $dialplan_context . "' " . ($context == $dialplan_subcontext . "@" . $dialplan_context ? "selected='selected'" : null) . ">&nbsp;&nbsp;&nbsp;" . escape($dialplan_subcontext) . "@</option>\n";
				}
			} else {
				$dialplan_context_label = in_array($dialplan_context, ['global', 'public']) ? ucwords($dialplan_context) : $dialplan_context;
				$context_selector      .= "<option value='" . $dialplan_context . "' " . ($context == $dialplan_context ? "selected='selected'" : null) . ">" . escape($dialplan_context_label) . "</option>\n";
			}
		}
	}
	$context_selector .= "</select>\n";
}

// build the table header columns
$sort_params = [];
if (!empty($app_uuid)) {
	$sort_params[] = "app_uuid=" . urlencode($app_uuid);
}
if (!empty($context)) {
	$sort_params[] = "context=" . urlencode($context);
}
if (!empty($search)) {
	$sort_params[] = "search=" . urlencode($search);
}
if ($show == 'all' && $has_dialplan_all) {
	$sort_params[] = "show=all";
}
$sort_param_str = !empty($sort_params) ? implode('&', $sort_params) : null;
unset($sort_params);
$th_domain_name = '';
if ($show == 'all' && $has_dialplan_all) {
	$th_domain_name = "<th>" . $text['label-domain'] . "</th>";
}
$th_name        = th_order_by('dialplan_name', $text['label-name'], $order_by, $order, $app_uuid, null, $sort_param_str);
$th_number      = th_order_by('dialplan_number', $text['label-number'], $order_by, $order, $app_uuid, null, $sort_param_str);
$th_context_col = th_order_by('dialplan_context', $text['label-context'], $order_by, $order, $app_uuid, null, $sort_param_str);
$th_order_col   = th_order_by('dialplan_order', $text['label-order'], $order_by, $order, $app_uuid, "class='center shrink'", $sort_param_str);
$th_original_col = "<th class='center shrink' style='width: 1%; white-space: nowrap; padding-left: 4px; padding-right: 4px;'>".
	($all_original_status_match
		? "<span title='All rows match most recent version' style='color: #16a34a;'><i class='fas fa-check-circle' aria-hidden='true'></i></span>"
		: "<span title='One or more rows do not match most recent version' style='color: #b45309;'><i class='fas fa-exclamation-triangle' aria-hidden='true'></i></span>").
"</th>";
$th_enabled     = th_order_by('dialplan_enabled', $text['label-enabled'], $order_by, $order, $app_uuid, "class='center'", $sort_param_str);
$th_description = th_order_by('dialplan_description', $text['label-description'], $order_by, $order, $app_uuid, "class='hide-sm-dn' style='min-width: 100px;'", $sort_param_str);
unset($sort_param_str);

// build the row data
$x = 0;
foreach ($dialplans as &$row) {
	$list_row_url = '';
	if ($row['app_uuid'] == "4b821450-926b-175a-af93-a03c441818b1") {
		if ($has_time_condition_edit || $has_dialplan_edit) {
			$list_row_url = PROJECT_PATH . "/app/time_conditions/time_condition_edit.php?id=" . urlencode($row['dialplan_uuid']) . (is_uuid($app_uuid) ? "&app_uuid=" . urlencode($app_uuid) : null);
			if ($row['domain_uuid'] != $_SESSION['domain_uuid'] && $has_domain_select) {
				$list_row_url .= '&domain_uuid=' . urlencode($row['domain_uuid']) . '&domain_change=true';
			}
		}
	} else if (
		($row['app_uuid'] == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && $has_inbound_route_edit) ||
		($row['app_uuid'] == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && $has_outbound_route_edit) ||
		($row['app_uuid'] == "16589224-c876-aeb3-f59f-523a1c0801f7" && $has_fifo_edit) ||
		$has_dialplan_edit
	) {
		$list_row_url = "dialplan_edit.php?id=" . urlencode($row['dialplan_uuid']) . (is_uuid($app_uuid) ? "&app_uuid=" . urlencode($app_uuid) : null);
		if ($row['domain_uuid'] != $_SESSION['domain_uuid'] && $has_domain_select) {
			$list_row_url .= '&domain_uuid=' . urlencode($row['domain_uuid'] ?? '') . '&domain_change=true';
		}
	}
	$row['_list_row_url']         = $list_row_url;
	$dialplan_description         = $row['dialplan_description'] ?? ($text['description-dialplan_' . $row['dialplan_name']] ?? '');
	$dialplan_description         = str_replace('${number}', $row['dialplan_number'], $dialplan_description);
	$row['_dialplan_description'] = $dialplan_description;
	if ($show == 'all' && $has_dialplan_all) {
		$row['_domain'] = !empty($_SESSION['domains'][$row['domain_uuid']]['domain_name'])
			? $_SESSION['domains'][$row['domain_uuid']]['domain_name']
			: $text['label-global'];
	} else {
		$row['_domain'] = '';
	}
	$row['_number']        = !empty($row['dialplan_number']) ? format_phone($row['dialplan_number']) : '';
	$has_row_toggle        = (
		(!is_uuid($app_uuid) && $has_dialplan_edit) ||
		($row['app_uuid'] == "c03b422e-13a8-bd1b-e42b-b6b9b4d27ce4" && $has_inbound_route_edit) ||
		($row['app_uuid'] == "8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3" && $has_outbound_route_edit) ||
		($row['app_uuid'] == "16589224-c876-aeb3-f59f-523a1c0801f7" && $has_fifo_edit) ||
		($row['app_uuid'] == "4b821450-926b-175a-af93-a03c441818b1" && $has_time_condition_edit)
	);
	$row['_has_toggle']    = $has_row_toggle;
	$row['_original_status'] = $row['original_xml_status'] ?? 'missing';
	$row['_restore_button'] = '';
	if ($row['_original_status'] === 'diff' && $has_row_toggle) {
		$row['_restore_button'] = button::create([
			'type' => 'submit',
			'class' => 'link',
			'icon' => 'exclamation-triangle',
			'title' => 'Mismatch detected. Click to restore.',
			'style' => 'color: #b45309; padding: 0; min-width: 0; margin: 0;',
			'onclick' => "list_self_check('checkbox_" . $x . "'); list_action_set('restore_original'); list_form_submit('form_list')",
		]);
	}
	$row['_toggle_button'] = '';
	if ($has_row_toggle) {
		$row['_toggle_button'] = button::create(['type' => 'submit', 'class' => 'link', 'label' => $text['label-' . ($row['dialplan_enabled'] ? 'true' : 'false')], 'title' => $text['button-toggle'], 'onclick' => "list_self_check('checkbox_" . $x . "'); list_action_set('toggle'); list_form_submit('form_list')"]);
	}
	$row['_edit_button'] = '';
	if ($has_edit_column && $has_row_toggle && !empty($list_row_url)) {
		$row['_edit_button'] = button::create(['type' => 'button', 'title' => $text['button-edit'], 'icon' => $button_icon_edit, 'link' => $list_row_url]);
	}
	$x++;
}
unset($row);

// build the template
$template               = new template();
$template->engine       = 'smarty';
$template->template_dir = __DIR__ . '/resources/views';
$template->cache_dir    = sys_get_temp_dir();
$template->init();

// assign the template variables
$template->assign('text', $text);
$template->assign('num_rows', $num_rows);
$template->assign('dialplans', $dialplans ?? []);
$template->assign('app_uuid', $app_uuid);
$template->assign('context', $context);
$template->assign('search', $search);
$template->assign('order_by', $order_by);
$template->assign('order', $order);
$template->assign('show', $show);
$template->assign('paging_controls', $paging_controls);
$template->assign('paging_controls_mini', $paging_controls_mini);
$template->assign('token', $token);
$template->assign('has_dialplan_add', $has_dialplan_add);
$template->assign('has_dialplan_all', $has_dialplan_all);
$template->assign('has_dialplan_context', $has_dialplan_context);
$template->assign('has_dialplan_delete', $has_dialplan_delete);
$template->assign('has_dialplan_edit', $has_dialplan_edit);
$template->assign('has_dialplan_xml', $has_dialplan_xml);
$template->assign('has_domain_select', $has_domain_select);
$template->assign('list_row_edit_button', $list_row_edit_button);
$template->assign('show_checkbox', $show_checkbox);
$template->assign('has_edit_column', $has_edit_column);
$template->assign('page_header', $page_header);
$template->assign('page_description', $page_description);
$template->assign('btn_add', $btn_add);
$template->assign('btn_copy', $btn_copy);
$template->assign('btn_toggle', $btn_toggle);
$template->assign('btn_delete', $btn_delete);
$template->assign('btn_restore', $btn_restore);
$template->assign('btn_xml', $btn_xml);
$template->assign('btn_show_all', $btn_show_all);
$template->assign('btn_search', $btn_search);
$template->assign('modal_copy', $modal_copy);
$template->assign('modal_toggle', $modal_toggle);
$template->assign('modal_delete', $modal_delete);
$template->assign('context_selector', $context_selector);
$template->assign('th_domain_name', $th_domain_name);
$template->assign('th_name', $th_name);
$template->assign('th_number', $th_number);
$template->assign('th_context_col', $th_context_col);
$template->assign('th_order_col', $th_order_col);
$template->assign('th_original_col', $th_original_col);
$template->assign('th_enabled', $th_enabled);
$template->assign('th_description', $th_description);

// invoke pre-render hook
app::dispatch_list_pre_render('dialplan_list_page_hook', $url, $template);

// include the header
$document['title'] = $page_title;
require_once "resources/header.php";

// render the template
$html = $template->render('dialplans_list.tpl');

// invoke post-render hook
app::dispatch_list_post_render('dialplan_list_page_hook', $url, $html);

echo $html;

// include the footer
require_once "resources/footer.php";
