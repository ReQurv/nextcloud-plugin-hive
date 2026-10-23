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
use OCA\RequrvHive\Http\SSEResponse;
use OCA\RequrvHive\Service\ConversationChatService;
use OCA\RequrvHive\Service\ContextChatService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class ConversationController extends Controller {
    use RequiresUserIdTrait;
    use ErrorResponseTrait;

    private const MIN_THINKING_BUDGET = 1024;

    private ConversationMapper $conversationMapper;
    private MessageMapper $messageMapper;
    private MessageFileMapper $messageFileMapper;
    private LLMProviderFactory $providerFactory;
    private ConversationChatService $chatService;
    private ContextChatService $contextChat;
    private ?string $userId;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ConversationMapper $conversationMapper,
        MessageMapper $messageMapper,
        MessageFileMapper $messageFileMapper,
        LLMProviderFactory $providerFactory,
        ConversationChatService $chatService,
        ContextChatService $contextChat,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        $this->logger = $logger;
        $this->conversationMapper = $conversationMapper;
        $this->messageMapper = $messageMapper;
        $this->messageFileMapper = $messageFileMapper;
        $this->providerFactory = $providerFactory;
        $this->chatService = $chatService;
        $this->contextChat = $contextChat;
        $this->userId = $userId;
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
        // Validate before anything else: getProviderById() falls back to the
        // default provider for an unknown id, which is right for a stale config
        // value but would let an arbitrary request string reach persistence unnoticed.
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
                $model = $this->chatService->resolveProvider($conversation, $this->requireUserId())->getModel($this->userId);
            }

            // The pinned effort belongs to the old provider's vocabulary.
            if (!$this->chatService->resolveProvider($conversation, $this->requireUserId())->getCapabilities()['effort']) {
                $conversation->setEffort(null);
            }

            // Likewise the budget, which is meaningless without thinking.
            if (!$this->chatService->resolveProvider($conversation, $this->requireUserId())->getCapabilities()['thinking']) {
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
            // Effort vocabularies differ per provider, so validate against the
            // provider that owns the conversation rather than a hardcoded table.
            $provider = $this->chatService->resolveProvider($conversation, $this->requireUserId());
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
            // Like effort, not a universal concept — validate against the
            // provider that owns the conversation.
            $provider = $this->chatService->resolveProvider($conversation, $this->requireUserId());
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
                // Fast mode is a provider-specific feature, and only on part of
                // any provider's line-up. Refuse the provider first, then the
                // model, so the message names whichever one is actually in the way.
                $provider = $this->chatService->resolveProvider($conversation, $this->requireUserId());
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
     * Send a message in a conversation and get the model's response
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

        $result = $this->chatService->send($conversation, $prompt, $files, $this->requireUserId());

        return new JSONResponse([
            'userMessage' => $this->chatService->serializeMessage($result['userMessage'], $result['files']),
            'assistantMessage' => $result['assistantMessage']->jsonSerialize(),
            'conversation' => $result['conversation']->jsonSerialize(),
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
     * @param list<string> $files File paths to attach
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
            $conversation = $this->conversationMapper->findByIdAndUser($id, $this->requireUserId());
        } catch (DoesNotExistException $e) {
            return $this->clientError(404, 'Conversation not found');
        }
        return new SSEResponse($this->chatService->stream($conversation, $prompt, $files, $this->requireUserId()));
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

        $this->chatService->queueContextChatIndex($newConv->getId());

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
}
