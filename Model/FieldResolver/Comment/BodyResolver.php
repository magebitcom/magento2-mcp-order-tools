<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Comment;

use Magebit\McpOrderTools\Api\OrderCommentFieldResolverInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;

/**
 * Comment text + timestamp.
 */
class BodyResolver implements OrderCommentFieldResolverInterface
{
    public const KEY = 'body';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 20;
    }

    /** @inheritDoc */
    public function resolve(OrderStatusHistoryInterface $comment, array $args): array
    {
        return [
            'comment' => (string) $comment->getComment(),
            'created_at' => (string) $comment->getCreatedAt(),
        ];
    }
}
