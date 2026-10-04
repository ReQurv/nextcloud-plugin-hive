// SPDX-License-Identifier: MIT

import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock declarations
vi.mock('webdav', () => ({ createClient: vi.fn(() => ({})) }));
global.fetch = vi.fn();

describe('Calendar Tools', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    process.env.NEXTCLOUD_URL = 'https://cloud.example.com';
    process.env.NEXTCLOUD_USER = 'admin';
    process.env.NEXTCLOUD_PASSWORD = 'testpass';
  });

  describe('list_calendars', () => {
    it('should return formatted calendar list', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:x1="http://apple.com/ns/ical/" xmlns:x2="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/</d:href>
    <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/</d:href>
    <d:propstat><d:prop>
      <d:resourcetype><d:collection/><cal:calendar xmlns:cal="urn:ietf:params:xml:ns:caldav"/></d:resourcetype>
      <d:displayname>Personal</d:displayname>
      <cs:getctag>ctag-123</cs:getctag>
      <x1:calendar-color>#0082c9</x1:calendar-color>
      <x2:calendar-enabled>1</x2:calendar-enabled>
      <c:supported-calendar-component-set><c:comp name="VEVENT"/><c:comp name="VTODO"/></c:supported-calendar-component-set>
    </d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/work/</d:href>
    <d:propstat><d:prop>
      <d:resourcetype><d:collection/><cal:calendar xmlns:cal="urn:ietf:params:xml:ns:caldav"/></d:resourcetype>
      <d:displayname>Work</d:displayname>
      <x2:calendar-enabled>0</x2:calendar-enabled>
      <c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.content[0].text).toContain('2 found');
      expect(result.content[0].text).toContain('Personal');
      expect(result.content[0].text).toContain('#0082c9');
      expect(result.content[0].text).toContain('events');
      expect(result.content[0].text).toContain('tasks');
      expect(result.content[0].text).toContain('Work');
      expect(result.content[0].text).toContain('(disabled)');
    });

    it('should parse component support with cal: namespace prefix', async () => {
      const propfindResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:x1="http://apple.com/ns/ical/" xmlns:x2="http://owncloud.org/ns">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/</d:href>
    <d:propstat><d:prop>
      <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
      <d:displayname>Personal</d:displayname>
      <x2:calendar-enabled>1</x2:calendar-enabled>
      <cal:supported-calendar-component-set><cal:comp name="VEVENT"/><cal:comp name="VTODO"/></cal:supported-calendar-component-set>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(propfindResponse),
      });

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.content[0].text).toContain('1 found');
      expect(result.content[0].text).toContain('Personal');
      expect(result.content[0].text).toContain('events');
      expect(result.content[0].text).toContain('tasks');
    });

    it('should handle empty calendar list', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/</d:href>
    <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.content[0].text).toContain('No calendars found');
    });

    it('should handle errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Connection refused'));

      const { listCalendarsTool } = await import('../tools/apps/calendar.js');
      const result = await listCalendarsTool.handler();

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Connection refused');
    });
  });

  describe('list_events', () => {
    const veventResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-ev1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1
SUMMARY:Team standup
DTSTART:20240315T090000Z
DTEND:20240315T093000Z
LOCATION:Conference Room A
DESCRIPTION:Daily standup meeting
STATUS:CONFIRMED
ORGANIZER;CN=Alice:mailto:alice@example.com
ATTENDEE;CN=Bob;ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:bob@example.com
ATTENDEE;CN=Carol;ROLE=OPT-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:carol@example.com
CATEGORIES:work,meetings
RRULE:FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-2.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-ev2"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-2
SUMMARY:Company holiday
DTSTART;VALUE=DATE:20240401
DTEND;VALUE=DATE:20240402
STATUS:CONFIRMED
CLASS:PUBLIC
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should return formatted event list with all details', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(veventResponse),
      });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.content[0].text).toContain('2 found');
      // Event 1: timed event with attendees and recurrence
      expect(result.content[0].text).toContain('Team standup');
      expect(result.content[0].text).toContain('2024-03-15 09:00');
      expect(result.content[0].text).toContain('2024-03-15 09:30');
      expect(result.content[0].text).toContain('Conference Room A');
      expect(result.content[0].text).toContain('Daily standup meeting');
      expect(result.content[0].text).toContain('Alice');
      expect(result.content[0].text).toContain('Bob');
      expect(result.content[0].text).toContain('ACCEPTED');
      expect(result.content[0].text).toContain('Carol');
      expect(result.content[0].text).toContain('NEEDS-ACTION');
      expect(result.content[0].text).toContain('Tags: work, meetings');
      expect(result.content[0].text).toContain('Every week');
      expect(result.content[0].text).toContain('UID: event-1');
      // Event 2: all-day event
      expect(result.content[0].text).toContain('Company holiday');
      expect(result.content[0].text).toContain('all day');
      expect(result.content[0].text).toContain('UID: event-2');
    });

    it('should parse events when server uses cal: namespace prefix', async () => {
      const calPrefixResponse = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-ev1"</d:getetag>
      <cal:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-cal
SUMMARY:Namespace prefix test
DTSTART:20240315T090000Z
DTEND:20240315T093000Z
END:VEVENT
END:VCALENDAR</cal:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(calPrefixResponse),
      });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.content[0].text).toContain('1 found');
      expect(result.content[0].text).toContain('Namespace prefix test');
      expect(result.content[0].text).toContain('UID: event-cal');
    });

    it('should handle empty event list', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.content[0].text).toContain('No events found');
    });

    it('should handle errors', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'personal' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Network error');
    });
  });

  describe('get_event', () => {
    const eventResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-get1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1
SUMMARY:Project review
DTSTART:20240320T140000Z
DTEND:20240320T150000Z
DESCRIPTION:Quarterly project review with stakeholders
LOCATION:Board room
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should return event details', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(eventResponse),
      });

      const { getEventTool } = await import('../tools/apps/calendar.js');
      const result = await getEventTool.handler({ uid: 'event-1', calendarName: 'personal' });

      expect(result.content[0].text).toContain('Project review');
      expect(result.content[0].text).toContain('2024-03-20 14:00');
      expect(result.content[0].text).toContain('Board room');
      expect(result.content[0].text).toContain('Quarterly project review');
      expect(result.content[0].text).toContain('UID: event-1');
    });

    it('should handle event not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { getEventTool } = await import('../tools/apps/calendar.js');
      const result = await getEventTool.handler({ uid: 'nonexistent', calendarName: 'personal' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });
  });

  describe('create_event', () => {
    // PROPFIND response confirming the calendar supports VEVENT (new pre-flight check)
    const propfindVeventOk = {
      ok: true,
      status: 207,
      text: () =>
        Promise.resolve(
          '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' +
            '<d:response><d:propstat><d:prop>' +
            '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>' +
            '</d:prop></d:propstat></d:response></d:multistatus>'
        ),
    };

    // Mock REPORT response for post-creation verification
    const verifyResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-verify"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:test-uid
SUMMARY:Test
DTSTART:20240315T120000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should create a timed event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Lunch meeting',
        calendarName: 'personal',
        dtstart: '20240315T120000Z',
        dtend: '20240315T130000Z',
        location: 'Cafe downtown',
        description: 'Discuss Q2 plans',
      });

      expect(result.content[0].text).toContain('created successfully');
      expect(result.content[0].text).toContain('Lunch meeting');
      expect(result.content[0].text).toContain('UID:');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].method).toBe('PUT');
      expect(putCall[1].body).toContain('BEGIN:VEVENT');
      expect(putCall[1].body).toContain('SUMMARY:Lunch meeting');
      expect(putCall[1].body).toContain('DTSTART:20240315T120000Z');
      expect(putCall[1].body).toContain('DTEND:20240315T130000Z');
      expect(putCall[1].body).toContain('LOCATION:Cafe downtown');
      expect(putCall[1].body).toContain('DESCRIPTION:Discuss Q2 plans');
    });

    it('should create an all-day event with default end', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Holiday',
        calendarName: 'personal',
        dtstart: '20240401',
      });

      expect(result.content[0].text).toContain('created successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).toContain('DTSTART;VALUE=DATE:20240401');
      expect(putCall[1].body).toContain('DTEND;VALUE=DATE:20240402');
    });

    it('should create event with attendees and alarm', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Team sync',
        calendarName: 'personal',
        dtstart: '20240315T100000Z',
        attendees: [{ email: 'bob@example.com', cn: 'Bob', role: 'REQ-PARTICIPANT' }],
        alarm: 15,
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).toContain('ATTENDEE');
      expect(putCall[1].body).toContain('CN=Bob');
      expect(putCall[1].body).toContain('mailto:bob@example.com');
      expect(putCall[1].body).toContain('ORGANIZER');
      expect(putCall[1].body).toContain('BEGIN:VALARM');
      expect(putCall[1].body).toContain('TRIGGER:-PT15M');
      expect(putCall[1].body).toContain('END:VALARM');
    });

    it('should create event with multiple alarms via alarms[] (and merge with alarm)', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Release',
        calendarName: 'personal',
        dtstart: '20240315T100000Z',
        alarm: 15,
        alarms: [1440, 60],
      });

      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      // One VALARM block per reminder: alarms[] first, then alarm
      expect(putBody.match(/BEGIN:VALARM/g)).toHaveLength(3);
      expect(putBody).toContain('TRIGGER:-PT24H'); // 1440 min = 1 day
      expect(putBody).toContain('TRIGGER:-PT1H'); // 60 min
      expect(putBody).toContain('TRIGGER:-PT15M'); // 15 min
    });

    it('should create event with recurrence rule', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Weekly standup',
        calendarName: 'personal',
        dtstart: '20240315T090000Z',
        rrule: 'FREQ=WEEKLY;BYDAY=MO,WE,FR',
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).toContain('RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR');
    });

    it('should emit VTIMEZONE and TZID when tzid is provided (DST zone)', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Testeintrag',
        calendarName: 'personal',
        dtstart: '20260512T130000Z',
        dtend: '20260512T133000Z',
        tzid: 'Europe/Berlin',
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      const body: string = putCall[1].body;
      // VTIMEZONE block with both STANDARD and DAYLIGHT
      expect(body).toContain('BEGIN:VTIMEZONE');
      expect(body).toContain('TZID:Europe/Berlin');
      expect(body).toContain('BEGIN:STANDARD');
      expect(body).toContain('BEGIN:DAYLIGHT');
      expect(body).toContain('TZOFFSETFROM:+0100');
      expect(body).toContain('TZOFFSETTO:+0200');
      expect(body).toContain('END:VTIMEZONE');
      // 13:00Z in May is 15:00 Europe/Berlin (CEST)
      expect(body).toContain('DTSTART;TZID=Europe/Berlin:20260512T150000');
      expect(body).toContain('DTEND;TZID=Europe/Berlin:20260512T153000');
      // No trailing Z on the TZID'd properties
      expect(body).not.toMatch(/DTSTART;TZID=Europe\/Berlin:[^\r\n]*Z/);
    });

    it('should emit single STANDARD block for fixed-offset zone', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Tokyo meeting',
        calendarName: 'personal',
        dtstart: '20260512T010000Z',
        tzid: 'Asia/Tokyo',
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      const body: string = putCall[1].body;
      expect(body).toContain('BEGIN:VTIMEZONE');
      expect(body).toContain('TZID:Asia/Tokyo');
      expect(body).toContain('TZOFFSETTO:+0900');
      expect(body).not.toContain('BEGIN:DAYLIGHT');
      // 01:00 UTC -> 10:00 JST same day; default dtend = +1h -> 11:00 JST
      expect(body).toContain('DTSTART;TZID=Asia/Tokyo:20260512T100000');
      expect(body).toContain('DTEND;TZID=Asia/Tokyo:20260512T110000');
    });

    it('should accept floating local dtstart when tzid is set', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Local lunch',
        calendarName: 'personal',
        dtstart: '20260512T150000',
        tzid: 'Europe/Berlin',
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      const body: string = putCall[1].body;
      expect(body).toContain('DTSTART;TZID=Europe/Berlin:20260512T150000');
      expect(body).toContain('DTEND;TZID=Europe/Berlin:20260512T160000');
    });

    it('should ignore tzid for all-day events', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOk)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(verifyResponse),
        });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      await createEventTool.handler({
        summary: 'Holiday',
        calendarName: 'personal',
        dtstart: '20260401',
        tzid: 'Europe/Berlin',
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      const body: string = putCall[1].body;
      expect(body).toContain('DTSTART;VALUE=DATE:20260401');
      expect(body).not.toContain('BEGIN:VTIMEZONE');
      expect(body).not.toContain('TZID=');
    });

    it('should handle create failure', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: false,
        status: 403,
        text: () => Promise.resolve('Forbidden'),
      });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Denied event',
        calendarName: 'personal',
        dtstart: '20240315T100000Z',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('403');
    });
  });

  describe('update_event', () => {
    const resolveResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-upd1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-1
SUMMARY:Old title
DTSTART:20240315T090000Z
DTEND:20240315T100000Z
LOCATION:Room A
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should update event fields', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        summary: 'New title',
        location: 'Room B',
      });

      expect(result.content[0].text).toContain('updated successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].method).toBe('PUT');
      expect(putCall[1].headers['If-Match']).toBe('"etag-upd1"');
      expect(putCall[1].body).toContain('SUMMARY:New title');
      expect(putCall[1].body).toContain('LOCATION:Room B');
    });

    it('should remove fields when set to null', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        location: null,
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].body).not.toMatch(/^LOCATION:/m);
    });

    it('should handle ETag conflict (412)', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({
          ok: false,
          status: 412,
          text: () => Promise.resolve('Precondition Failed'),
        });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        summary: 'New title',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
    });

    it('should extract ETag correctly when server uses non-d: namespace prefix', async () => {
      const resolveResponseUpperNs = `<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:response>
    <D:href>/remote.php/dav/calendars/admin/personal/event-1.ics</D:href>
    <D:propstat><D:prop>
      <D:getetag>"etag-upper-ns"</D:getetag>
      <C:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-1
SUMMARY:Old title
DTSTART:20240315T090000Z
DTEND:20240315T100000Z
END:VEVENT
END:VCALENDAR</C:calendar-data>
    </D:prop></D:propstat>
  </D:response>
</D:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponseUpperNs) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        summary: 'Updated title',
      });

      expect(result.content[0].text).toContain('updated successfully');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(putCall[1].headers['If-Match']).toBe('"etag-upper-ns"');
    });

    it('should handle event not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'nonexistent',
        calendarName: 'personal',
        summary: 'test',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });

    it('should add alarm to event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        alarm: 15,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).toContain('BEGIN:VALARM');
      expect(putBody).toContain('TRIGGER:-PT15M');
      expect(putBody).toContain('ACTION:DISPLAY');
      expect(putBody).toContain('END:VALARM');
    });

    it('should set multiple alarms via alarms[]', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        alarms: [1440, 60],
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody.match(/BEGIN:VALARM/g)).toHaveLength(2);
      expect(putBody).toContain('TRIGGER:-PT24H');
      expect(putBody).toContain('TRIGGER:-PT1H');
    });

    it('should remove alarm when set to null', async () => {
      const responseWithAlarm = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-upd1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-1
SUMMARY:Old title
DTSTART:20240315T090000Z
DTEND:20240315T100000Z
BEGIN:VALARM
ACTION:DISPLAY
DESCRIPTION:Reminder
TRIGGER:-PT15M
END:VALARM
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(responseWithAlarm) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        alarm: null,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).not.toContain('BEGIN:VALARM');
      expect(putBody).not.toContain('END:VALARM');
    });

    it('should add attendees to event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        attendees: [
          { email: 'alice@example.com', cn: 'Alice' },
          { email: 'bob@example.com', role: 'OPT-PARTICIPANT', rsvp: false },
        ],
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).toContain('ORGANIZER;CN=admin:mailto:admin');
      expect(putBody).toContain(
        'ATTENDEE;CN=Alice;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:alice@example.com'
      );
      expect(putBody).toContain(
        'ATTENDEE;ROLE=OPT-PARTICIPANT;PARTSTAT=NEEDS-ACTION:mailto:bob@example.com'
      );
    });

    it('should remove attendees when set to null', async () => {
      const responseWithAttendees = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-upd1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-1
SUMMARY:Old title
DTSTART:20240315T090000Z
DTEND:20240315T100000Z
ORGANIZER;CN=admin:mailto:admin
ATTENDEE;CN=Alice;ROLE=REQ-PARTICIPANT:mailto:alice@example.com
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(responseWithAttendees) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'event-1',
        calendarName: 'personal',
        attendees: null,
      });

      expect(result.content[0].text).toContain('updated successfully');
      const putBody = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1][1].body;
      expect(putBody).not.toMatch(/^ORGANIZER/m);
      expect(putBody).not.toMatch(/^ATTENDEE/m);
    });
  });

  describe('delete_event', () => {
    const resolveResponse = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-del1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1
SUMMARY:Event to delete
DTSTART:20240315T090000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('should delete an event', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { deleteEventTool } = await import('../tools/apps/calendar.js');
      const result = await deleteEventTool.handler({ uid: 'event-1', calendarName: 'personal' });

      expect(result.content[0].text).toContain('deleted successfully');

      const deleteCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(deleteCall[1].method).toBe('DELETE');
      expect(deleteCall[1].headers['If-Match']).toBe('"etag-del1"');
    });

    it('should handle event not found', async () => {
      const emptyResponse = `<d:multistatus xmlns:d="DAV:"></d:multistatus>`;
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
        ok: true,
        text: () => Promise.resolve(emptyResponse),
      });

      const { deleteEventTool } = await import('../tools/apps/calendar.js');
      const result = await deleteEventTool.handler({
        uid: 'nonexistent',
        calendarName: 'personal',
      });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('not found');
    });

    it('should handle ETag conflict', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: true, text: () => Promise.resolve(resolveResponse) })
        .mockResolvedValueOnce({
          ok: false,
          status: 412,
          text: () => Promise.resolve('Precondition Failed'),
        });

      const { deleteEventTool } = await import('../tools/apps/calendar.js');
      const result = await deleteEventTool.handler({ uid: 'event-1', calendarName: 'personal' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('ETag mismatch');
    });
  });

  describe('calendar display-name resolution (issue #339)', () => {
    // Calendar list PROPFIND response mapping displayName "Persönlich" → slug "personal"
    const calendarListXml = `<?xml version="1.0" encoding="UTF-8"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/</d:href>
    <d:propstat><d:prop>
      <d:resourcetype><d:collection/><cal:calendar xmlns:cal="urn:ietf:params:xml:ns:caldav"/></d:resourcetype>
      <d:displayname>Persönlich</d:displayname>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    const eventReportXml = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event-1.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"etag-ev1"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:event-1
SUMMARY:Meeting
DTSTART:20240315T090000Z
DTEND:20240315T093000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

    it('list_events: first attempt uses input, retry uses resolved slug', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: false, status: 404, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(calendarListXml),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(eventReportXml),
        });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      await listEventsTool.handler({ calendarName: 'Persönlich' });

      const calls = (global.fetch as ReturnType<typeof vi.fn>).mock.calls;
      // 1st: REPORT against display name (404)
      expect(calls[0][1].method).toBe('REPORT');
      expect(decodeURI(calls[0][0])).toContain('/Persönlich/');
      // 2nd: PROPFIND for calendar list
      expect(calls[1][1].method).toBe('PROPFIND');
      // 3rd: REPORT against resolved slug
      expect(calls[2][1].method).toBe('REPORT');
      expect(calls[2][0]).toContain('/personal/');
    });

    it('list_events: matches display name case-insensitively', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: false, status: 404, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(calendarListXml),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(eventReportXml),
        });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'PERSÖNLICH' });

      expect(result.isError).toBeUndefined();
      const calls = (global.fetch as ReturnType<typeof vi.fn>).mock.calls;
      expect(calls[2][0]).toContain('/personal/');
    });

    it('list_events: when no match found, surfaces original 404', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: false, status: 404, text: () => Promise.resolve('Not found') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(calendarListXml),
        });

      const { listEventsTool } = await import('../tools/apps/calendar.js');
      const result = await listEventsTool.handler({ calendarName: 'Unknown' });

      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('404');
    });

    it('get_event: resolves display name and retries REPORT', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({ ok: false, status: 404, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(calendarListXml),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(eventReportXml),
        });

      const { getEventTool } = await import('../tools/apps/calendar.js');
      const result = await getEventTool.handler({ uid: 'event-1', calendarName: 'Persönlich' });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('Meeting');

      const calls = (global.fetch as ReturnType<typeof vi.fn>).mock.calls;
      expect(calls[2][0]).toContain('/personal/');
    });

    it('create_event: resolves display name when assertCalendarSupportsEvents 404s', async () => {
      const propfindVeventOk = {
        ok: true,
        status: 207,
        text: () =>
          Promise.resolve(
            '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' +
              '<d:response><d:propstat><d:prop>' +
              '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>' +
              '</d:prop></d:propstat></d:response></d:multistatus>'
          ),
      };
      const verifyXml = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>/remote.php/dav/calendars/admin/personal/event.ics</d:href>
    <d:propstat><d:prop>
      <d:getetag>"x"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
BEGIN:VEVENT
UID:any
SUMMARY:Test
DTSTART:20240315T120000Z
END:VEVENT
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;

      (global.fetch as ReturnType<typeof vi.fn>)
        // 1st PROPFIND: assertCalendarSupportsEvents on display name → 404
        .mockResolvedValueOnce({ ok: false, status: 404, text: () => Promise.resolve('') })
        // 2nd PROPFIND: calendar list for slug resolution
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(calendarListXml),
        })
        // 3rd PROPFIND: assertCalendarSupportsEvents on resolved slug → ok
        .mockResolvedValueOnce(propfindVeventOk)
        // 4th: PUT
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        // 5th: verification REPORT
        .mockResolvedValueOnce({ ok: true, status: 207, text: () => Promise.resolve(verifyXml) });

      const { createEventTool } = await import('../tools/apps/calendar.js');
      const result = await createEventTool.handler({
        summary: 'Test',
        calendarName: 'Persönlich',
        dtstart: '20240315T120000Z',
      });

      expect(result.isError).toBeUndefined();
      expect(result.content[0].text).toContain('created successfully');

      const calls = (global.fetch as ReturnType<typeof vi.fn>).mock.calls;
      // PUT URL must use resolved slug, not display name
      const putCall = calls.find((c) => c[1].method === 'PUT');
      expect(putCall![0]).toContain('/personal/');
      expect(putCall![0]).not.toContain('Persönlich');
    });
  });

  // ---------------------------------------------------------------------------
  // Shared fixtures for the 0.5.0 tool suite
  // ---------------------------------------------------------------------------

  const homePropfindXml = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
    <d:response>
      <d:propstat><d:prop>
        <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
        <d:displayname>Personal</d:displayname>
        <c:supported-calendar-component-set><c:comp name="VEVENT"/><c:comp name="VTODO"/></c:supported-calendar-component-set>
      </d:prop></d:propstat>
      <d:href>/remote.php/dav/calendars/admin/personal/</d:href>
    </d:response>
    <d:response>
      <d:propstat><d:prop>
        <d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>
        <d:displayname>Work</d:displayname>
        <c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>
      </d:prop></d:propstat>
      <d:href>/remote.php/dav/calendars/admin/work/</d:href>
    </d:response>
  </d:multistatus>`;

  const propfindVeventOkHome = {
    ok: true,
    status: 207,
    text: () => Promise.resolve(homePropfindXml),
  };

  const propfindVeventOkDepth0 = {
    ok: true,
    status: 207,
    text: () =>
      Promise.resolve(
        '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' +
          '<d:response><d:propstat><d:prop>' +
          '<c:supported-calendar-component-set><c:comp name="VEVENT"/></c:supported-calendar-component-set>' +
          '</d:prop></d:propstat></d:response></d:multistatus>'
      ),
  };

  const emptyMultistatus = `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"></d:multistatus>`;

  function icsEvent(opts: {
    uid: string;
    summary: string;
    /** Wall-clock value, YYYYMMDDTHHmmss */
    date: string;
    tzid?: string;
    dateEnd?: string;
    rrule?: string;
    location?: string;
    status?: string;
    transp?: string;
  }): string {
    const start = opts.tzid ? `DTSTART;TZID=${opts.tzid}:${opts.date}` : `DTSTART:${opts.date}Z`;
    const end = opts.dateEnd
      ? opts.tzid
        ? `DTEND;TZID=${opts.tzid}:${opts.dateEnd}`
        : `DTEND:${opts.dateEnd}Z`
      : '';
    return [
      'BEGIN:VEVENT',
      `UID:${opts.uid}`,
      `SUMMARY:${opts.summary}`,
      start,
      end,
      opts.rrule ? `RRULE:${opts.rrule}` : '',
      opts.location ? `LOCATION:${opts.location}` : '',
      opts.status ? `STATUS:${opts.status}` : '',
      opts.transp ? `TRANSP:${opts.transp}` : '',
      'END:VEVENT',
    ]
      .filter(Boolean)
      .join('\n');
  }

  function reportXml(
    events: string[],
    etag = 'etag-x',
    href = '/remote.php/dav/calendars/admin/personal/e.ics'
  ): string {
    return `<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
  <d:response>
    <d:href>${href}</d:href>
    <d:propstat><d:prop>
      <d:getetag>"${etag}"</d:getetag>
      <c:calendar-data>BEGIN:VCALENDAR
VERSION:2.0
${events.join('\n')}
END:VCALENDAR</c:calendar-data>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>`;
  }

  function utcDayISO(offsetDays: number): string {
    return new Date(Date.now() + offsetDays * 86_400_000).toISOString().slice(0, 10);
  }

  function toICalDate(iso: string): string {
    return iso.replace(/-/g, '');
  }

  // ---------------------------------------------------------------------------
  // get_upcoming_events
  // ---------------------------------------------------------------------------

  describe('get_upcoming_events', () => {
    it('aggregates events across all event-capable calendars, sorted by start', async () => {
      const day1 = toICalDate(utcDayISO(1));
      const day2 = toICalDate(utcDayISO(2));
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml([
                icsEvent({
                  uid: 'up-1',
                  summary: 'Standup',
                  date: `${day1}T100000`,
                  tzid: 'Europe/Berlin',
                }),
                icsEvent({
                  uid: 'up-2',
                  summary: 'Late one',
                  date: `${day2}T100000`,
                  tzid: 'Europe/Berlin',
                }),
              ])
            ),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml([
                icsEvent({
                  uid: 'up-3',
                  summary: 'Work early',
                  date: `${day1}T090000`,
                  tzid: 'Europe/Berlin',
                }),
              ])
            ),
        });

      const { getUpcomingEventsTool } = await import('../tools/apps/calendar.js');
      const result = await getUpcomingEventsTool.handler({});

      const text = result.content[0].text;
      expect(text).toContain('Upcoming events (next 7 days, 3 found):');
      // Work's 09:00 event must sort before Personal's 10:00 one
      expect(text.indexOf('Work early')).toBeLessThan(text.indexOf('Standup'));
      expect(text.indexOf('Standup')).toBeLessThan(text.indexOf('Late one'));
      expect(text).toContain('Calendar: Personal');
      expect(text).toContain('Calendar: Work');
    });

    it('reports a clean message when nothing is upcoming', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        });

      const { getUpcomingEventsTool } = await import('../tools/apps/calendar.js');
      const result = await getUpcomingEventsTool.handler({});
      expect(result.content[0].text).toContain('No upcoming events in the next 7 days.');
    });
  });

  // ---------------------------------------------------------------------------
  // create_meeting
  // ---------------------------------------------------------------------------

  describe('create_meeting', () => {
    it('creates a timezone-bound meeting with attendees and computed end time', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkDepth0)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml(['BEGIN:VEVENT\nUID:x\nSUMMARY:Sprint Planning\nEND:VEVENT'])
            ),
        });

      const { createMeetingTool } = await import('../tools/apps/calendar.js');
      const result = await createMeetingTool.handler({
        title: 'Sprint Planning',
        date: '2026-11-10',
        time: '14:00',
        durationMinutes: 90,
        calendarName: 'personal',
        attendees: 'alice@example.com, bob@example.com',
        location: 'Room 4',
        timezone: 'Europe/Berlin',
      });

      const text = result.content[0].text;
      expect(text).toContain('Meeting created: Sprint Planning');
      expect(text).toContain('2026-11-10 14:00 (Europe/Berlin) (90 min)');
      expect(text).toContain('Calendar: personal');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
        (c) => c[1].method === 'PUT'
      );
      const body = putCall![1].body as string;
      expect(body).toContain('DTSTART;TZID=Europe/Berlin:20261110T140000');
      expect(body).toContain('DTEND;TZID=Europe/Berlin:20261110T153000');
      expect(body).toContain('SUMMARY:Sprint Planning');
      expect(body).toContain('LOCATION:Room 4');
      expect(body).toContain('STATUS:CONFIRMED');
      expect(body).toContain('ORGANIZER;CN=admin:mailto:admin');
      expect(body).toContain(
        'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:alice@example.com'
      );
      expect(body).toContain(
        'ATTENDEE;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:bob@example.com'
      );
      expect(body).toContain('TRIGGER:-PT15M');
    });

    it('defaults to UTC, one hour and a 15-minute reminder when omitted', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkDepth0)
        .mockResolvedValueOnce({ ok: true, status: 201, text: () => Promise.resolve('') })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(reportXml(['BEGIN:VEVENT\nUID:x\nSUMMARY:Quick sync\nEND:VEVENT'])),
        });

      const { createMeetingTool } = await import('../tools/apps/calendar.js');
      const result = await createMeetingTool.handler({
        title: 'Quick sync',
        date: '2026-11-10',
        time: '14:00',
      });

      expect(result.content[0].text).toContain('2026-11-10 14:00 UTC (60 min)');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
        (c) => c[1].method === 'PUT'
      );
      const body = putCall![1].body as string;
      expect(body).toContain('DTSTART:20261110T140000Z');
      expect(body).toContain('DTEND:20261110T150000Z');
      expect(body).not.toContain('ATTENDEE');
      expect(body).toContain('TRIGGER:-PT15M');
    });

    it('rejects an unknown timezone', async () => {
      const { createMeetingTool } = await import('../tools/apps/calendar.js');
      const result = await createMeetingTool.handler({
        title: 'X',
        date: '2026-11-10',
        time: '14:00',
        timezone: 'Mars/Olympus',
      });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('Unknown time zone');
    });
  });

  // ---------------------------------------------------------------------------
  // find_availability
  // ---------------------------------------------------------------------------

  describe('find_availability', () => {
    function nextMondayISO(minOffsetDays = 2): string {
      const d = new Date(Date.now() + minOffsetDays * 86_400_000);
      const diffToMonday = (8 - d.getUTCDay()) % 7 || 7;
      d.setUTCDate(d.getUTCDate() + diffToMonday);
      return d.toISOString().slice(0, 10);
    }
    function plusDays(iso: string, days: number): string {
      const d = new Date(`${iso}T00:00:00Z`);
      d.setUTCDate(d.getUTCDate() + days);
      return d.toISOString().slice(0, 10);
    }

    it('returns business-hours slots with a busy block subtracted', async () => {
      const mon = nextMondayISO();
      const tue = plusDays(mon, 1);
      const wed = plusDays(mon, 2);
      const thu = plusDays(mon, 3);
      const fri = plusDays(mon, 4);

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml([
                icsEvent({
                  uid: 'busy-1',
                  summary: 'Focus block',
                  date: `${toICalDate(tue)}T100000`,
                  tzid: 'Europe/Berlin',
                  dateEnd: `${toICalDate(tue)}T120000`,
                }),
              ])
            ),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        });

      const { findAvailabilityTool } = await import('../tools/apps/calendar.js');
      const result = await findAvailabilityTool.handler({
        durationMinutes: 60,
        dateRangeStart: mon,
        dateRangeEnd: fri,
        timezone: 'Europe/Berlin',
      });

      const text = result.content[0].text;
      expect(text).toContain('Available slots for 60 min in Europe/Berlin:');
      expect(text).toContain(`1. ${mon} 09:00 – ${mon} 17:00 (480 min free)`);
      expect(text).toContain(`2. ${tue} 09:00 – ${tue} 10:00 (60 min free)`);
      expect(text).toContain(`3. ${tue} 12:00 – ${tue} 17:00 (300 min free)`);
      expect(text).toContain(`4. ${wed} 09:00 – ${wed} 17:00 (480 min free)`);
      expect(text).toContain(`5. ${thu} 09:00 – ${thu} 17:00 (480 min free)`);
      expect(text).toContain(`6. ${fri} 09:00 – ${fri} 17:00 (480 min free)`);
      expect(text).toContain('across 2 calendar(s)');
    });

    it('drops slots shorter than the required duration', async () => {
      const mon = nextMondayISO();
      const fri = plusDays(mon, 4);
      const tue = plusDays(mon, 1);

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml([
                icsEvent({
                  uid: 'busy-1',
                  summary: 'Focus block',
                  date: `${toICalDate(tue)}T100000`,
                  tzid: 'Europe/Berlin',
                  dateEnd: `${toICalDate(tue)}T120000`,
                }),
              ])
            ),
        })
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        });

      const { findAvailabilityTool } = await import('../tools/apps/calendar.js');
      const result = await findAvailabilityTool.handler({
        durationMinutes: 300,
        dateRangeStart: mon,
        dateRangeEnd: fri,
        timezone: 'Europe/Berlin',
      });

      const text = result.content[0].text;
      // Tuesday 09:00-10:00 (60 min) must be gone; the 12:00-17:00 part remains.
      expect(text).not.toContain(`${tue} 09:00 – ${tue} 10:00`);
      expect(text).toContain(`${tue} 12:00 – ${tue} 17:00 (300 min free)`);
    });

    it('ignores all-day events unless includeAllDay is set', async () => {
      const mon = nextMondayISO();
      const fri = plusDays(mon, 4);

      const allDayEvent = `BEGIN:VEVENT
UID:allday-1
SUMMARY:Company day off
DTSTART;VALUE=DATE:${toICalDate(mon)}
DTEND;VALUE=DATE:${plusDays(mon, 1)}
END:VEVENT`;

      const withBusy = (events: string[]) => ({
        ok: true,
        status: 207,
        text: () => Promise.resolve(reportXml(events)),
      });

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce(withBusy([allDayEvent]))
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        });

      const { findAvailabilityTool } = await import('../tools/apps/calendar.js');
      const free = await findAvailabilityTool.handler({
        durationMinutes: 60,
        dateRangeStart: mon,
        dateRangeEnd: fri,
        timezone: 'Europe/Berlin',
      });
      expect(free.content[0].text).toContain(`1. ${mon} 09:00 – ${mon} 17:00 (480 min free)`);

      (global.fetch as ReturnType<typeof vi.fn>)
        .mockReset()
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce(withBusy([allDayEvent]))
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        });

      const blocked = await findAvailabilityTool.handler({
        durationMinutes: 60,
        dateRangeStart: mon,
        dateRangeEnd: fri,
        timezone: 'Europe/Berlin',
        includeAllDay: true,
      });
      expect(blocked.content[0].text).not.toContain(`${mon} 09:00 – ${mon} 17:00`);
    });
  });

  // ---------------------------------------------------------------------------
  // manage_calendar
  // ---------------------------------------------------------------------------

  describe('manage_calendar', () => {
    it('creates a calendar with slug, color and component set', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
        ok: true,
        status: 201,
        text: () => Promise.resolve(''),
      });

      const { manageCalendarTool } = await import('../tools/apps/calendar.js');
      const result = await manageCalendarTool.handler({
        action: 'create',
        calendarName: 'Project Alpha',
        displayName: 'Project Alpha',
        color: '#FF0000',
        description: 'Sprint tracking',
      });

      const text = result.content[0].text;
      expect(text).toContain('Calendar created: Project Alpha (slug: project-alpha)');
      expect(text).toContain('/remote.php/dav/calendars/admin/project-alpha/');

      const call = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[0];
      expect(call[1].method).toBe('MKCALENDAR');
      const body = call[1].body as string;
      expect(body).toContain('<cs:calendar-color>#FF0000</cs:calendar-color>');
      expect(body).toContain('<caldav:comp name="VEVENT"/>');
      expect(body).toContain('<caldav:comp name="VTODO"/>');
      expect(body).toContain('Sprint tracking');
    });

    it('resolves a display name and updates via PROPPATCH', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce({ ok: true, status: 207, text: () => Promise.resolve('') });

      const { manageCalendarTool } = await import('../tools/apps/calendar.js');
      const result = await manageCalendarTool.handler({
        action: 'update',
        calendarName: 'Personal',
        displayName: 'Personal 2',
        color: '#00FF00',
      });

      expect(result.content[0].text).toContain('Calendar updated: personal');

      const call = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(call[0]).toContain('/personal/');
      expect(call[1].method).toBe('PROPPATCH');
      const body = call[1].body as string;
      expect(body).toContain('<d:displayname>Personal 2</d:displayname>');
      expect(body).toContain('<cs:calendar-color>#00FF00</cs:calendar-color>');
    });

    it('deletes a calendar', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce(propfindVeventOkHome)
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { manageCalendarTool } = await import('../tools/apps/calendar.js');
      const result = await manageCalendarTool.handler({
        action: 'delete',
        calendarName: 'work',
      });

      expect(result.content[0].text).toContain('Calendar deleted: work');
      const call = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      expect(call[1].method).toBe('DELETE');
      expect(call[0]).toContain('/work/');
    });

    it('lists calendars', async () => {
      (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce(propfindVeventOkHome);

      const { manageCalendarTool } = await import('../tools/apps/calendar.js');
      const result = await manageCalendarTool.handler({ action: 'list' });
      const text = result.content[0].text;
      expect(text).toContain('Calendars (2 found):');
      expect(text).toContain('Personal');
      expect(text).toContain('Work');
    });
  });

  // ---------------------------------------------------------------------------
  // bulk_operations
  // ---------------------------------------------------------------------------

  describe('bulk_operations', () => {
    const decEvent = (uid: string, summary: string, rrule?: string): string =>
      icsEvent({
        uid,
        summary,
        date: '20261201T090000',
        dateEnd: '20261201T100000',
        rrule,
      });

    it('updates matching events and skips recurring series by default', async () => {
      const fullEvent = `BEGIN:VEVENT
UID:ev-1
SUMMARY:Team sync
DTSTART:20261201T090000Z
DTEND:20261201T100000Z
LAST-MODIFIED:20261001T000000Z
END:VEVENT`;

      (global.fetch as ReturnType<typeof vi.fn>)
        // 1: list calendars
        .mockResolvedValueOnce(propfindVeventOkHome)
        // 2: window REPORT on personal (2 matching events, one recurring)
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml([
                decEvent('ev-1', 'Team sync'),
                decEvent('ev-2', 'Weekly review', 'FREQ=WEEKLY;BYDAY=MO'),
              ])
            ),
        })
        // 3: window REPORT on work (none)
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        })
        // 4: resolve ev-1 by UID
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml([fullEvent], 'etag-1', '/remote.php/dav/calendars/admin/personal/ev-1.ics')
            ),
        })
        // 5: PUT the modification
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { bulkOperationsTool } = await import('../tools/apps/calendar.js');
      const result = await bulkOperationsTool.handler({
        operation: 'update',
        titleContains: 'e',
        newTitle: 'Team sync v2',
      });

      const text = result.content[0].text;
      expect(text).toContain(
        'Bulk update complete: 1 updated, 0 failed, 1 skipped (of 2 matched).'
      );
      expect(text).toContain('[updated] Team sync (UID: ev-1)');
      expect(text).toContain('[skipped] Weekly review (UID: ev-2) (recurring)');

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
        (c) => c[1].method === 'PUT'
      );
      expect(putCall![0]).toContain('/ev-1.ics');
      expect(putCall![1].headers['If-Match']).toBe('"etag-1"');
      expect(putCall![1].body).toContain('SUMMARY:Team sync v2');
    });

    it('deletes matching events', async () => {
      (global.fetch as ReturnType<typeof vi.fn>)
        // 1: list calendars
        .mockResolvedValueOnce(propfindVeventOkHome)
        // 2: window REPORT on personal
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(reportXml([decEvent('ev-3', 'One-off cleanup')])),
        })
        // 3: window REPORT on work
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () => Promise.resolve(emptyMultistatus),
        })
        // 4: resolve ev-3 by UID
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml(
                [decEvent('ev-3', 'One-off cleanup')],
                'etag-3',
                '/remote.php/dav/calendars/admin/personal/ev-3.ics'
              )
            ),
        })
        // 5: DELETE
        .mockResolvedValueOnce({ ok: true, status: 204, text: () => Promise.resolve('') });

      const { bulkOperationsTool } = await import('../tools/apps/calendar.js');
      const result = await bulkOperationsTool.handler({
        operation: 'delete',
        titleContains: 'One-off',
      });

      const text = result.content[0].text;
      expect(text).toContain(
        'Bulk delete complete: 1 deleted, 0 failed, 0 skipped (of 1 matched).'
      );
      expect(text).toContain('[deleted] One-off cleanup (UID: ev-3)');

      const delCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls.find(
        (c) => c[1].method === 'DELETE'
      );
      expect(delCall![0]).toContain('/ev-3.ics');
      expect(delCall![1].headers['If-Match']).toBe('"etag-3"');
    });

    it('rejects update without any update data', async () => {
      const { bulkOperationsTool } = await import('../tools/apps/calendar.js');
      const result = await bulkOperationsTool.handler({ operation: 'update' });
      expect(result.isError).toBe(true);
      expect(result.content[0].text).toContain('No update data provided');
    });
  });

  // ---------------------------------------------------------------------------
  // update_event — recurrence / attendee / alarm parity regression
  // ---------------------------------------------------------------------------

  describe('update_event parity (recurrence, attendees, alarms)', () => {
    const recurringIcal = `BEGIN:VCALENDAR
BEGIN:VEVENT
UID:parity-1
SUMMARY:Weekly board
DTSTART:20261102T090000Z
DTEND:20261102T100000Z
RRULE:FREQ=WEEKLY;BYDAY=MO
EXDATE:20261206T090000Z
ORGANIZER;CN=admin:mailto:admin
ATTENDEE;CN=Alice;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:alice@example.com
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT10M
END:VALARM
END:VEVENT
END:VCALENDAR`;

    function mockResolveAndPut(putStatus = 204) {
      (global.fetch as ReturnType<typeof vi.fn>)
        .mockResolvedValueOnce({
          ok: true,
          status: 207,
          text: () =>
            Promise.resolve(
              reportXml(
                [recurringIcal],
                'etag-p',
                '/remote.php/dav/calendars/admin/personal/parity-1.ics'
              )
            ),
        })
        .mockResolvedValueOnce({ ok: true, status: putStatus, text: () => Promise.resolve('') });
    }

    it('preserves RRULE, EXDATE, attendees and alarms when only location changes', async () => {
      mockResolveAndPut();
      const { updateEventTool } = await import('../tools/apps/calendar.js');
      const result = await updateEventTool.handler({
        uid: 'parity-1',
        calendarName: 'personal',
        location: 'New room',
      });

      expect(result.isError).toBeUndefined();
      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      const body = putCall[1].body as string;
      expect(body).toContain('RRULE:FREQ=WEEKLY;BYDAY=MO');
      expect(body).toContain('EXDATE:20261206T090000Z');
      expect(body).toContain(
        'ATTENDEE;CN=Alice;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:alice@example.com'
      );
      expect(body).toContain('TRIGGER:-PT10M');
      expect(body).toContain('LOCATION:New room');
    });

    it('replaces alarms and attendees when given', async () => {
      mockResolveAndPut();
      const { updateEventTool } = await import('../tools/apps/calendar.js');
      await updateEventTool.handler({
        uid: 'parity-1',
        calendarName: 'personal',
        alarm: 30,
        attendees: [{ email: 'carol@example.com', cn: 'Carol' }],
      });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      const body = putCall[1].body as string;
      expect(body).not.toContain('TRIGGER:-PT10M');
      expect(body).toContain('TRIGGER:-PT30M');
      expect(body).not.toContain('alice@example.com');
      expect(body).toContain(
        'ATTENDEE;CN=Carol;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:carol@example.com'
      );
    });

    it('removes the series when rrule is set to null', async () => {
      mockResolveAndPut();
      const { updateEventTool } = await import('../tools/apps/calendar.js');
      await updateEventTool.handler({ uid: 'parity-1', calendarName: 'personal', rrule: null });

      const putCall = (global.fetch as ReturnType<typeof vi.fn>).mock.calls[1];
      const body = putCall[1].body as string;
      expect(body).not.toContain('RRULE:');
    });
  });
});
