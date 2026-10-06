<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ImportJob> */
class ImportJobMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_import_jobs', ImportJob::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): ImportJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/** @return ImportJob[] */
	public function findAllForUser(string $userId, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * Os que ainda esperam, mais antigos primeiro.
	 *
	 * Inclui os que ficaram em `running`: um trabalho de fundo que morra a
	 * meio -- o cron e morto, o servidor reinicia -- deixaria a fatura presa
	 * nesse estado para sempre. Voltam a entrar na fila, e o limite de
	 * tentativas trava o que nunca vai passar.
	 *
	 * @return ImportJob[]
	 */
	public function findPending(int $limit = 5): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(
				[ImportJob::PENDING, ImportJob::RUNNING],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter(
				ImportJob::MAX_ATTEMPTS, IQueryBuilder::PARAM_INT
			)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}
}
