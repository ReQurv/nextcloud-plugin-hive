<?php
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\RequrvHive\Notifier;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class RequrvHiveNotifier implements INotifier {

    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l10n,
    ) {
    }

    public function getID(): string {
        return 'requrvhive';
    }

    public function getName(): string {
        return $this->l10n->t('RequrvHive');
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'requrvhive') {
            throw new \InvalidArgumentException();
        }

        $params = $notification->getSubjectParameters();

        switch ($notification->getSubject()) {
            case 'task_success':
                $taskType = $params[0] ?? 'AI';
                $notification->setParsedSubject(
                    $this->l10n->t('RequrvHive task completed')
                );
                $notification->setParsedMessage(
                    $this->l10n->t('Your %s task has completed successfully.', [$taskType])
                );
                break;

            case 'task_failure':
                $taskType = $params[0] ?? 'AI';
                $error = $params[1] ?? '';
                $notification->setParsedSubject(
                    $this->l10n->t('RequrvHive task failed')
                );
                $message = $error
                    ? $this->l10n->t('Your %1$s task failed: %2$s', [$taskType, $error])
                    : $this->l10n->t('Your %s task failed.', [$taskType]);
                $notification->setParsedMessage($message);
                break;

            case 'ask_response':
                $notification->setParsedSubject(
                    $this->l10n->t('RequrvHive response')
                );
                $notification->setParsedMessage((string)($params[0] ?? ''));
                break;

            default:
                throw new \InvalidArgumentException();
        }

        $notification->setIcon(
            $this->urlGenerator->imagePath('requrvhive', 'app-dark.svg')
        );

        return $notification;
    }
}
