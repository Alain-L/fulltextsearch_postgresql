<!--
SPDX-FileCopyrightText: 2026 Alain Lesage
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Contributing to fulltextsearch_postgresql

Thank you for considering a contribution. This app is a Nextcloud search platform
backed by PostgreSQL, so most changes touch either SQL or the Nextcloud platform
interface — both are covered below.

## Code of Conduct

Please review [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) to understand the expected
behavior within this community.

## Getting a development setup

Any Nextcloud instance on PostgreSQL will do. Enable the app, select the PostgreSQL
platform, and index a folder of your own documents:

```sh
occ app:enable fulltextsearch_postgresql
occ fulltextsearch:check
occ fulltextsearch:index
```

Bear in mind that `occ fulltextsearch:reset` is destructive and asks for confirmation
twice — it drops the whole index and forces a full re-extraction.

## Before opening a pull request

```sh
make check   # manifest schema, REUSE compliance, PHP syntax
make test    # PDF and Office extraction
```

Both run in containers; no local PHP or PostgreSQL is required.

## Writing code

- **Keep it consistent with what is already there.** The SQL lives in
  `lib/Db/FtsRequest.php` and nowhere else; the platform interface lives in
  `lib/Platform/PostgresPlatform.php`.
- **Explain the why, not the what.** Comments in this codebase exist to record
  decisions — why a query is shaped a certain way, why a fallback exists. Several
  of them cite measurements; if you change the behaviour, update the measurement.
- **Measure performance claims.** Query plans and timings, not intuition: a claim
  without a number attached will be asked for one. Several comments in the SQL cite
  the measurement that justifies the shape of a query — if you change the behaviour,
  update the measurement.
- **Every file needs an SPDX header.** `make check` enforces REUSE 3.3 compliance
  and will fail otherwise.

Comments and documentation are written in French, identifiers and user-facing
strings in English. That split is deliberate; please keep it.

## Translating

The interface exists in English and French. Adding a language needs no tooling and no
compilation: two files, thirteen strings.

```
l10n/de.json    {"translations": {"Save": "Speichern", …}, "pluralForm": "nplurals=2; plural=(n != 1);"}
l10n/de.js      OC.L10N.register("fulltextsearch_postgresql", { … }, "nplurals=2; plural=(n != 1);");
```

Keys are the English strings exactly as they appear in `templates/settings-admin.php`;
`l10n/fr.json` serves as the template. Translations are welcome — preferably from native
speakers: English beats an approximate translation.

The manifest accepts localised variants too, which the App Store displays:

```xml
<name lang="de">Volltextsuche - PostgreSQL-Plattform</name>
```

## Reporting a bug

Please include your Nextcloud version, PostgreSQL version, and the relevant lines
from `nextcloud.log`. If the issue concerns search results, the query and what you
expected to find are more useful than a screenshot.
