<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Invoice> */
class InvoiceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_invoices', Invoice::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id, string $userId): Invoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * O ATCUD identifica univocamente um documento fiscal portugues. E com ele
	 * que a importacao fica idempotente: reimportar o mesmo PDF reconhece o que
	 * ja la esta em vez de duplicar.
	 */
	public function findByAtcud(string $atcud, string $userId): ?Invoice {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('atcud', $qb->createNamedParameter($atcud)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/** @return Invoice[] */
	public function findAll(string $userId, ?int $meterId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		if ($meterId !== null) {
			$qb->andWhere($qb->expr()->eq('meter_id', $qb->createNamedParameter($meterId, IQueryBuilder::PARAM_INT)));
		}

		$qb->orderBy('period_from', 'ASC')->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}
}
