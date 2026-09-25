// SPDX-License-Identifier: MIT

import { z } from 'zod';
import { executeOCC } from '../../client/requrvhive.js';

/**
 * ReQurv Hive Internal Tools
 * Provides configuration and diagnostics for the RequrvHive OCC commands
 */

/**
 * Helper function to run OCC commands via the app API
 */
async function runOCC(command: string, args: string[] = []): Promise<string> {
  const result = await executeOCC(command, args);

  if (!result.success) {
    const errorMsg = result.stderr || result.error || 'Unknown error';
    throw new Error(`OCC command failed (exit ${result.exitCode}): ${errorMsg}`);
  }

  return result.stdout || '';
}

/**
 * Show current ReQurv Hive configuration
 */
export const showConfigTool = {
  name: 'requrvhive_show_config',
  title: 'Show ReQurv Hive Configuration',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Show current ReQurv Hive configuration',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const output = await runOCC('requrvhive:configure', ['--show']);
      return {
        content: [
          {
            type: 'text',
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text',
            text: `Error: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  },
};

/**
 * Configure ReQurv Hive settings
 */
export const configureTool = {
  name: 'requrvhive_configure',
  title: 'Configure ReQurv Hive',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Configure ReQurv Hive settings (API key, model, tokens, timeout)',
  inputSchema: z.object({
    apiKey: z.string().optional().describe('ReQurv AI Hive API key (instance-level)'),
    model: z.string().optional().describe("Hive model to use (e.g., 'requrv-small-3.8')"),
    maxTokens: z
      .number()
      .optional()
      .describe('Maximum tokens for responses (1-128000, default: 8192)'),
    timeout: z.number().optional().describe('Request timeout in seconds (10-1800, default: 30)'),
  }),
  handler: async (args: {
    apiKey?: string;
    model?: string;
    maxTokens?: number;
    timeout?: number;
  }) => {
    try {
      const configArgs: string[] = [];

      if (args.apiKey) {
        configArgs.push('--api-key', args.apiKey);
      }
      if (args.model) {
        configArgs.push('--model', args.model);
      }
      if (args.maxTokens) {
        configArgs.push('--max-tokens', args.maxTokens.toString());
      }
      if (args.timeout) {
        configArgs.push('--timeout', args.timeout.toString());
      }

      const output = await runOCC('requrvhive:configure', configArgs);
      return {
        content: [
          {
            type: 'text',
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text',
            text: `Error: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  },
};

/**
 * Test ReQurv Hive integration
 */
export const testTool = {
  name: 'requrvhive_test',
  title: 'Test ReQurv Hive Integration',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: true,
  },
  description:
    'Run the RequrvHive integration diagnostic (app status, Assistant task providers, API key)',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const output = await runOCC('requrvhive:doctor');
      return {
        content: [
          {
            type: 'text',
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text',
            text: `Error: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
      };
    }
  },
};

/**
 * Export all ReQurv Hive internal tools
 */
export const requrvhiveTools = [showConfigTool, configureTool, testTool];
