<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Service;

use OCA\HomeExpenses\AppInfo\Application;
use OCA\HomeExpenses\BackgroundJob\ProcessImportJob;
use OCA\HomeExpenses\Db\ImportJob;
use OCA\HomeExpenses\Db\ImportJobMapper;
use OCP\BackgroundJob\IJobList;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Fila de importacao de faturas.
 *
 * Ler um PDF demora ate um minuto -- renderizar paginas e procurar QR codes
 * nao e trabalho para um pedido web. Em sincrono isso prende o separador, e
 * quem sair a meio perde o resultado, que e a parte que nao se pode perder:
 * os avisos.
 *
 * Por isso o envio so guarda o ficheiro e devolve. O trabalho e feito em
 * fundo, varias faturas de seguida, e o resultado chega pelas notificacoes do
 * Nextcloud.
 *
 * O PDF fica no appdata e nao na base de dados: um BLOB por fatura engordaria
 * a base e os backups dela sem necessidade. E apagado assim que a importacao
 * termina, com ou sem sucesso -- so se guarda o que serve para alguma coisa.
 */
class ImportQueue {
	private const FOLDER = 'imports';

	public function __construct(
		private ImportJobMapper $jobs,
		private InvoiceService $invoices,
		private IAppDataFactory $appDataFactory,
		private IJobList $jobList,
		private INotificationManager $notifications,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Poe um PDF na fila. Devolve logo, sem o ler.
	 */
	public function enqueue(string $userId, string $contents, string $filename, ?int $meterId): ImportJob {
		$storageName = bin2hex(random_bytes(16)) . '.pdf';
		$this->folder()->newFile($storageName, $contents);

		$job = new ImportJob();
		$job->setUserId($userId);
		$job->setMeterId($meterId);
		$job->setFilename(mb_substr($filename, 0, 255));
		$job->setStorageName($storageName);
		$job->setSize(strlen($contents));
		$job->setStatus(ImportJob::PENDING);
		$job->setCreatedAt(new \DateTimeImmutable());
		$job = $this->jobs->insert($job);

		$this->jobList->add(ProcessImportJob::class, ['jobId' => $job->getId()]);

		return $job;
	}

	/**
	 * Trata um item da fila. Chamado pelo trabalho de fundo.
	 *
	 * Nunca lanca: uma falha aqui tem de ficar registada no proprio item e
	 * notificada, nao rebentar o cron do Nextcloud e levar com ela os outros
	 * trabalhos agendados.
	 */
	public function process(int $jobId): void {
		try {
			$job = $this->jobs->find($jobId);
		} catch (\Throwable) {
			return;
		}

		if (in_array($job->getStatus(), [ImportJob::DONE, ImportJob::FAILED], true)) {
			return;
		}

		// O servico de leitura so processa alguns PDF ao mesmo tempo. Se nao
		// houver lugar, adia-se para a proxima passagem do cron em vez de
		// segurar uma ligacao a espera atras dos outros. Nao conta como
		// tentativa: nao falhou nada, so nao era a vez.
		if (!$this->invoices->readerHasRoom()) {
			$this->jobList->add(ProcessImportJob::class, ['jobId' => $jobId]);
			return;
		}

		$job->setStatus(ImportJob::RUNNING);
		$job->setAttempts($job->getAttempts() + 1);
		$this->jobs->update($job);

		try {
			$contents = $this->folder()->getFile($job->getStorageName())->getContent();
			$result = $this->invoices->import(
				$job->getUserId(), $contents, $job->getFilename(), $job->getMeterId()
			);

			$job->setStatus(ImportJob::DONE);
			$job->setResult(json_encode($result, JSON_UNESCAPED_UNICODE));
			$job->setError(null);
		} catch (\Throwable $e) {
			$this->logger->warning('Falhou a importacao de fatura', ['exception' => $e, 'jobId' => $jobId]);

			// So se desiste ao fim das tentativas. Uma falha de rede nao deve
			// condenar a fatura, mas um PDF que nunca sera legivel tambem nao
			// pode ficar a ocupar a fila para sempre.
			$giveUp = $job->getAttempts() >= ImportJob::MAX_ATTEMPTS;
			$job->setStatus($giveUp ? ImportJob::FAILED : ImportJob::PENDING);
			$job->setError(mb_substr($e->getMessage(), 0, 2000));

			if (!$giveUp) {
				$this->jobs->update($job);
				$this->jobList->add(ProcessImportJob::class, ['jobId' => $jobId]);
				return;
			}
		}

		$job->setFinishedAt(new \DateTimeImmutable());
		$this->jobs->update($job);

		$this->cleanUp($job);
		$this->notify($job);
	}

	/** @return list<array> */
	public function findAllForUser(string $userId): array {
		return array_map(
			static fn (ImportJob $job) => $job->jsonSerialize(),
			$this->jobs->findAllForUser($userId)
		);
	}

	/**
	 * Avisa o utilizador pelas notificacoes do Nextcloud.
	 *
	 * A notificacao diz o essencial e nada mais: quantos documentos entraram
	 * e se ha avisos. Os avisos em si ficam no item da fila, porque sao
	 * demasiado compridos para caber aqui e demasiado importantes para serem
	 * resumidos.
	 */
	private function notify(ImportJob $job): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($job->getUserId())
			->setDateTime(new \DateTime())
			->setObject('invoice_import', (string)$job->getId());

		if ($job->getStatus() === ImportJob::FAILED) {
			$notification->setSubject('import_failed', [
				'filename' => $job->getFilename(),
				'error' => (string)$job->getError(),
			]);
		} else {
			$result = json_decode((string)$job->getResult(), true) ?: [];
			$notification->setSubject('import_done', [
				'filename' => $job->getFilename(),
				'created' => count($result['created'] ?? []),
				'existing' => count($result['existing'] ?? []),
				'warnings' => count($result['warnings'] ?? []),
			]);
		}

		try {
			$this->notifications->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Nao foi possivel notificar', ['exception' => $e]);
		}
	}

	private function cleanUp(ImportJob $job): void {
		try {
			$this->folder()->getFile($job->getStorageName())->delete();
		} catch (\Throwable) {
			// O ficheiro ja nao estar la nao e problema: o objectivo era
			// deixar de o ter.
		}
	}

	private function folder(): ISimpleFolder {
		$appData = $this->appDataFactory->get(Application::APP_ID);
		try {
			return $appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $appData->newFolder(self::FOLDER);
		}
	}
}
