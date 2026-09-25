// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the ReQurvHive API client module
const mockFetchRequrvhiveAPI = vi.fn();

vi.mock('../client/requrvhive.js', () => ({
  fetchRequrvhiveAPI: (...args: unknown[]) => mockFetchRequrvhiveAPI(...args),
}));

import {
  listCoworkersTool,
  listCoworkerTemplatesTool,
  createCoworkerTool,
  updateCoworkerTool,
  deleteCoworkerTool,
  pauseCoworkerTool,
  runCoworkerTool,
  getCoworkerRunsTool,
} from '../tools/apps/cowork.js';

const sampleCoworker = {
  id: 7,
  title: 'Classify images',
  provider: 'hive',
  model: null,
  taskType: 'vision:classify',
  cronSchedule: '0 3 * * *',
  inputType: 'folder',
  inputPath: '/Photos',
  outputType: 'system_tags',
  isActive: true,
  paused: false,
  lastRunAt: null,
  nextRunAt: 1800000000,
  lastStatus: null,
  lastError: null,
};

describe('Cowork Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'testuser';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_coworkers', () => {
    it('formats a coworker list', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue([sampleCoworker]);
      const res = await listCoworkersTool.handler();
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers');
      expect(res.content[0].text).toContain('Classify images');
      expect(res.content[0].text).toContain('provider: hive');
      expect(res).not.toHaveProperty('isError');
    });

    it('handles the empty case', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue([]);
      const res = await listCoworkersTool.handler();
      expect(res.content[0].text).toContain('No coworkers configured');
    });

    it('surfaces errors', async () => {
      mockFetchRequrvhiveAPI.mockRejectedValue(new Error('boom'));
      const res = await listCoworkersTool.handler();
      expect(res.isError).toBe(true);
      expect(res.content[0].text).toContain('boom');
    });
  });

  describe('list_coworker_templates', () => {
    it('lists templates and task types', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue({
        templates: [{ id: 'classify-images-hive', title: 'Classify — Hive', description: 'd' }],
        taskTypes: [{ id: 'vision:classify', label: 'Classify images', family: 'vision' }],
      });
      const res = await listCoworkerTemplatesTool.handler();
      expect(res.content[0].text).toContain('classify-images-hive');
      expect(res.content[0].text).toContain('vision:classify');
    });
  });

  describe('create_coworker', () => {
    it('creates from a template via the templates endpoint', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue(sampleCoworker);
      const res = await createCoworkerTool.handler({ templateId: 'classify-images-hive' });
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers/templates', {
        method: 'POST',
        body: { templateId: 'classify-images-hive' },
      });
      expect(res.content[0].text).toContain('Coworker created');
    });

    it('creates a custom coworker with mapped fields and options', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue(sampleCoworker);
      await createCoworkerTool.handler({
        title: 'My job',
        provider: 'hive',
        inputPath: '/Albums',
        cronSchedule: '0 4 * * *',
        maxTags: 5,
        recursive: false,
      });
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers', {
        method: 'POST',
        body: {
          title: 'My job',
          provider: 'hive',
          input_type: 'folder',
          input_path: '/Albums',
          cron_schedule: '0 4 * * *',
          options: { maxTags: 5, recursive: false },
        },
      });
    });
  });

  describe('update_coworker', () => {
    it('PUTs only provided fields', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue(sampleCoworker);
      await updateCoworkerTool.handler({ id: 7, provider: 'hive' });
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers/7', {
        method: 'PUT',
        body: { provider: 'hive' },
      });
    });
  });

  describe('delete_coworker', () => {
    it('DELETEs by id', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue({ deleted: true });
      const res = await deleteCoworkerTool.handler({ id: 7 });
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers/7', { method: 'DELETE' });
      expect(res.content[0].text).toContain('deleted');
    });
  });

  describe('pause_coworker', () => {
    it('POSTs to the pause action', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue({ ...sampleCoworker, paused: true });
      const res = await pauseCoworkerTool.handler({ id: 7 });
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers/7/pause', { method: 'POST' });
      expect(res.content[0].text).toContain('paused');
    });
  });

  describe('run_coworker', () => {
    it('runs now and reports progress', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue({
        id: 1,
        coworkerId: 7,
        status: 'success',
        itemsTotal: 3,
        itemsProcessed: 3,
        summary: 'Processed 3/3 image file(s) via hive.',
        error: null,
        startedAt: 1700000000,
        finishedAt: 1700000050,
      });
      const res = await runCoworkerTool.handler({ id: 7 });
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers/7/run', { method: 'POST' });
      expect(res.content[0].text).toContain('processed 3/3');
      expect(res.content[0].text).toContain('success');
    });
  });

  describe('get_coworker_runs', () => {
    it('lists run history', async () => {
      mockFetchRequrvhiveAPI.mockResolvedValue([
        {
          id: 2,
          coworkerId: 7,
          status: 'success',
          itemsTotal: 5,
          itemsProcessed: 5,
          summary: 'ok',
          error: null,
          startedAt: 1700000000,
          finishedAt: 1700000100,
        },
      ]);
      const res = await getCoworkerRunsTool.handler({ id: 7 });
      expect(mockFetchRequrvhiveAPI).toHaveBeenCalledWith('/coworkers/7/runs', {
        queryParams: undefined,
      });
      expect(res.content[0].text).toContain('#2 success');
    });
  });
});
