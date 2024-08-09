<?php

namespace Crm\ProductsModule\Models;

use Crm\ProductsModule\Events\CartItemAddedEvent;
use Crm\ProductsModule\Events\CartItemRemovedEvent;
use Nette\Application\BadRequestException;
use Nette\Http\Response;

trait CartTrait
{
    private $cartSession;

    private $cartProducts;

    private $cartProductSum;

    private function buildSession(): void
    {
        $this->buildCartSession();
        $this->buildTrackingParamsSession();
    }

    private function buildCartSession()
    {
        $this->cartSession = $this->getSession('cart');

        if (!isset($this->cartSession->products)) {
            $this->cartSession->products = [];
        }
        if (!isset($this->cartSession->freeProducts)) {
            $this->cartSession->freeProducts = [];
        }

        // check and throw away any deleted products
        $cartProductIds = array_merge(
            array_keys($this->cartSession->products),
            array_keys($this->cartSession->freeProducts),
        );
        $availableProductIds = array_keys($this->productsRepository->findByIds($cartProductIds));
        $unavailableProductIds = array_diff($cartProductIds, $availableProductIds);

        foreach ($unavailableProductIds as $productId) {
            unset($this->cartSession->products[$productId]);
            unset($this->cartSession->freeProducts[$productId]);
        }

        $this->cartProducts = $this->cartSession->products;
        $this->cartProductSum = array_sum($this->cartProducts);
    }

    public function handleAddCart($id, $redirectToCheckout = false, $count = 1)
    {
        $product = $this->productsRepository->find($id);
        if (!$product || !$product->shop) {
            throw new BadRequestException('Product not found.', Response::S404_NOT_FOUND);
        }

        if ($product->stock <= 0) {
            $this->flashMessage($product->name, 'product-not-available');
            $this->redirect('this');
        }

        if (isset($this->cartSession->products[$product->id]) && $product->stock <= $this->cartSession->products[$product->id]) {
            $this->flashMessage($product->name, 'product-more-not-available');
            $this->redirect('this');
        }

        if (!isset($this->cartSession->products[$product->id])) {
            if ($this->user->isLoggedIn() && $product->unique_per_user && $this->paymentItemHelper->hasUniqueProduct($product, $this->user->getId())) {
                $this->flashMessage($product->name, 'product-exists');
                $this->redirect('this');
            }

            $this->cartSession->products[$product->id] = 0;
        }

        if ($product->unique_per_user) {
            $count = 1;
            $this->cartSession->products[$product->id] = 0;
        }

        // fast checkout could mislead users if they already had something in their cart
        if ($redirectToCheckout) {
            $this->cartSession->products = [];
            $this->cartSession->products[$product->id] = 0;
            $this->cartSession->freeProducts = [];
        }

        $this->cartSession->products[$product->id] += $count;

        $this->emitter->emit(new CartItemAddedEvent($product));

        if ($this->isAjax() && !$redirectToCheckout) {
            $this->buildSession();
            $this->redrawControl('cart');
            $this->redrawControl('cartIcon');
        } else {
            $this->flashMessage($product->name, 'add-cart');
            $redirect = $redirectToCheckout ? 'checkout' : 'cart';
            $this->redirect($redirect);
        }
    }

    public function handleRemoveCart($id)
    {
        $product = $this->productsRepository->find($id);
        if (!$product || !$product->shop) {
            throw new BadRequestException('Product not found.', 404);
        }

        if (!isset($this->cartSession->products[$product->id])) {
            throw new BadRequestException('Product not found.', 404);
        }

        $this->cartSession->products[$product->id]--;

        if ($this->cartSession->products[$product->id] == 0) {
            unset($this->cartSession->products[$product->id]);
        }

        $this->emitter->emit(new CartItemRemovedEvent($product));

        if ($this->isAjax()) {
            $this->buildSession();
            $this->redrawControl('cart');
            $this->redrawControl('cartIcon');
        }
    }

    public function handleRemoveProductCart($id)
    {
        $product = $this->productsRepository->find($id);
        if (!$product || !$product->shop) {
            throw new BadRequestException('Product not found.', 404);
        }

        if (isset($this->cartSession->products[$product->id])) {
            unset($this->cartSession->products[$product->id]);
            $this->emitter->emit(new CartItemRemovedEvent($product));
        }

        if ($this->isAjax()) {
            $this->buildSession();
            $this->redrawControl('cart');
            $this->redrawControl('cartIcon');
        }
    }
}
