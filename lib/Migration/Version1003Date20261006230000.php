<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fila de importacao de faturas.
 *
 * Ler um PDF demora ate um minuto, o que e demais para um pedido web: prende
 * o separador, e quem sair perde o resultado. Passa a ficar em fila, tratada
 * por um trabalho de fundo, com o resultado a chegar pelas notificacoes do
 * Nextcloud.
 */
#[CreateTable(table: 'hex_import_jobs', description: 'Queue of invoice PDFs waiting to be read in the background')]
class Version1003Date20261006230000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('hex_import_jobs')) {
			return $schema;
		}

		$table = $schema->createTable('hex_import_jobs');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('meter_id', Types::BIGINT, ['notnull' => false, 'length' => 20, 'unsigned' => true]);
		$table->addColumn('filename', Types::STRING, ['notnull' => true, 'length' => 255]);
		// O PDF vive no appdata, nao aqui: um BLOB por fatura engordaria a
		// base de dados e os backups dela sem necessidade nenhuma.
		$table->addColumn('storage_name', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('size', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);
		// pending | running | done | failed
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('error', Types::TEXT, ['notnull' => false]);
		// Resumo do que a importacao produziu: quantos documentos entraram,
		// quantos ja existiam, e os avisos. Guardado porque a notificacao e
		// curta e os avisos sao a parte que nao se pode perder.
		$table->addColumn('result', Types::TEXT, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
		$table->addColumn('finished_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'hex_imp_uid_idx');
		$table->addIndex(['status'], 'hex_imp_status_idx');

		return $schema;
	}
}
