<?php

namespace Crm\ProductsModule\Components\AvgProductsPaymentWidget;

use Crm\ApplicationModule\Models\Widget\BaseLazyWidget;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManager;
use Crm\ApplicationModule\Repositories\CacheRepository;
use Crm\SegmentModule\Models\SegmentWidgetInterface;
use Crm\UsersModule\Repositories\UserStatsRepository;
use Nette\Database\Table\ActiveRow;

class AvgProductsPaymentWidget extends BaseLazyWidget implements SegmentWidgetInterface
{
    private string $templateName = 'avg_products_payment_widget.latte';

    private CacheRepository $cacheRepository;
    private UserStatsRepository $userStatsRepository;

    public function __construct(
        LazyWidgetManager $lazyWidgetManager,
        UserStatsRepository $userStatsRepository,
        CacheRepository $cacheRepository,
    ) {
        parent::__construct($lazyWidgetManager);
        $this->cacheRepository = $cacheRepository;
        $this->userStatsRepository = $userStatsRepository;
    }

    public function identifier()
    {
        return 'avgproductspaymentwidget';
    }

    public function render(ActiveRow $segment)
    {
        if (!$this->isWidgetUsable($segment)) {
            return;
        }

        $avgProductPayments = $this->cacheRepository->load($this->getCacheKey($segment));

        $this->template->avgProductPayments = $avgProductPayments->value ?? 0;
        $this->template->updatedAt = $avgProductPayments->updated_at ?? null;
        $this->template->setFile(__DIR__ . DIRECTORY_SEPARATOR . $this->templateName);
        $this->template->render();
    }

    public function recalculate(ActiveRow $segment, array $userIds): void
    {
        if (!$this->isWidgetUsable($segment)) {
            return;
        }

        $result = $this->userStatsRepository
            ->getTable()
            ->select('COALESCE(SUM(value), 0) AS sum')
            ->where(['key' => 'product_payments', 'user_id' => $userIds])
            ->fetch();

        $value = 0;
        if ($result !== null && count($userIds) !== 0) {
            $value = $result->sum / count($userIds);
        }

        $this->cacheRepository->updateKey($this->getCacheKey($segment), $value);
    }

    private function isWidgetUsable($segment): bool
    {
        return $segment->table_name === 'users';
    }

    private function getCacheKey($segment): string
    {
        return sprintf('segment_%s_avg_products_payment', $segment->id);
    }
}
