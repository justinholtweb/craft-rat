# Changelog

## 5.1.3 - 2026-10-05

> {warning} Rat now prunes its edit log. During Craft's garbage collection, entries older than
> **90 days** are deleted. Change that under **Settings → Plugins → Rat**, or set it to 0 to keep
> everything. On a site that has been running for a while, the first prune can remove a lot, so
> raise the setting first if you want more history kept. Front-end saves by visitors who aren't
> signed in, and Commerce orders and carts, are no longer recorded.

### Fixed
- The edit log grew without limit: `cleanupOldLogs()` existed, but nothing called it. It now runs on
  Craft's garbage collection, keeping the new **Keep edit history for** setting (90 days by
  default), and there's a `php craft rat/log/prune [--days=N]` command.
- Every front-end save was logged, so a store gained rows on every cart action. Saves by anonymous
  front-end visitors are now skipped (a setting turns them back on), and so are Commerce orders
  (an editable list of excluded element types). Saves made by queue jobs are still recorded, even
  when Craft runs the queue from an anonymous request.
- `cleanupOldLogs()` built its cutoff in the server's time zone while Craft stores dates in UTC,
  so rows were kept or deleted up to a day off. It now compares in UTC, and deletes in chunks so
  pruning a large log doesn't lock the table for long.
- The Recent Edits widget loaded each entry's element and editor one query at a time, up to 500
  queries per render for a user who can see little. Elements now load in one query per type and
  site, and editors in one query. The edit-history sidebar loads its editors in one query too.
- The **Last Editor** index column ran one query per row. It's now fetched for the whole page in
  one query.

## 5.1.2 - 2026-09-15

### Fixed
- User avatars no longer render blank in the Edit History sidebar and the Recent Edits widget. The templates called `Asset::getThumbUrl()`, which doesn’t exist in Craft 5; they now use `User::getThumbHtml()`, the same method Craft’s own CP header uses ([#1](https://github.com/justinholtweb/craft-rat/issues/1))
- The element-history endpoint no longer throws `UnknownMethodException` for users with a photo; it now generates thumbnail URLs through the assets service
- Users without a photo now get their initials instead of an empty circle
- The “View more...” link in the Edit History sidebar now loads more history. The JavaScript behind it was never shipped, so the link did nothing and the `rat/edit-log/element-history` endpoint it was built for was never called
- The sidebar no longer offers a “View more...” link when the first page is exactly full and nothing follows it

### Changed
- The element-history response now also includes `html` (rendered rows, so entries appended by the “View more...” link match the ones rendered with the page) and `hasMore`. The existing `history` array is unchanged

## 5.1.1 - 2026-07-22

### Security
- Edit history is now gated by the same permissions Craft uses for the element itself. The element-history endpoint returns 403 for elements the current user can’t view, and the Recent Edits widget no longer lists edits to sections a user has no access to

### Fixed
- Saving an element whose label exceeds 255 characters no longer fails. The label is truncated to fit the column instead of throwing out of the element’s own save
- Edits recorded within the same second are now ordered newest-first consistently in the widget and sidebar, and no longer shift between pages
- The “Last Editor” column no longer shows a stale value when an element is saved and re-rendered in the same request

### Changed
- The uninstall migration uses `dropTableIfExists()` instead of the deprecated `MigrationHelper::dropTable()`
- Added a DDEV environment and a Codeception test suite (unit + integration against a real Craft install)

## 5.1.0 - 2026-07-05

### Added
- “Last Editor” column, available on every element index (entries, categories, assets, users, Commerce products, etc.), showing who made the most recent tracked edit and when
- “Last Editor” sort option on element indexes, so you can order any list by who last touched each element

## 5.0.0 - 2026-06-12

### Added
- Initial release
- Track edits to entries, assets, globals, categories, tags, users, and Commerce elements
- Dashboard widget showing recent edit activity
- Element sidebar panel showing edit history per element
- Multi-site support
- Automatic filtering of drafts, revisions, propagating saves, and bulk resaves
