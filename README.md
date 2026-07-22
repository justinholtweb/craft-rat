# Rat for Craft CMS 5

Track which users made edits to all content types on your Craft CMS site. Rat logs every element save with user attribution, changed fields, and timestamps — then surfaces that history in a dashboard widget and per-element sidebar panel.

Full documentation: [craft-rat.com](https://craft-rat.com)

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

### Permissions

Edit history is only ever shown to users who could open the element themselves. Rat defers to Craft's own `canView` check, so section permissions, peer-entry rules, and site access all apply:

- The **Recent Edits** widget only lists edits to elements the viewing user can access. An editor limited to one section won't see titles from any other.
- The element-history endpoint returns a 403 for elements the user isn't authorized to view.

Admins see everything, including edits to elements that have since been deleted.

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

## Development

The repo ships with a [DDEV](https://ddev.com) environment so the test suite has a PHP runtime and a database without needing a full Craft install:

```bash
ddev start
ddev composer install
ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS test"
```

### Tests

Two Codeception suites:

- **`unit`** — pure PHPUnit tests over logic that doesn't need a booted Craft app. Fast, no database.
- **`integration`** — boots a real Craft application against a throwaway `test` schema using Craft's own Codeception module, installs the plugin, and exercises it end to end through real element saves. Each test runs in a transaction that's rolled back afterwards.

```bash
ddev composer test               # both suites
ddev composer test-unit
ddev composer test-integration
```

The unit suite can also be run directly through PHPUnit via `ddev exec vendor/bin/phpunit`.

Test database credentials live in `tests/.env` and point at the DDEV database container. They only ever address the throwaway `test` schema, which the suite drops and recreates on every run.

## License

See [LICENSE.md](LICENSE.md).
