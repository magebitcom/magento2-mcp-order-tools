<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

/**
 * Fixture: one guest order `MCPSEC000001` whose visible status comment carries the injection text.
 */

use Magebit\Mcp\Test\Api\Security\InjectionPayload;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\TestFramework\Helper\Bootstrap;

// Remove a leftover from an interrupted run first.
require __DIR__ . '/mcpsec_injection_order_rollback.php';

$om = Bootstrap::getObjectManager();
$addressData = [
    'firstname' => 'Mcpsec',
    'lastname' => 'Fixture',
    'street' => 'Fixture street 1',
    'city' => 'Los Angeles',
    'region' => 'CA',
    'region_id' => '12',
    'postcode' => '11111',
    'country_id' => 'US',
    'telephone' => '11111111',
    'email' => InjectionPayload::CUSTOMER_EMAIL,
];

/** @var OrderAddress $billing */
$billing = $om->create(OrderAddress::class, ['data' => $addressData]);
$billing->setAddressType('billing');
/** @var OrderAddress $shipping */
$shipping = $om->create(OrderAddress::class, ['data' => $addressData]);
$shipping->setAddressType('shipping');
/** @var Payment $payment */
$payment = $om->create(Payment::class);
$payment->setMethod('checkmo');
/** @var OrderItem $item */
$item = $om->create(OrderItem::class);
$item->setSku(InjectionPayload::PRODUCT_SKU)
    ->setName('MCPSEC injection item')
    ->setProductType('simple')
    ->setQtyOrdered(1)
    ->setPrice(10)
    ->setBasePrice(10)
    ->setRowTotal(10)
    ->setBaseRowTotal(10);

/** @var Order $order */
$order = $om->create(Order::class);
$order->setIncrementId(InjectionPayload::ORDER_INCREMENT_ID)
    ->setState(Order::STATE_PROCESSING)
    ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING))
    ->setStoreId(1)
    ->setSubtotal(10)
    ->setBaseSubtotal(10)
    ->setGrandTotal(10)
    ->setBaseGrandTotal(10)
    ->setOrderCurrencyCode('USD')
    ->setBaseCurrencyCode('USD')
    ->setCustomerIsGuest(true)
    ->setCustomerFirstname('Mcpsec')
    ->setCustomerLastname('Fixture')
    ->setCustomerEmail(InjectionPayload::CUSTOMER_EMAIL)
    ->setBillingAddress($billing)
    ->setShippingAddress($shipping)
    ->addItem($item)
    ->setPayment($payment);
$om->get(OrderRepositoryInterface::class)->save($order);

$history = $om->get(OrderStatusHistoryInterfaceFactory::class)->create();
$history->setParentId((int) $order->getEntityId())
    ->setComment(InjectionPayload::TEXT)
    ->setIsVisibleOnFront(1)
    ->setIsCustomerNotified(0)
    ->setEntityName('order');
$om->get(OrderStatusHistoryRepositoryInterface::class)->save($history);
