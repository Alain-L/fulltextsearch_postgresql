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

- **Nextcloud 32 to 34**, on **PostgreSQL 15 or later**. Both ends of the Nextcloud range
  are tested, as are PostgreSQL 15 and 18.
- The [Full text search](https://apps.nextcloud.com/apps/fulltextsearch) app and a content
  provider — normally
  [Full text search - Files](https://apps.nextcloud.com/apps/files_fulltextsearch). Install
  those first.
- Optional: `pdftotext` (package `poppler-utils`), which reads PDFs faster than the
  bundled library.

### The app

Install [Full text search - PostgreSQL
Platform](https://apps.nextcloud.com/apps/fulltextsearch_postgresql) from Administration →
Apps, then open Administration → Full text search and pick it under **Search Platform**.

Without the App Store, clone this repository into `custom_apps/fulltextsearch_postgresql`,
give it to your web server user, and enable it with `occ app:enable
fulltextsearch_postgresql`. Everything after that is the same.

### PostgreSQL extensions, before the first indexing run

The app tries to create `unaccent` and `pg_trgm` on first run. Both are *trusted*, so a role
holding `CREATE` on the database installs them without being a superuser — but Nextcloud does
not necessarily run as such a role: its installer often creates a dedicated `oc_…` role that
holds `CREATE` on the schema and not on the database, which is not enough.

`occ fulltextsearch:check` names whichever is missing — it reports, it does not create; only
an indexing run does. Either grant the privilege once and let the app do the rest, or create
the two extensions yourself:

```sh
# grant, using the role named by `occ config:system:get dbuser`
psql -U <superuser> -d <your_nextcloud_database> \
    -c 'GRANT CREATE ON DATABASE <your_nextcloud_database> TO <dbuser>'

# or create them directly
psql -U <superuser> -d <your_nextcloud_database> \
    -c 'CREATE EXTENSION IF NOT EXISTS unaccent' \
    -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
```

The official `postgres` image creates no `postgres` role when `POSTGRES_USER` is set, so
`<superuser>` there is that user, reached with `docker exec <db_container> psql …`.

- `unaccent` lets a search without accents match accented content,
- `pg_trgm` lets partial matching on names use an index instead of scanning the table.

Both are optional, but clear that warning before indexing: the `tsvector` column is generated
when the table is created, so `unaccent` added later costs a full `occ fulltextsearch:reset`
and re-index.

### The first indexing run

```sh
sudo -u www-data php occ fulltextsearch:check                 # platform and extensions
sudo -u www-data php occ fulltextsearch:index
sudo -u www-data php occ fulltextsearch:search <user> <term>  # a term you know is indexed
```

In a container, replace `sudo -u www-data` with `docker exec -u www-data …`.
`fulltextsearch:index` draws a full-screen progress display; `-r` only drops the interactive
prompt, so send its output to `/dev/null` in a cron job. Nextcloud indexes new files on its
own from then on.

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
`y`, then `reset ALL ALL` — anything else prints `aborted.` and leaves the index alone, so
avoid chaining it with `&&`.

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

`occ fulltextsearch:test` covers the operators and the phrase syntax, not prefix matching.

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
