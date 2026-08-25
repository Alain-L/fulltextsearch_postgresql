<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alain Lesage
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_PostgreSQL\Platform;

use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FullTextSearch_PostgreSQL\Db\FtsRequest;
use OCA\FullTextSearch_PostgreSQL\Exceptions\ConfigurationException;
use OCA\FullTextSearch_PostgreSQL\Model\SearchFilters;
use OCA\FullTextSearch_PostgreSQL\Service\ContentService;
use OCP\DB\Exception as DBException;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchResult;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The PostgreSQL search platform.
 *
 * Registered through appinfo/info.xml (<fulltextsearch><platform>), which is all the
 * framework needs to pick it up.
 */
class PostgresPlatform implements IFullTextSearchPlatform {
	/** How many halvings we try before giving up on an oversized document. */
	private const TSVECTOR_RETRIES = 4;

	private ?IRunner $runner = null;

	public function __construct(
		private FtsRequest $request,
		private ContentService $contentService,
		private LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return 'postgresql';
	}

	public function getName(): string {
		return 'PostgreSQL';
	}

	public function getConfiguration(): array {
		$config = [
			'pdf_engine' => $this->contentService->pdfEngine(),
			'table' => FtsRequest::TABLE,
			'languages' => implode(', ', $this->request->languages()),
			'text_search_configs' => implode(', ', $this->request->tsConfigs()),
			'max_content_size' => FtsRequest::MAX_CONTENT_SIZE,
		];

		try {
			$unaccent = $this->request->hasUnaccent();
			$trigram = $this->request->hasExtension('pg_trgm');
			$config['unaccent'] = $unaccent;
			$config['pg_trgm'] = $trigram;

			// A boolean buried in a JSON dump is not a warning. Say what is lost and how to
			// fix it, because both extensions fail silently: search simply stops finding
			// things, and nothing else reports why.
			$avertissements = [];
			if (!$unaccent) {
				$avertissements[] = 'unaccent is MISSING: searching without accents will not '
					. 'match accented content at all. Have a superuser run '
					. '"CREATE EXTENSION unaccent;" on the Nextcloud database, then '
					. '"occ fulltextsearch:reset" and re-index.';
			}
			if (!$trigram) {
				$avertissements[] = 'pg_trgm is MISSING: partial matching on file and share '
					. 'names still works, but falls back to a full table scan on every search. '
					. 'Have a superuser run "CREATE EXTENSION pg_trgm;" on the Nextcloud '
					. 'database; no re-indexing is needed.';
			}
			if ($avertissements !== []) {
				$config['warnings'] = $avertissements;
			}

			$config += $this->request->stats();
		} catch (Throwable) {
			$config['documents'] = 'n/a (index not initialized)';
		}

		return $config;
	}

	public function setRunner(IRunner $runner) {
		$this->runner = $runner;
	}

	/**
	 * @throws ConfigurationException
	 */
	public function loadPlatform() {
		if (!$this->request->isPostgres()) {
			throw new ConfigurationException(
				'fulltextsearch_postgresql requires Nextcloud to run on PostgreSQL'
			);
		}
	}

	public function testPlatform(): bool {
		$this->loadPlatform();

		return $this->request->ping();
	}

	public function initializeIndex() {
		$this->loadPlatform();
		$this->request->initSchema();
	}

	public function resetIndex(string $providerId) {
		$this->request->reset($providerId);
	}

	public function deleteIndexes(array $indexes) {
		/** @var IIndex $index */
		foreach ($indexes as $index) {
			try {
				$this->request->delete($index->getProviderId(), $index->getDocumentId());
				$this->updateRunnerResult($index, 'index deleted', 'success', IRunner::RESULT_TYPE_SUCCESS);
			} catch (Throwable $t) {
				$this->logger->warning('could not delete index', ['exception' => $t]);
				$this->updateRunnerResult($index, 'index not deleted', 'fail', IRunner::RESULT_TYPE_WARNING);
			}
		}
	}

	public function indexDocument(IIndexDocument $document): IIndex {
		$document->initHash();
		$index = $document->getIndex();

		// The framework routes deletions through indexDocument() too — a document whose file
		// is gone comes back flagged INDEX_REMOVE instead of through deleteIndexes().
		if ($index->isStatus(IIndex::INDEX_REMOVE)) {
			return $this->removeDocument($index);
		}

		try {
			$this->indexWithinTsvectorLimit($document, $index);

			$index->setLastIndex();
			if ($index->getErrorCount() === 0) {
				$index->setStatus(IIndex::INDEX_DONE);
			}

			$this->updateRunnerResult($index, 'document indexed', 'ok', IRunner::RESULT_TYPE_SUCCESS);
		} catch (DBException $e) {
			// An unreachable database is not a faulty document: without that distinction, an
			// outage would mark the whole corpus as permanently failed.
			$this->rethrowIfTemporary($e);
			$this->failIndex($index, $e);
		} catch (Throwable $t) {
			$this->failIndex($index, $t);
		}

		return $index;
	}

	public function searchRequest(ISearchResult $result, IDocumentAccess $access) {
		$request = $result->getRequest();
		$query = trim($request->getSearch());
		if ($query === '') {
			return;
		}

		$size = max(1, $request->getSize());
		$offset = max(0, ($request->getPage() - 1) * $size);
		$start = microtime(true);

		try {
			$rows = $this->request->search(
				$result->getProvider()->getId(),
				$query,
				$access->getViewerId(),
				$access->getGroups(),
				$access->getCircles(),
				SearchFilters::fromRequest($request),
				$size,
				$offset
			);
		} catch (DBException $e) {
			// Deliberately no PlatformTemporaryException here: the framework only handles it
			// on the indexing path. Throwing it during a search would produce a server error
			// instead of a message. So we let the original exception through.
			$this->logger->warning(
				'fulltextsearch_postgresql: search failed',
				['exception' => $e, 'query' => $query]
			);

			throw $e;
		}

		$total = 0;
		foreach ($rows as $row) {
			$total = (int)$row['total'];
			$result->addDocument($this->toSearchDocument($row, $access->getViewerId()));
		}

		// A page past the last result returns no rows, and the total travels in the rows:
		// without this fallback, the interface would conclude "no results".
		if ($rows === [] && $offset > 0) {
			$total = $this->request->count(
				$result->getProvider()->getId(),
				$query,
				$access->getViewerId(),
				$access->getGroups(),
				$access->getCircles(),
				SearchFilters::fromRequest($request)
			);
		}

		$result->setTotal($total);
		$result->setMaxScore((int)round((float)($rows[0]['max_score'] ?? 0) * 100));
		$result->setTime((int)round((microtime(true) - $start) * 1000));
		$result->setTimedOut(false);
	}

	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		$row = $this->request->get($providerId, $documentId);

		$document = new IndexDocument($providerId, $documentId);
		if ($row === null) {
			// getAccess() is typed non-nullable: without this call, any read of the returned
			// document would raise a fatal error instead of just an empty document.
			$document->setAccess(new DocumentAccess());

			return $document;
		}

		$access = new DocumentAccess((string)$row['owner_id']);
		$access->setUsers($this->jsonArray($row['acl_users']));
		$access->setGroups($this->jsonArray($row['acl_groups']));
		$access->setCircles($this->jsonArray($row['acl_circles']));

		$document->setAccess($access);
		$document->setSource((string)$row['source']);
		$document->setTitle((string)$row['title']);
		$document->setContent((string)$row['content']);
		$document->setHash((string)$row['hash']);
		$document->setModifiedTime((int)$row['modified_at']);
		$access->setLinks($this->jsonArray($row['links']));
		$document->setMetaTags($this->jsonArray($row['metatags']));
		$document->setSubTags($this->jsonArray($row['subtags']));
		$document->setTags($this->jsonArray($row['tags']));
		$document->setParts((array)json_decode((string)$row['parts'], true));

		foreach ((array)json_decode((string)$row['info'], true) as $cle => $valeur) {
			if (is_array($valeur)) {
				$document->setInfoArray((string)$cle, $valeur);
			} elseif (is_bool($valeur)) {
				$document->setInfoBool((string)$cle, $valeur);
			} elseif (is_int($valeur)) {
				$document->setInfoInt((string)$cle, $valeur);
			} else {
				$document->setInfo((string)$cle, (string)$valeur);
			}
		}

		return $document;
	}

	/**
	 * Inserts the document, shrinking its content when the `tsvector` refuses to hold it.
	 *
	 * PostgreSQL rejects any `tsvector` over 1 MiB, and the size it produces depends on the
	 * text: there is no predicting it from the length alone. Rather than a low cap that
	 * truncates every document just in case, we try the whole thing and shrink only those
	 * that really overflow — and say so in the log.
	 */
	private function indexWithinTsvectorLimit(IIndexDocument $document, IIndex $index): void {
		$row = $this->toRow($document);
		$avecContenu = $index->isStatus(IIndex::INDEX_CONTENT);
		$entier = strlen((string)$row['content']);

		for ($essai = 0; $essai < self::TSVECTOR_RETRIES; $essai++) {
			try {
				$this->request->upsert($row, $avecContenu);

				if ($essai > 0) {
					$this->logger->warning(
						'fulltextsearch_postgresql: content truncated to fit the 1 MiB tsvector limit',
						[
							'title' => $document->getTitle(),
							'kept' => strlen((string)$row['content']),
							'original' => $entier,
						]
					);
				}

				return;
			} catch (DBException $e) {
				$this->rethrowIfTemporary($e);

				if (!$this->isTsvectorTooLarge($e) || $essai === self::TSVECTOR_RETRIES - 1) {
					throw $e;
				}

				// Halved on each pass: four attempts cover a factor of 8.
				$row['content'] = mb_strcut((string)$row['content'], 0, intdiv(strlen((string)$row['content']), 2), 'UTF-8');
				$row['partsText'] = mb_strcut((string)$row['partsText'], 0, intdiv(strlen((string)$row['partsText']), 2), 'UTF-8');
			}
		}
	}

	/**
	 * PostgreSQL reports this overflow with a program-limit code and an explicit message; the
	 * code alone would cover other limits too, hence the double check.
	 */
	private function isTsvectorTooLarge(DBException $e): bool {
		return str_contains($e->getMessage(), 'too long for tsvector')
			|| str_contains($e->getMessage(), 'string is too long');
	}

	private function removeDocument(IIndex $index): IIndex {
		try {
			$this->request->delete($index->getProviderId(), $index->getDocumentId());

			$index->setLastIndex();
			$index->setStatus(IIndex::INDEX_DONE);
			$this->updateRunnerResult($index, 'document removed', 'ok', IRunner::RESULT_TYPE_SUCCESS);
		} catch (Throwable $t) {
			$this->logger->warning('could not remove document', ['exception' => $t]);

			$index->setStatus(IIndex::INDEX_FAILED);
			$index->addError($t->getMessage(), get_class($t), IIndex::ERROR_SEV_3);
			$this->updateRunnerResult($index, 'document not removed', 'fail', IRunner::RESULT_TYPE_FAIL);
		}

		return $index;
	}

	/**
	 * Serialises to JSON without ever returning `false`.
	 *
	 * `json_encode()` fails on malformed UTF-8 — which happens with tags or user names coming
	 * from elsewhere. That `false` ended up as an empty string, which PostgreSQL rejects as
	 * `jsonb`: the document was then marked permanently failed over one damaged character.
	 * Invalid sequences are substituted rather than fatal.
	 */
	private function toJson(mixed $valeur): string {
		$json = json_encode($valeur, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);

		return $json === false ? '[]' : $json;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function toRow(IIndexDocument $document): array {
		$access = $document->getAccess();

		return [
			'providerId' => $document->getProviderId(),
			'documentId' => $document->getId(),
			'ownerId' => $access->getOwnerId(),
			'aclUsers' => $this->toJson(array_values($access->getUsers())),
			'aclGroups' => $this->toJson(array_values($access->getGroups())),
			'aclCircles' => $this->toJson(array_values($access->getCircles())),
			'source' => $document->getSource(),
			'title' => $document->getTitle(),
			'content' => $this->contentService->extract($document),
			'parts' => $this->toJson($document->getParts()),
			'partsText' => $this->contentService->extractParts($document),
			'metatags' => $this->toJson(array_values($document->getMetaTags())),
			'subtags' => $this->toJson(array_values($document->getSubTags(true))),
			'tags' => $this->toJson(array_values($document->getTags())),
			'links' => $this->toJson(array_values($access->getLinks())),
			'info' => $this->toJson($document->getInfoAll()),
			// The name each recipient sees the file under: it differs from the owner's path,
			// and that is the one the Files provider queries.
			'shareNames' => $this->toJson($document->getInfoArray('share_names', [])),
			'shareText' => implode(' ', array_map(
				static fn ($nom): string => is_string($nom) ? $nom : '',
				$document->getInfoArray('share_names', [])
			)),
			'hash' => $document->getHash(),
			'modifiedAt' => $document->getModifiedTime(),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function toSearchDocument(array $row, string $viewerId): IIndexDocument {
		$access = new DocumentAccess();
		$access->setViewerId($viewerId);

		$document = new IndexDocument((string)$row['provider_id'], (string)$row['document_id']);
		$document->setAccess($access);
		$document->setScore((string)$row['score']);
		$document->setSource((string)$row['source']);
		$document->setTitle((string)$row['title']);
		$document->setHash((string)$row['hash']);
		$document->setModifiedTime((int)$row['modified_at']);

		$excerpts = [];
		foreach (['content' => 'excerpt', 'parts' => 'excerpt_parts'] as $source => $column) {
			$excerpt = trim((string)($row[$column] ?? ''));
			if ($excerpt !== '') {
				$excerpts[] = ['source' => $source, 'excerpt' => $excerpt];
			}
		}
		$document->setExcerpts($excerpts);

		return $document;
	}

	/**
	 * @return string[]
	 */
	private function jsonArray(mixed $value): array {
		$decoded = json_decode((string)$value, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function failIndex(IIndex $index, Throwable $t): void {
		$this->logger->warning('could not index document', ['exception' => $t]);

		$index->setStatus(IIndex::INDEX_FAILED);
		$index->addError($t->getMessage(), get_class($t), IIndex::ERROR_SEV_3);
		$this->updateRunnerResult($index, 'document not indexed', 'fail', IRunner::RESULT_TYPE_FAIL);
	}

	/**
	 * Tells the framework this is a transient failure, so it resumes later instead of
	 * condemning the documents.
	 *
	 * The class moved around across Nextcloud versions: OCA up to 33, OCP afterwards. We
	 * throw whichever one exists.
	 */
	private function rethrowIfTemporary(DBException $e): void {
		$passagere = [
			DBException::REASON_CONNECTION_LOST,
			DBException::REASON_SERVER,
			DBException::REASON_DEADLOCK,
			DBException::REASON_LOCK_WAIT_TIMEOUT,
		];

		if (!in_array($e->getReason(), $passagere, true)) {
			return;
		}

		foreach ([
			'OCP\\FullTextSearch\\Exceptions\\PlatformTemporaryException',
			'OCA\\FullTextSearch\\Exceptions\\PlatformTemporaryException',
		] as $class) {
			if (class_exists($class)) {
				throw new $class($e->getMessage(), 0, $e);
			}
		}
	}

	private function updateRunnerResult(IIndex $index, string $message, string $status, int $type): void {
		$this->runner?->newIndexResult($index, $message, $status, $type);
	}
}
