<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\BackgroundJob;

use OCA\HomeExpenses\Service\ImportQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Le uma fatura da fila.
 *
 * Fica para o cron do Nextcloud em vez de correr no pedido: a leitura do PDF
 * demora, e um pedido web nao e sitio para esperar por um servico externo.
 */
class ProcessImportJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ImportQueue $queue,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$jobId = (int)($argument['jobId'] ?? 0);
		if ($jobId > 0) {
			$this->queue->process($jobId);
		}
	}
}
