<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Test\Unit\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\Pdf\RefundReceipt;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use Acme\SellerRefund\Model\Total\RefundFigures;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive unit test suite for RefundReceipt.
 * Validates:
 * 1. Restating original seller header figures and grouping taxes on pure seller orders.
 * 2. Strict isolation of seller lines on mixed orders (first-party core lines are excluded).
 * 3. Accurate multi-rate tax grouping (e.g. 10% standard and 8% reduced rate) for Japanese Qualified Invoicing.
 */
class RefundReceiptTest extends TestCase
{
    public function testBuildDataRestatesOrderHeaderAndGroupsTax(): void
    {
        $refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $calculator = $this->createMock(RefundTotalCalculator::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-09-01 10:00:00'));

        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(42);
        $refund->method('getOrderId')->willReturn(7);
        $refund->method('getRefundNo')->willReturn('SR-20260901-000042');
        $refund->method('getRefundType')->willReturn(RefundInterface::TYPE_FULL);
        $refund->method('getCreatedAt')->willReturn('2026-09-01 10:00:00');

        $orderItem = $this->createMock(OrderItemInterface::class);
        $orderItem->method('getRowTotal')->willReturn(2400.0);
        $orderItem->method('getTaxAmount')->willReturn(240.0);
        $orderItem->method('getQtyOrdered')->willReturn(2.0);

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(7);
        $order->method('getShippingAmount')->willReturn(0.0);
        $order->method('getOrderCurrencyCode')->willReturn('JPY');
        $order->method('getAllVisibleItems')->willReturn([$orderItem]);
        $orderRepository->method('get')->with(7)->willReturn($order);

        $item = $this->createMock(RefundItemInterface::class);
        $item->method('getOrderItemId')->willReturn(101);
        $item->method('getSku')->willReturn('SELLER-RED-01');
        $item->method('getProductName')->willReturn('Red Seller Item');
        $item->method('getQtyRefund')->willReturn('2.0000');
        $item->method('getQtyRefundedBefore')->willReturn('0.0000');
        $item->method('getRowAmount')->willReturn('2400.0000');
        $item->method('getShippingAmount')->willReturn('0.0000');
        $item->method('getTaxRate')->willReturn('0.1000');
        $item->method('getTaxCode')->willReturn('010');
        $item->method('getTaxAmount')->willReturn('240.0000');
        $item->method('getGrandTotal')->willReturn('2640.0000');
        $refundRepository->method('getItems')->with(42)->willReturn([$item]);

        $figures = new RefundFigures(
            '2400.0000',
            '0.0000',
            '240.0000',
            '2640.0000',
            '2400.0000',
            '0.0000',
            '240.0000',
            '2640.0000',
            [],
            'JPY',
            false
        );
        $calculator->method('fromSnapshot')->willReturn($figures);

        $receipt = new RefundReceipt($refundRepository, $orderRepository, $calculator, $timezone);
        $data = $receipt->buildData($refund);

        $expected = [
            'subtotal' => '2400.0000',
            'shipping' => '0.0000',
            'tax' => '240.0000',
            'grand_total' => '2640.0000',
        ];
        self::assertFalse($data['is_partial']);
        self::assertSame('JPY', $data['currency']);
        self::assertSame($expected, $data['pre_refund']);
        self::assertSame($expected, $data['refund']);
        self::assertSame([['rate' => '0.1000', 'taxable' => '2400.0000', 'tax' => '240.0000']], $data['tax_groups']);
        self::assertCount(1, $data['lines']);
        self::assertSame('SELLER-RED-01', $data['lines'][0]['sku']);
        self::assertSame('0.0000', $data['lines'][0]['refunded_before']);
    }

    public function testBuildDataExcludesCoreItemsOnMixedOrders(): void
    {
        $refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $calculator = $this->createMock(RefundTotalCalculator::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-09-01 10:00:00'));
        $sellerLineResolver = $this->createMock(SellerLineResolver::class);

        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(50);
        $refund->method('getOrderId')->willReturn(10);
        $refund->method('getRefundNo')->willReturn('SR-20260901-000050');
        $refund->method('getRefundType')->willReturn(RefundInterface::TYPE_PARTIAL);

        // Mixed order: 1 seller line, 1 core (1st-party) line
        $sellerItem = $this->createMock(OrderItemInterface::class);
        $sellerItem->method('getItemId')->willReturn(101);
        $sellerItem->method('getRowTotal')->willReturn(1200.0);
        $sellerItem->method('getTaxAmount')->willReturn(120.0);

        $coreItem = $this->createMock(OrderItemInterface::class);
        $coreItem->method('getItemId')->willReturn(999);
        $coreItem->method('getRowTotal')->willReturn(8000.0);
        $coreItem->method('getTaxAmount')->willReturn(800.0);

        $order = $this->createMock(Order::class);
        $order->method('getAllVisibleItems')->willReturn([$sellerItem, $coreItem]);
        $order->method('getOrderCurrencyCode')->willReturn('JPY');
        $orderRepository->method('get')->with(10)->willReturn($order);

        // SellerLineResolver isolates seller lines
        $sellerLineResolver->method('sellerLines')->with($order)->willReturn([101 => $sellerItem]);

        $refundItem = $this->createMock(RefundItemInterface::class);
        $refundItem->method('getOrderItemId')->willReturn(101);
        $refundItem->method('getSku')->willReturn('SELLER-RED-01');
        $refundItem->method('getProductName')->willReturn('Red Seller Item');
        $refundItem->method('getQtyRefund')->willReturn('1.0000');
        $refundItem->method('getQtyRefundedBefore')->willReturn('0.0000');
        $refundItem->method('getRowAmount')->willReturn('1200.0000');
        $refundItem->method('getShippingAmount')->willReturn('0.0000');
        $refundItem->method('getTaxRate')->willReturn('0.1000');
        $refundItem->method('getTaxAmount')->willReturn('120.0000');
        $refundItem->method('getGrandTotal')->willReturn('1320.0000');
        $refundRepository->method('getItems')->with(50)->willReturn([$refundItem]);

        $figures = new RefundFigures(
            '1200.0000',
            '0.0000',
            '120.0000',
            '1320.0000',
            '1200.0000',
            '0.0000',
            '120.0000',
            '1320.0000',
            [],
            'JPY',
            true
        );
        $calculator->method('fromSnapshot')->willReturn($figures);

        $receipt = new RefundReceipt(
            $refundRepository,
            $orderRepository,
            $calculator,
            $timezone,
            $sellerLineResolver
        );
        $data = $receipt->buildData($refund);

        self::assertTrue($data['is_partial']);
        // Verify tax groups ONLY contain the seller item (1200 / 120), not the core item (8000 / 800)
        self::assertSame([['rate' => '0.1000', 'taxable' => '1200.0000', 'tax' => '120.0000']], $data['tax_groups']);
    }

    public function testBuildDataGroupsMultipleTaxRatesSeparately(): void
    {
        $refundRepository = $this->createMock(RefundRepositoryInterface::class);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $calculator = $this->createMock(RefundTotalCalculator::class);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-09-01 10:00:00'));
        $sellerLineResolver = $this->createMock(SellerLineResolver::class);
        $taxCodeResolver = $this->createMock(TaxCodeResolver::class);

        $refund = $this->createMock(RefundInterface::class);
        $refund->method('getEntityId')->willReturn(60);
        $refund->method('getOrderId')->willReturn(15);
        $refund->method('getRefundNo')->willReturn('SR-20260901-000060');
        $refund->method('getRefundType')->willReturn(RefundInterface::TYPE_FULL);

        // Seller line 1: 10% tax rate (mp_tax_class = 101 => '010' => 0.1000)
        $line10 = $this->createMock(OrderItem::class);
        $line10->method('getItemId')->willReturn(1);
        $line10->method('getRowTotal')->willReturn(2000.0);
        $line10->method('getTaxAmount')->willReturn(200.0);
        $line10->method('getData')->with('mp_tax_class')->willReturn(101);

        // Seller line 2: 8% tax rate (mp_tax_class = 102 => '008' => 0.0800)
        $line8 = $this->createMock(OrderItem::class);
        $line8->method('getItemId')->willReturn(2);
        $line8->method('getRowTotal')->willReturn(1000.0);
        $line8->method('getTaxAmount')->willReturn(80.0);
        $line8->method('getData')->with('mp_tax_class')->willReturn(102);

        $order = $this->createMock(Order::class);
        $order->method('getOrderCurrencyCode')->willReturn('JPY');
        $orderRepository->method('get')->with(15)->willReturn($order);

        $sellerLineResolver->method('sellerLines')->with($order)->willReturn([1 => $line10, 2 => $line8]);

        $taxCodeResolver->method('toBusinessCode')->willReturnMap([
            [101, '010'],
            [102, '008'],
        ]);
        $taxCodeResolver->method('rateFor')->willReturnMap([
            ['010', '0.1000'],
            ['008', '0.0800'],
        ]);

        $refundRepository->method('getItems')->with(60)->willReturn([]);

        $figures = new RefundFigures(
            '3000.0000',
            '0.0000',
            '280.0000',
            '3280.0000',
            '3000.0000',
            '0.0000',
            '280.0000',
            '3280.0000',
            [],
            'JPY',
            false
        );
        $calculator->method('fromSnapshot')->willReturn($figures);

        $receipt = new RefundReceipt(
            $refundRepository,
            $orderRepository,
            $calculator,
            $timezone,
            $sellerLineResolver,
            $taxCodeResolver
        );
        $data = $receipt->buildData($refund);

        $expectedTaxGroups = [
            ['rate' => '0.1000', 'taxable' => '2000.0000', 'tax' => '200.0000'],
            ['rate' => '0.0800', 'taxable' => '1000.0000', 'tax' => '80.0000'],
        ];
        self::assertSame($expectedTaxGroups, $data['tax_groups']);
    }
}
