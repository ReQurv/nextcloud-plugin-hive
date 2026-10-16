<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Migration;

use OCA\RequrvHive\Db\McpServerMapper;
use OCA\RequrvHive\Service\CredentialService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

class RepairEncryptMcpTokens implements IRepairStep {
    public function __construct(
        private McpServerMapper $mapper,
        private CredentialService $credentials,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string {
        return 'Encrypt MCP server tokens at rest';
    }

    public function run(IOutput $output): void {
        $servers = $this->mapper->findAll();
        $migrated = 0;

        foreach ($servers as $server) {
            $changed = false;

            foreach (['getAuthToken', 'getOauthAccessToken', 'getOauthRefreshToken'] as $getter) {
                $value = $server->$getter();
                if ($value === null || $value === '') {
                    continue;
                }

                $setter = str_replace('get', 'set', $getter);

                // decryptToken returns the plaintext on success and the raw value when
                // decryption fails (plaintext pre-migration value). A changed return
                // means the value was already encrypted; an unchanged return means it
                // is still plaintext and needs encrypting.
                $decrypted = $this->credentials->decryptToken($value);
                if ($decrypted === $value) {
                    $server->$setter($this->credentials->encryptToken($value));
                    $changed = true;
                }
            }

            if ($changed) {
                $server->setUpdatedAt(time());
                $this->mapper->update($server);
                $migrated++;
            }
        }

        if ($migrated > 0) {
            $output->info("Encrypted tokens for $migrated MCP server(s)");
            $this->logger->info('RepairEncryptMcpTokens: migrated {count} servers', ['count' => $migrated]);
        }
    }
}
