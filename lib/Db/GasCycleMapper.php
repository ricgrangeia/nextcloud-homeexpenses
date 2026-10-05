<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<GasCycle> */
class GasCycleMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_gas_cycles', GasCycle::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id, string $userId): GasCycle {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/** @return GasCycle[] */
	public function findAllForUser(string $userId, ?string $appliance = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		if ($appliance !== null) {
			$qb->andWhere($qb->expr()->eq('appliance', $qb->createNamedParameter($appliance)));
		}

		$qb->orderBy('installed_at', 'DESC')->addOrderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Garrafas ainda em uso -- as que entraram e ainda nao acabaram.
	 *
	 * @return GasCycle[]
	 */
	public function findOpenForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNull('removed_at'))
			->orderBy('installed_at', 'DESC');

		return $this->findEntities($qb);
	}

	/** @return GasCycle[] */
	public function findAllForBottleType(int $bottleTypeId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('bottle_type_id', $qb->createNamedParameter($bottleTypeId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}
}
