<?php

namespace Crm\ProductsModule\Forms;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\UI\Form;
use Crm\ProductsModule\Models\TagsCache;
use Crm\ProductsModule\Repositories\TagsRepository;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\Html;
use Nette\Utils\Strings;
use Tomaj\Form\Renderer\BootstrapRenderer;

class TagsFormFactory
{
    public $onSave;
    public $onUpdate;

    public function __construct(
        private TagsCache $tagsCache,
        private TagsRepository $tagsRepository,
        private Translator $translator,
    ) {
    }

    public function create($tagId): Form
    {
        $defaults = [
            'user_assignable' => 1,
            'frontend_visible' => 0,
        ];
        $tag = null;
        if (isset($tagId)) {
            $tag = $this->tagsRepository->find($tagId);
            if ($tag) {
                $defaults = $tag->toArray();
                if (isset($defaults['sorting']) && $defaults['sorting'] > 1) {
                    $defaults['sorting'] = $defaults['sorting'] - 1; // select element after which current tag is displayed
                } else {
                    $defaults['sorting'] = null;
                }
            }
        }

        $form = new Form;

        $form->setRenderer(new BootstrapRenderer());
        $form->setTranslator($this->translator);
        $form->addProtection();

        $form->addText('name', 'products.data.tags.fields.name')
            ->setRequired('products.data.tags.errors.name')
            ->setHtmlAttribute('placeholder', 'products.data.tags.placeholder.name');

        $form->addText('code', 'products.data.tags.fields.code')
            ->setOption('description', 'products.data.tags.descriptions.code')
            ->setHtmlAttribute('placeholder', 'products.data.tags.placeholder.code')
            ->setRequired('products.data.tags.errors.code')
            ->addRule(function (TextInput $control) use ($tag) {
                $newValue = $control->getValue();
                if ($tag && $newValue === $tag->code) {
                    return true;
                }
                return $this->tagsRepository->findBy('code', $newValue) === null;
            }, 'products.data.tags.errors.duplicate_code')
            ->addRule(function (TextInput $control) {
                return !empty(Strings::webalize($control->getValue()));
            }, 'products.data.tags.errors.code')
            ->setDisabled(isset($tagId));

        $form->addTextArea('html_heading', 'products.data.tags.fields.html_heading')
            ->setHtmlAttribute('data-html-editor', []);

        $form->addText('icon', 'products.data.tags.fields.icon')
            ->setRequired('products.data.tags.errors.icon')
            ->setOption('description', Html::el('a href="https://fontawesome.io/icons/"', $this->translator->translate('products.data.tags.descriptions.icon')))
            ->setHtmlAttribute('placeholder', 'products.data.tags.placeholder.icon');

        $tagPairsQuery = $this->tagsRepository->all()->where('sorting IS NOT NULL');
        if ($tagId) {
            $tagPairsQuery->where('id != ?', $tagId);
        }
        $tagPairs = $tagPairsQuery->fetchPairs('sorting', 'name');
        $form->addSelect('sorting', 'products.data.tags.fields.sorting', $tagPairs)
            ->setPrompt('products.data.tags.placeholder.sorting');

        $form->addCheckbox('visible', 'products.data.tags.fields.visible');

        $form->addCheckbox('user_assignable', 'products.data.tags.fields.user_assignable');

        $form->addCheckbox('frontend_visible', 'products.data.tags.fields.frontend_visible');

        $form->addSubmit('send', 'system.save')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('system.save'));

        if ($tagId) {
            $form->addHidden('id', $tagId);
        }

        $form->setDefaults($defaults);

        $form->onSuccess[] = [$this, 'formSucceeded'];

        return $form;
    }

    public function formSucceeded(Form $form, array $values)
    {
        $values['sorting'] = (int) $values['sorting'] + 1; // sort after the selected element

        if (isset($values['id'])) {
            $tag = $this->tagsRepository->find($values['id']);

            if ($tag->sorting && $values['sorting'] > $tag->sorting) {
                $values['sorting'] = $values['sorting'] - 1;
            }
            $this->tagsRepository->updateSorting($values['sorting'], $tag->sorting);

            $this->tagsRepository->update($tag, $values);
            $this->onUpdate->__invoke($tag);
        } else {
            $code = Strings::webalize($values['code']);
            $tag = $this->tagsRepository->add(
                $code,
                $values['name'],
                $values['icon'],
                $values['visible'],
                $values['frontend_visible'],
                $values['user_assignable'],
                $values['html_heading'],
            );

            $this->tagsCache->add($tag->id, $code);
            $this->onSave->__invoke($tag);
        }
    }
}
