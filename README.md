# fulltextsearch_postgresql

**Full-text search for Nextcloud, powered by PostgreSQL.**

This is a Nextcloud `fulltextsearch` *platform app* that keeps the index in the
PostgreSQL database Nextcloud already runs — no separate service to run.

Features:

- **Language-aware indexing.** Content is indexed with PostgreSQL text search
  configuration (stemming, stop words…) *and* with an accent-folding variant
  derived from it. The app follows your instance language by default and can
  combine several for a mixed corpus.
- **PDF and Office content, extracted by the app.** No external extraction
  service to install: PDFs and the usual office formats (`docx`, `xlsx`, `pptx`,
  `odt`, `ods`, `odp`) are read out of the box.
- **The usual search filters**: by source, by file extension, within a folder,
  and partial matching on file names.

Permissions are indexed with the content, so a search only ever matches what the
person running it can access.

## Installation

### Prerequisites

- **Nextcloud 32 to 34**, on **PostgreSQL 15 or later**. Both ends of that range are
  exercised, as is PostgreSQL 15.
- The [Full text search](https://apps.nextcloud.com/apps/fulltextsearch) app and a content
  provider — normally
  [Full text search - Files](https://apps.nextcloud.com/apps/files_fulltextsearch). Install
  those first.
- Optional: `pdftotext` (package `poppler-utils`) to speed up PDF extraction faster.

### PostgreSQL extensions, before the first indexing run

The `tsvector` column is generated when the table is created, so installing these later has
no effect until the index is rebuilt.

Nextcloud's database role cannot create them itself: `CREATE EXTENSION` needs the `CREATE`
privilege on the database. A superuser runs them once, on the Nextcloud database:

```sh
sudo -u postgres psql -d <your_nextcloud_database> \
    -c 'CREATE EXTENSION IF NOT EXISTS unaccent' \
    -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
```

- `unaccent` lets a search without accents match accented content,
- `pg_trgm` lets partial matching on names use an index instead of scanning the
table.

Both are optional but recommanded, `occ fulltextsearch:check` reports either one
as missing.

### The app

```sh
cd /var/www/nextcloud
git clone https://github.com/Alain-L/fulltextsearch_postgresql \
    custom_apps/fulltextsearch_postgresql
chown -R www-data:www-data custom_apps/fulltextsearch_postgresql

sudo -u www-data php occ app:enable fulltextsearch_postgresql
sudo -u www-data php occ config:app:set fulltextsearch search_platform \
    --value 'OCA\FullTextSearch_PostgreSQL\Platform\PostgresPlatform'
sudo -u www-data php occ fulltextsearch:check                 # platform and extensions
sudo -u www-data php occ fulltextsearch:index
sudo -u www-data php occ fulltextsearch:search <user> <term>  # a term you know is indexed
```

In a container, replace `sudo -u www-data` with `docker exec -u www-data …`.
`fulltextsearch:index` draws a full-screen progress display; add `--output json -r` for a
script or a cron job.

## Configuration

The indexing language is the only setting, and lives under Administration → Full text
search. The panel lists the text search configurations installed on your server.

![The settings panel, under Administration → Full text search](https://raw.githubusercontent.com/Alain-L/fulltextsearch_postgresql/main/screenshots/admin-settings.png)

`search_language` defaults to `auto`, which follows the Nextcloud instance
language; a language PostgreSQL does not know falls back to English. Several can
be combined for a mixed corpus, at roughly 20% of index size each.

Changing the language re-indexes everything from scratch. The `tsvector` column
is generated, so it cannot be altered in place: the table is dropped and every
document is extracted again. Nextcloud still believes they are indexed, hence
the reset:

```sh
sudo -u www-data php occ config:app:set fulltextsearch_postgresql search_language \
    --value french,english
sudo -u www-data php occ fulltextsearch:reset
sudo -u www-data php occ fulltextsearch:index
```

The settings panel says as much when you save a change. `fulltextsearch:reset` asks twice:
`y`, then the exact phrase `reset ALL ALL` — anything else aborts silently, so avoid chaining
it with `&&`.

## How it works

The `fulltextsearch` framework splits the work in two: a *content provider*
reads files and extracts their text, a platform app as this project stores it
and answers queries. So a PDF that yields no text is a provider matter; a search
that returns the wrong thing is ours.

Further reading: [PostgreSQL full text search](https://www.postgresql.org/docs/current/textsearch.html)
and [controlling it](https://www.postgresql.org/docs/current/textsearch-controls.html) for
`tsvector`, `tsquery`, `ts_rank` and `ts_headline`; the
[fulltextsearch wiki](https://github.com/nextcloud/fulltextsearch/wiki) for the framework
itself.

## How queries are interpreted

| You type         | What happens                                                        |
| ---------------- | ------------------------------------------------------------------- |
| `two words`      | matched with OR; documents carrying every term rank first           |
| `"exact phrase"` | quotes are binding — the words must be adjacent                     |
| `-word`          | excludes documents carrying it                                      |
| `+word`          | makes it mandatory, whatever the OR would otherwise let through     |
| `wor`            | matches by prefix from four characters — `chauff` finds `chauffage` |

`occ fulltextsearch:test` covers all of the above.

## Known limitations

- **Writing systems without spaces are not segmented**, so Chinese or Japanese content stays
  unfindable. No setting changes this — if your corpus is CJK, this app is not for you.
- **Scanned PDFs are not read**, though scanned images are: the upstream OCR app hands over
  text for `jpg` and `png`, but its PDF path is broken and it declares support only up to
  Nextcloud 32.
- **The indexed content is held twice**, once as text so excerpts can be built and once in the
  generated `tsvector` — and once more per extra language, at roughly 20% each.
- **Advanced search filters** (comparison queries, additional fields) are not implemented: the
  Files content provider never issues them.
- **No facets and no configurable sorting**: the framework API does not expose them.
- **A `tsvector` keeps only 16,383 positions**: past that, phrase search stops working towards
  the end of very long documents.
- **A compound word is out of reach of an exact phrase**: "comptes-rendus" tokenizes into two
  lexemes that `"compte rendu"` cannot span. Search it without quotes.
- **Tested up to 50,000 documents**; beyond that, uncharted.

## Contributing

The interface exists in English and French; adding a language takes two files and thirteen
strings. Bug reports, translations and patches are all welcome — see
[CONTRIBUTING.md](.github/CONTRIBUTING.md) for the development setup and the checks to run.

## Licence

This app is **AGPL-3.0-or-later** (see [`LICENSE`](LICENSE)); everything outside `vendor/` is
its own code and documentation.

`vendor/` bundles [smalot/pdfparser](https://github.com/smalot/pdfparser) by Sébastien MALOT
under **LGPL-3.0-only**, unmodified and pinned in `composer.lock`, with its licence text in
[`vendor/smalot/pdfparser/LICENSE.txt`](vendor/smalot/pdfparser/LICENSE.txt). Anyone remains
free to replace it with another version, and this paragraph is the use notice the LGPL-3.0
asks for in its section 4.
