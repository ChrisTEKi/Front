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
devient l'**adresse par défaut** du nouveau compte.

*Unicité à l'inscription (clients et fournisseurs).* Une adresse e-mail et un numéro de téléphone ne servent qu'à **un seul
compte** : si l'un des deux (ou les deux) appartient déjà à un autre compte, l'inscription est **refusée** (HTTP 409) avec le
message sous le champ concerné (« Cette adresse e-mail est déjà utilisée… », « Ce numéro de téléphone est déjà utilisé… »).
Sont comptés : les comptes actifs, ceux en cours de suppression, et les numéros **vérifiés ou non** ; l'e-mail est comparé sans
tenir compte de la casse ni des espaces. Aucun compte, aucun code, aucun parcours n'est créé en cas de refus. Le titulaire de
l'adresse saisie reçoit un avis « quelqu'un a tenté de s'inscrire avec votre adresse » (3 par jour au plus). Le contrôle se
fait sous verrou PostgreSQL (e-mail puis téléphone) dans la transaction de création : deux inscriptions simultanées ne
peuvent pas créer de doublon. Le même contrôle protège la modification du numéro depuis le profil (`phone_taken`).

**Fournisseur.** Assistant « Devenir partenaire » : e-mail **et** téléphone vérifiés, dossier détaillé enregistré
(`supplier_profiles.application`, JSONB). Statut `pending` jusqu'à décision d'un administrateur
(`approved` / `rejected` avec motif / `suspended`). Un fournisseur non approuvé ouvre son espace (il voit son statut et
le motif) mais **aucun chiffre de vente**. Il n'y a **ni formule « Pro », ni loyer, ni programme de solidarité** : un seul
taux de commission par rayon, pour tous (§ 6octies).

*Pièces justificatives (§ 6octies).* L'inscription fournisseur (assistant `/devenir-partenaire` **et** formulaire rapide de `/connexion`)
demande des **fichiers** : une **pièce d'identité valide** (obligatoire) et le **NIF / STAT** (obligatoire pour une société, facultatif pour
un particulier). La requête est alors `multipart/form-data` : un champ `payload` (le JSON habituel) + `identityDocument` et `taxDocument`.

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

*Anti-énumération (changement de contact uniquement).* Si la nouvelle adresse appartient déjà à un autre compte, l'écran est **identique** : aucun code n'est
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
| `POST /auth/register/client`, `/auth/register/supplier` | Inscriptions (fournisseur : `multipart/form-data` avec `payload` + `identityDocument` + `taxDocument`, § 6octies) |
| `POST /auth/verify`, `/auth/resend`, `/auth/skip` | Saisie / renvoi / passage de l'étape SMS |
| `POST /auth/restore`, `/auth/cancel` | Restaurer un compte en cours de suppression / abandonner |
| `POST /auth/forgot`, `/auth/reset` | Mot de passe oublié |
| `GET /auth/social/{google\|apple}` (+ `/callback`, `/finish`) | Connexion rapide |
| `POST /compte/api/{mot-de-passe,double-authentification,telephone,telephone/verifier,confirmation/envoyer,suppression}` | Compte (`telephone` : ajout / correction d'un numéro **non vérifié** seulement) |
| `POST /compte/api/contact/{demarrer,confirmer,renvoyer,annuler}` | Changement d'e-mail ou de téléphone avec double confirmation (§ 2) |
| `POST /compte/api/photo`, `/compte/api/photo/supprimer` · `GET /profil/photo` | Photo de profil (multipart, champ `photo`) |
| `GET /compte/api/adresses` · `POST /compte/api/adresses`, `/{id}/{modifier,defaut,supprimer}` | Carnet d'adresses (clients) ; l'identifiant d'une adresse d'un autre compte donne 404 |
| `GET /fournisseur/produits` · `POST /fournisseur/api/produits`, `/{id}/{modifier,visibilite,supprimer}` | Catalogue du fournisseur (§ 6bis) ; le produit d'un autre vendeur donne 404 |
| `GET /admin/produits` · `POST /admin/api/produits`, `/{id}/{modifier,retrait,signalement,mise-en-avant,supprimer}`, `/admin/api/fournisseurs/{id}/catalogue` | Modération du catalogue (§ 6bis) |
| `POST /commande/valider`, `/paiement/telephone/{envoyer,verifier}`, `/paiement/mobile-money/{envoyer,verifier}` | Commande et paiement (la commande accepte `currency` et `quotedTotal`, voir § 6sexies) |
| `GET /devise/{code}` · `GET /panier/prix?refs=` | Choix de la devise d'affichage (cookie) · prix à jour des articles du panier dans toutes les devises (§ 6sexies) |
| `GET /admin/devises` · `POST /admin/api/devises/{code}/{manuel,automatique}`, `/admin/api/devises/actualiser` | Taux de change (§ 6sexies) |
| `GET /admin/commissions` · `POST /admin/api/commissions` | Taux de commission par rayon (§ 6octies) |
| `GET /admin/fournisseurs/{id}/pieces/{identity\|tax}` | Télécharger une pièce d'un fournisseur (droit `suppliers.approve`, § 6octies) |
| `POST /support/message` | Contact, réclamation, candidature, avis |
| `POST /admin/api/…` | Administration (§ 6) |
| `GET /langue/{fr\|en\|es}` | Changement de langue |

## 4. Base PostgreSQL

Migrations Doctrine commentées dans `migrations/` (colonnes et tables ont un `COMMENT` SQL). Identifiants **UUID v7**.

| Table | Contenu | Points notables |
|---|---|---|
| `users` | Tous les comptes (client, fournisseur, admin) | e-mail unique ; **téléphone vérifié unique** (index partiel) ; `roles` JSONB ; `deletion_scheduled_at` (+ origine, motif, auteur, statut d'avant) ; `banned_at` / `ban_reason` ; `funds_frozen_at` / `funds_frozen_reason` ; `avatar_updated_at` (NULL = pas de photo) |
| `user_avatars` | Photo de profil (JPEG 512 px, `bytea`) | une ligne par compte ; supprimée en cascade avec le compte |
| `one_time_codes` | Codes OTP | **hachés** (HMAC-SHA256 lié à la finalité, au compte et à la destination) ; tentatives, expiration |
| `social_identities` | Google / Apple | unique (fournisseur, identifiant) |
| `addresses` | Carnet d'adresses de livraison | `is_default` (**une seule par client** : index unique partiel), `label`, téléphone facultatif ; supprimées en cascade avec le compte |
| `mobile_money_accounts` | Numéros Mobile Money vérifiés | cascade |
| `products` | Catalogue (fournisseurs et GoBuy : `owner_id` NULL) | prix de base `price_mga` (entier) ; `price_overrides` JSONB (prix spécifiques EUR/USD en unités mineures) ; statut `active` / `hidden` / `removed` ; retrait, signalement et mise en avant (qui, quand, motif) ; supprimés en cascade avec le compte du fournisseur |
| `supplier_profiles` | Dossier fournisseur | `application` JSONB (réponses de l'assistant, sans formule ni pièces), statut, relecteur, motif |
| `supplier_documents` | Pièces d'inscription d'un fournisseur (identité, NIF/STAT) en `bytea` | une pièce par type et par fournisseur ; 2 Mo, PDF / JPG / PNG vérifiés sur le **contenu** ; supprimées en cascade avec le compte |
| `commission_rates` | Taux de commission par rayon (`DECIMAL(5,2)`) | une ligne par rayon modifié (absente = taux d'origine du code) ; qui et quand |
| `admin_roles`, `admin_profiles` | Profils de droits et administrateurs | droits ajoutés/retirés par administrateur ; `ON DELETE RESTRICT` sur un profil encore utilisé |
| `supplier_appeals`, `supplier_appeal_documents` | Contestations d'un refus de dossier et pièces jointes (`bytea`) | 3 contestations au plus par décision ; pièces supprimées en cascade avec le compte |
| `exchange_rates` | Historique des taux de change (Ariary pour 1 € / 1 $) | une ligne par changement ; source `auto` ou `manual` (+ échéance, note, auteur) ; **jamais de taux inventé** (§ 6sexies) |
| `blacklist_entries` | Liste noire (e-mail, téléphone, nom, raison sociale, IP) | valeur **normalisée** ; expiration facultative (obligatoire pour une IP) ; quel compte et pourquoi |
| `orders`, `order_items`, `order_tracking_events` | Commandes | coordonnées figées dans la commande ; anonymisées à la purge ; `confirmation_email_sent_at` ; `refunded_amount` / `refunded_at` (remboursement **enregistré**, voir § 6quater) ; `currency`, `exchange_rate` et montants **facturés** (`subtotal_charged`, `delivery_fee_charged`, `total_charged`, `unit_price_charged` par ligne) : le règlement reste en Ariary (§ 6sexies) |
| `audit_logs` | Journal d'audit | sans clé étrangère (survit aux suppressions), détails JSONB sans secret |

Le profil système « Super Admin » et six profils de départ (**Finance, Comptabilité, Support, Modération, PDG, DG**) sont
créés par les migrations : ce sont des réglages, pas des données de démonstration.

## 5. Sécurité : ce qui est en place

| Sujet | Mesure |
|---|---|
| Mots de passe | Argon2id (64 Mio, 4 itérations) ; clients ≥ 10 caractères, admins ≥ 14 avec complexité ; refus des mots de passe contenant l'e-mail ou présents dans des fuites connues (API Have I Been Pwned en k-anonymité ; si elle est injoignable, la vérification est ignorée) |
| Connexion | 20 essais / 10 min par IP **et** 5 échecs / 15 min par e-mail ; temps de réponse identique que le compte existe ou non (hachage factice) ; même message d'erreur |
| Inscription | 10 / h par IP ; une adresse ou un numéro **déjà utilisé** est refusé explicitement (409, message sur le champ) : le formulaire révèle donc l'existence d'un compte, d'où le quota par IP et la limite de 3 avis / jour au titulaire de l'adresse sondée ; la connexion et « mot de passe oublié », eux, ne révèlent rien |
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
  (Finance, Comptabilité, Support, Modération, **PDG**, **DG** ou un profil qu'il crée) puis peut **ajouter** ou **retirer** des droits
  individuellement. L'administrateur reçoit un **lien d'invitation** (256 bits, valable 48 h, à usage unique) pour
  choisir son mot de passe.
- **Droits** (`Permission`) : `users.view/suspend/export`, `suppliers.view/approve/export`, `orders.view/update_tracking`,
  `products.view/manage/flag/feature/remove/delete`, `sanctions.ban/freeze/refund`, `blacklist.manage`, `accounts.delete`,
  `finance.view/manage`, `currency.manage`, `commissions.manage`, `audit.view`, `admins.view/manage/export`. Les trois droits `admins.*` ne sont attribuables à
  personne d'autre que le Super Admin. Contrôle par `#[IsGranted]` sur chaque action.
- **Droits délégués (PDG, DG).** Le Super Admin peut donner de larges pouvoirs à d'autres administrateurs en leur attribuant un
  profil : **PDG** = tous les droits attribuables (y compris suppression définitive d'un compte et d'un produit) ; **DG** = les
  mêmes, **sauf** `accounts.delete` et `products.delete`. Ce sont des profils modifiables (le Super Admin peut en retirer des droits
  ou en créer d'autres, ou ajuster un administrateur précis). **Créer, modifier, suspendre ou exporter des administrateurs reste
  réservé au Super Admin**, quel que soit le profil. Quand un droit est ajouté à l'application, la migration l'ajoute aussi à PDG
  (et à DG s'il ne s'agit pas d'une suppression définitive). C'est le cas de `currency.manage` (taux de change).
- **Suspension de plusieurs administrateurs** (Super Admin seul) : cases à cocher dans l'onglet Administrateurs puis
  « Suspendre / Réactiver la sélection » (`POST /admin/api/administrateurs/statut-groupe`, 50 comptes au plus, mot de passe
  demandé **une seule fois**). Chaque compte suit les règles d'une suspension unitaire (sessions coupées immédiatement, une ligne
  d'audit par administrateur + une ligne récapitulative). Sont **ignorés, avec leur raison** : un compte introuvable, le Super
  Admin, une invitation non acceptée, un compte déjà dans l'état demandé : ils n'empêchent pas les autres.
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

## 6bis. Catalogue de produits et modération

**Côté fournisseur** (`/fournisseur/produits`) : créer, modifier, masquer/réafficher et supprimer SES produits (2000 au
maximum). Un fournisseur dont le dossier est en attente ou approuvé peut préparer son catalogue ; refusé ou suspendu, il ne
peut plus rien modifier. Les produits ne sont **vendables qu'après approbation** du dossier.

**Prix.** Le prix de base est en **Ariary** (entier). Le vendeur peut fixer un prix **spécifique** en euro et/ou en dollar ;
une devise laissée vide sera convertie au taux du jour (étape « devises », à venir). Les montants sont lus sans ambiguïté
(`12,50`, `12.5`, `12 500`) et refusés s'ils ont trop de décimales ou dépassent le maximum.

**Qu'est-ce qui est vendable ?** Une seule règle (`ProductRepository::sellableQuery`, reprise par `DoctrineCatalog`, donc par
la boutique ET par la commande) : produit **actif** et (vendu par GoBuy **ou** par un fournisseur au dossier **approuvé** dont
le compte est **actif**). Un fournisseur suspendu, refusé, banni ou en cours de suppression disparaît donc de la boutique
sans qu'on touche à ses produits. Le prix et le nom d'une commande sont toujours relus côté serveur.

**Côté administrateur** (`/admin/produits`, droit `products.view` ; Super Admin : tout) :

| Action | Droit | Détails |
|---|---|---|
| Voir tous les produits (fournisseurs et GoBuy), filtrer, chercher (produit, référence, vendeur, société) | `products.view` | filtres propriétaire / statut / signalés / mis en avant |
| Créer et modifier les produits **de GoBuy** | `products.manage` | jamais ceux d'un fournisseur (réécrire le produit d'autrui est refusé) |
| Retirer de la vente / rétablir | `products.remove` | motif obligatoire (5 caractères) ; le fournisseur est prévenu par e-mail et **ne peut plus toucher** au produit |
| Signaler / retirer le signalement | `products.flag` | simple marque « à examiner », sans effet sur la vente |
| Mettre en avant / retirer | `products.feature` | durée facultative (1 à 365 jours), expire seule ; produits en vente uniquement ; passent en premier en boutique |
| Supprimer définitivement | `products.delete` | **mot de passe redemandé** ; le fournisseur est prévenu |
| Retirer ou supprimer **tout le catalogue** d'un fournisseur | `products.remove` / `products.delete` | mot de passe redemandé ; motif obligatoire pour le retrait ; un seul e-mail récapitulatif |

Chaque action est inscrite au journal d'audit (`product.*`, `catalog.*`). Les commandes déjà passées gardent leur propre copie
du nom et du prix. Ces droits peuvent être donnés à n'importe quel administrateur (profil ou droits individuels).

## 6ter. Contestation d'un refus et nouvelle validation

- **Refuser, puis revalider.** Un administrateur ayant `suppliers.approve` peut refuser un dossier (motif obligatoire, e-mail au
  fournisseur) **et le revalider ensuite** : le refus n'est donc plus définitif. Chaque décision reste dans le journal.
- **Contestation par le fournisseur** (`/fournisseur/contestation`) : un fournisseur dont le dossier est **refusé** ou **suspendu** écrit à l'équipe (20 à 4000
  caractères) et joint des **compléments de dossier** : 5 fichiers au plus, 2 Mo chacun, 6 Mo au total, **PDF, JPEG ou PNG**
  (le type réel est relu côté serveur, pas seulement l'extension). **une seule contestation ouverte à la fois**, 3 au plus par décision de l'équipe. Les
  administrateurs reçoivent un e-mail, lisent le message et les pièces depuis la fiche du compte, puis **revalident** ou
  **maintiennent le refus** avec un motif (le fournisseur est prévenu dans les deux cas).
- Un compte qui n'est plus **actif** (suspendu, banni, en cours de suppression) ne peut pas contester.
- **Suspendre un fournisseur approuvé** (`suppliers.approve`, motif obligatoire) retire sa boutique de la vente, **prévient le fournisseur par e-mail** (avec le motif et le rappel qu'il peut contester) et s'inscrit au journal sous l'action propre `admin.supplier.suspended` (et non plus comme un refus).

## 6quater. Sanctions sur les clients et fournisseurs

Fiche d'un compte : `/admin/comptes/{id}`. Les sanctions **ne s'appliquent jamais à un administrateur** (voir § 6 pour les
suspendre). Chacune demande son droit, un **motif de 5 caractères au moins** et le **mot de passe** de l'administrateur.

| Sanction | Droit | Effet |
|---|---|---|
| **Bannissement définitif** (clôture immédiate) | `sanctions.ban` | connexion refusée, sessions coupées, compte et pièces **conservés** comme preuve ; l'e-mail et le téléphone restent occupés (pas de réinscription). **Levée réservée au Super Admin.** Les options sont **toutes contrôlées avant** de prononcer le bannissement (durée de liste noire hors limites = refus `invalid_days`, rien n'est banni). Un bannissement **remplace** une suppression en attente : la ligne d'audit porte `deletion_cancelled: true` |
| …avec **retrait / suppression du catalogue** | `products.remove` / `products.delete` | option du bannissement, fournisseur seulement |
| …avec **remboursement des acheteurs** | `sanctions.refund` | option : commandes **en cours** ou **en cours + livrées** pour un fournisseur ; pour un **client** banni, ses commandes en cours sont annulées et remboursées ; un seul e-mail par acheteur |
| …avec **liste noire** | `blacklist.manage` | option : e-mail, téléphone, nom, raison sociale, IP |
| **Gel conservatoire des fonds et des paiements** | `sanctions.freeze` | le fournisseur ne peut plus encaisser ni être payé (`PayoutGuard`), ses produits ne se commandent plus ; réversible |
| **Remboursement** seul (fournisseur) | `sanctions.refund` | rembourse les acheteurs lésés d'un fournisseur (commandes en cours, ou en cours + livrées), par lots de 500 commandes : relancez l'action s'il en reste |

**Remboursement : il est ENREGISTRÉ, pas viré.** Il n'y a aucune passerelle de paiement dans le projet : l'application note le
montant remboursé sur chaque commande (`refunded_amount`), marque les lignes concernées, annule la commande et passe le paiement à
« remboursé » s'il avait été encaissé, puis **prévient l'acheteur par e-mail**. Le virement réel reste à faire par l'équipe
financière (liste dans la fiche du compte et dans l'e-mail). Branchez votre prestataire dans `RefundService` pour automatiser.

**Liste noire** (`/admin/liste-noire`). Les valeurs sont **normalisées** avant comparaison : e-mail en minuscules, sans `+étiquette`,
points Gmail ignorés ; téléphone au format E.164 ; nom sans accents, mots triés (au moins deux mots) ; raison sociale sans accents ni
ponctuation (3 caractères au moins) ; IP en forme canonique. Une entrée peut avoir une **échéance** ; pour une **IP**, elle est
**obligatoire** (365 jours au plus, car une IP change de titulaire). Elle bloque l'**inscription** (client, fournisseur, et création de compte
Google/Apple) avec un message neutre ; elle n'affecte pas les comptes déjà existants (pour eux, c'est le bannissement qui ferme l'accès).

- **Un nouvel ajout ne raccourcit jamais une interdiction** : ajouter à nouveau une valeur déjà listée met à jour le motif et garde l'**échéance la plus lointaine** (« définitive » l'emporte et le reste). Pour réduire une durée, supprimez l'entrée puis recréez-la. Durée maximale : 3 650 jours (365 pour une IP).
- **Compte banni puis supprimé** (suppression immédiate ou à l'échéance du délai) : son **e-mail et son téléphone sont inscrits d'office sur la liste noire, définitivement**, juste avant l'effacement (sinon la suppression libérerait ces identifiants et la personne se réinscrirait). Un compte supprimé **sans** bannissement libère ses identifiants comme avant.

## 6quinquies. Suppression définitive d'un compte, avec délai

Droit `accounts.delete` (PDG ou Super Admin ; **pas** le DG). Délais proposés : **immédiat, 24 h, 48 h, 72 h, 7 j, 14 j, 30 j,
3 mois, 6 mois, 1 an** (`POST /admin/api/comptes/{id}/suppression`, motif + mot de passe). Pendant le délai, le compte est
**fermé** (connexion refusée avec un message dédié) ; l'administrateur peut **annuler** avant l'échéance, ce qui rétablit le statut
d'origine. À l'échéance, `app:accounts:purge` efface le compte (comme pour une demande du client : commandes anonymisées). Tant qu'il reste des
**commandes en cours** (client ou fournisseur), la purge est **repoussée** au passage suivant : le compte reste fermé. Un fournisseur ne peut **jamais**
être purgé par la tâche planifiée sans décision de l'équipe : seul le délai choisi par un administrateur le fait.

## 6sexies. Devises et taux de change (Ariary, euro, dollar)

- **Devise de référence : l'Ariary.** Les prix de base sont en Ariary (`products.price_mga`). Un fournisseur, GoBuy ou un administrateur peut en
  plus fixer un **prix propre à l'euro ou au dollar** (`products.price_overrides`, en centimes) ; sans prix propre, le prix est
  **converti** au taux du jour. Le client choisit sa devise d'affichage dans le pied de page (`GET /devise/{code}`, cookie `gobuy_currency`
  d'un an, `HttpOnly`, `SameSite=Lax` ; retour à la page d'origine **seulement si elle appartient au site**). Une devise sans taux n'est **pas proposée**.
- **Taux automatiques** : `php bin/console app:rates:refresh` (cron toutes les 6 h) interroge `EXCHANGE_RATE_API_URL`
  (par défaut open.er-api.com, gratuit, sans clé, mis à jour une fois par jour). **Aucun taux n'est inventé** : tant qu'une devise n'a pas de taux
  (récupéré ou saisi), elle reste masquée. Un taux qui s'écarte de **plus de 30 %** du précédent est **refusé** (action d'audit
  `rate.rejected`, l'ancien taux reste en vigueur, code de sortie 1). Un taux inchangé n'est réenregistré qu'une fois par jour (preuve que le service répond).
- **Taux manuel** (`/admin/devises`, droit `currency.manage`, mot de passe redemandé, motif facultatif) : bloque les mises à jour automatiques de cette devise, pour une
  durée choisie ou jusqu'à ce qu'un administrateur **reprenne l'automatique**. Le bouton « Actualiser » lance une mise à jour immédiate.
- **Règlement en Ariary.** Le client voit et choisit sa devise, mais la commande reste **réglée en Ariary** : elle enregistre la devise, le taux **figé** au moment de la
  commande et les montants facturés dans la devise (`*_charged`). Les arrondis ne descendent jamais à 0 pour un montant positif. Les frais de livraison (10 000 Ar fixes) sont
  convertis au même taux. L'e-mail de confirmation, le suivi, le compte et les exports CSV affichent les deux montants.
- **Prix de la page = prix facturé.** Le navigateur envoie `quotedTotal` (le total affiché) ; si le serveur calcule autre chose (taux ou prix modifié entre-temps),
  la commande est **refusée** avec l'erreur `price_changed` et le panier se met à jour avant un nouvel essai (`GET /panier/prix`, public, sans cache).
- **Limites.** Le service gratuit ne confirme pas l'Ariary de façon contractuelle : surveillez `app:rates:refresh` et saisissez un taux manuel si besoin. L'attribution
  « Taux de change : exchangerate-api.com » est exigée par le service gratuit (déjà affichée sur l'écran des devises) ; l'article de CGV sur la monnaie de paiement doit être
  **revalidé juridiquement** (règlement en Ariary).

## 6septies. Recrutement (offres d'emploi, candidats, entretiens)

- **Pages publiques** (sans compte) : `GET /emplois` (offres ouvertes), `GET /emplois/{id}`, `POST /emplois/{id}/candidater` (multipart, jeton CSRF exigé).
  Une offre n'est visible que si elle est **ouverte** et que sa **date limite** (heure de Madagascar, jour inclus) n'est pas passée ; sinon 404. Les offres sont rédigées dans
  **une seule langue** par l'équipe (leur texte n'est pas traduit) ; tout le reste de l'interface et **tous les e-mails** le sont (langue du visiteur au dépôt).
- **Dépôt** : nom, e-mail, téléphone (format international enregistré), message facultatif (2 000 caractères), **CV obligatoire**, lettre facultative. Fichiers PDF / JPEG / PNG,
  **2 Mo par fichier, 4 Mo au total** ; le **type est lu dans le contenu** (le nom et le type annoncés sont ignorés), le nom d'origine est assaini. Une personne ne postule
  qu'**une fois par offre** (e-mail comparé sans tenir compte des majuscules, sous verrou : deux envois simultanés ne passent pas tous les deux) ; **8 dépôts par heure et par IP**
  (429) ; champ-piège `website` (un robot qui le remplit reçoit un faux succès, rien n'est enregistré). PHP limite chaque envoi à `upload_max_filesize` (2 Mo par défaut :
  suffisant) et `post_max_size` ; un envoi plus gros reçoit un message clair. Le candidat reçoit un **accusé de réception** et l'équipe un avis à `GOBUY_SUPPORT_EMAIL` (sans les pièces).
  Le visiteur ne reçoit **jamais d'identifiant** de candidature et ne peut ni la relire ni la modifier (il écrit au support).
- **Droits** (Super Admin : tous ; profils PDG et DG : ajoutés par la migration `Version20261105000100`) :
  `recruitment.view` (voir offres, candidats, **lire les CV et lettres**, planning), `recruitment.manage` (créer, modifier, publier, clôturer, supprimer une offre),
  `recruitment.decide` (refuser, retenir, fixer ou annuler un entretien, effacer un candidat). Chaque action vérifie son droit côté serveur ; le mot de passe est redemandé pour
  **supprimer** une offre ou **effacer** un candidat. Une offre qui a reçu des candidatures ne peut plus être supprimée, seulement **clôturée**.
- **Pièces** (`GET /admin/recrutement/pieces/{id}`) : servies **en téléchargement** (`Content-Disposition: attachment`, nom reconstruit « cv-nom-du-candidat.pdf »), `nosniff`,
  `Content-Security-Policy: sandbox`, `no-store`. Chaque lecture est inscrite au journal (`admin.job_application.document_viewed`). Les journaux ne contiennent **aucune donnée
  personnelle de candidat** (ni nom, ni e-mail, ni téléphone, ni message).
- **Refus** : e-mail automatique **adressé au nom du candidat** (destinataire « Nom <adresse> », « Bonjour Nom », sujet « Réponse à la candidature de Nom — Poste »), dans la langue de sa
  candidature, avec une phrase personnelle facultative (500 caractères). Les entretiens encore prévus sont annulés. Décision **définitive** (on ne convoque plus un candidat refusé).
  « **Retenu** » est un simple repère interne : **aucun e-mail** n'est envoyé. Si un e-mail ne peut pas partir, l'action **réussit quand même** et la réponse porte un avertissement :
  l'administrateur contacte alors la personne lui-même.
- **Entretiens** : mode **téléphone** (l'équipe appelle le numéro du candidat) ou **sur place** (lieu obligatoire), date, heure et durée (10 à 240 min) choisis par l'administrateur,
  **à l'heure de Madagascar** (UTC+3, sans heure d'été), à venir et dans l'année. Deux entretiens du **même** candidat ne peuvent pas se chevaucher (des candidats différents le peuvent).
  Convocation, modification et annulation **préviennent le candidat par e-mail** ; « effectué » n'envoie rien. Le premier entretien passe la candidature à « entretien ».
- **Planning** (`/admin/recrutement/planning`) : **tous** les candidats ayant un entretien, **classés par date de candidature** (du plus ancien au plus récent par défaut, ordre
  inversable), avec la date et l'heure de chaque entretien ; filtres « à venir / tous » et par offre ; pages de 50.
- **Conservation** : `php bin/console app:recruitment:purge` (cron `30 3 * * *`) efface les candidatures **24 mois après leur dernière activité** (décision, sinon dépôt), avec leurs
  pièces et entretiens (`--dry-run` pour compter). **Cette durée est un choix par défaut à faire valider par votre conseil juridique** et à indiquer dans votre politique de confidentialité
  (information des candidats, droit d'effacement : bouton « Effacer ce candidat »).

## 6octies. Commissions et pièces justificatives des fournisseurs

- **Un seul taux par rayon, pour tous.** Il n'y a plus de formule « Pro » ni de loyer mensuel : le simulateur de `/devenir-partenaire` et l'Espace
  Fournisseur affichent le taux du rayon (`App\Supplier\CommissionSchedule`, seule source de ces chiffres). Les questions de l'assistant sur la formule,
  la casse et les pièces de solidarité ont disparu ; les anciennes réponses ont été **effacées** des dossiers par la migration (irréversible).
- **Taux modifiables** (`/admin/commissions`, droit `commissions.manage`, donné par défaut aux profils **PDG** et **DG** — le Super Admin peut
  l'ajouter à un autre profil) : 0 à **50 %**, 2 décimales au plus (la virgule est acceptée), **mot de passe redemandé**, tout ou rien (un taux
  invalide refuse l'ensemble). Chaque changement s'inscrit au journal (`admin.commission.changed`, ancien → nouveau taux, auteur, jamais le mot de
  passe) et la page affiche les 15 derniers. Une ligne absente en base = taux d'origine (alim. 4, électronique 5, enfants et électroménager 6,
  maison et sport 7, mode et beauté 8). Le barème est **informatif** : le calcul réel des reversements n'est pas branché sur les paiements.
- **Pièces d'inscription.** Pièce d'identité (toujours) et NIF / STAT (société) : 2 Mo au plus, PDF / JPG / PNG reconnus par le **contenu** du
  fichier (l'extension et le type annoncé ne comptent pas), nom du fichier nettoyé. Elles sont stockées en base (`supplier_documents`) dans la même
  transaction que le compte : si l'inscription échoue, rien n'est gardé. Les erreurs (`doc_required`, `doc_too_large`, `doc_type`, `doc_invalid`) reviennent
  par champ, avec les autres erreurs du formulaire (HTTP 422). Les valeurs PHP par défaut suffisent (`upload_max_filesize` 2 Mo par fichier, `post_max_size` 8 Mo par requête) ; un fichier refusé par PHP lui-même est signalé comme trop gros.
- **Lecture des pièces** (`GET /admin/fournisseurs/{id}/pieces/{identity|tax}`) : réservée au droit `suppliers.approve` (voir seulement les
  fournisseurs, `suppliers.view`, ne suffit pas), servie **en téléchargement** (`attachment`, `nosniff`, `no-store`, CSP `sandbox`), et **chaque lecture est journalisée**
  (`admin.supplier.document_viewed`). Ce sont des données personnelles : à ne conserver que le temps nécessaire à la vérification.

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
| `app:accounts:purge [--dry-run] [--limit=200]` | **toutes les heures** (une fois par nuit suffit pour les clients) | Efface les comptes échus (clients ; fournisseurs seulement si un administrateur l'a décidé) ; sans danger si lancée deux fois. Un délai de 24 h choisi par un administrateur n'est respecté qu'à l'heure près si la tâche tourne toutes les heures |
| `app:orders:send-confirmations` | toutes les 10 min | Renvoie les e-mails de confirmation restés en attente |
| `app:recruitment:purge [--dry-run] [--limit=200]` | une fois par nuit (`30 3 * * *`) | Efface les candidatures (CV, lettres, entretiens) 24 mois après leur dernière activité (§ 6septies) ; sans danger si lancée deux fois |
| `app:rates:refresh` | toutes les 6 h (`0 0,6,12,18 * * *`) | Met à jour les taux EUR et USD contre l'Ariary (§ 6sexies) ; code de sortie 1 si le service est injoignable ou si une valeur est refusée : à surveiller |

## 11. Limites connues et choix assumés

- **Numéro non vérifié = numéro pris.** Un numéro saisi à l'inscription bloque les inscriptions suivantes avec ce numéro tant
  que le compte le porte, même sans SMS validé. Quelqu'un pourrait donc « réserver » le numéro d'un tiers. Atténuations :
  quota de 10 inscriptions / h / IP, et le titulaire réel prouve son numéro par SMS (la preuve prime pour le changement de
  numéro depuis le profil). Évolution possible : libérer automatiquement un numéro jamais vérifié après quelques jours.
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
- **Catalogue.** Pas de stock, pas de photos de produit, pas de variantes (taille, couleur) : un produit = un prix. Un produit
  retiré par un administrateur reste visible du fournisseur (avec le motif) mais n'est plus modifiable par lui. La mise en
  avant expire à sa date, sans tâche planifiée (la date est comparée à chaque affichage).
- **Paiement** : voir la section « À savoir avant la mise en ligne » du README (aucun prélèvement Mobile Money).
- **Remboursements.** Ils sont enregistrés et notifiés, **pas virés** (pas de passerelle de paiement) : voir § 6quater. Les
  montants remboursés sont ceux de la commande figée : le total n'est rendu en entier (frais de livraison compris) que si **toutes**
  les lignes de la commande sont remboursées ; sinon seul le montant des lignes concernées l'est.
- **Liste noire par nom.** Deux personnes différentes peuvent porter le même nom : une entrée « nom » peut bloquer un homonyme
  innocent (le message reste neutre et le support peut retirer l'entrée). Préférez l'e-mail, le téléphone ou la raison sociale quand vous
  les connaissez. Une IP bloquée peut être partagée (famille, entreprise, opérateur mobile) : d'où l'échéance obligatoire.
- **Pièces d'une contestation.** PHP limite l'envoi : `upload_max_filesize` ≥ 2 Mo et `post_max_size` ≥ 6 Mo (valeurs de l'application) ;
  en dessous, l'envoi est refusé avant d'atteindre l'application. Les pièces sont dans PostgreSQL et supprimées avec le compte.
- **Fournisseur effacé et commandes passées.** Les lignes de commande gardent le prix et le nom du produit mais **perdent le lien vers le fournisseur** (et son nom) quand son compte est purgé. Si vous devez retrouver « qui vendait quoi » après une purge, ajoutez un nom de fournisseur figé dans la ligne de commande (non fait).
- **Remboursements simultanés.** `RefundService` ne verrouille pas les lignes : deux administrateurs qui lancent exactement le même remboursement au même instant pourraient produire deux lignes d'audit et deux e-mails (le montant enregistré reste plafonné au total de la commande et les lignes déjà remboursées sont ignorées).
- **Formulaires de l'administration (JavaScript).** Un formulaire admin porte `data-admin-form` (géré par `assets/admin-account.js`) et jamais `data-account-form` (réservé au compte client, `assets/account.js`) : deux scripts sur le même attribut videraient le mot de passe avant l'envoi et l'action « ne ferait rien ». `tests/Ui/AdminFormsTest.php` le vérifie.
- **Un seul Super Admin.** Les pouvoirs étendus se délèguent par profil (PDG, DG), jamais en créant un second Super Admin ;
  l'accès aux administrateurs (création, suspension, export) reste au Super Admin.
