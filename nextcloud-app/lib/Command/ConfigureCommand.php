<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\RequrvHive\Command;

use OC\Core\Command\Base;
use OCA\RequrvHive\Service\CredentialService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigureCommand extends Base {
    private IConfig $config;
    private CredentialService $credentials;
    private LLMProviderFactory $providerFactory;
    private const APP_NAME = 'requrvhive';

    public function __construct(IConfig $config, CredentialService $credentials, LLMProviderFactory $providerFactory) {
        parent::__construct();
        $this->config = $config;
        $this->credentials = $credentials;
        $this->providerFactory = $providerFactory;
    }

    protected function configure(): void {
        $this
            ->setName('requrvhive:configure')
            ->setDescription('Configure RequrvHive settings')
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the ReQurv AI Hive API key (instance-level)'
            )
            ->addOption(
                'model',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the default Hive model (e.g., requrv-small-3.8)'
            )
            ->addOption(
                'max-tokens',
                null,
                InputOption::VALUE_REQUIRED,
                'Set maximum output tokens (1-128000, default: 8192)'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Set API timeout in seconds (10-1800, default: 30)'
            )
            ->addOption(
                'provider',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the active LLM provider (currently: hive)'
            )
            ->addOption(
                'provider-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the API key for the provider given by --provider (defaults to hive)'
            )
            ->addOption(
                'provider-model',
                null,
                InputOption::VALUE_REQUIRED,
                'Set the model for the provider given by --provider (defaults to hive)'
            )
            ->addOption(
                'show',
                null,
                InputOption::VALUE_NONE,
                'Show current configuration (API key will be masked)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        // Show current configuration if requested
        if ($input->getOption('show')) {
            return $this->showConfiguration($output);
        }

        $updated = false;

        // Provider selection + provider-scoped key/model. Used by headless
        // provisioning to point a fresh install at the Hive endpoint without
        // touching the web UI.
        $providerIds = $this->providerFactory->getProviderIds();
        $provider = $input->getOption('provider');
        $providerKey = $input->getOption('provider-key');
        $providerModel = $input->getOption('provider-model');

        if ($provider !== null) {
            if (!in_array($provider, $providerIds, true)) {
                $output->writeln('<error>Unknown provider "' . $provider . '". Available: ' . implode(', ', $providerIds) . '</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'provider', $provider);
            $output->writeln('<info>✓ Active provider set to: ' . $provider . '</info>');
            $updated = true;
        }

        $targetProvider = $provider ?? LLMProviderFactory::DEFAULT_PROVIDER;

        if ($providerKey !== null) {
            if ($providerKey === '') {
                $output->writeln('<error>--provider-key cannot be empty</error>');
                return 1;
            }
            $this->credentials->setApiKey(null, $providerKey, $targetProvider);
            $output->writeln('<info>✓ API key updated for provider: ' . $targetProvider . '</info>');
            $updated = true;
        }

        if ($providerModel !== null) {
            if ($providerModel === '') {
                $output->writeln('<error>--provider-model cannot be empty</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'model_' . $targetProvider, $providerModel);
            $output->writeln('<info>✓ Model for ' . $targetProvider . ' updated to: ' . $providerModel . '</info>');
            $updated = true;
        }

        // Set API key
        $apiKey = $input->getOption('api-key');
        if ($apiKey !== null) {
            if ($apiKey === '') {
                $output->writeln('<error>--api-key cannot be empty</error>');
                return 1;
            }
            $this->credentials->setApiKey(null, $apiKey, LLMProviderFactory::DEFAULT_PROVIDER);
            $output->writeln('<info>✓ API key updated</info>');
            $updated = true;
        }

        // Set model
        $model = $input->getOption('model');
        if ($model !== null) {
            if (empty($model)) {
                $output->writeln('<error>Model cannot be empty</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'model_' . LLMProviderFactory::DEFAULT_PROVIDER, $model);
            $output->writeln('<info>✓ Model updated to: ' . $model . '</info>');
            $updated = true;
        }

        // Set max tokens
        $maxTokens = $input->getOption('max-tokens');
        if ($maxTokens !== null) {
            $maxTokensInt = (int)$maxTokens;
            if ($maxTokensInt < 1 || $maxTokensInt > 128000) {
                $output->writeln('<error>Max tokens must be between 1 and 128000</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'max_tokens_' . LLMProviderFactory::DEFAULT_PROVIDER, (string)$maxTokensInt);
            $output->writeln('<info>✓ Max tokens updated to: ' . $maxTokensInt . '</info>');
            $updated = true;
        }

        // Set timeout
        $timeout = $input->getOption('timeout');
        if ($timeout !== null) {
            $timeoutInt = (int)$timeout;
            if ($timeoutInt < 10 || $timeoutInt > 1800) {
                $output->writeln('<error>Timeout must be between 10 and 1800 seconds</error>');
                return 1;
            }
            $this->config->setAppValue(self::APP_NAME, 'api_timeout', (string)$timeoutInt);
            $output->writeln('<info>✓ API timeout updated to: ' . $timeoutInt . ' seconds</info>');
            $updated = true;
        }

        if (!$updated) {
            $output->writeln('<comment>No options provided. Use --help for usage information or --show to view current configuration.</comment>');
            return 0;
        }

        $output->writeln('');
        $output->writeln('<info>Configuration updated successfully!</info>');
        return 0;
    }

    private function showConfiguration(OutputInterface $output): int {
        $providerId = $this->providerFactory->getActiveProviderId();
        $provider = $this->providerFactory->getProviderById($providerId);
        $hasKey = $this->credentials->hasApiKey(null, $providerId);
        $model = $provider->getModel();
        $maxTokens = (string)$provider->getMaxTokens();
        $timeout = $this->config->getAppValue(self::APP_NAME, 'api_timeout', '30');

        $output->writeln('');
        $output->writeln('<info>RequrvHive Configuration:</info>');
        $output->writeln('');
        $output->writeln('  Provider:   <comment>' . $providerId . '</comment>');

        if ($hasKey) {
            $output->writeln('  API Key:    <comment>(configured)</comment>');
        } else {
            $output->writeln('  API Key:    <error>Not configured</error>');
        }

        $output->writeln('  Model:      <comment>' . $model . '</comment>');
        $output->writeln('  Max Tokens: <comment>' . $maxTokens . '</comment>');
        $output->writeln('  Timeout:    <comment>' . $timeout . ' seconds</comment>');
        $output->writeln('');

        return 0;
    }
}
