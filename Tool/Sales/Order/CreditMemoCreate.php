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
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterface;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\RefundOrderInterface;

/**
 * MCP write tool `sales.order.credit_memo.create`. `is_online=true` triggers
 * a real gateway refund via {@see CreditmemoManagementInterface::refund()}.
 */
class CreditMemoCreate implements ToolInterface, UnderlyingAclAwareInterface
{
    public const TOOL_NAME = 'sales.order.credit_memo.create';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_credit_memo_create';

    /**
     * @param EntityFinder $entityFinder
     * @param RefundOrderInterface $refundOrder
     * @param CreditmemoManagementInterface $creditMemoManagement
     * @param CreditmemoRepositoryInterface $creditMemoRepository
     * @param CreditmemoItemCreationInterfaceFactory $itemFactory
     * @param CreditmemoCommentCreationInterfaceFactory $commentFactory
     * @param CreditmemoCreationArgumentsInterfaceFactory $argsFactory
     * @param LineItemValidator $lineItemValidator
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly RefundOrderInterface $refundOrder,
        private readonly CreditmemoManagementInterface $creditMemoManagement,
        private readonly CreditmemoRepositoryInterface $creditMemoRepository,
        private readonly CreditmemoItemCreationInterfaceFactory $itemFactory,
        private readonly CreditmemoCommentCreationInterfaceFactory $commentFactory,
        private readonly CreditmemoCreationArgumentsInterfaceFactory $argsFactory,
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
        return 'Credit Memo (Refund) an Order';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Create a credit memo for an order. `is_online=false` (default) '
            . 'produces an offline refund — the DB is updated but the payment '
            . 'gateway is not touched. `is_online=true` calls the gateway — '
            . 'use with extreme care. Partial refunds via `items`; '
            . '`adjustment_positive` adds to the refund, `adjustment_negative` '
            . 'subtracts.';
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
            ->number('adjustment_positive', fn (NumberBuilder $n) => $n->minimum(0))
            ->number('adjustment_negative', fn (NumberBuilder $n) => $n->minimum(0))
            ->number('shipping_amount', fn (NumberBuilder $n) => $n->minimum(0))
            ->boolean('is_online', fn (BooleanBuilder $b) => $b)
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
        return 'Magento_Sales::creditmemo';
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
        $notify = (bool) ($arguments['notify'] ?? false);
        $isOnline = (bool) ($arguments['is_online'] ?? false);
        $comment = $this->buildComment($arguments['comment'] ?? null);
        $extra = $this->buildArguments($arguments);

        $memoId = (int) $this->refundOrder->execute(
            $orderId,
            $items,
            $notify,
            $comment !== null,
            $comment,
            $extra
        );

        if ($isOnline) {
            // RefundOrderInterface always runs offline; trigger the gateway
            // refund explicitly on the memo we just created.
            $memo = $this->creditMemoRepository->get($memoId);
            $this->creditMemoManagement->refund($memo, true);
        }

        $payload = [
            'credit_memo_id' => $memoId,
            'order_id' => $orderId,
            'is_online' => $isOnline,
            'notified_customer' => $notify,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode credit memo payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'order_id' => $orderId,
                'credit_memo_id' => $memoId,
                'is_online' => $isOnline,
            ]
        );
    }

    /**
     * @param mixed $raw
     * @return CreditmemoItemCreationInterface[]
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
     * @return CreditmemoCommentCreationInterface|null
     */
    private function buildComment(mixed $raw): ?CreditmemoCommentCreationInterface
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

    /**
     * @param array $arguments
     * @phpstan-param array<string, mixed> $arguments
     * @return CreditmemoCreationArgumentsInterface|null
     */
    private function buildArguments(array $arguments): ?CreditmemoCreationArgumentsInterface
    {
        $positive = $arguments['adjustment_positive'] ?? null;
        $negative = $arguments['adjustment_negative'] ?? null;
        $shipping = $arguments['shipping_amount'] ?? null;

        if ($positive === null && $negative === null && $shipping === null) {
            return null;
        }

        $args = $this->argsFactory->create();
        if (is_numeric($positive)) {
            $args->setAdjustmentPositive((float) $positive);
        }
        if (is_numeric($negative)) {
            $args->setAdjustmentNegative((float) $negative);
        }
        if (is_numeric($shipping)) {
            $args->setShippingAmount((float) $shipping);
        }
        return $args;
    }
}
