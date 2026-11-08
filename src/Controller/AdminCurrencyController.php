<?php

namespace App\Controller;

use App\Admin\AdminActionException;
use App\Admin\StepUpService;
use App\Currency\ExchangeRates;
use App\Currency\ExchangeRateUnavailableException;
use App\Currency\RateRefreshReport;
use App\Entity\ExchangeRate;
use App\Entity\User;
use App\Enum\Currency;
use App\Http\ModerationResponder;
use App\Http\ReadsJsonPayload;
use App\Repository\ExchangeRateRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TAUX DE CHANGE (écran « Devises » de l'administration).
 *
 * Accès : ROLE_ADMIN (pare-feu), réseau autorisé et session active (AdminGuardSubscriber), puis le droit « currency.manage ».
 *
 *   GET  /admin/devises                          taux en vigueur, origine, ancienneté, historique
 *   POST /admin/api/devises/{code}/manuel        {rate, days, note, password}   fixe un taux à la main (bloque l'automatique)
 *   POST /admin/api/devises/{code}/automatique   {password}                     reprend le mode automatique (va chercher le taux)
 *   POST /admin/api/devises/actualiser           {}                              lance la mise à jour automatique tout de suite
 *
 * Les deux premières actions changent les prix affichés et facturés : elles redemandent le mot de passe de l'administrateur.
 * « Actualiser » ne fait que lancer la tâche planifiée (taux du service, avec son garde-fou) : pas de mot de passe.
 */
final class AdminCurrencyController extends AbstractController
{
    use ReadsJsonPayload;

    /** Au-delà de cet âge, un taux automatique est signalé « ancien » à l'administrateur (le service gratuit change une fois par jour). */
    private const STALE_AFTER = '-3 days';

    public function __construct(
        private readonly ExchangeRates $rates,
        private readonly ExchangeRateRepository $history,
        private readonly ModerationResponder $responder,
        private readonly StepUpService $stepUp,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/admin/devises', name: 'app_admin_currencies', methods: ['GET'])]
    #[IsGranted('currency.manage')]
    public function page(): Response
    {
        $now = $this->clock->now();
        $rows = [];
        foreach (Currency::cases() as $currency) {
            if (Currency::BASE === $currency) {
                continue;
            }
            $current = $this->rates->current($currency);
            $rows[] = [
                'code' => $currency->value,
                'symbol' => $currency->symbol(),
                'current' => $current,
                'locked' => null !== $current && $current->locksAutomaticUpdates($now),
                'stale' => null !== $current && !$current->isManual() && $current->getCreatedAt() < $now->modify(self::STALE_AFTER),
                'history' => $this->history->history($currency, 15),
            ];
        }

        $response = $this->render('admin/currencies.html.twig', ['rows' => $rows]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/admin/api/devises/{code}/manuel', name: 'admin_api_currency_manual', requirements: ['code' => '[A-Za-z]{3}'], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('currency.manage')]
    public function manual(string $code, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($code, $request): JsonResponse {
            $admin = $this->admin();
            $data = $this->payload($request);
            $this->stepUp->require($admin, $this->text($data, 'password'));

            $row = $this->rates->setManual($admin, $this->currency($code), $this->text($data, 'rate'), $this->days($data['days'] ?? null), $this->text($data, 'note'));

            return $this->responder->ok(['rate' => $row->getRate()]);
        });
    }

    #[Route('/admin/api/devises/{code}/automatique', name: 'admin_api_currency_auto', requirements: ['code' => '[A-Za-z]{3}'], methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('currency.manage')]
    public function automatic(string $code, Request $request): JsonResponse
    {
        return $this->responder->run(function () use ($code, $request): JsonResponse {
            $admin = $this->admin();
            $this->stepUp->require($admin, $this->text($this->payload($request), 'password'));

            $row = $this->rates->resumeAutomatic($admin, $this->currency($code));

            return $this->responder->ok(['rate' => $row->getRate()]);
        });
    }

    #[Route('/admin/api/devises/actualiser', name: 'admin_api_currency_refresh', methods: ['POST'], defaults: ['_csrf_token_id' => 'auth'])]
    #[IsGranted('currency.manage')]
    public function refresh(): JsonResponse
    {
        return $this->responder->run(function (): JsonResponse {
            try {
                $report = $this->rates->refresh();
            } catch (ExchangeRateUnavailableException) {
                throw new AdminActionException('rate_provider_down');
            }

            return $this->responder->ok([
                'updated' => $report->updated(),
                'problems' => array_keys(array_filter($report->rows, static fn (array $r): bool => \in_array($r['status'], [RateRefreshReport::SUSPICIOUS, RateRefreshReport::MISSING], true))),
                'locked' => array_keys(array_filter($report->rows, static fn (array $r): bool => RateRefreshReport::LOCKED === $r['status'])),
            ]);
        });
    }

    // ------------------------------------------------------------------ Outils

    /** Devise étrangère visée ; l'Ariary (base) et les codes inconnus sont refusés. */
    private function currency(string $code): Currency
    {
        $currency = Currency::tryFrom(strtoupper($code));
        if (null === $currency || Currency::BASE === $currency) {
            throw new AdminActionException('invalid_currency');
        }

        return $currency;
    }

    private function days(mixed $raw): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!\is_scalar($raw) || !preg_match('/^\d{1,4}$/', (string) $raw)) {
            throw new AdminActionException('invalid_days');
        }

        return (int) $raw;
    }

    private function admin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isAdmin()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
