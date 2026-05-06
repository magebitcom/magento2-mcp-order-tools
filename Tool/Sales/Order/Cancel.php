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
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderManagementInterface;

/**
 * MCP write tool `sales.order.cancel`.
 */
class Cancel implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'sales.order.cancel';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_cancel';

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
        return 'Cancel Order';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Cancel an order that has not yet been fulfilled. Optional '
            . 'comment is appended as a status-history entry.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('order_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('order_increment_id', fn (StringBuilder $s) => $s->minLength(1))
            ->object('comment', fn (ObjectBuilder $o) => $o
                ->string('text', fn (StringBuilder $s) => $s->required())
                ->boolean('is_visible_on_front', fn (BooleanBuilder $b) => $b)
                ->boolean('is_customer_notified', fn (BooleanBuilder $b) => $b)
            )
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
        return 'Magento_Sales::cancel';
    }

    /** @inheritDoc */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::WRITE;
    }

    /** @inheritDoc */
    public function getConfirmationRequired(): bool
    {
        return true;
    }

    /** @inheritDoc */
    public function execute(array $arguments): ToolResultInterface
    {
        $order = $this->entityFinder->orderFrom($arguments);
        $orderId = (int) $order->getEntityId();

        $cancelled = $this->orderManagement->cancel($orderId);

        if (isset($arguments['comment']) && is_array($arguments['comment'])) {
            $history = $this->historyFactory->create();
            $history->setParentId($orderId);
            $history->setComment((string) ($arguments['comment']['text'] ?? ''));
            $history->setIsVisibleOnFront(
                (int) (bool) ($arguments['comment']['is_visible_on_front'] ?? false)
            );
            $history->setIsCustomerNotified(
                (int) (bool) ($arguments['comment']['is_customer_notified'] ?? false)
            );
            $this->orderManagement->addComment($orderId, $history);
        }

        $payload = [
            'order_id' => $orderId,
            'cancelled' => $cancelled,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode cancel result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: $payload
        );
    }
}
