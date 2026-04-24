<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Portions created by the Initial Developer are Copyright (C) 2008-2026
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/

/*
 * Populate v_dialplans.dialplan_hash with the canonical MD5 hash of the
 * shipped baseline XML file that each row was installed from.
 *
 * Why this runs during `php core/upgrade/upgrade.php`:
 *
 *   The Enhanced Dialplan Manager UI (dialplans.php) highlights rows that
 *   have drifted from the FusionPBX-provided baseline and offers a
 *   "Restore original" action. Accurate diff detection requires XML
 *   canonicalization, which is expensive (DOMDocument parse of 85 shipped
 *   files plus each row's stored XML). Doing this work on every page load
 *   is wasteful because the shipped files only change when FusionPBX is
 *   upgraded. Pre-computing the baseline hashes here turns a per-page-load
 *   parse of 85 files into a single-column lookup.
 *
 * Ordering guarantee:
 *
 *   resources/classes/domains.php::upgrade() iterates the glob pattern
 *   "dirname(__DIR__, 2) . '/[all]/[all]/app_defaults.php'" — glob() returns
 *   paths in alphabetical order, so app/dialplans/app_defaults.php runs
 *   BEFORE app/enhanced_dialplans/app_defaults.php. This means v_dialplans
 *   has already been populated/refreshed by the core dialplans importer by
 *   the time we compute hashes here — so the hashes we store always reflect
 *   the current shipped XML files for the rows that exist right now.
 *
 * Idempotency:
 *
 *   Re-running upgrade.php recomputes and overwrites dialplan_hash for
 *   every row that has a matching baseline file. Rows with no matching
 *   baseline (custom dialplans, UI-managed apps like conferences / ring
 *   groups / voicemails) retain NULL in dialplan_hash and are treated as
 *   "no baseline available" ('missing') by the UI. There is no need for
 *   a dirty-tracking flag.
 *
 * Runtime invalidation:
 *
 *   dialplan_hash only ever stores the BASELINE hash. It does not need to
 *   be invalidated when a user edits a dialplan — the UI computes the
 *   current row's canonical hash on demand (in-memory cached) and compares
 *   it to the stored baseline hash. An edited row's current hash will
 *   diverge from dialplan_hash, and the UI will correctly report 'diff'.
 */

if ($domains_processed == 1) {

	require_once __DIR__ . '/resources/functions/dialplan_canonicalize.php';

	$baseline_dir = dialplan_baseline_directory();
	if (is_dir($baseline_dir)) {

		// Build order+name → canonical-hash map for shipped XML files.
		$file_map  = dialplan_build_original_file_map($baseline_dir);
		$file_hash = [];
		$file_enabled = [];
		foreach ($file_map as $key => $path) {
			$xml = @file_get_contents($path);
			if ($xml === false) {
				continue;
			}
			$canonical = dialplan_canonicalize_xml($xml);
			if ($canonical === null) {
				// Unparseable baseline — skip; affected rows will fall through
				// to the slow-path compare in the UI.
				continue;
			}
			$file_hash[$key] = md5($canonical);
			$enabled = dialplan_baseline_enabled($xml);
			if ($enabled !== null) {
				$file_enabled[$key] = $enabled;
			}
		}

		if (!empty($file_hash)) {
			// Select only the columns we need; no point loading dialplan_xml
			// (could be hundreds of KB across the whole table).
			$rows = $database->select(
				"select dialplan_uuid, dialplan_name, dialplan_order, dialplan_hash, dialplan_enabled_original from v_dialplans",
				null,
				'all'
			);

			$updates = 0;
			if (is_array($rows)) {
				foreach ($rows as $row) {
					$key = dialplan_build_original_file_key(
						$row['dialplan_order'] ?? null,
						$row['dialplan_name'] ?? null
					);
					if ($key === null || !isset($file_hash[$key])) {
						continue;
					}
					$new_hash = $file_hash[$key];
					$new_enabled = $file_enabled[$key] ?? null;

					// Existing values, normalized: PostgreSQL returns 't'/'f'.
					$existing_enabled_raw = $row['dialplan_enabled_original'] ?? null;
					$existing_enabled = null;
					if ($existing_enabled_raw !== null && $existing_enabled_raw !== '') {
						$existing_enabled = in_array(
							strtolower((string) $existing_enabled_raw),
							['1', 't', 'true', 'yes', 'on'],
							true
						);
					}

					$hash_ok    = (($row['dialplan_hash'] ?? null) === $new_hash);
					$enabled_ok = ($new_enabled === null) || ($existing_enabled === $new_enabled);

					// Skip rows that already have the correct values — avoids
					// pointless UPDATE churn on repeated upgrades.
					if ($hash_ok && $enabled_ok) {
						continue;
					}

					$sets = ['dialplan_hash = :h'];
					$params = ['h' => $new_hash, 'u' => $row['dialplan_uuid']];
					if ($new_enabled !== null) {
						$sets[] = 'dialplan_enabled_original = :e';
						$params['e'] = $new_enabled ? 'true' : 'false';
					}
					$database->execute(
						'update v_dialplans set ' . implode(', ', $sets) . ' where dialplan_uuid = :u',
						$params
					);
					$updates++;
				}
			}

			if ($updates > 0 && (PHP_SAPI === 'cli' || !empty($_REQUEST['debug']))) {
				echo "    enhanced_dialplans: populated dialplan_hash for {$updates} row(s).\n";
			}
		}
	}

	unset($baseline_dir, $file_map, $file_hash, $file_enabled, $rows, $row, $key, $new_hash, $new_enabled, $updates, $xml, $canonical, $enabled, $existing_enabled_raw, $existing_enabled, $hash_ok, $enabled_ok, $sets, $params);
}
