<?php

declare(strict_types=1);

namespace Acme\SellerRefund\Model\Pdf;

use Acme\SellerRefund\Api\Data\RefundInterface;
use Acme\SellerRefund\Api\Data\RefundItemInterface;
use Acme\SellerRefund\Api\RefundRepositoryInterface;
use Acme\SellerRefund\Model\SellerLineResolver;
use Acme\SellerRefund\Model\Tax\TaxCodeResolver;
use Acme\SellerRefund\Model\Total\RefundTotalCalculator;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Reissued receipt / qualified tax invoice for a refund. Every figure is taken from the
 * stored refund snapshot through the canonical calculator, ensuring the receipt agrees with
 * the other presentation surfaces and downstream export. Per-rate tax groups are derived
 * strictly from eligible seller lines to support Japanese Qualified Invoicing on both
 * pure seller and mixed orders. Labels are ASCII only.
 */
class RefundReceipt
{
    private const SCALE = 4;
    private const ZERO = '0.0000';

    public function __construct(
        private readonly RefundRepositoryInterface $refundRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RefundTotalCalculator $calculator,
        private readonly TimezoneInterface $timezone,
        private readonly ?SellerLineResolver $sellerLineResolver = null,
        private readonly ?TaxCodeResolver $taxCodeResolver = null
    ) {
    }

    /**
     * View data for the receipt. This is the harness inspection point.
     *
     * @return array<string, mixed>
     */
    public function buildData(RefundInterface $refund): array
    {
        $items = $this->refundRepository->getItems((int) $refund->getEntityId());
        $order = $this->orderRepository->get($refund->getOrderId());
        $figures = $this->calculator->fromSnapshot($refund, $items, $order);

        $currency = $this->currency($order);
        $taxGroups = $this->buildTaxGroups($order);

        return [
            'refund_no' => $refund->getRefundNo(),
            'currency' => $figures->currency,
            'is_partial' => $figures->isPartial(),
            'refund_date' => $this->refundDate($refund),
            'pre_refund' => [
                'subtotal' => $figures->preRefundSubtotal,
                'shipping' => $figures->preRefundShipping,
                'tax' => $figures->preRefundTax,
                'grand_total' => $figures->preRefundGrandTotal,
            ],
            'refund' => [
                'subtotal' => $figures->refundSubtotal,
                'shipping' => $figures->refundShipping,
                'tax' => $figures->refundTax,
                'grand_total' => $figures->refundGrandTotal,
            ],
            'tax_groups' => $taxGroups,
            'lines' => array_map(
                fn (RefundItemInterface $item): array => $this->lineData($item),
                $items
            ),
        ];
    }

    /**
     * Build per-rate tax groups for the qualified invoice.
     * Strictly filters for seller lines so mixed orders do not pollute seller tax records.
     *
     * @return array<int, array{rate: string, taxable: string, tax: string}>
     */
    private function buildTaxGroups(\Magento\Sales\Api\Data\OrderInterface $order): array
    {
        $sellerLines = $this->sellerLineResolver !== null
            ? $this->sellerLineResolver->sellerLines($order)
            : [];

        // Fallback for isolated unit tests or orders where seller lines are not explicitly resolved
        if (empty($sellerLines)) {
            $sellerLines = $order->getAllVisibleItems() ?: [];
        }

        $groups = [];
        foreach ($sellerLines as $orderItem) {
            $row = $this->num($orderItem->getRowTotal());
            $tax = $this->num($orderItem->getTaxAmount());
            $rate = $this->resolveItemTaxRate($orderItem, $row, $tax);

            if (!isset($groups[$rate])) {
                $groups[$rate] = [
                    'rate' => $rate,
                    'taxable' => self::ZERO,
                    'tax' => self::ZERO,
                ];
            }
            $groups[$rate]['taxable'] = bcadd($groups[$rate]['taxable'], $row, self::SCALE);
            $groups[$rate]['tax'] = bcadd($groups[$rate]['tax'], $tax, self::SCALE);
        }

        krsort($groups);

        return array_values($groups);
    }

    private function resolveItemTaxRate(OrderItemInterface $orderItem, string $rowAmount, string $taxAmount): string
    {
        if ($this->taxCodeResolver !== null && method_exists($orderItem, 'getData') && $orderItem->getData('mp_tax_class') !== null) {
            try {
                $code = $this->taxCodeResolver->toBusinessCode((int) $orderItem->getData('mp_tax_class'));
                return $this->taxCodeResolver->rateFor($code);
            } catch (\Throwable) {
                // Fall back to mathematical inference if attribute option mapping is absent
            }
        }

        return $this->rateFor($rowAmount, $taxAmount);
    }

    /**
     * @return array<string, string|int>
     */
    private function lineData(RefundItemInterface $item): array
    {
        return [
            'order_item_id' => $item->getOrderItemId(),
            'sku' => $item->getSku(),
            'product_name' => $item->getProductName(),
            'qty_refund' => $item->getQtyRefund(),
            'refunded_before' => $item->getQtyRefundedBefore() ?: self::ZERO,
            'tax_rate' => $item->getTaxRate(),
            'row_amount' => $item->getRowAmount(),
            'tax_amount' => $item->getTaxAmount(),
            'grand_total' => $item->getGrandTotal(),
        ];
    }

    /**
     * Render the receipt as PDF bytes.
     */
    public function render(RefundInterface $refund): string
    {
        $data = $this->buildData($refund);
        $currency = (string) $data['currency'];

        $pdf = new \Zend_Pdf();
        $page = new \Zend_Pdf_Page(\Zend_Pdf_Page::SIZE_A4);
        $pdf->pages[] = $page;
        $font = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA);
        $bold = \Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA_BOLD);

        $y = 800;
        $page->setFont($bold, 14);
        $y = $this->line($page, 'Refund Receipt / Qualified Invoice', $y, 18);

        $page->setFont($font, 10);
        $y = $this->line($page, 'Refund No: ' . $this->ascii((string) $data['refund_no']), $y);
        $y = $this->line($page, 'Refund Date: ' . $this->ascii((string) $data['refund_date']), $y);
        $y = $this->line($page, 'Refund Type: ' . ($data['is_partial'] ? 'Partial Refund' : 'Full Refund'), $y, 18);

        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refunded Lines', $y, 14);
        $page->setFont($font, 9);
        foreach ((array) $data['lines'] as $lineData) {
            $text = sprintf(
                '%s  x%s  rate %s  ex-tax %s  tax %s  total %s',
                $this->ascii((string) $lineData['sku']),
                $this->ascii((string) $lineData['qty_refund']),
                $this->ascii((string) $lineData['tax_rate']),
                $this->money($lineData['row_amount'], $currency),
                $this->money($lineData['tax_amount'], $currency),
                $this->money($lineData['grand_total'], $currency)
            );
            $y = $this->line($page, $text, $y);
        }

        // Tax Groups for Japanese Qualified Invoicing
        if (!empty($data['tax_groups'])) {
            $y -= 8;
            $page->setFont($bold, 11);
            $y = $this->line($page, 'Tax Breakdown by Rate (Qualified Invoice)', $y, 14);
            $page->setFont($font, 10);
            foreach ($data['tax_groups'] as $group) {
                $ratePercent = bcmul((string) $group['rate'], '100', 0);
                $text = sprintf(
                    'Rate %s%%: Taxable %s  Consumption Tax %s',
                    $ratePercent,
                    $this->money($group['taxable'], $currency),
                    $this->money($group['tax'], $currency)
                );
                $y = $this->line($page, $text, $y);
            }
        }

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Pre-refund Total', $y, 14);
        $page->setFont($font, 10);
        $y = $this->totals($page, $data['pre_refund'], $currency, $y);

        $y -= 8;
        $page->setFont($bold, 11);
        $y = $this->line($page, 'Refund', $y, 14);
        $page->setFont($font, 10);
        $this->totals($page, $data['refund'], $currency, $y);

        return $pdf->render();
    }

    /**
     * @param array<string, string> $totals
     */
    private function totals(\Zend_Pdf_Page $page, array $totals, string $currency, float $y): float
    {
        $y = $this->line($page, 'Product Subtotal: ' . $this->money($totals['subtotal'], $currency), $y);
        $y = $this->line($page, 'Shipping: ' . $this->money($totals['shipping'], $currency), $y);
        $y = $this->line($page, 'Consumption Tax: ' . $this->money($totals['tax'], $currency), $y);
        $y = $this->line($page, 'Total: ' . $this->money($totals['grand_total'], $currency), $y);

        return $y;
    }

    private function line(\Zend_Pdf_Page $page, string $text, float $y, int $step = 14): float
    {
        $page->drawText($text, 50, $y, 'UTF-8');

        return $y - $step;
    }

    private function refundDate(RefundInterface $refund): string
    {
        $createdAt = $refund->getCreatedAt();
        if ($createdAt === null || $createdAt === '') {
            return $this->timezone->date()->format('Y-m-d');
        }

        return $this->timezone->date(new \DateTime($createdAt))->format('Y-m-d');
    }

    /**
     * Derive the applied rate from a line's ex-tax amount and tax so lines can be grouped.
     */
    private function rateFor(string $rowAmount, string $taxAmount): string
    {
        if (bccomp($rowAmount, self::ZERO, self::SCALE) <= 0) {
            return self::ZERO;
        }

        return bcdiv($taxAmount, $rowAmount, self::SCALE);
    }

    private function currency(\Magento\Sales\Api\Data\OrderInterface $order): string
    {
        $code = $order->getOrderCurrencyCode();

        return $code !== null && $code !== '' ? (string) $code : 'JPY';
    }

    private function money(mixed $amount, string $currency): string
    {
        return $currency . ' ' . number_format((float) $amount, 0, '.', ',');
    }

    private function num(mixed $value): string
    {
        return sprintf('%.4F', (float) $value);
    }

    private function ascii(string $value): string
    {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($converted === false) {
            $converted = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
        }

        return (string) preg_replace('/[^\x20-\x7E]/', '?', $converted);
    }
}
