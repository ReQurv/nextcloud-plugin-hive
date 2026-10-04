// SPDX-License-Identifier: MIT

import { z } from 'zod';
import {
  decodeXmlEntities,
  encodeXmlEntities,
  fetchCalDAV,
  nsTagContent,
} from '../../client/caldav.js';
import {
  computeFreeSlots,
  formatZonedTimestamp,
  isValidTimezone,
  nextZonedDay,
  parsePreferredTimes,
  zonedDateParts,
  zonedWallTimeToMs,
  type Span,
} from '../availability.js';
import { escapeICalValue, unescapeICalValue } from '../dav-utils.js';
import { getNextcloudConfig } from '../types.js';

/**
 * Nextcloud Calendar App Tools
 * Provides calendar event management via CalDAV
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ParsedCalendar {
  displayName: string;
  url: string;
  ctag?: string;
  color?: string;
  supportsEvents: boolean;
  supportsTasks: boolean;
  supportsJournals: boolean;
  enabled: boolean;
  order?: number;
  timezone?: string;
}

interface ParsedEvent {
  uid: string;
  summary: string;
  dtstart: string;
  dtend?: string;
  duration?: string;
  isAllDay: boolean;
  /** IANA zone from a TZID parameter on DTSTART (timed, non-UTC events). */
  dtstartTzid?: string;
  /** IANA zone from a TZID parameter on DTEND. */
  dtendTzid?: string;
  location?: string;
  description?: string;
  status?: string;
  transp?: string;
  categories: string[];
  attendees: ParsedAttendee[];
  organizer?: string;
  rrule?: string;
  recurrenceId?: string;
  created?: string;
  lastModified?: string;
  url?: string;
  color?: string;
  accessClass?: string;
  etag?: string;
  href?: string;
  calendarName?: string;
}

interface ParsedAttendee {
  cn?: string;
  email?: string;
  role?: string;
  partstat?: string;
  cutype?: string;
}

// ---------------------------------------------------------------------------
// iCalendar helpers
// ---------------------------------------------------------------------------

function unfoldICalLines(text: string): string {
  return text.replace(/\r?\n[ \t]/g, '');
}

function formatICalDate(icalDate: string): string {
  if (icalDate.length === 8) {
    return `${icalDate.slice(0, 4)}-${icalDate.slice(4, 6)}-${icalDate.slice(6, 8)}`;
  }
  const match = icalDate.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/);
  if (match) {
    const [, y, m, d, hh, mm] = match;
    return `${y}-${m}-${d} ${hh}:${mm}`;
  }
  return icalDate;
}

function icalNow(): string {
  return new Date().toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
}

/**
 * Convert a JS Date or ISO string to iCalendar UTC format.
 */
function toICalDateTime(dateStr: string): string {
  // Already in iCal format
  if (/^\d{8}(T\d{6}Z?)?$/.test(dateStr)) {
    if (dateStr.length === 8) {
      // Parse as local-midnight date, then convert to UTC for CalDAV time-range
      const d = new Date(+dateStr.slice(0, 4), +dateStr.slice(4, 6) - 1, +dateStr.slice(6, 8));
      return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
    }
    return dateStr;
  }
  // ISO format -> iCal UTC
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return dateStr;
  return d.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
}

function setICalProperty(icalData: string, propName: string, value: string | null): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*$`, 'm');
  if (value === null) {
    return icalData.replace(regex, '').replace(/(\r?\n){2,}/g, '\r\n');
  }
  if (regex.test(icalData)) {
    return icalData.replace(regex, `${propName}:${value}`);
  }
  return icalData.replace(/END:VEVENT/, `${propName}:${value}\r\nEND:VEVENT`);
}

function setICalDateProperty(
  icalData: string,
  propName: string,
  value: string | null,
  tzid?: string
): string {
  const regex = new RegExp(`^${propName}(;[^:]*)?:.*$`, 'm');
  if (value === null) {
    return icalData.replace(regex, '').replace(/(\r?\n){2,}/g, '\r\n');
  }
  let formatted: string;
  if (value.length === 8) {
    formatted = `${propName};VALUE=DATE:${value}`;
  } else if (tzid) {
    formatted = `${propName};TZID=${tzid}:${normalizeLocalICal(value, tzid)}`;
  } else {
    formatted = `${propName}:${value}`;
  }
  if (regex.test(icalData)) {
    return icalData.replace(regex, formatted);
  }
  return icalData.replace(/END:VEVENT/, `${formatted}\r\nEND:VEVENT`);
}

/**
 * Format the UTC offset for a given IANA zone at a specific instant.
 * Returns "+HHMM" or "-HHMM" (e.g. "+0200", "-0500", "+0000").
 */
function getTimezoneOffset(tzid: string, date: Date): string {
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: tzid,
    timeZoneName: 'longOffset',
  });
  const tzPart = fmt.formatToParts(date).find((p) => p.type === 'timeZoneName')?.value || 'GMT';
  // tzPart is "GMT", "GMT+02:00", "GMT-05:30", or "GMT+5:45" depending on engine
  const m = tzPart.match(/GMT([+-])(\d{1,2})(?::(\d{2}))?/);
  if (!m) return '+0000';
  const sign = m[1];
  const hh = m[2].padStart(2, '0');
  const mm = m[3] ?? '00';
  return `${sign}${hh}${mm}`;
}

/**
 * Build a minimal but RFC 5545-conformant VTIMEZONE component for the given
 * IANA zone. Uses two reference instants in the current year to detect DST.
 * No external tzdata required — sufficient for iOS / Thunderbird to accept
 * the event and apply the TZID correctly to upcoming occurrences.
 */
export function buildVTimezone(tzid: string): string {
  const year = new Date().getUTCFullYear();
  const janOffset = getTimezoneOffset(tzid, new Date(Date.UTC(year, 0, 15)));
  const julOffset = getTimezoneOffset(tzid, new Date(Date.UTC(year, 6, 15)));
  const lines: string[] = ['BEGIN:VTIMEZONE', `TZID:${tzid}`];
  if (janOffset === julOffset) {
    lines.push(
      'BEGIN:STANDARD',
      'DTSTART:19700101T000000',
      `TZOFFSETFROM:${janOffset}`,
      `TZOFFSETTO:${janOffset}`,
      `TZNAME:${tzid}`,
      'END:STANDARD'
    );
  } else {
    // Higher numeric offset = daylight, lower = standard (works for both hemispheres)
    const standardOffset = janOffset < julOffset ? janOffset : julOffset;
    const daylightOffset = janOffset < julOffset ? julOffset : janOffset;
    lines.push(
      'BEGIN:STANDARD',
      'DTSTART:19701101T000000',
      `TZOFFSETFROM:${daylightOffset}`,
      `TZOFFSETTO:${standardOffset}`,
      `TZNAME:${tzid}`,
      'END:STANDARD',
      'BEGIN:DAYLIGHT',
      'DTSTART:19700301T000000',
      `TZOFFSETFROM:${standardOffset}`,
      `TZOFFSETTO:${daylightOffset}`,
      `TZNAME:${tzid}`,
      'END:DAYLIGHT'
    );
  }
  lines.push('END:VTIMEZONE');
  return lines.join('\r\n');
}

/**
 * Convert a UTC iCal datetime ("YYYYMMDDTHHmmssZ") to wall-clock time in the
 * given IANA zone, returning "YYYYMMDDTHHmmss" (no Z).
 */
function convertUtcICalToLocalICal(utcICal: string, tzid: string): string {
  const m = utcICal.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/);
  if (!m) return utcICal.replace(/Z$/, '');
  const [, y, mo, d, h, mi, s] = m;
  const instant = new Date(Date.UTC(+y, +mo - 1, +d, +h, +mi, +s));
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: tzid,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  });
  const parts = fmt.formatToParts(instant);
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '00';
  let hour = get('hour');
  if (hour === '24') hour = '00';
  return `${get('year')}${get('month')}${get('day')}T${hour}${get('minute')}${get('second')}`;
}

/**
 * If `value` ends in Z, convert it to wall-clock time in `tzid`. Otherwise
 * assume it is already a floating local datetime in that zone.
 */
function normalizeLocalICal(value: string, tzid: string): string {
  if (/Z$/.test(value)) return convertUtcICalToLocalICal(value, tzid);
  return value;
}

/**
 * Add `minutes` to a floating "YYYYMMDDTHHmmss" string, returning the same form.
 */
function addMinutesToLocalICal(local: string, minutes: number): string {
  const m = local.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/);
  if (!m) return local;
  const [, y, mo, d, h, mi, s] = m;
  const dt = new Date(Date.UTC(+y, +mo - 1, +d, +h, +mi + minutes, +s));
  const pad = (n: number) => String(n).padStart(2, '0');
  return (
    `${dt.getUTCFullYear()}${pad(dt.getUTCMonth() + 1)}${pad(dt.getUTCDate())}` +
    `T${pad(dt.getUTCHours())}${pad(dt.getUTCMinutes())}${pad(dt.getUTCSeconds())}`
  );
}

/**
 * Parse the numeric components out of an iCal datetime value.
 * Returns null for unparseable input.
 */
function parseICalDateTimeParts(
  value: string
): { y: number; mo: number; d: number; h: number; mi: number; s: number } | null {
  const m = value.match(/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2}))?/);
  if (!m) return null;
  return { y: +m[1], mo: +m[2], d: +m[3], h: +(m[4] ?? 0), mi: +(m[5] ?? 0), s: +(m[6] ?? 0) };
}

/**
 * Parse an RFC 5545 duration ("P1DT2H30M") to milliseconds.
 */
function parseICalDuration(value: string): number {
  const m = value.match(/^(-?)P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/);
  if (!m) return 0;
  const sign = m[1] === '-' ? -1 : 1;
  const days = +(m[2] ?? 0);
  const hours = +(m[3] ?? 0);
  const mins = +(m[4] ?? 0);
  const secs = +(m[5] ?? 0);
  return sign * (days * 86400 + hours * 3600 + mins * 60 + secs) * 1000;
}

/**
 * Ensure the VCALENDAR contains a VTIMEZONE with the requested TZID. Insert
 * one before the first VEVENT if not already present.
 */
function ensureVTimezone(icalData: string, tzid: string): string {
  const escaped = tzid.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const existing = new RegExp(`BEGIN:VTIMEZONE[\\s\\S]*?TZID:${escaped}[\\s\\S]*?END:VTIMEZONE`);
  if (existing.test(icalData)) return icalData;
  const vtz = buildVTimezone(tzid);
  return icalData.replace(/BEGIN:VEVENT/, `${vtz}\r\nBEGIN:VEVENT`);
}

/**
 * Build a DISPLAY VALARM block firing `minutes` before the event. Returns an
 * empty string for non-positive values (those are skipped, not emitted).
 */
function buildVAlarm(minutes: number): string {
  if (minutes <= 0) return '';
  const totalSeconds = minutes * 60;
  const hours = Math.floor(totalSeconds / 3600);
  const mins = Math.floor((totalSeconds % 3600) / 60);
  const durationStr = `-PT${hours > 0 ? hours + 'H' : ''}${mins > 0 ? mins + 'M' : ''}`;
  return `BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Reminder\r\nTRIGGER:${durationStr}\r\nEND:VALARM`;
}

/**
 * Merge the single `alarm` and the `alarms` array into one list of reminder
 * minutes. `alarms` entries come first, then `alarm` (if a positive number).
 * The MCP-client-friendly split exists because a union type's anyOf JSON Schema
 * is not reliably honored, so a dedicated array parameter is needed.
 */
function collectAlarmMinutes(alarm: number | null | undefined, alarms?: number[]): number[] {
  return [...(alarms ?? []), ...(alarm !== undefined && alarm !== null ? [alarm] : [])];
}

// ---------------------------------------------------------------------------
// Parsing
// ---------------------------------------------------------------------------

/**
 * Parse calendar collections from a PROPFIND response.
 */
function parseCalendars(responseXml: string): ParsedCalendar[] {
  const calendars: ParsedCalendar[] = [];

  const responseBlocks = responseXml.match(/<d:response>[\s\S]*?<\/d:response>/g);
  if (!responseBlocks) return calendars;

  for (const block of responseBlocks) {
    // Skip the root collection itself
    const resourceTypes = block.match(/<d:resourcetype>([\s\S]*?)<\/d:resourcetype>/);
    if (!resourceTypes || !resourceTypes[1].includes('<cal:calendar')) continue;

    const hrefMatch = block.match(/<d:href>([^<]+)<\/d:href>/);
    const displayNameMatch = block.match(/<d:displayname>([^<]*)<\/d:displayname>/);
    const ctagMatch = block.match(/<cs:getctag>([^<]*)<\/cs:getctag>/);
    const colorMatch = block.match(/<x1:calendar-color[^>]*>([^<]*)<\/x1:calendar-color>/);
    const enabledMatch = block.match(/<x2:calendar-enabled[^>]*>([^<]*)<\/x2:calendar-enabled>/);
    const orderMatch = block.match(/<x1:calendar-order[^>]*>([^<]*)<\/x1:calendar-order>/);

    const compSet = block.match(nsTagContent('supported-calendar-component-set'));
    const components = compSet ? compSet[1] : '';

    const url = hrefMatch?.[1] || '';
    const name = displayNameMatch?.[1] || url.split('/').filter(Boolean).pop() || '';

    calendars.push({
      displayName: name,
      url,
      ctag: ctagMatch?.[1],
      color: colorMatch?.[1],
      supportsEvents: components.includes('VEVENT'),
      supportsTasks: components.includes('VTODO'),
      supportsJournals: components.includes('VJOURNAL'),
      enabled: enabledMatch ? enabledMatch[1] !== '0' : true,
      order: orderMatch ? parseInt(orderMatch[1], 10) : undefined,
    });
  }

  return calendars;
}

/**
 * Parse an ATTENDEE property line into a ParsedAttendee.
 */
function parseAttendee(line: string): ParsedAttendee {
  const attendee: ParsedAttendee = {};

  const cnMatch = line.match(/CN=([^;:]+)/i);
  if (cnMatch) attendee.cn = cnMatch[1].replace(/"/g, '');

  const roleMatch = line.match(/ROLE=([^;:]+)/i);
  if (roleMatch) attendee.role = roleMatch[1];

  const partstatMatch = line.match(/PARTSTAT=([^;:]+)/i);
  if (partstatMatch) attendee.partstat = partstatMatch[1];

  const cutypeMatch = line.match(/CUTYPE=([^;:]+)/i);
  if (cutypeMatch) attendee.cutype = cutypeMatch[1];

  const valueMatch = line.match(/:(?:mailto:)?(.+)$/i);
  if (valueMatch) attendee.email = valueMatch[1];

  return attendee;
}

/**
 * Parse VEVENT fields from a CalDAV REPORT response.
 */
function parseVEvents(responseXml: string): ParsedEvent[] {
  const events: ParsedEvent[] = [];

  const responseBlocks = responseXml.match(/<d:response>[\s\S]*?<\/d:response>/g);
  if (!responseBlocks) return events;

  for (const responseBlock of responseBlocks) {
    const hrefMatch = responseBlock.match(/<d:href>([^<]+)<\/d:href>/);
    const etagMatch = responseBlock.match(/<d:getetag>"?([^"<]+)"?<\/d:getetag>/);
    const calDataMatch = responseBlock.match(nsTagContent('calendar-data'));
    if (!calDataMatch) continue;

    const icalData = calDataMatch[1];
    const veventBlocks = icalData.match(/BEGIN:VEVENT[\s\S]*?END:VEVENT/g);
    if (!veventBlocks) continue;

    for (const block of veventBlocks) {
      const unfolded = unfoldICalLines(block);
      const lines = unfolded.split(/\r?\n/);

      const event: ParsedEvent = {
        uid: '',
        summary: '',
        dtstart: '',
        isAllDay: false,
        categories: [],
        attendees: [],
        etag: etagMatch?.[1],
        href: hrefMatch?.[1],
      };

      for (const line of lines) {
        // Handle ATTENDEE lines specially (they have complex params)
        if (line.startsWith('ATTENDEE')) {
          event.attendees.push(parseAttendee(line));
          continue;
        }

        // Handle ORGANIZER
        if (line.startsWith('ORGANIZER')) {
          const cnMatch = line.match(/CN=([^;:]+)/i);
          const valueMatch = line.match(/:(?:mailto:)?(.+)$/i);
          event.organizer = cnMatch
            ? `${cnMatch[1].replace(/"/g, '')} <${valueMatch?.[1] || ''}>`
            : valueMatch?.[1] || '';
          continue;
        }

        const propMatch = line.match(/^([A-Z][A-Z0-9-]*)(;[^:]*)?:(.*)/);
        if (!propMatch) continue;

        const [, name, params, value] = propMatch;

        switch (name) {
          case 'UID':
            event.uid = value;
            break;
          case 'SUMMARY':
            event.summary = unescapeICalValue(value);
            break;
          case 'DTSTART': {
            event.dtstart = value;
            event.isAllDay =
              params?.includes('VALUE=DATE') === true && !params?.includes('VALUE=DATE-TIME');
            const tzidMatch = params?.match(/TZID=([^;:]+)/i);
            if (tzidMatch) event.dtstartTzid = tzidMatch[1];
            break;
          }
          case 'DTEND': {
            event.dtend = value;
            const tzidMatch = params?.match(/TZID=([^;:]+)/i);
            if (tzidMatch) event.dtendTzid = tzidMatch[1];
            break;
          }
          case 'DURATION':
            event.duration = value;
            break;
          case 'LOCATION':
            event.location = unescapeICalValue(value);
            break;
          case 'DESCRIPTION':
            event.description = unescapeICalValue(value);
            break;
          case 'STATUS':
            event.status = value;
            break;
          case 'TRANSP':
            event.transp = value;
            break;
          case 'URL':
            event.url = value;
            break;
          case 'COLOR':
            event.color = value;
            break;
          case 'CLASS':
            event.accessClass = value;
            break;
          case 'CREATED':
            event.created = value;
            break;
          case 'LAST-MODIFIED':
            event.lastModified = value;
            break;
          case 'RRULE':
            event.rrule = value;
            break;
          case 'RECURRENCE-ID':
            event.recurrenceId = value;
            break;
          case 'CATEGORIES':
            event.categories.push(
              ...value
                .split(',')
                .map((c) => c.trim())
                .filter(Boolean)
            );
            break;
        }
      }

      if (event.uid) {
        events.push(event);
      }
    }
  }

  return events;
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function formatAttendee(attendee: ParsedAttendee): string {
  let str = attendee.cn || attendee.email || 'unknown';
  if (attendee.cn && attendee.email) {
    str = `${attendee.cn} <${attendee.email}>`;
  }
  const parts: string[] = [];
  if (attendee.role) parts.push(attendee.role);
  if (attendee.partstat) parts.push(attendee.partstat);
  if (parts.length > 0) str += ` (${parts.join(', ')})`;
  return str;
}

function formatRecurrence(rrule: string): string {
  const parts = rrule.split(';');
  const map: Record<string, string> = {};
  for (const part of parts) {
    const [k, v] = part.split('=');
    if (k && v) map[k] = v;
  }

  const freq = map.FREQ;
  const interval = map.INTERVAL ? parseInt(map.INTERVAL, 10) : 1;

  const freqNames: Record<string, [string, string]> = {
    DAILY: ['day', 'days'],
    WEEKLY: ['week', 'weeks'],
    MONTHLY: ['month', 'months'],
    YEARLY: ['year', 'years'],
  };

  let result: string;
  if (freq && freqNames[freq]) {
    const [singular, plural] = freqNames[freq];
    result = interval === 1 ? `Every ${singular}` : `Every ${interval} ${plural}`;
  } else {
    result = `${freq || 'unknown'}`;
  }

  if (map.BYDAY) result += ` on ${map.BYDAY}`;
  if (map.BYMONTHDAY) result += ` on day ${map.BYMONTHDAY}`;
  if (map.BYMONTH) result += ` in month ${map.BYMONTH}`;
  if (map.COUNT) result += `, ${map.COUNT} times`;
  if (map.UNTIL) result += `, until ${formatICalDate(map.UNTIL)}`;

  return result;
}

function formatEvent(event: ParsedEvent): string {
  const dateRange = event.isAllDay
    ? event.dtend
      ? `${formatICalDate(event.dtstart)} - ${formatICalDate(event.dtend)} (all day)`
      : `${formatICalDate(event.dtstart)} (all day)`
    : event.dtend
      ? `${formatICalDate(event.dtstart)} - ${formatICalDate(event.dtend)}`
      : `${formatICalDate(event.dtstart)}`;

  let line = `${event.summary}`;
  line += `\n    When: ${dateRange}`;

  if (event.status && event.status !== 'CONFIRMED') {
    line += ` [${event.status}]`;
  }

  if (event.location) line += `\n    Where: ${event.location}`;
  if (event.organizer) line += `\n    Organizer: ${event.organizer}`;

  if (event.attendees.length > 0) {
    line += `\n    Attendees: ${event.attendees.map(formatAttendee).join('; ')}`;
  }

  if (event.rrule) {
    line += `\n    Recurrence: ${formatRecurrence(event.rrule)}`;
  }

  if (event.categories.length > 0) {
    line += `\n    Tags: ${event.categories.join(', ')}`;
  }

  if (event.description) {
    const desc =
      event.description.length > 200
        ? event.description.substring(0, 200) + '...'
        : event.description;
    line += `\n    ${desc}`;
  }

  if (event.accessClass && event.accessClass !== 'PUBLIC') {
    line += `\n    Class: ${event.accessClass}`;
  }

  line += `\n    UID: ${event.uid}`;

  return line;
}

function formatCalendar(cal: ParsedCalendar): string {
  const parts: string[] = [];
  if (cal.supportsEvents) parts.push('events');
  if (cal.supportsTasks) parts.push('tasks');
  if (cal.supportsJournals) parts.push('journals');

  let line = `${cal.displayName}`;
  if (cal.color) line += ` [${cal.color}]`;
  if (!cal.enabled) line += ' (disabled)';
  line += `\n    Supports: ${parts.join(', ') || 'none'}`;
  line += `\n    URL: ${cal.url}`;
  return line;
}

// ---------------------------------------------------------------------------
// CalDAV helpers
// ---------------------------------------------------------------------------

/**
 * Resolve a calendar identifier (display name or URL slug) to its URL slug.
 * Falls back to the input unchanged when no match is found or PROPFIND fails,
 * so existing slug-based callers keep working.
 */
async function resolveCalendarSlug(nameOrSlug: string): Promise<string> {
  const config = getNextcloudConfig();
  const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/`;
  const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop><d:resourcetype /><d:displayname /></d:prop>
</d:propfind>`;
  try {
    const response = await fetchCalDAV(calDavUrl, {
      method: 'PROPFIND',
      body: propfindBody,
      headers: { Depth: '1' },
    });
    if (!response.ok) return nameOrSlug;
    const text = await response.text();
    const calendars = parseCalendars(text);
    for (const cal of calendars) {
      const slug = cal.url.split('/').filter(Boolean).pop() ?? '';
      if (slug === nameOrSlug) return nameOrSlug;
    }
    const lower = nameOrSlug.toLowerCase();
    for (const cal of calendars) {
      if (cal.displayName.toLowerCase() === lower) {
        return cal.url.split('/').filter(Boolean).pop() ?? nameOrSlug;
      }
    }
  } catch {
    /* fall through — keep original input */
  }
  return nameOrSlug;
}

/**
 * Assert that a calendar supports VEVENT. Throws a descriptive error if not,
 * so create_event fails early with a useful message instead of a 403 from the server.
 */
async function assertCalendarSupportsEvents(
  calendarUrl: string,
  calendarName: string
): Promise<void> {
  const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <c:supported-calendar-component-set />
  </d:prop>
</d:propfind>`;

  const response = await fetchCalDAV(calendarUrl, {
    method: 'PROPFIND',
    body: propfindBody,
    headers: { Depth: '0' },
  });

  if (response.status === 404) {
    const err = new Error(
      `Calendar "${calendarName}" not found. Use list_calendars to find available calendar names.`
    ) as Error & { code?: string };
    err.code = 'CALENDAR_NOT_FOUND';
    throw err;
  }
  if (!response.ok) return; // Can't check — let the server reject if needed

  const text = await response.text();
  const compSet = text.match(nsTagContent('supported-calendar-component-set'));
  const components = compSet ? compSet[1] : '';

  if (!components.includes('VEVENT')) {
    const supported: string[] = [];
    if (components.includes('VTODO')) supported.push('tasks');
    if (components.includes('VJOURNAL')) supported.push('journals');
    throw new Error(
      `Calendar "${calendarName}" does not support events` +
        (supported.length > 0 ? ` (it only supports: ${supported.join(', ')})` : '') +
        `. Use list_calendars to find an event-capable calendar.`
    );
  }
}

/**
 * Resolve an event's CalDAV href, ETag, and full iCal data by UID.
 */
async function resolveEventByUid(
  calendarName: string,
  uid: string
): Promise<{ href: string; etag: string; icalData: string }> {
  const config = getNextcloudConfig();
  let slug = calendarName;
  const buildUrl = () => `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/`;

  const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:prop-filter name="UID">
          <c:text-match collation="i;octet">${encodeXmlEntities(uid)}</c:text-match>
        </c:prop-filter>
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

  let response = await fetchCalDAV(buildUrl(), {
    method: 'REPORT',
    body: reportBody,
    headers: { Depth: '1' },
  });

  if (response.status === 404) {
    const resolved = await resolveCalendarSlug(calendarName);
    if (resolved !== calendarName) {
      slug = resolved;
      response = await fetchCalDAV(buildUrl(), {
        method: 'REPORT',
        body: reportBody,
        headers: { Depth: '1' },
      });
    }
  }

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(
      `CalDAV REPORT failed for calendar "${calendarName}": ${response.status} - ${errorText}`
    );
  }

  const responseText = await response.text();

  const hrefMatch = responseText.match(nsTagContent('href'));
  const etagMatch = responseText.match(nsTagContent('getetag'));
  const calDataMatch = responseText.match(nsTagContent('calendar-data'));

  if (!hrefMatch || !etagMatch || !calDataMatch) {
    throw new Error(`Event with UID "${uid}" not found in calendar "${calendarName}"`);
  }

  const rawEtag = decodeXmlEntities(etagMatch[1].trim());
  const etag = rawEtag.startsWith('"') ? rawEtag : `"${rawEtag}"`;

  return {
    href: hrefMatch[1].trim(),
    etag,
    icalData: decodeXmlEntities(calDataMatch[1]),
  };
}

/**
 * PROPFIND the calendar home and return all calendar collections.
 */
export async function fetchAllCalendars(): Promise<ParsedCalendar[]> {
  const config = getNextcloudConfig();
  const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/`;

  const propfindBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:x1="http://apple.com/ns/ical/" xmlns:x2="http://owncloud.org/ns">
  <d:prop>
    <d:resourcetype />
    <d:displayname />
    <cs:getctag />
    <x1:calendar-color />
    <x1:calendar-order />
    <x2:calendar-enabled />
    <c:supported-calendar-component-set />
  </d:prop>
</d:propfind>`;

  const response = await fetchCalDAV(calDavUrl, {
    method: 'PROPFIND',
    body: propfindBody,
    headers: { Depth: '1' },
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`CalDAV PROPFIND failed: ${response.status} - ${errorText}`);
  }

  return parseCalendars(await response.text());
}

/**
 * Filter calendars down to event-capable ones that are enabled.
 */
export function eventCapableCalendars(calendars: ParsedCalendar[]): ParsedCalendar[] {
  return calendars.filter((c) => c.supportsEvents && c.enabled);
}

/**
 * Resolve a parsed event's start instant to UTC epoch milliseconds.
 * - "…Z" values are absolute.
 * - TZID-bound values are wall-clock time in that zone.
 * - Floating values are interpreted in `defaultTimezone` (the query context).
 * Returns null when the value is unparseable.
 */
function eventStartMs(event: ParsedEvent, defaultTimezone: string): number | null {
  const value = event.dtstart;
  if (!value) return null;
  if (/Z$/.test(value)) {
    const ms = Date.parse(value.replace('Z', '') + 'Z');
    return Number.isNaN(ms) ? null : ms;
  }
  const parts = parseICalDateTimeParts(value);
  if (!parts) return null;
  if (event.dtstartTzid && isValidTimezone(event.dtstartTzid)) {
    return zonedWallTimeToMs(
      parts.y,
      parts.mo,
      parts.d,
      parts.h,
      parts.mi,
      parts.s,
      event.dtstartTzid
    );
  }
  return zonedWallTimeToMs(parts.y, parts.mo, parts.d, parts.h, parts.mi, parts.s, defaultTimezone);
}

/**
 * Convert a parsed event to a busy span, or null when the event does not
 * consume time: cancelled, transparent, or all-day without includeAllDay.
 * All-day events occupy the whole calendar day (dtend is exclusive).
 */
export function eventToBusySpan(
  event: ParsedEvent,
  includeAllDay: boolean,
  defaultTimezone: string
): Span | null {
  if (event.status === 'CANCELLED') return null;
  if (event.transp === 'TRANSPARENT') return null;

  if (event.isAllDay) {
    if (!includeAllDay) return null;
    const parts = parseICalDateTimeParts(event.dtstart);
    if (!parts) return null;
    const startMs = zonedWallTimeToMs(parts.y, parts.mo, parts.d, 0, 0, 0, defaultTimezone);
    const endParts = event.dtend ? parseICalDateTimeParts(event.dtend) : null;
    const endMs = endParts
      ? zonedWallTimeToMs(endParts.y, endParts.mo, endParts.d, 0, 0, 0, defaultTimezone)
      : nextZonedDay(startMs, defaultTimezone);
    return endMs > startMs ? { startMs, endMs } : null;
  }

  const startMs = eventStartMs(event, defaultTimezone);
  if (startMs === null) return null;

  let endMs: number | null = null;
  if (event.dtend) {
    if (/Z$/.test(event.dtend)) {
      const ms = Date.parse(event.dtend);
      endMs = Number.isNaN(ms) ? null : ms;
    } else {
      const parts = parseICalDateTimeParts(event.dtend);
      if (parts) {
        if (event.dtendTzid && isValidTimezone(event.dtendTzid)) {
          endMs = zonedWallTimeToMs(
            parts.y,
            parts.mo,
            parts.d,
            parts.h,
            parts.mi,
            parts.s,
            event.dtendTzid
          );
        } else {
          endMs = zonedWallTimeToMs(
            parts.y,
            parts.mo,
            parts.d,
            parts.h,
            parts.mi,
            parts.s,
            defaultTimezone
          );
        }
      }
    }
  }
  if (endMs === null && event.duration) {
    const durationMs = parseICalDuration(event.duration);
    if (durationMs > 0) endMs = startMs + durationMs;
  }
  if (endMs === null) endMs = startMs + 3_600_000; // default 1 hour

  return endMs > startMs ? { startMs, endMs } : null;
}

// ---------------------------------------------------------------------------
// VEVENT building (shared by create_event and create_meeting)
// ---------------------------------------------------------------------------

export interface BuildVEventOptions {
  uid: string;
  summary: string;
  /** Fully-qualified DTSTART property line value, e.g. "DTSTART;TZID=Europe/Berlin:20260101T140000". */
  dtstartProp: string;
  dtendProp: string;
  tzid?: string;
  location?: string;
  description?: string;
  status?: string;
  transp?: string;
  accessClass?: string;
  categories?: string[];
  attendees?: Array<{ email: string; cn?: string; role?: string; rsvp?: boolean }>;
  rrule?: string;
  /** Reminder minutes (already merged from alarm + alarms). */
  alarms: number[];
  /** User ID written into the ORGANIZER line (only when attendees are set). */
  organizerUser: string;
}

/**
 * Build a complete VCALENDAR payload for a new VEVENT.
 */
export function buildVEventPayload(opts: BuildVEventOptions): string {
  const now = icalNow();
  const vtimezoneBlock = opts.tzid ? `${buildVTimezone(opts.tzid)}\r\n` : '';
  let vevent = `BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ReQurvHive//MCP Server//EN\r\n${vtimezoneBlock}BEGIN:VEVENT\r\nUID:${opts.uid}\r\nDTSTAMP:${now}\r\nCREATED:${now}\r\nLAST-MODIFIED:${now}\r\n${opts.dtstartProp}\r\n${opts.dtendProp}\r\nSUMMARY:${escapeICalValue(opts.summary)}`;

  if (opts.location) vevent += `\r\nLOCATION:${escapeICalValue(opts.location)}`;
  if (opts.description) vevent += `\r\nDESCRIPTION:${escapeICalValue(opts.description)}`;
  if (opts.status) vevent += `\r\nSTATUS:${opts.status}`;
  if (opts.transp) vevent += `\r\nTRANSP:${opts.transp}`;
  if (opts.accessClass) vevent += `\r\nCLASS:${opts.accessClass}`;
  if (opts.categories && opts.categories.length > 0) {
    vevent += `\r\nCATEGORIES:${opts.categories.map(escapeICalValue).join(',')}`;
  }
  if (opts.rrule) vevent += `\r\nRRULE:${opts.rrule}`;

  if (opts.attendees && opts.attendees.length > 0) {
    vevent += `\r\nORGANIZER;CN=${opts.organizerUser}:mailto:${opts.organizerUser}`;
    for (const attendee of opts.attendees) {
      let atLine = 'ATTENDEE';
      if (attendee.cn) atLine += `;CN=${attendee.cn}`;
      atLine += `;ROLE=${attendee.role || 'REQ-PARTICIPANT'}`;
      atLine += `;PARTSTAT=NEEDS-ACTION`;
      if (attendee.rsvp !== false) atLine += `;RSVP=TRUE`;
      atLine += `:mailto:${attendee.email}`;
      vevent += `\r\n${atLine}`;
    }
  }

  for (const mins of opts.alarms) {
    const valarm = buildVAlarm(mins);
    if (valarm) vevent += `\r\n${valarm}`;
  }

  vevent += `\r\nEND:VEVENT\r\nEND:VCALENDAR`;
  return vevent;
}

/**
 * PUT a new .ics into a calendar collection and verify it was persisted.
 */
async function putNewEvent(slug: string, uid: string, payload: string): Promise<void> {
  const config = getNextcloudConfig();
  const calDavUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/${uid}.ics`;

  const response = await fetchCalDAV(calDavUrl, {
    method: 'PUT',
    body: payload,
    headers: {
      'Content-Type': 'text/calendar; charset=utf-8',
      'If-None-Match': '*',
    },
  });

  if (response.status !== 201 && response.status !== 204) {
    const errorText = await response.text();
    throw new Error(
      `Failed to create event: server returned ${response.status} (expected 201 or 204) - ${errorText}`
    );
  }

  try {
    await resolveEventByUid(slug, uid);
  } catch {
    throw new Error(
      `Event creation appeared to succeed (HTTP ${response.status}) but the event ` +
        `could not be verified. The server may have rejected the request silently. ` +
        `UID: ${uid}`
    );
  }
}

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

/**
 * List all calendars available to the user.
 */
export const listCalendarsTool = {
  name: 'list_calendars',
  title: 'List Calendars',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List all calendars available to the current user, including their supported component types (events, tasks, journals) and metadata.',
  inputSchema: z.object({}),
  handler: async () => {
    try {
      const calendars = await fetchAllCalendars();

      if (calendars.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: 'No calendars found.',
            },
          ],
        };
      }

      const formatted = calendars.map(formatCalendar).join('\n\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Calendars (${calendars.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing calendars: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * List events from a calendar within a time range.
 */
export const listEventsTool = {
  name: 'list_events',
  title: 'List Calendar Events',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'List events from a Nextcloud calendar within an optional time range. Returns event details including time, location, attendees, recurrence, and UIDs.',
  inputSchema: z.object({
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
    from: z
      .string()
      .optional()
      .describe(
        'Start of time range (ISO 8601, e.g. 2026-04-12 or 2026-04-12T09:00:00Z). Defaults to today.'
      ),
    to: z
      .string()
      .optional()
      .describe(
        'End of time range (ISO 8601, e.g. 2026-04-12 or 2026-04-12T09:00:00Z). Defaults to 30 days from now.'
      ),
    limit: z
      .number()
      .min(1)
      .max(200)
      .optional()
      .describe('Maximum number of events to return (default: 50)'),
  }),
  handler: async (args: { calendarName: string; from?: string; to?: string; limit?: number }) => {
    try {
      const config = getNextcloudConfig();
      let slug = args.calendarName;
      const buildUrl = () => `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/`;
      const limit = args.limit || 50;

      // Default time range: today to 30 days from now
      const now = new Date();
      const defaultFrom = new Date(now);
      defaultFrom.setHours(0, 0, 0, 0);
      const defaultTo = new Date(now);
      defaultTo.setDate(defaultTo.getDate() + 30);

      const fromStr = toICalDateTime(args.from || defaultFrom.toISOString());
      const toStr = toICalDateTime(args.to || defaultTo.toISOString());

      const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="${fromStr}" end="${toStr}" />
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      let response = await fetchCalDAV(buildUrl(), {
        method: 'REPORT',
        body: reportBody,
        headers: { Depth: '1' },
      });

      if (response.status === 404) {
        const resolved = await resolveCalendarSlug(args.calendarName);
        if (resolved !== args.calendarName) {
          slug = resolved;
          response = await fetchCalDAV(buildUrl(), {
            method: 'REPORT',
            body: reportBody,
            headers: { Depth: '1' },
          });
        }
      }

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(
          `CalDAV REPORT failed for calendar "${args.calendarName}": ${response.status} - ${errorText}`
        );
      }

      const responseText = await response.text();
      let events = parseVEvents(responseText);

      // Sort by start date
      events.sort((a, b) => a.dtstart.localeCompare(b.dtstart));

      // Apply limit
      if (events.length > limit) {
        events = events.slice(0, limit);
      }

      if (events.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No events found in "${args.calendarName}" between ${formatICalDate(fromStr)} and ${formatICalDate(toStr)}.`,
            },
          ],
        };
      }

      const formatted = events.map(formatEvent).join('\n\n');
      return {
        content: [
          {
            type: 'text' as const,
            text: `Events in "${args.calendarName}" (${events.length} found):\n\n${formatted}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error listing events: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Get a single event by UID.
 */
export const getEventTool = {
  name: 'get_event',
  title: 'Get Calendar Event',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get detailed information about a single calendar event by its UID, including full description, attendees, and recurrence rules.',
  inputSchema: z.object({
    uid: z.string().describe('The UID of the event'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
  }),
  handler: async (args: { uid: string; calendarName: string }) => {
    try {
      const { icalData } = await resolveEventByUid(args.calendarName, args.uid);

      // Parse and format the full event
      const fakeResponse = `<d:response><d:href>/</d:href><d:propstat><d:prop><d:getetag>"x"</d:getetag><c:calendar-data>${icalData}</c:calendar-data></d:prop></d:propstat></d:response>`;
      const events = parseVEvents(fakeResponse);

      if (events.length === 0) {
        throw new Error(`Event with UID "${args.uid}" could not be parsed`);
      }

      const event = events[0];
      let output = formatEvent(event);

      // Include full description without truncation for detail view
      if (event.description && event.description.length > 200) {
        output = output.replace(event.description.substring(0, 200) + '...', event.description);
      }

      return {
        content: [
          {
            type: 'text' as const,
            text: output,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Create a new calendar event.
 */
export const createEventTool = {
  name: 'create_event',
  title: 'Create Calendar Event',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Create a new calendar event in Nextcloud with date/time, location, description, attendees, and optional recurrence.',
  inputSchema: z.object({
    summary: z.string().describe('The event title'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
    dtstart: z
      .string()
      .describe('Start date/time in YYYYMMDD (all-day) or YYYYMMDDTHHmmssZ (timed) format'),
    dtend: z
      .string()
      .optional()
      .describe(
        'End date/time. For all-day events, this is the exclusive end date (day after last day). If omitted, defaults to 1 hour after start (timed) or next day (all-day).'
      ),
    location: z.string().optional().describe('Event location'),
    description: z.string().optional().describe('Event description'),
    status: z
      .enum(['TENTATIVE', 'CONFIRMED', 'CANCELLED'])
      .optional()
      .describe('Event status (default: CONFIRMED)'),
    transp: z
      .enum(['OPAQUE', 'TRANSPARENT'])
      .optional()
      .describe('Time transparency for free/busy (OPAQUE = busy, TRANSPARENT = free)'),
    categories: z.array(z.string()).optional().describe('Tags/categories'),
    attendees: z
      .array(
        z.object({
          email: z.string().describe('Attendee email'),
          cn: z.string().optional().describe('Display name'),
          role: z
            .enum(['REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'NON-PARTICIPANT', 'CHAIR'])
            .optional()
            .describe('Attendee role'),
          rsvp: z.boolean().optional().describe('Request RSVP (default: true)'),
        })
      )
      .optional()
      .describe('List of attendees'),
    rrule: z
      .string()
      .optional()
      .describe("Recurrence rule (e.g. 'FREQ=WEEKLY;BYDAY=MO,WE,FR' or 'FREQ=MONTHLY;COUNT=12')"),
    accessClass: z
      .enum(['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'])
      .optional()
      .describe('Event visibility classification'),
    alarm: z
      .number()
      .optional()
      .describe(
        'Single reminder in minutes before the event (e.g. 15 for 15 min before). For multiple reminders use alarms[].'
      ),
    alarms: z
      .array(z.number())
      .optional()
      .describe(
        'Multiple reminders in minutes before the event (e.g. [1440, 60] for 1 day and 1 hour before). Combined with alarm if both are set.'
      ),
    tzid: z
      .string()
      .optional()
      .describe(
        'IANA time zone (e.g. "Europe/Berlin"). When set, a VTIMEZONE block is emitted and dtstart/dtend use TZID instead of UTC. Required for reliable iOS Calendar and Thunderbird CalDAV sync of timed events. UTC inputs (trailing Z) are converted to wall-clock time in this zone.'
      ),
  }),
  handler: async (args: {
    summary: string;
    calendarName: string;
    dtstart: string;
    dtend?: string;
    location?: string;
    description?: string;
    status?: string;
    transp?: string;
    categories?: string[];
    attendees?: Array<{
      email: string;
      cn?: string;
      role?: string;
      rsvp?: boolean;
    }>;
    rrule?: string;
    accessClass?: string;
    alarm?: number;
    alarms?: number[];
    tzid?: string;
  }) => {
    try {
      const config = getNextcloudConfig();
      const eventUid = `${Date.now()}-${Math.random().toString(36).substring(7)}`;
      let slug = args.calendarName;
      const baseUrl = () => `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/`;

      // Validate the calendar supports VEVENT before building and sending the iCal payload.
      // If the original name is a display name (not a slug), retry once after resolving.
      try {
        await assertCalendarSupportsEvents(baseUrl(), args.calendarName);
      } catch (err) {
        if ((err as { code?: string }).code === 'CALENDAR_NOT_FOUND') {
          const resolved = await resolveCalendarSlug(args.calendarName);
          if (resolved !== args.calendarName) {
            slug = resolved;
            await assertCalendarSupportsEvents(baseUrl(), args.calendarName);
          } else {
            throw err;
          }
        } else {
          throw err;
        }
      }
      const isAllDay = args.dtstart.length === 8;

      const tzid = !isAllDay ? args.tzid : undefined;
      const localDtstart = tzid ? normalizeLocalICal(args.dtstart, tzid) : args.dtstart;

      // Calculate default end
      let dtend = args.dtend;
      if (!dtend) {
        if (isAllDay) {
          // All-day: next day
          const d = new Date(
            parseInt(args.dtstart.slice(0, 4)),
            parseInt(args.dtstart.slice(4, 6)) - 1,
            parseInt(args.dtstart.slice(6, 8)) + 1
          );
          dtend = `${d.getFullYear()}${String(d.getMonth() + 1).padStart(2, '0')}${String(d.getDate()).padStart(2, '0')}`;
        } else if (tzid) {
          // Timed with TZID: 1 hour after local wall time
          dtend = addMinutesToLocalICal(localDtstart, 60);
        } else {
          // Timed: 1 hour later (UTC)
          const startDate = new Date(
            parseInt(args.dtstart.slice(0, 4)),
            parseInt(args.dtstart.slice(4, 6)) - 1,
            parseInt(args.dtstart.slice(6, 8)),
            parseInt(args.dtstart.slice(9, 11)),
            parseInt(args.dtstart.slice(11, 13)),
            parseInt(args.dtstart.slice(13, 15))
          );
          startDate.setHours(startDate.getHours() + 1);
          dtend = startDate.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
        }
      }
      const localDtend = tzid ? normalizeLocalICal(dtend, tzid) : dtend;

      let dtstartProp: string;
      let dtendProp: string;
      if (isAllDay) {
        dtstartProp = `DTSTART;VALUE=DATE:${args.dtstart}`;
        dtendProp = `DTEND;VALUE=DATE:${dtend}`;
      } else if (tzid) {
        dtstartProp = `DTSTART;TZID=${tzid}:${localDtstart}`;
        dtendProp = `DTEND;TZID=${tzid}:${localDtend}`;
      } else {
        dtstartProp = `DTSTART:${args.dtstart}`;
        dtendProp = `DTEND:${dtend}`;
      }

      const vevent = buildVEventPayload({
        uid: eventUid,
        summary: args.summary,
        dtstartProp,
        dtendProp,
        tzid,
        location: args.location,
        description: args.description,
        status: args.status,
        transp: args.transp,
        accessClass: args.accessClass,
        categories: args.categories,
        attendees: args.attendees,
        rrule: args.rrule,
        alarms: collectAlarmMinutes(args.alarm, args.alarms),
        organizerUser: config.user,
      });

      await putNewEvent(slug, eventUid, vevent);

      const timeInfo = isAllDay
        ? formatICalDate(args.dtstart)
        : `${formatICalDate(args.dtstart)} - ${formatICalDate(dtend)}`;
      return {
        content: [
          {
            type: 'text' as const,
            text: `Event created successfully: ${args.summary}\n  When: ${timeInfo}\n  UID: ${eventUid}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Update an existing calendar event by UID.
 */
export const updateEventTool = {
  name: 'update_event',
  title: 'Update Calendar Event',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Update an existing calendar event's fields by UID. Uses CalDAV ETag-based optimistic concurrency.",
  inputSchema: z.object({
    uid: z.string().describe('The UID of the event to update'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
    summary: z.string().optional().describe('New event title'),
    dtstart: z.string().optional().describe('New start date/time (YYYYMMDD or YYYYMMDDTHHmmssZ)'),
    dtend: z.string().nullable().optional().describe('New end date/time, or null to remove'),
    location: z.string().nullable().optional().describe('New location, or null to remove'),
    description: z.string().nullable().optional().describe('New description, or null to remove'),
    status: z.enum(['TENTATIVE', 'CONFIRMED', 'CANCELLED']).optional().describe('New status'),
    transp: z.enum(['OPAQUE', 'TRANSPARENT']).optional().describe('New transparency'),
    categories: z.array(z.string()).optional().describe('Replace all tags with these'),
    accessClass: z
      .enum(['PUBLIC', 'PRIVATE', 'CONFIDENTIAL'])
      .optional()
      .describe('New classification'),
    rrule: z.string().nullable().optional().describe('New recurrence rule, or null to remove'),
    alarm: z
      .number()
      .nullable()
      .optional()
      .describe(
        'Single reminder in minutes before the event, or null to remove all existing alarms. For multiple reminders use alarms[].'
      ),
    alarms: z
      .array(z.number())
      .optional()
      .describe(
        'Multiple reminders in minutes before the event (e.g. [1440, 60]). Replaces existing alarms; combined with alarm if both are set.'
      ),
    attendees: z
      .array(
        z.object({
          email: z.string().describe('Attendee email'),
          cn: z.string().optional().describe('Display name'),
          role: z
            .enum(['REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'NON-PARTICIPANT', 'CHAIR'])
            .optional()
            .describe('Attendee role'),
          rsvp: z.boolean().optional().describe('Request RSVP (default: true)'),
        })
      )
      .nullable()
      .optional()
      .describe('Replace attendees list, or null to remove all attendees'),
    tzid: z
      .string()
      .optional()
      .describe(
        'IANA time zone (e.g. "Europe/Berlin") for the updated dtstart/dtend. When set, a VTIMEZONE block is added (if missing) and DTSTART/DTEND use TZID instead of UTC. Required for iOS/Thunderbird CalDAV sync.'
      ),
  }),
  handler: async (args: {
    uid: string;
    calendarName: string;
    summary?: string;
    dtstart?: string;
    dtend?: string | null;
    location?: string | null;
    description?: string | null;
    status?: string;
    transp?: string;
    categories?: string[];
    accessClass?: string;
    rrule?: string | null;
    alarm?: number | null;
    alarms?: number[];
    attendees?: Array<{
      email: string;
      cn?: string;
      role?: string;
      rsvp?: boolean;
    }> | null;
    tzid?: string;
  }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag, icalData } = await resolveEventByUid(args.calendarName, args.uid);

      let modified = unfoldICalLines(icalData);

      if (args.summary !== undefined) {
        modified = setICalProperty(modified, 'SUMMARY', escapeICalValue(args.summary));
      }
      // Only timed datetimes carry a TZID; all-day (length 8) values are dates.
      const dtstartTz =
        args.tzid && args.dtstart && args.dtstart.length > 8 ? args.tzid : undefined;
      const dtendTz = args.tzid && args.dtend && args.dtend.length > 8 ? args.tzid : undefined;
      if (args.dtstart !== undefined) {
        modified = setICalDateProperty(modified, 'DTSTART', args.dtstart, dtstartTz);
      }
      if (args.dtend !== undefined) {
        modified = setICalDateProperty(modified, 'DTEND', args.dtend, dtendTz);
      }
      if (args.tzid && (dtstartTz || dtendTz)) {
        modified = ensureVTimezone(modified, args.tzid);
      }
      if (args.location !== undefined) {
        modified = setICalProperty(
          modified,
          'LOCATION',
          args.location ? escapeICalValue(args.location) : null
        );
      }
      if (args.description !== undefined) {
        modified = setICalProperty(
          modified,
          'DESCRIPTION',
          args.description ? escapeICalValue(args.description) : null
        );
      }
      if (args.status !== undefined) {
        modified = setICalProperty(modified, 'STATUS', args.status);
      }
      if (args.transp !== undefined) {
        modified = setICalProperty(modified, 'TRANSP', args.transp);
      }
      if (args.accessClass !== undefined) {
        modified = setICalProperty(modified, 'CLASS', args.accessClass);
      }
      if (args.rrule !== undefined) {
        modified = setICalProperty(modified, 'RRULE', args.rrule);
      }
      if (args.categories !== undefined) {
        modified = modified.replace(/^CATEGORIES(;[^:]*)?:.*\r?\n?/gm, '');
        if (args.categories.length > 0) {
          modified = modified.replace(
            /END:VEVENT/,
            `CATEGORIES:${args.categories.map(escapeICalValue).join(',')}\r\nEND:VEVENT`
          );
        }
      }

      // Handle alarms (VALARM). Any alarm/alarms input replaces all existing
      // VALARM blocks; alarm:null clears them without adding new ones.
      if (args.alarm !== undefined || args.alarms !== undefined) {
        modified = modified.replace(/BEGIN:VALARM[\s\S]*?END:VALARM\r?\n?/g, '');
        if (args.alarm !== null) {
          for (const mins of collectAlarmMinutes(args.alarm, args.alarms)) {
            const valarm = buildVAlarm(mins);
            if (valarm) {
              modified = modified.replace(/END:VEVENT/, `${valarm}\r\nEND:VEVENT`);
            }
          }
        }
      }

      // Handle attendees
      if (args.attendees !== undefined) {
        // Remove existing ATTENDEE and ORGANIZER lines
        modified = modified.replace(/^ATTENDEE[;:].*\r?\n?/gm, '');
        modified = modified.replace(/^ORGANIZER[;:].*\r?\n?/gm, '');
        if (args.attendees !== null && args.attendees.length > 0) {
          let attendeeLines = `ORGANIZER;CN=${config.user}:mailto:${config.user}`;
          for (const attendee of args.attendees) {
            let atLine = 'ATTENDEE';
            if (attendee.cn) atLine += `;CN=${attendee.cn}`;
            atLine += `;ROLE=${attendee.role || 'REQ-PARTICIPANT'}`;
            atLine += `;PARTSTAT=NEEDS-ACTION`;
            if (attendee.rsvp !== false) atLine += `;RSVP=TRUE`;
            atLine += `:mailto:${attendee.email}`;
            attendeeLines += `\r\n${atLine}`;
          }
          modified = modified.replace(/END:VEVENT/, `${attendeeLines}\r\nEND:VEVENT`);
        }
      }

      // Update timestamps
      const now = icalNow();
      modified = setICalProperty(modified, 'LAST-MODIFIED', now);
      modified = setICalProperty(modified, 'DTSTAMP', now);

      const putUrl = `${config.url}${href}`;
      const putResponse = await fetchCalDAV(putUrl, {
        method: 'PUT',
        body: modified,
        headers: {
          'Content-Type': 'text/calendar; charset=utf-8',
          'If-Match': etag,
        },
      });

      if (putResponse.ok) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Event updated successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (putResponse.status === 412) {
        throw new Error('Event was modified by another client (ETag mismatch). Please retry.');
      } else {
        const errorText = await putResponse.text();
        throw new Error(`Failed to update event: ${putResponse.status} - ${errorText}`);
      }
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error updating event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Delete a calendar event by UID.
 */
export const deleteEventTool = {
  name: 'delete_event',
  title: 'Delete Calendar Event',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: true,
    openWorldHint: false,
  },
  description: 'Delete a calendar event from Nextcloud by UID. This action is irreversible.',
  inputSchema: z.object({
    uid: z.string().describe('The UID of the event to delete'),
    calendarName: z
      .string()
      .describe('The calendar name. Use list_calendars to find available calendar names.'),
  }),
  handler: async (args: { uid: string; calendarName: string }) => {
    try {
      const config = getNextcloudConfig();
      const { href, etag } = await resolveEventByUid(args.calendarName, args.uid);

      const deleteUrl = `${config.url}${href}`;
      const response = await fetchCalDAV(deleteUrl, {
        method: 'DELETE',
        headers: {
          'If-Match': etag,
        },
      });

      if (response.ok || response.status === 204) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `Event deleted successfully (UID: ${args.uid})`,
            },
          ],
        };
      } else if (response.status === 412) {
        throw new Error('Event was modified by another client (ETag mismatch). Please retry.');
      } else {
        const errorText = await response.text();
        throw new Error(`Failed to delete event: ${response.status} - ${errorText}`);
      }
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error deleting event: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Get upcoming events across all (or one specific) calendar within N days.
 */
export const getUpcomingEventsTool = {
  name: 'get_upcoming_events',
  title: 'Get Upcoming Events',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    'Get upcoming events from the next N days across all Nextcloud calendars (or one specific calendar), sorted by start time.',
  inputSchema: z.object({
    daysAhead: z
      .number()
      .min(1)
      .max(90)
      .optional()
      .describe('How many days ahead to look (default: 7)'),
    limit: z
      .number()
      .min(1)
      .max(200)
      .optional()
      .describe('Maximum number of events to return (default: 10)'),
    calendarName: z
      .string()
      .optional()
      .describe('Restrict to one calendar. Defaults to all event-capable calendars.'),
  }),
  handler: async (args: { daysAhead?: number; limit?: number; calendarName?: string }) => {
    try {
      const config = getNextcloudConfig();
      const daysAhead = args.daysAhead || 7;
      const limit = args.limit || 10;

      const all = await fetchAllCalendars();
      let calendars = eventCapableCalendars(all);

      if (args.calendarName) {
        const slug = await resolveCalendarSlug(args.calendarName);
        const wanted = args.calendarName.toLowerCase();
        calendars = calendars.filter((c) => {
          const cSlug = c.url.split('/').filter(Boolean).pop() ?? '';
          return cSlug === slug || c.displayName.toLowerCase() === wanted;
        });
      }

      if (calendars.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: 'No event-capable calendars found. Use list_calendars to check your calendars.',
            },
          ],
        };
      }

      const now = new Date();
      const end = new Date(now);
      end.setDate(end.getDate() + daysAhead);
      const fromStr = toICalDateTime(now.toISOString());
      const toStr = toICalDateTime(end.toISOString());

      const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="${fromStr}" end="${toStr}" />
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      const found: Array<ParsedEvent & { calendarLabel: string }> = [];
      const failed: string[] = [];

      for (const cal of calendars) {
        const slug = cal.url.split('/').filter(Boolean).pop() ?? '';
        try {
          const response = await fetchCalDAV(
            `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/`,
            { method: 'REPORT', body: reportBody, headers: { Depth: '1' } }
          );
          if (!response.ok) {
            failed.push(cal.displayName);
            continue;
          }
          const events = parseVEvents(await response.text());
          for (const e of events) found.push({ ...e, calendarLabel: cal.displayName });
        } catch {
          failed.push(cal.displayName);
        }
      }

      found.sort((a, b) => (eventStartMs(a, 'UTC') ?? 0) - (eventStartMs(b, 'UTC') ?? 0));

      const limited = found.slice(0, limit);
      if (limited.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No upcoming events in the next ${daysAhead} days.`,
            },
          ],
        };
      }

      const formatted = limited
        .map((e) => `${formatEvent(e)}\n    Calendar: ${e.calendarLabel}`)
        .join('\n\n');

      let text = `Upcoming events (next ${daysAhead} days, ${limited.length} found):\n\n${formatted}`;
      if (found.length > limited.length) {
        text += `\n\n(Showing first ${limited.length} of ${found.length} events — raise limit or daysAhead to see more.)`;
      }
      if (failed.length > 0) {
        text += `\n\nNote: could not read: ${failed.join(', ')}`;
      }

      return {
        content: [
          {
            type: 'text' as const,
            text,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error getting upcoming events: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Quick meeting creation with sensible defaults.
 */
export const createMeetingTool = {
  name: 'create_meeting',
  title: 'Create Meeting',
  annotations: {
    readOnlyHint: false,
    destructiveHint: false,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Quick meeting creation with smart defaults: computes the end time from a duration, sets status CONFIRMED, and adds a reminder. For full control over every property, use create_event instead.',
  inputSchema: z.object({
    title: z.string().describe('Meeting title'),
    date: z.string().describe('Meeting date (YYYY-MM-DD format, e.g. 2026-01-15)'),
    time: z.string().describe('Meeting start time (HH:MM format, e.g. 14:00)'),
    durationMinutes: z
      .number()
      .min(5)
      .max(1440)
      .optional()
      .describe('Meeting duration in minutes (default: 60)'),
    calendarName: z
      .string()
      .optional()
      .describe('Calendar to create the meeting in (default: personal)'),
    attendees: z.string().optional().describe('Comma-separated email addresses of attendees'),
    location: z.string().optional().describe('Meeting location'),
    description: z.string().optional().describe('Meeting description/agenda'),
    reminderMinutes: z
      .number()
      .min(0)
      .optional()
      .describe('Reminder in minutes before the meeting (default: 15)'),
    timezone: z
      .string()
      .optional()
      .describe(
        'IANA time zone the date/time are expressed in (e.g. "Europe/Berlin"). When omitted, the given time is treated as UTC.'
      ),
  }),
  handler: async (args: {
    title: string;
    date: string;
    time: string;
    durationMinutes?: number;
    calendarName?: string;
    attendees?: string;
    location?: string;
    description?: string;
    reminderMinutes?: number;
    timezone?: string;
  }) => {
    try {
      const config = getNextcloudConfig();
      const duration = args.durationMinutes || 60;
      const reminder = args.reminderMinutes ?? 15;

      const dateM = args.date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (!dateM) throw new Error(`Invalid date "${args.date}" — expected YYYY-MM-DD`);
      const timeM = args.time.match(/^(\d{1,2}):(\d{2})$/);
      if (!timeM) throw new Error(`Invalid time "${args.time}" — expected HH:MM`);
      const [, yy, mm, dd] = dateM;
      const hh = timeM[1].padStart(2, '0');
      const mi = timeM[2];

      const tzid = args.timezone || undefined;
      if (tzid && !isValidTimezone(tzid)) throw new Error(`Unknown time zone: ${tzid}`);

      const eventUid = `${Date.now()}-${Math.random().toString(36).substring(7)}`;
      let slug = args.calendarName || 'personal';
      const baseUrl = () => `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/`;

      try {
        await assertCalendarSupportsEvents(baseUrl(), slug);
      } catch (err) {
        if ((err as { code?: string }).code === 'CALENDAR_NOT_FOUND') {
          const resolved = await resolveCalendarSlug(slug);
          if (resolved !== slug) {
            slug = resolved;
            await assertCalendarSupportsEvents(baseUrl(), slug);
          } else {
            throw err;
          }
        } else {
          throw err;
        }
      }

      let dtstartProp: string;
      let dtendProp: string;
      let timeInfo: string;
      if (tzid) {
        const localStart = `${yy}${mm}${dd}T${hh}${mi}00`;
        const localEnd = addMinutesToLocalICal(localStart, duration);
        dtstartProp = `DTSTART;TZID=${tzid}:${localStart}`;
        dtendProp = `DTEND;TZID=${tzid}:${localEnd}`;
        timeInfo = `${args.date} ${hh}:${mi} (${tzid})`;
      } else {
        const startMs = Date.UTC(+yy, +mm - 1, +dd, +hh, +mi, 0);
        const endMs = startMs + duration * 60_000;
        const toICalZ = (ms: number) =>
          new Date(ms).toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
        dtstartProp = `DTSTART:${toICalZ(startMs)}`;
        dtendProp = `DTEND:${toICalZ(endMs)}`;
        timeInfo = `${args.date} ${hh}:${mi} UTC`;
      }

      const attendees = (args.attendees || '')
        .split(',')
        .map((a) => a.trim())
        .filter(Boolean)
        .map((email) => ({ email }));

      const payload = buildVEventPayload({
        uid: eventUid,
        summary: args.title,
        dtstartProp,
        dtendProp,
        tzid,
        location: args.location,
        description: args.description,
        status: 'CONFIRMED',
        attendees: attendees.length > 0 ? attendees : undefined,
        alarms: reminder > 0 ? [reminder] : [],
        organizerUser: config.user,
      });

      await putNewEvent(slug, eventUid, payload);

      return {
        content: [
          {
            type: 'text' as const,
            text: `Meeting created: ${args.title}\n  When: ${timeInfo} (${duration} min)\n  Calendar: ${slug}\n  UID: ${eventUid}`,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error creating meeting: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Find free time slots in the user's calendars.
 */
export const findAvailabilityTool = {
  name: 'find_availability',
  title: 'Find Availability',
  annotations: {
    readOnlyHint: true,
    destructiveHint: false,
    idempotentHint: true,
    openWorldHint: false,
  },
  description:
    "Find free time slots in the user's Nextcloud calendars long enough to fit a meeting of the given duration. Considers busy (opaque, non-cancelled) events across all event-capable calendars; supports business hours, weekend exclusion and preferred time ranges. Returns the maximal free windows — pick any sub-range of one. Attendee free/busy is not consulted.",
  inputSchema: z.object({
    durationMinutes: z
      .number()
      .min(1)
      .max(480)
      .describe('Required slot length in minutes (e.g. 60)'),
    dateRangeStart: z
      .string()
      .optional()
      .describe(
        'First day to consider (YYYY-MM-DD, default: today). Past slots are never returned.'
      ),
    dateRangeEnd: z
      .string()
      .optional()
      .describe('Last day to consider (YYYY-MM-DD, default: start + 7 days)'),
    businessHoursOnly: z
      .boolean()
      .optional()
      .describe('Only suggest slots between 09:00 and 17:00 (default: true)'),
    excludeWeekends: z.boolean().optional().describe('Skip Saturdays and Sundays (default: true)'),
    preferredTimes: z
      .string()
      .optional()
      .describe(
        'Preferred time ranges as "HH:MM-HH:MM" (comma-separated). When given, these replace business hours.'
      ),
    includeAllDay: z.boolean().optional().describe('Treat all-day events as busy (default: false)'),
    timezone: z
      .string()
      .optional()
      .describe('IANA time zone for hour/weekend semantics (default: UTC)'),
  }),
  handler: async (args: {
    durationMinutes: number;
    dateRangeStart?: string;
    dateRangeEnd?: string;
    businessHoursOnly?: boolean;
    excludeWeekends?: boolean;
    preferredTimes?: string;
    includeAllDay?: boolean;
    timezone?: string;
  }) => {
    try {
      const config = getNextcloudConfig();
      const tz = args.timezone || 'UTC';
      if (!isValidTimezone(tz)) throw new Error(`Unknown time zone: ${tz}`);

      const parseDay = (v: string): { y: number; mo: number; d: number } => {
        const m = v.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) throw new Error(`Invalid date "${v}" — expected YYYY-MM-DD`);
        return { y: +m[1], mo: +m[2], d: +m[3] };
      };

      const nowMs = Date.now();
      const nowParts = zonedDateParts(nowMs, tz);
      const startDay = args.dateRangeStart
        ? parseDay(args.dateRangeStart)
        : { y: nowParts.year, mo: nowParts.month, d: nowParts.day };
      let windowStartMs = zonedWallTimeToMs(startDay.y, startDay.mo, startDay.d, 0, 0, 0, tz);
      windowStartMs = Math.max(windowStartMs, nowMs);

      let endDay: { y: number; mo: number; d: number };
      if (args.dateRangeEnd) {
        endDay = parseDay(args.dateRangeEnd);
      } else {
        const shifted = new Date(
          Date.UTC(startDay.y, startDay.mo - 1, startDay.d) + 7 * 86_400_000
        );
        endDay = {
          y: shifted.getUTCFullYear(),
          mo: shifted.getUTCMonth() + 1,
          d: shifted.getUTCDate(),
        };
      }
      const windowEndMs = nextZonedDay(
        zonedWallTimeToMs(endDay.y, endDay.mo, endDay.d, 0, 0, 0, tz),
        tz
      );
      if (windowEndMs <= windowStartMs) {
        throw new Error('dateRangeEnd must be after dateRangeStart (and in the future)');
      }

      const calendars = eventCapableCalendars(await fetchAllCalendars());
      const fromStr = toICalDateTime(new Date(windowStartMs).toISOString());
      const toStr = toICalDateTime(new Date(windowEndMs).toISOString());

      const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="${fromStr}" end="${toStr}" />
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      const busySpans: Span[] = [];
      const failed: string[] = [];
      for (const cal of calendars) {
        const slug = cal.url.split('/').filter(Boolean).pop() ?? '';
        try {
          const response = await fetchCalDAV(
            `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/`,
            { method: 'REPORT', body: reportBody, headers: { Depth: '1' } }
          );
          if (!response.ok) {
            failed.push(cal.displayName);
            continue;
          }
          for (const e of parseVEvents(await response.text())) {
            const span = eventToBusySpan(e, args.includeAllDay === true, tz);
            if (span) busySpans.push(span);
          }
        } catch {
          failed.push(cal.displayName);
        }
      }

      const preferredTimes = args.preferredTimes ? parsePreferredTimes(args.preferredTimes) : [];
      const slots = computeFreeSlots({
        durationMs: args.durationMinutes * 60_000,
        windowStartMs,
        windowEndMs,
        timeZone: tz,
        businessHoursOnly: args.businessHoursOnly !== false,
        excludeWeekends: args.excludeWeekends !== false,
        preferredTimes,
        busySpans,
      });

      const windowNote = `Searched ${formatZonedTimestamp(windowStartMs, tz)} → ${formatZonedTimestamp(windowEndMs, tz)} (${tz}) across ${calendars.length} calendar(s).`;

      if (slots.length === 0) {
        return {
          content: [
            {
              type: 'text' as const,
              text: `No free slots of ${args.durationMinutes} min or longer found.\n  ${windowNote}`,
            },
          ],
        };
      }

      const lines = slots.map((s, i) => {
        const mins = Math.round((s.endMs - s.startMs) / 60_000);
        return `${i + 1}. ${formatZonedTimestamp(s.startMs, tz)} – ${formatZonedTimestamp(s.endMs, tz)} (${mins} min free)`;
      });

      let text = `Available slots for ${args.durationMinutes} min in ${tz}:\n\n${lines.join('\n')}\n\n  ${windowNote}`;
      if (failed.length > 0) {
        text += `\n  Note: could not read ${failed.join(', ')} — their busy time is NOT accounted for.`;
      }

      return {
        content: [
          {
            type: 'text' as const,
            text,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error finding availability: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Derive a CalDAV collection slug from a calendar name.
 */
export function slugifyCalendarName(name: string): string {
  const slug = name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return slug || `calendar-${Date.now()}`;
}

/**
 * Manage calendar collections: create, update, delete, list.
 */
export const manageCalendarTool = {
  name: 'manage_calendar',
  title: 'Manage Calendar',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Manage Nextcloud calendar collections: create a new calendar, rename it, change its color or description, delete it, or list them. Deletion is irreversible and removes all events in the calendar.',
  inputSchema: z.object({
    action: z.enum(['create', 'delete', 'update', 'list']).describe('Action to perform'),
    calendarName: z
      .string()
      .optional()
      .describe('Calendar name or URL slug (required for create/delete/update)'),
    displayName: z.string().optional().describe('Human-readable name (create/update)'),
    description: z.string().optional().describe('Calendar description (create/update)'),
    color: z.string().optional().describe('Hex color code, e.g. "#1976D2" (create/update)'),
  }),
  handler: async (args: {
    action: string;
    calendarName?: string;
    displayName?: string;
    description?: string;
    color?: string;
  }) => {
    try {
      const config = getNextcloudConfig();
      const home = `${config.url}/remote.php/dav/calendars/${config.user}/`;

      if (args.action === 'list') {
        const calendars = await fetchAllCalendars();
        if (calendars.length === 0) {
          return {
            content: [
              {
                type: 'text' as const,
                text: 'No calendars found.',
              },
            ],
          };
        }
        return {
          content: [
            {
              type: 'text' as const,
              text: `Calendars (${calendars.length} found):\n\n${calendars.map(formatCalendar).join('\n\n')}`,
            },
          ],
        };
      }

      if (!args.calendarName) {
        throw new Error('calendarName is required for this action');
      }

      if (args.action === 'create') {
        const slug = slugifyCalendarName(args.calendarName);
        const url = `${home}${slug}/`;
        const mkcalendarBody = `<?xml version="1.0" encoding="UTF-8"?>
<mkcalendar xmlns="urn:ietf:params:xml:ns:caldav" xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">
  <d:set>
    <d:prop>
      <d:displayname>${encodeXmlEntities(args.displayName || args.calendarName)}</d:displayname>
      <cs:calendar-color>${encodeXmlEntities(args.color || '#1976D2')}</cs:calendar-color>
      <caldav:calendar-description xmlns:caldav="urn:ietf:params:xml:ns:caldav">${encodeXmlEntities(args.description || '')}</caldav:calendar-description>
      <caldav:supported-calendar-component-set xmlns:caldav="urn:ietf:params:xml:ns:caldav">
        <caldav:comp name="VEVENT"/>
        <caldav:comp name="VTODO"/>
      </caldav:supported-calendar-component-set>
    </d:prop>
  </d:set>
</mkcalendar>`;

        const response = await fetchCalDAV(url, { method: 'MKCALENDAR', body: mkcalendarBody });
        if (response.status === 201) {
          return {
            content: [
              {
                type: 'text' as const,
                text: `Calendar created: ${args.displayName || args.calendarName} (slug: ${slug})\n  URL: ${url}`,
              },
            ],
          };
        }
        const errorText = await response.text();
        throw new Error(
          `Failed to create calendar: ${response.status} - ${errorText.slice(0, 300)}`
        );
      }

      if (args.action === 'update') {
        const slug = await resolveCalendarSlug(args.calendarName);
        const url = `${home}${slug}/`;
        const sets: string[] = [];
        if (args.displayName !== undefined) {
          sets.push(`<d:displayname>${encodeXmlEntities(args.displayName)}</d:displayname>`);
        }
        if (args.description !== undefined) {
          sets.push(
            `<c:calendar-description>${encodeXmlEntities(args.description)}</c:calendar-description>`
          );
        }
        if (args.color !== undefined) {
          sets.push(`<cs:calendar-color>${encodeXmlEntities(args.color)}</cs:calendar-color>`);
        }
        if (sets.length === 0) {
          throw new Error('Nothing to update: provide displayName, description, or color');
        }
        const propPatchBody = `<?xml version="1.0" encoding="UTF-8"?>
<d:propertyupdate xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">
  <d:set>
    <d:prop>
      ${sets.join('\n      ')}
    </d:prop>
  </d:set>
</d:propertyupdate>`;

        const response = await fetchCalDAV(url, { method: 'PROPPATCH', body: propPatchBody });
        if (response.status === 200 || response.status === 207) {
          return {
            content: [
              {
                type: 'text' as const,
                text: `Calendar updated: ${slug}`,
              },
            ],
          };
        }
        const errorText = await response.text();
        throw new Error(
          `Failed to update calendar: ${response.status} - ${errorText.slice(0, 300)}`
        );
      }

      if (args.action === 'delete') {
        const slug = await resolveCalendarSlug(args.calendarName);
        const url = `${home}${slug}/`;
        const response = await fetchCalDAV(url, { method: 'DELETE' });
        if (response.status === 204 || response.status === 404) {
          return {
            content: [
              {
                type: 'text' as const,
                text: `Calendar deleted: ${slug}`,
              },
            ],
          };
        }
        const errorText = await response.text();
        throw new Error(
          `Failed to delete calendar: ${response.status} - ${errorText.slice(0, 300)}`
        );
      }

      throw new Error(`Unknown action: ${args.action}`);
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error managing calendar: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Bulk update/delete/move of events matching filter criteria.
 */
export const bulkOperationsTool = {
  name: 'bulk_operations',
  title: 'Calendar Bulk Operations',
  annotations: {
    readOnlyHint: false,
    destructiveHint: true,
    idempotentHint: false,
    openWorldHint: false,
  },
  description:
    'Perform a bulk update, delete, or move on calendar events matching filter criteria (title, location, categories, status, date range). Recurring series are skipped unless applyToSeries is set. Capped at maxCount events per call as a safety net. For full control, use update_event/delete_event per UID.',
  inputSchema: z.object({
    operation: z.enum(['update', 'delete', 'move']).describe('Operation to perform'),
    titleContains: z
      .string()
      .optional()
      .describe('Match events whose title contains this text (case-insensitive)'),
    locationContains: z
      .string()
      .optional()
      .describe('Match events whose location contains this text (case-insensitive)'),
    categories: z
      .string()
      .optional()
      .describe('Match events containing any of these categories (comma-separated)'),
    calendarName: z
      .string()
      .optional()
      .describe('Restrict to one calendar (default: all event-capable calendars)'),
    startDate: z
      .string()
      .optional()
      .describe('Only events starting on/after this date (YYYY-MM-DD)'),
    endDate: z
      .string()
      .optional()
      .describe('Only events starting on/before this date (YYYY-MM-DD)'),
    status: z
      .enum(['CONFIRMED', 'TENTATIVE', 'CANCELLED'])
      .optional()
      .describe('Match events with this status'),
    newTitle: z.string().optional().describe('New title (update)'),
    newDescription: z
      .string()
      .nullable()
      .optional()
      .describe('New description, or null to remove (update)'),
    newLocation: z
      .string()
      .nullable()
      .optional()
      .describe('New location, or null to remove (update)'),
    newCategories: z
      .string()
      .optional()
      .describe('New categories, comma-separated; empty string clears them (update)'),
    newStatus: z
      .enum(['CONFIRMED', 'TENTATIVE', 'CANCELLED'])
      .optional()
      .describe('New status (update)'),
    newReminderMinutes: z
      .number()
      .optional()
      .describe('New single reminder in minutes before the event (update)'),
    targetCalendar: z.string().optional().describe('Destination calendar (move)'),
    applyToSeries: z
      .boolean()
      .optional()
      .describe('Also act on recurring series (default: false — recurring events are skipped)'),
    maxCount: z
      .number()
      .min(1)
      .max(200)
      .optional()
      .describe('Safety cap on affected events (default: 50)'),
  }),
  handler: async (args: {
    operation: string;
    titleContains?: string;
    locationContains?: string;
    categories?: string;
    calendarName?: string;
    startDate?: string;
    endDate?: string;
    status?: string;
    newTitle?: string;
    newDescription?: string | null;
    newLocation?: string | null;
    newCategories?: string;
    newStatus?: string;
    newReminderMinutes?: number;
    targetCalendar?: string;
    applyToSeries?: boolean;
    maxCount?: number;
  }) => {
    try {
      const config = getNextcloudConfig();
      const maxCount = args.maxCount || 50;

      if (args.operation === 'update') {
        const hasUpdateData =
          args.newTitle !== undefined ||
          args.newDescription !== undefined ||
          args.newLocation !== undefined ||
          args.newCategories !== undefined ||
          args.newStatus !== undefined ||
          args.newReminderMinutes !== undefined;
        if (!hasUpdateData) {
          throw new Error('No update data provided for update operation');
        }
      }
      if (args.operation === 'move' && !args.targetCalendar) {
        throw new Error('targetCalendar is required for move');
      }

      const parseDay = (v: string): string => {
        const m = v.match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!m) throw new Error(`Invalid date "${v}" — expected YYYY-MM-DD`);
        return `${m[1]}${m[2]}${m[3]}`;
      };
      let fromStr = '19700101T000000Z';
      let toStr = '21000101T000000Z';
      if (args.startDate) fromStr = `${parseDay(args.startDate)}T000000Z`;
      if (args.endDate) {
        const d = parseDay(args.endDate);
        const next = new Date(Date.UTC(+d.slice(0, 4), +d.slice(4, 6) - 1, +d.slice(6, 8) + 1));
        toStr = next.toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
      }

      const all = await fetchAllCalendars();
      let calendars = eventCapableCalendars(all);
      if (args.calendarName) {
        const slug = await resolveCalendarSlug(args.calendarName);
        const wanted = args.calendarName.toLowerCase();
        calendars = calendars.filter((c) => {
          const cSlug = c.url.split('/').filter(Boolean).pop() ?? '';
          return cSlug === slug || c.displayName.toLowerCase() === wanted;
        });
        if (calendars.length === 0) {
          throw new Error(`Calendar "${args.calendarName}" not found or does not support events`);
        }
      }

      const reportBody = `<?xml version="1.0" encoding="UTF-8"?>
<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:prop>
    <d:getetag />
    <c:calendar-data />
  </d:prop>
  <c:filter>
    <c:comp-filter name="VCALENDAR">
      <c:comp-filter name="VEVENT">
        <c:time-range start="${fromStr}" end="${toStr}" />
      </c:comp-filter>
    </c:comp-filter>
  </c:filter>
</c:calendar-query>`;

      interface Matched {
        event: ParsedEvent;
        calendar: ParsedCalendar;
      }
      const matched: Matched[] = [];
      const unreadable: string[] = [];
      for (const cal of calendars) {
        const slug = cal.url.split('/').filter(Boolean).pop() ?? '';
        try {
          const response = await fetchCalDAV(
            `${config.url}/remote.php/dav/calendars/${config.user}/${slug}/`,
            { method: 'REPORT', body: reportBody, headers: { Depth: '1' } }
          );
          if (!response.ok) {
            unreadable.push(cal.displayName);
            continue;
          }
          for (const e of parseVEvents(await response.text())) {
            matched.push({ event: e, calendar: cal });
          }
        } catch {
          unreadable.push(cal.displayName);
        }
      }

      const catFilter = (args.categories || '')
        .split(',')
        .map((s) => s.trim().toLowerCase())
        .filter(Boolean);

      const filtered = matched.filter(({ event }) => {
        if (
          args.titleContains &&
          !event.summary.toLowerCase().includes(args.titleContains.toLowerCase())
        )
          return false;
        if (
          args.locationContains &&
          !(event.location || '').toLowerCase().includes(args.locationContains.toLowerCase())
        )
          return false;
        if (catFilter.length > 0) {
          const evCats = event.categories.map((c) => c.toLowerCase());
          if (!catFilter.some((c) => evCats.includes(c))) return false;
        }
        if (args.status && (event.status || 'CONFIRMED') !== args.status) return false;
        return true;
      });

      const results: Array<Record<string, string | number>> = [];
      const targets: Matched[] = [];
      let skipped = 0;
      for (const m of filtered) {
        if ((m.event.rrule || m.event.recurrenceId) && !args.applyToSeries) {
          skipped += 1;
          results.push({
            title: m.event.summary,
            uid: m.event.uid,
            status: 'skipped',
            reason: 'recurring',
          });
        } else {
          targets.push(m);
        }
      }
      if (targets.length > maxCount) {
        for (const m of targets.slice(maxCount)) {
          results.push({
            title: m.event.summary,
            uid: m.event.uid,
            status: 'skipped',
            reason: `maxCount (${maxCount})`,
          });
        }
        targets.length = maxCount;
      }

      let verb = '';
      let done = 0;
      let failedCount = 0;

      if (args.operation === 'delete') {
        verb = 'deleted';
        for (const { event, calendar } of targets) {
          const slug = calendar.url.split('/').filter(Boolean).pop() ?? '';
          try {
            const { href, etag } = await resolveEventByUid(slug, event.uid);
            const response = await fetchCalDAV(`${config.url}${href}`, {
              method: 'DELETE',
              headers: { 'If-Match': etag },
            });
            if (response.ok || response.status === 204) {
              done += 1;
              results.push({ title: event.summary, uid: event.uid, status: 'deleted' });
            } else {
              failedCount += 1;
              results.push({
                title: event.summary,
                uid: event.uid,
                status: 'failed',
                error: `HTTP ${response.status}`,
              });
            }
          } catch (err) {
            failedCount += 1;
            results.push({
              title: event.summary,
              uid: event.uid,
              status: 'failed',
              error: err instanceof Error ? err.message : String(err),
            });
          }
        }
      } else if (args.operation === 'update') {
        verb = 'updated';
        for (const { event, calendar } of targets) {
          const slug = calendar.url.split('/').filter(Boolean).pop() ?? '';
          try {
            const { href, etag, icalData } = await resolveEventByUid(slug, event.uid);
            let modified = unfoldICalLines(icalData);
            if (args.newTitle !== undefined) {
              modified = setICalProperty(modified, 'SUMMARY', escapeICalValue(args.newTitle));
            }
            if (args.newDescription !== undefined) {
              modified = setICalProperty(
                modified,
                'DESCRIPTION',
                args.newDescription ? escapeICalValue(args.newDescription) : null
              );
            }
            if (args.newLocation !== undefined) {
              modified = setICalProperty(
                modified,
                'LOCATION',
                args.newLocation ? escapeICalValue(args.newLocation) : null
              );
            }
            if (args.newStatus !== undefined) {
              modified = setICalProperty(modified, 'STATUS', args.newStatus);
            }
            if (args.newCategories !== undefined) {
              modified = modified.replace(/^CATEGORIES(;[^:]*)?:.*\r?\n?/gm, '');
              const cats = args.newCategories
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean);
              if (cats.length > 0) {
                modified = modified.replace(
                  /END:VEVENT/,
                  `CATEGORIES:${cats.map(escapeICalValue).join(',')}\r\nEND:VEVENT`
                );
              }
            }
            if (args.newReminderMinutes !== undefined) {
              modified = modified.replace(/BEGIN:VALARM[\s\S]*?END:VALARM\r?\n?/g, '');
              const valarm = buildVAlarm(args.newReminderMinutes);
              if (valarm) {
                modified = modified.replace(/END:VEVENT/, `${valarm}\r\nEND:VEVENT`);
              }
            }
            const now = icalNow();
            modified = setICalProperty(modified, 'LAST-MODIFIED', now);
            modified = setICalProperty(modified, 'DTSTAMP', now);

            const put = await fetchCalDAV(`${config.url}${href}`, {
              method: 'PUT',
              body: modified,
              headers: {
                'Content-Type': 'text/calendar; charset=utf-8',
                'If-Match': etag,
              },
            });
            if (put.ok) {
              done += 1;
              results.push({ title: event.summary, uid: event.uid, status: 'updated' });
            } else {
              failedCount += 1;
              results.push({
                title: event.summary,
                uid: event.uid,
                status: 'failed',
                error: `HTTP ${put.status}`,
              });
            }
          } catch (err) {
            failedCount += 1;
            results.push({
              title: event.summary,
              uid: event.uid,
              status: 'failed',
              error: err instanceof Error ? err.message : String(err),
            });
          }
        }
      } else if (args.operation === 'move') {
        verb = 'moved';
        const targetSlug = await resolveCalendarSlug(args.targetCalendar!);
        await assertCalendarSupportsEvents(
          `${config.url}/remote.php/dav/calendars/${config.user}/${targetSlug}/`,
          args.targetCalendar!
        );
        for (const { event, calendar } of targets) {
          const slug = calendar.url.split('/').filter(Boolean).pop() ?? '';
          try {
            const { href, etag, icalData } = await resolveEventByUid(slug, event.uid);
            const targetUrl = `${config.url}/remote.php/dav/calendars/${config.user}/${targetSlug}/${event.uid}.ics`;
            const put = await fetchCalDAV(targetUrl, {
              method: 'PUT',
              body: icalData,
              headers: {
                'Content-Type': 'text/calendar; charset=utf-8',
                'If-None-Match': '*',
              },
            });
            if (put.status !== 201 && put.status !== 204) {
              failedCount += 1;
              results.push({
                title: event.summary,
                uid: event.uid,
                status: 'failed',
                error: `copy to ${targetSlug} failed: HTTP ${put.status}`,
              });
              continue;
            }
            const del = await fetchCalDAV(`${config.url}${href}`, {
              method: 'DELETE',
              headers: { 'If-Match': etag },
            });
            if (del.ok || del.status === 204) {
              done += 1;
              results.push({
                title: event.summary,
                uid: event.uid,
                status: 'moved',
                from: calendar.displayName,
                to: targetSlug,
              });
            } else {
              failedCount += 1;
              results.push({
                title: event.summary,
                uid: event.uid,
                status: 'failed',
                error: `copied to ${targetSlug} but the source copy could not be deleted (event now exists in both calendars): HTTP ${del.status}`,
              });
            }
          } catch (err) {
            failedCount += 1;
            results.push({
              title: event.summary,
              uid: event.uid,
              status: 'failed',
              error: err instanceof Error ? err.message : String(err),
            });
          }
        }
      }

      const detail = results
        .slice(0, 50)
        .map((r) => {
          const extra =
            r.reason !== undefined
              ? ` (${String(r.reason)})`
              : r.error !== undefined
                ? ` — ${String(r.error)}`
                : '';
          return `  [${r.status}] ${r.title} (UID: ${r.uid})${extra}`;
        })
        .join('\n');

      let text = `Bulk ${args.operation} complete: ${done} ${verb}, ${failedCount} failed, ${skipped} skipped (of ${filtered.length} matched).\n${detail}`;
      if (results.length > 50) {
        text += `\n  (Showing first 50 of ${results.length} results.)`;
      }
      if (unreadable.length > 0) {
        text += `\n\nNote: could not read: ${unreadable.join(', ')}`;
      }

      return {
        content: [
          {
            type: 'text' as const,
            text,
          },
        ],
      };
    } catch (error) {
      return {
        content: [
          {
            type: 'text' as const,
            text: `Error in bulk operation: ${error instanceof Error ? error.message : String(error)}`,
          },
        ],
        isError: true,
      };
    }
  },
};

/**
 * Export all Calendar app tools
 */
export const calendarTools = [
  listCalendarsTool,
  listEventsTool,
  getEventTool,
  createEventTool,
  updateEventTool,
  deleteEventTool,
  getUpcomingEventsTool,
  createMeetingTool,
  findAvailabilityTool,
  manageCalendarTool,
  bulkOperationsTool,
];
