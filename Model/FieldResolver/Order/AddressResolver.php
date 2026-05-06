<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model\FieldResolver\Order;

use Magebit\McpOrderTools\Api\OrderFieldResolverInterface;
use Magebit\McpOrderTools\Model\FieldResolver\CastsScalars;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * Billing + shipping address slice. `OrderInterface` only declares
 * `getBillingAddress()`, so we narrow to the concrete `Order` to read the
 * shipping side instead of reaching through extension attributes.
 */
class AddressResolver implements OrderFieldResolverInterface
{
    use CastsScalars;

    public const KEY = 'addresses';

    /** @inheritDoc */
    public function getKey(): string
    {
        return self::KEY;
    }

    /** @inheritDoc */
    public function getSortOrder(): int
    {
        return 40;
    }

    /** @inheritDoc */
    public function resolve(OrderInterface $order, array $args): array
    {
        $shipping = $order instanceof Order ? $order->getShippingAddress() : null;
        return [
            'billing' => $this->format($order->getBillingAddress()),
            'shipping' => $this->format($shipping),
        ];
    }

    /**
     * @param OrderAddressInterface|null $address
     * @return array<string, mixed>|null
     */
    private function format(?OrderAddressInterface $address): ?array
    {
        if ($address === null) {
            return null;
        }
        $streetRaw = $address->getStreet();
        $street = is_array($streetRaw) ? array_values($streetRaw) : [];

        return [
            'firstname' => (string) $address->getFirstname(),
            'lastname' => (string) $address->getLastname(),
            'company' => $address->getCompany(),
            'street' => $street,
            'city' => (string) $address->getCity(),
            'region' => $address->getRegion(),
            'region_id' => $this->asInt($address->getRegionId()),
            'postcode' => $address->getPostcode(),
            'country_id' => (string) $address->getCountryId(),
            'telephone' => $address->getTelephone(),
        ];
    }
}
