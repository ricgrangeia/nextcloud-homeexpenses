<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Reading> */
class ReadingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_readings', Reading::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Reading {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Por data da LEITURA (a que esta no contador), nao por ordem de insercao
	 * -- lancar hoje uma leitura de ha um mes tem de a colocar no sitio certo
	 * da serie, senao o consumo entre leituras fica errado.
	 *
	 * @return Reading[]
	 */
	public function findAllForMeter(int $meterId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('meter_id', $qb->createNamedParameter($meterId, IQueryBuilder::PARAM_INT)))
			->orderBy('read_at', 'ASC')
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
