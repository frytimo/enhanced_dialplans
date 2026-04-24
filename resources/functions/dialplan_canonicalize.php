<?php
/*
 * Canonicalization and baseline-lookup helpers for dialplan XML.
 *
 * Shared between:
 *   - app/enhanced_dialplans/dialplans.php  (per-request UI compare)
 *   - app/enhanced_dialplans/app_defaults.php (populate v_dialplans.dialplan_hash
 *     during install / `php core/upgrade/upgrade.php`)
 *
 * This file must be safe to include multiple times and from both the web
 * request path and the CLI upgrade path.
 */

if (!function_exists('dialplan_normalize_name')) {

	function dialplan_normalize_name($name): string {
		return preg_replace('/[^a-z0-9]/', '', strtolower((string) $name));
	}

	/**
	 * Produce a canonical, whitespace-independent representation of a dialplan
	 * XML fragment so semantically equivalent documents compare equal.
	 *
	 * The FusionPBX save path normalizes the XML it writes back to v_dialplans
	 * in ways that differ cosmetically from the shipped baseline files:
	 *   - boolean attributes rendered as "1"/"" instead of "true"/"false"
	 *   - wrapper metadata attrs (uuid/app_uuid/order/number/context/global)
	 *     emitted inconsistently on the <extension> element
	 *   - empty-string attributes (data="", field="", expression="")
	 *   - XML comments stripped
	 *   - <action enabled="false"/> entries stripped (they are inert)
	 *   - `enabled="true"` attribute omitted (default)
	 *
	 * Returns null when the input is not parseable XML.
	 */
	function dialplan_canonicalize_xml($xml): ?string {
		$xml = (string) $xml;
		if ($xml === '') {
			return '';
		}

		$wrapped = '<?xml version="1.0" encoding="UTF-8"?><_root>' . $xml . '</_root>';

		$prev_errors = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$loaded = $doc->loadXML($wrapped, LIBXML_NOBLANKS | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev_errors);
		if (!$loaded) {
			return null;
		}

		$xpath = new DOMXPath($doc);

		foreach (iterator_to_array($xpath->query('//comment()')) as $comment) {
			$comment->parentNode->removeChild($comment);
		}

		foreach (iterator_to_array($xpath->query('//action[@enabled="false"]')) as $node) {
			$node->parentNode->removeChild($node);
		}

		$extension_metadata_attrs = ['uuid', 'app_uuid', 'order', 'number', 'context', 'global'];
		$boolean_attrs = ['continue', 'global', 'enabled', 'inline', 'break'];

		foreach (iterator_to_array($xpath->query('//*')) as $element) {
			/** @var DOMElement $element */
			$is_extension = ($element->nodeName === 'extension');

			$attrs = [];
			foreach ($element->attributes as $attr) {
				$attrs[$attr->nodeName] = $attr->nodeValue;
			}
			foreach (array_keys($attrs) as $name) {
				$element->removeAttribute($name);
			}

			$clean = [];
			foreach ($attrs as $name => $value) {
				if ($value === '') {
					continue;
				}
				if ($is_extension && in_array($name, $extension_metadata_attrs, true)) {
					continue;
				}
				if (in_array($name, $boolean_attrs, true)) {
					$lc = strtolower($value);
					if (in_array($lc, ['1', 'true', 'yes', 'on'], true)) {
						$value = 'true';
					} elseif (in_array($lc, ['0', 'false', 'no', 'off'], true)) {
						$value = 'false';
					}
					if ($name === 'enabled' && $value === 'true') {
						continue;
					}
					if ($is_extension && $name === 'continue' && $value === 'false') {
						continue;
					}
				}
				$clean[$name] = $value;
			}

			ksort($clean, SORT_STRING);
			foreach ($clean as $name => $value) {
				$element->setAttribute($name, $value);
			}
		}

		$out = '';
		foreach ($doc->documentElement->childNodes as $child) {
			$out .= $doc->saveXML($child);
		}
		$out = preg_replace('/>\s+</', '><', $out);
		return trim($out);
	}

	/**
	 * Return the MD5 of a dialplan XML fragment after canonicalization.
	 *
	 * The expensive DOM canonicalization only runs on a cache miss. The cache
	 * key is md5() of the raw XML, so any edit automatically invalidates the
	 * cached canonical hash. In steady-state operation this cache is no longer
	 * strictly required for the baseline side — baseline hashes are now
	 * stored in v_dialplans.dialplan_hash by the enhanced_dialplans
	 * app_defaults.php (run during `php core/upgrade/upgrade.php`). The cache
	 * remains useful for the current-row side, where v_dialplans rows can be
	 * edited at any time between upgrades.
	 */
	function dialplan_canonical_hash($xml): ?string {
		static $cache = null;
		static $cache_dirty = false;
		static $cache_path = null;
		static $shutdown_registered = false;

		if ($cache === null) {
			$cache_path = sys_get_temp_dir() . '/fusionpbx_dialplan_canon_hashes.cache';
			$cache = [];
			if (is_file($cache_path) && is_readable($cache_path)) {
				$raw = @file_get_contents($cache_path);
				if (is_string($raw) && $raw !== '') {
					$decoded = @unserialize($raw, ['allowed_classes' => false]);
					if (is_array($decoded)) {
						$cache = $decoded;
					}
				}
			}
			if (!$shutdown_registered) {
				register_shutdown_function(function () use (&$cache, &$cache_dirty, &$cache_path) {
					if (!$cache_dirty || $cache_path === null) {
						return;
					}
					if (count($cache) > 5000) {
						$cache = array_slice($cache, -5000, null, true);
					}
					@file_put_contents($cache_path, serialize($cache), LOCK_EX);
					@chmod($cache_path, 0640);
				});
				$shutdown_registered = true;
			}
		}

		$raw = (string) $xml;
		$raw_md5 = md5($raw);
		if (array_key_exists($raw_md5, $cache)) {
			return $cache[$raw_md5] === '' ? null : $cache[$raw_md5];
		}

		$canonical = dialplan_canonicalize_xml($raw);
		if ($canonical === null) {
			$cache[$raw_md5] = '';
			$cache_dirty = true;
			return null;
		}

		$hash = md5($canonical);
		$cache[$raw_md5] = $hash;
		$cache_dirty = true;
		return $hash;
	}

	/**
	 * Compare a shipped baseline XML to a stored dialplan XML, treating
	 * `{v_token}` placeholders in the baseline as wildcards that accept any
	 * substituted value in the stored copy.
	 *
	 * Returns 'match' / 'diff' / null (unparseable).
	 */
	function dialplan_compare_status($file_xml, $db_xml): ?string {
		static $cache = null;
		static $cache_dirty = false;
		static $cache_path = null;
		static $shutdown_registered = false;

		if ($cache === null) {
			$cache_path = sys_get_temp_dir() . '/fusionpbx_dialplan_compare.cache';
			$cache = [];
			if (is_file($cache_path) && is_readable($cache_path)) {
				$raw = @file_get_contents($cache_path);
				if (is_string($raw) && $raw !== '') {
					$decoded = @unserialize($raw, ['allowed_classes' => false]);
					if (is_array($decoded)) {
						$cache = $decoded;
					}
				}
			}
			if (!$shutdown_registered) {
				register_shutdown_function(function () use (&$cache, &$cache_dirty, &$cache_path) {
					if (!$cache_dirty || $cache_path === null) {
						return;
					}
					if (count($cache) > 5000) {
						$cache = array_slice($cache, -5000, null, true);
					}
					@file_put_contents($cache_path, serialize($cache), LOCK_EX);
					@chmod($cache_path, 0640);
				});
				$shutdown_registered = true;
			}
		}

		$key = md5(md5((string) $file_xml) . '|' . md5((string) $db_xml));
		if (array_key_exists($key, $cache)) {
			$v = $cache[$key];
			return $v === '' ? null : $v;
		}

		$file_canonical = dialplan_canonicalize_xml($file_xml);
		$db_canonical = dialplan_canonicalize_xml($db_xml);
		if ($file_canonical === null || $db_canonical === null) {
			$cache[$key] = '';
			$cache_dirty = true;
			return null;
		}

		if ($file_canonical === $db_canonical) {
			$cache[$key] = 'match';
			$cache_dirty = true;
			return 'match';
		}

		$status = 'diff';
		if (strpos($file_canonical, '{v_') !== false) {
			$pattern = preg_quote($file_canonical, '/');
			$pattern = preg_replace('/\\\\\{v_[a-zA-Z0-9_]+\\\\\}/', '[^"<>]*', $pattern);
			if (preg_match('/\A' . $pattern . '\z/', $db_canonical) === 1) {
				$status = 'match';
			}
		}

		$cache[$key] = $status;
		$cache_dirty = true;
		return $status;
	}

	function dialplan_build_original_file_key($dialplan_order, $dialplan_name): ?string {
		$name_normalized = dialplan_normalize_name($dialplan_name);
		if ($name_normalized === '') {
			return null;
		}
		return ((int) $dialplan_order) . '_' . $name_normalized;
	}

	function dialplan_build_original_file_map($dialplan_directory): array {
		$file_map = [];
		$paths = glob($dialplan_directory . '/*.xml') ?: [];
		foreach ($paths as $path) {
			$filename = basename($path);
			if (preg_match('/^(\d{1,3})_([^.]+)\.xml$/', $filename, $matches)) {
				$key = dialplan_build_original_file_key($matches[1], $matches[2]);
				if ($key !== null) {
					$file_map[$key] = $path;
				}
			}
		}
		return $file_map;
	}

	function dialplan_find_original_file($dialplan_order, $dialplan_name, $file_map): ?string {
		$key = dialplan_build_original_file_key($dialplan_order, $dialplan_name);
		if ($key === null) {
			return null;
		}
		return $file_map[$key] ?? null;
	}

	/**
	 * Return the default shipped-baseline directory for dialplan XML files.
	 */
	function dialplan_baseline_directory(): string {
		// This file lives at app/enhanced_dialplans/resources/functions/ so
		// dirname(__DIR__, 3) is the app/ root.
		return dirname(__DIR__, 3) . '/dialplans/resources/switch/conf/dialplan';
	}

	/**
	 * Extract the "enabled" attribute of the root <extension> element from a
	 * shipped dialplan XML fragment. FusionPBX's dialplan importer defaults
	 * missing `enabled` attrs to true (see app/dialplans/resources/classes/
	 * dialplan.php ~L300), so we mirror that default here.
	 *
	 * Returns true/false, or null if the XML cannot be parsed (caller should
	 * then leave dialplan_enabled_original untouched).
	 */
	function dialplan_baseline_enabled($xml): ?bool {
		$xml = (string) $xml;
		if ($xml === '') {
			return null;
		}
		$wrapped = '<?xml version="1.0" encoding="UTF-8"?><_root>' . $xml . '</_root>';
		$prev_errors = libxml_use_internal_errors(true);
		$doc = new DOMDocument();
		$loaded = $doc->loadXML($wrapped, LIBXML_NOBLANKS | LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($prev_errors);
		if (!$loaded) {
			return null;
		}
		$extension = $doc->getElementsByTagName('extension')->item(0);
		if ($extension === null) {
			return true;
		}
		$value = $extension->getAttribute('enabled');
		if ($value === '') {
			return true;
		}
		$lc = strtolower($value);
		if (in_array($lc, ['0', 'false', 'no', 'off'], true)) {
			return false;
		}
		return true;
	}
}
