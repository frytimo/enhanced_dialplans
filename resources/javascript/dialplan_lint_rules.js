/**
 * DialplanLintRules
 *
 * A self-contained array of lint rules for the FusionPBX visual dialplan
 * editor.  Each rule is evaluated by DialplanLinter.run(tree, rules).
 *
 * Adding a custom rule:
 *   Push an object with { id, severity, type, description, check } into this array.
 *   check(tree) must return an array of { node, message } objects.
 *   Exceptions thrown inside check() are silently swallowed by the engine.
 *
 * Severity levels: 'error' | 'warning' | 'info'
 */
var DialplanLintRules = (function () {
    'use strict';

    /** Applications whose execution ends the channel (stops further actions) */
    var TERMINAL_APPS = ['transfer', 'hangup', 'reject', 'respond', 'deflect'];

    /**
     * Walk every node in the tree recursively, calling fn for each.
     *
     * @param {Array}    nodes
     * @param {Function} fn(node, siblings, parentNode, index)
     * @param {object}   parentNode
     */
    function walkNodes(nodes, fn, parentNode) {
        if (!nodes) return;
        for (var i = 0; i < nodes.length; i++) {
            fn(nodes[i], nodes, parentNode, i);
            if (nodes[i].type === 'condition' && nodes[i].children) {
                walkNodes(nodes[i].children, fn, nodes[i]);
            }
        }
    }

    /**
     * Returns true when a condition has at least one meaningful child.
     *
     * Comment nodes count as meaningful by design so users can document why a
     * break="never" gate exists and intentionally suppress related warnings.
     *
     * @param {Array} children
     * @returns {boolean}
     */
    function hasMeaningfulConditionChild(children) {
        if (!children || !children.length) return false;
        for (var i = 0; i < children.length; i++) {
            var child = children[i];
            if (!child) continue;
            if (child.type === 'comment') return true;
            if (child.enabled !== false) return true;
        }
        return false;
    }

    var rules = [

        // ── Rule 0 ───────────────────────────────────────────────────────────
        // Comments above the <extension> tag are outside normal dialplan
        // extension scope. They are preserved for visibility in the editor,
        // but should be flagged so users can intentionally keep or relocate
        // them.
        {
            id:          'comment-outside-extension',
            severity:    'warning',
            description: 'Comment exists above the <extension> tag',
            check: function (tree) {
                var findings = [];
                var children = (tree && tree.children) || [];
                for (var i = 0; i < children.length; i++) {
                    var node = children[i];
                    if (!node || node.type !== 'comment') continue;
                    if (!(node.meta && node.meta.outsideExtension)) continue;
                    findings.push({
                        node: node,
                        message: 'Comment was found above the <extension> tag (outside normal extension scope)'
                    });
                }
                return findings;
            }
        },

        // ── Rule 1 ───────────────────────────────────────────────────────────
        // An enabled action or anti-action with no application set will be
        // silently skipped by FreeSWITCH, which is almost certainly unintended.
        {
            id:          'action-empty-application',
            severity:    'error',
            description: 'Action or anti-action has no application set',
            check: function (tree) {
                var findings = [];
                walkNodes(tree.children, function (node) {
                    if ((node.type === 'action' || node.type === 'anti-action') &&
                        node.enabled !== false &&
                        (!node.attributes.application || !node.attributes.application.trim())) {
                        findings.push({
                            node:    node,
                            message: 'Application is required — FreeSWITCH will skip this action'
                        });
                    }
                });
                return findings;
            }
        },

        // ── Rule 2 ───────────────────────────────────────────────────────────
        // A condition with no children and break != "never" acts as a gate:
        // if it fails, the extension stops processing.  This is a deliberate
        // and common FreeSWITCH pattern (e.g. user_record), so severity is
        // info rather than warning.
        {
            id:          'gate-condition',
            severity:    'info',
            type:        'exit',
            description: 'Condition with no actions acts as a gate (halts the extension if the condition fails)',
            check: function (tree) {
                var findings = [];
                walkNodes(tree.children, function (node) {
                    if (node.type === 'condition' &&
                        node.enabled !== false &&
                        !node.isRegexCondition &&
                        node.attributes.break !== 'never') {
                        var enabledChildren = (node.children || []).filter(function (c) {
                            return c.enabled !== false;
                        });
                        if (enabledChildren.length === 0) {
                            findings.push({
                                node:    node,
                                message: 'Exit Point: If this condition fails the processing stops and moves to the next dialplan (break="on-false" default)'
                            });
                        }
                    }
                });
                return findings;
            }
        },

        // ── Rule 3 ───────────────────────────────────────────────────────────
        // Actions that follow a non-inline terminal action (transfer, hangup,
        // reject, respond, deflect) within the same condition will never run.
        {
            id:          'unreachable-after-terminal',
            severity:    'warning',
            description: 'Action follows a terminal action (transfer/hangup/etc.) and will never execute',
            check: function (tree) {
                var findings = [];
                walkNodes(tree.children, function (node) {
                    if (node.type !== 'condition' || !node.children) return;
                    var enabledChildren = node.children.filter(function (c) {
                        return c.enabled !== false;
                    });
                    var terminalFound = false;
                    for (var i = 0; i < enabledChildren.length; i++) {
                        var child = enabledChildren[i];
                        if (terminalFound &&
                            (child.type === 'action' || child.type === 'anti-action')) {
                            findings.push({
                                node:    child,
                                message: 'Unreachable: a terminal action above (transfer / hangup / etc.) has already ended the call flow'
                            });
                        }
                        if ((child.type === 'action' || child.type === 'anti-action') &&
                            child.attributes.inline !== 'true' &&
                            TERMINAL_APPS.indexOf((child.attributes.application || '').toLowerCase()) !== -1) {
                            terminalFound = true;
                        }
                    }
                });
                return findings;
            }
        },

        // ── Rule 4 ───────────────────────────────────────────────────────────
        // Two enabled conditions that are immediately adjacent at the same
        // level and share the same field + expression are almost always a
        // copy-paste mistake.  Non-consecutive duplicates (e.g. repeating a
        // guard condition in separate groups) are intentional and not flagged.
        {
            id:          'adjacent-duplicate-condition',
            severity:    'warning',
            description: 'Two consecutive conditions share the same field and expression',
            check: function (tree) {
                var findings = [];
                function checkLevel(nodes) {
                    if (!nodes || nodes.length < 2) return;
                    for (var i = 0; i < nodes.length - 1; i++) {
                        var curr = nodes[i];
                        var next = nodes[i + 1];
                        if (curr.type === 'condition' && curr.enabled !== false &&
                            !curr.isRegexCondition &&
                            next.type === 'condition' && next.enabled !== false &&
                            !next.isRegexCondition) {
                            var cf = (curr.attributes.field      || '').trim();
                            var nf = (next.attributes.field      || '').trim();
                            var ce = (curr.attributes.expression || '').trim();
                            var ne = (next.attributes.expression || '').trim();
                            if (cf && nf && cf === nf && ce === ne) {
                                findings.push({
                                    node:    next,
                                    message: 'Adjacent duplicate: same field ("' + cf + '") and expression ("' + ce + '") as the condition immediately above'
                                });
                            }
                        }
                        if (curr.type === 'condition' && curr.children) {
                            checkLevel(curr.children);
                        }
                    }
                    // Check children of the last node too
                    var last = nodes[nodes.length - 1];
                    if (last && last.type === 'condition' && last.children) {
                        checkLevel(last.children);
                    }
                }
                checkLevel(tree.children);
                return findings;
            }
        },

        // ── Rule 5 ───────────────────────────────────────────────────────────
        // break="never" on the last enabled condition in an extension has no
        // effect — there are no further conditions to continue to.
        {
            id:          'break-never-on-last-condition',
            severity:    'info',
            description: 'break="never" on the last condition is redundant — no further conditions follow',
            check: function (tree) {
                var findings = [];
                // Only check the top-level (extension) children.
                // Inside a condition's children the break attribute controls a
                // different flow, so those are out of scope for this rule.
                var nodes = tree.children || [];
                var lastCondIdx = -1;
                for (var i = nodes.length - 1; i >= 0; i--) {
                    if (nodes[i].type === 'condition' && nodes[i].enabled !== false) {
                        lastCondIdx = i;
                        break;
                    }
                }
                if (lastCondIdx === -1) return findings;
                var lastCond = nodes[lastCondIdx];
                // Make sure there really are no enabled conditions after it
                var hasAfter = false;
                for (var j = lastCondIdx + 1; j < nodes.length; j++) {
                    if (nodes[j].type === 'condition' && nodes[j].enabled !== false) {
                        hasAfter = true;
                        break;
                    }
                }
                if (!hasAfter && lastCond.attributes.break === 'never') {
                    findings.push({
                        node:    lastCond,
                        message: 'break="never" on the last condition is redundant — there are no further conditions to continue to'
                    });
                }
                return findings;
            }
        },

        // ── Rule 6 ───────────────────────────────────────────────────────────
        // A condition with break="never" and no child nodes is effectively a
        // no-op and adds unnecessary parse overhead.  A comment child is
        // considered sufficient to intentionally suppress this warning.
        {
            id:          'break-never-empty-condition',
            severity:    'error',
            description: 'Condition uses break="never" but has no child nodes',
            check: function (tree) {
                var findings = [];
                walkNodes(tree.children, function (node) {
                    if (node.type !== 'condition' || node.enabled === false) return;
                    if ((node.attributes && node.attributes.break) !== 'never') return;

                    if (!hasMeaningfulConditionChild(node.children || [])) {
                        findings.push({
                            node:    node,
                            message: 'break="never" with no children is a no-op; add an action/condition or a comment child to document intent'
                        });
                    }
                });
                return findings;
            }
        },

        // ── Rule 7 ───────────────────────────────────────────────────────────
        // In a regex condition using regex="any", an enabled <regex> child
        // with an empty expression will match trivially, causing the parent
        // condition to pass regardless of the other regex children.
        {
            id:          'regex-any-empty-expression',
            severity:    'warning',
            description: 'Regex condition uses regex="any" and contains an empty regex expression',
            check: function (tree) {
                var findings = [];
                walkNodes(tree.children, function (node) {
                    if (node.type !== 'condition' || node.enabled === false) return;
                    if (!(node.isRegexCondition || (node.attributes && node.attributes.regex))) return;
                    if (((node.attributes && node.attributes.regex) || 'all') !== 'any') return;

                    var children = node.children || [];
                    var hasEmptyRegexExpr = children.some(function (child) {
                        return child &&
                            child.type === 'regex' &&
                            child.enabled !== false &&
                            !String((child.attributes && child.attributes.expression) || '').trim();
                    });

                    if (hasEmptyRegexExpr) {
                        findings.push({
                            node:    node,
                            message: 'Regex condition uses "any" and has a regex row with an empty expression, so the parent condition can match regardless of the other regex rows'
                        });
                    }
                });
                return findings;
            }
        },

        // ── Rule 8 ───────────────────────────────────────────────────────────
        // An enabled regex row with an empty expression matches trivially.
        // Under regex="all" or regex="xor" it contributes nothing useful;
        // under regex="any" it makes the parent condition always pass.
        {
            id:          'regex-empty-expression-noop',
            severity:    'warning',
            description: 'Regex row has an empty expression',
            check: function (tree) {
                var findings = [];
                walkNodes(tree.children, function (node, siblings, parentNode) {
                    if (node.type !== 'regex' || node.enabled === false) return;
                    if (!parentNode || parentNode.type !== 'condition') return;
                    if (!(parentNode.isRegexCondition || (parentNode.attributes && parentNode.attributes.regex))) return;

                    var expression = String((node.attributes && node.attributes.expression) || '').trim();
                    if (expression) return;

                    var regexMode = String((parentNode.attributes && parentNode.attributes.regex) || 'all');
                    var message = (regexMode === 'any')
                        ? 'Empty expression matches trivially and makes the parent regex="any" condition pass regardless of the other regex rows'
                        : 'Empty expression matches trivially, so this regex row is effectively a no-op';

                    findings.push({
                        node:    node,
                        message: message
                    });
                });
                return findings;
            }
        }

    ];

    return rules;

}());
