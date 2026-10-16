<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

/**
 * Rollback: delete only the `MCPSEC000001` order. Items, addresses, payment and comments go with it.
 */

use Magebit\Mcp\Test\Api\Security\InjectionPayload;
use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\TestFramework\Helper\Bootstrap;

$om = Bootstrap::getObjectManager();
/** @var Registry $registry */
$registry = $om->get(Registry::class);
$registry->unregister('isSecureArea');
$registry->register('isSecureArea', true);

/** @var Order $order */
$order = $om->create(Order::class);
$order->loadByIncrementId(InjectionPayload::ORDER_INCREMENT_ID);
if ($order->getId()) {
    /** @var OrderRepositoryInterface $orderRepository */
    $orderRepository = $om->get(OrderRepositoryInterface::class);
    $orderRepository->delete($order);
}

$registry->unregister('isSecureArea');
$registry->register('isSecureArea', false);
