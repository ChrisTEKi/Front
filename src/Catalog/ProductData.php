<?php

namespace App\Catalog;

use App\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Données d'un produit saisies par un fournisseur (ou un administrateur pour GoBuy), avec leurs règles de validation.
 * Les messages sont des CLÉS de traduction (domaine « validators »).
 *
 * Les prix arrivent en TEXTE, en unités « humaines » (« 12500 » Ariary, « 12,50 » euros) : on les convertit ici en entiers
 * (Ariary / centimes) pour ne jamais manipuler de nombres à virgule.
 */
final class ProductData
{
    /** Prix maximum accepté, dans chaque devise (en unités entières) : au-delà, c'est presque sûrement une faute de frappe. */
    public const MAX_PRICE = 100_000_000;

    #[Assert\NotBlank(message: 'field.required')]
    #[Assert\Length(min: 2, max: 255, minMessage: 'field.too_short', maxMessage: 'field.too_long')]
    public string $name = '';

    #[Assert\Length(max: 5000, maxMessage: 'field.too_long')]
    public string $description = '';

    /** Rayon : lettres, chiffres, tiret et soulignement (clé de catégorie). */
    #[Assert\Length(max: 64, maxMessage: 'field.too_long')]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]*$/', message: 'field.invalid')]
    public string $category = '';

    #[Assert\Length(max: 64, maxMessage: 'field.too_long')]
    public string $reference = '';

    /** Prix de base en Ariary, saisi comme texte (entier). */
    public string $priceMga = '';

    /**
     * Prix spécifiques par devise (clé : « EUR », « USD »), saisis comme texte. Vide = pas de prix spécifique : le prix est
     * alors converti au taux du jour.
     *
     * @var array<string, string>
     */
    public array $prices = [];

    /**
     * @param array<string, mixed> $data Contenu JSON de la requête
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->name = self::str($data, 'name');
        $dto->description = self::str($data, 'description');
        $dto->category = self::str($data, 'category');
        $dto->reference = self::str($data, 'reference');
        $dto->priceMga = self::str($data, 'priceMga');
        foreach (\is_array($data['prices'] ?? null) ? $data['prices'] : [] as $code => $value) {
            if (\is_string($code) && \is_scalar($value) && '' !== trim((string) $value)) {
                $dto->prices[$code] = trim((string) $value);
            }
        }

        return $dto;
    }

    #[Assert\Callback]
    public function validatePrices(ExecutionContextInterface $context): void
    {
        if ('' === $this->priceMga) {
            $context->buildViolation('field.required')->atPath('priceMga')->addViolation();
        } elseif (null === self::parseMinor($this->priceMga, Currency::MGA)) {
            $context->buildViolation('price.invalid')->atPath('priceMga')->addViolation();
        } elseif (0 === $this->priceMgaInt()) {
            $context->buildViolation('price.invalid')->atPath('priceMga')->addViolation();
        }

        foreach ($this->prices as $code => $text) {
            $currency = Currency::tryFrom($code);
            // Une devise inconnue ou la devise de base n'a pas de « prix spécifique » : on le dit au lieu de l'ignorer en silence.
            if (null === $currency || Currency::BASE === $currency) {
                $context->buildViolation('field.invalid')->atPath('price_'.$code)->addViolation();
            } elseif (null === self::parseMinor($text, $currency) || 0 === self::parseMinor($text, $currency)) {
                $context->buildViolation('price.invalid')->atPath('price_'.$code)->addViolation();
            }
        }
    }

    /** Prix de base en Ariary (entier). À n'appeler qu'après validation. */
    public function priceMgaInt(): int
    {
        return self::parseMinor($this->priceMga, Currency::MGA) ?? 0;
    }

    /**
     * Prix spécifiques en unités mineures (centimes), par devise. À n'appeler qu'après validation.
     *
     * @return array<string, int>
     */
    public function overridesMinor(): array
    {
        $result = [];
        foreach ($this->prices as $code => $text) {
            $currency = Currency::tryFrom($code);
            if (null !== $currency && Currency::BASE !== $currency && null !== ($minor = self::parseMinor($text, $currency)) && $minor > 0) {
                $result[$currency->value] = $minor;
            }
        }

        return $result;
    }

    /**
     * Convertit un montant saisi (« 12500 », « 12,50 », « 12.5 ») en unités mineures de la devise, ou null s'il est invalide
     * (lettres, négatif, trop de décimales pour cette devise, supérieur au maximum).
     */
    public static function parseMinor(string $text, Currency $currency): ?int
    {
        $text = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($text));
        $decimals = $currency->decimals();
        $pattern = 0 === $decimals ? '/^\d{1,9}$/' : '/^\d{1,9}(\.\d{1,'.$decimals.'})?$/';
        if (1 !== preg_match($pattern, $text)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        if ((int) $whole > self::MAX_PRICE) {
            return null;
        }
        $fraction = str_pad($fraction, $decimals, '0');

        return (int) $whole * $currency->minorFactor() + (0 === $decimals ? 0 : (int) $fraction);
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key): string
    {
        return \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
    }
}
