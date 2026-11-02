<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Talk;

use OCA\RequrvHive\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Registers ReQurv as a Nextcloud Talk bot and owns its identity and secret.
 *
 * The bot is an *event* bot (no public webhook URL): Talk dispatches the
 * room's chat messages to it through the BotInvokeEvent, and it answers by
 * posting back through the bot OCS API using the shared secret. The secret
 * is generated once on first registration and persisted in the app config,
 * because it must stay stable — Talk validates every reply against it.
 */
class TalkBotRegistration {
    public const BOT_NAME = 'ReQurv';
    /**
     * Names a room message may use to address the bot by mention. The first
     * is the display name the bot is registered under; the rest are aliases
     * (Talk's mention autocompletion only offers the display name, but the
     * trigger accepts any of these typed as plain text).
     */
    public const MENTION_NAMES = ['ReQurv', 'ai'];
    public const BOT_URL = 'nextcloudapp://requrvhive';
    public const BOT_DESCRIPTION = 'Answers in Talk rooms using the ReQurv Hive model pinned to the room.';
    /** Talk's actor type for bot participants (Attendee::ACTOR_BOTS). */
    public const BOT_ACTOR_TYPE = 'bots';
    /**
     * EVENT so Talk pushes chat messages to us; RESPONSE so we may post
     * replies through the bot OCS API.
     *
     * NOTE: Talk's CLI installer declares EVENT and RESPONSE mutually
     * exclusive, but the runtime install path (BotListener) does not enforce
     * that — it only strips WEBHOOK for nextcloudapp:// URLs. We need both:
     * invocation is in-process (EVENT) while the answer is posted out-of-band
     * (RESPONSE), since a chat turn runs an agentic LLM that cannot block the
     * sender's request.
     */
    public const BOT_FEATURES = 6; // FEATURE_EVENT (4) | FEATURE_RESPONSE (2)

    private const SECRET_KEY = 'talk_bot_secret';
    private const REGISTERED_TALK_VERSION_KEY = 'talk_bot_registered_talk_version';

    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly IAppManager $appManager,
        private readonly IEventDispatcher $dispatcher,
        private readonly ISecureRandom $secureRandom,
    ) {
    }

    /**
     * The actor id Talk assigns to this bot's participant: the "bot-" prefix
     * followed by the SHA1 of the bot URL. Reply and mention triggers are
     * matched against it.
     */
    public static function botActorId(): string {
        return 'bot-' . sha1(self::BOT_URL);
    }

    /**
     * The "actor type / actor id" pair Talk uses to reference this bot, e.g.
     * in a message's inReplyTo.actor.id when someone answers one of our
     * messages.
     */
    public static function botActorRef(): string {
        return self::BOT_ACTOR_TYPE . '/' . self::botActorId();
    }

    /**
     * Regex matching a mention of the bot under any of its names — e.g.
     * "@ReQurv" or "@ai" — as a whole word. Used both to detect the mention
     * trigger and to strip the addressing token from the prompt, so the two
     * never drift apart.
     */
    /** @return non-empty-string the pattern is a literal, never empty */
    public static function mentionPattern(): string {
        return '/@(?:' . implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            self::MENTION_NAMES
        )) . ')\b/i';
    }

    /**
     * The shared secret used to sign OCS replies (and which Talk would use to
     * sign anything it sends us). Generated on first use and stored so it
     * stays stable across requests and restarts.
     */
    public function getSecret(): string {
        $secret = $this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, '');
        if ($secret === '') {
            $secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
            $this->appConfig->setValueString(Application::APP_ID, self::SECRET_KEY, $secret);
        }
        return $secret;
    }

    /** Whether a bot secret has been generated and persisted yet. */
    public function hasSecret(): bool {
        return $this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, '') !== '';
    }

    /**
     * The Talk version this bot last registered against (see
     * ensureRegistered()). '' means never registered.
     */
    public function getRegisteredTalkVersion(): string {
        return $this->appConfig->getValueString(Application::APP_ID, self::REGISTERED_TALK_VERSION_KEY, '');
    }

    /**
     * Generate a fresh secret and persist it. Use when the stored secret and
     * Talk's bot row have diverged — after that, every reply signs with a
     * secret Talk no longer knows and answers 401 forever, and there is no
     * way to recover from the stored side alone. Callers must follow up with
     * register() so Talk upserts the new secret.
     */
    public function rotateSecret(): string {
        $secret = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $this->appConfig->setValueString(Application::APP_ID, self::SECRET_KEY, $secret);
        return $secret;
    }

    /**
     * Make sure the bot is registered with Talk. Runs cheaply on every boot:
     * a no-op when Talk is absent or already registered at the current Talk
     * version, and re-registers (Talk upserts by URL) when the Talk version
     * changes, so an upgrade that rewrites the bot tables does not leave us
     * unregistered.
     *
     * $force skips the version check and re-registers anyway — the recovery
     * path for a bot row that Talk dropped (or whose secret changed) without
     * a Talk upgrade, where the version marker still says "registered".
     */
    public function ensureRegistered(bool $force = false): void {
        // getAppVersion() reports "disabled" for an app that is not installed,
        // so this doubles as the "is Talk present?" check. The register() call
        // below carries its own class_exists guard as a final safety net.
        $talkVersion = $this->appManager->getAppVersion('spreed', true);
        if ($talkVersion === '' || $talkVersion === 'disabled') {
            return;
        }
        if (!$force
            && $this->appConfig->getValueString(Application::APP_ID, self::REGISTERED_TALK_VERSION_KEY, '') === $talkVersion
        ) {
            return;
        }

        if ($this->register()) {
            $this->appConfig->setValueString(Application::APP_ID, self::REGISTERED_TALK_VERSION_KEY, $talkVersion);
        }
    }

    /**
     * Dispatch the install event that Talk turns into a bot-server row.
     * Returns false without throwing when Talk is not installed, because the
     * event class does not exist then and there is nothing to register with.
     */
    public function register(): bool {
        if (!class_exists('OCA\Talk\Events\BotInstallEvent')) {
            return false;
        }

        $this->dispatcher->dispatchTyped(new \OCA\Talk\Events\BotInstallEvent(
            self::BOT_NAME,
            $this->getSecret(),
            self::BOT_URL,
            self::BOT_DESCRIPTION,
            self::BOT_FEATURES,
        ));
        return true;
    }
}
