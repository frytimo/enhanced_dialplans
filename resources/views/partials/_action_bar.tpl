<div class='action_bar' id='action_bar'>
	<div class='heading'><b>{$page_header}</b><div class='count'>{$num_rows|number_format}</div></div>
	<div class='actions'>
		{$btn_add}
		{$btn_copy}
		{$btn_toggle}
		{$btn_delete}
		{$btn_restore}
		{$btn_xml}
		<form id='form_search' class='inline' method='get'>
			{if $has_dialplan_all}
				{if $show == 'all'}
					<input type='hidden' name='show' value='all'>
				{else}
					{$btn_show_all}
				{/if}
			{/if}
			{if $app_uuid != ''}
				<input type='hidden' name='app_uuid' value='{$app_uuid|escape}'>
			{/if}
			{if $order_by != ''}
				<input type='hidden' name='order_by' value='{$order_by|escape}'>
			{/if}
			{if $order != ''}
				<input type='hidden' name='order' value='{$order|escape}'>
			{/if}
			{if $has_dialplan_context}
				{$context_selector}
			{/if}
			<input type='text' class='txt list-search' name='search' id='search' value="{$search|escape}" placeholder="{$text['label-search']}" onkeydown=''>
			{$btn_search}
			{if $paging_controls_mini != ''}
				<span style='margin-left: 15px;'>{$paging_controls_mini}</span>
			{/if}
		</form>
	</div>
	<div style='clear: both;'></div>
</div>
