<?php

namespace Crm\ProductsModule\DataProviders;

use Nette\Database\Table\ActiveRow;

interface EbookProviderInterface
{
    public static function identifier(): string;

    /**
     * Returns supported ebook formats.
     *
     * @return array
     */
    public function getFileTypes(): array;

    /**
     * Returns download links to ebooks.
     *
     * @param ActiveRow $product
     * @param ActiveRow $user
     * @param ActiveRow|null $address
     * @return mixed
     */
    public function getDownloadLinks(ActiveRow $product, ActiveRow $user, ?ActiveRow $address);
}
