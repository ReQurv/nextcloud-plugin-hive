// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockExecuteOCC = vi.fn();

vi.mock('../client/requrvhive.js', async () => {
  const actual =
    await vi.importActual<typeof import('../client/requrvhive.js')>('../client/requrvhive.js');
  return {
    ...actual,
    executeOCC: (...args: unknown[]) => mockExecuteOCC(...args),
  };
});

describe('ReQurv Hive Internal Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('requrvhive_show_config', () => {
    it('should return OCC output on success', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'api_key: (configured)\nmodel: requrv-small-3.8',
        stderr: '',
      });

      const { showConfigTool } = await import('../tools/apps/requrvhive.js');
      const result = await showConfigTool.handler();

      expect(result.content[0].text).toContain('api_key');
      expect(result.content[0].text).toContain('model');
      expect(mockExecuteOCC).toHaveBeenCalledWith('requrvhive:configure', ['--show']);
    });

    it('should handle OCC failure', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '',
        stderr: 'Command not found',
        error: 'Command not found',
      });

      const { showConfigTool } = await import('../tools/apps/requrvhive.js');
      const result = await showConfigTool.handler();

      expect(result.content[0].text).toContain('Error');
      expect(result.content[0].text).toContain('Command not found');
    });

    it('should handle thrown errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('Network error'));

      const { showConfigTool } = await import('../tools/apps/requrvhive.js');
      const result = await showConfigTool.handler();

      expect(result.content[0].text).toContain('Error');
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('requrvhive_configure', () => {
    it('should pass all config args', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'Configuration updated',
        stderr: '',
      });

      const { configureTool } = await import('../tools/apps/requrvhive.js');
      const result = await configureTool.handler({
        apiKey: 'hive-test-key',
        model: 'requrv-small-3.8',
        maxTokens: 8192,
        timeout: 120,
      });

      expect(result.content[0].text).toContain('Configuration updated');
      expect(mockExecuteOCC).toHaveBeenCalledWith('requrvhive:configure', [
        '--api-key',
        'hive-test-key',
        '--model',
        'requrv-small-3.8',
        '--max-tokens',
        '8192',
        '--timeout',
        '120',
      ]);
    });

    it('should only pass provided args', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'Model updated',
        stderr: '',
      });

      const { configureTool } = await import('../tools/apps/requrvhive.js');
      await configureTool.handler({ model: 'requrv-small-3.8' });

      expect(mockExecuteOCC).toHaveBeenCalledWith('requrvhive:configure', [
        '--model',
        'requrv-small-3.8',
      ]);
    });

    it('should handle errors', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: false,
        exitCode: 1,
        stdout: '',
        stderr: 'Invalid API key',
        error: 'Invalid API key',
      });

      const { configureTool } = await import('../tools/apps/requrvhive.js');
      const result = await configureTool.handler({ apiKey: 'bad-key' });

      expect(result.content[0].text).toContain('Error');
    });
  });

  describe('requrvhive_test', () => {
    it('should run the doctor diagnostic', async () => {
      mockExecuteOCC.mockResolvedValue({
        success: true,
        exitCode: 0,
        stdout: 'Provider: hive\nAPI Key: configured',
        stderr: '',
      });

      const { testTool } = await import('../tools/apps/requrvhive.js');
      const result = await testTool.handler();

      expect(result.content[0].text).toContain('Provider');
      expect(mockExecuteOCC).toHaveBeenCalledWith('requrvhive:doctor', []);
    });

    it('should handle errors', async () => {
      mockExecuteOCC.mockRejectedValue(new Error('API timeout'));

      const { testTool } = await import('../tools/apps/requrvhive.js');
      const result = await testTool.handler();

      expect(result.content[0].text).toContain('Error');
      expect(result.content[0].text).toContain('API timeout');
    });
  });
});
