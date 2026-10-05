<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ReadingValue> */
class ReadingValueMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_reading_values', ReadingValue::class);
	}

	/** @return ReadingValue[] */
	public function findAllForReading(int $readingId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('reading_id', $qb->createNamedParameter($readingId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * Todos os valores de um conjunto de leituras numa unica query -- evita o
	 * N+1 ao montar a serie historica de um contador.
	 *
	 * @param int[] $readingIds
	 * @return ReadingValue[]
	 */
	public function findAllForReadings(array $readingIds): array {
		if ($readingIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('reading_id', $qb->createNamedParameter($readingIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->findEntities($qb);
	}

	public function deleteAllForReading(int $readingId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('reading_id', $qb->createNamedParameter($readingId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** @param int[] $readingIds */
	public function deleteAllForReadings(array $readingIds): void {
		if ($readingIds === []) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('reading_id', $qb->createNamedParameter($readingIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$qb->executeStatement();
	}
}
