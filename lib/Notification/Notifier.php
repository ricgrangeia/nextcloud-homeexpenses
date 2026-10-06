<?php

declare(strict_types=1);

namespace OCA\HomeExpenses\Notification;

use OCA\HomeExpenses\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Transforma as notificacoes da app em texto para o utilizador.
 */
class Notifier implements INotifier {
	public function __construct(private IURLGenerator $url) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return 'Consumos de Casa';
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$p = $notification->getSubjectParameters();
		$link = $this->url->linkToRouteAbsolute('homeexpenses.page.index') . '#/invoices';

		switch ($notification->getSubject()) {
			case 'import_done':
				$created = (int)($p['created'] ?? 0);
				$existing = (int)($p['existing'] ?? 0);
				$warnings = (int)($p['warnings'] ?? 0);

				$notification->setParsedSubject(
					$created === 1
						? 'Fatura importada'
						: sprintf('%d documentos importados', $created)
				);

				$parts = [sprintf('De %s.', $p['filename'] ?? 'um ficheiro')];
				if ($created === 0 && $existing > 0) {
					$parts[] = 'Nada de novo: já tinha sido importado.';
				} elseif ($existing > 0) {
					$parts[] = sprintf('%d já existia(m).', $existing);
				}
				// Os avisos nao cabem aqui, mas tem de se saber que existem --
				// um aviso ignorado deixa totais a menos com ar de certos.
				if ($warnings > 0) {
					$parts[] = $warnings === 1
						? 'Há 1 aviso para ver.'
						: sprintf('Há %d avisos para ver.', $warnings);
				}
				$notification->setParsedMessage(implode(' ', $parts));
				break;

			case 'import_failed':
				$notification->setParsedSubject('Não foi possível ler a fatura');
				$notification->setParsedMessage(sprintf(
					'%s — %s',
					$p['filename'] ?? 'ficheiro',
					$p['error'] ?? 'razão desconhecida'
				));
				break;

			default:
				throw new UnknownNotificationException();
		}

		$notification->setLink($link);
		$notification->setIcon($this->url->getAbsoluteURL(
			$this->url->imagePath(Application::APP_ID, 'app.svg')
		));

		return $notification;
	}
}
