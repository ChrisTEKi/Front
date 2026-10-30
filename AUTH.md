# GoBuy — authentification, comptes et administration

Document de référence du back-end (Symfony + PostgreSQL). Pour l'installation et les variables `.env`, voir
[README.md](README.md). Les commentaires du code source détaillent chaque ligne ; ce document donne la vue d'ensemble.

## 1. Principe : « personne n'est à moitié connecté »

Un utilisateur n'a un jeton Symfony **qu'une fois toutes les étapes exigées franchies** : mot de passe → e-mail (et
téléphone, pour un fournisseur) vérifié → 2FA si activée → restauration du compte si une suppression était en cours.
Ces étapes sont pilotées par une machine à états côté serveur (`App\Auth\LoginFlow`, état dans la session) ; le
navigateur ne décide jamais de l'étape suivante. Le dernier geste est `Security::login()` avec
`App\Security\FlowAuthenticator`. `UserChecker` est une seconde barrière (compte suspendu, e-mail non vérifié) et
l'identifiant de session est renouvelé à la connexion.

États du parcours (`FlowStep`) : `login`, `verify_email`, `verify_sms`, `two_factor`, `restore_account`, `done`,
`blocked`. Un parcours expire après 30 min au total ou 15 min d'inactivité.

## 2. Parcours

**Client.** Inscription (nom, e-mail, mot de passe, téléphone facultatif, **adresse de livraison facultative**) → code à 6 chiffres envoyé par e-mail → si un
téléphone a été saisi, code SMS (on peut passer cette étape) → connecté. Le **téléphone mobile valide et vérifié est
obligatoire avant la première commande** : il est demandé et vérifié au moment de commander si besoin. Un numéro
vérifié est **unique** dans la base (index unique partiel PostgreSQL). Si une adresse a été saisie à l'inscription, elle
devient l'**adresse par défaut** du nouveau compte (rien n'est créé quand l'e-mail existe déjà : la réponse « leurre »
ne modifie aucun compte).

**Fournisseur.** Assistant « Devenir partenaire » : e-mail **et** téléphone vérifiés, dossier détaillé enregistré
(`supplier_profiles.application`, JSONB). Statut `pending` jusqu'à décision d'un administrateur
(`approved` / `rejected` avec motif / `suspended`). Un fournisseur non approuvé ouvre son espace (il voit son statut et
le motif) mais **aucun chiffre de vente**.

**Google / Apple.** OpenID Connect avec PKCE, `state` et `nonce` liés à la session. Le compte est créé ou rattaché à
l'identité (`social_identities`) ; l'e-mail doit être vérifié par le fournisseur d'identité. Apple répond par un POST
venant d'un autre site : les paramètres sont mis de côté (2 min, usage unique, limités en débit et en taille) puis
l'utilisateur est redirigé en GET pour que le cookie de session soit présent. Sans identifiants dans `.env`, les
boutons sont masqués.

**Double authentification (facultative).** Activée dans « Mon compte → Sécurité » (nécessite le mot de passe actuel).
Code envoyé par e-mail ou SMS à chaque connexion. Pas d'application TOTP/WebAuthn (voir § 11).

**Mot de passe oublié.** Code à usage unique par e-mail. La réponse est identique que l'adresse existe ou non, y compris
pour une adresse d'administrateur. Les **administrateurs n'ont pas de réinitialisation en libre-service** (§ 6).

**Invité.** Catalogue, panier, pages d'information : sans compte. La connexion n'est demandée que pour commander.

**Commande.** Téléphone vérifié → vérification du **numéro Mobile Money** (code SMS envoyé à ce numéro ; opérateurs
Mvola, Orange Money, Airtel Money) → commande (clé d'idempotence : un double clic ne crée pas deux commandes) →
numéro `GB-AAAA-NNNNNN` → **e-mail de confirmation automatique**. Si le serveur d'e-mails est indisponible, la commande
est quand même enregistrée et `app:orders:send-confirmations` renvoie l'e-mail plus tard. Suivi : statuts
`confirmed → preparing → out_for_delivery → delivered` (ou `cancelled`), chaque changement crée un événement horodaté
(`order_tracking_events`) visible dans `/suivi/{numéro}` et l'historique du compte.

**Changer son e-mail ou son téléphone (tous les comptes : client, fournisseur, administrateur, Super Admin).**
« Mon compte → Mes informations → Modifier ». Double confirmation, rien n'est écrit avant la fin :

1. la personne saisit la nouvelle valeur → un code part vers l'**ancienne** adresse e-mail (preuve : « je suis bien le
   titulaire ») ;
2. ce code saisi → un second code part vers la **nouvelle** adresse e-mail (ou un SMS vers le nouveau numéro) ;
3. second code juste → le compte est modifié ; la nouvelle adresse est vérifiée d'office, un **avis part vers l'ancienne
   adresse** (la nouvelle n'y figure que masquée), le journal d'audit garde le fait (adresses masquées).

La demande vit dans la session du serveur (30 minutes, liée au compte et à l'e-mail de départ) ; le navigateur ne peut ni
sauter une étape ni changer la valeur demandée entre deux étapes. Après un changement d'e-mail, **les autres appareils sont
déconnectés** (l'e-mail fait partie de l'empreinte de session) et la session en cours est rouverte. Retirer son numéro ne demande
que le code de l'ancienne adresse ; **un fournisseur peut remplacer son numéro mais pas le retirer**. Le nouveau numéro est
prouvé par SMS : si la 2FA par SMS était active, elle le reste. Un numéro déjà vérifié sur un autre compte est refusé
(`phone_taken`, ancien numéro conservé). Ajouter un premier numéro ou corriger un numéro **non vérifié** garde le parcours
simple (code SMS seul) : il n'y a encore rien de fiable à protéger.

*Anti-énumération.* Si la nouvelle adresse appartient déjà à un autre compte, l'écran est **identique** : aucun code n'est
envoyé à cette adresse, mais son vrai titulaire reçoit un avis (3 par jour au plus) ; tout code saisi est refusé comme un code faux
(5 essais puis blocage, renvoi avec le même délai de 30 s). Au plus 5 codes par jour vers une même adresse, quel que soit
le compte demandeur (une boîte tierce ne peut pas être inondée).

**Photo de profil (tous les comptes).** « Mon compte → Mes informations ». Le navigateur réduit l'image à 1 024 px ; le serveur la
**décode puis la ré-encode toujours** en JPEG carré de 512 px au plus (fond blanc sous les transparences, sens EXIF appliqué, métadonnées
dont le GPS supprimées). Seuls JPEG, PNG, WebP et GIF reconnus par leur **contenu** sont acceptés (le SVG et les scripts déguisés en
image sont refusés) ; la taille en pixels est lue dans l'en-tête avant tout décodage (16 millions au plus : pas de « bombe »
d'image) ; 5 Mo au plus ; 10 envois / heure et par compte. L'image est dans `user_avatars` (une par compte), servie par
`GET /profil/photo` (celle du compte connecté uniquement, `private, no-cache` + ETag) et **supprimée avec le compte** (cascade).

**Adresses de livraison (clients).** Plusieurs adresses (10 au plus), **une seule « par défaut »** garantie par un index unique
partiel (`uniq_addresses_default`) et un verrou par client contre les requêtes simultanées. La 1re adresse enregistrée devient
la défaut ; supprimer la défaut promeut la plus récente des autres ; une adresse identique n'est jamais recréée. L'adresse peut
être saisie **à l'inscription** (facultative) ou **à la commande** : la page de commande préremplit la défaut et propose les autres
adresses enregistrées ou une nouvelle, avec les cases « enregistrer cette adresse » (cochée) et « en faire mon adresse par
défaut ». Une commande n'échoue jamais parce que le carnet est plein (l'adresse n'est alors simplement pas enregistrée) ; les
commandes passées gardent leur propre copie de l'adresse. Gestion complète dans « Mon compte → Mes adresses ».

**Suppression de compte (clients).** Demande avec mot de passe (ou code e-mail pour un compte Google/Apple) ; refusée
tant qu'une commande est en cours. Le compte passe en `pending_deletion` et la personne est déconnectée ; **se
reconnecter pendant les 30 jours propose de restaurer le compte**. Passé ce délai, `app:accounts:purge` :
anonymise les commandes (montants et dates conservés pour la comptabilité), épure le journal d'audit (e-mail, IP,
navigateur), supprime le compte (adresses, Mobile Money, identités sociales et codes partent en cascade en base),
ajoute une ligne neutre `account.purged` et envoie un dernier e-mail. Un compte qui aurait encore une commande en cours
au moment de la purge est reporté. Seuls les **clients** sont concernés, jamais un fournisseur ni un administrateur.

## 3. API JSON

Toutes les routes `POST` JSON exigent l'en-tête `X-CSRF-Token` (jeton de session) ; sans lui, réponse 403.

| Route | Rôle |
|---|---|
| `GET /auth/state` | Étape courante du parcours (reprise après rechargement) |
| `POST /auth/login` | Mot de passe |
| `POST /auth/register/client`, `/auth/register/supplier` | Inscriptions |
| `POST /auth/verify`, `/auth/resend`, `/auth/skip` | Saisie / renvoi / passage de l'étape SMS |
| `POST /auth/restore`, `/auth/cancel` | Restaurer un compte en cours de suppression / abandonner |
| `POST /auth/forgot`, `/auth/reset` | Mot de passe oublié |
| `GET /auth/social/{google\|apple}` (+ `/callback`, `/finish`) | Connexion rapide |
| `POST /compte/api/{mot-de-passe,double-authentification,telephone,telephone/verifier,confirmation/envoyer,suppression}` | Compte (`telephone` : ajout / correction d'un numéro **non vérifié** seulement) |
| `POST /compte/api/contact/{demarrer,confirmer,renvoyer,annuler}` | Changement d'e-mail ou de téléphone avec double confirmation (§ 2) |
| `POST /compte/api/photo`, `/compte/api/photo/supprimer` · `GET /profil/photo` | Photo de profil (multipart, champ `photo`) |
| `GET /compte/api/adresses` · `POST /compte/api/adresses`, `/{id}/{modifier,defaut,supprimer}` | Carnet d'adresses (clients) ; l'identifiant d'une adresse d'un autre compte donne 404 |
| `POST /commande/valider`, `/paiement/telephone/{envoyer,verifier}`, `/paiement/mobile-money/{envoyer,verifier}` | Commande et paiement |
| `POST /support/message` | Contact, réclamation, candidature, avis |
| `POST /admin/api/…` | Administration (§ 6) |
| `GET /langue/{fr\|en\|es}` | Changement de langue |

## 4. Base PostgreSQL

Migrations Doctrine commentées dans `migrations/` (colonnes et tables ont un `COMMENT` SQL). Identifiants **UUID v7**.

| Table | Contenu | Points notables |
|---|---|---|
| `users` | Tous les comptes (client, fournisseur, admin) | e-mail unique ; **téléphone vérifié unique** (index partiel) ; `roles` JSONB ; `deletion_scheduled_at` ; `avatar_updated_at` (NULL = pas de photo) |
| `user_avatars` | Photo de profil (JPEG 512 px, `bytea`) | une ligne par compte ; supprimée en cascade avec le compte |
| `one_time_codes` | Codes OTP | **hachés** (HMAC-SHA256 lié à la finalité, au compte et à la destination) ; tentatives, expiration |
| `social_identities` | Google / Apple | unique (fournisseur, identifiant) |
| `addresses` | Carnet d'adresses de livraison | `is_default` (**une seule par client** : index unique partiel), `label`, téléphone facultatif ; supprimées en cascade avec le compte |
| `mobile_money_accounts` | Numéros Mobile Money vérifiés | cascade |
| `supplier_profiles` | Dossier fournisseur | `application` JSONB, statut, relecteur, motif |
| `admin_roles`, `admin_profiles` | Profils de droits et administrateurs | droits ajoutés/retirés par administrateur ; `ON DELETE RESTRICT` sur un profil encore utilisé |
| `orders`, `order_items`, `order_tracking_events` | Commandes | coordonnées figées dans la commande ; anonymisées à la purge ; `confirmation_email_sent_at` |
| `audit_logs` | Journal d'audit | sans clé étrangère (survit aux suppressions), détails JSONB sans secret |

Le profil système « Super Admin » et quatre profils de départ (**Finance, Comptabilité, Support, Modération**) sont
créés par les migrations : ce sont des réglages, pas des données de démonstration.

## 5. Sécurité : ce qui est en place

| Sujet | Mesure |
|---|---|
| Mots de passe | Argon2id (64 Mio, 4 itérations) ; clients ≥ 10 caractères, admins ≥ 14 avec complexité ; refus des mots de passe contenant l'e-mail ou présents dans des fuites connues (API Have I Been Pwned en k-anonymité ; si elle est injoignable, la vérification est ignorée) |
| Connexion | 20 essais / 10 min par IP **et** 5 échecs / 15 min par e-mail ; temps de réponse identique que le compte existe ou non (hachage factice) ; même message d'erreur |
| Inscription | 10 / h par IP ; inscrire une adresse déjà utilisée renvoie une réponse **indiscernable** d'une inscription réussie (pas d'énumération de comptes) |
| Codes OTP | 6 chiffres, 10 min, 5 essais par code **et 15 échecs cumulés / 6 h** par compte et finalité, 30 s entre deux envois, 3 envois / 10 min, comparaison à temps constant, verrou de ligne (pas de course entre requêtes parallèles) |
| SMS (coût et fraude) | 15 / h par IP, 10 / jour par compte, 8 / jour par numéro |
| Mot de passe oublié | 3 / h par e-mail, 10 / h par IP |
| Changement d'e-mail / téléphone | code à l'**ancienne** adresse puis à la **nouvelle** destination, avant toute écriture ; mêmes protections OTP (finalités distinctes `contact_change_old` / `contact_change_new`, échecs cumulés comptés séparément) ; 5 codes / jour par adresse cible ; avis à l'ancienne adresse ; autres sessions fermées ; réponse « leurre » si l'adresse est déjà prise |
| Photo de profil | ré-encodage systématique, type reconnu par le contenu, contrôle des dimensions avant décodage, 10 envois / h / compte, servie au seul propriétaire |
| Session | cookie `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS ; 8 h d'inactivité (15 min pour les admins) |
| CSRF | jeton obligatoire sur toutes les requêtes JSON modifiantes ; `/langue` n'enregistre la langue du compte que si le clic vient d'une page de GoBuy |
| En-têtes | `nosniff`, `X-Frame-Options: DENY`, CSP (`frame-ancestors`, `base-uri`, `object-src`, `form-action`), `Referrer-Policy`, `Permissions-Policy`, HSTS en HTTPS, `no-store` sur les pages privées |
| Secrets | hors `dev`/`test` le Kernel refuse un `APP_SECRET` faible ; les codes OTP ne sont jamais écrits dans les logs hors `dev`/`test` |
| Données | CSV protégés contre l'injection de formules ; aucun mot de passe, jeton ni code exporté ; purge définitive des comptes supprimés |

## 6. Administration

- **Super Admin** : un seul, créé **uniquement en ligne de commande** (`php bin/console app:admin:create-super`, mot de
  passe saisi en masqué ou `GOBUY_SUPER_ADMIN_PASSWORD`, 14 caractères minimum). Le mot de passe se change par
  `app:admin:reset-super-password` (accès serveur requis). Il possède tous les droits.
- **Créer des administrateurs** : réservé au Super Admin (`/admin` → Administrateurs). Il choisit un **profil**
  (Finance, Comptabilité, Support, Modération ou un profil qu'il crée) puis peut **ajouter** ou **retirer** des droits
  individuellement. L'administrateur reçoit un **lien d'invitation** (256 bits, valable 48 h, à usage unique) pour
  choisir son mot de passe.
- **Droits** (`Permission`) : `users.view/suspend/export`, `suppliers.view/approve/export`, `orders.view/update_tracking`,
  `finance.view/manage`, `audit.view`, `admins.view/manage/export`. Les trois droits `admins.*` ne sont attribuables à
  personne d'autre que le Super Admin. Contrôle par `#[IsGranted]` sur chaque action.
- **Actions sensibles** (inviter, modifier les droits, suspendre ou réinitialiser un admin, gérer les profils) : le mot
  de passe de l'administrateur est redemandé (5 essais ratés / 15 min). Les exports demandent un jeton CSRF, sont limités
  à 20 actions sensibles / 10 min par administrateur et tracés dans le journal.
- **Pas de mot de passe oublié pour un admin** : le Super Admin utilise « Réinitialiser l'accès » (le mot de passe est
  supprimé, ses sessions s'arrêtent, une nouvelle invitation part par e-mail).
- **Restriction réseau** : `ADMIN_ALLOWED_IPS` (IP/CIDR) limite l'accès à `/admin` ; un admin inactif 15 min est
  déconnecté.
- **Journal d'audit** (`audit_logs`) : connexions, échecs, blocages, vérifications, changements de mot de passe/2FA,
  suppression et restauration de compte, création et modification d'admins, décisions sur les fournisseurs, exports,
  commandes. Il contient l'IP et le navigateur (épurés pour un client supprimé).

## 7. Exports CSV

Menu « Exports » de l'espace admin (droit `users.export` pour les clients, `suppliers.export` pour les fournisseurs,
`admins.export` pour les administrateurs, `finance.view` pour les commandes).
`POST /admin/export/{clients|fournisseurs|admins|commandes}` → `gobuy_<type>_<AAAA-MM-JJ>.csv`.

Format : séparateur `;`, UTF-8 avec BOM (Excel affiche les accents), dates à l'heure de Madagascar, téléphones en
chiffres lisibles. Colonnes :

- **clients** : ID, Nom complet, E-mail, E-mail vérifié, Téléphone, Téléphone vérifié, Double authentification, Langue,
  Statut, Inscrit le, Dernière connexion
- **fournisseurs** : ID, Raison sociale, Gérant, E-mail, E-mail vérifié, Téléphone, Téléphone vérifié, Adresse, Statut
  d'approbation, Dossier déposé le, Examiné le, Examiné par, Motif, Ventes cumulées (Ar)
- **admins** : ID, Nom complet, E-mail, Profil, Droits effectifs, Droits ajoutés, Droits retirés, Double
  authentification, Statut, Mot de passe défini, Créé par, Créé le, Dernière connexion
- **commandes** : N° commande, Date, Client, E-mail client, Téléphone client, Articles, Sous-total, Livraison, Total,
  Moyen de paiement, Échéances, Opérateur et numéro Mobile Money, Statut du paiement, Statut de la commande, Ville

Chaque export est tracé dans le journal d'audit (qui, quoi, combien de lignes, depuis quelle IP) **avant** le
téléchargement.

## 8. E-mails et SMS

Langue du compte (ou du navigateur pour un visiteur). E-mails : codes de vérification, invitation admin, confirmation
de commande, alertes de sécurité (mot de passe, 2FA, téléphone ou **adresse e-mail** modifiés — ce dernier part vers l'ancienne
adresse), codes de changement de coordonnées, avis « votre adresse a été saisie sur un autre compte », suppression,
restauration et purge de compte, décision sur un dossier fournisseur, avis à une personne dont l'adresse a été utilisée pour une nouvelle
inscription, messages du formulaire de support (reçus par l'équipe). SMS : codes uniquement. Les codes de vérification n'apparaissent jamais dans les logs
hors `dev`/`test`.

## 9. Langues

Français (défaut), anglais, espagnol. Priorité : choix explicite (session puis cookie d'un an) > `Accept-Language` >
français. Le sélecteur du pied de page appelle `/langue/{fr|en|es}`. Fichiers `translations/<domaine>.<langue>.yaml`.
Les clés manquantes sont détectées par les tests (aucune clé brute ne doit s'afficher).

## 10. Tâches planifiées

| Commande | Fréquence | Rôle |
|---|---|---|
| `app:accounts:purge [--dry-run] [--limit=200]` | chaque nuit | Efface les comptes clients échus ; sans danger si lancée deux fois |
| `app:orders:send-confirmations` | toutes les 10 min | Renvoie les e-mails de confirmation restés en attente |

## 11. Limites connues et choix assumés

- **Blocage de connexion par e-mail (5 échecs / 15 min).** Il protège contre le force-brute distribué, mais n'importe
  qui connaissant une adresse peut la bloquer 15 minutes (y compris le Super Admin). Atténuation : `ADMIN_ALLOWED_IPS`
  pour les administrateurs, durée courte, alerte dans le journal (`auth.login.blocked`). Une évolution possible est un
  délai progressif par couple (e-mail, IP).
- **Derrière un reverse-proxy ou un CDN**, renseignez `TRUSTED_PROXIES` : sinon toutes les limites « par IP » partagent
  l'adresse du proxy (40 vérifications de code bloqueraient alors tout le site 10 minutes).
- **CSP partielle** : sans `script-src` (le front-end utilise des scripts en ligne). Une politique complète avec `nonce`
  est une évolution prévue ; les directives actuelles (`frame-ancestors`, `base-uri`, `object-src`, `form-action`) ne
  cassent rien.
- **Pas de TOTP / WebAuthn** pour les administrateurs : la 2FA est un code par e-mail ou SMS. Recommandé pour la suite.
- **Valeurs par défaut de `.env`** (`APP_ENV=dev`, secret d'exemple, identifiants PostgreSQL `gobuy:gobuy`,
  `APP_OTP_LOG_CODES=1`) sont celles du développement : en production, tout se définit dans `.env.local` ou les secrets
  Symfony (`APP_ENV=prod`).
- **Photos de profil.** Elles nécessitent l'extension PHP **GD** (déclarée dans `composer.json`) ; `exif` est facultative (sans
  elle, le sens de prise de vue n'est pas corrigé). PHP limite l'envoi à `upload_max_filesize` (2 Mo par défaut) : le navigateur
  réduit la photo avant l'envoi, donc cela suffit ; relevez la valeur si vous voulez accepter des fichiers plus gros. Les images
  sont dans PostgreSQL (simple, sauvegardées et supprimées avec le compte) ; avec beaucoup de comptes, un stockage objet derrière
  un CDN serait préférable.
- **Journal d'audit et ancien e-mail.** À la purge d'un compte, toutes les lignes portant son identifiant sont épurées (même
  celles qui portent l'ancienne adresse). Seules des lignes **anonymes** (tentative de connexion échouée sur l'ancienne adresse
  avant le changement) peuvent garder cette ancienne adresse, car elles ne sont retrouvées que par l'adresse actuelle.
- **Changement d'e-mail.** L'avis part vers l'ancienne adresse sans lien d'annulation : si ce n'est pas le titulaire qui a agi,
  il passe par « Mot de passe oublié » (le compte est alors protégé par le nouveau mot de passe) ou par le support.
- **Catalogue et paiement** : voir la section « À savoir avant la mise en ligne » du README (catalogue vide, aucun
  prélèvement Mobile Money).
