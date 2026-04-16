# Rat

A Craft CMS 5 plugin that tracks which users made edits to all content types on your site.

## Features

- Tracks edits to **entries, assets, globals, categories, tags, users**, and **Commerce elements** (products, variants, orders)
- **Dashboard widget** showing recent edit activity across the site
- **Element sidebar** showing per-element edit history (who edited, when, what changed)
- **Multi-site aware** — tracks edits per site
- Automatically filters out drafts, revisions, propagating saves, and bulk resaves

## Requirements

- Craft CMS 5.3.0 or later
- PHP 8.2 or later

## Installation

Install via Composer:

```bash
composer require justinholtweb/craft-rat
```

Then install the plugin from the Craft control panel under **Settings > Plugins**, or via the CLI:

```bash
php craft plugin/install rat
```

## Usage

### Dashboard Widget

Add the **Recent Edits** widget to your dashboard from the dashboard settings. Configure the number of entries to display (default: 20).

### Element Sidebar

The edit history sidebar appears automatically on all element edit pages (entries, assets, globals, etc.). It shows the most recent 10 edits with the user, action, and timestamp.

### Commerce Support

If Craft Commerce is installed, Rat automatically tracks edits to products, variants, and orders — no extra configuration needed.

## License

MIT
