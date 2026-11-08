<?php

namespace App\Currency;

/**
 * Source des taux de change : retourne, pour chaque devise étrangère, le nombre d'ARIARY pour UNE unité.
 *
 * Implémentation branchée : OpenErApiProvider (service public gratuit, sans clé). Pour utiliser un autre fournisseur
 * (banque centrale, service payant), écrivez une classe qui implémente cette interface et changez l'alias dans config/services.yaml.
 */
interface ExchangeRateProvider
{
    /**
     * @return array<string, string> code de devise (« EUR », « USD ») => Ariary pour 1 unité, en texte décimal positif
     *
     * @throws ExchangeRateUnavailableException si le service ne répond pas ou répond n'importe quoi
     */
    public function fetch(): array;
}
