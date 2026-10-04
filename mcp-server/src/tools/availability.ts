// SPDX-License-Identifier: MIT

/**
 * Pure availability math for the calendar `find_availability` tool.
 * No I/O here — every function is deterministic and unit-testable.
 */

export interface Span {
  startMs: number;
  endMs: number;
}

/** Calendar date in an IANA zone, plus the day's weekday (0 = Sunday). */
export interface ZonedDateParts {
  year: number;
  month: number; // 1-12
  day: number; // 1-31
  weekday: number; // 0=Sunday .. 6=Saturday
}

/**
 * Validate an IANA time zone name. Returns true when Intl accepts it.
 */
export function isValidTimezone(timeZone: string): boolean {
  try {
    new Intl.DateTimeFormat('en-CA', { timeZone });
    return true;
  } catch {
    return false;
  }
}

function zoneParts(ms: number, timeZone: string): Intl.DateTimeFormatPart[] {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
    weekday: 'short',
  }).formatToParts(new Date(ms));
}

/**
 * Calendar date of an instant, expressed in the given IANA zone.
 */
export function zonedDateParts(ms: number, timeZone: string): ZonedDateParts {
  const parts = zoneParts(ms, timeZone);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  const weekdayMap: Record<string, number> = {
    Sun: 0,
    Mon: 1,
    Tue: 2,
    Wed: 3,
    Thu: 4,
    Fri: 5,
    Sat: 6,
  };
  return {
    year: Number(get('year')),
    month: Number(get('month')),
    day: Number(get('day')),
    weekday: weekdayMap[get('weekday')] ?? 0,
  };
}

/**
 * Convert a wall-clock time in an IANA zone to UTC epoch milliseconds.
 * Uses an iterative offset correction (converges in 1-2 passes, exact across
 * DST transitions for every modern time zone).
 */
export function zonedWallTimeToMs(
  year: number,
  month: number,
  day: number,
  hour = 0,
  minute = 0,
  second = 0,
  timeZone: string
): number {
  const target = Date.UTC(year, month - 1, day, hour, minute, second);
  let utc = target;
  for (let i = 0; i < 3; i++) {
    const parts = zoneParts(utc, timeZone);
    const get = (type: string) => Number(parts.find((p) => p.type === type)?.value ?? '0');
    const wall = Date.UTC(
      get('year'),
      get('month') - 1,
      get('day'),
      get('hour') === 24 ? 0 : get('hour'),
      get('minute'),
      get('second')
    );
    const correction = target - (wall - utc);
    utc = correction;
    if (wall === target) break;
  }
  return utc;
}

/**
 * Midnight (in the zone) of the day after the day containing `ms`.
 */
export function nextZonedDay(ms: number, timeZone: string): number {
  const p = zonedDateParts(ms, timeZone);
  const next = new Date(Date.UTC(p.year, p.month - 1, p.day) + 86_400_000);
  return zonedWallTimeToMs(
    next.getUTCFullYear(),
    next.getUTCMonth() + 1,
    next.getUTCDate(),
    0,
    0,
    0,
    timeZone
  );
}

/**
 * Parse one "HH:MM-HH:MM" preferred-time entry into [startMin, endMin)
 * minutes from midnight. Returns null when malformed or non-positive length.
 */
export function parseTimeRange(value: string): [number, number] | null {
  const m = value.trim().match(/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/);
  if (!m) return null;
  const sh = Number(m[1]);
  const sm = Number(m[2]);
  const eh = Number(m[3]);
  const em = Number(m[4]);
  if (sh > 23 || sm > 59 || eh > 23 || em > 59) return null;
  const start = sh * 60 + sm;
  const end = eh * 60 + em;
  if (end <= start) return null;
  return [start, end];
}

/**
 * Parse a comma-separated preferred-times string. Malformed entries are
 * skipped rather than failing the query — one typo in a hint should not
 * break availability.
 */
export function parsePreferredTimes(value: string): Array<[number, number]> {
  const out: Array<[number, number]> = [];
  for (const part of value.split(',')) {
    if (!part.trim()) continue;
    const range = parseTimeRange(part);
    if (range) out.push(range);
  }
  return out;
}

/**
 * Merge overlapping/adjacent spans, sorted by start.
 */
export function mergeSpans(spans: Span[]): Span[] {
  if (spans.length === 0) return [];
  const sorted = [...spans].sort((a, b) => a.startMs - b.startMs);
  const merged: Span[] = [{ startMs: sorted[0].startMs, endMs: sorted[0].endMs }];
  for (let i = 1; i < sorted.length; i++) {
    const last = merged[merged.length - 1];
    if (sorted[i].startMs <= last.endMs) {
      last.endMs = Math.max(last.endMs, sorted[i].endMs);
    } else {
      merged.push({ startMs: sorted[i].startMs, endMs: sorted[i].endMs });
    }
  }
  return merged;
}

/**
 * Subtract `holes` from `base`, returning the remaining free spans.
 * Holes outside the base are ignored.
 */
export function subtractSpans(base: Span, holes: Span[]): Span[] {
  const remaining: Span[] = [];
  let cursor = base.startMs;
  for (const hole of mergeSpans(holes)) {
    if (hole.endMs <= cursor || hole.startMs >= base.endMs) continue;
    if (hole.startMs > cursor) {
      remaining.push({ startMs: cursor, endMs: Math.min(hole.startMs, base.endMs) });
    }
    cursor = Math.max(cursor, hole.endMs);
    if (cursor >= base.endMs) break;
  }
  if (cursor < base.endMs) {
    remaining.push({ startMs: cursor, endMs: base.endMs });
  }
  return remaining;
}

export interface AvailabilityOptions {
  /** Minimum slot length in ms. */
  durationMs: number;
  /** Inclusive window start (already clamped to "now" by the caller). */
  windowStartMs: number;
  /** Exclusive window end. */
  windowEndMs: number;
  /** IANA zone the windows are expressed in (validated by the caller). */
  timeZone: string;
  /** Restrict to 09:00-17:00 when no preferred times are given. */
  businessHoursOnly: boolean;
  /** Skip Saturday/Sunday. */
  excludeWeekends: boolean;
  /** Preferred ranges in minutes-from-midnight; replaces business hours. */
  preferredTimes: Array<[number, number]>;
  /** Busy spans to subtract (already in UTC ms). */
  busySpans: Span[];
}

/**
 * Compute the maximal free slots of at least `durationMs` inside the window,
 * honouring business hours / preferred times / weekends and subtracting busy.
 * Slots are "maximal": a free morning comes back once, not as a grid.
 */
export function computeFreeSlots(opts: AvailabilityOptions): Span[] {
  const { timeZone } = opts;
  const firstDay = zonedDateParts(opts.windowStartMs, timeZone);
  const lastDay = zonedDateParts(opts.windowEndMs - 1, timeZone);

  const dayMs = zonedWallTimeToMs(firstDay.year, firstDay.month, firstDay.day, 0, 0, 0, timeZone);
  const lastDayMs = zonedWallTimeToMs(lastDay.year, lastDay.month, lastDay.day, 0, 0, 0, timeZone);

  const slots: Span[] = [];

  for (let d = dayMs; d <= lastDayMs; d = nextZonedDay(d, timeZone)) {
    const parts = zonedDateParts(d, timeZone);
    if (opts.excludeWeekends && (parts.weekday === 0 || parts.weekday === 6)) continue;

    let ranges: Array<[number, number]>;
    if (opts.preferredTimes.length > 0) {
      ranges = opts.preferredTimes;
    } else if (opts.businessHoursOnly) {
      ranges = [[9 * 60, 17 * 60]];
    } else {
      ranges = [[0, 24 * 60]];
    }

    for (const [startMin, endMin] of ranges) {
      const rawStart = zonedWallTimeToMs(
        parts.year,
        parts.month,
        parts.day,
        Math.floor(startMin / 60),
        startMin % 60,
        0,
        timeZone
      );
      const rawEnd = zonedWallTimeToMs(
        parts.year,
        parts.month,
        parts.day,
        Math.floor(endMin / 60),
        endMin % 60,
        0,
        timeZone
      );
      const startMs = Math.max(rawStart, opts.windowStartMs);
      const endMs = Math.min(rawEnd, opts.windowEndMs);
      if (endMs - startMs < opts.durationMs) continue;
      const holes = opts.busySpans.filter((b) => b.endMs > startMs && b.startMs < endMs);
      slots.push(...subtractSpans({ startMs, endMs }, holes));
    }
  }

  return slots
    .filter((s) => s.endMs - s.startMs >= opts.durationMs)
    .sort((a, b) => a.startMs - b.startMs);
}

/**
 * Format an instant as "YYYY-MM-DD HH:MM" in the given zone.
 */
export function formatZonedTimestamp(ms: number, timeZone: string): string {
  const parts = zoneParts(ms, timeZone);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
  return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')}`;
}
