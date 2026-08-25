# SPDX-FileCopyrightText: 2026 Alain Lesage
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name := fulltextsearch_postgresql
version  := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir := build

.PHONY: help
help:
	@echo "  make check     manifest, licences, PHP syntax, dependency advisories"
	@echo "  make test      run the extraction tests (PDF and Office)"
	@echo "  make appstore  build the distributable archive into $(build_dir)/"
	@echo "  make clean     wipe $(build_dir)/"

.PHONY: check
check: check-xml check-php check-licenses check-deps check-version

.PHONY: check-xml
check-xml:
	@echo "→ manifest against the official schema"
	@mkdir -p $(build_dir)/xml && cp appinfo/info.xml $(build_dir)/xml/
	@# The schema is cached: apps.nextcloud.com answers 429 if it gets re-downloaded too often.
	@test -s .info.xsd || curl -sSL -o .info.xsd https://apps.nextcloud.com/schema/apps/info.xsd
	@cp .info.xsd $(build_dir)/xml/info.xsd
	@docker run --rm -v "$(CURDIR)/$(build_dir)/xml":/w -w /w alpine sh -c \
		'apk add --no-cache libxml2-utils >/dev/null 2>&1; xmllint --noout --schema info.xsd info.xml'

.PHONY: check-php
check-php:
	@echo "→ PHP syntax"
	@docker run --rm -v "$(CURDIR)":/app -w /app php:8.3-cli \
		sh -c 'find lib tests -name "*.php" -exec php -l {} \; | grep -v "No syntax errors" || true'

.PHONY: check-licenses
check-licenses:
	@echo "→ REUSE compliance"
	@docker run --rm -v "$(CURDIR)":/data -w /data python:3.12-alpine sh -c \
		'apk add --no-cache git >/dev/null 2>&1; git config --global --add safe.directory /data; \
		 pip install --quiet "reuse[charset-normalizer]" && reuse lint' | tail -3

.PHONY: check-deps
check-deps:
	@echo "→ known vulnerabilities in dependencies"
	@docker run --rm -v "$(CURDIR)":/app -w /app composer:2 audit --no-interaction 2>&1 \
		| grep -v '^$$' | tail -2

# The manifest is what the App Store reads; a tag that disagrees with it ships a version
# nobody asked for. Only checked when a tag points at HEAD, so day-to-day work is unaffected.
.PHONY: check-version
check-version:
	@manifest=$$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' appinfo/info.xml); \
	 tag=$$(git tag --points-at HEAD | head -1); \
	 if [ -n "$$tag" ] && [ "$$tag" != "v$$manifest" ]; then \
		echo "→ tag $$tag disagrees with manifest version $$manifest"; exit 1; \
	 else echo "→ version $$manifest$${tag:+ (tagged $$tag)}"; fi

.PHONY: test
test:
	@./tests/run-extraction.sh | tail -1

# The archive the App Store expects: a single folder named after the app, without
# the development tooling (see .gitattributes).
.PHONY: appstore
appstore: clean check
	@mkdir -p $(build_dir)
	@git archive --format=tar --prefix=$(app_name)/ HEAD \
		| gzip > $(build_dir)/$(app_name)-$(version).tar.gz
	@echo "→ $(build_dir)/$(app_name)-$(version).tar.gz  ($$(du -h $(build_dir)/$(app_name)-$(version).tar.gz | cut -f1))"
	@tar tzf $(build_dir)/$(app_name)-$(version).tar.gz | sed 's|^$(app_name)/||' \
		| awk -F/ 'NF>1{print $$1"/"} NF==1{print}' | sort -u | sed 's/^/     /'

.PHONY: clean
clean:
	@rm -rf $(build_dir)
