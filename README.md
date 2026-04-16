# Rat for Craft CMS 5

Track which users made edits to all content types on your Craft CMS site. Rat logs every element save with user attribution, changed fields, and timestamps — then surfaces that history in a dashboard widget and per-element sidebar panel.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

## Installation

Open your terminal and run:

```bash
composer require justinholtweb/craft-rat
php craft plugin/install rat
```

Or install via the Craft control panel under **Settings > Plugins**.

## Features

### Automatic Edit Tracking

Rat listens for saves on all element types and logs who made the change, what fields were modified, and whether the element was created or updated. Drafts, revisions, propagating saves, and bulk resaves are automatically filtered out.

Supported element types:

- Entries, Assets, Globals, Categories, Tags, Users
- Craft Commerce Products, Variants, and Orders (if Commerce is installed)
- Any custom element type

### Dashboard Widget

Add the **Recent Edits** widget to your dashboard to see a live feed of edit activity across the site. Each row shows the user, element name (linked to its edit page), element type, action (created or edited), and a relative timestamp. The display limit is configurable from 1 to 100.

### Element Sidebar

Every element edit page gets an **Edit History** panel in the sidebar showing the last 10 edits with user photos, action type, changed fields, and timestamps. A "View more..." link loads additional history via AJAX.

### Multi-Site Support

Edits are tracked per site, so multi-site installs get accurate per-site history.

## How It Works

Rat registers a single `Element::EVENT_AFTER_SAVE` listener on the base `Element` class, so all element types are covered without needing individual listeners. Each save is recorded to a `rat_editlog` database table with the element ID, site ID, user ID, element type, label, and a JSON list of changed field names.

The sidebar uses `Element::EVENT_DEFINE_SIDEBAR_HTML` to inject edit history into every element edit page. The widget is registered via `Dashboard::EVENT_REGISTER_WIDGET_TYPES`.

## Cleanup

Rat includes a `cleanupOldLogs` method that removes records older than a given number of days (default 90). This is not scheduled automatically — call it from a console command or cron job if needed:

```php
use justinholtweb\rat\Plugin as Rat;

Rat::getInstance()->editTracker->cleanupOldLogs(90);
```

## Configuration

Rat works out of the box with no configuration. Install and go.

## License

See [LICENSE.md](LICENSE.md).
