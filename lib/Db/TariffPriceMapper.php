<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<TariffPrice> */
class TariffPriceMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_tariff_prices', TariffPrice::class);
	}

	/** @return TariffPrice[] */
	public function findAllForTariff(int $tariffId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('tariff_id', $qb->createNamedParameter($tariffId, IQueryBuilder::PARAM_INT)))
			->orderBy('register_code', 'ASC');

		return $this->findEntities($qb);
	}

	public function deleteAllForTariff(int $tariffId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('tariff_id', $qb->createNamedParameter($tariffId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
