<?php

namespace Crm\ProductsModule\Repositories;

use Crm\ApplicationModule\Models\Database\ActiveRow;
use Crm\ApplicationModule\Models\Database\Repository;
use Crm\ApplicationModule\Models\Database\Selection;

class TagsRepository extends Repository
{
    protected $tableName = 'tags';

    protected $slugs = ['code'];

    final public function all()
    {
        return $this->getTable()->order('sorting IS NOT NULL, sorting ASC, code ASC');
    }

    final public function counts()
    {
        return $this->getTable()
            ->where([
                ':product_tags.product.stock > ?' => 0,
            ])
            ->group(':product_tags.tag_id')
            ->select(':product_tags.tag_id AS id, COUNT(*) AS val');
    }

    final public function add(
        string $code,
        string $name,
        string $icon,
        bool $visible = false,
        bool $frontendVisible = false,
        bool $userAssignable = false,
        string $htmlHeading = '',
    ) {
        return $this->insert([
            'code' => $code,
            'name' => $name,
            'icon' => $icon,
            'visible' => $visible,
            'frontend_visible' => $frontendVisible,
            'user_assignable' => $userAssignable,
            'html_heading' => $htmlHeading,
        ]);
    }

    final public function updateSorting($newSorting, $oldSorting = null)
    {
        if ($newSorting == $oldSorting) {
            return;
        }

        if ($oldSorting !== null) {
            $this->getTable()->where('sorting > ?', $oldSorting)->update(['sorting-=' => 1]);
        }

        $this->getTable()->where('sorting >= ?', $newSorting)->update(['sorting+=' => 1]);
    }

    final public function isTagUsed(int $id): bool
    {
        return $this->getTable()->where([':product_tags.tag_id' => $id])->count() > 0;
    }

    final public function userAssignable(): Selection
    {
        return $this->all()->where('user_assignable', 1);
    }

    final public function findByCode(string $code): ?ActiveRow
    {
        return $this->findBy('code', $code);
    }
}
