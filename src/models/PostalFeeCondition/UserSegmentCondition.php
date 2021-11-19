<?php

namespace Crm\ProductsModule\PostalFeeCondition;

use Crm\SegmentModule\Repository\SegmentsRepository;
use Crm\SegmentModule\SegmentFactoryInterface;
use Kdyby\Translation\Translator;
use Nette\ComponentModel\IComponent;
use Nette\Forms\Controls\SelectBox;

class UserSegmentCondition implements PostalFeeConditionInterface, PostalFeeMessageConditionInterface
{
    public const CONDITION_CODE = 'user_segment';

    private $translator;

    private $segmentFactory;

    private $segmentsRepository;

    public function __construct(
        Translator $translator,
        SegmentFactoryInterface $segmentFactory,
        SegmentsRepository $segmentsRepository
    ) {
        $this->translator = $translator;
        $this->segmentFactory = $segmentFactory;
        $this->segmentsRepository = $segmentsRepository;
    }

    public function isReached(array $products, string $value, int $userId = null): bool
    {
        if ($userId === null) {
            return false;
        }

        $segment = $this->segmentFactory->buildSegment($value);

        return $segment->isIn('id', $userId);
    }

    public function getLabel(): string
    {
        return $this->translator->translate('products.admin.country_postal_fees.conditions.user_segment.label');
    }

    public function getInputControl(): IComponent
    {
        $selectBox = new SelectBox(
            'products.data.country_postal_fees.fields.condition_value',
            $this->segmentsRepository->all()->fetchPairs('code', 'name')
        );

        $selectBox->setRequired()
            ->setPrompt('----')
            ->getControlPrototype()
            ->addAttributes(['class' => 'select2 form-control']);

        return $selectBox;
    }

    public function getReachedMessage(): string
    {
        return $this->translator->translate('products.admin.country_postal_fees.conditions.user_segment.message');
    }
}
