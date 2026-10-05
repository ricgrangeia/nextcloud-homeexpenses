<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

#[CreateTable(table: 'hex_meters', description: 'Utility meters (electricity, water) owned by a user')]
#[CreateTable(table: 'hex_registers', description: 'Registers of a meter -- three (V/C/P) for a tri-hourly electricity meter, one for water')]
#[CreateTable(table: 'hex_readings', description: 'A reading event: all registers of one meter read on the same date')]
#[CreateTable(table: 'hex_reading_values', description: 'The value read for one register within one reading event')]
#[CreateTable(table: 'hex_bottle_types', description: 'Kinds of gas bottle a user buys (brand + gas + nominal weight)')]
#[CreateTable(table: 'hex_gas_cycles', description: 'One physical bottle from the day it was installed to the day it ran out')]
#[CreateTable(table: 'hex_weighings', description: 'Gross weight of the bottle of a cycle on a given date')]
#[CreateTable(table: 'hex_tariffs', description: 'A priced tariff valid over a date range')]
#[CreateTable(table: 'hex_tariff_prices', description: 'Unit price per register code within a tariff')]
class Version1000Date20261005120000 extends SimpleMigrationStep {
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// --- Contadores -------------------------------------------------

		if (!$schema->hasTable('hex_meters')) {
			$table = $schema->createTable('hex_meters');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'electricity']);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('serial', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('unit', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'kWh']);
			// Numero de digitos inteiros do mostrador. Quando o contador da a
			// volta (99999 -> 00000) e o unico dado que permite calcular o
			// consumo real; sem ele, a diferenca da negativa e nao ha como
			// corrigir a posteriori. 0 = nao aplicar correcao de volta.
			$table->addColumn('digits', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// O que o CONTRATO fatura (simples|bi|tri). Pode nao coincidir com
			// o numero de registos do contador -- um contador tri-horario
			// faturado em bi-horario e o caso normal em Portugal.
			$table->addColumn('tariff_option', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'simples']);
			$table->addColumn('installed_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('removed_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('archived', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'hex_meters_uid_idx');
		}

		if (!$schema->hasTable('hex_registers')) {
			$table = $schema->createTable('hex_registers');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('meter_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			// V = vazio, C = cheias, P = ponta, FV = fora de vazio, TOTAL = registo unico
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'TOTAL']);
			$table->addColumn('label', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$table->addColumn('sort_order', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['meter_id'], 'hex_regs_meter_idx');
		}

		if (!$schema->hasTable('hex_readings')) {
			$table = $schema->createTable('hex_readings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('meter_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			// Data que esta NO contador, nao a data em que foi lancada na app.
			$table->addColumn('read_at', Types::DATE_IMMUTABLE, ['notnull' => true]);
			// Uma estimativa da distribuidora misturada com leituras reais
			// estraga qualquer media -- tem de ser distinguivel.
			$table->addColumn('is_estimate', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'manual']);
			$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['meter_id'], 'hex_rdg_meter_idx');
			$table->addIndex(['read_at'], 'hex_rdg_date_idx');
		}

		if (!$schema->hasTable('hex_reading_values')) {
			$table = $schema->createTable('hex_reading_values');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('reading_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('register_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('value', Types::FLOAT, ['notnull' => true, 'default' => 0.0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['reading_id'], 'hex_rdgval_rdg_idx');
			$table->addIndex(['register_id'], 'hex_rdgval_reg_idx');
			// Um registo so pode ter um valor dentro da mesma leitura.
			$table->addUniqueIndex(['reading_id', 'register_id'], 'hex_rdgval_uniq');
		}

		// --- Garrafas de gas ---------------------------------------------

		if (!$schema->hasTable('hex_bottle_types')) {
			$table = $schema->createTable('hex_bottle_types');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('brand', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
			$table->addColumn('gas_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'butano']);
			$table->addColumn('nominal_kg', Types::FLOAT, ['notnull' => true, 'default' => 13.0]);
			// Valor por omissao apenas: a tara real vem gravada na gola de cada
			// garrafa e varia entre garrafas do mesmo tipo, por isso a que conta
			// e a do ciclo.
			$table->addColumn('default_tare_kg', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->addColumn('archived', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'hex_btype_uid_idx');
		}

		if (!$schema->hasTable('hex_gas_cycles')) {
			$table = $schema->createTable('hex_gas_cycles');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('bottle_type_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			// esquentador | fogao | ambos | outro
			$table->addColumn('appliance', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'ambos']);
			$table->addColumn('installed_at', Types::DATE_IMMUTABLE, ['notnull' => true]);
			$table->addColumn('removed_at', Types::DATE_IMMUTABLE, ['notnull' => false]);
			$table->addColumn('tare_kg', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('price_paid', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('supplier', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'hex_cycle_uid_idx');
			$table->addIndex(['bottle_type_id'], 'hex_cycle_type_idx');
		}

		if (!$schema->hasTable('hex_weighings')) {
			$table = $schema->createTable('hex_weighings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('cycle_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('weighed_at', Types::DATE_IMMUTABLE, ['notnull' => true]);
			// Peso TOTAL na balanca (garrafa + gas). O nivel deriva-se com a tara.
			$table->addColumn('gross_kg', Types::FLOAT, ['notnull' => true, 'default' => 0.0]);
			$table->addColumn('note', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['cycle_id'], 'hex_weigh_cycle_idx');
		}

		// --- Tarifas -------------------------------------------------------

		if (!$schema->hasTable('hex_tariffs')) {
			$table = $schema->createTable('hex_tariffs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'electricity']);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('tariff_option', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'simples']);
			$table->addColumn('valid_from', Types::DATE_IMMUTABLE, ['notnull' => true]);
			$table->addColumn('valid_to', Types::DATE_IMMUTABLE, ['notnull' => false]);
			// Termo fixo / potencia contratada, por dia.
			$table->addColumn('standing_charge_day', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('currency', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'EUR']);
			$table->addColumn('notes', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'hex_tariff_uid_idx');
		}

		if (!$schema->hasTable('hex_tariff_prices')) {
			$table = $schema->createTable('hex_tariff_prices');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('tariff_id', Types::BIGINT, ['notnull' => true, 'length' => 20, 'unsigned' => true]);
			$table->addColumn('register_code', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'TOTAL']);
			$table->addColumn('price_per_unit', Types::FLOAT, ['notnull' => true, 'default' => 0.0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['tariff_id'], 'hex_tprice_tariff_idx');
			$table->addUniqueIndex(['tariff_id', 'register_code'], 'hex_tprice_uniq');
		}

		return $schema;
	}
}
