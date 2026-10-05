<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Meter> */
class MeterMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_meters', Meter::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id, string $userId): Meter {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/** @return Meter[] */
	public function findAllForUser(string $userId, bool $includeArchived = false, ?string $kind = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		if (!$includeArchived) {
			$qb->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));
		}
		if ($kind !== null) {
			$qb->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		}

		$qb->orderBy('kind', 'ASC')->addOrderBy('name', 'ASC');

		return $this->findEntities($qb);
	}
}
