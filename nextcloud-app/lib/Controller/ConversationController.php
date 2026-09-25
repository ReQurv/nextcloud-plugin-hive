<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Controller;

use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Db\ConversationMapper;
use OCA\RequrvHive\Db\Message as MessageEntity;
use OCA\RequrvHive\Db\MessageFile;
use OCA\RequrvHive\Db\MessageFileMapper;
use OCA\RequrvHive\Db\MessageMapper;
use OCA\RequrvHive\Db\ProjectMapper;
use OCA\RequrvHive\Db\ProjectPathMapper;
use OCA\RequrvHive\Http\SSEResponse;
use OCA\RequrvHive\Service\ChatToolsService;
use OCA\RequrvHive\Service\FileService;
use OCA\RequrvHive\Service\ImageOptimizer;
use OCA\RequrvHive\Service\McpClientService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCA\RequrvHive\BackgroundJob\IndexConversationJob;
use OCA\RequrvHive\Service\ContextChatService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ConversationController extends Controller {
    use RequiresUserIdTrait;
    use ErrorResponseTrait;

    private const MIN_THINKING_BUDGET = 1024;

    /**
     * Appended to every conversation turn so the model knows it is an agent
     * over the user's files, not a blind chatbot: when a question the user's
     * storage can answer arrives, it is expected to go find the answer with
     * the file tools instead of guessing or asking for an attachment.
     */
    private const AGENT_SYSTEM_NOTE = "You are connected to the user's Nextcloud files. When a request can be answered from the user's files or folders, do not guess and do not ask them to attach anything: locate it yourself with your file tools (search_files to find it, list_directory to inspect a folder, get_file_info for metadata, read_file for text content, read_image to view a picture) and answer from what you actually read. Paths are relative to the root of the user's files; '/' is the root. Only read what the request needs.";

    /**
     * Caps for a directory attached to a message. The directory expands to the
     * content of the files inside it, so a big tree cannot blow up the context
     * window or the request cost: at most this many files are read, recursion
     * stops at this depth, readable text is bounded in total, and image/PDF
     * blocks are bounded per request.
     */
    private const MAX_DIRECTORY_FILES = 100;
    private const MAX_DIRECTORY_DEPTH = 16;
    private const MAX_DIRECTORY_TEXT_BYTES = 1024 * 1024; // 1MB of combined text
    private const MAX_DIRECTORY_DOCUMENTS = 5;
    private const MAX_DIRECTORY_NODES = 1000; // total entries (files + folders) walked

    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private MessageFileMapper $messageFileMapper;
    private ProjectMapper $projectMapper;
    private ProjectPathMapper $projectPathMapper;
    private LLMProviderFactory $providerFactory;
    private FileService $fileService;
    private ImageOptimizer $imageOptimizer;
    private McpClientService $mcpClient;
    private ChatToolsService $chatTools;
    private IJobList $jobList;
    private ContextChatService $contextChat;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        MessageFileMapper $messageFileMapper,
        ProjectMapper $projectMapper,
        ProjectPathMapper $projectPathMapper,
        LLMProviderFactory $providerFactory,
        FileService $fileService,
        ImageOptimizer $imageOptimizer,
        McpClientService $mcpClient,
        ChatToolsService $chatTools,
        IJobList $jobList,
        ContextChatService $contextChat,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->logger = $logger;
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper = $messageMapper;
        $this->messageFileMapper = $messageFileMapper;
        $this->projectMapper = $projectMapper;
        $this->projectPathMapper = $projectPathMapper;
        $this->providerFactory = $providerFactory;
        $this->fileService = $fileService;
        $this->imageOptimizer = $imageOptimizer;
        $this->mcpClient = $mcpClient;
        $this->chatTools = $chatTools;
        $this->jobList = $jobList;
        $this->contextChat = $contextChat;
        $this->userId = $userId;
    }

    /**
     * Queue a best-effort Context Chat re-index for a conversation, off the
     * request path. No-op downstream when Context Chat is not installed.
     */
    private function queueContextChatIndex(int $conversationId): void {
        $this->jobList->add(IndexConversationJob::class, ['id' => $conversationId]);
    }

    /**
     * List all conversations for the current user
     *
     * 200: List of conversations
     *
     * @return JSONResponse<Http::STATUS_OK, list<array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int, speedFast: ?bool}>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function index(): JSONResponse {
        $conversations = $this->conversationMapper->findAllByUser($this->requireUserId());
        return new JSONResponse(array_map(
            fn(Conversation $c) => $c->jsonSerialize(),
            $conversations
        ));
    }

    /**
     * Create a new conversation
     *
     * Provider and model are snapshotted so the conversation keeps answering
     * from where it started even after the user changes their default. Omit
     * both to follow the user's setting (`provider` stays null).
     *
     * @param string|null $provider Provider to pin (null/'' follows the user's setting)
     * @param string|null $model Model to pin (null/'' uses the provider's default)
     *
     * 200: The created conversation
     * 400: Unknown provider
     * 403: The provider is not permitted for this user
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int, speedFast: ?bool}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function create(?string $provider = null, ?string $model = null): JSONResponse {
        // Validate before anything else: getProviderById() falls back to
        // Anthropic for an unknown id, which is right for a stale config value
        // but would let an arbitrary request string reach persistence unnoticed.
        if ($provider !== null && $provider !== '' && !$this->providerFactory->isKnownProviderId($provider)) {
            return $this->clientError(400, 'Unknown provider: ' . $provider);
        }
        // Pinning is a provider-selecting path like any other, so it is subject
        // to the same access rules as the settings pages.
        if ($provider !== null && $provider !== '' && !$this->providerFactory->isAllowedForUser($provider, $this->userId)) {
            return $this->forbiddenProvider($provider);
        }

        $now = time();
        $pinned = ($provider !== null && $provider !== '') ? $provider : null;
        try {
            $service = $pinned !== null
                ? $this->providerFactory->getProviderById($pinned)
                : $this->providerFactory->getProvider($this->userId);
        } catch (NoPermittedProviderException $e) {
            return $this->noProviderAvailable();
        }

        $conversation = new Conversation();
        $conversation->setUserId($this->requireUserId());
        $conversation->setProvider($pinned);
        $conversation->setModel(($model !== null && $model !== '') ? $model : $service->getModel($this->userId));
        $conversation->setCreatedAt($now);
        $conversation->setUpdatedAt($now);

        $conversation = $this->conversationMapper->insert($conversation);
        return new JSONResponse($conversation->jsonSerialize());
    }

    /**
     * Pin a provider and/or model on an existing conversation
     *
     * Passing an empty provider unpins it, so the conversation follows the
     * user's setting again. Changing the provider re-snapshots the model to
     * that provider's default unless one is given explicitly — otherwise the
     * conversation would carry a model id the new provider does not serve.
     *
     * @param int $id Conversation ID
     * @param string|null $provider Provider to pin ('' unpins, null keeps unchanged)
     * @param string|null $model Model to pin (null keeps unchanged)
     *
     * 200: Updated conversation
     * 400: Unknown provider
     * 403: The provider is not permitted for this user
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int, speedFast: ?bool}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function setModel(int $id, ?string $provider = null, ?string $model = null): JSONResponse {
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        if ($provider !== null) {
            if ($provider !== '' && !$this->providerFactory->isKnownProviderId($provider)) {
                return $this->clientError(400, 'Unknown provider: ' . $provider);
            }
            if ($provider !== '' && !$this->providerFactory->isAllowedForUser($provider, $this->userId)) {
                return $this->forbiddenProvider($provider);
            }
            $conversation->setProvider($provider === '' ? null : $provider);

            if ($model === null || $model === '') {
                $model = $this->resolveProvider($conversation)->getModel($this->userId);
            }

            // The pinned effort belongs to the old provider's vocabulary.
            if (!$this->resolveProvider($conversation)->getCapabilities()['effort']) {
                $conversation->setEffort(null);
            }

            // Likewise the budget, which is meaningless without thinking.
            if (!$this->resolveProvider($conversation)->getCapabilities()['thinking']) {
                $conversation->setThinkingBudget(null);
            }
        }

        if ($model !== null && $model !== '') {
            $conversation->setModel($model);
        }

        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        return new JSONResponse($conversation->jsonSerialize());
    }

    /**
     * Get a conversation with its messages and files
     *
     * @param int $id Conversation ID
     *
     * 200: Conversation with messages
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int, speedFast: ?bool, messages: list<array{id: int, conversationId: int, role: string, content: string, inputTokens: ?int, outputTokens: ?int, cacheCreationTokens: ?int, cacheReadTokens: ?int, latencyMs: ?int, citations: ?array<string, mixed>, documents: ?array<string, mixed>, createdAt: int, files: list<array{id: int, messageId: int, filePath: string, fileName: string, mimeType: ?string, createdAt: int}>}>}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function show(int $id): JSONResponse {
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        $messages = $this->messageMapper->findByConversation($id);
        $messagesData = [];
        foreach ($messages as $msg) {
            $msgData = $msg->jsonSerialize();
            $msgData['files'] = array_map(
                fn(MessageFile $f) => $f->jsonSerialize(),
                $this->messageFileMapper->findByMessage($msg->getId())
            );
            $messagesData[] = $msgData;
        }

        $data = $conversation->jsonSerialize();
        $data['messages'] = $messagesData;
        return new JSONResponse($data);
    }

    /**
     * Update a conversation (title, project link, effort, thinking and/or fast-mode override)
     *
     * @param int $id Conversation ID
     * @param string $title New title
     * @param int|null $projectId Project ID to link (null to clear)
     * @param string|null $effort Effort override (low…max; empty string clears)
     * @param string|null $thinking Adaptive-thinking override ('on'/'off'; empty string clears)
     * @param string|null $thinkingBudget Explicit thinking budget in tokens (empty string clears)
     * @param string|null $speedFast Fast-mode override ('on'/'off'; empty string clears)
     *
     * 200: Updated conversation
     * 400: Invalid effort, thinking, thinkingBudget or speedFast value
     * 403: No provider is permitted for this user
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int, speedFast: ?bool}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, allowed: list<string>}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function update(int $id, string $title = '', ?int $projectId = null, ?string $effort = null, ?string $thinking = null, ?string $thinkingBudget = null, ?string $speedFast = null): JSONResponse {
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        if ($title !== '') {
            $conversation->setTitle($title);
        }

        // Allow explicitly setting or clearing project association
        $requestParams = $this->request->getParams();
        if (array_key_exists('projectId', $requestParams)) {
            $conversation->setProjectId($projectId);
        }

        if ($effort !== null && $effort !== '') {
            // Effort vocabularies differ per provider (Anthropic's low…max,
            // Mistral's none/high), so validate against the provider that owns
            // the conversation — checking a Mistral conversation against
            // Anthropic's table produced nonsense advice.
            $provider = $this->resolveProvider($conversation);
            if (!$provider->getCapabilities()['effort']) {
                return new JSONResponse([
                    'error' => $provider->getLabel() . ' does not support effort levels',
                    'errorId' => '',
                    'allowed' => [],
                ], 400);
            }

            $model = $conversation->getModel();
            $allowed = $provider->getAllowedEfforts($model);
            if (!in_array($effort, $allowed, true)) {
                return new JSONResponse([
                    'error' => $allowed === []
                        ? 'Model ' . $model . ' does not support effort'
                        : 'Invalid effort for ' . $model . '. Allowed: ' . implode(', ', $allowed),
                    'errorId' => '',
                    'allowed' => $allowed,
                ], 400);
            }
            $conversation->setEffort($effort);
        } elseif ($effort === '') {
            $conversation->setEffort(null);
        }

        if ($thinking !== null) {
            if (!in_array($thinking, ['on', 'off', ''], true)) {
                return $this->clientError(400, 'Thinking must be "on", "off" or empty');
            }
            $conversation->setThinking($thinking === '' ? null : $thinking === 'on');
        }

        if ($thinkingBudget !== null && $thinkingBudget !== '') {
            // Like effort, an Anthropic concept — do not validate a Hetzner or
            // Ollama conversation against it.
            $provider = $this->resolveProvider($conversation);
            if (!$provider->getCapabilities()['thinking']) {
                return new JSONResponse([
                    'error' => $provider->getLabel() . ' does not support a thinking budget',
                    'errorId' => '',
                    'allowed' => [],
                ], 400);
            }

            if (!ctype_digit($thinkingBudget)) {
                return $this->clientError(400, 'Thinking budget must be a whole number of tokens');
            }

            // The API caps thinking and response text against the same
            // max_tokens, so the budget has to stay strictly below it.
            $budget = (int)$thinkingBudget;
            $ceiling = $provider->getMaxTokens($this->userId);
            if ($budget < self::MIN_THINKING_BUDGET || $budget >= $ceiling) {
                return $this->clientError(400, sprintf(
                    'Thinking budget must be between %d and %d tokens',
                    self::MIN_THINKING_BUDGET,
                    $ceiling - 1,
                ));
            }
            $conversation->setThinkingBudget($budget);
        } elseif ($thinkingBudget === '') {
            $conversation->setThinkingBudget(null);
        }

        if ($speedFast !== null) {
            if (!in_array($speedFast, ['on', 'off', ''], true)) {
                return $this->clientError(400, 'Fast mode must be "on", "off" or empty');
            }
            if ($speedFast === 'on') {
                // Fast mode is an Anthropic feature, and only on part of their
                // line-up. Refuse the provider first, then the model, so the
                // message names whichever one is actually in the way.
                $provider = $this->resolveProvider($conversation);
                if (!$provider->getCapabilities()['fast_mode']) {
                    return new JSONResponse([
                        'error' => $provider->getLabel() . ' does not support fast mode',
                        'errorId' => '',
                        'allowed' => [],
                    ], 400);
                }
            }
            $conversation->setSpeedFast($speedFast === '' ? null : $speedFast === 'on');
        }

        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        return new JSONResponse($conversation->jsonSerialize());
    }

    /**
     * Delete a conversation and all its messages and files
     *
     * @param int $id Conversation ID
     *
     * 200: Deletion confirmed
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{deleted: true}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function destroy(int $id): JSONResponse {
        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        // Delete files for each message
        $messages = $this->messageMapper->findByConversation($id);
        foreach ($messages as $msg) {
            $this->messageFileMapper->deleteByMessage($msg->getId());
        }
        $this->messageMapper->deleteByConversation($id);
        $this->conversationMapper->delete($conversation);

        // Drop the conversation from Context Chat (no-op when not installed).
        $this->contextChat->removeConversation($id);

        return new JSONResponse(['deleted' => true]);
    }

    /**
     * Send a message in a conversation and get Claude's response
     *
     * @param int $id Conversation ID
     * @param string $prompt The user's message
     * @param list<string> $files Optional file paths to attach
     *
     * 200: User message and assistant response
     * 400: No prompt provided
     * 403: No provider is permitted for this user
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{userMessage: array<string, mixed>, assistantMessage: array<string, mixed>, conversation: array<string, mixed>}, array{}>|JSONResponse<Http::STATUS_BAD_REQUEST, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>|JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    // Each call bills an LLM request. These are the endpoints the chat UI
    // actually uses — ChatController's limits never covered them.
    #[UserRateLimit(limit: 30, period: 60)]
    #[AnonRateLimit(limit: 30, period: 60)]
    #[OpenAPI]
    public function message(int $id, string $prompt = '', array $files = []): JSONResponse {
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        if (!$prompt && empty($files)) {
            return $this->clientError(400, 'No prompt provided');
        }

        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        $now = time();

        // 1. Create and persist user message
        $userMsg = new MessageEntity();
        $userMsg->setConversationId($id);
        $userMsg->setRole('user');
        $userMsg->setContent($prompt);
        $userMsg->setCreatedAt($now);
        $userMsg = $this->messageMapper->insert($userMsg);

        // 2. Persist attached files
        $fileEntities = [];
        foreach ($files as $filePath) {
            try {
                $info = $this->fileService->getInfo($filePath, $this->requireUserId());
            } catch (\Exception $e) {
                // Store with basic info if lookup fails
                $info = ['name' => basename($filePath), 'mimeType' => 'application/octet-stream'];
            }
            $mf = new MessageFile();
            $mf->setMessageId($userMsg->getId());
            $mf->setFilePath($filePath);
            $mf->setFileName($info['name'] ?? basename($filePath));
            $mf->setMimeType($info['mimeType'] ?? null);
            $mf->setCreatedAt($now);
            $mf = $this->messageFileMapper->insert($mf);
            $fileEntities[] = $mf;
        }

        // 3. Build the messages array from the full conversation history, with
        //    every message's persisted files re-expanded into content blocks.
        //    Otherwise a file only reaches the model on the turn it was sent:
        //    the next turn sees plain prompt text, and the model has lost access
        //    to everything the user attached (the "I can't see that file,
        //    re-attach it" failure mode).
        $documentsIndex = [];
        $claudeMessages = $this->buildHistoryMessages($id, $documentsIndex);

        // 4. Load project system prompt if conversation has a project
        $systemPrompt = null;
        $projectId = $conversation->getProjectId();
        if ($projectId !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($projectId, $this->requireUserId());
                $systemPrompt = $project->getSystemPrompt();

                // Build project context from paths
                $paths = $this->projectPathMapper->findByProject($project->getId());
                if (!empty($paths)) {
                    $contextLines = ['Project: ' . $project->getTitle()];
                    foreach ($paths as $projectPath) {
                        $contextLines[] = '- [' . $projectPath->getPathType() . '] ' . $projectPath->getPath();
                    }
                    $pathContext = implode("\n", $contextLines);
                    $systemPrompt = ($systemPrompt ? $systemPrompt . "\n\n" : '') . $pathContext;
                }
            } catch (DoesNotExistException $e) {
                // Project was deleted, ignore
            }
        }

        if ($this->resolveProvider($conversation)->getCapabilities()['tools']) {
            $systemPrompt = ($systemPrompt !== null && $systemPrompt !== '' ? $systemPrompt . "\n\n" : '') . self::AGENT_SYSTEM_NOTE;
        }

        // 5. Call the provider (with MCP tools if available)
        $startMs = (int)(microtime(true) * 1000.0);
        $options = $this->conversationOptions($conversation);
        $result = $this->callClaude($claudeMessages, $systemPrompt, $options, $conversation);
        $latencyMs = (int)(microtime(true) * 1000.0) - $startMs;

        if (isset($result['error'])) {
            // Persist error as assistant message so user sees it in history
            $assistantMsg = new MessageEntity();
            $assistantMsg->setConversationId($id);
            $assistantMsg->setRole('assistant');
            $assistantMsg->setContent('Error: ' . $result['error']);
            $assistantMsg->setLatencyMs($latencyMs);
            $assistantMsg->setCreatedAt(time());
            $assistantMsg = $this->messageMapper->insert($assistantMsg);

            $conversation->setUpdatedAt(time());
            $this->conversationMapper->update($conversation);

            return new JSONResponse([
                'userMessage' => $this->serializeMessage($userMsg, $fileEntities),
                'assistantMessage' => $assistantMsg->jsonSerialize(),
                'conversation' => $conversation->jsonSerialize(),
            ]);
        }

        // 6. Persist assistant response
        $assistantMsg = new MessageEntity();
        $assistantMsg->setConversationId($id);
        $assistantMsg->setRole('assistant');
        $assistantMsg->setContent($result['response']);
        $assistantMsg->setInputTokens($result['usage']['input_tokens'] ?? null);
        $assistantMsg->setOutputTokens($result['usage']['output_tokens'] ?? null);
        $assistantMsg->setCacheCreationTokens($result['usage']['cache_creation_tokens'] ?? null);
        $assistantMsg->setCacheReadTokens($result['usage']['cache_read_tokens'] ?? null);
        $assistantMsg->setLatencyMs($latencyMs);
        if (!empty($result['citations'])) {
            $assistantMsg->setCitations(json_encode($result['citations']) ?: null);
            if (!empty($documentsIndex)) {
                $assistantMsg->setDocuments(json_encode($documentsIndex) ?: null);
            }
        }
        $assistantMsg->setCreatedAt(time());
        $assistantMsg = $this->messageMapper->insert($assistantMsg);

        // 7. Auto-title: generate from first user message if no title yet
        if ($conversation->getTitle() === null || $conversation->getTitle() === '') {
            $title = mb_substr($prompt, 0, 50);
            if (mb_strlen($prompt) > 50) {
                $title .= '…';
            }
            $conversation->setTitle($title);
        }

        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        $this->queueContextChatIndex($id);

        return new JSONResponse([
            'userMessage' => $this->serializeMessage($userMsg, $fileEntities),
            'assistantMessage' => $assistantMsg->jsonSerialize(),
            'conversation' => $conversation->jsonSerialize(),
        ]);
    }

    /**
     * Streaming variant of message(): sends the same conversation turn but
     * returns an SSE stream so the assistant text reaches the UI as it is
     * generated. Event types yielded over the stream:
     *
     *   user_message   — once at the start: persisted user message + files
     *   text_delta     — assistant text chunk
     *   tool_use       — finalized tool invocation (name + input)
     *   tool_result    — locally-executed tool output
     *   done           — terminal: usage totals + accumulated citations
     *   persisted      — final: persisted assistant message + conversation
     *   error          — terminal on failure (still followed by persisted
     *                    so the user sees what was streamed before the
     *                    error in their conversation history)
     *
     * Same precondition rules and error handling as message().
     *
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    // Each call bills an LLM request. These are the endpoints the chat UI
    // actually uses — ChatController's limits never covered them.
    #[UserRateLimit(limit: 30, period: 60)]
    #[AnonRateLimit(limit: 30, period: 60)]
    #[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
    public function messageStream(int $id, string $prompt = '', array $files = []): Response {
        if (!$prompt && empty($files)) {
            return $this->clientError(400, 'No prompt provided');
        }
        if (!$this->providerFactory->hasPermittedProvider($this->userId)) {
            return $this->noProviderAvailable();
        }
        try {
            $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }
        return new SSEResponse($this->streamConversationReply($id, $prompt, $files));
    }

    /**
     * The generator that drives messageStream(). Persists the user message
     * up front, runs chatWithToolsStream(), accumulates text/citations as
     * events flow through, and persists the assistant message on stream
     * completion (or on error, with whatever text streamed before failure).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function streamConversationReply(int $id, string $prompt, array $files): \Generator {
        $now = time();

        try {
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            yield ['type' => 'error', 'error' => 'Conversation not found'];
            return;
        }

        // 1. Persist user message + files (mirrors message()).
        $userMsg = new MessageEntity();
        $userMsg->setConversationId($id);
        $userMsg->setRole('user');
        $userMsg->setContent($prompt);
        $userMsg->setCreatedAt($now);
        $userMsg = $this->messageMapper->insert($userMsg);

        $fileEntities = [];
        foreach ($files as $filePath) {
            try {
                $info = $this->fileService->getInfo($filePath, $this->requireUserId());
            } catch (\Exception $e) {
                $info = ['name' => basename($filePath), 'mimeType' => 'application/octet-stream'];
            }
            $mf = new MessageFile();
            $mf->setMessageId($userMsg->getId());
            $mf->setFilePath($filePath);
            $mf->setFileName($info['name'] ?? basename($filePath));
            $mf->setMimeType($info['mimeType'] ?? null);
            $mf->setCreatedAt($now);
            $mf = $this->messageFileMapper->insert($mf);
            $fileEntities[] = $mf;
        }

        yield ['type' => 'user_message', 'userMessage' => $this->serializeMessage($userMsg, $fileEntities)];

        // 2. Build messages + system prompt (mirrors message()): history with
        //    every message's persisted files re-expanded into content blocks.
        $documentsIndex = [];
        $claudeMessages = $this->buildHistoryMessages($id, $documentsIndex);

        $systemPrompt = null;
        $projectId = $conversation->getProjectId();
        if ($projectId !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($projectId, $this->requireUserId());
                $systemPrompt = $project->getSystemPrompt();
                $paths = $this->projectPathMapper->findByProject($project->getId());
                if (!empty($paths)) {
                    $contextLines = ['Project: ' . $project->getTitle()];
                    foreach ($paths as $projectPath) {
                        $contextLines[] = '- [' . $projectPath->getPathType() . '] ' . $projectPath->getPath();
                    }
                    $systemPrompt = ($systemPrompt ? $systemPrompt . "\n\n" : '') . implode("\n", $contextLines);
                }
            } catch (DoesNotExistException $e) {
                // Project deleted — proceed without project context.
            }
        }

        // 3. Local agentic loop: PHP dispatches the built-in file tools and
        //    the user's MCP tools per turn.
        $provider = $this->resolveProvider($conversation);
        if ($provider->getCapabilities()['tools']) {
            $systemPrompt = ($systemPrompt !== null && $systemPrompt !== '' ? $systemPrompt . "\n\n" : '') . self::AGENT_SYSTEM_NOTE;
        }
        $built = $this->buildChatTools();
        $tools = $built['tools'];
        $toolExecutor = $built['executor'];

        // 4. Drive the streaming generator, accumulating final state.
        $accumulatedText = '';
        $finalCitations = [];
        $finalUsage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_tokens' => null, 'cache_read_tokens' => null];
        $errorMessage = null;
        $startMs = (int)(microtime(true) * 1000.0);
        $options = $this->conversationOptions($conversation);

        $eventStream = $provider->chatWithToolsStream(
            $claudeMessages,
            $tools,
            $toolExecutor,
            $systemPrompt,
            $this->userId,
            $options,
        );

        // A provider reports a failed turn as an `error` event, but it can also
        // throw outright — a transport error mid-generation, a malformed
        // upstream frame. Both have to end the same way: whatever streamed so
        // far is persisted below and the client still gets `error` followed by
        // `persisted`, rather than a stream that simply stops.
        try {
            foreach ($eventStream as $event) {
                switch ($event['type'] ?? null) {
                    case 'text_delta':
                        $accumulatedText .= $event['text'] ?? '';
                        break;
                    case 'done':
                        $finalCitations = $event['citations'] ?? [];
                        $finalUsage = $event['usage'] ?? $finalUsage;
                        break;
                    case 'error':
                        $errorMessage = $event['error'] ?? 'Stream error';
                        if (isset($event['usage']) && is_array($event['usage'])) {
                            $finalUsage = $event['usage'];
                        }
                        break;
                }
                yield $event;
            }
        } catch (\Throwable $e) {
            $this->logger->error('RequrvHive: conversation stream failed', [
                'conversationId' => $id,
                'exception' => $e,
            ]);
            $errorMessage = $e->getMessage() !== '' ? $e->getMessage() : $e::class;
            yield ['type' => 'error', 'error' => $errorMessage];
        }

        $latencyMs = (int)(microtime(true) * 1000.0) - $startMs;

        // 5. Persist assistant message — even on error, so partial output is preserved.
        $assistantContent = $accumulatedText !== ''
            ? $accumulatedText
            : ($errorMessage !== null ? 'Error: ' . $errorMessage : '');
        if ($errorMessage !== null && $accumulatedText !== '') {
            $assistantContent .= "\n\n_(stream interrupted: " . $errorMessage . ')_';
        }

        $assistantMsg = new MessageEntity();
        $assistantMsg->setConversationId($id);
        $assistantMsg->setRole('assistant');
        $assistantMsg->setContent($assistantContent);
        $assistantMsg->setInputTokens($finalUsage['input_tokens'] ?? null);
        $assistantMsg->setOutputTokens($finalUsage['output_tokens'] ?? null);
        $assistantMsg->setCacheCreationTokens($finalUsage['cache_creation_tokens'] ?? null);
        $assistantMsg->setCacheReadTokens($finalUsage['cache_read_tokens'] ?? null);
        $assistantMsg->setLatencyMs($latencyMs);
        if (!empty($finalCitations)) {
            $assistantMsg->setCitations(json_encode($finalCitations) ?: null);
            if (!empty($documentsIndex)) {
                $assistantMsg->setDocuments(json_encode($documentsIndex) ?: null);
            }
        }
        $assistantMsg->setCreatedAt(time());
        $assistantMsg = $this->messageMapper->insert($assistantMsg);

        // 6. Auto-title (mirrors message()).
        if ($conversation->getTitle() === null || $conversation->getTitle() === '') {
            $title = mb_substr($prompt, 0, 50);
            if (mb_strlen($prompt) > 50) {
                $title .= '…';
            }
            $conversation->setTitle($title);
        }
        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        $this->queueContextChatIndex($id);

        yield [
            'type' => 'persisted',
            'assistantMessage' => $assistantMsg->jsonSerialize(),
            'conversation' => $conversation->jsonSerialize(),
        ];
    }

    /**
     * Duplicate a conversation with all its messages and files
     *
     * @param int $id Conversation ID
     *
     * 200: The duplicated conversation
     * 404: Conversation not found
     *
     * @return JSONResponse<Http::STATUS_OK, array{id: int, userId: string, title: ?string, model: string, provider: ?string, createdAt: int, updatedAt: int, projectId: ?int, effort: ?string, thinking: ?bool, thinkingBudget: ?int, speedFast: ?bool}, array{}>|JSONResponse<Http::STATUS_NOT_FOUND, array{error: string, errorId: string}, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function duplicate(int $id): JSONResponse {
        try {
            $original = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }

        $now = time();

        // Clone conversation
        $newConv = new Conversation();
        $newConv->setUserId($this->requireUserId());
        $newConv->setTitle(($original->getTitle() ?? '') . ' (copy)');
        $newConv->setModel($original->getModel());
        // The copy must answer like the original: carry the pinned provider and
        // the per-conversation overrides, not just the model. A pin the user is
        // no longer permitted to use is dropped rather than copied forward, so
        // the copy follows their current setting instead of carrying a denied
        // provider into a brand-new conversation.
        $originalProvider = $original->getProvider();
        $newConv->setProvider(
            $originalProvider !== null && $this->providerFactory->isAllowedForUser($originalProvider, $this->userId)
                ? $originalProvider
                : null,
        );
        $newConv->setEffort($original->getEffort());
        $newConv->setThinking($original->getThinking());
        $newConv->setProjectId($original->getProjectId());
        $newConv->setCreatedAt($now);
        $newConv->setUpdatedAt($now);
        $newConv = $this->conversationMapper->insert($newConv);

        // Clone messages and their files
        $messages = $this->messageMapper->findByConversation($id);
        foreach ($messages as $msg) {
            $newMsg = new MessageEntity();
            $newMsg->setConversationId($newConv->getId());
            $newMsg->setRole($msg->getRole());
            $newMsg->setContent($msg->getContent());
            $newMsg->setInputTokens($msg->getInputTokens());
            $newMsg->setOutputTokens($msg->getOutputTokens());
            $newMsg->setCacheCreationTokens($msg->getCacheCreationTokens());
            $newMsg->setCacheReadTokens($msg->getCacheReadTokens());
            $newMsg->setLatencyMs($msg->getLatencyMs());
            $newMsg->setCitations($msg->getCitations());
            $newMsg->setDocuments($msg->getDocuments());
            $newMsg->setCreatedAt($msg->getCreatedAt());
            $newMsg = $this->messageMapper->insert($newMsg);

            $files = $this->messageFileMapper->findByMessage($msg->getId());
            foreach ($files as $file) {
                $newFile = new MessageFile();
                $newFile->setMessageId($newMsg->getId());
                $newFile->setFilePath($file->getFilePath());
                $newFile->setFileName($file->getFileName());
                $newFile->setMimeType($file->getMimeType());
                $newFile->setCreatedAt($file->getCreatedAt());
                $this->messageFileMapper->insert($newFile);
            }
        }

        $this->queueContextChatIndex($newConv->getId());

        return new JSONResponse($newConv->jsonSerialize());
    }

    /**
     * Search messages across all conversations
     *
     * @param string $query Search query
     * @param int $limit Max results
     * @param int $cursor Pagination cursor (message ID)
     *
     * 200: Matching messages
     *
     * @return JSONResponse<Http::STATUS_OK, list<array<string, mixed>>, array{}>
     */
    #[NoAdminRequired]
    #[OpenAPI]
    public function search(string $query = '', int $limit = 20, int $cursor = 0): JSONResponse {
        if (trim($query) === '') {
            return new JSONResponse([]);
        }

        $messages = $this->messageMapper->search($this->requireUserId(), $query, $limit, $cursor);
        $result = [];
        foreach ($messages as $msg) {
            $data = $msg->jsonSerialize();
            try {
                $conv = $this->conversationMapper->findByIdAndUser($msg->getConversationId(), $this->requireUserId());
                $data['conversationTitle'] = $conv->getTitle();
            } catch (DoesNotExistException $e) {
                $data['conversationTitle'] = null;
            }
            $result[] = $data;
        }

        return new JSONResponse($result);
    }

    /**
     * The conversation history as provider messages, with every message's
     * persisted files re-expanded into content blocks. Without this a file
     * only reaches the model on the turn it was sent: the next turn sees
     * plain prompt text and the model has lost access to everything the user
     * previously attached.
     *
     * Fills $documentsIndex with one entry per document block in the order
     * the provider sees them across the whole history — citation
     * `document_index` values resolve against it.
     *
     * @param array<int, array<string, mixed>> $documentsIndex
     * @return array<int, array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    private function buildHistoryMessages(int $conversationId, array &$documentsIndex): array {
        $messages = [];
        foreach ($this->messageMapper->findByConversation($conversationId) as $msg) {
            $filePaths = array_map(
                static fn(MessageFile $f): string => $f->getFilePath(),
                $this->messageFileMapper->findByMessage($msg->getId())
            );

            if ($filePaths === []) {
                $messages[] = ['role' => $msg->getRole(), 'content' => $msg->getContent()];
                continue;
            }

            $built = $this->buildFileContentBlocks($filePaths);
            $messages[] = [
                'role' => $msg->getRole(),
                'content' => $msg->getContent() === ''
                    ? $built['blocks']
                    : array_merge($built['blocks'], [['type' => 'text', 'text' => $msg->getContent()]]),
            ];

            // The per-call document indices restart at 0 for each message;
            // renumber them against the whole history.
            foreach ($built['documents'] as $document) {
                $document['index'] = count($documentsIndex);
                $documentsIndex[] = $document;
            }
        }
        return $messages;
    }

    /**
     * Build Anthropic content blocks for the given Nextcloud file paths.
     *
     * A file expands to one block (image, PDF document, or text carrying its
     * content). A directory expands to a tree header plus one block per file
     * it contains, so the model can answer about any file inside it without
     * the user attaching each one individually.
     *
     * Returns the blocks alongside a documents index — one entry per `type:document`
     * block in the order Anthropic sees them. The documents index is what citation
     * `document_index` values resolve against, so the frontend can map a citation
     * back to a Nextcloud file path and open it.
     *
     * @param string[] $files
     * @return array{blocks: array<int, array<string, mixed>>, documents: array<int, array{index:int,path:string,title:string,mimeType:string,fileId?:string}>}
     */
    private function buildFileContentBlocks(array $files): array {
        $blocks = [];
        $documents = [];
        foreach ($files as $filePath) {
            try {
                $info = $this->fileService->getInfo($filePath, $this->requireUserId());
            } catch (\Exception $e) {
                // This text is sent to the LLM provider, so an exception message
                // here would ship server paths off-instance. Log it, tell the
                // model only that the file was unreadable.
                $this->logger->warning(
                    'Could not attach file to conversation',
                    ['exception' => $e, 'file' => basename($filePath)],
                );
                $blocks[] = [
                    'type' => 'text',
                    'text' => "--- File: " . basename($filePath) . " (could not be read) ---",
                ];
                continue;
            }

            if ($info['type'] === 'folder') {
                $this->buildDirectoryBlocks($info['name'], $filePath, $blocks, $documents);
                continue;
            }

            $built = $this->buildFileBlock($filePath);
            $this->appendBlock($blocks, $documents, $built['block'], $built['document']);
        }
        return ['blocks' => $blocks, 'documents' => $documents];
    }

    /**
     * The content block for a single file, plus its documents-index entry when
     * the block is a PDF document. Unreadable files degrade to a text note
     * rather than failing the whole message.
     *
     * @param string|null $displayName Shown in the block header; defaults to the file name
     * @return array{block: array<string, mixed>, document: array{path: string, title: string, mimeType: string}|null}
     */
    private function buildFileBlock(string $filePath, ?string $displayName = null): array {
        try {
            $fileData = $this->fileService->getContent($filePath, $this->requireUserId());
            return $this->buildFileBlockFromData($fileData, $filePath, $displayName ?? $fileData['name']);
        } catch (\Exception $e) {
            // This text is sent to the LLM provider, so an exception message
            // here would ship server paths off-instance. Log it, tell the
            // model only that the file was unreadable.
            $this->logger->warning(
                'Could not attach file to conversation',
                ['exception' => $e, 'file' => basename($filePath)],
            );
            return [
                'block' => [
                    'type' => 'text',
                    'text' => "--- File: " . ($displayName ?? basename($filePath)) . " (could not be read) ---",
                ],
                'document' => null,
            ];
        }
    }

    /**
     * Build the content block from already-loaded file data. Throws when the
     * content cannot be turned into a block (e.g. an image the optimizer
     * rejects); callers decide how a failure degrades.
     *
     * @param array{name: string, mimeType: string, size: int, encoding: string, content: string} $fileData
     * @return array{block: array<string, mixed>, document: array{path: string, title: string, mimeType: string}|null}
     */
    private function buildFileBlockFromData(array $fileData, string $filePath, string $displayName): array {
        $mimeType = $fileData['mimeType'];

        if (str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType)) {
            $optimized = $this->imageOptimizer->optimize(
                base64_decode($fileData['content']),
                $mimeType
            );
            return [
                'block' => [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $optimized['mimeType'],
                        'data' => $optimized['data'],
                    ],
                ],
                'document' => null,
            ];
        }

        if ($mimeType === 'application/pdf') {
            return [
                'block' => [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $fileData['content'],
                    ],
                    'title' => $displayName,
                    'citations' => ['enabled' => true],
                ],
                'document' => [
                    'path' => $filePath,
                    'title' => $displayName,
                    'mimeType' => 'application/pdf',
                ],
            ];
        }

        return [
            'block' => [
                'type' => 'text',
                'text' => "--- File: {$displayName} ({$mimeType}, {$fileData['size']} bytes) ---\n{$fileData['content']}",
            ],
            'document' => null,
        ];
    }

    /**
     * Append a content block and, for PDF document blocks, its documents-index
     * entry. The index must mirror the order in which Anthropic sees the
     * document blocks, so it is assigned here, not by the caller.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array{index: int, path: string, title: string, mimeType: string, fileId?: string}> $documents
     * @param array<string, mixed> $block
     * @param array{path: string, title: string, mimeType: string, fileId?: string}|null $document
     */
    private function appendBlock(array &$blocks, array &$documents, array $block, ?array $document): void {
        $blocks[] = $block;
        if ($document !== null) {
            $document['index'] = count($documents);
            $documents[] = $document;
        }
    }

    /**
     * Expand an attached directory into content blocks for the files it
     * contains, so the model can answer about any of them without the user
     * attaching each one individually.
     *
     * The first block carries the full file tree, so the model sees everything
     * in the directory even when a file is not read (binary, too large, or a
     * cap reached). Files are then included as their own blocks, subject to
     * the MAX_DIRECTORY_* caps.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, array{index: int, path: string, title: string, mimeType: string, fileId?: string}> $documents
     */
    private function buildDirectoryBlocks(string $name, string $directoryPath, array &$blocks, array &$documents): void {
        $treeLines = [];
        $entries = [];
        $skipped = [];
        $counts = ['nodes' => 0, 'files' => 0, 'textBytes' => 0, 'images' => 0, 'documents' => 0];

        $this->walkDirectory($directoryPath, '', 0, $treeLines, $entries, $skipped, $counts);

        $included = count($entries);
        $header = '--- Directory: ' . $name . ' (' . $counts['files'] . ' files, ' . $included . ' included as content) ---';
        $text = $treeLines === [] ? $header : $header . "\n" . implode("\n", $treeLines);
        if ($skipped !== []) {
            $text .= "\nNot read: " . implode(', ', $skipped) . ' (binary, too large, or a directory cap was reached)';
        }
        $this->appendBlock($blocks, $documents, ['type' => 'text', 'text' => $text], null);

        foreach ($entries as $entry) {
            $this->appendBlock($blocks, $documents, $entry['block'], $entry['document']);
        }
    }

    /**
     * Recursively collect the tree of an attached directory and the content
     * blocks for its readable files. $relPath is the folder's path relative to
     * the attached directory ('' for the directory itself).
     *
     * @param list<string> $treeLines
     * @param list<array{block: array<string, mixed>, document: array{path: string, title: string, mimeType: string}|null}> $entries
     * @param list<string> $skipped
     * @param array{nodes: int, files: int, textBytes: int, images: int, documents: int} $counts
     */
    private function walkDirectory(
        string $folderPath,
        string $relPath,
        int $depth,
        array &$treeLines,
        array &$entries,
        array &$skipped,
        array &$counts
    ): void {
        if ($depth >= self::MAX_DIRECTORY_DEPTH) {
            $treeLines[] = str_repeat('  ', $depth) . '(max depth reached, not listed further)';
            return;
        }

        try {
            $listing = $this->fileService->listDirectory($folderPath, $this->requireUserId());
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not list directory in attached folder',
                ['exception' => $e, 'directory' => basename($folderPath)],
            );
            $treeLines[] = str_repeat('  ', $depth)
                . ($relPath === '' ? '' : basename($relPath) . '/ ')
                . '(could not be listed)';
            return;
        }

        $items = $listing['items'];
        usort($items, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

        foreach ($items as $item) {
            $indent = str_repeat('  ', $depth);
            if ($counts['nodes'] >= self::MAX_DIRECTORY_NODES) {
                $treeLines[] = $indent . '(listing truncated at ' . self::MAX_DIRECTORY_NODES . ' entries)';
                return;
            }
            $counts['nodes']++;
            $childPath = $folderPath . '/' . $item['name'];
            $childRel = $relPath === '' ? $item['name'] : $relPath . '/' . $item['name'];
            if ($item['type'] === 'folder') {
                $treeLines[] = $indent . $item['name'] . '/';
                $this->walkDirectory($childPath, $childRel, $depth + 1, $treeLines, $entries, $skipped, $counts);
                continue;
            }

            $counts['files']++;
            $treeLines[] = $indent . $item['name'] . ' (' . $item['size'] . ' bytes)';
            $this->includeDirectoryFile($childPath, $childRel, $entries, $skipped, $counts);
        }
    }

    /**
     * Add the content block for one file inside an attached directory, or
     * record it as skipped when its format, size or a directory cap gets in
     * the way. Skipped files stay in the tree, so the model still knows they
     * exist and where.
     *
     * @param list<array{block: array<string, mixed>, document: array{path: string, title: string, mimeType: string}|null}> $entries
     * @param list<string> $skipped
     * @param array{nodes: int, files: int, textBytes: int, images: int, documents: int} $counts
     */
    private function includeDirectoryFile(
        string $filePath,
        string $relPath,
        array &$entries,
        array &$skipped,
        array &$counts
    ): void {
        if ($counts['files'] > self::MAX_DIRECTORY_FILES) {
            $skipped[] = $relPath;
            return;
        }

        try {
            $fileData = $this->fileService->getContent($filePath, $this->requireUserId());
        } catch (\Exception $e) {
            // Unreadable or over the per-file read limit: the tree still shows it.
            $skipped[] = $relPath;
            return;
        }

        $mimeType = $fileData['mimeType'];
        $isImage = str_starts_with($mimeType, 'image/') && $this->imageOptimizer->isSupported($mimeType);

        if ($isImage) {
            if ($counts['images'] >= ImageOptimizer::MAX_IMAGES) {
                $skipped[] = $relPath;
                return;
            }
        } elseif ($mimeType === 'application/pdf') {
            if ($counts['documents'] >= self::MAX_DIRECTORY_DOCUMENTS) {
                $skipped[] = $relPath;
                return;
            }
        } elseif ($fileData['encoding'] === 'text') {
            if ($counts['textBytes'] + $fileData['size'] > self::MAX_DIRECTORY_TEXT_BYTES) {
                $skipped[] = $relPath;
                return;
            }
        } else {
            // Binary content would arrive as base64 text: too opaque to be
            // useful, so leave it in the tree only.
            $skipped[] = $relPath;
            return;
        }

        try {
            $built = $this->buildFileBlockFromData($fileData, $filePath, $relPath);
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not include file from attached directory',
                ['exception' => $e, 'file' => $relPath],
            );
            $skipped[] = $relPath;
            return;
        }

        if ($isImage) {
            $counts['images']++;
        } elseif ($mimeType === 'application/pdf') {
            $counts['documents']++;
        } else {
            $counts['textBytes'] += $fileData['size'];
        }

        $entries[] = ['block' => $built['block'], 'document' => $built['document']];
    }

    /**
     * @return JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    private function forbiddenProvider(string $providerId): JSONResponse {
        return $this->clientError(
            Http::STATUS_FORBIDDEN,
            'You are not permitted to use this provider: ' . $providerId,
        );
    }

    /**
     * @return JSONResponse<Http::STATUS_FORBIDDEN, array{error: string, errorId: string}, array{}>
     */
    private function noProviderAvailable(): JSONResponse {
        return $this->clientError(
            Http::STATUS_FORBIDDEN,
            NoPermittedProviderException::USER_MESSAGE,
        );
    }

    /**
     * The provider that should serve a conversation.
     *
     * A pinned provider wins; null falls back to the user's current setting,
     * which is what every pre-existing conversation does. getProviderForUser()
     * degrades rather than fails: a pin that later stops being registered — or
     * that the admin has since blocked for this user — falls back to the user's
     * current provider instead of continuing to be honoured.
     *
     * @throws NoPermittedProviderException when every provider is blocked
     */
    private function resolveProvider(Conversation $conversation): LLMProviderInterface {
        return $this->providerFactory->getProviderForUser($this->userId, $conversation->getProvider());
    }

    /**
     * Per-conversation request options (effort / thinking / fast-mode overrides).
     * Unset overrides are omitted so provider-side defaults apply.
     */
    private function conversationOptions(Conversation $conversation): array {
        $options = [];
        // Only a pinned conversation overrides the model. An unpinned one
        // follows the user's setting for both provider and model, so passing
        // its snapshotted model would send e.g. a Claude model id to whichever
        // provider the user switched to.
        if ($conversation->getProvider() !== null && $conversation->getModel() !== '') {
            $options['model'] = $conversation->getModel();
        }
        if ($conversation->getEffort() !== null) {
            $options['effort'] = $conversation->getEffort();
        }
        if ($conversation->getThinking() !== null) {
            $options['thinking'] = $conversation->getThinking();
        }
        if ($conversation->getThinkingBudget() !== null) {
            $options['thinking_budget'] = $conversation->getThinkingBudget();
        }
        if ($conversation->getSpeedFast() !== null) {
            $options['speed'] = $conversation->getSpeedFast();
        }
        return $options;
    }

    /**
     * The tool set for one chat turn: the built-in file tools plus the tools
     * of the user's enabled MCP servers, and a single executor that dispatches
     * each call to whichever of the two owns the name.
     *
     * A built-in name always wins: an MCP tool that collides with one of them
     * is exposed under an `mcp__<name>` alias rather than shadowing it, so the
     * file tools stay available under the names the model was told about.
     *
     * @return array{tools: list<array<string, mixed>>, executor: callable(string, array<string, mixed>): array}
     */
    private function buildChatTools(): array {
        $tools = $this->chatTools->getTools();
        $builtinNames = [];
        foreach ($tools as $tool) {
            $builtinNames[(string)$tool['name']] = true;
        }

        try {
            $allTools = $this->mcpClient->getAllTools();
        } catch (\Throwable $e) {
            // A dead MCP server must not take the built-in tools down with it.
            $allTools = ['tools' => [], 'mapping' => []];
        }

        $mapping = [];
        foreach ($allTools['tools'] ?? [] as $tool) {
            $originalName = (string)($tool['name'] ?? '');
            if ($originalName === '') {
                continue;
            }
            $exposed = isset($builtinNames[$originalName]) ? 'mcp__' . $originalName : $originalName;
            $tools[] = [
                'name' => $exposed,
                'description' => (string)($tool['description'] ?? ''),
                'input_schema' => $tool['input_schema'] ?? ['type' => 'object'],
            ];
            $mapping[$exposed] = $allTools['mapping'][$originalName] ?? null;
        }

        $chatTools = $this->chatTools;
        $mcpClient = $this->mcpClient;
        $userId = $this->requireUserId();
        $executor = static function (string $name, array $input) use ($chatTools, $mcpClient, $mapping, $userId): array {
            if ($chatTools->isBuiltin($name)) {
                return $chatTools->execute($name, $input, $userId);
            }
            return $mcpClient->executeTool($name, $input, $mapping);
        };

        return ['tools' => $tools, 'executor' => $executor];
    }

    /**
     * Call the conversation's LLM provider, with the agentic file tools and
     * the user's MCP tools on every turn.
     */
    private function callClaude(array $messages, ?string $systemPrompt = null, array $options = [], ?Conversation $conversation = null): array {
        $provider = $conversation !== null
            ? $this->resolveProvider($conversation)
            : $this->providerFactory->getProvider($this->userId);

        if (!$provider->getCapabilities()['tools']) {
            return $provider->chat($messages, $systemPrompt, $this->userId, $options);
        }

        $built = $this->buildChatTools();
        return $provider->chatWithTools(
            $messages,
            $built['tools'],
            $built['executor'],
            $systemPrompt,
            $this->userId,
            $options,
        );
    }

    /**
     * Serialize a message entity with its file entities
     */
    private function serializeMessage(MessageEntity $msg, array $files): array {
        $data = $msg->jsonSerialize();
        $data['files'] = array_map(fn(MessageFile $f) => $f->jsonSerialize(), $files);
        return $data;
    }
}
