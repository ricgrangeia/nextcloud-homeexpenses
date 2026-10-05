<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<MeterRegister> */
class MeterRegisterMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_registers', MeterRegister::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): MeterRegister {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @return MeterRegister[] */
	public function findAllForMeter(int $meterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('meter_id', $qb->createNamedParameter($meterId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function deleteAllForMeter(int $meterId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('meter_id', $qb->createNamedParameter($meterId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
