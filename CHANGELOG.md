# Changelog

This format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.0] — 2026-08-25

First usable release. Tested on Nextcloud 33 / PostgreSQL 18 against a real corpus of
varied formats.

### Added

- Complete `IFullTextSearchPlatform` implementation: indexing, searching, deletion, reset,
  document retrieval.
- Language-aware full-text search: a real PostgreSQL text search configuration plus the
  `unaccent` dictionary, GIN indexes, `ts_rank` for ranking, `ts_headline` for excerpts.
- **Dual indexing** with the language configuration *and* an accent-folding variant: stemming
  stays correct while missing accents are tolerated, so “numerisation” finds “numérisation”.
- Access control translated into SQL: owner, users, `__all`, groups, circles.
- Text extraction for **PDF** — `pdftotext` (poppler) when installed, `smalot/pdfparser` as a
  fallback — and **Office** — `docx`, `xlsx`, `pptx`, `odt`, `ods`, `odp`, `odg` — by reading
  the XML inside the archive directly.
- The search filters the Files content provider actually issues: partial matching on names,
  `metatags`, regex filters (extension), `limitFields` (`in:`), `subtags`, time filter.
- Prefix search (`sear` finds “searching”).
- Broad recall, precise ranking: terms are matched with `OR`, and documents carrying every
  term are ranked first.
- Graceful degradation when the `unaccent` extension cannot be installed.
- Failure recovery: an unreachable database is reported as a transient incident, not as a
  faulty document.
- Trigram GIN index (`pg_trgm`) on the title, without which partial matching forces every
  query into a full table scan.
- Highlighting applied after the page has been cut: on 50,000 documents with a frequent term,
  41 ms instead of 189.
- **Administration panel** in the “Full text search” section, offering the languages actually
  installed on the PostgreSQL server. No JavaScript build step.
- **French translation** of the interface and of the manifest.
- By default the indexing language **follows the Nextcloud instance** (`auto`); a language
  with no PostgreSQL equivalent falls back to English.
- `search_language` setting: any PostgreSQL text search configuration, and several combined
  for a multilingual corpus (`french,english`), at a cost of roughly 20% of index size per
  language. An unknown value is reported and ignored; beyond three languages the app warns
  without forbidding anything.
- Versioned schema — the language is part of the version — rebuilt automatically when it
  changes.
- Extraction tests (`tests/`).
- `make check` also reports known vulnerability advisories for the bundled dependency, and
  refuses to pass when a git tag disagrees with the version in the manifest.

### Licensing

- The app is AGPL-3.0-or-later; `smalot/pdfparser` remains under LGPL-3.0, with the use
  notice its section 4 requires (see the Licence section of the README).

### Fixed before release

Three independent reviews were run over the code, the shape of the repository and App Store
compliance. What they found, and what has been fixed:

- **Changing the language from the panel took search down.** The derived configuration only
  existed after reindexing, and every query failed until then. It is now created when the
  setting is saved, and search falls back to the native configuration if it is still missing.
- **Content beyond 512 KiB was silently dropped**, in the name of a misread `tsvector` limit:
  the real headroom is ten times larger on ordinary text. The ceiling is now 4 MiB, and a
  document that still exceeds it is halved and retried — with a log entry — instead of being
  truncated outright.
- **Spreadsheets were indexed from their sheet XML**, formulas and row numbers included: one
  file went from 81,000 to 2,200 characters once only the strings were kept, which cuts index
  size substantially.
- **The prefix wildcard applied to three-letter words**: “the” matched *theatr*, *their*,
  *them*… The share of results actually carrying the searched terms rose from 68% to 75%.
- **`occ fulltextsearch:reset` did nothing** after the app was renamed: the existence check
  targeted a table that did not exist.
- **`min-version` promised Nextcloud 31**, where the app cannot start — the configuration
  lexicon only exists from 32 onwards. Range corrected and tested on 32, 33 and 34.
- **Pagination**: the total dropped to zero past the last page, and the maximum score was
  computed over the page rather than the whole result set.
- **`getDocument()` on an unknown identifier** raised a fatal error; the provider's free-form
  fields were not returned.
- **Extractor robustness**: the call to `pdftotext` could deadlock on a large file and its
  timeout was ineffective; archive reading had no decompression ceiling.
- **Share names are indexed and filterable.** A share recipient sees a path that is not the
  owner's; the Files provider queries that name for partial matching, field restriction and
  the “within this folder” filter. That last one therefore had no effect for a recipient.
- **JSON serialisation can no longer condemn a document.** A malformed UTF-8 character in a
  tag made `json_encode()` fail, leaving the document permanently in error.
- **Partial matching on share names is indexable again.** The name depends on who is
  searching, so it cannot be indexed in advance; placed as-is in the query's `OR`, it forced
  the planner into a full scan and cancelled the benefit of the title index along the way. A
  broader but indexable condition now sits in front of it. Measured on 200,000 documents:
  87 ms before, 0.2 ms after, with identical results; no visible effect on a small corpus.
- **Search no longer raises `PlatformTemporaryException`**: the framework only handles it
  during indexing, so a database outage during a query produced a server error instead of a
  message.

### Fixed after a cold-install review

Three fresh installations, each following the documentation from scratch, found the
following:

- **Quotation marks now bind.** A phrase query was relaxed into a conjunction before
  filtering, so `"compte rendu"` returned documents where the two words merely appeared
  apart — quotes did nothing at all. The filter now uses the strict form when the query holds
  a phrase, while the ranking keeps the permissive one. This is what made
  `occ fulltextsearch:test` fail.
- **`+term` is honoured.** PostgreSQL parses `+test` exactly like `test`, so a mandatory term
  behaved like an ordinary one. The raw query is now read before conversion.
- **An exclusion in first position no longer inverts the query.** Clauses are sorted by
  nature instead of split at the first exclusion, so `-word rest…` no longer read as “or not
  word”.
- **`occ fulltextsearch:test` passes in full**, including group and share permissions.
- **No more inert derived configuration.** Without `unaccent`, the derived text search
  configuration was created anyway as a byte-for-byte clone of the native one — and both were
  emitted, storing every lexeme twice for no benefit and halving the usable `tsvector`
  positions.
- **`occ fulltextsearch:check` warns properly.** Missing extensions were two `false` booleans
  buried in a JSON dump; it now states what is lost and how to fix it. A missing `pg_trgm` is
  logged too, as `unaccent` already was.
- **A mutilated index table is rebuilt.** Dropping a text search configuration with `CASCADE`
  takes the generated column with it; the table survived without the one column everything
  depends on, and nothing rebuilt it.

### Known limitations

- The administration panel exposes only the language: it is the only setting there is.
- Simple queries (`ISearchRequestSimpleQuery`), additional fields and `wildcardFilters` are
  not implemented — the Files content provider does not use them.
- Writing systems without spaces are not segmented: Chinese or Japanese content becomes a
  single lexeme and stays unfindable. No setting changes this.
- In a multilingual corpus, excerpts are cut using the dictionary of the **first** configured
  language: the text stays correct, only the choice of fragment may be less apt.
- Scanned PDFs depend on OCR upstream, which is currently broken
  (`files_fulltextsearch_tesseract` calls an API of `spatie/pdf-to-image` that no longer
  exists).
