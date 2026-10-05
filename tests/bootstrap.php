<?php

declare(strict_types=1);

/**
 * Os testes desta app sao unitarios puros: exercitam ConsumptionService, que
 * nao tem dependencias nem toca na base de dados. Correm de duas maneiras:
 *
 *  - Dentro de um Nextcloud (app em custom_apps/): usa-se o bootstrap do
 *    servidor, e OCP\* vem do proprio servidor.
 *  - Isolados, numa maquina de trabalho ou em CI: nao ha servidor nenhum, e
 *    OCP\* vem dos stubs do pacote nextcloud/ocp. O pacote nao declara
 *    autoload -- existe para analise estatica -- por isso e preciso registar
 *    um autoloader a mao.
 *
 * O autoloader dos stubs so e registado no segundo caso. Registado sempre,
 * sombrearia o OCP verdadeiro quando ha servidor, e os testes passariam a
 * correr contra codigo que nao e o que esta em producao.
 */
$serverBootstrap = __DIR__ . '/../../../tests/bootstrap.php';

require_once __DIR__ . '/../vendor/autoload.php';

if (file_exists($serverBootstrap)) {
	require_once $serverBootstrap;
	\OC_App::loadApp(\OCA\HomeExpenses\AppInfo\Application::APP_ID);
	OC_Hook::clear();
} else {
	spl_autoload_register(static function (string $class): void {
		$roots = [
			'OCP\\' => __DIR__ . '/../vendor/nextcloud/ocp/OCP/',
			'NCU\\' => __DIR__ . '/../vendor/nextcloud/ocp/NCU/',
		];
		foreach ($roots as $prefix => $dir) {
			if (!str_starts_with($class, $prefix)) {
				continue;
			}
			$path = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($path)) {
				require_once $path;
			}
			return;
		}
	});
}
