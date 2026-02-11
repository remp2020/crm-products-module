<?php

declare(strict_types=1);

namespace Crm\ProductsModule\Scenarios;

use Contributte\Translation\Translator;
use Crm\ApplicationModule\Models\Criteria\ScenarioParams\StringLabeledArrayParam;
use Crm\ApplicationModule\Models\Criteria\ScenariosCriteriaInterface;
use Crm\ProductsModule\Repositories\TagsRepository;
use Exception;
use Nette\Database\Table\ActiveRow;
use Nette\Database\Table\Selection;
use Nette\Utils\Random;

class ProductHasTagCriteria implements ScenariosCriteriaInterface
{
    public const KEY = 'product_has_tag';

    public function __construct(
        private readonly TagsRepository $tagsRepository,
        private readonly Translator $translator,
    ) {
    }

    public function params(): array
    {
        $tags = $this->tagsRepository->all()->fetchPairs('code', 'name');

        return [
            new StringLabeledArrayParam(self::KEY, $this->label(), $tags),
        ];
    }

    public function addConditions(Selection $selection, array $paramValues, ActiveRow $criterionItemRow): bool
    {
        $values = $paramValues[self::KEY];
        if (empty($values->selection)) {
            throw new Exception("Empty selection is not allowed for " . self::KEY);
        }

        $suffix = Random::generate();
        $productTagsAlias = "product_tags_{$suffix}";
        $tagAlias = "tag_{$suffix}";

        $selection->alias("payment:payment_items.product:product_tags", $productTagsAlias);
        $selection->alias("{$productTagsAlias}.tag", $tagAlias);
        $selection->where("{$tagAlias}.code IN (?)", $values->selection);

        return true;
    }

    public function label(): string
    {
        return $this->translator->translate('products.admin.scenarios.product_has_tag.label');
    }
}
