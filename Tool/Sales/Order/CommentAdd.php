<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Tool\Sales\Order;

use Magebit\Mcp\Api\ToolInterface;
use Magebit\Mcp\Api\ToolResultInterface;
use Magebit\Mcp\Api\UnderlyingAclAwareInterface;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderManagementInterface;

/**
 * MCP write tool `sales.order.comment.add`.
 */
class CommentAdd implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'sales.order.comment.add';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_comment_add';

    /**
     * @param EntityFinder $entityFinder
     * @param OrderManagementInterface $orderManagement
     * @param OrderStatusHistoryInterfaceFactory $historyFactory
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderStatusHistoryInterfaceFactory $historyFactory
    ) {
    }

    /** @inheritDoc */
    public function getName(): string
    {
        return self::TOOL_NAME;
    }

    /** @inheritDoc */
    public function getTitle(): string
    {
        return 'Add Order Comment';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Append a comment to an order. Optional `status` performs a '
            . 'controlled status transition (rejected by Magento if invalid '
            . 'for the current state). `is_visible_on_front` exposes the '
            . 'comment to the customer; `is_customer_notified` triggers an '
            . 'email.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('order_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('order_increment_id', fn (StringBuilder $s) => $s->minLength(1))
            ->string('text', fn (StringBuilder $s) => $s->minLength(1)->required())
            ->boolean('is_visible_on_front', fn (BooleanBuilder $b) => $b)
            ->boolean('is_customer_notified', fn (BooleanBuilder $b) => $b)
            ->string('status', fn (StringBuilder $s) => $s)
            ->toArray();
    }

    /** @inheritDoc */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /** @inheritDoc */
    public function getUnderlyingAclResource(): ?string
    {
        return 'Magento_Sales::comment';
    }

    /** @inheritDoc */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::WRITE;
    }

    /** @inheritDoc */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function execute(array $arguments): ToolResultInterface
    {
        $order = $this->entityFinder->orderFrom($arguments);
        $orderId = (int) $order->getEntityId();

        $text = $arguments['text'] ?? null;
        if (!is_string($text) || $text === '') {
            throw new LocalizedException(__('Parameter "text" is required.'));
        }

        $history = $this->historyFactory->create();
        $history->setParentId($orderId);
        $history->setComment($text);
        $history->setIsVisibleOnFront((int) (bool) ($arguments['is_visible_on_front'] ?? false));
        $history->setIsCustomerNotified((int) (bool) ($arguments['is_customer_notified'] ?? false));
        if (isset($arguments['status']) && is_string($arguments['status']) && $arguments['status'] !== '') {
            $history->setStatus($arguments['status']);
        }

        $ok = $this->orderManagement->addComment($orderId, $history);

        $payload = [
            'order_id' => $orderId,
            'added' => $ok,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode comment result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: $payload
        );
    }
}
