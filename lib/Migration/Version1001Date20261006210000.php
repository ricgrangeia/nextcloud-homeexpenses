<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

#[CreateTable(table: 'hex_invoices', description: 'A fiscal document read from a supplier invoice PDF, identified by its ATCUD')]
#[CreateTable(table: 'hex_invoice_lines', description: 'One charge line of an invoice, classified by kind and register')]
class Version1001Date20261006210000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('hex_invoices')) {
			$table = $schema->createTable('hex_invoices');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Opcional: uma fatura pode chegar antes de se saber a que contador
			// pertence, e ha documentos (a Contribuicao Audiovisual) que nao
			// pertencem a contador nenhum.
			$table->addColumn('meter_id', Types::BIGINT, ['notnull' => false, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('supplier', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('doc_type', Types::STRING, ['notnull' => false, 'length' => 8]);
			$table->addColumn('doc_number', Types::STRING, ['notnull' => false, 'length' => 128]);
			// O ATCUD identifica univocamente um documento fiscal portugues.
			// E a chave que torna a importacao idempotente: reimportar o mesmo
			// PDF nao duplica nada.
			$table->addColumn('atcud', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('issued_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('period_from', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('period_to', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('currency', Types::STRING, ['notnull' => true, 'length' => 3, 'default' => 'EUR']);
			$table->addColumn('total_net', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('total_vat', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('total_gross', Types::FLOAT, ['notnull' => false]);
			// Falso quando as contas do proprio documento nao fecham. Guarda-se
			// na mesma, mas nao entra em calculos: apagar o que nao se entende
			// e pior do que guardar com uma marca.
			$table->addColumn('verified', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('source_name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'hex_inv_uid_idx');
			$table->addIndex(['meter_id'], 'hex_inv_meter_idx');
			$table->addIndex(['period_from'], 'hex_inv_from_idx');
			$table->addUniqueIndex(['user_id', 'atcud'], 'hex_inv_atcud_uniq');
		}

		if (!$schema->hasTable('hex_invoice_lines')) {
			$table = $schema->createTable('hex_invoice_lines');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('invoice_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('description', Types::STRING, ['notnull' => true, 'length' => 255]);
			// energy | power | network | levy | other
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('register_code', Types::STRING, ['notnull' => false, 'length' => 8]);
			$table->addColumn('is_estimate', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			// O intervalo da linha, que pode ser mais curto do que o da fatura:
			// uma mudanca de tarifario a meio do mes poe dois intervalos na
			// mesma fatura.
			$table->addColumn('period_from', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('period_to', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('quantity', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('unit_price', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('discount', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('vat_rate', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('total_net', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('page', Types::INTEGER, ['notnull' => true, 'default' => 0]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['invoice_id'], 'hex_invline_inv_idx');
			$table->addIndex(['register_code'], 'hex_invline_reg_idx');
		}

		return $schema;
	}
}
