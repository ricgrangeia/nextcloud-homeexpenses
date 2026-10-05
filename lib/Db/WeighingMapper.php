<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Weighing> */
class WeighingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_weighings', Weighing::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Weighing {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @return Weighing[] */
	public function findAllForCycle(int $cycleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('cycle_id', $qb->createNamedParameter($cycleId, IQueryBuilder::PARAM_INT)))
			->orderBy('weighed_at', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function deleteAllForCycle(int $cycleId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('cycle_id', $qb->createNamedParameter($cycleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
