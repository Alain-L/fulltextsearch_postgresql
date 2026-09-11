# Changelog

This format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## 0.1.2 — 2026-09-11

### Fixed

- The index table no longer sits inside the Nextcloud table prefix. Nextcloud hands every
  prefixed table to Doctrine before each schema migration, and Doctrine has no mapping for
  `text[]`: `occ upgrade` stopped on `Unknown database type _text` at the first core migration,
  leaving the server in maintenance mode. That blocked every Nextcloud update, security ones
  included, for as long as this app was installed. Updating the app renames the table in place,
  keeping the rows, the indexes and the generated column, so no re-indexing is needed.
- Index names are derived from the table name, the two trigram ones included, so they no longer
  collide when several installations share a schema. The rename carries them over, rather than
  leaving a second set to be created beside the first.

### If you are stuck

Before attempting a Nextcloud upgrade, update this app: `occ app:update
fulltextsearch_postgresql`, then upgrade as usual.

If the upgrade has already failed, installing this version does not help on its own: Nextcloud
runs core schema migrations before any app repair step, so the step never gets its turn, and
`occ app:update` reports the app as up to date without doing anything. Removing the app does not
help either — it leaves the table behind, and the table is what breaks the migration. Rename it
by hand:

```sh
psql -d <your_nextcloud_database> \
    -c 'ALTER TABLE oc_fulltextsearch_pg RENAME TO fts_pg_oc'
occ upgrade && occ maintenance:mode --off
```

The next update of this app carries the index names over. `fts_pg_oc` assumes the default `oc_`
table prefix; the name is `fts_pg_` followed by yours, stripped of anything that is not a letter
or a digit. Dropping the table works too — it is a cache — at the price of one re-index.

### Before removing the app

`occ app:remove` leaves the index table, the `nc_fts_*` text search configurations and a few
rows in `oc_appconfig` behind — `occ fulltextsearch:reset` empties the table but does not drop
it. Nothing breaks if they stay, the table being outside the prefix now; drop it and the
configurations by hand if you want the database clean.

## 0.1.1 — 2026-09-10

### Fixed

- Installing `unaccent` after the first indexing run now rebuilds the index table, so searching
  without accents starts working. It used to stay broken for good: the generated `tsvector`
  column is fixed when the table is created, and neither `fulltextsearch:reset` nor a full
  re-index redefined it.
- `occ fulltextsearch:check` no longer reports a healthy index when the table was built for a
  different configuration. It names the mismatch and the two commands that settle it.

### Changed

- The warning about a missing extension now suggests granting `CREATE` on the database to the
  role Nextcloud connects with, which lets the app create both extensions on its next indexing
  run, instead of asking a superuser to create each one.

## 0.1.0 — 2026-09-10

First release. Tested on Nextcloud 32, 33 and 34, against PostgreSQL 15 and 18.

### Added

- Full-text search for Nextcloud backed by PostgreSQL, with no service to run alongside it.
- Language-aware indexing: content is indexed with a PostgreSQL text search configuration and
  with an accent-folding variant derived from it, so a search without accents still matches
  accented content. The language follows the Nextcloud instance by default, and several can be
  combined for a mixed corpus.
- PDF and office content extracted by the app itself — `docx`, `xlsx`, `pptx`, `odt`, `ods`,
  `odp` and PDF, with no external extraction service to install.
- Permissions indexed with the content, so a search only ever matches what the person running
  it can access.
- Search filters as the Files content provider issues them: by source, by extension, within a
  folder, and partial matching on file and share names.
- Query syntax: `OR` between words with documents carrying every term ranked first, binding
  quotes for exact phrases, `-word` to exclude, `+word` to require, and prefix matching from
  four characters.
- An administration panel offering the text search configurations installed on the server.
- English and French interface translations.

### Known limitations

- Writing systems without spaces are not segmented, so Chinese or Japanese content stays
  unfindable. No setting changes this.
- Scanned PDFs are not read, though scanned images are: the upstream OCR app hands over text
  for `jpg` and `png`, but its PDF path is broken and it declares support only up to
  Nextcloud 32.
- The indexed content is held twice, once as text so excerpts can be built and once in the
  generated `tsvector`, and once more per extra language.
- In a multilingual corpus, excerpts are cut using the dictionary of the first configured
  language: the text stays correct, only the choice of fragment may be less apt.
- Advanced search filters (comparison queries, additional fields) are not implemented, as the
  Files content provider does not issue them.
- No facets and no configurable sorting: the framework API does not expose them.
- Tested up to 50,000 documents; beyond that, uncharted.
