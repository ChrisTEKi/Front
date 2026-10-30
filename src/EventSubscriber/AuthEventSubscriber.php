<?php

namespace App\EventSubscriber;

use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Réagit aux événements de sécurité de Symfony :
 *  - connexion réussie : la langue enregistrée sur le compte devient la langue de la session ;
 *  - déconnexion      : inscrite au journal d'audit.
 */
final class AuthEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if ($user instanceof User && $event->getRequest()->hasSession()) {
            $event->getRequest()->getSession()->set(LocaleSubscriber::SESSION_KEY, $user->getLocale());
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->audit->record(AuditAction::LOGOUT, $user, 'user', $user->getId()->toRfc4122());
        }
    }
}
