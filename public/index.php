<?php
// =============================================================================
// public/index.php — front controller : TOUTES les requêtes HTTP passent ici.
// Le serveur web (Nginx / Apache / `symfony serve`) ne doit exposer que le
// dossier public/ : le reste du projet (src/, config/, .env…) reste inaccessible.
// =============================================================================

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Retourne une closure qui fabrique le noyau applicatif ; le runtime se charge
// de créer la Request, d'appeler le Kernel et d'envoyer la Response.
return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
