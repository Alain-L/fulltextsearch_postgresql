#!/bin/sh
# SPDX-FileCopyrightText: 2026 Alain Lesage
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Exercises the PDF and Office extractors against generated fixtures.
# Replay it after every `composer update`: this is the safety net upstream was missing.
#
# The Nextcloud image doubles as the PHP test bench: it ships ext-zip and ext-mbstring,
# which php:*-cli does not have. The test itself does not depend on Nextcloud.
#
# poppler-utils is added on the fly: without it, both passes of the PDF test fall back to
# pdfparser and the pdftotext branch — the one driving an external process — is never
# exercised.
set -eu
cd "$(dirname "$0")/.."
exec docker run --rm --entrypoint sh \
	-v "$PWD":/app -w /app "${PHP_IMAGE:-nextcloud:33-apache}" -c \
	'command -v pdftotext >/dev/null 2>&1 || { apt-get update -qq && \
		apt-get install -y -qq --no-install-recommends poppler-utils; } >/dev/null 2>&1
	 exec php tests/extraction/smoke.php'
