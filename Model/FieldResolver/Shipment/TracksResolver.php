<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Shipment;

use Magebit\McpOrderTools\Api\ShipmentFieldResolverInterface;
use Magento\Sales\Api\Data\ShipmentInterface;

/**
 * Shipment tracking records — carrier, title, tracking number.
 */
class TracksResolver implements ShipmentFieldResolverInterface
{
    public const KEY = 'tracks';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 30;
    }

    /** @inheritDoc */
    public function resolve(ShipmentInterface $shipment, array $args): array
    {
        $rows = [];
        foreach ($shipment->getTracks() as $track) {
            $rows[] = [
                'entity_id' => (int) $track->getEntityId(),
                'carrier_code' => (string) $track->getCarrierCode(),
                'title' => (string) $track->getTitle(),
                'track_number' => (string) $track->getTrackNumber(),
                'created_at' => (string) $track->getCreatedAt(),
            ];
        }
        return $rows;
    }
}
