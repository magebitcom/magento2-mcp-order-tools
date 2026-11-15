<?php
/**
 * @author    Magebit <info@magebit.com>
 * @copyright Copyright (c) Magebit, Ltd. (https://magebit.com)
 * @license   MIT
 */
declare(strict_types=1);

namespace Magebit\McpOrderTools\Test\Unit\Tool\Sales\Order;

use Magebit\McpOrderTools\Model\EntityFinder;
use Magebit\McpOrderTools\Model\Tool\Helper\LineItemValidator;
use Magebit\McpOrderTools\Tool\Sales\Order\CreditMemoCreate;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterface;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceSearchResultInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CreditMemoCreateTest extends TestCase
{
    private const ORDER_ID = 42;

    /**
     * @var EntityFinder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private EntityFinder&MockObject $entityFinder;

    /**
     * @var RefundOrderInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private RefundOrderInterface&MockObject $refundOrder;

    /**
     * @var RefundInvoiceInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private RefundInvoiceInterface&MockObject $refundInvoice;

    /**
     * @var InvoiceRepositoryInterface&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private InvoiceRepositoryInterface&MockObject $invoiceRepository;

    /**
     * @var SearchCriteriaBuilder&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private SearchCriteriaBuilder&MockObject $criteriaBuilder;

    /**
     * @var Payment&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private Payment&MockObject $payment;

    /**
     * @var CreditmemoItemCreationInterfaceFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CreditmemoItemCreationInterfaceFactory&MockObject $itemFactory;

    /**
     * @var CreditmemoCommentCreationInterfaceFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CreditmemoCommentCreationInterfaceFactory&MockObject $commentFactory;

    /**
     * @var CreditmemoCreationArgumentsInterfaceFactory&MockObject
     */
    // phpcs:ignore Magento2.Commenting.ClassPropertyPHPDocFormatting
    private CreditmemoCreationArgumentsInterfaceFactory&MockObject $argsFactory;

    /**
     * @var array<int, array{0: string, 1: mixed}>
     */
    private array $filterCalls = [];

    protected function setUp(): void
    {
        $this->givenPaymentMethod(true, false);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(self::ORDER_ID);
        $order->method('getPayment')->willReturnCallback(fn () => $this->payment);

        $this->entityFinder = $this->createMock(EntityFinder::class);
        $this->entityFinder->method('orderFrom')->willReturn($order);

        $this->refundOrder = $this->createMock(RefundOrderInterface::class);
        $this->refundInvoice = $this->createMock(RefundInvoiceInterface::class);
        $this->invoiceRepository = $this->createMock(InvoiceRepositoryInterface::class);

        $this->filterCalls = [];
        $this->criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->criteriaBuilder->method('addFilter')
            ->willReturnCallback(function (string $field, mixed $value) {
                $this->filterCalls[] = [$field, $value];
                return $this->criteriaBuilder;
            });
        $this->criteriaBuilder->method('create')
            ->willReturn($this->createStub(SearchCriteriaInterface::class));

        $this->itemFactory = $this->createMock(CreditmemoItemCreationInterfaceFactory::class);
        $this->commentFactory = $this->createMock(CreditmemoCommentCreationInterfaceFactory::class);
        $this->argsFactory = $this->createMock(CreditmemoCreationArgumentsInterfaceFactory::class);
    }

    public function testOfflineRefundUsesRefundOrder(): void
    {
        $this->refundOrder->expects($this->once())
            ->method('execute')
            ->with(self::ORDER_ID, [], true, false, null, null)
            ->willReturn(7);
        $this->refundInvoice->expects($this->never())->method('execute');

        $payload = $this->execute(['order_id' => self::ORDER_ID, 'notify' => true]);

        $this->assertSame(7, $payload['credit_memo_id']);
        $this->assertFalse($payload['is_online']);
    }

    public function testOnlineRefundUsesRefundInvoiceOnTheCapturedInvoice(): void
    {
        $this->givenOrderInvoices([
            $this->invoice(5, false, 'txn-old'),
            $this->invoice(6, true, ''),
            $this->invoice(9, true, 'txn-capture'),
        ]);
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->once())
            ->method('execute')
            ->with(9, [], true, false, false, null, null)
            ->willReturn(11);

        $payload = $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true]);

        $this->assertContains(['order_id', self::ORDER_ID], $this->filterCalls);
        $this->assertSame(11, $payload['credit_memo_id']);
        $this->assertTrue($payload['is_online']);
    }

    public function testOnlineRefundPassesItemsCommentNotifyAndTotalsToRefundInvoice(): void
    {
        $item = $this->createMock(CreditmemoItemCreationInterface::class);
        $item->expects($this->once())->method('setOrderItemId')->with(3);
        $item->expects($this->once())->method('setQty')->with(2.0);
        $this->itemFactory->method('create')->willReturn($item);

        $comment = $this->createMock(CreditmemoCommentCreationInterface::class);
        $comment->expects($this->once())->method('setComment')->with('Damaged in transit');
        $comment->expects($this->once())->method('setIsVisibleOnFront')->with(1);
        $this->commentFactory->method('create')->willReturn($comment);

        $extra = $this->createMock(CreditmemoCreationArgumentsInterface::class);
        $extra->expects($this->once())->method('setShippingAmount')->with(5.0);
        $extra->expects($this->once())->method('setAdjustmentNegative')->with(1.5);
        $extra->expects($this->never())->method('setAdjustmentPositive');
        $this->argsFactory->method('create')->willReturn($extra);

        $this->givenOrderInvoices([$this->invoice(9, true, 'txn-capture')]);
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->once())
            ->method('execute')
            ->with(9, [$item], true, true, true, $comment, $extra)
            ->willReturn(11);

        $this->execute([
            'order_id' => self::ORDER_ID,
            'is_online' => true,
            'notify' => true,
            'items' => [['item_id' => 3, 'qty' => 2]],
            'comment' => ['text' => 'Damaged in transit', 'is_visible_on_front' => true],
            'shipping_amount' => 5,
            'adjustment_negative' => 1.5,
        ]);
    }

    public function testOnlineAutoSelectionSkipsInvoiceAlreadyUsedForRefund(): void
    {
        $this->givenOrderInvoices([
            $this->invoice(8, true, 'txn-a', self::ORDER_ID, true),
            $this->invoice(9, true, 'txn-b'),
        ]);
        $this->refundInvoice->expects($this->once())
            ->method('execute')
            ->with(9, [], true, false, false, null, null)
            ->willReturn(11);

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true]);
    }

    public function testOnlineAutoSelectionKeepsUsedInvoiceWhenMethodRefundsPartiallyPerInvoice(): void
    {
        $this->givenPaymentMethod(true, true);
        $this->payment->method('getAmountPaid')->willReturn(100.0);
        $this->payment->method('getAmountRefunded')->willReturn(40.0);
        $this->givenOrderInvoices([$this->invoice(8, true, 'txn-a', self::ORDER_ID, true)]);
        $this->refundInvoice->expects($this->once())
            ->method('execute')
            ->with(8, [], true, false, false, null, null)
            ->willReturn(11);

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true]);
    }

    public function testOnlineRefundFailsWhenPaymentMethodCannotRefund(): void
    {
        $this->givenPaymentMethod(false, false);
        $this->givenOrderInvoices([$this->invoice(9, true, 'txn-capture')]);
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no invoice that can be refunded online');

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true]);
    }

    public function testInvoiceIdIsRejectedForOfflineRefund(): void
    {
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('applies only to online refunds');

        $this->execute(['order_id' => self::ORDER_ID, 'invoice_id' => 9]);
    }

    public function testOnlineRefundUsesTheRequestedInvoice(): void
    {
        $this->entityFinder->expects($this->once())
            ->method('invoiceFrom')
            ->with(['invoice_id' => 9])
            ->willReturn($this->invoice(9, true, 'txn-capture'));
        $this->invoiceRepository->expects($this->never())->method('getList');
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->once())
            ->method('execute')
            ->with(9, [], true, false, false, null, null)
            ->willReturn(11);

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true, 'invoice_id' => 9]);
    }

    public function testOnlineRefundWithoutCapturedInvoiceFailsBeforeAnyRefund(): void
    {
        $this->givenOrderInvoices([$this->invoice(6, true, '')]);
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no invoice that can be refunded online');

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true]);
    }

    public function testOnlineRefundWithSeveralCapturedInvoicesRequiresInvoiceId(): void
    {
        $this->givenOrderInvoices([
            $this->invoice(8, true, 'txn-a'),
            $this->invoice(9, true, 'txn-b'),
        ]);
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('pass invoice_id');

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true]);
    }

    public function testOnlineRefundRejectsInvoiceOfAnotherOrder(): void
    {
        $this->entityFinder->method('invoiceFrom')
            ->willReturn($this->invoice(9, true, 'txn-capture', self::ORDER_ID + 1));
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not belong to order');

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true, 'invoice_id' => 9]);
    }

    public function testOnlineRefundRejectsRequestedInvoiceWithoutTransaction(): void
    {
        $this->entityFinder->method('invoiceFrom')->willReturn($this->invoice(9, true, ''));
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('cannot be refunded online');

        $this->execute(['order_id' => self::ORDER_ID, 'is_online' => true, 'invoice_id' => 9]);
    }

    private function givenPaymentMethod(bool $canRefund, bool $partialPerInvoice): void
    {
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('canRefund')->willReturn($canRefund);
        $this->payment->method('canRefundPartialPerInvoice')->willReturn($partialPerInvoice);
    }

    /**
     * @param Invoice[] $invoices
     */
    private function givenOrderInvoices(array $invoices): void
    {
        $result = $this->createMock(InvoiceSearchResultInterface::class);
        $result->method('getItems')->willReturn($invoices);
        $this->invoiceRepository->method('getList')->willReturn($result);
    }

    private function invoice(
        int $id,
        bool $canRefund,
        string $transactionId,
        int $orderId = self::ORDER_ID,
        bool $usedForRefund = false
    ): Invoice&MockObject {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getEntityId')->willReturn($id);
        $invoice->method('getOrderId')->willReturn($orderId);
        $invoice->method('canRefund')->willReturn($canRefund);
        $invoice->method('getTransactionId')->willReturn($transactionId);
        $invoice->method('getIsUsedForRefund')->willReturn($usedForRefund);
        return $invoice;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function execute(array $arguments): array
    {
        $tool = new CreditMemoCreate(
            $this->entityFinder,
            $this->refundOrder,
            $this->refundInvoice,
            $this->invoiceRepository,
            $this->criteriaBuilder,
            $this->itemFactory,
            $this->commentFactory,
            $this->argsFactory,
            new LineItemValidator()
        );

        $content = $tool->execute($arguments)->getContent();
        $text = $content[0]['text'] ?? '';
        $this->assertIsString($text);
        $payload = json_decode($text, true);
        $this->assertIsArray($payload);
        return $payload;
    }
}
