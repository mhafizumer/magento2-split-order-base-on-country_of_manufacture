<?php

namespace Magento\SplitOrder\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Store;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
class ProcessOrder implements ObserverInterface
{
    const DEFAULT_SHIPPING_METHOD = 'freeshipping_freeshipping';
    const CHILD_ORDER_PAYMENT_METHOD = 'checkmo';
    public function __construct(
        protected OrderFactory $orderFactory,
        protected StoreManagerInterface $storeManager,
        protected Store $store,
        protected CartRepositoryInterface $cartRepositoryInterface,
        protected CustomerFactory $customerFactory,
        protected CartManagementInterface $cartManagementInterface,
        protected CustomerRepositoryInterface $customerRepository
    ){}

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if ($this->shouldSplitOrder($order)) {
            $itemsByCountry = $this->groupItemsByCountry($order);
            foreach ($itemsByCountry??[] as $countryId => $items) {
                $this->createChildOrders($order,$items,$countryId);
            }
        }
    }

    /**
     * @param Order $parentOrder
     * @param $items
     * @param $countryId
     * @return void
     * @description this function creates a child order for the given items
     */

    public function createChildOrders(Order $parentOrder, $items,$countryId) {
        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        $customer = $this->customerFactory->create();
        $customer->setWebsiteId($websiteId);
        $customer->loadByEmail($parentOrder->getCustomerEmail());
        $cartId = $this->cartManagementInterface->createEmptyCart();
        $quote = $this->cartRepositoryInterface->get($cartId);
        $quote->setStoreId($parentOrder->getStoreId());
        if ($customer->getEntityId()){
            $customer = $this->customerRepository->getById($customer->getEntityId());
            $quote->assignCustomer($customer);
        }
        $quote->setCurrency();
        foreach ($items as $item) {
           $qty = $item->getQtyOrdered();
           $quote->addProduct($item->getProduct(), intval($qty));
        }
        $parentOrderShippingMethod = $parentOrder->getShippingMethod();
        if ($parentOrderShippingMethod == null || empty($parentOrderShippingMethod)) {
            $parentOrderShippingMethod = self::DEFAULT_SHIPPING_METHOD;
        }
        $quote->getBillingAddress()->addData($parentOrder->getBillingAddress()->getData());
        $quote->getShippingAddress()->addData($parentOrder->getShippingAddress()->getData());
        $shippingAddress = $quote->getShippingAddress();
        $shippingAddress->setCollectShippingRates(true)
            ->collectShippingRates()
            ->setShippingMethod($parentOrderShippingMethod);
        $quote->setPaymentMethod(self::CHILD_ORDER_PAYMENT_METHOD);
        $quote->setInventoryProcessed(false);
        $quote->getPayment()->importData(['method' => self::CHILD_ORDER_PAYMENT_METHOD]);
        $quote->setChildOrderQuote(1);
        $quote->save();
        $quote->collectTotals();
        $quote->setReservedOrderId($parentOrder->getIncrementId()." - ".$countryId);
        $quote = $this->cartRepositoryInterface->get($quote->getId());
        try {
             $this->cartManagementInterface->placeOrder($quote->getId());
        } catch (\Exception $exception) {
        } finally {
            $quote->setIsActive(false)->save();
        }
    }

    /**
     * @param Order $order
     * @return array
     * @description this function returns an array of items grouped by country_of_manufacture attribute
     */
    protected function groupItemsByCountry(Order $order)
    {
        $itemsByCountry = [];
        foreach ($order->getAllItems() as $item) {
            $countryId = $item->getProduct()->getData('country_of_manufacture');
            if ($countryId == "" || empty($countryId)){
                continue;
            }
            $itemsByCountry[$countryId][] = $item;
        }
        return $itemsByCountry;
    }
    /**
     * @param Order $order
     * @return bool
     * @description Split if there are items with different countries
     */
    protected function shouldSplitOrder(Order $order)
    {
        $uniqueCountries = array_unique($this->getCountryIds($order));
        return count($uniqueCountries) > 1;
    }

    /**
     * @param Order $order
     * @return array
     * @description Get country ids of all items in the order
     */
    protected function getCountryIds(Order $order)
    {
        $countryIds = [];
        foreach ($order->getAllItems() as $item) {
            $countryIds[] = $item->getProduct()->getData('country_of_manufacture');
        }
        return $countryIds;
    }
}
