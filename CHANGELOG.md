# Changelog

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
