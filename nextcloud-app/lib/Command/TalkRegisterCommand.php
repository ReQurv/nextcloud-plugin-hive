<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Command;

use OC\Core\Command\Base;
use OCA\RequrvHive\Talk\TalkBotRegistration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers (or re-registers) ReQurv as a Nextcloud Talk bot.
 *
 * Registration also happens automatically at boot, but this command lets an
 * admin force it — for example after a Talk upgrade or to recover a bot row
 * that was removed. It is idempotent: Talk upserts by bot URL.
 */
class TalkRegisterCommand extends Base {
    public function __construct(private readonly TalkBotRegistration $registration) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('requrvhive:talk:register')
            ->setDescription('Register ReQurv as a Nextcloud Talk bot (idempotent)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if (!$this->registration->register()) {
            $output->writeln('<error>Nextcloud Talk is not installed or its bot API is unavailable.</error>');
            return 1;
        }

        $output->writeln('<info>✓ ReQurv Talk bot registered: ' . TalkBotRegistration::BOT_URL . '</info>');
        return 0;
    }
}
