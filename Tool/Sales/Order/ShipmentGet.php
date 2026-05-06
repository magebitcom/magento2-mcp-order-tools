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
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Api\ShipmentFieldResolverInterface;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magebit\Mcp\Model\Util\ResolverPipeline;
use Magento\Framework\Exception\LocalizedException;

/**
 * MCP tool `sales.order.shipment.get` — fetch one shipment by id or increment id.
 */
class ShipmentGet implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.shipment.get';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_shipment_get';

    /**
     * @param EntityFinder $entityFinder
     * @param ResolverPipeline $pipeline
     * @param ShipmentFieldResolverInterface[] $fieldResolvers
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ResolverPipeline $pipeline,
        private readonly array $fieldResolvers = []
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
        return 'Get Shipment';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Fetch a single shipment by its numeric id or increment id.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('shipment_id', fn (IntegerBuilder $i) => $i
                ->minimum(1)
                ->description('Numeric sales_shipment.entity_id.')
            )
            ->string('shipment_increment_id', fn (StringBuilder $s) => $s
                ->minLength(1)
                ->description('Shipment increment id.')
            )
            ->array('fields', fn (ArrayBuilder $a) => $a->ofStrings())
            ->array('exclude', fn (ArrayBuilder $a) => $a->ofStrings())
            ->toArray();
    }

    /** @inheritDoc */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /** @inheritDoc */
    public function getWriteMode(): WriteMode
    {
        return WriteMode::READ;
    }

    /** @inheritDoc */
    public function getConfirmationRequired(): bool
    {
        return false;
    }

    /** @inheritDoc */
    public function execute(array $arguments): ToolResultInterface
    {
        $shipment = $this->entityFinder->shipmentFrom($arguments);

        $response = [];
        foreach ($this->pipeline->plan($this->fieldResolvers, $arguments) as $resolver) {
            $response[$resolver->getKey()] = $resolver->resolve($shipment, $arguments);
        }

        $json = json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode shipment as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'entity_id' => (int) $shipment->getEntityId(),
                'increment_id' => (string) $shipment->getIncrementId(),
                'order_id' => (int) $shipment->getOrderId(),
            ]
        );
    }
}
