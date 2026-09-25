<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\RequrvHive\Service;

use OCA\RequrvHive\Public\IRequrvHive;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCP\Files\NotFoundException;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * RequrvHive Internal Service API
 *
 * This service provides internal access to AI functionality
 * for use by other Nextcloud apps, background jobs, and workflows.
 * Requests are served by the active LLM provider (ReQurv AI Hive).
 */
class RequrvHiveService implements IRequrvHive {
    private LLMProviderFactory $providerFactory;
    private FileService $fileService;
    private ImageOptimizer $imageOptimizer;
    private INotificationManager $notificationManager;
    private LoggerInterface $logger;
    private string $appName = 'requrvhive';

    public function __construct(
        LLMProviderFactory $providerFactory,
        FileService $fileService,
        ImageOptimizer $imageOptimizer,
        INotificationManager $notificationManager,
        LoggerInterface $logger
    ) {
        $this->providerFactory = $providerFactory;
        $this->fileService = $fileService;
        $this->imageOptimizer = $imageOptimizer;
        $this->notificationManager = $notificationManager;
        $this->logger = $logger;
    }

    /** @throws NoPermittedProviderException */
    private function provider(?string $userId): LLMProviderInterface {
        return $this->providerFactory->getProvider($userId);
    }

    /**
     * Ask a question with optional context
     *
     * @param string $prompt The question to ask
     * @param string $context Optional context (file content, etc.)
     * @param string|null $userId User ID
     * @return array ['response' => string] or ['error' => string]
     */
    public function ask(string $prompt, string $context = '', ?string $userId = null): array {
        $this->logger->info('RequrvHive: Ask request', [
            'user' => $userId,
            'prompt_length' => strlen($prompt),
            'context_length' => strlen($context),
        ]);

        try {
            $result = $this->provider($userId)->ask($prompt, $context, $userId);

            if (isset($result['error'])) {
                $this->logger->error('RequrvHive: Provider error', ['error' => $result['error']]);
            } else {
                $this->logger->info('RequrvHive: Successful response');
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('RequrvHive: Exception in ask()', ['exception' => $e->getMessage()]);
            return ['error' => 'Internal error: ' . $e->getMessage()];
        }
    }

    /**
     * Summarize content
     *
     * @param string $content Content to summarize
     * @param string|null $userId User ID
     * @return array ['response' => string] or ['error' => string]
     */
    public function summarize(string $content, ?string $userId = null): array {
        $this->logger->info('RequrvHive: Summarize request', [
            'user' => $userId,
            'content_length' => strlen($content),
        ]);

        try {
            return $this->provider($userId)->summarize($content, $userId);
        } catch (\Exception $e) {
            $this->logger->error('RequrvHive: Exception in summarize()', ['exception' => $e->getMessage()]);
            return ['error' => 'Internal error: ' . $e->getMessage()];
        }
    }

    /**
     * Analyze a file
     *
     * @param string $filePath Nextcloud file path (e.g., '/Documents/file.txt')
     * @param string $prompt What to ask about the file
     * @param string|null $userId User ID who owns the file
     * @return array ['response' => string] or ['error' => string]
     */
    public function analyzeFile(string $filePath, string $prompt, ?string $userId = null): array {
        $this->logger->info('RequrvHive: File analysis request', [
            'user' => $userId,
            'file' => $filePath,
        ]);

        if ($userId === null) {
            return ['error' => 'A user id is required to read files'];
        }

        try {
            $fileData = $this->fileService->getContent($filePath, $userId);
            $mimeType = $fileData['mimeType'];

            if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
                $optimized = $this->imageOptimizer->optimize(
                    base64_decode($fileData['content']),
                    $mimeType
                );
                return $this->provider($userId)->askWithImage(
                    $prompt,
                    $optimized['data'],
                    $optimized['mimeType'],
                    $userId
                );
            }

            // Text and other files: pass content as context
            $context = "File: {$fileData['name']} ({$mimeType}, {$fileData['size']} bytes)\n\n"
                     . $fileData['content'];
            return $this->provider($userId)->ask($prompt, $context, $userId);

        } catch (NotFoundException $e) {
            return ['error' => 'File not found: ' . $filePath];
        } catch (\Exception $e) {
            $this->logger->error('RequrvHive: Exception in analyzeFile()', ['exception' => $e->getMessage()]);
            return ['error' => 'File analysis error: ' . $e->getMessage()];
        }
    }

    /**
     * Analyze multiple files (multi-image vision, text)
     *
     * @param string[] $filePaths Array of Nextcloud file paths
     * @param string $prompt What to ask about the files
     * @param string|null $userId User ID who owns the files
     * @return array ['response' => string] or ['error' => string]
     */
    public function analyzeFiles(array $filePaths, string $prompt, ?string $userId = null): array {
        $this->logger->info('RequrvHive: Multi-file analysis request', [
            'user' => $userId,
            'file_count' => count($filePaths),
        ]);

        if ($userId === null) {
            return ['error' => 'A user id is required to read files'];
        }

        try {
            $images = [];
            $textParts = [];

            foreach ($filePaths as $filePath) {
                $fileData = $this->fileService->getContent($filePath, $userId);
                $mimeType = $fileData['mimeType'];

                if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
                    $optimized = $this->imageOptimizer->optimize(
                        base64_decode($fileData['content']),
                        $mimeType
                    );
                    $images[] = ['base64' => $optimized['data'], 'mimeType' => $optimized['mimeType']];
                } else {
                    $textParts[] = "--- File: {$fileData['name']} ({$mimeType}, {$fileData['size']} bytes) ---\n{$fileData['content']}";
                }
            }

            if (count($images) > ImageOptimizer::MAX_IMAGES) {
                return ['error' => 'Too many images. Maximum ' . ImageOptimizer::MAX_IMAGES . ' per request.'];
            }

            $provider = $this->provider($userId);

            // Images only
            if (!empty($images) && empty($textParts)) {
                if (count($images) === 1) {
                    return $provider->askWithImage($prompt, $images[0]['base64'], $images[0]['mimeType'], $userId);
                }
                return $provider->askWithImages($prompt, $images, $userId);
            }

            // Text only or mixed — use text context
            $context = implode("\n\n", $textParts);
            if (!empty($images)) {
                // Mixed: use askWithImages and prepend text context to prompt
                $promptWithContext = $context . "\n\n" . $prompt;
                return $provider->askWithImages($promptWithContext, $images, $userId);
            }

            return $provider->ask($prompt, $context, $userId);

        } catch (NotFoundException $e) {
            return ['error' => 'File not found: ' . $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error('RequrvHive: Exception in analyzeFiles()', ['exception' => $e->getMessage()]);
            return ['error' => 'File analysis error: ' . $e->getMessage()];
        }
    }

    /**
     * Check if RequrvHive is configured and ready to use
     *
     * @param string|null $userId Optional user ID
     * @return bool True if the active provider has an API key
     */
    public function isConfigured(?string $userId = null): bool {
        try {
            return $this->provider($userId)->isConfigured($userId);
        } catch (NoPermittedProviderException $e) {
            return false;
        }
    }

    /**
     * Get current configuration status
     *
     * @return array Configuration details
     */
    public function getStatus(): array {
        try {
            $provider = $this->provider(null);
            $config = $provider->getConfiguration();
        } catch (NoPermittedProviderException $e) {
            return ['configured' => false, 'model' => '', 'max_tokens' => 0, 'timeout' => 0];
        }

        return [
            'configured' => $provider->isConfigured(),
            'model' => $config['model'],
            'max_tokens' => $config['max_tokens'],
            'timeout' => $config['timeout'],
        ];
    }

    /**
     * Send a notification to a user
     *
     * @param string $userId User to notify
     * @param string $subject Notification subject key, as handled by RequrvHiveNotifier
     * @param string $message Notification message
     */
    private function notify(string $userId, string $subject, string $message): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp($this->appName)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('requrvhive', 'response')
            ->setSubject($subject, [$message]);

        $this->notificationManager->notify($notification);
    }

    /**
     * Process a request asynchronously (useful for long-running operations)
     *
     * @param string $prompt The prompt
     * @param string $context Optional context
     * @param string $userId User ID
     * @param bool $notify Whether to send notification on completion
     * @return array ['status' => 'queued', 'message' => string]
     */
    public function askAsync(string $prompt, string $context, string $userId, bool $notify = true): array {
        $this->logger->info('RequrvHive: Async request queued', ['user' => $userId]);

        // For now, just run synchronously
        $result = $this->ask($prompt, $context, $userId);

        if ($notify && isset($result['response'])) {
            // Must be a subject key RequrvHiveNotifier knows: it throws on anything
            // else, and the notification then never renders.
            $this->notify($userId, 'ask_response', substr($result['response'], 0, 100));
        }

        return $result;
    }
}
