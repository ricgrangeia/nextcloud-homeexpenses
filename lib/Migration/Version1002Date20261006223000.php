<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\AddColumn;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * O esquema v1 do extractor traz, ao nivel do ficheiro, a referencia e a
 * data-limite de pagamento. Sao do ficheiro e nao do documento -- um PDF com
 * duas faturas tem um so pagamento -- mas guardam-se em cada documento porque
 * e dai que se consultam.
 */
#[AddColumn(table: 'hex_invoices', name: 'due_date', description: 'Payment due date, read from the payment QR of the file')]
#[AddColumn(table: 'hex_invoices', name: 'payment_reference', description: 'Payment reference for the whole file')]
class Version1002Date20261006223000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('hex_invoices')) {
			return null;
		}

		$table = $schema->getTable('hex_invoices');

		if (!$table->hasColumn('due_date')) {
			$table->addColumn('due_date', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addIndex(['due_date'], 'hex_inv_due_idx');
		}

		if (!$table->hasColumn('payment_reference')) {
			$table->addColumn('payment_reference', Types::STRING, ['notnull' => false, 'length' => 64]);
		}

		return $schema;
	}
}
