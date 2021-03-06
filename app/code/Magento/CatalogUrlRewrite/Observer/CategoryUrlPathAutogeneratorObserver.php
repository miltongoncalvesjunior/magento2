<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\CatalogUrlRewrite\Observer;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\CatalogUrlRewrite\Model\Category\ChildrenCategoriesProvider;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\CatalogUrlRewrite\Service\V1\StoreViewService;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;

/**
 * Class for set or update url path.
 */
class CategoryUrlPathAutogeneratorObserver implements ObserverInterface
{

    /**
     * Reserved endpoint names.
     *
     * @var string[]
     */
    private $invalidValues = [];

    /**
     * @var \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator
     */
    protected $categoryUrlPathGenerator;

    /**
     * @var \Magento\CatalogUrlRewrite\Model\Category\ChildrenCategoriesProvider
     */
    protected $childrenCategoriesProvider;

    /**
     * @var \Magento\CatalogUrlRewrite\Service\V1\StoreViewService
     */
    protected $storeViewService;

    /**
     * @var CategoryRepositoryInterface
     */
    private $categoryRepository;

    /**
     * @var \Magento\Backend\App\Area\FrontNameResolver
     */
    private $frontNameResolver;

    /**
     * @param CategoryUrlPathGenerator $categoryUrlPathGenerator
     * @param ChildrenCategoriesProvider $childrenCategoriesProvider
     * @param \Magento\CatalogUrlRewrite\Service\V1\StoreViewService $storeViewService
     * @param CategoryRepositoryInterface $categoryRepository
     * @param \Magento\Backend\App\Area\FrontNameResolver $frontNameResolver
     * @param string[] $invalidValues
     */
    public function __construct(
        CategoryUrlPathGenerator $categoryUrlPathGenerator,
        ChildrenCategoriesProvider $childrenCategoriesProvider,
        StoreViewService $storeViewService,
        CategoryRepositoryInterface $categoryRepository,
        \Magento\Backend\App\Area\FrontNameResolver $frontNameResolver = null,
        array $invalidValues = []
    ) {
        $this->categoryUrlPathGenerator = $categoryUrlPathGenerator;
        $this->childrenCategoriesProvider = $childrenCategoriesProvider;
        $this->storeViewService = $storeViewService;
        $this->categoryRepository = $categoryRepository;
        $this->frontNameResolver = $frontNameResolver ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(\Magento\Backend\App\Area\FrontNameResolver::class);
        $this->invalidValues = $invalidValues;
    }

    /**
     * Method for update/set url path.
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var Category $category */
        $category = $observer->getEvent()->getCategory();
        $useDefaultAttribute = !empty($category->getData('use_default')['url_key']);
        if ($category->getUrlKey() !== false && !$useDefaultAttribute) {
            $resultUrlKey = $this->categoryUrlPathGenerator->getUrlKey($category);
            $this->updateUrlKey($category, $resultUrlKey);
        } elseif ($useDefaultAttribute) {
            if (!$category->isObjectNew() && $category->getStoreId() === Store::DEFAULT_STORE_ID) {
                $resultUrlKey = $category->formatUrlKey($category->getOrigData('name'));
                $this->updateUrlKey($category, $resultUrlKey);
            }
            $category->setUrlKey(null)->setUrlPath(null);
        }
    }

    /**
     * Update Url Key
     *
     * @param Category $category
     * @param string|null $urlKey
     * @return void
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function updateUrlKey(Category $category, ?string $urlKey): void
    {
        $this->validateUrlKey($category, $urlKey);
        $category->setUrlKey($urlKey)
            ->setUrlPath($this->categoryUrlPathGenerator->getUrlPath($category));
        if (!$category->isObjectNew()) {
            $category->getResource()->saveAttribute($category, 'url_path');
            if ($category->dataHasChangedFor('url_path')) {
                $this->updateUrlPathForChildren($category);
            }
        }
    }

    /**
     * Validate URL key value
     *
     * @param Category $category
     * @param string|null $urlKey
     * @return void
     * @throws LocalizedException
     */
    private function validateUrlKey(Category $category, ?string $urlKey): void
    {
        if (empty($urlKey) && !empty($category->getName()) && !empty($category->getUrlKey())) {
            throw new LocalizedException(
                __(
                    'Invalid URL key. The "%1" URL key can not be used to generate Latin URL key. ' .
                    'Please use Latin letters and numbers to avoid generating URL key issues.',
                    $category->getUrlKey()
                )
            );
        }

        if (empty($urlKey) && !empty($category->getName())) {
            throw new LocalizedException(
                __(
                    'Invalid URL key. The "%1" category name can not be used to generate Latin URL key. ' .
                    'Please add URL key or change category name using Latin letters and numbers to avoid generating ' .
                    'URL key issues.',
                    $category->getName()
                )
            );
        }

        if (empty($urlKey)) {
            throw new LocalizedException(__('Invalid URL key'));
        }

        if (in_array($urlKey, $this->getInvalidValues())) {
            throw new LocalizedException(
                __(
                    'URL key "%1" matches a reserved endpoint name (%2). Use another URL key.',
                    $urlKey,
                    implode(', ', $this->getInvalidValues())
                )
            );
        }
    }

    /**
     * Get reserved endpoint names.
     *
     * @return array
     */
    private function getInvalidValues()
    {
        return array_unique(array_merge($this->invalidValues, [$this->frontNameResolver->getFrontName()]));
    }

    /**
     * Update url path for children category.
     *
     * @param Category $category
     * @return void
     */
    protected function updateUrlPathForChildren(Category $category)
    {
        if ($this->isGlobalScope($category->getStoreId())) {
            $childrenIds = $this->childrenCategoriesProvider->getChildrenIds($category, true);
            foreach ($childrenIds as $childId) {
                foreach ($category->getStoreIds() as $storeId) {
                    if ($this->storeViewService->doesEntityHaveOverriddenUrlPathForStore(
                        $storeId,
                        $childId,
                        Category::ENTITY
                    )) {
                        $child = $this->categoryRepository->get($childId, $storeId);
                        $this->updateUrlPathForCategory($child);
                    }
                }
            }
        } else {
            $children = $this->childrenCategoriesProvider->getChildren($category, true);
            foreach ($children as $child) {
                /** @var Category $child */
                $child->setStoreId($category->getStoreId());
                if ($child->getParentId() === $category->getId()) {
                    $this->updateUrlPathForCategory($child, $category);
                } else {
                    $this->updateUrlPathForCategory($child);
                }
            }
        }
    }

    /**
     * Check is global scope
     *
     * @param int|null $storeId
     * @return bool
     */
    protected function isGlobalScope($storeId)
    {
        return null === $storeId || $storeId == Store::DEFAULT_STORE_ID;
    }

    /**
     * Update url path for category.
     *
     * @param Category $category
     * @param Category|null $parentCategory
     * @return void
     * @throws NoSuchEntityException
     */
    protected function updateUrlPathForCategory(Category $category, Category $parentCategory = null)
    {
        $category->unsUrlPath();
        $category->setUrlPath($this->categoryUrlPathGenerator->getUrlPath($category, $parentCategory));
        $category->getResource()->saveAttribute($category, 'url_path');
    }
}
