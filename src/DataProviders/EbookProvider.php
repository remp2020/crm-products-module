<?php

namespace Crm\ProductsModule\DataProviders;

use Nette\Database\Table\ActiveRow;
use Tracy\Debugger;

class EbookProvider
{
    /** @var EbookProviderInterface[] */
    private $providers = [];

    public function register(EbookProviderInterface $ebookProvider)
    {
        $this->providers[$ebookProvider::identifier()] = $ebookProvider;
    }

    public function getFileTypes(): array
    {
        $fileTypes = [];
        foreach ($this->providers as $provider) {
            $fileTypes[$provider::identifier()] = $provider->getFileTypes();
        }

        return $fileTypes;
    }

    public function getDownloadLinks(ActiveRow $product, ActiveRow $user, ?ActiveRow $address): array
    {
        $downloadLinks = [];
        foreach ($this->providers as $provider) {
            try {
                $links = $provider->getDownloadLinks($product, $user, $address);
            } catch (\Exception $e) {
                Debugger::log(
                    "Ebook provider [{$provider::identifier()}] returned exception: " . $e->getMessage(),
                    Debugger::EXCEPTION
                );
                continue;
            }

            if ($links !== null) {
                $downloadLinks[$provider::identifier()] = $links;
            }
        }

        return $downloadLinks;
    }
}
