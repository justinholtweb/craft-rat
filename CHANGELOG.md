# Changelog

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
