// SPDX-License-Identifier: MIT

import { describe, it, expect } from 'vitest';

import {
  computeFreeSlots,
  isValidTimezone,
  mergeSpans,
  parsePreferredTimes,
  subtractSpans,
  zonedDateParts,
  zonedWallTimeToMs,
} from '../tools/availability.js';

const BERLIN = 'Europe/Berlin';

// 2026-01-15 is a Thursday; Berlin is UTC+1 in winter.
// 2026-07-15 is a Wednesday; Berlin is UTC+2 in summer (DST).
describe('zonedWallTimeToMs / zonedDateParts', () => {
  it('converts wall time to an absolute instant (winter offset)', () => {
    expect(zonedWallTimeToMs(2026, 1, 15, 12, 0, 0, BERLIN)).toBe(Date.UTC(2026, 0, 15, 11, 0));
  });

  it('converts wall time to an absolute instant (summer offset)', () => {
    expect(zonedWallTimeToMs(2026, 7, 15, 12, 0, 0, BERLIN)).toBe(Date.UTC(2026, 6, 15, 10, 0));
  });

  it('supports the UTC timezone', () => {
    expect(zonedWallTimeToMs(2026, 1, 15, 12, 0, 0, 'UTC')).toBe(Date.UTC(2026, 0, 15, 12, 0));
  });

  it('round-trips an instant through zonedDateParts', () => {
    const instant = Date.UTC(2026, 0, 15, 11, 0); // = 12:00 Berlin
    const parts = zonedDateParts(instant, BERLIN);
    expect(parts).toMatchObject({ year: 2026, month: 1, day: 15, weekday: 4 }); // Thursday
  });

  it('rejects unknown timezones', () => {
    expect(() => zonedWallTimeToMs(2026, 1, 15, 12, 0, 0, 'Not/AZone')).toThrow(/Not\/AZone/);
    expect(isValidTimezone('Not/AZone')).toBe(false);
    expect(isValidTimezone('Europe/Berlin')).toBe(true);
  });
});

describe('parsePreferredTimes', () => {
  it('parses HH:MM-HH:MM ranges into minutes-from-midnight pairs', () => {
    expect(parsePreferredTimes('08:00-10:00, 14:00-16:00')).toEqual([
      [480, 600],
      [840, 960],
    ]);
  });

  it('skips malformed or inverted ranges without failing', () => {
    expect(parsePreferredTimes('garbage, 10:00-09:00, 09:00-10:00')).toEqual([[540, 600]]);
  });

  it('returns nothing for an empty query', () => {
    expect(parsePreferredTimes('')).toEqual([]);
  });
});

describe('mergeSpans / subtractSpans', () => {
  const t = (hour: number) => zonedWallTimeToMs(2026, 1, 15, hour, 0, 0, BERLIN);

  it('merges overlapping and adjacent spans', () => {
    const merged = mergeSpans([
      { startMs: t(9), endMs: t(10) },
      { startMs: t(9) + 30 * 60000, endMs: t(11) },
      { startMs: t(11), endMs: t(12) },
    ]);
    expect(merged).toEqual([{ startMs: t(9), endMs: t(12) }]);
  });

  it('subtracts a busy span from a free span', () => {
    const base = { startMs: t(9), endMs: t(17) };
    expect(subtractSpans(base, [{ startMs: t(10), endMs: t(12) }])).toEqual([
      { startMs: t(9), endMs: t(10) },
      { startMs: t(12), endMs: t(17) },
    ]);
  });

  it('subtracts spanning and edge-touching busy spans', () => {
    const base = { startMs: t(9), endMs: t(17) };
    expect(subtractSpans(base, [{ startMs: t(5), endMs: t(20) }])).toEqual([]);
    expect(subtractSpans(base, [{ startMs: t(9), endMs: t(11) }])).toEqual([
      { startMs: t(11), endMs: t(17) },
    ]);
  });

  it('ignores holes fully outside the base', () => {
    const base = { startMs: t(9), endMs: t(17) };
    expect(subtractSpans(base, [{ startMs: t(18), endMs: t(19) }])).toEqual([base]);
  });
});

describe('computeFreeSlots', () => {
  const berlin = (day: number, hour: number) => zonedWallTimeToMs(2026, 1, day, hour, 0, 0, BERLIN);
  const thuStart = berlin(15, 0); // Thursday 2026-01-15
  const friStart = berlin(16, 0);
  const hour = 60 * 60000;

  const base = {
    windowStartMs: thuStart,
    windowEndMs: friStart,
    timeZone: BERLIN,
    businessHoursOnly: true,
    excludeWeekends: true,
    preferredTimes: [] as Array<[number, number]>,
    busySpans: [] as Array<{ startMs: number; endMs: number }>,
  };

  it('yields a single business-hours slot on a free weekday', () => {
    const slots = computeFreeSlots({ ...base, durationMs: hour });
    expect(slots).toEqual([{ startMs: berlin(15, 9), endMs: berlin(15, 17) }]);
  });

  it('splits the day around a busy span', () => {
    const slots = computeFreeSlots({
      ...base,
      durationMs: hour,
      busySpans: [{ startMs: berlin(15, 10), endMs: berlin(15, 12) }],
    });
    expect(slots).toEqual([
      { startMs: berlin(15, 9), endMs: berlin(15, 10) },
      { startMs: berlin(15, 12), endMs: berlin(15, 17) },
    ]);
  });

  it('drops slots shorter than the required duration', () => {
    const slots = computeFreeSlots({
      ...base,
      durationMs: 5 * hour,
      busySpans: [{ startMs: berlin(15, 10), endMs: berlin(15, 12) }],
    });
    expect(slots).toEqual([{ startMs: berlin(15, 12), endMs: berlin(15, 17) }]);
  });

  it('skips weekends when asked', () => {
    const satStart = berlin(17, 0); // Saturday
    const monStart = berlin(19, 0); // Monday
    const slots = computeFreeSlots({
      ...base,
      windowStartMs: satStart,
      windowEndMs: monStart,
      durationMs: hour,
    });
    expect(slots).toEqual([]);
  });

  it('includes weekends when not excluded', () => {
    const satStart = berlin(17, 0);
    const monStart = berlin(19, 0);
    const slots = computeFreeSlots({
      ...base,
      windowStartMs: satStart,
      windowEndMs: monStart,
      durationMs: hour,
      excludeWeekends: false,
    });
    expect(slots).toEqual([
      { startMs: berlin(17, 9), endMs: berlin(17, 17) },
      { startMs: berlin(18, 9), endMs: berlin(18, 17) },
    ]);
  });

  it('covers the whole day when business hours are disabled', () => {
    const slots = computeFreeSlots({ ...base, durationMs: 5 * hour, businessHoursOnly: false });
    expect(slots).toEqual([{ startMs: thuStart, endMs: friStart }]);
  });

  it('uses preferred times instead of business hours', () => {
    const slots = computeFreeSlots({
      ...base,
      durationMs: hour,
      preferredTimes: parsePreferredTimes('08:00-10:00, 14:00-16:00'),
    });
    expect(slots).toEqual([
      { startMs: berlin(15, 8), endMs: berlin(15, 10) },
      { startMs: berlin(15, 14), endMs: berlin(15, 16) },
    ]);
  });

  it('clamps the window to the given start instant', () => {
    const slots = computeFreeSlots({ ...base, durationMs: hour, windowStartMs: berlin(15, 15) });
    expect(slots).toEqual([{ startMs: berlin(15, 15), endMs: berlin(15, 17) }]);
  });

  it('handles a multi-day window', () => {
    const monStart = berlin(19, 0);
    const wedStart = berlin(21, 0);
    const slots = computeFreeSlots({
      ...base,
      windowStartMs: monStart,
      windowEndMs: wedStart,
      durationMs: hour,
    });
    expect(slots).toEqual([
      { startMs: berlin(19, 9), endMs: berlin(19, 17) },
      { startMs: berlin(20, 9), endMs: berlin(20, 17) },
    ]);
  });
});
