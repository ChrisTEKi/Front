<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Applique la contrainte StrongPassword (voir ses règles).
 */
final class StrongPasswordValidator extends ConstraintValidator
{
    /** Mots de passe les plus courants (extrait) : refusés même s'ils atteignent la longueur minimale. */
    private const COMMON = [
        'password123', 'password1234', '1234567890', '12345678910', 'azertyuiop', 'azerty12345', 'qwertyuiop',
        'motdepasse', 'motdepasse1', 'motdepasse123', 'gobuy12345', 'madagascar', 'madagascar1', 'iloveyou123',
        'abcdefghij', '0123456789', '0000000000', '1111111111', 'azertyazerty', 'password1!', 'passw0rd123',
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof StrongPassword) {
            throw new UnexpectedTypeException($constraint, StrongPassword::class);
        }
        // Une valeur vide est le travail de NotBlank, pas de cette contrainte.
        if (null === $value || '' === $value) {
            return;
        }
        $password = (string) $value;
        $length = mb_strlen($password);

        if ($length < $constraint->min) {
            $this->context->buildViolation($constraint->tooShortMessage)->setParameter('{{ limit }}', (string) $constraint->min)->addViolation();

            return;
        }
        if ($length > $constraint->max) {
            $this->context->buildViolation($constraint->tooLongMessage)->setParameter('{{ limit }}', (string) $constraint->max)->addViolation();

            return;
        }

        // Familles de caractères présentes : minuscules, majuscules, chiffres, autres (symboles, espaces, accents…).
        if ($constraint->complexity) {
            $families = (int) (1 === preg_match('/\p{Ll}/u', $password))
                + (int) (1 === preg_match('/\p{Lu}/u', $password))
                + (int) (1 === preg_match('/\d/', $password))
                + (int) (1 === preg_match('/[^\p{L}\d]/u', $password));
            if ($families < 3) {
                $this->context->buildViolation($constraint->weakMessage)->addViolation();

                return;
            }
        }

        if (\in_array(mb_strtolower($password), self::COMMON, true)) {
            $this->context->buildViolation($constraint->commonMessage)->addViolation();

            return;
        }

        // Ne doit pas contenir la partie locale de l'e-mail du même formulaire (« jean.dupont@… » → « jean.dupont »).
        $object = $this->context->getObject();
        $email = $constraint->email ?? (\is_object($object) && property_exists($object, 'email') ? (string) $object->email : '');
        $local = mb_strtolower((string) strstr($email, '@', true));
        if (\strlen($local) >= 4 && str_contains(mb_strtolower($password), $local)) {
            $this->context->buildViolation($constraint->containsEmailMessage)->addViolation();
        }
    }
}
