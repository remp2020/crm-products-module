<?php declare(strict_types=1);

namespace Crm\ProductsModule\Forms\Controls;

use Crm\ApplicationModule\Helpers\PriceHelper;
use Nette\Forms\Controls\SelectBox;

class PostalFeesSelectBoxFactory
{
    public function __construct(
        private readonly PriceHelper $priceHelper,
    ) {
    }

    public function build(
        string $label,
        array $postalFees,
    ): SelectBox {
        $processedItems = [];
        foreach ($postalFees as $postalFee) {
            $processedItems[$postalFee->id] = sprintf(
                '%s / %s <small><code class="muted">%s</code></small>',
                $postalFee->title,
                $this->priceHelper->getFormattedPrice($postalFee->amount),
                $postalFee->code,
            );
        }

        $selectBox = new SelectBox($label, $processedItems);

        $selectBox->getControlPrototype()->addAttributes([
            'class' => 'select2',
            'style' => 'width: 100%',
        ]);

        return $selectBox;
    }
}
