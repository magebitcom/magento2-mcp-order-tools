<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Order;

use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magebit\McpOrderTools\Model\Payment\AdditionalInformationFilter;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;

/**
 * Payment-method slice. `additional_information` is passed through a response-
 * side allowlist filter ({@see AdditionalInformationFilter}) so PSP secrets do
 * not leak to the MCP client; only operator-allowlisted keys are returned. Note
 * this is distinct from the base-module PII redactor, which sanitizes the audit
 * row, NOT this response payload (see `docs/EXTENDING.md`).
 */
class PaymentResolver implements OrderFieldResolverInterface
{
    public const KEY = 'payment';

    /**
     * @param AdditionalInformationFilter $additionalInformationFilter
     */
    public function __construct(
        private readonly AdditionalInformationFilter $additionalInformationFilter
    ) {
    }

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
            'additional_information' => $this->additionalInformationFilter->filter(
                $payment->getAdditionalInformation()
            ),
        ];
    }
}
