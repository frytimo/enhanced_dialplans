<?php
/*
 * FusionPBX - Dialplan XML Pop-out Editor
 * Standalone ACE editor window that syncs with dialplan_edit_unified.php
 * via the BroadcastChannel API (same-origin only).
 */

require_once dirname(__DIR__, 2) . "/resources/require.php";
require_once "resources/check_auth.php";

// check permissions
if (!permission_exists('dialplan_edit') && !permission_exists('dialplan_xml')) {
	echo "access denied";
	exit;
}

// Sanitize channel ID - allow only hex characters, hyphens, and underscores
// The channel ID is generated server-side from the user UUID (hex + hyphens), prefixed with 'fpbx-dialplan-'
$raw_channel = $_GET['channel'] ?? '';
$channel_id = preg_replace('/[^a-zA-Z0-9\-_]/', '', $raw_channel);
if (empty($channel_id)) {
	echo "invalid channel";
	exit;
}

// get domain/user context
$domain_uuid = $_SESSION['domain_uuid'];

// get editor settings
$settings = new settings(['domain_uuid' => $domain_uuid, 'user_uuid' => $_SESSION['user_uuid'] ?? null]);
$setting_size       = !empty($settings->get('editor', 'font_size'))   ? $settings->get('editor', 'font_size')   : '12px';
$setting_theme      = !empty($settings->get('editor', 'theme'))        ? $settings->get('editor', 'theme')        : 'cobalt';
$setting_invisibles = $settings->get('editor', 'invisibles',    'false');
$setting_indenting  = $settings->get('editor', 'indent_guides', 'false');
$setting_numbering  = $settings->get('editor', 'line_numbers',  'true');

$themes = [
	'Light' => ['chrome','clouds','crimson_editor','dawn','dreamweaver','eclipse','github','iplastic','katzenmilch','kuroir','solarized_light','sqlserver','textmate','tomorrow','xcode'],
	'Dark'  => ['ambiance','chaos','clouds_midnight','cobalt','dracula','gob','gruvbox','idle_fingers','kr_theme','merbivore','merbivore_soft','mono_industrial','monokai','nord_dark','one_dark','pastel_on_dark','solarized_dark','terminal','tomorrow_night','tomorrow_night_blue','tomorrow_night_bright','tomorrow_night_eighties','twilight','vibrant_ink'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo $text['label-xml_editor'] ?? 'XML Editor'; ?> — <?php echo $text['label-dialplan'] ?? 'Dialplan'; ?></title>
	<link rel="stylesheet" href="<?php echo PROJECT_PATH; ?>/resources/fontawesome/css/all.min.css">
	<style>
		*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
		html, body { height: 100%; overflow: hidden; font-family: sans-serif; background: #1e1e1e; color: #ccc; }

		#wrapper { display: flex; flex-direction: column; height: 100vh; }

		/* Toolbar */
		#toolbar {
			display: flex;
			align-items: center;
			gap: 4px;
			padding: 5px 10px;
			background: #2a2a2a;
			border-bottom: 1px solid #444;
			flex-shrink: 0;
			flex-wrap: wrap;
			min-height: 40px;
		}

		/* Toggle icon buttons */
		.tool-btn {
			width: 28px;
			height: 28px;
			border: 1px solid #555;
			border-radius: 3px;
			background: transparent;
			cursor: pointer;
			color: #aaa;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			font-size: 13px;
			transition: background 0.15s, color 0.15s, border-color 0.15s;
			flex-shrink: 0;
		}

		.tool-btn:hover { background: rgba(255,255,255,0.1); color: #eee; }

		.tool-btn.active {
			background: #0275d8;
			border-color: #0275d8;
			color: #fff;
		}

		/* Dropdowns */
		.tool-select {
			height: 28px;
			font-size: 12px;
			background: #3a3a3a;
			color: #ccc;
			border: 1px solid #555;
			border-radius: 3px;
			padding: 0 4px;
			cursor: pointer;
			flex-shrink: 0;
		}

		.tool-select:focus { outline: 1px solid #0275d8; }

		.toolbar-sep {
			width: 1px;
			height: 18px;
			background: #555;
			margin: 0 4px;
			flex-shrink: 0;
		}

		.toolbar-spacer { flex: 1; min-width: 8px; }

		/* Sync badge */
		#sync-badge {
			font-size: 11px;
			padding: 2px 10px;
			border-radius: 10px;
			border: 1px solid transparent;
			transition: all 0.3s;
			white-space: nowrap;
			flex-shrink: 0;
		}

		#sync-badge.synced  { color: #5cb85c; border-color: #5cb85c; }
		#sync-badge.editing { color: #f0ad4e; border-color: #f0ad4e; }
		#sync-badge.offline { color: #d9534f; border-color: #d9534f; }

		#editor { flex: 1; overflow: hidden; }
	</style>
</head>
<body>
<div id="wrapper">
	<div id="toolbar">
		<!-- Toggle buttons: line numbers, invisibles, indent guides -->
		<button type="button" class="tool-btn<?php echo $setting_numbering === 'true' ? ' active' : ''; ?>" id="btn-line-numbers" onclick="toggleOption('line_numbers');" title="<?php echo $text['label-line_numbers'] ?? 'Line numbers'; ?>"><i class="fas fa-list-ol"></i></button>
		<button type="button" class="tool-btn<?php echo $setting_invisibles === 'true' ? ' active' : ''; ?>" id="btn-invisibles" onclick="toggleOption('invisibles');" title="<?php echo $text['label-show_invisibles'] ?? 'Show invisible characters'; ?>"><i class="fas fa-paragraph"></i></button>
		<button type="button" class="tool-btn<?php echo $setting_indenting === 'true' ? ' active' : ''; ?>" id="btn-indent-guides" onclick="toggleOption('indent_guides');" title="<?php echo $text['label-indent_guides'] ?? 'Indent guides'; ?>"><i class="fas fa-indent"></i></button>

		<span class="toolbar-sep"></span>

		<!-- Font size -->
		<select id="font-size" class="tool-select" style="width: 68px;" onchange="changeFontSize();" title="<?php echo $text['label-font_size'] ?? 'Font size'; ?>">
			<?php foreach (['10px','11px','12px','13px','14px','16px','18px','20px','24px'] as $sz): ?>
			<option value="<?php echo $sz; ?>"<?php echo $sz === $setting_size ? ' selected' : ''; ?>><?php echo $sz; ?></option>
			<?php endforeach; ?>
		</select>

		<!-- Theme -->
		<select id="theme-select" class="tool-select" style="min-width: 120px;" onchange="changeTheme();" title="<?php echo $text['label-theme'] ?? 'Theme'; ?>">
			<?php foreach ($themes as $group => $theme_list): ?>
			<optgroup label="<?php echo escape($group); ?>">
				<?php foreach ($theme_list as $theme): ?>
				<option value="<?php echo escape($theme); ?>"<?php echo $theme === $setting_theme ? ' selected' : ''; ?>>
					<?php echo escape(ucwords(str_replace('_', ' ', $theme))); ?>
				</option>
				<?php endforeach; ?>
			</optgroup>
			<?php endforeach; ?>
		</select>

		<span class="toolbar-spacer"></span>
		<span id="sync-badge" class="offline"><?php echo $text['label-connecting'] ?? 'Connecting…'; ?></span>
	</div>

	<div id="editor"></div>
</div>

<script src="<?php echo PROJECT_PATH; ?>/resources/ace/ace.js" charset="utf-8"></script>
<script>
(function() {
	'use strict';

	const channelId     = <?php echo json_encode($channel_id); ?>;
	const initSize      = <?php echo json_encode($setting_size); ?>;
	const initTheme     = <?php echo json_encode($setting_theme); ?>;
	const initInvisibles  = <?php echo $setting_invisibles; ?>;
	const initIndenting   = <?php echo $setting_indenting; ?>;
	const initNumbering   = <?php echo $setting_numbering; ?>;

	let editor;
	let channel;
	let skipChange = false;

	// Sync badge
	function setSyncBadge(state, label) {
		const el = document.getElementById('sync-badge');
		el.className = '';
		el.classList.add(state);
		el.textContent = label;
	}

	// Editor init
	function initEditor() {
		editor = ace.edit('editor');
		editor.setOptions({
			mode: 'ace/mode/xml',
			theme: 'ace/theme/' + initTheme,
			selectionStyle: 'text',
			cursorStyle: 'smooth',
			showInvisibles: initInvisibles,
			displayIndentGuides: initIndenting,
			showLineNumbers: initNumbering,
			showGutter: true,
			scrollPastEnd: true,
			fadeFoldWidgets: true,
			showPrintMargin: false,
			highlightGutterLine: false,
			useSoftTabs: false
		});
		document.getElementById('editor').style.fontSize = initSize;
		editor.setReadOnly(true);

		editor.on('change', function() {
			if (skipChange) return;
			setSyncBadge('editing', 'Editing (syncing...)');
			channel.postMessage({ type: 'xml-update', xml: editor.getValue() });
		});
	}

	// Toolbar controls
	const optionMap = {
		'line_numbers':  { aceKey: 'showLineNumbers',     btnId: 'btn-line-numbers' },
		'invisibles':    { aceKey: 'showInvisibles',      btnId: 'btn-invisibles' },
		'indent_guides': { aceKey: 'displayIndentGuides', btnId: 'btn-indent-guides' }
	};

	window.toggleOption = function(option) {
		const def = optionMap[option];
		if (!def) return;
		const newVal = !editor.getOption(def.aceKey);
		editor.setOption(def.aceKey, newVal);
		const btn = document.getElementById(def.btnId);
		if (btn) btn.classList.toggle('active', newVal);
	};

	window.changeFontSize = function() {
		const size = document.getElementById('font-size').value;
		document.getElementById('editor').style.fontSize = size;
	};

	window.changeTheme = function() {
		const theme = document.getElementById('theme-select').value;
		editor.setTheme('ace/theme/' + theme);
	};

	// Apply settings broadcast from main window
	function applySettings(s) {
		if (!s) return;
		if (s.theme) {
			editor.setTheme('ace/theme/' + s.theme);
			const sel = document.getElementById('theme-select');
			if (sel) sel.value = s.theme;
		}
		if (s.font_size) {
			document.getElementById('editor').style.fontSize = s.font_size;
			const fs = document.getElementById('font-size');
			if (fs) fs.value = s.font_size;
		}
		['line_numbers', 'invisibles', 'indent_guides'].forEach(function(opt) {
			if (s[opt] === undefined) return;
			const def = optionMap[opt];
			editor.setOption(def.aceKey, s[opt]);
			const btn = document.getElementById(def.btnId);
			if (btn) btn.classList.toggle('active', !!s[opt]);
		});
	}

	// BroadcastChannel
	function initChannel() {
		channel = new BroadcastChannel(channelId);

		channel.addEventListener('message', function(e) {
			const d = e.data;
			if (d.type === 'xml-init') {
				skipChange = true;
				editor.setReadOnly(false);
				editor.setValue(d.xml || '', -1);
				skipChange = false;
				setSyncBadge('synced', 'Synced');
			} else if (d.type === 'sync-state') {
				if (d.state === 'synced') {
					setSyncBadge('synced', 'Synced');
				} else if (d.state === 'error') {
					setSyncBadge('editing', 'Out of sync (parse error)');
				} else if (d.state === 'stale') {
					setSyncBadge('editing', 'Out of sync');
				}
			} else if (d.type === 'settings-update') {
				applySettings(d.settings);
			}
		});

		// Announce readiness; retry several times in case main page is slow
		let retries = 0;
		function sendReady() {
			channel.postMessage({ type: 'ready' });
			if (++retries < 5) {
				setTimeout(sendReady, 600);
			}
		}
		setSyncBadge('offline', 'Connecting…');
		sendReady();
	}

	window.addEventListener('beforeunload', function() {
		if (channel) {
			channel.postMessage({ type: 'close' });
			channel.close();
		}
	});

	document.addEventListener('DOMContentLoaded', function() {
		initEditor();
		initChannel();
	});
}());
</script>
</body>
</html>
