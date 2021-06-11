<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Catgento\Switch301Redirect\Model\StoreSwitcher;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Response\Http;
use Magento\Store\Api\Data\StoreInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\Framework\HTTP\PhpEnvironment\RequestFactory;

/**
 * Handle url rewrites for redirect url
 */
class RewriteUrl extends \Magento\UrlRewrite\Model\StoreSwitcher\RewriteUrl
{
    private ProductRepositoryInterface $productRepository;
    private CategoryRepositoryInterface $categoryRepository;
    private UrlFinderInterface $urlFinder;
    private RequestFactory $requestFactory;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        UrlFinderInterface $urlFinder,
        \Magento\Framework\HTTP\PhpEnvironment\RequestFactory $requestFactory
    ) {
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->urlFinder = $urlFinder;
        $this->requestFactory = $requestFactory;

        parent::__construct($urlFinder, $requestFactory);
    }

    /**
     * Switch to another store.
     *
     * @param StoreInterface $fromStore
     * @param StoreInterface $targetStore
     * @param string $redirectUrl
     * @return string
     */
    public function switch(StoreInterface $fromStore, StoreInterface $targetStore, string $redirectUrl): string
    {
        $targetUrl = $redirectUrl;
        $request = $this->requestFactory->create(['uri' => $targetUrl]);

        $urlPath = ltrim($request->getPathInfo(), '/');

        if ($targetStore->isUseStoreInUrl()) {
            // Remove store code in redirect url for correct rewrite search
            $storeCode = preg_quote($targetStore->getCode() . '/', '/');
            $pattern = "@^($storeCode)@";
            $urlPath = preg_replace($pattern, '', $urlPath);
        }

        $oldStoreId = $fromStore->getId();
        $oldRewrite = $this->urlFinder->findOneByData(
            [
                UrlRewrite::REQUEST_PATH => $urlPath,
                UrlRewrite::STORE_ID => $oldStoreId,
            ]
        );

        if ($oldRewrite) {
            $entityVisible = $this->checkRedirectEntityType($oldRewrite, $targetStore);

            $targetUrl = $targetStore->getBaseUrl();
            if ($entityVisible) {
                // look for url rewrite match on the target store
                $currentRewrite = $this->findCurrentRewrite($oldRewrite, $targetStore);
                if ($currentRewrite) {
                    $targetUrl .= $currentRewrite->getRequestPath();
                }
            }
        } else {
            $existingRewrite = $this->urlFinder->findOneByData([UrlRewrite::REQUEST_PATH => $urlPath]);
            $currentRewrite = $this->urlFinder->findOneByData(
                [
                    UrlRewrite::REQUEST_PATH => $urlPath,
                    UrlRewrite::STORE_ID => $targetStore->getId(),
                ]
            );

            if ($existingRewrite && !$currentRewrite) {
                /** @var Http $response */
                $targetUrl = $targetStore->getBaseUrl();
            }
        }

        return $targetUrl;
    }

    public function checkRedirectEntityType($rewrite, $targetStore): bool
    {
        $entityType = $rewrite->getEntityType();
        $isVisible = true;

        if ($entityType == 'product') {
            $product = $this->getProduct($rewrite->getEntityId(), $targetStore->getId());

            if ($product->getVisibility() == '1') {
                $isVisible = false;
            }
        } elseif ($entityType == 'category') {
            $category = $this->getCategory($rewrite->getEntityId(), $targetStore->getId());

            if ($category->getIsActive() == '0') {
                $isVisible = false;
            }
        }

        return $isVisible;
    }

    public function getCategory($id, $storeId)
    {
        return $this->categoryRepository->get($id, $storeId);
    }

    public function getProduct($id, $storeId)
    {
        return $this->productRepository->getById($id, false, $storeId);
    }

    /**
     * Look for url rewrite match on the target store
     *
     * @param UrlRewrite $oldRewrite
     * @param StoreInterface $targetStore
     * @return UrlRewrite|null
     */
    private function findCurrentRewrite(UrlRewrite $oldRewrite, StoreInterface $targetStore)
    {
        $currentRewrite = $this->urlFinder->findOneByData(
            [
                UrlRewrite::TARGET_PATH => $oldRewrite->getTargetPath(),
                UrlRewrite::STORE_ID => $targetStore->getId(),
            ]
        );
        if (!$currentRewrite) {
            $currentRewrite = $this->urlFinder->findOneByData(
                [
                    UrlRewrite::REQUEST_PATH => $oldRewrite->getRequestPath(),
                    UrlRewrite::STORE_ID => $targetStore->getId(),
                ]
            );
        }
        return $currentRewrite;
    }
}
