<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Order;

use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Payment-method slice. `additional_information` is forwarded as-is; PSP-
 * specific keys that contain PII should be added to the base-module PII
 * redactor's `additionalSensitiveKeys` argument (see `docs/EXTENDING.md`).
 */
class PaymentResolver implements OrderFieldResolverInterface
{
    public const KEY = 'payment';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 70;
    }

    /** @inheritDoc */
    public function resolve(OrderInterface $order, array $args): array
    {
        $payment = $order->getPayment();
        if (!$payment instanceof OrderPaymentInterface) {
            return [];
        }
        return [
            'method' => (string) $payment->getMethod(),
            'cc_type' => $payment->getCcType(),
            'cc_last4' => $payment->getCcLast4(),
            'additional_information' => $payment->getAdditionalInformation(),
        ];
    }
}
