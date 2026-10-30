<?php

namespace App\Validator;

use App\Phone\PhoneNumberParser;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Valide un numéro de mobile via PhoneNumberParser (mêmes règles partout dans l'application).
 */
final class MobilePhoneValidator extends ConstraintValidator
{
    public function __construct(private readonly PhoneNumberParser $parser)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MobilePhone) {
            throw new UnexpectedTypeException($constraint, MobilePhone::class);
        }
        if (null === $value || '' === trim((string) $value)) {
            return;
        }
        if (null === $this->parser->parse((string) $value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
