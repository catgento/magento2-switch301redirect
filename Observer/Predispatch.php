<?php

namespace Catgento\Switch301Redirect\Observer;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;

class Predispatch implements ObserverInterface
{
    const ENABLE_SIMPLENOTVISILBE_REDIRECT = 'redirects/general/enable_simple_redirect';

    protected \Magento\Framework\App\Response\Http $_redirect;
    protected \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $_productTypeConfigurable;
    protected \Magento\Catalog\Model\ProductRepository $_productRepository;
    protected \Magento\Store\Model\StoreManagerInterface $_storeManager;
    protected ScopeConfigInterface $scopeConfig;
    protected \Magento\GroupedProduct\Model\Product\Type\Grouped $groupedProduct;

    public function __construct(
        \Magento\Framework\App\Response\Http                                       $redirect,
        \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $productTypeConfigurable,
        \Magento\Catalog\Model\ProductRepository                                   $productRepository,
        \Magento\Store\Model\StoreManagerInterface                                 $storeManager,
        \Magento\GroupedProduct\Model\Product\Type\Grouped                         $groupedProduct,
        ScopeConfigInterface                                                       $scopeConfig

    )
    {
        $this->_redirect = $redirect;
        $this->_productTypeConfigurable = $productTypeConfigurable;
        $this->_productRepository = $productRepository;
        $this->_storeManager = $storeManager;
        $this->groupedProduct = $groupedProduct;
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(Observer $observer)
    {
        if (!$this->scopeConfig->getValue(self::ENABLE_SIMPLENOTVISILBE_REDIRECT, ScopeInterface::SCOPE_STORE)) {
            return;
        }

        $pathInfo = $observer->getEvent()->getRequest()->getPathInfo();

        /** If it's not a product view we don't need to do anything. */
        if (strpos($pathInfo, 'product') === false) {
            return;
        }

        $request = $observer->getEvent()->getRequest();
        $simpleProductId = $request->getParam('id');
        if (!$simpleProductId) {
            return;
        }

        $simpleProduct = $this->_productRepository->getById($simpleProductId, false, $this->_storeManager->getStore()->getId());
        if (!$simpleProduct
            || $simpleProduct->getTypeId() != \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE
        ) {
            return;
        }

        $groupedProductIds = $this->groupedProduct->getParentIdsByChild($simpleProductId);
        $this->checkParentProductsStatus($groupedProductIds);
        $configProductIds = $this->_productTypeConfigurable->getParentIdsByChild($simpleProductId);
        $this->checkParentProductsStatus($configProductIds);
    }

    private function redirectToParentProduct($parentProduct)
    {
        $parentProductUrl = $parentProduct->getUrlModel()
                ->getUrl($parentProduct);
        $this->_redirect->setRedirect($parentProductUrl, 301);
    }

    private function checkParentProductsStatus($parentProductIds)
    {
        if ($parentProductIds) {
            foreach ($parentProductIds as $parentProductId) {
                $parentProduct = $this->_productRepository->getById($parentProductId, false, $this->_storeManager->getStore()->getId());

                if (!$parentProduct || $parentProduct->getStatus() != \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED) {
                    continue;
                }

                if ($parentProduct->getVisibility() != \Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE) {
                    $this->redirectToParentProduct($parentProduct);
                }
            }
        }
    }
}
