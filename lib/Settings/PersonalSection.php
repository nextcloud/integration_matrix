<?php

namespace OCA\Matrix\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {

	public function __construct(
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getID(): string {
		return 'connected-accounts';
	}

	public function getName(): string {
		return $this->l->t('Connected accounts');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'categories/integration.svg');
	}
}
