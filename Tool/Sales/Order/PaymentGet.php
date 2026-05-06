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
use Magebit\Mcp\Model\Tool\Schema\Builder\IntegerBuilder;
use Magebit\Mcp\Model\Tool\Schema\Builder\StringBuilder;
use Magebit\Mcp\Model\Tool\Schema\Schema;
use Magebit\Mcp\Model\Tool\ToolResult;
use Magebit\Mcp\Model\Tool\WriteMode;
use Magebit\McpOrderTools\Model\EntityFinder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;

/**
 * MCP tool `sales.order.payment` — fetch the payment record + associated
 * transactions for an order.
 */
class PaymentGet implements ToolInterface
{
    public const TOOL_NAME = 'sales.order.payment';
    public const ACL_RESOURCE = 'Magebit_McpOrderTools::tool_sales_order_payment';

    private const TRANSACTION_PAGE_SIZE = 100;

    /**
     * @param EntityFinder $entityFinder
     * @param TransactionRepositoryInterface $transactionRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly EntityFinder $entityFinder,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
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
        return 'Get Order Payment';
    }

    /** @inheritDoc */
    public function getDescription(): string
    {
        return 'Return the payment record attached to an order together with '
            . 'its captured / authorized / refund transactions.';
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
        $order = $this->entityFinder->orderFrom($arguments);
        $payment = $order->getPayment();

        $paymentRow = $payment instanceof OrderPaymentInterface
            ? [
                'entity_id' => (int) $payment->getEntityId(),
                'method' => (string) $payment->getMethod(),
                'cc_type' => $payment->getCcType(),
                'cc_last4' => $payment->getCcLast4(),
                'additional_information' => $payment->getAdditionalInformation(),
            ]
            : null;

        $transactions = [];
        if ($payment instanceof OrderPaymentInterface && $payment->getEntityId() !== null) {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter(TransactionInterface::PAYMENT_ID, (int) $payment->getEntityId())
                ->setPageSize(self::TRANSACTION_PAGE_SIZE)
                ->create();
            foreach ($this->transactionRepository->getList($criteria)->getItems() as $txn) {
                if (!$txn instanceof TransactionInterface) {
                    continue;
                }
                $transactions[] = [
                    'transaction_id' => (int) $txn->getTransactionId(),
                    'parent_id' => $txn->getParentId() !== null ? (int) $txn->getParentId() : null,
                    'txn_id' => (string) $txn->getTxnId(),
                    'txn_type' => (string) $txn->getTxnType(),
                    'is_closed' => (bool) $txn->getIsClosed(),
                    'created_at' => (string) $txn->getCreatedAt(),
                ];
            }
        }

        $payload = [
            'payment' => $paymentRow,
            'transactions' => $transactions,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new LocalizedException(__('Failed to encode payment payload as JSON.'));
        }

        return new ToolResult(
            content: [['type' => 'text', 'text' => $json]],
            auditSummary: [
                'order_id' => (int) $order->getEntityId(),
                'method' => $paymentRow['method'] ?? null,
                'transaction_count' => count($transactions),
            ]
        );
    }
}
