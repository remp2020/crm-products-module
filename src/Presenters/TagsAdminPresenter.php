<?php

namespace Crm\ProductsModule\Presenters;

use Crm\AdminModule\Presenters\AdminPresenter;
use Crm\ProductsModule\Forms\TagsFormFactory;
use Crm\ProductsModule\Repositories\TagsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Request;

class TagsAdminPresenter extends AdminPresenter
{
    public $request;

    public $tagsRepository;

    public $tagsFormFactory;

    public function __construct(
        Request $request,
        TagsRepository $tagsRepository,
        TagsFormFactory $tagsFormFactory,
    ) {
        parent::__construct();
        $this->request = $request;
        $this->tagsRepository = $tagsRepository;
        $this->tagsFormFactory = $tagsFormFactory;
    }

    /**
     * @admin-access-level read
     */
    public function renderDefault()
    {
        $this->template->tags = $this->tagsRepository->all();
    }

    /**
     * @admin-access-level write
     */
    public function renderNew()
    {
    }

    /**
     * @admin-access-level write
     */
    public function renderEdit($id)
    {
        $tag = $this->tagsRepository->find($id);
        if (!$tag) {
            $this->flashMessage($this->translator->translate('products.admin.products.messages.tag_not_found'));
            $this->redirect('default');
        }
        $this->template->tag = $tag;
    }

    protected function createComponentTagsForm()
    {
        $id = $this->getParameter('id');
        $form = $this->tagsFormFactory->create($id);

        $this->tagsFormFactory->onSave = function (ActiveRow $tag) {
            $this->flashMessage($this->translator->translate('products.admin.tags.messages.tag_created'));
            $this->redirect('Default');
        };
        $this->tagsFormFactory->onUpdate = function (ActiveRow $tag) {
            $this->flashMessage($this->translator->translate('products.admin.tags.messages.tag_updated'));
            $this->redirect('Default');
        };

        return $form;
    }

    /**
     * @admin-access-level write
     */
    public function handleDeleteTag(int $id)
    {
        $tag = $this->tagsRepository->find($id);
        if (!$tag) {
            $this->flashMessage($this->translator->translate('products.admin.products.messages.tag_not_found'), 'warning');
        } else {
            if ($this->tagsRepository->isTagUsed($id)) {
                $this->flashMessage($this->translator->translate('products.admin.tags.messages.tag_indelible'), 'warning');
            } else {
                $this->tagsRepository->delete($tag);
                $this->flashMessage($this->translator->translate('products.admin.tags.messages.tag_deleted'));
            }
        }

        $this->redirect('Default');
    }
}
