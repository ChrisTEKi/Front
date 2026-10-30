<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Lecture sûre du corps JSON d'une requête, partagée par les contrôleurs d'API.
 * (Un trait = un petit morceau de code réutilisable dans plusieurs classes.)
 */
trait ReadsJsonPayload
{
    /**
     * Lit le corps JSON de la requête. Un corps invalide ou qui n'est pas un objet JSON donne une erreur 400.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        try {
            return $request->getPayload()->all();
        } catch (\Throwable) {
            throw new BadRequestHttpException('Requête invalide.');
        }
    }

    /**
     * Lit un champ texte (espaces retirés). Une valeur qui n'est pas un texte (tableau, objet) devient « ».
     *
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }
}
