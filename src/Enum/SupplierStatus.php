<?php

namespace App\Enum;

/**
 * Statut d'approbation d'un fournisseur (colonne `supplier_profiles.status`).
 * Un fournisseur ne peut publier des produits qu'une fois APPROVED par un administrateur.
 */
enum SupplierStatus: string
{
    /** Dossier soumis, en attente d'examen par un admin. */
    case Pending = 'pending';

    /** Dossier validé : le fournisseur peut vendre. */
    case Approved = 'approved';

    /** Dossier refusé (un motif est enregistré). */
    case Rejected = 'rejected';

    /** Approbation retirée après coup par un admin. */
    case Suspended = 'suspended';
}
