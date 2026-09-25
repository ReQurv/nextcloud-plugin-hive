<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\OpenMetrics;

use Generator;
use OCA\RequrvHive\Db\McpServerMapper;
use OCP\OpenMetrics\IMetricFamily;
use OCP\OpenMetrics\Metric;
use OCP\OpenMetrics\MetricType;
use Override;

/**
 * Exports the connectivity status of each configured MCP server as a gauge
 * (1 = enabled and last connection healthy, 0 = disabled or last connection failed).
 */
class McpServerStatusExporter implements IMetricFamily {
    public function __construct(
        private McpServerMapper $mcpServerMapper,
    ) {
    }

    #[Override]
    public function name(): string {
        return 'requrvhive_mcp_server_status';
    }

    #[Override]
    public function type(): MetricType {
        return MetricType::gauge;
    }

    #[Override]
    public function unit(): string {
        return '';
    }

    #[Override]
    public function help(): string {
        return 'RequrvHive MCP server connectivity (1 = enabled and healthy, 0 = disabled or failing).';
    }

    #[Override]
    public function metrics(): Generator {
        foreach ($this->mcpServerMapper->findAll() as $server) {
            $up = $server->getIsEnabled() && $server->getLastStatus() === 'ok';
            yield new Metric($up ? 1 : 0, [
                'server_id' => (string)$server->getId(),
                'server' => $server->getDisplayName(),
            ]);
        }
    }
}
