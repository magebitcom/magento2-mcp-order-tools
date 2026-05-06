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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\ObjectBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentTrackInterfaceFactory;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;

/**
 * MCP write tool `sales.order.shipment.track.add` — attach tracking numbers
 * to an existing shipment.
 */
class ShipmentTrackAdd implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'sales.order.shipment.track.add';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_shipment_track_add';

    /**
     * @param EntityFinder $entityFinder
     * @param ShipmentTrackRepositoryInterface $trackRepository
     * @param ShipmentTrackInterfaceFactory $trackFactory
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly ShipmentTrackRepositoryInterface $trackRepository,
        private readonly ShipmentTrackInterfaceFactory $trackFactory
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
        return 'Add Tracking Numbers';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Attach one or more tracking numbers to an existing shipment '
            . 'without creating a new one. Each track requires `carrier_code`, '
            . '`title`, and `track_number`.';
    }

    /** @inheritDoc */
    public function getInputSchema(): array
    {
        return Schema::object()
            ->integer('shipment_id', fn (IntegerBuilder $i) => $i->minimum(1))
            ->string('shipment_increment_id', fn (StringBuilder $s) => $s->minLength(1))
            ->array('tracks', fn (ArrayBuilder $a) => $a
                ->ofObjects(fn (ObjectBuilder $o) => $o
                    ->string('carrier_code', fn (StringBuilder $s) => $s->minLength(1)->required())
                    ->string('title', fn (StringBuilder $s) => $s->minLength(1)->required())
                    ->string('track_number', fn (StringBuilder $s) => $s->minLength(1)->required())
                )
                ->minItems(1)
                ->required()
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
        return false;
    }

    /** @inheritDoc */
    public function execute(array $arguments): ToolResultInterface
    {
        $shipment = $this->entityFinder->shipmentFrom($arguments);
        $shipmentId = (int) $shipment->getEntityId();

        $tracksRaw = $arguments['tracks'] ?? null;
        if (!is_array($tracksRaw) || $tracksRaw === []) {
            throw new LocalizedException(__('Parameter "tracks" must be a non-empty array.'));
        }

        $added = [];
        foreach ($tracksRaw as $row) {
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
            $track->setOrderId((int) $shipment->getOrderId());
            $track->setParentId($shipmentId);
            $track->setCarrierCode($carrier);
            $track->setTitle($title);
            $track->setTrackNumber($number);
            $saved = $this->trackRepository->save($track);
            $added[] = [
                'entity_id' => (int) $saved->getEntityId(),
                'carrier_code' => $carrier,
                'title' => $title,
                'track_number' => $number,
            ];
        }

        $payload = [
            'shipment_id' => $shipmentId,
            'added' => $added,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode shipment track payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'shipment_id' => $shipmentId,
                'track_count' => count($added),
            ]
        );
    }
}
