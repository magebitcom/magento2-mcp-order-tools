<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\CreditMemo;

use Magebit\McpOrderTools\Api\CreditMemoFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\CastsScalars;
use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Credit memo identity slice.
 */
class IdentityResolver implements CreditMemoFieldResolverInterface
{
    use CastsScalars;

    public const KEY = 'identity';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 10;
    }

    /** @inheritDoc */
    public function resolve(CreditmemoInterface $creditMemo, array $args): array
    {
        return [
            'entity_id' => (int) $creditMemo->getEntityId(),
            'increment_id' => (string) $creditMemo->getIncrementId(),
            'order_id' => (int) $creditMemo->getOrderId(),
            'store_id' => (int) $creditMemo->getStoreId(),
            'state' => $this->asInt($creditMemo->getState()),
            'created_at' => (string) $creditMemo->getCreatedAt(),
            'updated_at' => (string) $creditMemo->getUpdatedAt(),
        ];
    }
}
