<?php

declare(strict_types=1);

namespace Aithos\Agentenblick\Admin;

use Shopware\Core\Framework\Api\Acl\Event\AclGetAdditionalPrivilegesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AclPrivilegesSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [AclGetAdditionalPrivilegesEvent::class => 'addPrivileges'];
    }

    public function addPrivileges(AclGetAdditionalPrivilegesEvent $event): void
    {
        $event->setPrivileges(array_values(array_unique([...$event->getPrivileges(), 'agentenblick.viewer', 'agentenblick.editor'])));
    }
}
