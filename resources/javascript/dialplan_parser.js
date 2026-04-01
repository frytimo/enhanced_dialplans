
/**
 * DialplanParser
 *
 * Parses FusionPBX dialplan XML into a tree structure for the visual editor,
 * and generates dialplan XML from a tree structure.
 *
 * Tree structure:
 *   {
 *     type: 'extension',
 *     attributes: { name, continue, uuid },
 *     children: [ <node>, ... ]
 *   }
 *
 * Node structure:
 *   {
 *     type: 'condition' | 'action' | 'anti-action' | 'regex' | 'comment',
 *     attributes: { ... type-specific ... },
 *     children: [ <node>, ... ],  // only for conditions
 *     enabled: true | false,
 *     isRegexCondition: true | false  // only for conditions with regex attribute
 *   }
 *
 * Condition attributes (regular):  { field, expression, break }
 * Condition attributes (regex):    { regex, break }
 * Action/Anti-action attributes:   { application, data, inline }
 * Regex child attributes:          { field, expression, break }
 * Comment attributes:              { text }
 */
var DialplanParser = (function () {
    'use strict';

    /**
     * Parse an XML string representing a dialplan <extension> block into a tree.
     *
     * @param {string} xmlString - The XML to parse (should contain an <extension> element)
     * @returns {{ success: boolean, tree?: object, error?: string }}
     */
    function parseXmlToTree(xmlString) {
        try {
            var trimmed = (xmlString || '').trim();
            if (!trimmed) {
                return { success: false, error: 'Empty XML' };
            }

            // If the XML doesn't start with <extension, wrap it so DOMParser has a valid root
            var xmlToParse = trimmed;
            if (trimmed.charAt(0) !== '<') {
                return { success: false, error: 'XML must begin with a < character' };
            }

            // Strip leading XML declaration if present so we can wrap safely
            var xmlDeclarationPattern = /^<\?xml[^?]*\?>\s*/i;
            var withoutDecl = trimmed.replace(xmlDeclarationPattern, '');

            if (!(/^<extension[\s>]/i.test(withoutDecl))) {
                // Wrap bare conditions in a minimal extension element
                xmlToParse = '<extension name="" continue="false" uuid="">' + withoutDecl + '</extension>';
            } else {
                xmlToParse = withoutDecl;
            }

            var parser = new DOMParser();
            var doc = parser.parseFromString(xmlToParse, 'text/xml');

            // DOMParser signals errors via a <parsererror> element
            var parserError = doc.querySelector('parsererror');
            if (parserError) {
                var errText = (parserError.textContent || 'XML parse error').trim();
                // Return first line only to keep the message short
                var firstLine = errText.split('\n')[0];
                return { success: false, error: firstLine || errText };
            }

            var extensionEl = doc.documentElement;
            if (!extensionEl || extensionEl.tagName !== 'extension') {
                return { success: false, error: 'Root element is not <extension>' };
            }

            var tree = {
                type: 'extension',
                attributes: {
                    name:     extensionEl.getAttribute('name')     || '',
                    continue: extensionEl.getAttribute('continue') || 'false',
                    uuid:     extensionEl.getAttribute('uuid')     || ''
                },
                children: []
            };

            parseChildElements(extensionEl.childNodes, tree.children);

            return { success: true, tree: tree };

        } catch (e) {
            return { success: false, error: (e && e.message) ? e.message : 'Unknown parse error' };
        }
    }

    /**
     * Walk a NodeList and collect recognised dialplan nodes into the result array.
     * Only ELEMENT_NODEs with tags condition / action / anti-action / regex are processed.
     * <regex> elements are only valid inside a regex condition — they are silently
     * skipped at any other level.
     *
     * @param {NodeList} nodeList
     * @param {Array}    result
     * @param {boolean}  parentIsRegexCondition  - true only when the direct parent is a
     *                                             <condition regex="..."> element
     */
    function parseChildElements(nodeList, result, parentIsRegexCondition) {
        var i = 0;
        while (i < nodeList.length) {
            var child = nodeList[i];

            if (child.nodeType === 8 /* Comment node */) {
                var commentText = child.nodeValue.trim();

                // Disabled condition with children:
                //   <!-- <condition ...> --> ... <!-- </condition> -->
                var condOpen = commentText.match(/^<condition(\s[\s\S]*)?(?<!\/)>$/);
                if (condOpen) {
                    i++;
                    var condChildren = [];
                    while (i < nodeList.length) {
                        var inner = nodeList[i];
                        if (inner.nodeType === 8) {
                            var innerComment = inner.nodeValue.trim();
                            if (innerComment === '<\/condition>') { i++; break; }
                            var disabledInner = parseDisabledElement(innerComment, parentIsRegexCondition);
                            if (disabledInner !== null) {
                                condChildren.push(disabledInner);
                            } else {
                                condChildren.push(parseCommentNode(innerComment));
                            }
                        } else if (inner.nodeType === 1) {
                            var innerParsed = parseNode(inner);
                            if (innerParsed !== null) condChildren.push(innerParsed);
                        }
                        i++;
                    }
                    var condNode = parseConditionFromComment(condOpen[1] || '', condChildren);
                    condNode.enabled = false;
                    result.push(condNode);
                    continue;
                }

                // Self-closing disabled condition: <!-- <condition .../> -->
                var condSelfClose = commentText.match(/^<condition(\s[\s\S]*)?\/>\s*$/);
                if (condSelfClose) {
                    var scNode = parseConditionFromComment(condSelfClose[1] || '', []);
                    scNode.enabled = false;
                    result.push(scNode);
                    i++;
                    continue;
                }

                // Disabled action / anti-action / regex: <!--<action .../>-->
                var disabledNode = parseDisabledElement(commentText, parentIsRegexCondition);
                if (disabledNode !== null) {
                    result.push(disabledNode);
                } else {
                    result.push(parseCommentNode(commentText));
                }

                i++;
                continue;
            }

            if (child.nodeType === 1 /* Element node */) {
                // <regex> with field/expression is only valid inside a regex condition
                if (child.tagName === 'regex' && !parentIsRegexCondition) {
                    i++;
                    continue;
                }
                var parsed = parseNode(child);
                if (parsed !== null) {
                    result.push(parsed);
                }
            }

            i++;
        }
    }

    /**
     * Build a condition node from the attribute string extracted from a
     * commented opening tag (<!-- <condition ATTRSSTR> -->) and a pre-parsed
     * children array.
     *
     * @param {string} attrsStr  - e.g. ' field="x" expression="y"'
     * @param {Array}  children
     * @returns {object}
     */
    function parseConditionFromComment(attrsStr, children) {
        var field = '', expression = '', breakAttr = '', regexAttr = null;
        try {
            var doc = new DOMParser().parseFromString('<condition' + attrsStr + '/>', 'text/xml');
            if (!doc.querySelector('parsererror')) {
                var el = doc.documentElement;
                if (el && el.tagName === 'condition') {
                    regexAttr  = el.getAttribute('regex');
                    field      = el.getAttribute('field')      || '';
                    expression = el.getAttribute('expression') || '';
                    breakAttr  = el.getAttribute('break')      || '';
                }
            }
        } catch (e) { /* use empty defaults */ }

        var node = {
            type: 'condition',
            attributes: { field: field, expression: expression, break: breakAttr },
            children: children || [],
            enabled: true
        };
        if (regexAttr !== null) {
            node.attributes.regex = regexAttr || 'all';
            node.isRegexCondition = true;
        }
        return node;
    }

    /**
     * Attempt to parse a disabled (commented-out) leaf element from comment text.
     * Handles self-closing <action>, <anti-action>, and <regex> elements only.
     *
     * @param {string}  commentText
     * @param {boolean} parentIsRegexCondition
     * @returns {object|null}
     */
    function parseDisabledElement(commentText, parentIsRegexCondition) {
        if (!commentText) return null;
        try {
            var doc = new DOMParser().parseFromString('<root>' + commentText + '<\/root>', 'text/xml');
            if (doc.querySelector('parsererror')) return null;
            var el = doc.documentElement.firstChild;
            while (el && el.nodeType !== 1) el = el.nextSibling;
            if (!el) return null;
            var tag = el.tagName;
            if (tag !== 'action' && tag !== 'anti-action' && tag !== 'regex') return null;
            if (tag === 'regex' && !parentIsRegexCondition) return null;
            var node = parseNode(el);
            if (node) node.enabled = false;
            return node;
        } catch (e) {
            return null;
        }
    }

    /**
     * Convert a single XML element to a node object.
     *
     * @param {Element} el
     * @returns {object|null}
     */
    function parseNode(el) {
        var tag = el.tagName;

        if (tag === 'condition') {
            var regexAttr = el.getAttribute('regex');
            var isRegexCond = (regexAttr !== null);

            var node = {
                type: 'condition',
                attributes: {
                    field:      el.getAttribute('field')      || '',
                    expression: el.getAttribute('expression') || '',
                    break:      el.getAttribute('break')      || ''
                },
                children: [],
                enabled:  true
            };

            if (isRegexCond) {
                node.attributes.regex = regexAttr || 'all';
                node.isRegexCondition = true;
            }

            // Pass whether this condition accepts <regex> children
            parseChildElements(el.childNodes, node.children, isRegexCond);
            return node;
        }

        if (tag === 'action' || tag === 'anti-action') {
            return {
                type: tag,
                attributes: {
                    application: el.getAttribute('application') || '',
                    data:        el.getAttribute('data')        || '',
                    inline:      el.getAttribute('inline')      || ''
                },
                enabled: true
            };
        }

        if (tag === 'regex') {
            return {
                type: 'regex',
                attributes: {
                    field:      el.getAttribute('field')      || '',
                    expression: el.getAttribute('expression') || '',
                    break:      el.getAttribute('break')      || ''
                },
                enabled: true
            };
        }

        // Unknown element — ignore
        return null;
    }

    /**
     * Convert a plain XML comment into a comment node.
     *
     * @param {string} commentText
     * @returns {object}
     */
    function parseCommentNode(commentText) {
        return {
            type: 'comment',
            attributes: {
                text: unescapeCommentText(commentText || '')
            },
            enabled: true
        };
    }

    /**
     * Generate a dialplan XML string from a tree structure.
     * Disabled nodes (enabled === false) are omitted from the output so that
     * FreeSWITCH does not execute them.
     *
     * @param {object} tree
     * @returns {string}
     */
    function generateXmlFromTree(tree) {
        if (!tree) { return ''; }

        var name = escapeAttr(tree.attributes.name     || '');
        var cont = escapeAttr(tree.attributes.continue || 'false');
        var uuid = escapeAttr(tree.attributes.uuid     || '');

        var xml = '<extension name="' + name + '" continue="' + cont + '" uuid="' + uuid + '">\n';

        if (tree.children && tree.children.length > 0) {
            for (var i = 0; i < tree.children.length; i++) {
                xml += nodeToXml(tree.children[i], '\t');
            }
        }

        xml += '</extension>';
        return xml;
    }

    /**
     * Recursively convert a node to its XML representation.
     * Children with enabled === false are omitted.
     *
     * @param {object} node
     * @param {string} indent  - current indentation prefix
     * @returns {string}
     */
    function nodeToXml(node, indent) {
        if (node.type === 'condition') {
            return conditionToXml(node, indent);
        }
        if (node.type === 'action' || node.type === 'anti-action') {
            return actionToXml(node, indent);
        }
        if (node.type === 'regex') {
            return regexToXml(node, indent);
        }
        if (node.type === 'comment') {
            return commentToXml(node, indent);
        }
        return '';
    }

    function conditionToXml(node, indent) {
        var isRegexCond = node.isRegexCondition || (node.attributes && node.attributes.regex);
        var attrs = '';

        if (isRegexCond) {
            attrs = ' regex="' + escapeAttr((node.attributes && node.attributes.regex) || 'all') + '"';
        } else {
            attrs  = ' field="'      + escapeAttr((node.attributes && node.attributes.field)      || '') + '"';
            attrs += ' expression="' + escapeAttr((node.attributes && node.attributes.expression) || '') + '"';
        }

        var brk = (node.attributes && node.attributes.break) || '';
        if (brk) {
            attrs += ' break="' + escapeAttr(brk) + '"';
        }

        var allChildren = node.children || [];

        // Disabled condition: wrap opening/closing condition tags in XML comments;
        // all children (enabled or not) render with their own enabled/disabled state.
        if (node.enabled === false) {
            if (allChildren.length === 0) {
                return indent + '<!-- <condition' + attrs + '/> -->\n';
            }
            var dxml = indent + '<!-- <condition' + attrs + '> -->\n';
            for (var k = 0; k < allChildren.length; k++) {
                dxml += nodeToXml(allChildren[k], indent + '\t');
            }
            dxml += indent + '<!-- </condition> -->\n';
            return dxml;
        }

        // Enabled condition: all children rendered (disabled children use comment syntax)
        if (allChildren.length === 0) {
            return indent + '<condition' + attrs + '/>\n';
        }

        var xml = indent + '<condition' + attrs + '>\n';
        for (var j = 0; j < allChildren.length; j++) {
            xml += nodeToXml(allChildren[j], indent + '\t');
        }
        xml += indent + '</condition>\n';
        return xml;
    }

    function actionToXml(node, indent) {
        var app    = escapeAttr((node.attributes && node.attributes.application) || '');
        var data   = escapeAttr((node.attributes && node.attributes.data)        || '');
        var inline = (node.attributes && node.attributes.inline) || '';
        var attrs  = ' application="' + app + '" data="' + data + '"';
        if (inline) {
            attrs += ' inline="' + escapeAttr(inline) + '"';
        }
        var tag = '<' + node.type + attrs + '/>';
        if (node.enabled === false) {
            return indent + '<!--' + tag + '-->\n';
        }
        return indent + tag + '\n';
    }

    function regexToXml(node, indent) {
        var field      = escapeAttr((node.attributes && node.attributes.field)      || '');
        var expression = escapeAttr((node.attributes && node.attributes.expression) || '');
        var attrs  = ' field="' + field + '" expression="' + expression + '"';
        var brk = (node.attributes && node.attributes.break) || '';
        if (brk) {
            attrs += ' break="' + escapeAttr(brk) + '"';
        }
        var tag = '<regex' + attrs + '/>';
        if (node.enabled === false) {
            return indent + '<!--' + tag + '-->\n';
        }
        return indent + tag + '\n';
    }

    function commentToXml(node, indent) {
        var text = sanitizeXmlCommentText((node.attributes && node.attributes.text) || '');
        var tag = '<!-- ' + text + ' -->';
        // Comment nodes are always comments in XML and are intentionally never
        // treated as executable enabled/disabled dialplan logic.
        return indent + tag + '\n';
    }

    /**
     * Escape comment text for safe XML comments and to avoid matching
     * disabled-node parser patterns (e.g. <!-- <action .../> -->).
     *
     * @param {string} str
     * @returns {string}
     */
    function sanitizeXmlCommentText(str) {
        var value = String(str || '');
        value = value
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/--/g, '- -');

        // XML comments cannot end with '-'
        if (value.slice(-1) === '-') {
            value += ' ';
        }

        return value;
    }

    /**
     * Unescape comment text rendered with sanitizeXmlCommentText().
     *
     * @param {string} str
     * @returns {string}
     */
    function unescapeCommentText(str) {
        return String(str || '')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&amp;/g, '&');
    }

    /**
     * Escape a string for safe use inside an XML attribute value.
     *
     * @param {string} str
     * @returns {string}
     */
    function escapeAttr(str) {
        if (str === null || str === undefined) { return ''; }
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;');
    }

    // Public API
    return {
        parseXmlToTree:     parseXmlToTree,
        generateXmlFromTree: generateXmlFromTree
    };

}());
