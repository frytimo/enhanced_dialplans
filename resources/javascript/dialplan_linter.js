
/**
 * DialplanLinter
 *
 * Runs an array of lint rules against a parsed dialplan tree and returns a
 * flat array of findings.
 *
 * Usage:
 *   var findings = DialplanLinter.run(tree, DialplanLintRules);
 *
 * Each finding: { node, severity, ruleId, type, message }
 *   node     — reference to the tree node object
 *   severity — 'error' | 'warning' | 'info'
 *   ruleId   — string identifier of the rule that produced it
 *   type     — optional finding type such as 'exit'
 *   message  — human-readable description of the problem
 *
 * Rules format (see dialplan_lint_rules.js):
 *   {
 *     id:          string
 *     severity:    'error' | 'warning' | 'info'
 *     description: string
 *     type:        optional string such as 'exit'
 *     check:       function(tree) -> [ { node, message }, ... ]
 *   }
 */
var DialplanLinter = (function () {
    'use strict';

    /**
     * Run all rules against a tree and return a flat findings array.
     *
     * @param {object}   tree  - parsed tree from DialplanParser.parseXmlToTree
     * @param {Array}    rules - array of rule objects
     * @returns {Array}  findings
     */
    function run(tree, rules) {
        var findings = [];

        if (!tree || !rules || !rules.length) {
            return findings;
        }

        for (var i = 0; i < rules.length; i++) {
            var rule = rules[i];
            if (!rule || typeof rule.check !== 'function') {
                continue;
            }
            try {
                var results = rule.check(tree);
                if (results && results.length) {
                    for (var j = 0; j < results.length; j++) {
                        var r = results[j];
                        if (r && r.node) {
                            findings.push({
                                node:     r.node,
                                severity: rule.severity || 'info',
                                ruleId:   rule.id       || 'unknown',
                                type:     r.type       || rule.type       || '',
                                message:  r.message     || rule.description || ''
                            });
                        }
                    }
                }
            } catch (e) {
                // A broken rule must never crash the editor
            }
        }

        return findings;
    }

    return { run: run };

}());
