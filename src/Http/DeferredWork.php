<?php

namespace App\Http;

/**
 * File d'attente de tâches à exécuter APRÈS l'envoi de la réponse HTTP au navigateur.
 *
 * Usage typique : « mot de passe oublié ». Si le compte existe on envoie un e-mail (≈ 200 ms), sinon on ne fait
 * rien (≈ 0 ms) : en mesurant le temps de réponse, un curieux pourrait deviner quelles adresses sont inscrites.
 * En renvoyant la réponse AVANT de faire le travail, la durée perçue est identique dans tous les cas.
 *
 * Les tâches sont exécutées par DeferredWorkSubscriber à l'événement « kernel.terminate ».
 */
final class DeferredWork
{
    /** @var list<\Closure> */
    private array $tasks = [];

    public function defer(\Closure $task): void
    {
        $this->tasks[] = $task;
    }

    /**
     * Retire et retourne les tâches en attente.
     *
     * @return list<\Closure>
     */
    public function drain(): array
    {
        $tasks = $this->tasks;
        $this->tasks = [];

        return $tasks;
    }
}
