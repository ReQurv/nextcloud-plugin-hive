<?php

namespace OCA\RequrvHive\Tests\Unit\Notifier;

use OCA\RequrvHive\Notifier\RequrvHiveNotifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class RequrvHiveNotifierTest extends TestCase {
    private IURLGenerator $urlGenerator;
    private IL10N $l10n;
    private RequrvHiveNotifier $notifier;

    protected function setUp(): void {
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => $params ? vsprintf($text, $params) : $text
        );
        $this->urlGenerator->method('imagePath')->willReturn('/apps/requrvhive/img/app-dark.svg');
        $this->notifier = new RequrvHiveNotifier($this->urlGenerator, $this->l10n);
    }

    public function testGetId(): void {
        $this->assertSame('requrvhive', $this->notifier->getID());
    }

    public function testGetName(): void {
        $this->assertSame('RequrvHive', $this->notifier->getName());
    }

    public function testPrepareThrowsForOtherApp(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('other_app');

        $this->expectException(\InvalidArgumentException::class);
        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskSuccess(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('requrvhive');
        $notification->method('getSubject')->willReturn('task_success');
        $notification->method('getSubjectParameters')->willReturn(['Summarization']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('RequrvHive task completed');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Summarization task has completed successfully.');
        $notification->expects($this->once())->method('setIcon')
            ->with('/apps/requrvhive/img/app-dark.svg');

        // Chain methods return self
        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskFailureWithError(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('requrvhive');
        $notification->method('getSubject')->willReturn('task_failure');
        $notification->method('getSubjectParameters')->willReturn(['Text generation', 'API timeout']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('RequrvHive task failed');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Text generation task failed: API timeout');
        $notification->expects($this->once())->method('setIcon');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareTaskFailureWithoutError(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('requrvhive');
        $notification->method('getSubject')->willReturn('task_failure');
        $notification->method('getSubjectParameters')->willReturn(['Image analysis', '']);

        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Your Image analysis task failed.');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareAskResponse(): void {
        // The subject RequrvHiveService::askAsync() sends; it used to be free text,
        // which landed in the default branch and threw.
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('requrvhive');
        $notification->method('getSubject')->willReturn('ask_response');
        $notification->method('getSubjectParameters')->willReturn(['Here is the answer']);

        $notification->expects($this->once())->method('setParsedSubject')
            ->with('RequrvHive response');
        $notification->expects($this->once())->method('setParsedMessage')
            ->with('Here is the answer');

        $notification->method('setParsedSubject')->willReturn($notification);
        $notification->method('setParsedMessage')->willReturn($notification);
        $notification->method('setIcon')->willReturn($notification);

        $this->notifier->prepare($notification, 'en');
    }

    public function testPrepareUnknownSubjectThrows(): void {
        $notification = $this->createMock(INotification::class);
        $notification->method('getApp')->willReturn('requrvhive');
        $notification->method('getSubject')->willReturn('unknown_subject');

        $this->expectException(\InvalidArgumentException::class);
        $this->notifier->prepare($notification, 'en');
    }
}
