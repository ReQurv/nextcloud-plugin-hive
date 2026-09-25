<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\RequrvHive\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\RequrvHive\Service\Exception\ContentTooLargeException;
use OCA\RequrvHive\Service\FileService;
use OCA\RequrvHive\Service\ImageOptimizer;
use OCA\RequrvHive\Service\McpClientService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use Psr\Log\LoggerInterface;

class ChatController extends Controller {
    use RequiresUserIdTrait;
    use ErrorResponseTrait;

    private LLMProviderFactory $providerFactory;
    private FileService $fileService;
    private ImageOptimizer $imageOptimizer;
    private McpClientService $mcpClient;
    private ?string $userId;
    private LoggerInterface $logger;

    private const MAX_CONTENT_LENGTH = 5242880; // 5MB (5 * 1024 * 1024)

    // Rate limits are enforced by Nextcloud's RateLimitingMiddleware via the
    // attributes on each endpoint: UserRateLimit keys on the session user,
    // AnonRateLimit on the remote IP. Both are needed — a user-keyed limit alone
    // gives every anonymous caller one shared bucket.
    private const RATE_LIMIT_REQUESTS = 10;
    private const RATE_LIMIT_WINDOW = 60; // seconds

    public function __construct(
        string $appName,
        IRequest $request,
        LLMProviderFactory $providerFactory,
        FileService $fileService,
        ImageOptimizer $imageOptimizer,
        McpClientService $mcpClient,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->providerFactory = $providerFactory;
        $this->fileService = $fileService;
        $this->imageOptimizer = $imageOptimizer;
        $this->mcpClient = $mcpClient;
        $this->userId = $userId;
        $this->logger = $logger;
    }

    /**
     * The LLM provider active for the current user.
     *
     * @throws NoPermittedProviderException when the admin has blocked every
     *         provider for this user; each endpoint checks noProviderAvailable()
     *         up front so this never escapes as a 500.
     */
    private function provider(): LLMProviderInterface {
        return $this->providerFactory->getProvider($this->userId);
    }

    /**
     * Fail closed when no provider is permitted, or null to carry on.
     *
     * @return JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|null
     */
    private function noProviderAvailable(): ?JSONResponse {
        if ($this->providerFactory->hasPermittedProvider($this->userId)) {
            return null;
        }
        return $this->clientError(
            Http::STATUS_FORBIDDEN,
            NoPermittedProviderException::USER_MESSAGE,
        );
    }

    /**
     * Validate content length
     */
    private function validateContentLength(string $content): bool {
        return strlen($content) <= self::MAX_CONTENT_LENGTH;
    }

    /**
     * Send a single-turn prompt to the model, optionally with attached files
     *
     * @param string $prompt  The user's question or instruction
     * @param string $context Optional context to provide alongside the prompt
     * @param list<string> $files Optional list of Nextcloud file paths to include as context
     *
     * 200: Model response with model info and token usage
     * 400: No prompt was provided
     * 404: One of the attached files was not found
     * 413: Prompt or context exceeds the 5 MB content limit
     * 403: No provider is permitted for this user
     * 429: Rate limit exceeded (10 requests per minute)
     * 500: An attached file could not be read
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[AnonRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[OpenAPI]
    public function ask(string $prompt = '', string $context = '', array $files = []): JSONResponse {
        if (($denied = $this->noProviderAvailable()) !== null) {
            return $denied;
        }

        if (!$prompt) {
            return $this->clientError(400, 'No prompt provided');
        }

        // When files are attached, load their content and delegate appropriately
        if (!empty($files)) {
            return $this->askWithFiles($prompt, $files);
        }

        if (!$this->validateContentLength($prompt . $context)) {
            return $this->clientError(413, 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB');
        }

        $result = $this->provider()->ask($prompt, $context, $this->userId);
        return new JSONResponse($result);
    }

    /**
     * Handle ask() with attached files — reads content and delegates to the appropriate provider method
     *
     * @param list<string> $files
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     */
    private function askWithFiles(string $prompt, array $files): JSONResponse {
        $fileDataList = [];
        foreach ($files as $path) {
            try {
                $fileDataList[] = $this->fileService->getContent($path, $this->requireUserId());
            } catch (\OCP\Files\NotFoundException $e) {
                return $this->clientError(404, 'File not found: ' . $path);
            } catch (\InvalidArgumentException $e) {
                return $this->clientError(400, 'Attached path does not refer to a file');
            } catch (ContentTooLargeException $e) {
                return $this->clientError(413, 'Attached file is too large to read');
            } catch (\Exception $e) {
                return $this->errorResponse($e, 500, 'Could not read an attached file', 'ChatController::ask');
            }
        }

        // Partition files by type
        $images = [];
        $documents = [];
        $textParts = [];

        foreach ($fileDataList as $f) {
            if (str_starts_with($f['mimeType'], 'image/') && $this->imageOptimizer->isSupported($f['mimeType'])) {
                $optimized = $this->imageOptimizer->optimize(
                    base64_decode($f['content']),
                    $f['mimeType']
                );
                $images[] = [
                    'base64' => $optimized['data'],
                    'mimeType' => $optimized['mimeType'],
                    'name' => $f['name'] ?? 'image',
                ];
            } elseif ($f['mimeType'] === 'application/pdf') {
                $documents[] = $f;
            } else {
                $textParts[] = "--- File: {$f['name']} ({$f['mimeType']}, {$f['size']} bytes) ---\n{$f['content']}";
            }
        }

        if (count($images) > ImageOptimizer::MAX_IMAGES) {
            return $this->clientError(400, 'Too many images. Maximum ' . ImageOptimizer::MAX_IMAGES . ' images per request.');
        }

        // Images only (no other file types)
        if (!empty($images) && empty($documents) && empty($textParts)) {
            if (count($images) === 1) {
                $result = $this->provider()->askWithImage(
                    $prompt,
                    $images[0]['base64'],
                    $images[0]['mimeType'],
                    $this->userId,
                );
            } else {
                $result = $this->provider()->askWithImages($prompt, $images, $this->userId);
            }
            return new JSONResponse($result);
        }

        // Single PDF only
        if (empty($images) && count($documents) === 1 && empty($textParts)) {
            $f = $documents[0];
            $rawBytes = base64_decode($f['content']);
            $result = $this->provider()->askWithDocument(
                $prompt,
                $rawBytes,
                'application/pdf',
                $f['name'],
                $this->userId,
            );
            return new JSONResponse($result);
        }

        // Mixed content: build structured content blocks for a single user message
        if (!empty($images) || !empty($documents)) {
            $content = [];

            // Images first (vision best practice: images before text)
            foreach ($images as $img) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $img['mimeType'],
                        'data' => $img['base64'],
                    ],
                ];
            }

            // PDFs as document blocks
            foreach ($documents as $doc) {
                $content[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $doc['content'],
                    ],
                    'title' => $doc['name'],
                ];
            }

            // Text files as context in the text block
            $promptWithContext = $prompt;
            if (!empty($textParts)) {
                $promptWithContext = implode("\n\n", $textParts) . "\n\n" . $prompt;
            }

            $content[] = ['type' => 'text', 'text' => $promptWithContext];

            $messages = [['role' => 'user', 'content' => $content]];
            $result = $this->provider()->chat($messages, null, $this->userId);
            return new JSONResponse($result);
        }

        // Text-only fallback (no images, no PDFs)
        $context = implode("\n\n", $textParts);

        if (!$this->validateContentLength($prompt . $context)) {
            return $this->clientError(413, 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB');
        }

        $result = $this->provider()->ask($prompt, $context, $this->userId);
        return new JSONResponse($result);
    }

    /**
     * Send a multi-turn conversation to the model
     *
     * @param list<array{role: string, content: string}> $messages Conversation messages
     * @param string|null $system Optional system prompt
     * @param array<string, mixed> $options Optional model parameters (temperature, top_p, top_k, stop_sequences)
     *
     * 200: Model response with model info and token usage
     * 400: Messages array is missing or empty
     * 413: Combined message content exceeds the 5 MB content limit
     * 403: No provider is permitted for this user
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[AnonRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[OpenAPI]
    public function chat(array $messages = [], ?string $system = null, array $options = []): JSONResponse {
        if (($denied = $this->noProviderAvailable()) !== null) {
            return $denied;
        }

        if (empty($messages)) {
            return $this->clientError(400, 'No messages provided');
        }

        // Validate total content size
        $totalLength = 0;
        foreach ($messages as $msg) {
            $content = $msg['content'] ?? '';
            $totalLength += is_string($content) ? strlen($content) : strlen((string)json_encode($content));
        }
        if ($totalLength > self::MAX_CONTENT_LENGTH) {
            return $this->clientError(413, 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB');
        }

        // Check for enabled MCP servers and use agentic tool loop if available
        try {
            $allTools = $this->mcpClient->getAllTools();
        } catch (\Throwable $e) {
            $allTools = ['tools' => [], 'mapping' => []];
        }

        if (!empty($allTools['tools'])) {
            $mapping = $allTools['mapping'];
            $mcpClient = $this->mcpClient;
            $result = $this->provider()->chatWithTools(
                $messages,
                $allTools['tools'],
                function (string $name, array $input) use ($mcpClient, $mapping): array {
                    return $mcpClient->executeTool($name, $input, $mapping);
                },
                $system,
                $this->userId,
                $options,
            );
        } else {
            $result = $this->provider()->chat($messages, $system, $this->userId, $options);
        }

        return new JSONResponse($result);
    }

    /**
     * Summarize text content
     *
     * @param string $content The text content to summarize
     *
     * 200: Summary with model info and token usage
     * 400: No content was provided
     * 413: Content exceeds the 5 MB content limit
     * 403: No provider is permitted for this user
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[AnonRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[OpenAPI]
    public function summarize(string $content = ''): JSONResponse {
        if (($denied = $this->noProviderAvailable()) !== null) {
            return $denied;
        }

        if (!$content) {
            return $this->clientError(400, 'No content provided');
        }

        if (!$this->validateContentLength($content)) {
            return $this->clientError(413, 'Content too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB');
        }

        $result = $this->provider()->summarize($content, $this->userId);
        return new JSONResponse($result);
    }

    /**
     * Analyze a Nextcloud file (vision for images, document for PDFs, text for others)
     *
     * @param string $filePath Path to the file within the user's Nextcloud storage
     * @param string $prompt   Instruction or question about the file
     *
     * 200: Analysis with model info and token usage
     * 400: No filePath provided or prompt is invalid
     * 404: File not found at the given path
     * 413: Prompt exceeds the 5 MB content limit
     * 403: No provider is permitted for this user
     * 429: Rate limit exceeded (10 requests per minute)
     *
     * @return JSONResponse<Http::STATUS_OK, array{response: string, model: string, usage: array{input_tokens: int, output_tokens: int}}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_REQUEST_ENTITY_TOO_LARGE, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_TOO_MANY_REQUESTS, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string, errorId: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[AnonRateLimit(limit: self::RATE_LIMIT_REQUESTS, period: self::RATE_LIMIT_WINDOW)]
    #[OpenAPI]
    public function analyzeFile(string $filePath = '', string $prompt = 'Analyze and describe this file.'): JSONResponse {
        if (($denied = $this->noProviderAvailable()) !== null) {
            return $denied;
        }

        if (empty($filePath)) {
            return $this->clientError(400, 'No filePath provided');
        }

        if (!$this->validateContentLength($prompt)) {
            return $this->clientError(413, 'Prompt too large. Maximum size is ' . (self::MAX_CONTENT_LENGTH / (1024 * 1024)) . 'MB');
        }

        try {
            $fileData = $this->fileService->getContent($filePath, $this->requireUserId());
        } catch (\OCP\Files\NotFoundException $e) {
            return $this->clientError(404, 'File not found: ' . $filePath);
        } catch (\InvalidArgumentException $e) {
            return $this->clientError(400, 'Path does not refer to a file');
        } catch (ContentTooLargeException $e) {
            return $this->clientError(413, 'File is too large to read');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Could not read the file', 'ChatController::analyzeFile');
        }

        $mimeType = $fileData['mimeType'];

        if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
            $optimized = $this->imageOptimizer->optimize(
                base64_decode($fileData['content']),
                $mimeType
            );
            $result = $this->provider()->askWithImage(
                $prompt,
                $optimized['data'],
                $optimized['mimeType'],
                $this->userId,
            );
        } elseif ($mimeType === 'application/pdf') {
            $rawBytes = base64_decode($fileData['content']);
            $docName = $fileData['name'] ?? basename($filePath);
            $result = $this->provider()->askWithDocument(
                $prompt,
                $rawBytes,
                'application/pdf',
                $docName,
                $this->userId,
            );
        } else {
            $context = "File: {$fileData['name']} ({$mimeType}, {$fileData['size']} bytes)\n\n"
                     . $fileData['content'];
            $result = $this->provider()->ask($prompt, $context, $this->userId);
        }

        return new JSONResponse($result);
    }
}
