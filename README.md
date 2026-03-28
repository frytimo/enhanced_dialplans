# FusionPBX Visual Dialplans

## Overview
The Visual Dialplans app is a part my extensions library for FusionPBX, designed to manage and visualize dialplan configurations. This app allows users to view, edit, and manage dialplan settings, making it easier to configure call routing and other telephony features.

## Features
- **Automatic synchronization**: Automatically synchronized XML file as you update the drag-and-drop elements of the dialplan.
- **Simple rule checking**: Checks for simple mistakes and gives error notifications.
- **Dialplan Management**: Users can add, edit, and delete dialplans.
- **Context Filtering**: Users can filter dialplans by context to manage specific sets of rules.
- **Search Functionality**: Users can search for dialplans by name, number, or description.
- **Toggle Enabled/Disabled**: Users can enable or disable dialplans as needed.

## Usage
1. **Access the App**: Navigate to the Visual Dialplans section in the FusionPBX dashboard.
2. **View Dialplans**: The app displays a list of all dialplans, with options to filter and search.
3. **Edit Dialplans**: Click on a dialplan to edit its settings.
4. **Add New Dialplans**: Use the "Add" button to create new dialplan configurations.
5. **Toggle Status**: Use the toggle button to enable or disable dialplans.
6. **Export XML**: Use the "XML" button to export the current dialplan configuration in XML format.

## Permissions
- **dialplan_view**: View dialplans.
- **dialplan_add**: Add new dialplans.
- **dialplan_edit**: Edit existing dialplans.
- **dialplan_delete**: Delete dialplans.
- **dialplan_xml**: Export dialplans in XML format.

## Images and Snapshots
- **Visual Dialplan Editor**: ![Dialplan List](docs/images/Screenshot_20260327_235450.png)
- **Syncronized XML View**:
  - ![Dialplan Edit](docs/images/Screenshot_20260327_235727.png)
  - ![Dialplan Edit](docs/images/Screenshot_20260327_235719.png)
- **Pop-out XML View**: ![Dialplan Popout](docs/images/Screenshot_20260327_235551.png)
- **Three-way buttons**:
  - ![Dialplan Add](docs/images/Screenshot_20260327_235817.png)
  - ![Dialplan Add](docs/images/Screenshot_20260327_235828.png)
  - ![Dialplan Add](docs/images/Screenshot_20260327_235835.png)
- **Drag-and-drop**: ![Dialplan XML Export](docs/images/draganddrop.png)
- **Nesting**:
  - ![Nested](docs/images/nested.png)
- **Autocomplete as you type**:
  - ![Autocomplete](docs/images/autocomplete.png)
- **Rules based error checking**:
  - ![Notice](docs/images/notice.png)
  - ![Error](docs/images/error.png)

## Migrate
The current dialplan editor uses a hybrid approach where the xml file OR the dialplan details can determine the final xml values used depending on which one was last saved. This version uses a single authority of the XML file as the ultimate authority.

The included tool: `php app/visual_dialplans/resources/migrate_to_unified.php` will ensure all dialplans can use the new visual tool.

Once the XML file is saved, this editor will be needed to edit the entry. It is assumed that you will not use the old one once this one is installed.

## Install

**Requires PHP 8.1+ and FusionPBX 5.4 or higher**

```
cd /var/www/fusionpbx/app
git clone https://github.com/frytimo/visual_dialplans
cd /var/www/fusionpbx
php core/upgrade/upgrade.php --schema
php core/upgrade/upgrade.php --defaults
php core/upgrade/upgrade.php --permissions
php core/upgrade/upgrade.php --menu
```
Logout and back in again

## Developers

### Visual Dialplan Custom Lint Rules

The Visual Dialplan editor can load lint rules from any app automatically.

Rule file location per app:

- `app/<your_app>/resources/javascript/dialplan_lint_rules.js`

How discovery works:

- The loader in `app/visual_dialplans/dialplan_lint_rules_loader.php` scans all app folders for `resources/javascript/dialplan_lint_rules.js`.
- Each file is loaded in isolation and merged into the global `DialplanLintRules` array.
- If one custom rule file has an error, it is ignored so the editor continues working.

Rule format:

```javascript
var DialplanLintRules = [
	{
		id: 'action-missing-data',
		severity: 'warning', // 'error' | 'warning' | 'info'
		description: 'Action or anti-action has an application but no data',
		check: function (tree) {
			var findings = [];

			function walk(nodes) {
				if (!nodes) return;
				for (var i = 0; i < nodes.length; i++) {
					var node = nodes[i];

					if ((node.type === 'action' || node.type === 'anti-action') &&
						node.enabled !== false &&
						node.attributes &&
						node.attributes.application &&
						!node.attributes.data) {
						findings.push({
							node: node,
							message: 'Application is set but data is empty'
						});
					}

					if (node.type === 'condition' && node.children) {
						walk(node.children);
					}
				}
			}

			walk(tree.children || []);

			return findings;
		}
	}
];
```

Notes:

- `check(tree)` must return an array of objects with `{ node, message }`.
- `node` should be a reference to a node from the parsed dialplan tree.
- Keep rule files self-contained and avoid dependencies on app page globals.
