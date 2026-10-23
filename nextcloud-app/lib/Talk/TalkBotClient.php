<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Talk;

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Posts ReQurv's replies into a Talk room through the Talk bot OCS API.
 *
 * The API authenticates the caller with the bot's shared secret rather than a
 * user session: a random seed goes in one header and the SHA256-HMAC of the
 * seed concatenated with the message, keyed by the secret, in another. Talk
 * verifies both before posting the message as the bot.
 */
class TalkBotClient {
    public function __construct(
        private readonly IClientService $clientService,
        private readonly ISecureRandom $secureRandom,
        private readonly TalkBotRegistration $registration,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Post a message into the room, optionally threaded as a reply to an
     * existing message.
     *
     * @throws \Exception on transport failure (per IClient::post)
     * @throws \RuntimeException when Talk answers with a non-2xx status
     */
    public function sendMessage(
        string $talkToken,
        string $message,
        string $backendUrl,
        int $replyTo = 0,
        string $referenceId = '',
        bool $silent = false,
    ): IResponse {
        $random = $this->secureRandom->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
        $signature = hash_hmac('sha256', $random . $message, $this->registration->getSecret());

        $url = $backendUrl
            . '/ocs/v2.php/apps/spreed/api/v1/bot/' . $talkToken . '/message';

        $response = $this->clientService->newClient()->post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'OCS-APIRequest' => 'true',
                'X-Nextcloud-Talk-Bot-Random' => $random,
                'X-Nextcloud-Talk-Bot-Signature' => $signature,
            ],
            'body' => json_encode([
                'message' => $message,
                'replyTo' => $replyTo,
                'referenceId' => $referenceId,
                'silent' => $silent,
            ], JSON_THROW_ON_ERROR),
            'timeout' => 30,
            'nextcloud' => ['allow_local_address' => true],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('RequrvHive Talk: bot reply rejected', [
                'talkToken' => $talkToken,
                'status' => $status,
            ]);
            throw new \RuntimeException('Talk rejected the bot reply with status ' . $status);
        }

        return $response;
    }
}
