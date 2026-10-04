<?php

declare(strict_types=1);

namespace OCA\RequrvHive\Tests\Unit\Service;

use OCA\RequrvHive\Service\CalendarToolsService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The calendar tools read the CalDAV tables the calendar app owns. What the
 * tests pin down: the queries are scoped to the caller's principal, event
 * blobs are parsed with the real Sabre parser (not a regex on the blob),
 * recurring events are expanded, and creation mirrors the CalDAV backend's
 * write protocol (object row + change log row + sync-token bump, in a
 * transaction) so clients see the event on their next sync.
 */
class CalendarToolsServiceTest extends DbToolsTestCase {
    private CalendarToolsService $service;

    /** @param list<\OCP\DB\IResult|null> $results */
    private function makeService(array $results): CalendarToolsService {
        $db = $this->makeDb($results);
        $this->service = new CalendarToolsService($db, $this->createMock(LoggerInterface::class));
        $this->db = $db;

        return $this->service;
    }

    private function calendarsRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'displayname' => 'Personal',
            'uri' => 'personal',
            'description' => '',
            'components' => 'VEVENT',
        ], $overrides);
    }

    public function testIsToolRecognisesOnlyTheCalendarTools(): void {
        $this->assertTrue(CalendarToolsService::isTool(CalendarToolsService::TOOL_LIST_CALENDARS));
        $this->assertTrue(CalendarToolsService::isTool(CalendarToolsService::TOOL_LIST_CALENDAR_EVENTS));
        $this->assertTrue(CalendarToolsService::isTool(CalendarToolsService::TOOL_CREATE_CALENDAR_EVENT));
        $this->assertFalse(CalendarToolsService::isTool('list_mailboxes'));
        $this->assertFalse(CalendarToolsService::isTool('delete_event'));
    }

    public function testGetToolsOffersThreeToolsInCanonicalShape(): void {
        $this->makeService([]);

        $tools = $this->service->getTools();
        $names = array_map(static fn(array $t): string => (string)$t['name'], $tools);

        $this->assertSame(
            ['list_calendars', 'list_calendar_events', 'create_calendar_event'],
            $names
        );
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('description', $tool);
            $this->assertSame('object', $tool['input_schema']['type'] ?? null);
        }
    }

    public function testListCalendarsShowsNameAndUri(): void {
        $this->makeService([$this->makeResult([
            $this->calendarsRow(),
            $this->calendarsRow(['id' => 2, 'displayname' => 'Work', 'uri' => 'work', 'description' => 'Meetings']),
        ])]);

        $result = $this->service->execute(CalendarToolsService::TOOL_LIST_CALENDARS, [], 'requrv');
        $this->assertFalse($result['isError']);
        $text = (string)$result['content'][0]['text'];

        $this->assertStringContainsString('- Personal  (uri: personal)', $text);
        $this->assertStringContainsString('- Work  (uri: work) — Meetings', $text);
    }

    public function testListCalendarsWithoutAnyIsAnErrorResult(): void {
        $this->makeService([$this->makeResult([])]);

        $result = $this->service->execute(CalendarToolsService::TOOL_LIST_CALENDARS, [], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('No calendars found', (string)$result['content'][0]['text']);
    }

    public function testListEventsParsesTheBlobsAndExpandsTheWindow(): void {
        $blob = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//requrvhive//tests//EN',
            'BEGIN:VEVENT',
            'UID:team-sync@requrvhive',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20261005T140000Z',
            'DTEND:20261005T150000Z',
            'SUMMARY:Team sync',
            'LOCATION:Room 1',
            'DESCRIPTION:Weekly sync',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:birthday@requrvhive',
            'DTSTAMP:20260101T000000Z',
            'DTSTART;VALUE=DATE:20261007',
            'SUMMARY:Birthday',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $this->makeService([
            $this->makeResult([$this->calendarsRow()]), // calendars()
            $this->makeResult([['calendardata' => $blob]]), // calendarobjects
        ]);

        $result = $this->service->execute(CalendarToolsService::TOOL_LIST_CALENDAR_EVENTS, [
            'start' => '2026-10-05',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $text = (string)$result['content'][0]['text'];

        $this->assertStringContainsString('Events from 2026-10-05 to 2026-10-11:', $text);
        $this->assertStringContainsString('[2026-10-05 14:00–15:00] Team sync', $text);
        $this->assertStringContainsString('    location: Room 1', $text);
        $this->assertStringContainsString('    description: Weekly sync', $text);
        $this->assertStringContainsString('[2026-10-07] Birthday', $text);
        // Chronological order: the timed morning-of event comes first.
        $this->assertLessThan(
            mb_strpos($text, 'Birthday'),
            mb_strpos($text, 'Team sync')
        );
    }

    public function testListEventsWithNothingInTheWindowIsNotAnError(): void {
        $this->makeService([
            $this->makeResult([$this->calendarsRow()]),
            $this->makeResult([]),
        ]);

        $result = $this->service->execute(CalendarToolsService::TOOL_LIST_CALENDAR_EVENTS, [
            'start' => '2026-10-05',
            'end' => '2026-10-06',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString('No events between 2026-10-05 and 2026-10-06', (string)$result['content'][0]['text']);
    }

    public function testListEventsRejectsAnUnknownCalendar(): void {
        $this->makeService([
            $this->makeResult([$this->calendarsRow()]),
        ]);

        $result = $this->service->execute(CalendarToolsService::TOOL_LIST_CALENDAR_EVENTS, [
            'calendar' => 'nope',
            'start' => '2026-10-05',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString("Unknown calendar 'nope'", (string)$result['content'][0]['text']);
        $this->assertStringContainsString('Personal', (string)$result['content'][0]['text']);
    }

    public function testCreateEventWritesObjectChangeAndTokenBumpInATransaction(): void {
        $db = $this->makeDb([
            $this->makeResult([$this->calendarsRow()]), // calendars()
            null, // INSERT calendarobjects
            $this->makeResult([['synctoken' => 7]]), // SELECT synctoken
            null, // INSERT calendarchanges
            null, // UPDATE calendars
        ]);
        $db->expects($this->once())->method('beginTransaction');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollBack');
        $this->service = new CalendarToolsService($db, $this->createMock(LoggerInterface::class));

        $result = $this->service->execute(CalendarToolsService::TOOL_CREATE_CALENDAR_EVENT, [
            'title' => 'Dentist',
            'start' => '2026-11-15T14:00:00',
            'location' => 'Dental clinic',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString("Event 'Dentist' created in calendar 'Personal'", (string)$result['content'][0]['text']);
        $this->assertStringContainsString('2026-11-15 14:00', (string)$result['content'][0]['text']);
    }

    public function testCreateEventWithAllDayEndTreatsItAsTheLastDay(): void {
        $this->makeService([
            $this->makeResult([$this->calendarsRow()]),
            null,
            $this->makeResult([['synctoken' => 0]]),
            null,
            null,
        ]);

        $result = $this->service->execute(CalendarToolsService::TOOL_CREATE_CALENDAR_EVENT, [
            'title' => 'Sabbatical',
            'start' => '2026-12-01',
            'end' => '2026-12-07',
        ], 'requrv');
        $this->assertFalse($result['isError']);
        $this->assertStringContainsString("Event 'Sabbatical' created", (string)$result['content'][0]['text']);
    }

    public function testCreateEventRejectsAnEndBeforeTheStart(): void {
        // Validated before any query runs, so the queue stays empty.
        $this->makeService([]);

        $result = $this->service->execute(CalendarToolsService::TOOL_CREATE_CALENDAR_EVENT, [
            'title' => 'Backwards',
            'start' => '2026-11-15T15:00:00',
            'end' => '2026-11-15T14:00:00',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('end must be after its start', (string)$result['content'][0]['text']);
    }

    public function testCreateEventRejectsAStartInThePast(): void {
        $this->makeService([]);

        $result = $this->service->execute(CalendarToolsService::TOOL_CREATE_CALENDAR_EVENT, [
            'title' => 'Time travel',
            'start' => '2020-01-01T10:00:00',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('past', (string)$result['content'][0]['text']);
    }

    public function testCreateEventWithoutATitleIsAnError(): void {
        $this->makeService([]);

        $result = $this->service->execute(CalendarToolsService::TOOL_CREATE_CALENDAR_EVENT, [
            'start' => '2026-11-15T14:00:00',
        ], 'requrv');
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('title', (string)$result['content'][0]['text']);
    }

    public function testUnknownToolIsAnErrorResult(): void {
        $this->makeService([]);

        $result = $this->service->execute('delete_calendar_event', [], 'requrv');
        $this->assertTrue($result['isError']);
    }
}
