<?php
/**
 * Apptha
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.apptha.com/LICENSE.txt
 *
 * ==============================================================
 *                 MAGENTO EDITION USAGE NOTICE
 * ==============================================================
 * This package designed for Magento COMMUNITY edition
 * Apptha does not guarantee correct work of this extension
 * on any other Magento edition except Magento COMMUNITY edition.
 * Apptha does not provide extension support in case of
 * incorrect edition usage.
 * ==============================================================
 *
 * @category    Apptha
 * @package     Apptha_Marketplace
 * @version     1.2
 * @author      Apptha Team <developers@contus.in>
 * @copyright   Copyright (c) 2017 Apptha. (http://www.apptha.com)
 * @license     http://www.apptha.com/LICENSE.txt
 *
 */
namespace Apptha\Marketplace\Controller\Assignproduct\Cart;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart as CustomerCart;
use Magento\Framework\Exception\NoSuchEntityException;
/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Add extends \Magento\Checkout\Controller\Cart {
    /**
     *
     * @var ProductRepositoryInterface
     */
    protected $request;
    protected $formKeyValidator;
    /**
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator
     * @param CustomerCart $cart
     * @param ProductRepositoryInterface $productRepository
     *            @codeCoverageIgnore
     */
    public function __construct(\Magento\Framework\App\Action\Context $context, \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig, \Magento\Checkout\Model\Session $checkoutSession, \Magento\Store\Model\StoreManagerInterface $storeManager, \Magento\Framework\Data\Form\FormKey\Validator $formKeyValidator, \Magento\Framework\App\Request\Http $request, CustomerCart $cart) {
        parent::__construct ( $context, $scopeConfig, $checkoutSession, $storeManager, $formKeyValidator, $cart );
        $this->_request = $request;
        $this->formKeyValidator = $formKeyValidator;
    }

    /**
     * Initialize product instance from request data
     *
     * @return \Magento\Catalog\Model\Product|false
     */
    protected function _initProduct() {
        $productId = ( int ) $this->getRequest ()->getParam ( 'product' );
        if ($productId) {
            $storeId = $this->_objectManager->get ( 'Magento\Store\Model\StoreManagerInterface' )->getStore ()->getId ();
            try {
                return $this->_objectManager->get ( 'Magento\Catalog\Api\ProductRepositoryInterface' )->getById ( $productId, false, $storeId );
            } catch ( NoSuchEntityException $e ) {
                return false;
            }
        }
        return false;
    }

    /**
     * Add product to shopping cart action
     *
     * @return \Magento\Framework\Controller\Result\Redirect @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute() {
        $goBack = 0;
        $params = $this->getRequest ()->getParams ();
        $flagForSeller = $this->checkingForSellerProduct ( $params );
        if ($flagForSeller == 0) {
            try {
                if (isset ( $params ['qty'] )) {
                    $filter = $this->_objectManager->get('Magento\Framework\Filter\LocalizedToNormalized');
                    $params ['qty'] = $filter->filter ( $params ['qty'] );
                }

                $product = $this->_initProduct ();
                $related = $this->getRequest ()->getParam ( 'related_product' );

                /**
                 * Check product availability
                 */
                if (! $product) {
                    $goBack = 1;
                }

                if ($goBack == 0) {
                    $this->cart->addProduct ( $product, $params );
                    if (! empty ( $related )) {
                        $this->cart->addProductsByIds ( explode ( ',', $related ) );
                    }

                    $this->cart->save ();
                    $this->_eventManager->dispatch ( 'checkout_cart_add_product_complete', [
                            'product' => $product,
                            'request' => $this->getRequest (),
                            'response' => $this->getResponse ()
                    ] );

                    if (! $this->_checkoutSession->getNoCartRedirect ( true )) {
                        $this->checkQuoteError ( $product );
                        return $this->goBack ( null, $product );
                    }
                }
            } catch ( \Magento\Framework\Exception\LocalizedException $e ) {
                if ($this->_checkoutSession->getUseNotice ( true )) {
                    $this->messageManager->addNotice ( $this->_objectManager->get ( 'Magento\Framework\Escaper' )->escapeHtml ( $e->getMessage () ) );
                } else {
                    $messages = array_unique ( explode ( "\n", $e->getMessage () ) );
                    foreach ( $messages as $message ) {
                        $this->messageManager->addError ( $this->_objectManager->get ( 'Magento\Framework\Escaper' )->escapeHtml ( $message ) );
                    }
                }
            } catch ( \Exception $e ) {
                $this->_objectManager->get ( 'Psr\Log\LoggerInterface' )->critical ( $e );
                $goBack = 1;
            }
        }
        $url = $this->_checkoutSession->getRedirectUrl ( true );

        if ($goBack == 1) {
            return $this->goBack ();
        }
        if (! $url) {
            $cartUrl = $this->_objectManager->get ( 'Magento\Checkout\Helper\Cart' )->getCartUrl ();
            $url = $this->_redirect->getRedirectUrl ( $cartUrl );
        }
        return $this->goBack ( $url );
    }

    /**
     * To set session message
     *
     * @param object $product
     *
     * @return void
     */
    public function checkQuoteError($product){
        if (! $this->cart->getQuote ()->getHasError ()) {
            $message = __ ( 'You added %1 to your shopping cart.', $product->getName () );
            $this->messageManager->addSuccessMessage ( $message );
        }
    }

    /**
     * Checking for seller product
     *
     * @param array $params
     *
     * @return string
     */
    public function checkingForSellerProduct($params){

        $flagForSeller = 0;

        if (! $this->formKeyValidator->validate ( $this->getRequest () )) {
            return $this->resultRedirectFactory->create ()->setPath ( '*/*/' );
        }

        $cartFlag = 0;
        if (isset ( $params ['qty'] )) {
            $productQty = $params ['qty'];
        }
        $productId = $params ['product'];
        $product = $this->_objectManager->get ( 'Magento\Catalog\Model\Product' )->load ( $productId );
        $isAssignProduct = $product->getIsAssignProduct ();
        $typeId = $product->getTypeId ();
        $stockData = $this->getProductStockData ( $productId );
        $productName = $product->getName ();
        if ($typeId != "configurable" && $isAssignProduct == 1 && isset ( $params ['qty'] ) &&  $productQty > $stockData) {
            $cartFlag = 1;
        }
        if ($typeId == "configurable") {
            $superAttributes = array();
            if(isset($params ['super_attribute'])){
                $superAttributes = $params ['super_attribute'];
            }
            $configProductCollection = $this->_objectManager->get ( 'Magento\ConfigurableProduct\Model\Product\Type\Configurable' )->getUsedProductCollection ( $product )->addAttributeToSelect ( '*' );
            foreach ( $superAttributes as $opt => $key ) {
                $configProductCollection->addAttributeToFilter ( $opt, $key );
            }
            $configProductCollectionData = $configProductCollection->getData ();

            foreach ( $configProductCollectionData as $associatedProducts ) {
                $productId = $associatedProducts ['entity_id'];
                $stockData = $this->getProductStockData ( $productId );
            }
            if(isset($params['qty']) && ($productQty > $stockData)){
                $cartFlag = 1;
            }
        }

        if ($cartFlag == 1) {
            $this->messageManager->addError ( __ ( 'We don\'t have as many ') . $productName . (' as you requested') );
            $this->messageManager->addError ( __ ( 'We can\'t add this item to your shopping cart right now.' ) );
            $url = $this->_checkoutSession->getRedirectUrl ( true );

            if (! $url) {
                $cartUrl = $this->_objectManager->get ( 'Magento\Checkout\Helper\Cart' )->getCartUrl ();
                $url = $this->_redirect->getRedirectUrl ( $cartUrl );
            }

            $flagForSeller = 1;
        }
        return $flagForSeller;
    }

    /**
     * Resolve response
     *
     * @param string $backUrl
     * @param \Magento\Catalog\Model\Product $product
     * @return $this|\Magento\Framework\Controller\Result\Redirect
     */
    protected function goBack($backUrl = null, $product = null) {
        if (! $this->getRequest ()->isAjax ()) {
            return parent::_goBack ( $backUrl );
        }
        $result = [ ];
        if ($backUrl || $backUrl = $this->getBackUrl ()) {
            $result ['backUrl'] = $backUrl;
        } else {
            if ($product && ! $product->getIsSalable ()) {
                $result ['product'] = [
                        'statusText' => __ ( 'Out of stock' )
                ];
            }
        }
        $this->getResponse ()->representJson ( $this->_objectManager->get ( 'Magento\Framework\Json\Helper\Data' )->jsonEncode ( $result ) );
    }
    /**
     * Get Product Stock Qty
     *
     * @return int
     */
    public function getProductStockData($productId) {
        $qtys = $this->_objectManager->get ( 'Magento\CatalogInventory\Api\Data\StockItemInterface' )->load ( $productId, 'product_id' );
        return $qtys->getQty ();
    }
}