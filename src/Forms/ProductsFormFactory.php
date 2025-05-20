<?php

namespace Crm\ProductsModule\Forms;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Forms\FormFactoryDefaultsTrait;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Models\Database\SlugColumnTrait;
use Crm\ApplicationModule\UI\Form;
use Crm\ProductsModule\DataProviders\ProductTemplatePropertiesDataProviderInterface;
use Crm\ProductsModule\DataProviders\ProductsFormDataProviderInterface;
use Crm\ProductsModule\Events\ProductSaveEvent;
use Crm\ProductsModule\Models\Builder\ProductBuilder;
use Crm\ProductsModule\Models\Distribution\ProductSaveEventDistributionException;
use Crm\ProductsModule\Models\ProductsCache;
use Crm\ProductsModule\Repositories\DistributionCentersRepository;
use Crm\ProductsModule\Repositories\ProductBundlesRepository;
use Crm\ProductsModule\Repositories\ProductPropertiesRepository;
use Crm\ProductsModule\Repositories\ProductTagsRepository;
use Crm\ProductsModule\Repositories\ProductTemplatePropertiesRepository;
use Crm\ProductsModule\Repositories\ProductTemplatesRepository;
use Crm\ProductsModule\Repositories\ProductsRepository;
use Crm\ProductsModule\Repositories\TagsRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Forms\Controls\TextInput;
use Nette\Utils\Html;
use Tomaj\Form\Renderer\BootstrapRenderer;
use Tracy\Debugger;

class ProductsFormFactory
{
    use FormFactoryDefaultsTrait, SlugColumnTrait;

    public $onSave;

    public $onUpdate;

    public function __construct(
        private ProductsRepository $productsRepository,
        private ProductPropertiesRepository $productPropertiesRepository,
        private ProductTemplatesRepository $productTemplatesRepository,
        private ProductTemplatePropertiesRepository $productTemplatePropertiesRepository,
        private ProductBundlesRepository $productBundlesRepository,
        private TagsRepository $tagsRepository,
        private ProductTagsRepository $productTagsRepository,
        private DistributionCentersRepository $distributionCentersRepository,
        private ProductsCache $productsCache,
        private DataProviderManager $dataProviderManager,
        private ProductBuilder $productBuilder,
        private Translator $translator,
        private Emitter $emitter,
    ) {
    }

    /**
     * @return Form
     */
    public function create($productId)
    {
        $defaults = $this->getDefaults();
        $products = $this->productsRepository->getShopProducts(false, false);
        if (isset($productId)) {
            $products->where('id != ?', $productId);
        }
        $sortingPairs = $products->fetchPairs('sorting', 'name');

        if (isset($productId)) {
            $product = $this->productsRepository->find($productId);
            $defaults = $product->toArray();

            if (isset($defaults['sorting']) && $defaults['sorting'] > 1) {
                $defaultSorting = null;
                foreach ($sortingPairs as $sorting => $_) {
                    if (is_numeric($sorting) && $defaults['sorting'] < $sorting) {
                        break;
                    }
                    $defaultSorting = $sorting;
                }
                $defaults['sorting'] = $defaultSorting;
                reset($sortingPairs);
            } else {
                $defaults['sorting'] = null;
            }

            if ($product->product_template_id) {
                foreach ($product->related('product_properties') as $property) {
                    $defaults['template_properties_' . $product->product_template_id][$property->product_template_property_id] = $property->value;
                }
            }

            /** @var ActiveRow $pair */
            foreach ($product->related('product_bundles', 'bundle_id') as $pair) {
                $defaults['bundle_items'][] = $pair->item_id;
            }

            foreach ($product->related('product_tags')->where('tag.user_assignable', 1) as $pair) {
                $defaults['tags'][] = $pair->tag_id;
            }
        }

        $form = new Form;

        $form->setRenderer(new BootstrapRenderer());
        $form->setTranslator($this->translator);
        $form->addProtection();

        $form->addGroup();

        $form->addText('name', 'products.data.products.fields.name')
            ->setRequired('products.data.products.errors.name')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.name');

        $codeGenerateButton = Html::el('a', ['id' => 'generate_product_code', 'href' => 'javascript://'])
            ->setHtml($this->translator->translate('products.data.products.descriptions.generate_code'));
        $codeDescription = Html::el('span')
            ->setHtml($this->translator->translate('products.data.products.descriptions.code'));
        $form->addText('code', 'products.data.products.fields.code')
            ->setRequired('products.data.products.errors.code')
            ->setOption('description', Html::el('div', ['class' => 'help-block'])
                ->setHtml($codeGenerateButton . ' | ' . $codeDescription))
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.code')
            ->addRule(function (TextInput $control) {
                // code has to be URL friendly
                return $this->webalize($control->getValue()) === $control->getValue();
            }, 'products.data.products.errors.code_not_webalized')
            ->addRule(function (TextInput $control) use ($productId) {
                // single fetch is enough, code is unique column
                $productWithSameCode = $this->productsRepository->getByCode($control->getValue());
                if ($productWithSameCode === null) {
                    return true;
                }
                if ($productId === null) {
                    return false; // error; product with same code exists and we are not editing
                }
                // if there is product with same code, it has to be product we are editing
                return (int) $productId === $productWithSameCode->id;
            }, 'products.data.products.errors.code_already_used');

        $form->addText('user_label', 'products.data.products.fields.user_label')
            ->setOption('description', 'products.data.products.descriptions.user_label')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.user_label');

        $form->addText('price', 'products.data.products.fields.price')
            ->setRequired('products.data.products.errors.price_required')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.price')
            ->addRule($form::FLOAT, 'products.data.products.errors.price_float_positive')
            ->addRule($form::MIN, 'products.data.products.errors.price_float_positive', 0);

        $form->addText('catalog_price', 'products.data.products.fields.catalog_price')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.catalog_price')
            ->setOption('description', Html::el('span', ['class' => 'help-block'])
                ->setHtml($this->translator->translate('products.data.products.descriptions.catalog_price')))
            ->setRequired(false)
            ->setNullable()
            ->addRule($form::FLOAT, 'products.data.products.errors.catalog_price_float_positive')
            ->addRule($form::MIN, 'products.data.products.errors.catalog_price_float_positive', 0);

        $form->addInteger('vat', 'products.data.products.fields.vat')
            ->setRequired('products.data.products.errors.vat');

        $form->addInteger('stock', 'products.data.products.fields.stock')
            ->setRequired('products.data.products.errors.stock_required')
            ->setOption('description', 'products.data.products.descriptions.stock')
            ->addRule($form::MIN, 'products.data.products.errors.stock_positive', 0);

        $form->addText('available_at', 'products.data.products.fields.available_at')
            ->setOption('description', 'products.data.products.descriptions.available_at')
            ->setHtmlAttribute('class', 'flatpickr');

        $bundle = $form->addCheckbox('bundle', 'products.data.products.fields.bundle');
        $bundleItems = $form->addMultiSelect(
            'bundle_items',
            'products.data.products.fields.bundle_items',
            $this->productsRepository->getTable()->where([
                'bundle' => false,
            ])->fetchPairs('id', 'name'),
        )->setOption('id', 'bundleItems');

        $bundleItems->getControlPrototype()->addAttributes(['class' => 'select2']);
        $bundle->addCondition(Form::EQUAL, true)
            ->toggle($bundleItems->getOption('id'));

        // SHOP SPECIFIC SETTINGS

        $shop = $form->addCheckbox('shop', 'products.data.products.fields.shop');

        $form->addGroup($this->translator->translate('products.data.products.fields.shop_settings'))
            ->setOption('container', Html::el('div', ['id' => 'shop-container']));

        $sorting = $form->addSelect('sorting', 'products.data.products.fields.sorting', $sortingPairs)
            ->setOption('id', 'sorting')
            ->setPrompt('products.data.products.placeholder.sorting');
        $sorting->getControlPrototype()->addAttributes(['class' => 'select2']);

        $tagPairs = $this->tagsRepository->userAssignable()->fetchPairs('id', 'code');
        $tags = $form->addMultiSelect('tags', 'products.data.products.fields.tags', $tagPairs)->setOption('id', 'tags');
        $tags->getControlPrototype()->addAttributes(['class' => 'select2']);

        $description = $form->addTextArea('description', 'products.data.products.fields.description')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.description')
            ->setHtmlAttribute('rows', 10)
            ->setHtmlAttribute('data-html-editor', [])
            ->setOption('id', 'description');

        $image = $form->addText('image_url', 'products.data.products.fields.image_url')
            ->setOption('description', Html::el('span', ['class' => 'help-block'])
                ->setHtml($this->translator->translate('products.data.products.descriptions.image_url')))
            ->setOption('id', 'image')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.image_url');

        $ogImage = $form->addText('og_image_url', 'products.data.products.fields.og_image_url')
            ->setOption('id', 'ogImage')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.og_image_url');

        $images = $form->addTextArea('images', 'products.data.products.fields.images')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.images')
            ->setOption('description', 'products.data.products.descriptions.images')
            ->setOption('id', 'images')
            ->setHtmlAttribute('rows', 5);

        $ean = $form->addText('ean', 'products.data.products.fields.ean')
            ->setOption('id', 'ean')
            ->setHtmlAttribute('placeholder', 'products.data.products.placeholder.ean');

        $distributionCenters = $this->distributionCentersRepository->all()->fetchPairs('code', 'name');
        $distributionCenter = $form->addSelect('distribution_center', 'products.data.products.fields.distribution_center', $distributionCenters)
            ->setOption('id', 'distributionCenter')
            ->setPrompt('--');

        $visible = $form->addCheckbox('visible', 'products.data.products.fields.visible')->setOption('id', 'visible');
        $unique = $form->addCheckbox('unique_per_user', 'products.data.products.fields.unique_per_user')->setOption('id', 'unique');
        $delivery = $form->addCheckbox('has_delivery', 'products.data.products.fields.has_delivery')
            ->setOption('id', 'delivery')
            ->setDefaultValue(true);

        $image->addConditionOn($shop, Form::EQUAL, true)
            ->addRule(Form::FILLED, 'products.data.products.errors.image_url');

        $ogImage->addConditionOn($shop, Form::EQUAL, true)
            ->addRule(Form::FILLED, 'products.data.products.errors.og_image_url');

        $description->addConditionOn($shop, Form::EQUAL, true)
            ->addRule(Form::FILLED, 'products.data.products.errors.description');

        $ean->addCondition(Form::FILLED)
            ->addRule(Form::LENGTH, 'products.data.products.errors.ean13', 13);

        $templates = $this->productTemplatesRepository->all();
        $templateId = $form->addSelect('product_template_id', 'products.data.products.fields.template_id', $templates->fetchPairs('id', 'name'))
            ->setOption('id', 'templateId')
            ->setPrompt('No Template');

        $shop->addCondition(Form::EQUAL, true)
            ->toggle('shop-container');

        /**
         * @TODO Refactor this to load template part form by AJAX
         */
        foreach ($templates as $template) {
            $templateContainer = $form->addContainer('template_properties_' . $template->id);

            $templateProperties = $this->productTemplatePropertiesRepository->findByTemplate($template);
            foreach ($templateProperties as $templateProperty) {
                if ($templateProperty->type === ProductTemplatePropertiesRepository::TYPE_DATAPROVIDER) {
                    /** @var ProductsFormDataProviderInterface[] $providers */
                    $providers = $this->dataProviderManager->getProviders('products.dataprovider.product_form.product_template.' . $templateProperty->code, ProductTemplatePropertiesDataProviderInterface::class);
                    foreach ($providers as $sorting => $provider) {
                        $form = $provider->provide([
                            'form' => $form,
                            'container' => $templateContainer,
                            'templateProperty' => $templateProperty,
                            'productId' => $productId,
                        ]);
                    }
                } else {
                    $input = $templateContainer->addText($templateProperty->id, $templateProperty->title);
                    $input->setOption("id", "template_property_{$templateProperty->id}");
                    if ($templateProperty->required) {
                        $input->addConditionOn($templateId, Form::EQUAL, $template->id)
                            ->addRule(Form::FILLED, sprintf($this->translator->translate('products.data.products.errors.template_property'), $templateProperty->title));
                    }
                    if ($templateProperty->type === 'date') {
                        $input->setHtmlAttribute('class', 'flatpickr');
                    }

                    if ($templateProperty->hint) {
                        $input->setHtmlAttribute('placeholder', $templateProperty->hint);
                    }
                }
                $templateId->addCondition(Form::EQUAL, $template->id)
                    ->toggle("template_property_{$templateProperty->id}");
            }
        }

        // reset group to default
        $form->setCurrentGroup();

        $form->addSubmit('send', 'system.save')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> ' . $this->translator->translate('system.save'));

        if ($productId) {
            $form->addHidden('product_id', $productId);
        }

        /** @var ProductsFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders(
            'products.dataprovider.product_form',
            ProductsFormDataProviderInterface::class,
        );
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form]);
        }

        $form->setDefaults($defaults);

        $form->onSuccess[] = [$this, 'formSucceeded'];

        return $form;
    }

    /**
     * @param $form
     * @param $values
     */
    public function formSucceeded($form, $values)
    {
        $values['sorting'] = (int)$values['sorting'] + 1;

        $bundleItems = [];
        if ($values['bundle']) {
            $bundleItems = $values['bundle_items'];
        }
        unset($values['bundle_items']);

        $tags = [];
        if ($values['tags']) {
            $tags = $values['tags'];
        }
        unset($values['tags']);

        $productProperties = [];
        if ($values['shop']) {
            if (!empty($values['product_template_id'])) {
                $productProperties = $values['template_properties_' . $values['product_template_id']];
            }
        } else {
            unset($values['product_template_id']);
            unset($values['description']);
            unset($values['image_url']);
            unset($values['ean']);
        }

        $templates = $this->productTemplatesRepository->all();
        foreach ($templates as $template) {
            unset($values['template_properties_' . $template->id]);
        }

        if (!isset($values['available_at']) || empty(trim($values['available_at']))) {
            $values['available_at'] = null;
        }

        if (isset($values['product_id'])) {
            $productId = $values['product_id'];
            unset($values['product_id']);

            $product = $this->productsRepository->find($productId);

            $templateProperties = $this->productTemplatePropertiesRepository->getTable()->where([
                'product_template_id' => $product->product_template_id,
                'type' => ProductTemplatePropertiesRepository::TYPE_DATAPROVIDER,
            ]);

            foreach ($templateProperties as $templateProperty) {
                /** @var ProductTemplatePropertiesDataProviderInterface[] $providers */
                $providers = $this->dataProviderManager->getProviders(
                    'products.dataprovider.product_form.product_template.' . $templateProperty->code,
                    ProductTemplatePropertiesDataProviderInterface::class,
                );
                foreach ($providers as $provider) {
                    $provider->beforeUpdate($product, $templateProperty);
                }
            }

            if ($values['sorting'] > $product->sorting) {
                $values['sorting'] = $values['sorting'] - 1;
            }
            $this->productsRepository->updateSorting($values['sorting'], $product->sorting);

            $this->productsRepository->update($product, $values);
            $this->productsCache->add($productId, $values['code']);
            $this->productBundlesRepository->setBundleItems($product, $bundleItems);
            $this->productPropertiesRepository->setProductProperties($product, $productProperties);

            // preserve tags sorting and user's unassignable tags
            $assignable = $product->related('product_tags')
                ->where('tag.user_assignable', 1)
                ->fetchPairs('tag_id', 'sorting');

            $unAssignable = $product->related('product_tags')
                ->where('tag.user_assignable', 0)
                ->fetchPairs('tag_id', 'sorting');

            $tagsWithSorting = $unAssignable;
            foreach ($tags as $tagId) {
                $tagsWithSorting[$tagId] = isset($assignable[$tagId]) ? $assignable[$tagId] : null;
            }

            $this->productTagsRepository->setProductTagsWithSorting($product, $tagsWithSorting);

            try {
                $this->emitter->emit(new ProductSaveEvent($product));
            } catch (ProductSaveEventDistributionException $e) {
                Debugger::log($e, Debugger::EXCEPTION);
                $form->addError($this->translator->translate('products.data.products.errors.not_stored_to_distribution_center'));
                return;
            }

            $callback = $this->onUpdate;
        } else {
            $product = $this->productBuilder->createNew()
                ->fromArray((array)$values)
                ->setBundleItems($bundleItems)
                ->setTemplateProperties($productProperties)
                ->setProductsTags($tags)
                ->save();

            if (!$product) {
                $form['name']->addError(implode("\n", $this->productBuilder->getErrors()));
                return;
            }

            $this->productsCache->add($product->id, $product->code);
            $this->emitter->emit(new ProductSaveEvent($product));

            $callback = $this->onSave;
        }

        $templateProperties = $this->productTemplatePropertiesRepository->getTable()->where([
            'product_template_id' => $product->product_template_id,
            'type' => ProductTemplatePropertiesRepository::TYPE_DATAPROVIDER,
        ]);
        foreach ($templateProperties as $templateProperty) {
            /** @var ProductTemplatePropertiesDataProviderInterface[] $providers */
            $providers = $this->dataProviderManager->getProviders(
                'products.dataprovider.product_form.product_template.' . $templateProperty->code,
                ProductTemplatePropertiesDataProviderInterface::class,
            );
            foreach ($providers as $provider) {
                $provider->afterSave($product, $templateProperty);
            }
        }

        $callback->__invoke($product);
    }
}
