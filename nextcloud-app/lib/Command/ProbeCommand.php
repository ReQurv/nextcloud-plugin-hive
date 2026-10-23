<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Command;

use OC\Core\Command\Base;
use OCA\RequrvHive\Service\ChatToolsService;
use OCA\RequrvHive\Service\Provider\LLMProviderFactory;
use OCA\RequrvHive\Service\Provider\LLMProviderInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Live, authenticated health check against the active LLM provider.
 *
 *   occ requrvhive:probe            # GET /models (no request body)
 *   occ requrvhive:probe --chat     # + a minimal POST /chat/completions
 *
 * Distinguishes a dead backend/model (both calls 5xx) from a failure that only
 * bites the heavy agentic payload (models OK, chat failing).
 */
class ProbeCommand extends Base {
    public function __construct(
        private LLMProviderFactory $providerFactory,
        private ChatToolsService $chatTools,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this
            ->setName('requrvhive:probe')
            ->setDescription('Run a live authenticated probe against the active LLM provider')
            ->addOption('chat', null, InputOption::VALUE_NONE, 'Also send a minimal chat completion to /chat/completions')
            ->addOption('stream', null, InputOption::VALUE_NONE, 'Also send a minimal STREAMING chat completion (no tools)')
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'With --stream: pad the user message to this many KB')
            ->addOption('tools', null, InputOption::VALUE_REQUIRED, 'With --stream: attach this many dummy tool schemas')
            ->addOption('replay', null, InputOption::VALUE_NONE, 'Send crafted tool-call bodies to bisect the upstream parser (no real tool execution)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $providerId = $this->providerFactory->getActiveProviderId(null);
        $provider = $this->providerFactory->getProviderForUser(null, null);

        $configured = method_exists($provider, 'isConfigured')
            ? var_export($provider->isConfigured(null), true)
            : '?';
        $output->writeln('provider  : ' . $providerId);
        $output->writeln('model     : ' . $this->safeModel($provider));
        $output->writeln('configured: ' . $configured);
        $output->writeln('');

        $t0 = microtime(true);
        try {
            $this->report($output, 'GET  /models', $provider->probe(null), $t0);
        } catch (\Throwable $e) {
            $this->reportException($output, 'GET  /models', $e, $t0);
        }

        if ($input->getOption('chat')) {
            $output->writeln('');
            $t0 = microtime(true);
            try {
                $res = $provider->ask('ping', '', null);
                $dt = round((microtime(true) - $t0) * 1000);
                $output->writeln(sprintf('[POST /chat/completions] <info>OK in %dms</info>', $dt));
                $output->writeln('  ' . json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $this->reportException($output, 'POST /chat/completions', $e, $t0);
            }
        }

        if ($input->getOption('stream')) {
            $output->writeln('');

            $content = 'ping';
            $sizeKb = (int)($input->getOption('size') ?? 0);
            if ($sizeKb > 0) {
                $filler = str_repeat('lorem ipsum dolor sit amet ', 1024);
                $content = 'ping ' . str_repeat($filler, (int)ceil($sizeKb * 1024 / strlen($filler)));
            }

            $tools = [];
            $toolCount = (int)($input->getOption('tools') ?? 0);
            for ($n = 0; $n < $toolCount; $n++) {
                $tools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => 'dummy_tool_' . $n,
                        'description' => 'A dummy tool number ' . $n . ' used only to grow the tools payload of a diagnostic probe.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'arg' => ['type' => 'string', 'description' => 'a sample string argument'],
                                'num' => ['type' => 'integer', 'description' => 'a sample integer argument'],
                            ],
                            'required' => ['arg'],
                        ],
                    ],
                ];
            }

            $kb = round(strlen(json_encode([
                'messages' => [['role' => 'user', 'content' => $content]],
                'tools' => $tools,
            ])) / 1024, 1);
            $output->writeln(sprintf('[stream] payload ~%s KB, %d tools, message %d bytes', $kb, $toolCount, strlen($content)));

            $t0 = microtime(true);
            try {
                $gen = $provider->chatWithToolsStream(
                    [['role' => 'user', 'content' => $content]],
                    $tools,
                    static fn(string $name, array $input): array => ['type' => 'tool_result', 'tool_use_id' => '', 'output' => '', 'is_error' => true],
                    null,
                    null,
                );
                $deltas = 0;
                foreach ($gen as $ev) {
                    $dt = round((microtime(true) - $t0) * 1000);
                    $type = (string)($ev['type'] ?? '?');
                    if ($type === 'error') {
                        $output->writeln(sprintf('[stream] <error>error</error> in %dms: %s', $dt, (string)($ev['error'] ?? '')));
                    } elseif ($type === 'done') {
                        $output->writeln(sprintf('[stream] <info>done</info> in %dms (%d deltas)', $dt, $deltas));
                    } elseif ($type === 'delta') {
                        $deltas++;
                    }
                }
                if ($deltas > 0) {
                    $output->writeln(sprintf('[stream] first delta after stream open — <info>streaming OK</info>'));
                }
            } catch (\Throwable $e) {
                $this->reportException($output, 'stream /chat/completions', $e, $t0);
            }
        }

        if ($input->getOption('replay')) {
            $output->writeln('');
            $model = $this->safeModel($provider) !== '?' ? $this->safeModel($provider) : 'requrv-small-3.8';
            $tc = static fn(string $id, string $name, $args): array => [
                'id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => $args],
            ];
            $cases = [
                'R1: 1 tool_call, arguments STRING' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                        ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('a', 'list_calendars', '{}')]],
                        ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
                    ],
                ],
                'R2: 2 tool_calls, arguments STRING (replica del fallimento)' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                        ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('a', 'list_calendars', '{}'), $tc('b', 'list_calendar_events', '{"limit":30}')]],
                        ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
                        ['role' => 'tool', 'tool_call_id' => 'b', 'content' => 'No events between 2026-10-23 and 2026-10-29.'],
                    ],
                ],
                'R3: 1 tool_call, arguments DICT (oggetto)' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                        ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'a', 'type' => 'function', 'function' => ['name' => 'list_calendars', 'arguments' => (object)[]]]]],
                        ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
                    ],
                ],
                'R4: 1 tool_call, arguments STRING, assistant content NULL' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                        ['role' => 'assistant', 'content' => null, 'tool_calls' => [$tc('a', 'list_calendars', '{}')]],
                        ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
                    ],
                ],
            ];

            // Real built-in tool set (the same 14 the app sends every turn).
            $realTools = $this->chatTools->getTools();
            $openAiTools = $provider->toOpenAiTools($realTools) ?? [];
            $output->writeln('real tools loaded: ' . count($openAiTools));

            $r1Msgs = [
                ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('a', 'list_calendars', '{}')]],
                ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
            ];
            $r2Msgs = [
                ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('a', 'list_calendars', '{}'), $tc('b', 'list_calendar_events', '{"limit":30}')]],
                ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
                ['role' => 'tool', 'tool_call_id' => 'b', 'content' => 'No events between 2026-10-23 and 2026-10-29.'],
            ];
            $cases['R5: 1 tool_call + ' . count($openAiTools) . ' REAL tools'] = ['model' => $model, 'messages' => $r1Msgs, 'tools' => $openAiTools];
            $cases['R6: 2 tool_calls in ONE msg + ' . count($openAiTools) . ' REAL tools'] = ['model' => $model, 'messages' => $r2Msgs, 'tools' => $openAiTools];

            // Multi-turn agentic flow: the assistant emits ONE tool_call per turn,
            // in SEPARATE assistant messages (turn 1 then turn 2), which is how the
            // real app builds the history. R6 put both in a single message.
            $multiTurn = [
                ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('a', 'list_calendars', '{}')]],
                ['role' => 'tool', 'tool_call_id' => 'a', 'content' => "- Date di nascita dei contatti (uri: contact_birthdays) ¶ - Personal (uri: personal) ¶ Use the uri field to query events."],
                ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('b', 'list_calendar_events', '{"uri":"personal","start":"2026-10-23T00:00:00Z","end":"2026-10-29T23:59:59Z"}')]],
                ['role' => 'tool', 'tool_call_id' => 'b', 'content' => 'No events between 2026-10-23 and 2026-10-29 in the user\'s calendar.'],
            ];
            $systemMsg = "You are a helpful assistant integrated in Nextcloud with tools to list files, calendars and mail. Answer in Italian. Today is 2026-10-23.";
            $sys = array_merge([['role' => 'system', 'content' => $systemMsg]], $multiTurn);

            $cases['R7: MULTI-TURN (2 separate assistant tool_calls) + REAL tools'] = ['model' => $model, 'messages' => $multiTurn, 'tools' => $openAiTools];
            $cases['R8: MULTI-TURN + system + REAL tools'] = ['model' => $model, 'messages' => $sys, 'tools' => $openAiTools];
            $cases['R9: MULTI-TURN + system + tool_choice:auto + max_tokens + REAL tools'] = ['model' => $model, 'messages' => $sys, 'tools' => $openAiTools, 'tool_choice' => 'auto', 'max_tokens' => 4096];

            // R10-R11: the ONE thing the real request has that R5-R9 did not —
            // HiveProvider sends extra_body.reasoning_effort on every call for
            // requrv-small-3.8. The proxy spreads it top-level to the upstream,
            // which may flip vLLM into its reasoning chat-template branch.
            $cases['R10: R8 + extra_body.reasoning_effort=low'] = ['model' => $model, 'messages' => $sys, 'tools' => $openAiTools, 'extra_body' => ['reasoning_effort' => 'low']];
            $cases['R11: MULTI-TURN 2tc/1msg + extra_body.reasoning_effort=low'] = ['model' => $model, 'messages' => $sys, 'tools' => $openAiTools, 'extra_body' => ['reasoning_effort' => 'low'], 'stream' => false];

            // R12/R13: the actual app bug. json_encode([]) (an EMPTY PHP array,
            // which is what decodeArguments("{}") returns) yields "[]" — a JSON
            // ARRAY, not an object. The Qwen template does arguments|items, which
            // needs an object. "[]" must crash, "{}" must not.
            $cases['R12: tool_call arguments = "[]" (JSON array) — expected CRASH'] = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                    ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('a', 'list_calendars', '[]')]],
                    ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
                ],
                'tools' => $openAiTools,
            ];
            $cases['R13: tool_call arguments = "null" — expected CRASH'] = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                    ['role' => 'assistant', 'content' => '', 'tool_calls' => [$tc('a', 'list_calendars', 'null')]],
                    ['role' => 'tool', 'tool_call_id' => 'a', 'content' => '- Personal (uri: personal)'],
                ],
                'tools' => $openAiTools,
            ];

            // R14: end-to-end — build the turn-2 request the way the APP does
            // (app-format history -> toOpenAiMessages), so this is byte-for-byte
            // the body the fixed app now sends for "quali sono i miei
            // appuntamenti?". Must be 200 now (arguments "{}" not "[]").
            $m = new \ReflectionMethod($provider, 'toOpenAiMessages');
            $openAiMsgs = $m->invoke($provider, [
                ['role' => 'user', 'content' => 'quali sono i miei appuntamenti?'],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'tool_use', 'id' => 'a', 'name' => 'list_calendars', 'input' => []],
                ]],
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'a', 'content' => '- Personal (uri: personal)'],
                ]],
            ], 'You are a helpful assistant. Answer in Italian.', null);
            $cases['R14: E2E app-built turn-2 (list_calendars, NO args)'] = [
                'model' => $model,
                'messages' => $openAiMsgs,
                'tools' => $openAiTools,
            ];

            foreach ($cases as $label => $body) {
                $t0 = microtime(true);
                $r = $provider->rawChatProbe($body);
                $dt = round((microtime(true) - $t0) * 1000);
                $tag = $r['status'] === 200 ? '<info>OK</info>' : '<error>' . $r['status'] . '</error>';
                $output->writeln(sprintf('[%s] %s in %dms', $label, $tag, $dt));
                $output->writeln('  ' . $r['body']);
            }
        }

        return 0;
    }

    private function safeModel(LLMProviderInterface $provider): string {
        if (method_exists($provider, 'getModel')) {
            try {
                $m = $provider->getModel(null);
                return is_string($m) ? $m : '?';
            } catch (\Throwable) {
                return '?';
            }
        }
        return '?';
    }

    private function report(OutputInterface $output, string $label, array $probe, float $t0): void {
        $dt = round((microtime(true) - $t0) * 1000);
        $state = (string)($probe['state'] ?? '?');
        $tag = $state === 'ok' ? '<info>' : ($state === 'error' ? '<error>' : '<comment>');
        $output->writeln(sprintf('[%s] %s state: %s  in %dms  reason: %s', $label, $tag, $state, $dt, (string)($probe['reason'] ?? '')));
        if (!empty($probe['message'])) {
            $output->writeln('  ' . $probe['message']);
        }
        if (isset($probe['models']) && is_array($probe['models'])) {
            $output->writeln('  models (' . count($probe['models']) . '): ' . implode(', ', array_slice($probe['models'], 0, 40)));
        }
    }

    private function reportException(OutputInterface $output, string $label, \Throwable $e, float $t0): void {
        $dt = round((microtime(true) - $t0) * 1000);
        $output->writeln(sprintf('[%s] <error>EXCEPTION in %dms</error>: %s (%s)', $label, $dt, $e->getMessage(), $e::class));
    }
}
