<?php

namespace App\Form;

use App\Entity\Supplier;
use App\Enum\PayoutMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** @extends AbstractType<Supplier> */
final class SupplierProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('shopName', TextType::class, ['label' => 'Nom de la boutique *', 'constraints' => [new Assert\NotBlank(message: 'Indiquez le nom de la boutique.'), new Assert\Length(max: 120)]])
            ->add('description', TextareaType::class, ['label' => 'Présentation', 'required' => false, 'attr' => ['rows' => 4], 'constraints' => [new Assert\Length(max: 2000)]])
            ->add('contactRole', TextType::class, ['label' => 'Votre fonction', 'required' => false])
            ->add('businessEmail', EmailType::class, ['label' => 'E-mail de contact *', 'constraints' => [new Assert\NotBlank(message: 'Indiquez une adresse e-mail.'), new Assert\Email()]])
            ->add('phone', TelType::class, ['label' => 'Téléphone *', 'constraints' => [new Assert\NotBlank(message: 'Indiquez un numéro de téléphone.')]])
            ->add('city', TextType::class, ['label' => 'Ville *', 'constraints' => [new Assert\NotBlank(message: 'Indiquez votre ville.')]])
            ->add('address', TextType::class, ['label' => 'Adresse', 'required' => false])
            ->add('payoutMethod', EnumType::class, [
                'class' => PayoutMethod::class,
                'label' => 'Moyen de versement',
                'required' => false,
                'placeholder' => 'Choisir un moyen de paiement',
                'choice_label' => static fn (PayoutMethod $method): string => $method->label(),
            ])
            ->add('payoutAccount', TextType::class, ['label' => 'Numéro Mobile Money ou RIB', 'required' => false, 'attr' => ['placeholder' => '034 XX XXX XX ou RIB bancaire']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Supplier::class,
            'constraints' => [new Assert\Callback($this->validatePayout(...))],
        ]);
    }

    public function validatePayout(Supplier $supplier, ExecutionContextInterface $context): void
    {
        $method = $supplier->getPayoutMethod();
        $account = trim((string) $supplier->getPayoutAccount());

        if (null === $method) {
            if ('' !== $account) {
                $context->buildViolation('Choisissez le moyen de versement correspondant à ce numéro.')->atPath('payoutMethod')->addViolation();
            }

            return;
        }

        if ('' === $account) {
            $context->buildViolation('Indiquez le numéro ou le RIB de versement.')->atPath('payoutAccount')->addViolation();

            return;
        }

        if ($method->isMobileMoney()) {
            $digits = preg_replace('/^\+261/', '0', preg_replace('/[\s.\-]/', '', $account) ?? '') ?? '';
            if (1 !== preg_match('/^03[23478]\d{7}$/', $digits)) {
                $context->buildViolation('Numéro Mobile Money invalide (ex : 034 12 345 67).')->atPath('payoutAccount')->addViolation();
            }
        } elseif (mb_strlen(preg_replace('/\s+/', '', $account) ?? '') < 12) {
            $context->buildViolation('RIB trop court : vérifiez les chiffres saisis.')->atPath('payoutAccount')->addViolation();
        }
    }
}
