<?php

/**
 * Nextcloud - Matrix
 *
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Matrix\AppInfo;

use Closure;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'integration_matrix';

	public const INTEGRATION_USER_AGENT = 'Nextcloud Matrix integration';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(Closure::fromCallable([$this, 'registerNavigation']));
		$context->injectFn(Closure::fromCallable([$this, 'loadFilesPlugin']));
	}

	public function loadFilesPlugin(
		IUserSession $userSession, IEventDispatcher $eventDispatcher, IUserConfig $userConfig,
	): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			if ($userConfig->getValueString($userId, self::APP_ID, 'file_action_enabled', '1') === '1') {
				$eventDispatcher->addListener(LoadAdditionalScriptsEvent::class, function () {
					Util::addInitScript(self::APP_ID, self::APP_ID . '-filesplugin');
				});
			}
		}
	}

	public function registerNavigation(
		IUserSession $userSession, IUserConfig $userConfig, IAppConfig $appConfig,
	): void {
		$user = $userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
			$container = $this->getContainer();
			$navlinkDefault = $appConfig->getAppValueString('navlink_default', lazy: true);
			if ($userConfig->getValueString($userId, self::APP_ID, 'navigation_enabled', $navlinkDefault) === '1') {
				$adminOauthUrl = $appConfig->getAppValueString('oauth_instance_url', lazy: true);
				$matrixUrl = $userConfig->getValueString($userId, self::APP_ID, 'url', $adminOauthUrl) ?: $adminOauthUrl;
				if ($matrixUrl === '') {
					return;
				}
				$container->get(INavigationManager::class)->add(function () use ($container, $matrixUrl) {
					$urlGenerator = $container->get(IURLGenerator::class);
					$l10n = $container->get(IL10N::class);
					return [
						'id' => self::APP_ID,
						'order' => 10,
						'href' => $matrixUrl,
						'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
						'name' => $l10n->t('Matrix'),
						'target' => '_blank',
					];
				});
			}
		}
	}
}
