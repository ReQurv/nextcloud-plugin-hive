<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Command;

use OC\Core\Command\Base;
use OCA\RequrvHive\Talk\TalkBotRegistration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registers (or re-registers) ReQurv as a Nextcloud Talk bot.
 *
 * Registration also happens automatically at boot, but this command lets an
 * admin force it — for example after a Talk upgrade or to recover a bot row
 * that was removed. It is idempotent: Talk upserts by bot URL.
 *
 * --rotate-secret regenerates the shared bot secret before registering. That
 * is the fix for a bot that answers 401 to every reply: the stored secret and
 * Talk's bot row have diverged, and re-registering with the old secret cannot
 * heal it because both sides keep agreeing on the wrong value.
 */
class TalkRegisterCommand extends Base {
    public function __construct(private readonly TalkBotRegistration $registration) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('requrvhive:talk:register')
            ->setDescription('Register ReQurv as a Nextcloud Talk bot (idempotent)')
            ->addOption(
                'rotate-secret',
                null,
                InputOption::VALUE_NONE,
                'Generate a new bot secret before registering (fixes persistent 401 on bot replies)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('rotate-secret')) {
            $this->registration->rotateSecret();
            $output->writeln('<info>✓ Bot secret rotated</info>');
        }

        if (!$this->registration->register()) {
            $output->writeln('<error>Nextcloud Talk is not installed or its bot API is unavailable.</error>');
            return 1;
        }

        $output->writeln('<info>✓ ReQurv Talk bot registered: ' . TalkBotRegistration::BOT_URL . '</info>');
        return 0;
    }
}
