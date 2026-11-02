<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Command;

use OC\Core\Command\Base;
use OCA\RequrvHive\Service\CleanupService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Removes what `occ app:remove` cannot: the app's credentials, settings,
 * preferences, queued jobs and (optionally) its data tables.
 *
 * The uninstall repair step already wipes the configuration automatically,
 * so this command exists for the cases that step cannot cover — most
 * importantly purging data, which is never done implicitly because a disable
 * may be temporary.
 */
class CleanupCommand extends Base {
    public function __construct(
        private CleanupService $cleanup,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('requrvhive:cleanup')
            ->setDescription('Remove ReQurvHive credentials and configuration (and optionally all app data)')
            ->addOption(
                'with-config',
                null,
                InputOption::VALUE_NONE,
                'Remove credentials, app settings, user preferences, queued jobs and cached state'
            )
            ->addOption(
                'with-data',
                null,
                InputOption::VALUE_NONE,
                'Drop all app data tables (conversations, projects, coworkers, ...) — irreversible'
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Shortcut for --with-config --with-data'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Do not ask for confirmation (for scripts; the prompt is skipped, not a bypass of the flags)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $withConfig = (bool)$input->getOption('with-config') || (bool)$input->getOption('all');
        $withData = (bool)$input->getOption('with-data') || (bool)$input->getOption('all');

        if (!$withConfig && !$withData) {
            $output->writeln('<error>Nothing to do: pass --with-config, --with-data or --all.</error>');
            $output->writeln('');
            $output->writeln('  occ requrvhive:cleanup --with-config   credentials + settings + jobs');
            $output->writeln('  occ requrvhive:cleanup --with-data     drop all app data tables (irreversible)');
            $output->writeln('  occ requrvhive:cleanup --all           both, i.e. a full reset');
            return 1;
        }

        if ($withConfig) {
            $output->writeln('<comment>Removing configuration and credentials…</comment>');
            $counts = $this->cleanup->resetConfiguration();
            $output->writeln(sprintf(
                '<info>✓ Removed %d credential(s), %d app setting(s), %d user preference(s), %d queued job(s)</info>',
                $counts['credentials'],
                $counts['appconfig'],
                $counts['preferences'],
                $counts['jobs'],
            ));
        }

        if ($withData) {
            $output->writeln('<comment>App data is about to be dropped. This cannot be undone.</comment>');
            if (!$input->getOption('force') && !$this->askConfirmation($input, $output)) {
                $output->writeln('<error>Aborted: no data was dropped.</error>');
                return 1;
            }
            $dropped = $this->cleanup->purgeData();
            if ($dropped === []) {
                $output->writeln('<info>✓ No data tables found — nothing to drop</info>');
            } else {
                $output->writeln('<info>✓ Dropped ' . count($dropped) . ' table(s):</info>');
                foreach ($dropped as $table) {
                    $output->writeln('  - ' . $table);
                }
            }
        }

        $output->writeln('');
        $output->writeln('<info>Done. ReQurvHive now behaves like a fresh install.</info>');
        if ($withData) {
            $output->writeln('Note: the Talk bot row (if Talk was in use) lives in Talk\'s own tables and is');
            $output->writeln('not touched here. After reinstall the bot re-registers itself automatically;');
            $output->writeln('if it answers 401 in rooms, run occ requrvhive:talk:register --rotate-secret.');
        }
        return 0;
    }

    /**
     * The 'question' helper is a QuestionHelper at runtime; the declared
     * return type only names the interface, so psalm needs the narrowing.
     */
    private function askConfirmation(InputInterface $input, OutputInterface $output): bool {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return (bool)$helper->ask($input, $output, new ConfirmationQuestion('Drop all ReQurvHive data tables? ', false));
    }
}
