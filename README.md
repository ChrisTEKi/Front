# GoBuy — front-end Symfony + back-end d'authentification (PostgreSQL)

Portage Symfony de la maquette **Marketoo** (React) : Twig pour le rendu, Tailwind CSS (AssetMapper, aucun Node.js
requis), icônes Tabler (`symfony/ux-icons`), JavaScript natif. Ce livrable ajoute tout le **back-end d'authentification
et de comptes** sur PostgreSQL : inscription, vérifications e-mail/SMS, double authentification, Google/Apple,
fournisseurs avec validation par un administrateur, espace Super Admin, historique et suivi de commandes, suppression
de compte, exports CSV, e-mails de confirmation, trois langues.

> Le détail complet (parcours, sécurité, tables, tâches planifiées) est dans **[AUTH.md](AUTH.md)**.

## Ce qui est fait

| Domaine | Fonctionnalité |
|---|---|
| Inscription client | E-mail + code reçu par e-mail ; SMS facultatif si un téléphone est saisi ; un **mobile valide et vérifié reste obligatoire avant la 1re commande** |
| Inscription fournisseur | E-mail **et** téléphone vérifiés, puis **approbation par un administrateur** (dossier « Devenir partenaire » enregistré) |
| Connexion | Mot de passe (Argon2id), anti force-brute (IP + compte), 2FA facultative par e-mail ou SMS, Google et Apple |
| Mot de passe oublié | Code à usage unique, réponse identique que le compte existe ou non |
| Navigation invité | Catalogue, panier et pages d'information sans compte ; connexion demandée seulement pour commander |
| Commande | Vérification du téléphone et du numéro **Mobile Money** (Mvola, Orange Money, Airtel Money) avant le paiement ; e-mail de confirmation automatique |
| Compte | Historique des commandes, suivi de colis, mot de passe, 2FA, **suppression avec effacement définitif après 30 jours** ; **changement d'e-mail ou de téléphone avec double confirmation** (code à l'ancienne adresse puis à la nouvelle, tous types de comptes) ; **photo de profil** ; **carnet d'adresses de livraison** (plusieurs adresses, une par défaut, saisie à l'inscription ou à la commande) |
| Administration | **Super Admin** unique (créé en ligne de commande) qui crée les autres administrateurs (Finance, Comptabilité, Support, Modération…) avec droits ajoutés/retirés ; journal d'audit ; **exports CSV** clients, fournisseurs, administrateurs, commandes |
| Langues | Français, anglais, espagnol ; détection automatique (choix mémorisé > navigateur > français) ; e-mails et SMS dans la langue du compte |
| Support | Contact, réclamation, candidature spontanée et avis du site : envoyés par e-mail à `GOBUY_SUPPORT_EMAIL` |

Les commentaires du code sont écrits en français, ligne par ligne pour les parties sensibles (sécurité, base de
données, cron).

## Installation

Prérequis : **PHP 8.4** (extensions `intl`, `mbstring`, `pdo_pgsql`, `sodium`, `ctype`, `iconv`, **`gd`** pour les photos de profil ; `exif` recommandée), Composer,
PostgreSQL 16 (ou Docker).

```bash
composer install                      # génère aussi composer.lock : à versionner
docker compose up -d                  # PostgreSQL 16 + Mailpit (faux SMTP, http://localhost:8025)
cp .env .env.local                    # puis éditer .env.local (secret, base, SMTP, SMS…)
php bin/console doctrine:migrations:migrate
php bin/console tailwind:build        # compile le CSS (--watch en développement)
php bin/console app:admin:create-super --email=vous@exemple.com --name="Votre Nom"
symfony server:start                  # ou : php -S 127.0.0.1:8000 -t public
```

En production : `export APP_ENV=prod` **avant** `composer install --no-dev --optimize-autoloader` (sinon le
`cache:clear` du script d'installation cherche les bundles de développement), puis
`php bin/console tailwind:build --minify` et `php bin/console asset-map:compile`.

## Configuration (`.env.local`)

| Variable | Rôle |
|---|---|
| `APP_ENV`, `APP_SECRET` | **Hors `dev`/`test`** (prod, staging, preprod…), le Kernel refuse de démarrer si le secret est absent, < 32 caractères ou égal à l'exemple |
| `DATABASE_URL` | PostgreSQL (la base de tests est `<base>_test`) |
| `DEFAULT_URI` | URL publique (liens des e-mails, retours Google/Apple) |
| `TRUSTED_PROXIES` | **Obligatoire derrière un reverse-proxy / CDN** (sinon toutes les IP vues sont celles du proxy et les limites par IP s'appliquent à tout le monde) |
| `MAILER_DSN`, `MAILER_FROM_EMAIL`, `MAILER_FROM_NAME` | E-mails (codes, confirmations, support) |
| `TEXTER_DSN` | Passerelle SMS. `null://null` = aucun SMS envoyé. Twilio est fourni (`twilio://SID:TOKEN@default?from=+…`) ; un autre opérateur demande son pont `symfony/*-notifier` |
| `GOOGLE_CLIENT_ID/SECRET`, `APPLE_CLIENT_ID/TEAM_ID/KEY_ID/PRIVATE_KEY_PATH` | Vides = boutons Google/Apple masqués |
| `ADMIN_ALLOWED_IPS` | Liste d'IP/CIDR autorisées sur `/admin` (vide = pas de restriction) |
| `GOBUY_SUPPORT_EMAIL` | Reçoit contact, réclamations, candidatures, avis. **Vide = ces formulaires sont masqués** |
| `GOBUY_SUPPORT_WHATSAPP` | Numéro WhatsApp du support (vide = lien masqué) |
| `GOBUY_COMPANY_ADDRESS/NIF/STAT/RCS` | Identité légale affichée dans « À propos » (chaque ligne vide est masquée) |
| `GOBUY_SUPER_ADMIN_PASSWORD` | Facultatif : mot de passe fourni à `app:admin:create-super` en déploiement automatisé (à retirer ensuite) |
| `APP_OTP_LOG_CODES` | `1` = codes OTP en clair dans les logs, **uniquement honoré en `dev` et `test`** |

## Tâches planifiées (cron)

```cron
15 3 * * *            cd /chemin/gobuy && php bin/console app:accounts:purge            # efface les comptes dont les 30 jours sont écoulés
*/10 * * * *          cd /chemin/gobuy && php bin/console app:orders:send-confirmations # renvoie les e-mails de commande restés en attente
```

`app:accounts:purge --dry-run` compte sans rien modifier.

## Tests

```bash
APP_ENV=test php bin/console doctrine:database:create --if-not-exists
APP_ENV=test vendor/bin/phpunit
```

385 tests sur une **vraie base PostgreSQL** (pas de moteur de substitution) : parcours d'inscription et de connexion,
limites de débit, OTP, Google/Apple (faux fournisseur OIDC signé, aucun appel réseau), droits d'administration, exports
CSV, suppression de compte, rendu des pages dans les trois langues.

## À savoir avant la mise en ligne

- **Versions.** Le code cible Symfony 8.1 / PHP 8.4 (`composer.json`). Il a été exécuté et testé ici sur Symfony 7.4 /
  PHP 8.3 (seules versions installables dans l'environnement de travail) en s'interdisant les API qui diffèrent. Lancez
  la suite de tests après le premier `composer install`.
- **Catalogue.** Il n'existe pas de table de produits : `App\Order\CatalogInterface` est branchée sur `EmptyCatalog`
  (aucun produit, donc aucune commande possible). Écrivez votre implémentation (voir le commentaire dans
  `config/services.yaml`) pour que le panier et la commande fonctionnent. Les boutons « Ajouter un produit » du
  fournisseur sont désactivés en attendant.
- **Paiement.** Le Mobile Money est **vérifié** (le numéro appartient bien au client, code SMS) mais aucun prélèvement
  n'est effectué : la passerelle de paiement n'est pas incluse.
- **Google / Apple** : testés avec un faux fournisseur ; à essayer avec de vrais identifiants avant ouverture.
- **Textes juridiques** (CGU, CGV, confidentialité, délais et zones de livraison, chiffres d'« À propos ») : repris de
  la maquette, ce sont des **brouillons à faire valider** — ils ne remplacent pas un avis juridique.
- **Support.** Les messages arrivent dans la boîte du support ; il n'y a pas de système de tickets.
- **Fournisseurs.** Les ventes ne sont visibles qu'une fois le dossier approuvé. La facturation de la formule Pro n'est
  pas branchée (le taux de commission affiché suit la candidature).

## Structure

```
src/Auth/            parcours de connexion (LoginFlow), inscription, OTP, mot de passe oublié, politique de mot de passe
src/Account/         compte : coordonnées (double confirmation), photo, carnet d'adresses, 2FA, suppression, purge définitive
src/Admin/           Super Admin, profils et droits, exports CSV, validation fournisseurs, réauthentification
src/Social/          Google / Apple (OpenID Connect + PKCE)
src/Order/, Payment/ commande, numéro, e-mail de confirmation, vérification Mobile Money
src/Supplier/        tableau de bord fournisseur
src/Contact/         formulaires du support
src/Security/        authentificateur, vérification de compte, droits fins (voter)
src/Audit/           journal d'audit
src/Command/         commandes console (super admin, purge, confirmations)
templates/, assets/  Twig, Tailwind, JavaScript natif
translations/        fr / en / es par domaine (messages, js, auth, orders, emails, validators, security, admin, categories)
migrations/          schéma PostgreSQL commenté
tests/               PHPUnit (vraie base PostgreSQL)
```

Les classes Tailwind sont écrites en toutes lettres dans les templates (jamais construites par interpolation) pour
rester détectées par le scanner de `symfonycasts/tailwind-bundle`.
