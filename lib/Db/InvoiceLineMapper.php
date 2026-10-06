<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<InvoiceLine> */
class InvoiceLineMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'hex_invoice_lines', InvoiceLine::class);
	}

	/** @return InvoiceLine[] */
	public function findAllForInvoice(int $invoiceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)))
			->orderBy('page', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @param int[] $invoiceIds
	 * @return array<int, InvoiceLine[]> indexado por invoice_id
	 */
	public function findAllForInvoices(array $invoiceIds): array {
		if ($invoiceIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('invoice_id', $qb->createNamedParameter($invoiceIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('page', 'ASC')
			->addOrderBy('id', 'ASC');

		$grouped = [];
		foreach ($this->findEntities($qb) as $line) {
			$grouped[$line->getInvoiceId()][] = $line;
		}

		return $grouped;
	}

	public function deleteForInvoice(int $invoiceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('invoice_id', $qb->createNamedParameter($invoiceId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
