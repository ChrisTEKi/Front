<?php

namespace App\EventSubscriber;

use App\Http\DeferredWork;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exécute les tâches différées (voir DeferredWork) une fois la réponse envoyée au navigateur.
 * Une tâche qui échoue est journalisée mais n'empêche pas les suivantes.
 */
final class DeferredWorkSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DeferredWork $deferred,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        foreach ($this->deferred->drain() as $task) {
            try {
                $task();
            } catch (\Throwable $e) {
                $this->logger->error('Tâche différée en échec : {error}', ['error' => $e->getMessage()]);
            }
        }
    }
}
