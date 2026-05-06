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
use Magebit\Mcp\Model\Tool\Schema\Builder\ArrayBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\BooleanBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\NumberBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magebit\McpOrderTools\Model\Tool\Helper\LineItemValidator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterface;
use Magento\Sales\Api\Data\ShipmentCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\ShipOrderInterface;

/**
 * MCP write tool `sales.order.shipment.create` — wrapper over
 * {@see ShipOrderInterface}.
 */
class ShipmentCreate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'sales.order.shipment.create';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_shipment_create';

    /**
     * @param EntityFinder $entityFinder
     * @param ShipOrderInterface $shipOrder
     * @param ShipmentItemCreationInterfaceFactory $itemFactory
     * @param ShipmentTrackCreationInterfaceFactory $trackFactory
     * @param ShipmentCommentCreationInterfaceFactory $commentFactory
     * @param LineItemValidator $lineItemValidator
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentItemCreationInterfaceFactory $itemFactory,
        private readonly ShipmentTrackCreationInterfaceFactory $trackFactory,
        private readonly ShipmentCommentCreationInterfaceFactory $commentFactory,
        private readonly LineItemValidator $lineItemValidator
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
        return 'Ship an Order';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Create a shipment for an order, optionally with tracking '
            . 'numbers. Partial shipments via `items: [{item_id, qty}]`. '
            . 'The tool trusts Magento to reject qty overshoots.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('order_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('order_increment_id', fn (StringBuilder $s) => $s->minLength(1))
            ->array('items', fn (ArrayBuilder $a) => $a
                ->ofObjects(fn (ObjectBuilder $o) => $o
                    ->integer('item_id', fn (IntegerBuilder $i) => $i->minimum(1)->required())
                    ->number('qty', fn (NumberBuilder $n) => $n->exclusiveMinimum(0)->required())
                )
            )
            ->array('tracks', fn (ArrayBuilder $a) => $a
                ->ofObjects(fn (ObjectBuilder $o) => $o
                    ->string('carrier_code', fn (StringBuilder $s) => $s->minLength(1)->required())
                    ->string('title', fn (StringBuilder $s) => $s->minLength(1)->required())
                    ->string('track_number', fn (StringBuilder $s) => $s->minLength(1)->required())
                )
            )
            ->boolean('notify', fn (BooleanBuilder $b) => $b)
            ->object('comment', fn (ObjectBuilder $o) => $o
                ->string('text', fn (StringBuilder $s) => $s->required())
                ->boolean('is_visible_on_front', fn (BooleanBuilder $b) => $b)
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
        return 'Magento_Sales::ship';
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

        $items = $this->buildItems($arguments['items'] ?? null);
        $tracks = $this->buildTracks($arguments['tracks'] ?? null);
        $notify = (bool) ($arguments['notify'] ?? false);
        $comment = $this->buildComment($arguments['comment'] ?? null);

        $shipmentId = $this->shipOrder->execute(
            $orderId,
            $items,
            $notify,
            $comment !== null,
            $comment,
            $tracks
        );

        $payload = [
            'shipment_id' => (int) $shipmentId,
            'order_id' => $orderId,
            'track_count' => count($tracks),
            'notified_customer' => $notify,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode shipment payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'order_id' => $orderId,
                'shipment_id' => (int) $shipmentId,
                'track_count' => count($tracks),
            ]
        );
    }

    /**
     * @param mixed $raw
     * @return ShipmentItemCreationInterface[]
     * @throws LocalizedException
     */
    private function buildItems(mixed $raw): array
    {
        $items = [];
        foreach ($this->lineItemValidator->validate($raw) as $row) {
            $item = $this->itemFactory->create();
            $item->setOrderItemId($row['item_id']);
            $item->setQty($row['qty']);
            $items[] = $item;
        }
        return $items;
    }

    /**
     * @param mixed $raw
     * @return ShipmentTrackCreationInterface[]
     * @throws LocalizedException
     */
    private function buildTracks(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new LocalizedException(__('Parameter "tracks" must be an array of objects.'));
        }

        $tracks = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                throw new LocalizedException(__('Each track must be an object.'));
            }
            $carrier = $row['carrier_code'] ?? null;
            $title = $row['title'] ?? null;
            $number = $row['track_number'] ?? null;
            if (!is_string($carrier) || $carrier === ''
                || !is_string($title) || $title === ''
                || !is_string($number) || $number === ''
            ) {
                throw new LocalizedException(
                    __('Each track requires "carrier_code", "title", "track_number".')
                );
            }
            $track = $this->trackFactory->create();
            $track->setCarrierCode($carrier);
            $track->setTitle($title);
            $track->setTrackNumber($number);
            $tracks[] = $track;
        }
        return $tracks;
    }

    /**
     * @param mixed $raw
     * @return ShipmentCommentCreationInterface|null
     */
    private function buildComment(mixed $raw): ?ShipmentCommentCreationInterface
    {
        if (!is_array($raw)) {
            return null;
        }
        $comment = $this->commentFactory->create();
        $comment->setComment((string) ($raw['text'] ?? ''));
        $comment->setIsVisibleOnFront((int) (bool) ($raw['is_visible_on_front'] ?? false));
        return $comment;
    }
}
