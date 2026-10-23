<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service;

use OCA\RequrvHive\BackgroundJob\IndexConversationJob;
use OCA\RequrvHive\Db\Conversation;
use OCA\RequrvHive\Db\ConversationMapper;
use OCA\RequrvHive\Db\Message as MessageEntity;
use OCA\RequrvHive\Db\MessageFile;
use OCA\RequrvHive\Db\MessageFileMapper;
use OCA\RequrvHive\Db\MessageMapper;
use OCA\RequrvHive\Db\ProjectMapper;
use OCA\RequrvHive\Db\ProjectPathMapper;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use OCA\RequrvHive\Service\Provider\NoPermittedProviderException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * The conversation chat engine, independent of any HTTP request.
 *
 * Runs one turn of a persisted conversation: persist the user message and its
 * attached files, rebuild the full history (with every message's files
 * re-expanded into content blocks), resolve the provider and options, call the
 * model with the agentic file tools and the user's MCP tools, and persist the
 * assistant response. `send()` is the blocking variant; `stream()` is the
 * same turn as a generator of SSE-ready events.
 *
 * Controllers and background jobs alike call in with the acting user's id:
 * provider resolution, file access and the tool executor all run as that user.
 */
class ConversationChatService {
    /**
     * Appended to every conversation turn so the model knows it is an agent
     * over the user's files, not a blind chatbot: when a question the user's
     * storage can answer arrives, it is expected to go find the answer with
     * the file tools instead of guessing or asking for an attachment.
     */
    public const AGENT_SYSTEM_NOTE = "You are connected to the user's Nextcloud files. When a request can be answered from the user's files or folders, do not guess and do not ask them to attach anything: locate it yourself with your file tools (search_files to find it, list_directory to inspect a folder, get_file_info for metadata, read_file for text content, read_image to view a picture) and answer from what you actually read. Paths are relative to the root of the user's files; '/' is the root. Only read what the request needs.";

    /**
     * Caps for a directory attached to a message. The directory expands to the
     * content of the files inside it, so a big tree cannot blow up the context
     * window or the request cost: at most this many files are read, recursion
     * stops at this depth, readable text is bounded in total, and image/PDF
     * blocks are bounded per request.
     */
    public const MAX_DIRECTORY_FILES = 100;
    public const MAX_DIRECTORY_DEPTH = 16;
    public const MAX_DIRECTORY_TEXT_BYTES = 1024 * 1024; // 1MB of combined text
    public const MAX_DIRECTORY_DOCUMENTS = 5;
    public const MAX_DIRECTORY_NODES = 1000; // total entries (files + folders) walked

    public function __construct(
        private ConversationMapper $conversationMapper,
        private MessageMapper $messageMapper,
        private MessageFileMapper $messageFileMapper,
        private ProjectMapper $projectMapper,
        private ProjectPathMapper $projectPathMapper,
        private LLMProviderFactory $providerFactory,
        private FileService $fileService,
        private ImageOptimizer $imageOptimizer,
        private McpClientService $mcpClient,
        private ChatToolsService $chatTools,
        private IJobList $jobList,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run one blocking conversation turn.
     *
     * Persists the user message and its files, calls the model with the full
     * history, and persists the assistant response — including a model error,
     * which is stored as an assistant message so it shows up in the history.
     * Auto-titles the conversation on its first turn and queues the best-effort
     * Context Chat re-index.
     *
     * @param list<string> $files File paths to attach to the user message
     * @return array{userMessage: MessageEntity, files: list<MessageFile>, assistantMessage: MessageEntity, conversation: Conversation, modelError: string|null}
     */
    public function send(Conversation $conversation, string $prompt, array $files, string $userId): array {
        $id = $conversation->getId();
        $now = time();

        // 1. Create and persist user message
        $userMsg = new MessageEntity();
        $userMsg->setConversationId($id);
        $userMsg->setRole('user');
        $userMsg->setContent($prompt);
        $userMsg->setCreatedAt($now);
        $userMsg = $this->messageMapper->insert($userMsg);

        // 2. Persist attached files
        $fileEntities = $this->persistMessageFiles($userMsg->getId(), $files, $userId, $now);

        // 3. Build the messages array from the full conversation history, with
        //    every message's persisted files re-expanded into content blocks.
        //    Otherwise a file only reaches the model on the turn it was sent:
        //    the next turn sees plain prompt text, and the model has lost access
        //    to everything the user attached (the "I can't see that file,
        //    re-attach it" failure mode).
        $documentsIndex = [];
        $messages = $this->buildHistoryMessages($id, $documentsIndex, $userId);

        // 4. System prompt: the project's prompt (if any) plus the agent note
        //    when the provider supports tools.
        $systemPrompt = $this->buildSystemPrompt($conversation, $userId);

        // 5. Call the provider (with MCP tools if available)
        $startMs = (int)(microtime(true) * 1000.0);
        $options = $this->conversationOptions($conversation);
        $result = $this->callModel($messages, $systemPrompt, $options, $conversation, $userId);
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

            return [
                'userMessage' => $userMsg,
                'files' => $fileEntities,
                'assistantMessage' => $assistantMsg,
                'conversation' => $conversation,
                'modelError' => (string)$result['error'],
            ];
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
            $conversation->setTitle($this->autoTitle($prompt));
        }

        $conversation->setUpdatedAt(time());
        $this->conversationMapper->update($conversation);

        $this->queueContextChatIndex($id);

        return [
            'userMessage' => $userMsg,
            'files' => $fileEntities,
            'assistantMessage' => $assistantMsg,
            'conversation' => $conversation,
            'modelError' => null,
        ];
    }

    /**
     * The streaming variant of send(): the same conversation turn, but the
     * assistant text reaches the caller as it is generated. Event types
     * yielded by the generator:
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
     * The user message and its files are persisted up front, and the assistant
     * message on completion (or on error, with whatever text streamed before
     * failure) — mirroring send().
     *
     * @param list<string> $files File paths to attach to the user message
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(Conversation $conversation, string $prompt, array $files, string $userId): \Generator {
        $id = $conversation->getId();
        $now = time();

        // 1. Persist user message + files (mirrors send()).
        $userMsg = new MessageEntity();
        $userMsg->setConversationId($id);
        $userMsg->setRole('user');
        $userMsg->setContent($prompt);
        $userMsg->setCreatedAt($now);
        $userMsg = $this->messageMapper->insert($userMsg);

        $fileEntities = $this->persistMessageFiles($userMsg->getId(), $files, $userId, $now);

        yield ['type' => 'user_message', 'userMessage' => $this->serializeMessage($userMsg, $fileEntities)];

        // 2. Build messages + system prompt (mirrors send()): history with
        //    every message's persisted files re-expanded into content blocks.
        $documentsIndex = [];
        $messages = $this->buildHistoryMessages($id, $documentsIndex, $userId);
        $systemPrompt = $this->buildSystemPrompt($conversation, $userId);

        // 3. Local agentic loop: PHP dispatches the built-in file tools and
        //    the user's MCP tools per turn.
        $provider = $this->resolveProvider($conversation, $userId);
        $built = $this->buildChatTools($userId);
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
            $messages,
            $tools,
            $toolExecutor,
            $systemPrompt,
            $userId,
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

        // 6. Auto-title (mirrors send()).
        if ($conversation->getTitle() === null || $conversation->getTitle() === '') {
            $conversation->setTitle($this->autoTitle($prompt));
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
     * Queue a best-effort Context Chat re-index for a conversation, off the
     * request path. No-op downstream when Context Chat is not installed.
     */
    public function queueContextChatIndex(int $conversationId): void {
        $this->jobList->add(IndexConversationJob::class, ['id' => $conversationId]);
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
    public function resolveProvider(Conversation $conversation, ?string $userId): LLMProviderInterface {
        return $this->providerFactory->getProviderForUser($userId, $conversation->getProvider());
    }

    /**
     * Serialize a message entity with its file entities
     *
     * @param list<MessageFile> $files
     * @return array<string, mixed>
     */
    public function serializeMessage(MessageEntity $msg, array $files): array {
        $data = $msg->jsonSerialize();
        $data['files'] = array_map(fn(MessageFile $f) => $f->jsonSerialize(), $files);
        return $data;
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
    public function buildHistoryMessages(int $conversationId, array &$documentsIndex, string $userId): array {
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

            $built = $this->buildFileContentBlocks($filePaths, $userId);
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
     * @param list<string> $files
     * @return array{blocks: array<int, array<string, mixed>>, documents: array<int, array{index:int,path:string,title:string,mimeType:string,fileId?:string}>}
     */
    public function buildFileContentBlocks(array $files, string $userId): array {
        $blocks = [];
        $documents = [];
        foreach ($files as $filePath) {
            try {
                $info = $this->fileService->getInfo($filePath, $userId);
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
                $this->buildDirectoryBlocks($info['name'], $filePath, $blocks, $documents, $userId);
                continue;
            }

            $built = $this->buildFileBlock($filePath, null, $userId);
            $this->appendBlock($blocks, $documents, $built['block'], $built['document']);
        }
        return ['blocks' => $blocks, 'documents' => $documents];
    }

    /**
     * The system prompt for one conversation turn: the linked project's prompt
     * and path context, plus the agent note when the provider serves tools.
     * Null when the conversation has no project and the provider has no tools.
     */
    private function buildSystemPrompt(Conversation $conversation, string $userId): ?string {
        $systemPrompt = null;
        $projectId = $conversation->getProjectId();
        if ($projectId !== null) {
            try {
                $project = $this->projectMapper->findByIdAndUser($projectId, $userId);
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

        if ($this->resolveProvider($conversation, $userId)->getCapabilities()['tools']) {
            $systemPrompt = ($systemPrompt !== null && $systemPrompt !== '' ? $systemPrompt . "\n\n" : '') . self::AGENT_SYSTEM_NOTE;
        }

        return $systemPrompt;
    }

    /**
     * Per-conversation request options (effort / thinking / fast-mode overrides).
     * Unset overrides are omitted so provider-side defaults apply.
     *
     * @return array{model?: string, effort?: string, thinking?: bool, thinking_budget?: int, speed?: bool}
     */
    private function conversationOptions(Conversation $conversation): array {
        $options = [];
        // Only a pinned conversation overrides the model. An unpinned one
        // follows the user's setting for both provider and model, so passing
        // its snapshotted model would send a stale model id to whichever
        // provider the user switched to.
        if ($conversation->getProvider() !== null && $conversation->getModel() !== '') {
            $options['model'] = $conversation->getModel();
        }
        $effort = $conversation->getEffort();
        if ($effort !== null) {
            $options['effort'] = $effort;
        }
        $thinking = $conversation->getThinking();
        if ($thinking !== null) {
            $options['thinking'] = $thinking;
        }
        $thinkingBudget = $conversation->getThinkingBudget();
        if ($thinkingBudget !== null) {
            $options['thinking_budget'] = $thinkingBudget;
        }
        $speedFast = $conversation->getSpeedFast();
        if ($speedFast !== null) {
            $options['speed'] = $speedFast;
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
    private function buildChatTools(string $userId): array {
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
     *
     * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
     * @param array<string, string|bool|int> $options
     */
    private function callModel(array $messages, ?string $systemPrompt = null, array $options = [], ?Conversation $conversation = null, ?string $userId = null): array {
        $provider = $conversation !== null
            ? $this->resolveProvider($conversation, $userId)
            : $this->providerFactory->getProvider($userId);

        if (!$provider->getCapabilities()['tools']) {
            return $provider->chat($messages, $systemPrompt, $userId, $options);
        }

        $built = $this->buildChatTools((string)$userId);
        return $provider->chatWithTools(
            $messages,
            $built['tools'],
            $built['executor'],
            $systemPrompt,
            $userId,
            $options,
        );
    }

    /**
     * Persist the files attached to a freshly inserted message.
     *
     * @param list<string> $files
     * @return list<MessageFile>
     */
    private function persistMessageFiles(int $messageId, array $files, string $userId, int $now): array {
        $fileEntities = [];
        foreach ($files as $filePath) {
            try {
                $info = $this->fileService->getInfo($filePath, $userId);
            } catch (\Exception $e) {
                // Store with basic info if lookup fails
                $info = ['name' => basename($filePath), 'mimeType' => 'application/octet-stream'];
            }
            $mf = new MessageFile();
            $mf->setMessageId($messageId);
            $mf->setFilePath($filePath);
            $mf->setFileName($info['name'] ?? basename($filePath));
            $mf->setMimeType($info['mimeType'] ?? null);
            $mf->setCreatedAt($now);
            $mf = $this->messageFileMapper->insert($mf);
            $fileEntities[] = $mf;
        }
        return $fileEntities;
    }

    /**
     * The content block for a single file, plus its documents-index entry when
     * the block is a PDF document. Unreadable files degrade to a text note
     * rather than failing the whole message.
     *
     * @param string|null $displayName Shown in the block header; defaults to the file name
     * @return array{block: array<string, mixed>, document: array{path: string, title: string, mimeType: string}|null}
     */
    private function buildFileBlock(string $filePath, ?string $displayName, string $userId): array {
        try {
            $fileData = $this->fileService->getContent($filePath, $userId);
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
    private function buildDirectoryBlocks(string $name, string $directoryPath, array &$blocks, array &$documents, string $userId): void {
        $treeLines = [];
        $entries = [];
        $skipped = [];
        $counts = ['nodes' => 0, 'files' => 0, 'textBytes' => 0, 'images' => 0, 'documents' => 0];

        $this->walkDirectory($directoryPath, '', 0, $treeLines, $entries, $skipped, $counts, $userId);

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
        array &$counts,
        string $userId
    ): void {
        if ($depth >= self::MAX_DIRECTORY_DEPTH) {
            $treeLines[] = str_repeat('  ', $depth) . '(max depth reached, not listed further)';
            return;
        }

        try {
            $listing = $this->fileService->listDirectory($folderPath, $userId);
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
                $this->walkDirectory($childPath, $childRel, $depth + 1, $treeLines, $entries, $skipped, $counts, $userId);
                continue;
            }

            $counts['files']++;
            $treeLines[] = $indent . $item['name'] . ' (' . $item['size'] . ' bytes)';
            $this->includeDirectoryFile($childPath, $childRel, $entries, $skipped, $counts, $userId);
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
        array &$counts,
        string $userId
    ): void {
        if ($counts['files'] > self::MAX_DIRECTORY_FILES) {
            $skipped[] = $relPath;
            return;
        }

        try {
            $fileData = $this->fileService->getContent($filePath, $userId);
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
     * The conversation title for its first turn: the user's prompt, clipped.
     */
    private function autoTitle(string $prompt): string {
        $title = mb_substr($prompt, 0, 50);
        if (mb_strlen($prompt) > 50) {
            $title .= '…';
        }
        return $title;
    }
}
