<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<BottleType> */
class BottleTypeMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_bottle_types', BottleType::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id, string $userId): BottleType {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/** @return BottleType[] */
	public function findAllForUser(string $userId, bool $includeArchived = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		if (!$includeArchived) {
			$qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
		}

		$qb->orderBy('brand', 'ASC')->addOrderBy('nominal_kg', 'ASC');

		return $this->findEntities($qb);
	}
}
