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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderManagementInterface;

/**
 * MCP write tool `sales.order.unhold`.
 *
 * Non-destructive (reverse of `sales.order.hold`); no confirmation prompt.
 */
class Unhold implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'sales.order.unhold';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_unhold';

    /**
     * @param EntityFinder $entityFinder
     * @param OrderManagementInterface $orderManagement
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly OrderManagementInterface $orderManagement
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
        return 'Release Order Hold';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Release an order that was placed on hold.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('order_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('order_increment_id', fn (StringBuilder $s) => $s->minLength(1))
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
        return 'Magento_Sales::unhold';
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

        $unheld = $this->orderManagement->unHold($orderId);

        $payload = [
            'order_id' => $orderId,
            'unheld' => $unheld,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode unhold result as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: $payload
        );
    }
}
