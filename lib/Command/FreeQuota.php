<?php

declare(strict_types=1);

namespace OCA\FilesAccounting\Command;

use OCA\FilesAccounting\Service\StorageService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Show or set a user's individual free quota, the per-user override of the default
 * (an individual, stated exception). Setting it on the master stores it there,
 * forwards it to the user's home node and applies the resulting quota, exactly as
 * the admin page does. 'default' removes the override.
 */
class FreeQuota extends Command {
	public function __construct(
		private StorageService $storage,
		private IUserManager   $userManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('files_accounting:freequota')
			->setDescription('Show or set a user\'s individual free quota (e.g. "1 TB"; "default" removes it)')
			->addArgument('user', InputArgument::REQUIRED, 'User ID')
			->addArgument('quota', InputArgument::OPTIONAL, 'New free quota');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		$user = $this->userManager->get($userId);
		if ($user === null || $user->getUID() !== $userId) {
			$output->writeln("<error>No account {$userId}.</error>");
			return 1;
		}
		$quota = $input->getArgument('quota');
		if ($quota !== null) {
			$this->storage->setFreeQuota($userId, $quota === 'default' ? '' : (string)$quota);
			$this->storage->syncUserQuota($userId);
		}
		$own = $this->storage->getUserFreequota($userId);
		$output->writeln("{$userId}: " . ($own !== '' ? $own : 'default (' . $this->storage->getFreeQuota($userId) . ')'));
		return 0;
	}
}
