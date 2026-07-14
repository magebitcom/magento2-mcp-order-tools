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
use Magento\Sales\Api\Data\InvoiceCommentCreationInterface;
use Magento\Sales\Api\Data\InvoiceCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceItemCreationInterface;
use Magento\Sales\Api\Data\InvoiceItemCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;

/**
 * MCP write tool `sales.order.invoice.create` — wrapper over
 * {@see InvoiceOrderInterface}.
 */
class InvoiceCreate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'sales.order.invoice.create';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_invoice_create';

    /**
     * @param EntityFinder $entityFinder
     * @param InvoiceOrderInterface $invoiceOrder
     * @param InvoiceItemCreationInterfaceFactory $itemFactory
     * @param InvoiceCommentCreationInterfaceFactory $commentFactory
     * @param LineItemValidator $lineItemValidator
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly InvoiceItemCreationInterfaceFactory $itemFactory,
        private readonly InvoiceCommentCreationInterfaceFactory $commentFactory,
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
        return 'Invoice an Order';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Create an invoice for an order. If `items` is omitted the '
            . 'invoice covers every still-invoiceable line at its full '
            . 'remaining qty. `capture=true` (default) charges the order '
            . 'payment record; `capture=false` produces an offline invoice. '
            . 'Optional comment and customer-notify flag.';
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
            ->boolean('capture', fn (BooleanBuilder $b) => $b)
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
        return 'Magento_Sales::invoice';
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
        $capture = (bool) ($arguments['capture'] ?? true);
        $notify = (bool) ($arguments['notify'] ?? false);
        $comment = $this->buildComment($arguments['comment'] ?? null);

        $invoiceId = $this->invoiceOrder->execute(
            $orderId,
            $capture,
            $items,
            $notify,
            $comment !== null,
            $comment
        );

        $payload = [
            'invoice_id' => (int) $invoiceId,
            'order_id' => $orderId,
            'captured' => $capture,
            'notified_customer' => $notify,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode invoice payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'order_id' => $orderId,
                'invoice_id' => (int) $invoiceId,
                'captured' => $capture,
            ]
        );
    }

    /**
     * @param mixed $raw
     * @return InvoiceItemCreationInterface[]
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
     * @return InvoiceCommentCreationInterface|null
     */
    private function buildComment(mixed $raw): ?InvoiceCommentCreationInterface
    {
        if (!is_array($raw)) {
            return null;
        }
        $comment = $this->commentFactory->create();
        $text = $raw['text'] ?? '';
        $comment->setComment(is_string($text) ? $text : '');
        $comment->setIsVisibleOnFront((int) (bool) ($raw['is_visible_on_front'] ?? false));
        return $comment;
    }
}
