{include file='partials/_action_bar.tpl'}
{include file='partials/_modals.tpl'}

<div id='dialplan_preview_layer' style='display: none;'>
	<table cellpadding='0' cellspacing='0' border='0' width='100%' height='100%'>
		<tr>
			<td align='center' valign='middle'>
				<div id='dialplan_preview_container'>
					<div id='dialplan_preview_content'></div>
					<div class='dialplan-preview-actions'>
						<button type='button' class='btn btn-default' id='btn_dialplan_preview_restore' style='display: none;' onclick='dialplan_preview_restore_selected();'>Restore Original</button>
						<button type='button' class='btn btn-default' onclick='dialplan_preview_close();'>{$text['button-close']|escape}</button>
					</div>
				</div>
			</td>
		</tr>
	</table>
</div>

<style>
	#dialplan_preview_layer {
		z-index: 999999;
		position: fixed;
		left: 0;
		top: 0;
		right: 0;
		bottom: 0;
		background: rgba(0, 0, 0, 0.6);
	}

	#dialplan_preview_container {
		display: block;
		background: #ffffff;
		padding: 6px;
		margin: 0.5vh auto;
		text-align: left;
		width: 99vw;
		height: 99vh;
		overflow: auto;
		border-radius: 6px;
		box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
	}

	#dialplan_preview_content .dialplan-preview-header {
		margin-bottom: 12px;
	}

	#dialplan_preview_content .dialplan-preview-meta {
		color: #334155;
		font-size: 13px;
		line-height: 1.4;
	}

	#dialplan_preview_content .dialplan-preview-grid {
		display: grid;
		grid-template-columns: minmax(320px, var(--dialplan-preview-left, 1fr)) 12px minmax(320px, 1fr);
		gap: 0;
	}

	#dialplan_preview_content .dialplan-preview-splitter {
		background: #e5e7eb;
		cursor: col-resize;
		position: relative;
		border-left: 1px solid #cbd5e1;
		border-right: 1px solid #cbd5e1;
	}

	#dialplan_preview_content .dialplan-preview-splitter::before {
		content: '';
		position: absolute;
		top: 50%;
		left: 50%;
		transform: translate(-50%, -50%);
		width: 4px;
		height: 42px;
		border-radius: 999px;
		background: #94a3b8;
	}

	#dialplan_preview_content .dialplan-preview-pane {
		border: 1px solid #d0d7de;
		border-radius: 6px;
		overflow: hidden;
		min-height: 62vh;
		display: flex;
		flex-direction: column;
	}

	#dialplan_preview_content .dialplan-preview-pane-title {
		background: #f8fafc;
		padding: 8px 10px;
		font-weight: 600;
		border-bottom: 1px solid #d0d7de;
	}

	#dialplan_preview_content .dialplan-preview-xml {
		margin: 0;
		padding: 0;
		overflow: auto;
		font-family: Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
		font-size: 12px;
		line-height: 1.5;
		white-space: pre;
		background: #ffffff;
		flex: 1;
	}

	#dialplan_preview_content .dialplan-preview-line {
		display: grid;
		grid-template-columns: 56px 1fr;
		min-height: 20px;
	}

	#dialplan_preview_content .dialplan-preview-line-number {
		background: #f8fafc;
		color: #64748b;
		padding: 0 8px;
		text-align: right;
		border-right: 1px solid #e2e8f0;
		user-select: none;
	}

	#dialplan_preview_content .dialplan-preview-line-code {
		display: block;
		padding: 0 10px;
		overflow: visible;
	}

	#dialplan_preview_content .dialplan-preview-line.is-changed {
		background: #fef3c7;
	}

	#dialplan_preview_content .dialplan-preview-line.is-current-only {
		background: #dcfce7;
	}

	#dialplan_preview_content .dialplan-preview-line.is-original-only {
		background: #fee2e2;
	}

	#dialplan_preview_content .title {
		font-size: 18px;
		font-weight: 600;
		margin-bottom: 8px;
	}

	#dialplan_preview_container .dialplan-preview-actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 12px;
	}

	#dialplan_preview_container .dialplan-preview-actions .btn {
		min-width: 96px;
	}

	@media (max-width: 900px) {
		#dialplan_preview_content .dialplan-preview-grid {
			grid-template-columns: 1fr;
			gap: 10px;
		}

		#dialplan_preview_content .dialplan-preview-splitter {
			display: none;
		}

		#dialplan_preview_content .dialplan-preview-pane {
			min-height: 36vh;
		}
	}
</style>

{$page_description}<br /><br />
{include file='partials/_table.tpl'}

<script>
let dialplan_preview_row_index = null;

function dialplan_preview_bind_sync_scroll() {
	const panes = document.querySelectorAll('#dialplan_preview_content .dialplan-preview-xml');
	if (!panes || panes.length < 2) {
		return;
	}

	let syncing = false;
	panes.forEach(function(sourcePane) {
		sourcePane.addEventListener('scroll', function() {
			if (syncing) {
				return;
			}
			syncing = true;
			panes.forEach(function(targetPane) {
				if (targetPane === sourcePane) {
					return;
				}
				targetPane.scrollTop = sourcePane.scrollTop;
				targetPane.scrollLeft = sourcePane.scrollLeft;
			});
			syncing = false;
		});
	});
}

function dialplan_preview_init_resizer() {
	const grid = document.querySelector('#dialplan_preview_content .dialplan-preview-grid');
	const splitter = document.querySelector('#dialplan_preview_content .dialplan-preview-splitter');
	if (!grid || !splitter) {
		return;
	}

	let isDragging = false;

	function getMinPaneWidth() {
		if (window.matchMedia('(max-width: 1200px)').matches) {
			return 260;
		}
		return 320;
	}

	function applyDrag(clientX) {
		const rect = grid.getBoundingClientRect();
		const minPaneWidth = getMinPaneWidth();
		const splitterWidth = splitter.getBoundingClientRect().width || 12;
		let leftWidth = clientX - rect.left;
		const maxLeftWidth = rect.width - minPaneWidth - splitterWidth;
		if (leftWidth < minPaneWidth) {
			leftWidth = minPaneWidth;
		}
		if (leftWidth > maxLeftWidth) {
			leftWidth = maxLeftWidth;
		}
		grid.style.setProperty('--dialplan-preview-left', leftWidth + 'px');
	}

	function stopDrag() {
		isDragging = false;
		document.body.style.userSelect = '';
		document.body.style.cursor = '';
	}

	splitter.addEventListener('pointerdown', function(e) {
		if (window.matchMedia('(max-width: 900px)').matches) {
			return;
		}
		isDragging = true;
		document.body.style.userSelect = 'none';
		document.body.style.cursor = 'col-resize';
		splitter.setPointerCapture(e.pointerId);
		e.preventDefault();
	});

	splitter.addEventListener('pointermove', function(e) {
		if (!isDragging) {
			return;
		}
		applyDrag(e.clientX);
	});

	splitter.addEventListener('pointerup', function() {
		stopDrag();
	});

	splitter.addEventListener('pointercancel', function() {
		stopDrag();
	});

	window.addEventListener('resize', function() {
		if (window.matchMedia('(max-width: 900px)').matches) {
			grid.style.removeProperty('--dialplan-preview-left');
		}
	});
}

function dialplan_preview_open(preview_url, row_index, can_restore) {
	dialplan_preview_row_index = Number.isInteger(row_index) ? row_index : parseInt(row_index, 10);
	const layer = document.getElementById('dialplan_preview_layer');
	const content = document.getElementById('dialplan_preview_content');
	const restore_button = document.getElementById('btn_dialplan_preview_restore');

	if (!layer || !content || !restore_button) {
		return;
	}

	restore_button.style.display = can_restore ? 'inline-block' : 'none';
	content.innerHTML = '<div class="title">Loading preview...</div>';
	layer.style.display = 'block';

	fetch(preview_url, {
		method: 'GET',
		credentials: 'same-origin',
		headers: {
			'X-Requested-With': 'XMLHttpRequest'
		}
	})
		.then(function(response) {
			if (!response.ok) {
				throw new Error('Preview request failed.');
			}
			return response.text();
		})
		.then(function(html) {
			content.innerHTML = html;
			dialplan_preview_bind_sync_scroll();
			dialplan_preview_init_resizer();
		})
		.catch(function(error) {
			content.innerHTML = '<div class="title">Unable to load preview</div><div>' + String(error.message || error) + '</div>';
		});
}

function dialplan_preview_close() {
	const layer = document.getElementById('dialplan_preview_layer');
	if (layer) {
		layer.style.display = 'none';
	}
}

function dialplan_preview_restore_selected() {
	if (!Number.isInteger(dialplan_preview_row_index) || dialplan_preview_row_index < 0) {
		return;
	}
	list_self_check('checkbox_' + dialplan_preview_row_index);
	list_action_set('restore_original');
	list_form_submit('form_list');
}

document.addEventListener('keydown', function(e) {
	if (e.key === 'Escape') {
		const layer = document.getElementById('dialplan_preview_layer');
		if (layer && layer.style.display !== 'none') {
			dialplan_preview_close();
		}
	}
});
</script>
