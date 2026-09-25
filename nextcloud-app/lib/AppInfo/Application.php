<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\RequrvHive\AppInfo;

use OCA\RequrvHive\Cowork\CoworkerTaskRegistry;
use OCA\RequrvHive\Cowork\DocsSummarizeFolderTaskType;
use OCA\RequrvHive\Cowork\DocsTranslateFolderTaskType;
use OCA\RequrvHive\Cowork\VisionClassifyImagesTaskType;
use OCA\RequrvHive\Public\IRequrvHive;
use OCA\RequrvHive\Public\ICoworkManager;
use OCA\RequrvHive\Service\RequrvHiveService;
use OCA\RequrvHive\Service\CoworkManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
    public const APP_ID = 'requrvhive';

    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);

        // Load Composer autoloader
        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }
    }

    public function register(IRegistrationContext $context): void {
        // Register RequrvHive service for use by other apps
        $context->registerService(IRequrvHive::class, function ($c) {
            return $c->get(RequrvHiveService::class);
        });

        // Public Cowork management API for other Nextcloud apps
        $context->registerService(ICoworkManager::class, function ($c) {
            return $c->get(CoworkManager::class);
        });


        // Expose app capabilities via /ocs/v2.php/cloud/capabilities
        $context->registerCapability(\OCA\RequrvHive\Capabilities\RequrvHiveCapability::class);

        // Dashboard widgets — conversations, coworkers, and token usage
        $context->registerDashboardWidget(\OCA\RequrvHive\Dashboard\ConversationsWidget::class);
        $context->registerDashboardWidget(\OCA\RequrvHive\Dashboard\CoworkersWidget::class);
        $context->registerDashboardWidget(\OCA\RequrvHive\Dashboard\UsageWidget::class);
        $context->registerDashboardWidget(\OCA\RequrvHive\Dashboard\CoworkerOutputWidget::class);

        // Cowork task-type registry — the set of jobs coworkers can run
        $context->registerService(CoworkerTaskRegistry::class, function (ContainerInterface $c) {
            $visionClassify = $c->get(VisionClassifyImagesTaskType::class);
            assert($visionClassify instanceof VisionClassifyImagesTaskType);
            $docsSummarize = $c->get(DocsSummarizeFolderTaskType::class);
            assert($docsSummarize instanceof DocsSummarizeFolderTaskType);
            $docsTranslate = $c->get(DocsTranslateFolderTaskType::class);
            assert($docsTranslate instanceof DocsTranslateFolderTaskType);

            return new CoworkerTaskRegistry([$visionClassify, $docsSummarize, $docsTranslate]);
        });

        // Register RequrvHive TaskProcessing Providers for Nextcloud Assistant integration
        // Vision providers
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ImageToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\AnalyzeImagesProvider::class);

        // Audio and image-generation providers. Only providers declaring the
        // matching capability can serve these; the resolver fails with a message
        // naming the alternatives when the user's provider cannot.
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\AudioToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\TextToSpeechProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\TextToImageProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\AudioToAudioChatProvider::class);

        // Text-to-text providers
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\TextToTextProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\SummaryProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\HeadlineProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\TopicsProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\TranslateProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ProofreadProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ChangeToneProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\SimplificationProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ReformulationProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\FormalizationProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ChatProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ChatWithToolsProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ContextWriteProvider::class);
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\GenerateEmojiProvider::class);

        // Nextcloud 34+ only; on 33 the task type is not registered and this
        // provider is never offered.
        $context->registerTaskProcessingProvider(\OCA\RequrvHive\TaskProcessing\ReformatParagraphsProvider::class);

        // Register notification formatter for RequrvHive task notifications
        $context->registerNotifierService(\OCA\RequrvHive\Notifier\RequrvHiveNotifier::class);

        // Listen for task processing completion events to notify users
        $context->registerEventListener(
            \OCP\TaskProcessing\Events\TaskSuccessfulEvent::class,
            \OCA\RequrvHive\Listener\TaskSuccessfulListener::class
        );
        $context->registerEventListener(
            \OCP\TaskProcessing\Events\TaskFailedEvent::class,
            \OCA\RequrvHive\Listener\TaskFailedListener::class
        );

        // Register setup checks for admin overview (Settings → Overview)
        $context->registerSetupCheck(\OCA\RequrvHive\SetupCheck\ApiKeyConfigured::class);
        $context->registerSetupCheck(\OCA\RequrvHive\SetupCheck\PhpExtensions::class);
        $context->registerSetupCheck(\OCA\RequrvHive\SetupCheck\StreamingNotBuffered::class);

        // Register Unified Search provider for RequrvHive chat conversations
        $context->registerSearchProvider(\OCA\RequrvHive\Search\RequrvHiveSearchProvider::class);

        // Feed conversations into Context Chat (optional app) for the Assistant.
        // The event only fires when Context Chat is installed, so this is inert otherwise.
        $context->registerEventListener(
            \OCP\ContextChat\Events\ContentProviderRegisterEvent::class,
            \OCA\RequrvHive\Listener\ContextChatProviderListener::class
        );
    }

    public function boot(IBootContext $context): void {
        // Will be used to load app's main script once we build it
    }
}
