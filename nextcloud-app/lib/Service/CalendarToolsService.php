<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * The built-in chat tools for the user's calendar.
 *
 * The calendar app stores events as iCalendar blobs in `oc_calendarobjects`,
 * one row per event, with the owning calendar in `oc_calendars` (scoped by
 * the user's CalDAV principal). This service reads those rows directly and
 * parses the blobs with the same Sabre VObject library Nextcloud ships, so
 * the chat can answer "what do I have on Friday" without any MCP server.
 *
 * Creating an event mirrors what the CalDAV backend does on a PUT: insert the
 * object row, record the change in `oc_calendarchanges` and bump the
 * calendar's sync token, so CalDAV clients pick the new event up on their
 * next sync instead of waiting for a server-side repair.
 */
class CalendarToolsService {
    public const TOOL_LIST_CALENDARS = 'list_calendars';
    public const TOOL_LIST_CALENDAR_EVENTS = 'list_calendar_events';
    public const TOOL_CREATE_CALENDAR_EVENT = 'create_calendar_event';

    private const DEFAULT_EVENT_LIMIT = 30;
    private const MAX_EVENT_LIMIT = 100;
    private const MAX_EXPANDED_OCCURRENCES = 10;
    private const MAX_CREATION_DAYS_AHEAD = 366 * 5;

    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    public static function isTool(string $name): bool {
        return match ($name) {
            self::TOOL_LIST_CALENDARS,
            self::TOOL_LIST_CALENDAR_EVENTS,
            self::TOOL_CREATE_CALENDAR_EVENT => true,
            default => false,
        };
    }

    /**
     * @return list<array{name: string, description: string, input_schema: array<string, mixed>}>
     */
    public function getTools(): array {
        $calendarProp = [
            'type' => 'string',
            'description' => "Name or URI of the calendar (see list_calendars). Omit to use every calendar.",
        ];
        $dateProp = [
            'type' => 'string',
            'description' => 'Date, YYYY-MM-DD format.',
        ];

        return [
            [
                'name' => self::TOOL_LIST_CALENDARS,
                'description' => "List the user's calendars (personal, birthdays, shared, ...). Use it before listing or creating events to know which calendar to address.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
            [
                'name' => self::TOOL_LIST_CALENDAR_EVENTS,
                'description' => "List the user's calendar events within a date range (defaults to the next 7 days). Recurring events are expanded into their individual occurrences in the range. Use it whenever the user asks what they have on, before or during a given day.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'calendar' => $calendarProp,
                        'start' => $dateProp + ['description' => 'First day of the range (default: today).'],
                        'end' => $dateProp + ['description' => 'Last day of the range, inclusive (default: start + 6 days).'],
                        'limit' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => self::MAX_EVENT_LIMIT,
                            'description' => 'Maximum number of events to return (default 30).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => self::TOOL_CREATE_CALENDAR_EVENT,
                'description' => "Create a new event in the user's calendar. Use it when the user asks to put something in their calendar, schedule a meeting or remind them of an appointment. Times without a timezone are interpreted in the server's local timezone.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => [
                            'type' => 'string',
                            'description' => 'Event title.',
                        ],
                        'start' => [
                            'type' => 'string',
                            'description' => "Start as ISO 8601, e.g. '2026-10-05T14:00:00' or '2026-10-05' for an all-day event starting that day. A timezone offset is kept when given.",
                        ],
                        'end' => [
                            'type' => 'string',
                            'description' => "End as ISO 8601. Defaults to one hour after start (or the next day for all-day events). For an all-day event the given end is the LAST day, not the one after.",
                        ],
                        'calendar' => $calendarProp + ['description' => 'Name or URI of the calendar (see list_calendars). Omit for the user\'s default calendar.'],
                        'location' => [
                            'type' => 'string',
                            'description' => 'Optional location.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Optional description or notes.',
                        ],
                    ],
                    'required' => ['title', 'start'],
                ],
            ],
        ];
    }

    /**
     * Execute one of the calendar tools.
     *
     * Never throws: a failure is returned as an isError result so the model
     * can react to it inside the same turn.
     *
     * @param array<string, mixed> $input
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    public function execute(string $name, array $input, string $userId): array {
        try {
            $result = match ($name) {
                self::TOOL_LIST_CALENDARS => $this->listCalendars($userId),
                self::TOOL_LIST_CALENDAR_EVENTS => $this->listEvents(
                    (string)($input['calendar'] ?? ''),
                    (string)($input['start'] ?? ''),
                    (string)($input['end'] ?? ''),
                    isset($input['limit']) ? (int)$input['limit'] : null,
                    $userId,
                ),
                self::TOOL_CREATE_CALENDAR_EVENT => $this->createEvent(
                    (string)($input['title'] ?? ''),
                    (string)($input['start'] ?? ''),
                    isset($input['end']) ? (string)$input['end'] : null,
                    (string)($input['calendar'] ?? ''),
                    isset($input['location']) ? (string)$input['location'] : null,
                    isset($input['description']) ? (string)$input['description'] : null,
                    $userId,
                ),
                default => throw new \InvalidArgumentException("Unknown tool: $name"),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('RequrvHive calendar tool failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResult('The calendar tool call failed. Check the parameters and try again.');
        }

        return $result;
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function listCalendars(string $userId): array {
        $rows = $this->calendars($userId);
        if ($rows === []) {
            return $this->errorResult('No calendars found for this user. The calendar may need to be opened once in the Calendar app before events can be managed here.');
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = '- ' . $row['displayname'] . '  (uri: ' . $row['uri'] . ')'
                . ($row['description'] !== '' ? " — " . $row['description'] : '');
        }
        $lines[] = '';
        $lines[] = "Use the uri (or the display name) as 'calendar' in list_calendar_events and create_calendar_event.";

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function listEvents(string $calendar, string $start, string $end, ?int $limit, string $userId): array {
        $rows = $this->calendars($userId);
        if ($rows === []) {
            return $this->errorResult('No calendars found for this user. The calendar may need to be opened once in the Calendar app before events can be managed here.');
        }

        [$start, $end] = $this->dateRange($start, $end);
        $limit = $limit === null ? self::DEFAULT_EVENT_LIMIT : min(max($limit, 1), self::MAX_EVENT_LIMIT);
        $startTs = strtotime($start . ' 00:00:00');
        $endTs = strtotime($end . ' 23:59:59');
        if ($startTs === false || $endTs === false) {
            return $this->errorResult("Invalid date range '{$start}' to '{$end}'.");
        }

        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        if ($calendar !== '') {
            $match = null;
            foreach ($rows as $row) {
                if ($row['displayname'] === $calendar || $row['uri'] === $calendar) {
                    $match = $row;
                    break;
                }
            }
            if ($match === null) {
                return $this->errorResult("Unknown calendar '{$calendar}'. Available: " . implode(', ', array_map(static fn(array $r): string => $r['displayname'], $rows)));
            }
            $ids = [(int)$match['id']];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('calendardata')
            ->from('calendarobjects')
            ->where($qb->expr()->in('calendarid', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('componenttype', $qb->createNamedParameter('VEVENT')))
            ->andWhere($qb->expr()->isNull('deleted_at'))
            ->andWhere($qb->expr()->lte('firstoccurence', $qb->createNamedParameter($endTs, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('lastoccurence'),
                $qb->expr()->gte('lastoccurence', $qb->createNamedParameter($startTs, IQueryBuilder::PARAM_INT)),
            ));

        $events = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            $data = (string)$row['calendardata'];
            if (trim($data) === '') {
                continue;
            }
            try {
                $events = [...$events, ...$this->expandOccurrences($data, $startTs, $endTs)];
            } catch (\Throwable $e) {
                // One unreadable event must not hide the rest of the day.
                $this->logger->debug('RequrvHive: skipping unparseable calendar object', ['error' => $e->getMessage()]);
            }
        }

        // Sort chronologically: the model reads the list top to bottom.
        usort($events, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts'] ?: strcmp($a['line'], $b['line']));

        if ($events === []) {
            return $this->textResult("No events between {$start} and {$end} in the user's calendar.");
        }

        $lines = ['Events from ' . $start . ' to ' . $end . ':'];
        $count = 0;
        foreach ($events as $event) {
            if ($count >= $limit) {
                $lines[] = '(showing ' . $limit . ' of ' . count($events) . ' events — narrow the date range or raise the limit)';
                break;
            }
            $lines[] = $event['line'];
            foreach ($event['details'] as $detail) {
                $lines[] = '    ' . $detail;
            }
            $count++;
        }

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * @return array{content: list<array<string, mixed>>, isError: bool}
     */
    private function createEvent(string $title, string $start, ?string $end, string $calendar, ?string $location, ?string $description, string $userId): array {
        $title = trim($title);
        if ($title === '') {
            return $this->errorResult('An event needs a title.');
        }

        $allDay = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $start);
        $startDt = $this->parseDateTime($start, $allDay);
        if ($startDt === null) {
            return $this->errorResult("Invalid start date '{$start}'. Use ISO 8601, e.g. '2026-10-05T14:00:00' or '2026-10-05' for an all-day event.");
        }
        if ($end !== null && $end !== '') {
            $endDt = $this->parseDateTime($end, $allDay);
            if ($endDt === null) {
                return $this->errorResult("Invalid end date '{$end}'. Use ISO 8601 in the same form as the start.");
            }
            if ($allDay) {
                // The end of an all-day event is the day AFTER the last day.
                $endDt = $endDt->add(new \DateInterval('P1D'));
            }
            if ($endDt <= $startDt) {
                return $this->errorResult('The event end must be after its start.');
            }
        } else {
            $endDt = $allDay
                ? $startDt->add(new \DateInterval('P1D'))
                : $startDt->add(new \DateInterval('PT1H'));
        }

        if ($startDt->getTimestamp() < (time() - 24 * 3600)) {
            return $this->errorResult('Refusing to create an event in the past. Pick a start from today onwards.');
        }
        if ($startDt->getTimestamp() > (time() + self::MAX_CREATION_DAYS_AHEAD * 24 * 3600)) {
            return $this->errorResult('The start date is too far in the future (more than five years out).');
        }

        $rows = $this->calendars($userId);
        if ($rows === []) {
            return $this->errorResult('No calendars found for this user. The calendar may need to be opened once in the Calendar app before events can be managed here.');
        }

        $target = null;
        if ($calendar !== '') {
            foreach ($rows as $row) {
                if ($row['displayname'] === $calendar || $row['uri'] === $calendar) {
                    $target = $row;
                    break;
                }
            }
            if ($target === null) {
                return $this->errorResult("Unknown calendar '{$calendar}'. Available: " . implode(', ', array_map(static fn(array $r): string => $r['displayname'], $rows)));
            }
        } else {
            $target = $rows[0];
        }
        if (stripos((string)$target['components'], 'VEVENT') === false) {
            return $this->errorResult("The calendar '{$target['displayname']}' does not accept events.");
        }

        $uid = bin2hex(random_bytes(16)) . '@requrvhive';
        // serialize() renders the iCalendar text (CRLF line endings), which is
        // exactly what oc_calendarobjects.calendardata stores.
        $ical = $this->buildICalendar($uid, $title, $startDt, $endDt, $allDay, $location, $description)->serialize();
        $uri = $uid . '.ics';

        $this->db->beginTransaction();
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->insert('calendarobjects')
                ->values([
                    'calendarid' => $qb->createNamedParameter((int)$target['id'], IQueryBuilder::PARAM_INT),
                    'uri' => $qb->createNamedParameter($uri),
                    'calendardata' => $qb->createNamedParameter($ical, IQueryBuilder::PARAM_LOB),
                    'lastmodified' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
                    'etag' => $qb->createNamedParameter(md5($ical)),
                    'size' => $qb->createNamedParameter(strlen($ical), IQueryBuilder::PARAM_INT),
                    'componenttype' => $qb->createNamedParameter('VEVENT'),
                    'firstoccurence' => $qb->createNamedParameter($startDt->getTimestamp(), IQueryBuilder::PARAM_INT),
                    'lastoccurence' => $qb->createNamedParameter($endDt->getTimestamp(), IQueryBuilder::PARAM_INT),
                    'classification' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'uid' => $qb->createNamedParameter($uid),
                    'calendartype' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                ]);
            $qb->executeStatement();

            // Mirror what the CalDAV backend records on a PUT, so clients
            // sync the new event instead of seeing it only after a repair.
            $tokenQb = $this->db->getQueryBuilder();
            $tokenQb->select('synctoken')
                ->from('calendars')
                ->where($tokenQb->expr()->eq('id', $tokenQb->createNamedParameter((int)$target['id'], IQueryBuilder::PARAM_INT)));
            $syncToken = (int)$tokenQb->executeQuery()->fetchOne();

            $changesQb = $this->db->getQueryBuilder();
            $changesQb->insert('calendarchanges')
                ->values([
                    'uri' => $changesQb->createNamedParameter($uri),
                    'synctoken' => $changesQb->createNamedParameter($syncToken, IQueryBuilder::PARAM_INT),
                    'calendarid' => $changesQb->createNamedParameter((int)$target['id'], IQueryBuilder::PARAM_INT),
                    'operation' => $changesQb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
                    'calendartype' => $changesQb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
                    'created_at' => $changesQb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
                ]);
            $changesQb->executeStatement();

            $bumpQb = $this->db->getQueryBuilder();
            $bumpQb->update('calendars')
                ->set('synctoken', $bumpQb->createNamedParameter($syncToken + 1, IQueryBuilder::PARAM_INT))
                ->where($bumpQb->expr()->eq('id', $bumpQb->createNamedParameter((int)$target['id'], IQueryBuilder::PARAM_INT)));
            $bumpQb->executeStatement();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $when = $allDay
            ? $startDt->format('Y-m-d')
            : $startDt->format('Y-m-d H:i') . ' – ' . $endDt->format($endDt->getTimezone() === $startDt->getTimezone() ? 'H:i' : 'Y-m-d H:i T');

        return $this->textResult("Event '{$title}' created in calendar '{$target['displayname']}' on {$when}.");
    }

    /**
     * Parse an iCalendar blob into the occurrences of its events inside the
     * window. Recurring events are expanded with Sabre's EventIterator; a
     * single source object contributes at most MAX_EXPANDED_OCCURRENCES so an
     * eternal weekly cannot flood the reply.
     *
     * @return list<array{ts: int, line: string, details: list<string>}>
     */
    private function expandOccurrences(string $calendata, int $startTs, int $endTs): array {
        $calendar = Reader::read($calendata);
        if (!$calendar instanceof VCalendar) {
            return [];
        }

        $recurring = [];
        $oneOffs = [];
        foreach ($calendar->VEVENT as $event) {
            if (isset($event->RRULE) || isset($event->RDATE)) {
                $recurring[] = $event;
            } else {
                $oneOffs[] = $event;
            }
        }

        $found = [];
        foreach ($oneOffs as $event) {
            $found[] = $this->describeEvent($event);
        }

        if ($recurring !== []) {
            $expanded = $calendar->expand(new \DateTimeImmutable('@' . $startTs), new \DateTimeImmutable('@' . $endTs));
            $count = 0;
            foreach ($expanded->VEVENT as $event) {
                if (++$count > self::MAX_EXPANDED_OCCURRENCES) {
                    break;
                }
                $found[] = $this->describeEvent($event);
            }
        }

        return array_values(array_filter($found, static fn(?array $e): bool => $e !== null));
    }

    /**
     * @return array{ts: int, line: string, details: list<string>}|null
     */
    private function describeEvent(object $event): ?array {
        if (!isset($event->DTSTART)) {
            return null;
        }

        /** @var \Sabre\VObject\Property\ICalendar\DateTime|mixed $dtstart */
        $dtstart = $event->DTSTART;
        $allDay = $dtstart->getValueType() === 'DATE';
        $start = $dtstart->getDateTime();

        $details = [];
        if (isset($event->RRULE)) {
            $details[] = 'recurring: ' . (string)$event->RRULE;
        }
        if (isset($event->LOCATION) && (string)$event->LOCATION !== '') {
            $details[] = 'location: ' . (string)$event->LOCATION;
        }
        if (isset($event->DESCRIPTION) && (string)$event->DESCRIPTION !== '') {
            $description = (string)$event->DESCRIPTION;
            $details[] = 'description: ' . (mb_strlen($description) > 300 ? mb_substr($description, 0, 300) . '…' : $description);
        }

        $title = (isset($event->SUMMARY) && (string)$event->SUMMARY !== '') ? (string)$event->SUMMARY : '(untitled)';
        $end = $this->eventEnd($event);
        $time = $allDay
            ? $start->format('Y-m-d')
            : $start->format('Y-m-d H:i') . ($end !== null ? '–' . $end->format('H:i') : '');

        return ['ts' => $start->getTimestamp(), 'line' => '[' . $time . '] ' . $title, 'details' => $details];
    }

    private function eventEnd(object $event): ?\DateTimeImmutable {
        if (isset($event->DTEND) && $event->DTEND->getValueType() !== 'DATE') {
            try {
                return $event->DTEND->getDateTime();
            } catch (\Throwable) {
                return null;
            }
        }
        if (isset($event->DURATION)) {
            try {
                return (new \DateTimeImmutable('@' . (int)$event->DTSTART->getDateTime()->format('U')))
                    ->setTimezone($event->DTSTART->getDateTime()->getTimezone())
                    ->add($event->DURATION->getDurationInterval());
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }

    /**
     * @return \Sabre\VObject\Component\VCalendar
     */
    private function buildICalendar(string $uid, string $title, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $allDay, ?string $location, ?string $description): VCalendar {
        $calendar = new VCalendar(['CALSCALE' => 'GREGORIAN', 'VERSION' => '2.0']);
        $event = $calendar->createComponent('VEVENT');
        $calendar->add($event);
        // Sabre auto-fills UID and DTSTAMP placeholders when the VEVENT is
        // created; the magic setters replace them instead of duplicating.
        $event->UID = $uid;
        $event->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $event->add('SUMMARY', $title);
        $event->add('STATUS', 'CONFIRMED');

        if ($allDay) {
            $event->add('DTSTART', $start->setTime(0, 0), ['VALUE' => 'DATE']);
            $event->add('DTEND', $end->setTime(0, 0), ['VALUE' => 'DATE']);
        } else {
            $event->add('DTSTART', $start);
            $event->add('DTEND', $end);
        }

        if ($location !== null && trim($location) !== '') {
            $event->add('LOCATION', trim($location));
        }
        if ($description !== null && trim($description) !== '') {
            $event->add('DESCRIPTION', trim($description));
        }

        return $calendar;
    }

    /**
     * The user's own calendars, ordered the way the Calendar app shows them.
     *
     * @return list<array{id: int, displayname: string, uri: string, description: string, components: string}>
     */
    private function calendars(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'displayname', 'uri', 'description', 'components')
            ->from('calendars')
            ->where($qb->expr()->eq('principaluri', $qb->createNamedParameter('principals/users/' . $userId)))
            ->andWhere($qb->expr()->isNull('deleted_at'))
            ->orderBy('calendarorder', 'ASC')
            ->addOrderBy('displayname', 'ASC');

        $rows = $qb->executeQuery()->fetchAll();

        return array_map(static fn(array $row): array => [
            'id' => (int)$row['id'],
            'displayname' => (string)$row['displayname'],
            'uri' => (string)$row['uri'],
            'description' => (string)($row['description'] ?? ''),
            'components' => (string)($row['components'] ?? ''),
        ], $rows);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dateRange(string $start, string $end): array {
        $start = $this->validateDate($start) ?? (new \DateTimeImmutable('today'))->format('Y-m-d');
        $end = $this->validateDate($end) ?? (new \DateTimeImmutable($start))->add(new \DateInterval('P6D'))->format('Y-m-d');
        if ($end < $start) {
            $end = $start;
        }
        return [$start, $end];
    }

    private function validateDate(string $date): ?string {
        if ($date === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException("Invalid date '{$date}', expected YYYY-MM-DD.");
        }
        return $date;
    }

    private function parseDateTime(string $value, bool $allDay): ?\DateTimeImmutable {
        try {
            if ($allDay) {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
                return $dt === false ? null : $dt;
            }
            // A bare 'Y-m-d' was caught by the caller; anything else is a
            // datetime, with or without an explicit offset.
            $dt = new \DateTimeImmutable($value);
            if (substr_count($value, '-') === 2 && substr_count($value, ':') === 0) {
                // 'Y-m-d H' or similar oddities that DateTime silently accepts.
                return null;
            }
            return $dt;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function textResult(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    /** @return array{content: list<array<string, mixed>>, isError: bool} */
    private function errorResult(string $text): array {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }
}
