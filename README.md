# fulltextsearch_postgresql

**Full-text search for Nextcloud, powered by PostgreSQL.**
Language-aware indexing, with PDF and Office extraction included.

Nextcloud's `fulltextsearch` framework delegates storing and querying file content to a
*platform app*. This is one, and it keeps the index in the database Nextcloud already runs.

- **No extra service.** Nothing to install, monitor, secure or back up alongside Nextcloud,
  and no JVM — your existing `pg_dump` already covers the search index. It also removes one
  dimension from the compatibility matrix at every major Nextcloud release.
- **Language-aware indexing.** Content is indexed with a real PostgreSQL text search
  configuration — stemming, stop words, the lot — *and* with an accent-folding variant derived
  from it, so `numerisation` finds “numérisation” without costing the stemming. The app
  follows your instance language by default and can combine several for a mixed corpus.
- **PDF and Office content, extracted by the app.** No external extraction service: PDFs
  through `pdftotext` when poppler is available and a bundled library otherwise, and `docx`,
  `xlsx`, `pptx`, `odt`, `ods`, `odp` by direct reading.
- **Access rights enforced in SQL.** Owner, users, groups and circles become indexed `text[]`
  conditions in the query itself — filtered by the database, not after the fact.
- **The search filters the Files provider actually issues**: source, extension, “search
  within”, and partial matching on file and share names.

This answers a request open since April 2018 —
[“Postgres as Platform Apps?”](https://github.com/nextcloud/fulltextsearch/issues/302) —
labelled *help wanted*.

## Installation

Requires the [Full text search](https://apps.nextcloud.com/apps/fulltextsearch) app and a
content provider — normally [Full text search - Files](https://apps.nextcloud.com/apps/files_fulltextsearch).
Install those first.

### 1. The PostgreSQL extensions, before anything else

**Do this before the first indexing run.** The `tsvector` column is generated when the table is
created: installing the extensions afterwards changes nothing until the index is rebuilt, and
`occ fulltextsearch:check` will cheerfully report `"unaccent": true` while accent-insensitive
search stays broken.

Both are *trusted* extensions, but `CREATE EXTENSION` still needs the `CREATE` privilege on the
database, which Nextcloud's application role does not have. A superuser runs them once, **on
the Nextcloud database itself** — not on `postgres`:

```sh
sudo -u postgres psql -d nextcloud -c 'CREATE EXTENSION IF NOT EXISTS unaccent'
sudo -u postgres psql -d nextcloud -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm'
```

The app runs without them and says so — `occ fulltextsearch:check` lists what is missing and
what it costs. Without `unaccent`, searching without accents stops matching accented content
altogether; without `pg_trgm`, partial matching on names still works but scans the whole table.

### 2. The app

```sh
cd /var/www/nextcloud
git clone https://github.com/Alain-L/fulltextsearch_postgresql \
    custom_apps/fulltextsearch_postgresql
chown -R www-data:www-data custom_apps/fulltextsearch_postgresql

sudo -u www-data php occ app:enable fulltextsearch_postgresql
sudo -u www-data php occ config:app:set fulltextsearch search_platform \
    --value 'OCA\FullTextSearch_PostgreSQL\Platform\PostgresPlatform'
sudo -u www-data php occ fulltextsearch:index
```

Dependencies are bundled, so there is no `composer install` step. The platform can equally be
selected from Administration → *Full text search* if you prefer the interface. Adjust the paths
and the `www-data` user to your installation; in a container, `docker exec -u www-data …`
replaces `sudo -u www-data`.

`fulltextsearch:index` draws a full-screen progress display; add `--output json -r` when
running it from a script or a cron job.

### 3. Check that it works

```sh
sudo -u www-data php occ fulltextsearch:check              # platform, extensions, warnings
sudo -u www-data php occ fulltextsearch:search admin word  # a term you know is in a file
sudo -u www-data php occ fulltextsearch:test               # the framework's conformance test
```

`fulltextsearch:test` exercises the platform end to end — keywords, exclusions, mandatory
terms, group and share permissions — and exits non-zero on the first failure.

**Requirements**: Nextcloud 32 to 34, on PostgreSQL 13 or later. Both ends of that
range are exercised, as is PostgreSQL 13.

`pdftotext` (package `poppler-utils`) is used for PDFs when present, because it is faster.
Otherwise the bundled library handles them — nothing to install.

## Configuration

One setting: the indexing language. There is no cluster to reach, no index to name, no
certificate to accept — the database is Nextcloud's own. It lives under **Administration →
Full text search**, where the panel offers the configurations actually installed on your
server.

| Setting | Default | Effect |
|---|---|---|
| `search_language` | `auto` | The PostgreSQL text search configuration(s) used to index and query |

`auto` follows the Nextcloud instance language; a language with no PostgreSQL equivalent falls
back to English. Several can be combined for a mixed corpus, at roughly 20% of index size
each — worth it when English documentation sits next to French correspondence, since a French
stemmer will not reduce *searching* to *search*. Beyond three the app warns without forbidding
anything.

Changing the language **rebuilds the index**, because the `tsvector` column is generated and
cannot be altered in place. The table is recreated automatically, but Nextcloud still believes
the documents are indexed — hence the reset:

```sh
sudo -u www-data php occ config:app:set fulltextsearch_postgresql search_language \
    --value french,english
sudo -u www-data php occ fulltextsearch:reset   # asks 'y', then literally: reset ALL ALL
sudo -u www-data php occ fulltextsearch:index
```

`fulltextsearch:reset` asks for confirmation twice — `y`, then the exact phrase
`reset ALL ALL`. Anything else aborts silently, which is easy to miss when the command is
chained with `&&`.

## How it works

```
Nextcloud
  ├─ Content Provider (files_fulltextsearch)  ── reads the files
  │        │  IndexDocument { content, metadata, access rights }
  │        ▼
  └─ Platform App  ◄── THIS REPOSITORY ──►  PostgreSQL
                                       one table, a generated tsvector, GIN indexes
```

Content arrives encoded, sometimes as text, sometimes as raw binary (PDF, Office): the app
decodes it, extracts the text, then indexes it into a generated, weighted `tsvector` column —
title, content, attached parts. Queries are translated with `websearch_to_tsquery`, ranked with
`ts_rank` and highlighted with `ts_headline`. Access rights become a SQL clause over indexed
`text[]` columns.

Twelve files, roughly 1,950 lines, and all the SQL in one of them.

## How queries are interpreted

Worth knowing, because it is not what every engine does:

| You type | What happens |
|---|---|
| `two words` | matched with **OR**; documents carrying every term rank first |
| `"exact phrase"` | quotes are binding — the words must be adjacent |
| `-word` | excludes documents carrying it |
| `+word` | makes it mandatory, whatever the OR would otherwise let through |
| `wor` | matches by prefix from four characters — `chauff` finds `chauffage` |

Searching for several words is deliberately generous: a query returns more than a strict AND
would, and the ranking sorts it out. `occ fulltextsearch:test` covers all of the above.

## Known limitations

- **Writing systems without spaces are not segmented.** PostgreSQL splits text on separators,
  so Chinese or Japanese content becomes a single lexeme and stays unfindable. No setting
  changes this — it is a property of the text search parser. If your corpus is CJK, this app
  is not for you.
- **Advanced search filters** (comparison queries, additional fields) are not implemented: the
  Files content provider never issues them.
- **OCR is no longer available upstream.** `files_fulltextsearch_tesseract` caps at Nextcloud
  32 and is published on the App Store for neither 33 nor 34; its code also calls an API that
  has disappeared from its own dependency, so PDF OCR had already stopped working. Scanned
  documents are therefore out of reach until that app is revived — extraction of scanned pages
  happens upstream, not here.
- Tested up to **50,000 documents**; beyond that, uncharted.
- A `tsvector` keeps only **16,383 positions**: past that, phrase search stops working
  towards the end of very long documents.
- A **compound word is out of reach of an exact phrase**: “comptes-rendus” tokenizes into two
  lexemes that `"compte rendu"` cannot span. Search it without quotes.
- No facets and no configurable sorting: the framework API does not expose them.
- **The indexed content is held twice**: once as text, so excerpts can be built, and once in
  the generated `tsvector`. Expect the index to weigh more than the extracted text alone —
  and more still with several languages configured, at roughly 20% each.

## Contributing

The interface exists in English and French; adding a language takes two files and thirteen
strings. Bug reports, translations and patches are all welcome — see
[CONTRIBUTING.md](.github/CONTRIBUTING.md) for the development setup and the checks to run.

## Licence

This app is distributed under **AGPL-3.0-or-later** (see [`LICENSE`](LICENSE)). Everything
outside `vendor/` is this project's own code and documentation, under that licence.

`vendor/` bundles **[smalot/pdfparser](https://github.com/smalot/pdfparser)** by Sébastien
MALOT, under the GNU Lesser General Public License v3.0 (**LGPL-3.0-only**), used to extract
the text layer from PDF documents. It is included **unmodified**, pinned in `composer.lock`,
and ships with its own licence text in
[`vendor/smalot/pdfparser/LICENSE.txt`](vendor/smalot/pdfparser/LICENSE.txt); anyone remains
free to replace it with another version, as the LGPL-3.0 requires. The LGPL-3.0 permits
bundling a library into a work covered by the AGPL-3.0, and this section is the use notice its
section 4 asks for.
