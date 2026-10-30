<?php

// Amorçage des tests : charge l'autoload Composer puis les variables d'environnement (.env, .env.test).
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Charge .env puis .env.test (APP_ENV=test) : base de données de test, MAILER_DSN=null://null, etc.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
