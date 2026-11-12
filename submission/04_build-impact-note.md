# 04 - Build Impact Note: Partial-Refund Receipt & Japanese Qualified Invoice Tax Groups

*   **Implementation Commit:** [`f9a4a751b917d738fb8c77263f7b12256991d1af`](file:///home/prteek/projects/magento-tha-seller-refund)
*   **Target Module:** `Acme_SellerRefund`
*   **Test Command:** `bin/assignment-test unit --filter RefundReceiptTest`

---

## 1. Problem Statement & Defect Identification

In Part 1 (reviewing `review/pr-01-partial-refund-presentation`), QA identified that the reissued receipt (`RefundReceipt`) lacked the partial-refund breakdown and per-rate tax groups required for Japanese Qualified Invoicing (消費税の適格請求書等保存方式 / インボイス制度).

However, PR-01 attempted to resolve this by introducing severe architectural regressions:
1.  **Mixed-Order Calculation Corruption (BR-01 Violation):**
    PR-01 bypassed the canonical `RefundTotalCalculator` and iterated over `$order->getAllVisibleItems()`. On mixed orders containing both first-party (core) goods and third-party seller goods, core products and their taxes were included in the seller's pre-refund totals and tax groups on the seller receipt.
2.  **Breach of Single Source of Truth:**
    Discarding `RefundTotalCalculator::fromSnapshot()` caused receipt figures to diverge from the customer account view, order history, transactional email, and downstream sync export (breaking `SurfacesAgreeOnPureSellerOrderTest`).
3.  **Incomplete Delivery:**
    PR-01 appended `tax_groups` to the view data array in `buildData()`, but never rendered the tax groups on the PDF canvas in `render()`.
4.  **Flawed Historical Aggregation Query:**
    PR-01 introduced an ad-hoc query in `Refund::loadRefundedQtyByOrder` summing active refunds for the order, which erroneously included the current refund when generating the receipt.

---

## 2. Implemented Architecture & Code Changes

The defect was fixed properly on `main` in [`app/code/Acme/SellerRefund/Model/Pdf/RefundReceipt.php`](file:///home/prteek/projects/magento-tha-seller-refund/app/code/Acme/SellerRefund/Model/Pdf/RefundReceipt.php):

### 2.1 Preserving Single Source of Truth
*   Retained `RefundTotalCalculator::fromSnapshot()` as the canonical engine for all pre-refund and refund totals (`subtotal`, `shipping`, `tax`, `grand_total`).
*   Ensured 100% mathematical consistency across all five presentation surfaces.

### 2.2 Strict Seller Line Isolation on Mixed Orders
*   Injected `SellerLineResolver` and `TaxCodeResolver` into `RefundReceipt`.
*   When generating per-rate tax groups (`buildTaxGroups`), `RefundReceipt` strictly filters for seller lines using `SellerLineResolver::sellerLines($order)`. First-party core lines are completely ignored, fully satisfying **BR-01**.

### 2.3 Japanese Qualified Invoice Tax Grouping
*   Added `buildTaxGroups()`: Groups eligible seller lines by tax rate (e.g. `0.1000` for standard 10%, `0.0800` for reduced 8%, `0.0000` for exempt).
*   For each tax group, calculates:
    *   `rate`: Formatted 4-decimal rate string.
    *   `taxable`: Cumulative taxable ex-tax row amount.
    *   `tax`: Cumulative consumption tax amount.
*   Groups are sorted in descending order by tax rate (`krsort`).

### 2.4 PDF Canvas Rendering
*   Updated `RefundReceipt::render()` to visually draw the **Tax Breakdown by Rate (Qualified Invoice)** section on the PDF canvas with formatted percentage, taxable base, and consumption tax amounts.

### 2.5 Snapshot Data Integrity
*   Read `refunded_before` directly from the immutable line snapshot (`$item->getQtyRefundedBefore()`), avoiding unindexed SQL queries and preventing double-counting.

---

## 3. Automated Test Coverage

A comprehensive unit test suite was authored in [`app/code/Acme/SellerRefund/Test/Unit/Model/Pdf/RefundReceiptTest.php`](file:///home/prteek/projects/magento-tha-seller-refund/app/code/Acme/SellerRefund/Test/Unit/Model/Pdf/RefundReceiptTest.php) demonstrating both the corrected behavior and protection against regressions:

1.  **`testBuildDataRestatesOrderHeaderAndGroupsTax()`:**
    Validates a pure seller order: proves that header figures match `pre_refund`, that line items include `refunded_before`, and that a single 10% tax group is produced (`[['rate' => '0.1000', 'taxable' => '2400.0000', 'tax' => '240.0000']]`).
2.  **`testBuildDataExcludesCoreItemsOnMixedOrders()`:**
    Sets up a mixed order with 1 seller line (1,200 JPY row, 120 JPY tax @ 10%) and 1 first-party core line (8,000 JPY row, 800 JPY tax @ 10%). Proves that the core line is strictly excluded: `tax_groups` contains only the seller item (`1200.0000` taxable, `120.0000` tax), proving **BR-01** compliance.
3.  **`testBuildDataGroupsMultipleTaxRatesSeparately()`:**
    Sets up an order with multiple seller items across differing tax rates (10% standard rate and 8% reduced rate). Proves that `tax_groups` outputs two distinct, properly aggregated, and sorted groups (`0.1000` and `0.0800`).

---

## 4. Verification & Impact Summary

### 4.1 Verification Commands
Inside the containerized runtime:
```bash
# Run the newly authored unit test
bin/assignment-test unit --filter RefundReceiptTest

# Run all bounded unit and integration suites
bin/assignment-test all
```

### 4.2 Impact Matrix
*   **Database Schema:** No schema migrations or index changes required.
*   **External Integration:** No changes to ERP connector payloads; fully backward compatible.
*   **Regulatory Compliance:** Reissued PDF receipts now fully comply with the Japanese Qualified Invoice System requirements.
*   **Operational Risk:** Zero risk of regression to storefront or admin workflows; `SurfacesAgreeOnPureSellerOrderTest` remains green.
