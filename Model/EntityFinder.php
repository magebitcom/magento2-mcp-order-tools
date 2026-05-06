<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;

/**
 * Resolve sales entities from MCP tool arguments, accepting either a numeric
 * primary key or an increment id. Exactly one of the two must be supplied.
 */
class EntityFinder
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param CreditmemoRepositoryInterface $creditMemoRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly CreditmemoRepositoryInterface $creditMemoRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * Accepts `entity_id` / `order_id` or `increment_id` / `order_increment_id`.
     *
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return OrderInterface
     * @throws LocalizedException
     */
    public function orderFrom(array $args): OrderInterface
    {
        $id = $this->pickNumeric($args, ['entity_id', 'order_id']);
        $incrementId = $this->pickString($args, ['increment_id', 'order_increment_id']);
        $this->requireOneOf($id, $incrementId, 'entity_id/order_id', 'increment_id/order_increment_id');

        if ($id !== null) {
            try {
                return $this->orderRepository->get($id);
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Order %1 not found.', $id), $e);
            }
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();
        $items = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($items);
        if (!$order instanceof OrderInterface) {
            throw new LocalizedException(__('Order %1 not found.', (string) $incrementId));
        }
        return $order;
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return InvoiceInterface
     * @throws LocalizedException
     */
    public function invoiceFrom(array $args): InvoiceInterface
    {
        $id = $this->pickNumeric($args, ['invoice_id', 'entity_id']);
        $incrementId = $this->pickString($args, ['invoice_increment_id', 'increment_id']);
        $this->requireOneOf($id, $incrementId, 'invoice_id', 'invoice_increment_id');

        if ($id !== null) {
            try {
                return $this->invoiceRepository->get($id);
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Invoice %1 not found.', $id), $e);
            }
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(InvoiceInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();
        $items = $this->invoiceRepository->getList($criteria)->getItems();
        $invoice = reset($items);
        if (!$invoice instanceof InvoiceInterface) {
            throw new LocalizedException(__('Invoice %1 not found.', (string) $incrementId));
        }
        return $invoice;
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return ShipmentInterface
     * @throws LocalizedException
     */
    public function shipmentFrom(array $args): ShipmentInterface
    {
        $id = $this->pickNumeric($args, ['shipment_id', 'entity_id']);
        $incrementId = $this->pickString($args, ['shipment_increment_id', 'increment_id']);
        $this->requireOneOf($id, $incrementId, 'shipment_id', 'shipment_increment_id');

        if ($id !== null) {
            try {
                return $this->shipmentRepository->get($id);
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Shipment %1 not found.', $id), $e);
            }
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(ShipmentInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();
        $items = $this->shipmentRepository->getList($criteria)->getItems();
        $shipment = reset($items);
        if (!$shipment instanceof ShipmentInterface) {
            throw new LocalizedException(__('Shipment %1 not found.', (string) $incrementId));
        }
        return $shipment;
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @return CreditmemoInterface
     * @throws LocalizedException
     */
    public function creditMemoFrom(array $args): CreditmemoInterface
    {
        $id = $this->pickNumeric($args, ['credit_memo_id', 'entity_id']);
        $incrementId = $this->pickString($args, ['credit_memo_increment_id', 'increment_id']);
        $this->requireOneOf($id, $incrementId, 'credit_memo_id', 'credit_memo_increment_id');

        if ($id !== null) {
            try {
                return $this->creditMemoRepository->get($id);
            } catch (NoSuchEntityException $e) {
                throw new LocalizedException(__('Credit memo %1 not found.', $id), $e);
            }
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(CreditmemoInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();
        $items = $this->creditMemoRepository->getList($criteria)->getItems();
        $memo = reset($items);
        if (!$memo instanceof CreditmemoInterface) {
            throw new LocalizedException(__('Credit memo %1 not found.', (string) $incrementId));
        }
        return $memo;
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param array $candidates
     * @phpstan-param array<int, string> $candidates
     * @return int|null
     */
    private function pickNumeric(array $args, array $candidates): ?int
    {
        foreach ($candidates as $key) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $value = $args[$key];
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }
        return null;
    }

    /**
     * @param array $args
     * @phpstan-param array<string, mixed> $args
     * @param array $candidates
     * @phpstan-param array<int, string> $candidates
     * @return string|null
     */
    private function pickString(array $args, array $candidates): ?string
    {
        foreach ($candidates as $key) {
            if (!array_key_exists($key, $args)) {
                continue;
            }
            $value = $args[$key];
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param int|null $id
     * @param string|null $incrementId
     * @param string $idLabel
     * @param string $incrementIdLabel
     * @return void
     * @throws LocalizedException
     */
    private function requireOneOf(
        ?int $id,
        ?string $incrementId,
        string $idLabel,
        string $incrementIdLabel
    ): void {
        if ($id === null && $incrementId === null) {
            throw new LocalizedException(
                __('One of "%1" or "%2" is required.', $idLabel, $incrementIdLabel)
            );
        }
        if ($id !== null && $incrementId !== null) {
            throw new LocalizedException(
                __('Provide either "%1" or "%2", not both.', $idLabel, $incrementIdLabel)
            );
        }
    }
}
