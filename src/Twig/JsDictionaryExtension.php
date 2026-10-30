<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonction Twig `js_dictionary()` : tous les textes du domaine « js » dans la langue de la requête.
 *
 * Pourquoi : le JavaScript affiche lui-même quelques messages (« Article ajouté au panier », « Renvoyer le code dans 24 s »…).
 * Plutôt que de les écrire en dur dans le code JavaScript (donc en français seulement), la page les embarque dans une balise
 * <script type="application/json" id="gb-i18n"> (voir base.html.twig) et assets/i18n.js les lit : `t('cart.added')`.
 * Les textes manquants dans la langue demandée retombent sur le français (même règle que partout : framework.translator.fallbacks).
 */
final class JsDictionaryExtension extends AbstractExtension
{
    public function __construct(
        #[Autowire(service: 'translator')]
        private readonly TranslatorBagInterface $translator,
        #[Autowire('%app.default_locale%')]
        private readonly string $locale,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('js_dictionary', $this->dictionary(...))];
    }

    /**
     * @return array<string, string> « clé => texte », avec les clés à plat (ex. « cart.added »)
     */
    public function dictionary(?string $locale = null): array
    {
        $catalogue = $this->translator->getCatalogue($locale ?? ($this->translator instanceof \Symfony\Contracts\Translation\LocaleAwareInterface ? $this->translator->getLocale() : $this->locale));

        // Chaîne « langue → langue de repli (français) » : on part du repli puis on écrase avec la langue demandée.
        $chain = [];
        for ($c = $catalogue; null !== $c; $c = $c->getFallbackCatalogue()) {
            $chain[] = $c;
        }
        $messages = [];
        foreach (array_reverse($chain) as $c) {
            $messages = array_merge($messages, $c->all('js'));
        }

        return $messages;
    }
}
