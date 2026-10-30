<?php

namespace App\Notification;

use App\Auth\Exception\OtpDeliveryFailedException;
use App\Entity\User;
use App\Enum\OtpChannel;
use App\Enum\OtpPurpose;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Implémentation de production de OtpDeliveryInterface :
 *   e-mail → Symfony Mailer (MAILER_DSN)   |   SMS → Symfony Notifier (TEXTER_DSN).
 */
final class MailerSmsOtpDelivery implements OtpDeliveryInterface
{
    public function __construct(
        private readonly TransactionalMailer $mailer,
        private readonly TexterInterface $texter,
        private readonly TranslatorInterface $translator,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        // DEV : écrire les codes dans les logs pour tester sans vrai SMS. Refusé en production (voir plus bas et Kernel).
        #[Autowire('%app.otp.log_codes%')]
        private readonly bool $logCodes,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function deliver(User $user, OtpPurpose $purpose, OtpChannel $channel, string $target, string $code, \DateTimeImmutable $expiresAt): void
    {
        // Double sécurité : même si APP_OTP_LOG_CODES=1 (valeur par défaut de .env) était oublié sur un serveur « staging »,
        // « preprod »… les codes n'y sont jamais journalisés : seuls les environnements « dev » et « test » sont autorisés.
        if ($this->logCodes && \in_array($this->environment, ['dev', 'test'], true)) {
            $this->logger->warning('[OTP-DEV] {purpose} → {target} : {code}', ['purpose' => $purpose->value, 'target' => $target, 'code' => $code]);
        }

        // Durée de validité exprimée en minutes pour les messages (arrondi supérieur).
        $minutes = (int) ceil(($expiresAt->getTimestamp() - $this->clock->now()->getTimestamp()) / 60);

        try {
            if (OtpChannel::Email === $channel) {
                $this->sendEmail($user, $purpose, $target, $code, $minutes);
            } else {
                $this->sendSms($user, $purpose, $target, $code, $minutes);
            }
        } catch (\Throwable $e) {
            // Aucun détail technique vers l'utilisateur ; le détail est déjà dans les journaux.
            $this->logger->error('Échec d\'envoi OTP ({purpose}, {channel}) : {error}', ['purpose' => $purpose->value, 'channel' => $channel->value, 'error' => $e->getMessage()]);

            throw new OtpDeliveryFailedException('Échec d\'envoi du code.', 0, $e);
        }
    }

    private function sendEmail(User $user, OtpPurpose $purpose, string $target, string $code, int $minutes): void
    {
        // L'invitation d'administrateur n'est pas un code à recopier mais un LIEN à ouvrir.
        if (OtpPurpose::AdminInvitation === $purpose) {
            $this->mailer->send($target, $user->getFullName(), $user->getLocale(), 'admin_invitation.subject', [], 'admin_invitation', [
                // URL absolue : le lien doit fonctionner depuis la boîte mail. L'identifiant utilisateur n'est pas secret,
                // le jeton (256 bits) l'est.
                'link' => $this->urls->generate('admin_invitation', ['userId' => $user->getId()->toRfc4122(), 'token' => $code], UrlGeneratorInterface::ABSOLUTE_URL),
                'days' => (int) round($minutes / 1440),
            ]);

            return;
        }

        $this->mailer->send($target, $user->getFullName(), $user->getLocale(), 'otp.'.$purpose->value.'.subject', [], 'otp', [
            'purpose' => $purpose->value,
            'code' => $code,
            'minutes' => $minutes,
        ]);
    }

    private function sendSms(User $user, OtpPurpose $purpose, string $target, string $code, int $minutes): void
    {
        // Le SMS est rédigé dans la langue du destinataire.
        $text = $this->localeSwitcher->runWithLocale($user->getLocale(), fn (): string => $this->translator->trans(
            'sms.otp',
            ['%code%' => $code, '%minutes%' => $minutes],
            'emails',
        ));

        // $target est le numéro E.164 (ex. +261341234567) : format exigé par les passerelles SMS.
        $this->texter->send(new SmsMessage($target, $text));
    }
}
