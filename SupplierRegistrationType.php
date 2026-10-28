<?php

namespace App\Form;

use App\Dto\SupplierRegistration;
use App\Entity\Supplier;
use App\Enum\SupplierPlan;
use App\Service\CategoryProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<SupplierRegistration> */
final class SupplierRegistrationType extends AbstractType
{
    public const PRODUCT_TYPES = ['Électronique', 'Mode', 'Épicerie', 'Maison', 'Beauté', 'Sport', 'Jouets'];
    public const SALES_POINTS = ['Boutique physique', 'Entrepôt', 'Vente en ligne uniquement', 'Marché / Étal'];
    public const DAMAGE_PROOFS = ['Photos des dommages', 'Dépôt de plainte', 'Constat / rapport'];
    public const SKU_RANGES = ['1–10', '11–50', '51–200', '200+'];
    public const PREP_TIMES = ['Moins de 2h ouvrées', 'Le jour même', '24h à 48h'];

    public function __construct(private readonly CategoryProvider $categories)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $departments = [];
        foreach ($this->categories->all() as $category) {
            $departments[$category['name']] = $category['slug'];
        }

        $builder
            // Step 1 — account
            ->add('fullName', TextType::class, ['empty_data' => '', 'label' => 'Nom du contact *', 'attr' => ['placeholder' => 'RAKOTOMALALA Jean', 'autocomplete' => 'name']])
            ->add('contactRole', TextType::class, ['empty_data' => '', 'label' => 'Fonction / Rôle', 'required' => false, 'attr' => ['placeholder' => 'Gérant, Responsable…']])
            ->add('email', EmailType::class, ['empty_data' => '', 'label' => 'E-mail professionnel *', 'attr' => ['placeholder' => 'contact@entreprise.mg', 'autocomplete' => 'email']])
            ->add('phone', TelType::class, ['empty_data' => '', 'label' => 'Téléphone mobile *', 'attr' => ['placeholder' => '034 12 345 67', 'autocomplete' => 'tel']])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => ['label' => 'Mot de passe * (8 caractères min.)', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmer le mot de passe *', 'attr' => ['autocomplete' => 'new-password']],
            ])
            // Step 2 — shop
            ->add('shopName', TextType::class, ['empty_data' => '', 'label' => 'Nom de la boutique / entreprise *', 'attr' => ['placeholder' => 'StylePro Madagascar']])
            ->add('city', TextType::class, ['empty_data' => '', 'label' => 'Ville *'])
            ->add('address', TextType::class, ['empty_data' => '', 'label' => 'Adresse', 'required' => false, 'attr' => ['placeholder' => 'Lot, quartier, repère…']])
            ->add('activityType', ChoiceType::class, ['empty_data' => '', 
                'label' => 'Type d\'activité',
                'choices' => ['Particulier' => Supplier::ACTIVITY_INDIVIDUAL, 'Entreprise' => Supplier::ACTIVITY_COMPANY],
            ])
            ->add('taxId', TextType::class, ['empty_data' => '', 'label' => 'NIF / STAT (obligatoire pour une entreprise)', 'required' => false, 'attr' => ['placeholder' => 'Numéro fiscal']])
            ->add('mainCategory', ChoiceType::class, ['empty_data' => '', 'label' => 'Rayon principal *', 'choices' => $departments, 'placeholder' => 'Choisir un rayon'])
            ->add('plan', EnumType::class, [
                'class' => SupplierPlan::class,
                'label' => 'Formule souhaitée',
                'expanded' => true,
                'choice_label' => static fn (SupplierPlan $plan): string => SupplierPlan::Pro === $plan ? 'Pro (49 000 Ar/mois)' : 'Standard (0 Ar)',
            ])
            ->add('description', TextareaType::class, ['empty_data' => '', 'label' => 'Présentation de votre activité', 'required' => false, 'attr' => ['rows' => 3, 'placeholder' => 'Décrivez vos produits, marques, garanties…']])
            // Step 3 — activity & logistics
            ->add('productTypes', ChoiceType::class, ['label' => 'Types de produits proposés', 'choices' => array_combine(self::PRODUCT_TYPES, self::PRODUCT_TYPES), 'multiple' => true, 'expanded' => true, 'required' => false])
            ->add('skuRange', ChoiceType::class, ['label' => 'Nombre de références (SKU) estimé', 'choices' => array_combine(self::SKU_RANGES, self::SKU_RANGES), 'placeholder' => 'Choisir', 'required' => false])
            ->add('prepTime', ChoiceType::class, ['label' => 'Délai de préparation moyen', 'choices' => array_combine(self::PREP_TIMES, self::PREP_TIMES), 'placeholder' => 'Choisir', 'required' => false])
            ->add('salesPoints', ChoiceType::class, ['label' => 'Point(s) de vente', 'choices' => array_combine(self::SALES_POINTS, self::SALES_POINTS), 'multiple' => true, 'expanded' => true, 'required' => false])
            ->add('ownDelivery', CheckboxType::class, ['label' => 'Je dispose de ma propre livraison (sinon, coursiers GoBuy)', 'required' => false])
            ->add('stockTool', TextType::class, ['empty_data' => '', 'label' => 'Outil de gestion des stocks utilisé', 'required' => false, 'attr' => ['placeholder' => 'Ex : Sage, Excel, cahier papier…']])
            ->add('hasDamage', CheckboxType::class, ['label' => 'J\'ai subi un pillage ou des dommages matériels (programme « Reconstruisons Ensemble »)', 'required' => false])
            ->add('damageProofs', ChoiceType::class, ['label' => 'Justificatifs disponibles', 'choices' => array_combine(self::DAMAGE_PROOFS, self::DAMAGE_PROOFS), 'multiple' => true, 'expanded' => true, 'required' => false])
            ->add('expectations', TextareaType::class, ['empty_data' => '', 'label' => 'Vos attentes vis-à-vis de GoBuy', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('agreeTerms', CheckboxType::class, ['label' => 'J\'accepte les conditions du programme partenaire GoBuy', 'required' => false]);

        // Unique-email checks are case sensitive: normalise before validation.
        $builder->get('email')->addModelTransformer(new CallbackTransformer(
            static fn (?string $value): ?string => $value,
            static fn (?string $value): string => mb_strtolower(trim((string) $value)),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SupplierRegistration::class]);
    }
}
