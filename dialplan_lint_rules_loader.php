<?php
/*
 * FusionPBX - Dialplan Lint Rules Loader
 *
 * Aggregates lint rules from all app folders matching this pattern:
 *   app/<app_name>/resources/javascript/dialplan_lint_rules.js
 *
 * Each file is wrapped in an isolated scope. If it defines an array named
 * DialplanLintRules, those rules are merged into window.DialplanLintRules.
 */

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

// check permissions
if (!permission_exists('dialplan_edit') && !permission_exists('dialplan_add')) {
	http_response_code(403);
	header('Content-Type: application/javascript; charset=UTF-8');
	echo "window.DialplanLintRules = window.DialplanLintRules || [];\n";
	exit;
}

header('Content-Type: application/javascript; charset=UTF-8');

$rule_files = glob(dirname(__DIR__) . '/*/resources/javascript/dialplan_lint_rules.js') ?: [];
sort($rule_files, SORT_STRING);

echo "(function(window){\n";
echo "\t'use strict';\n";
echo "\twindow.DialplanLintRules = Array.isArray(window.DialplanLintRules) ? window.DialplanLintRules : [];\n\n";

echo "\tfunction __appendRules(sourceName, candidate) {\n";
echo "\t\tif (!Array.isArray(candidate)) return;\n";
echo "\t\tfor (var i = 0; i < candidate.length; i++) {\n";
echo "\t\t\twindow.DialplanLintRules.push(candidate[i]);\n";
echo "\t\t}\n";
echo "\t}\n\n";

foreach ($rule_files as $rule_file) {
	$rule_source = str_replace(dirname(__DIR__, 2) . '/', '', $rule_file);
	$rule_js = @file_get_contents($rule_file);
	if ($rule_js === false || trim($rule_js) === '') {
		continue;
	}

	echo "\t/* Source: " . addslashes($rule_source) . " */\n";
	echo "\t(function(){\n";
	echo "\t\tvar DialplanLintRules = undefined;\n";
	echo "\t\ttry {\n";
	echo $rule_js . "\n";
	echo "\t\t} catch (e) {\n";
	echo "\t\t\t// Ignore broken plugin rule files so they cannot break the editor.\n";
	echo "\t\t}\n";
	echo "\t\t__appendRules(" . json_encode($rule_source) . ", DialplanLintRules);\n";
	echo "\t})();\n\n";
}

echo "})(window);\n";
