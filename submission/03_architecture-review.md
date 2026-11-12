# 03 - Architecture Review & Target-State Extension

This document provides a comprehensive architectural evaluation of the proposed design described in [`docs/candidate/refund-architecture-overview.md`](../docs/candidate/refund-architecture-overview.md), evaluates critical operational risks ranked by blast radius, defines a production-ready target-state extension, and outlines the mandatory pre-sign-off verification checklist.

---

## 1. Executive Summary & Review Context

The inherited architecture overview outlines a v1 implementation designed for simplicity: monolithic, synchronous request processing within the admin controller, client-side optimistic UI transitions, aggressive 2-second HTTP polling, and channel-specific presentation logic without a shared presentation model.

While an all-in-one controller flow minimizes initial developer overhead, it introduces catastrophic failure modes when subjected to the documented production scale:
*   **Monthly Volume:** ~250,000 orders; ~5,000 refunds.
*   **Peak Concurrency:** 25 refund submissions/minute; 40 concurrent CS operators on the worklist.
*   **Settlement Working Set:** ~20,000 candidate records evaluated daily.
*   **SLA:** Worklist first-page render within 1.5 seconds.

---

## 2. Risk Assessment (Ranked by Blast Radius)

The table below summarizes the key architectural risks identified in the inherited overview, ranked from highest to lowest blast radius:

| Rank | Architectural Risk | Failure Mode | Blast Radius | Practical Mitigation |
|---|---|---|---|---|
| **1** | **Synchronous Remote HTTP Calls in Controller** | Remote ERP latency/timeout blocks PHP workers and MariaDB transactions | **Enterprise-wide Outage:** Exhaustion of PHP-FPM pools and DB connections takes down Storefront & Admin | Implement **Transactional Outbox Pattern** with background workers |
| **2** | **Unprotected Active Refund Invariant (BR-05)** | Application-only check permits race conditions under concurrent submissions | **Financial Loss:** Duplicate credit notes, over-refunding, corrupted seller balances | Database-level unique constraint / pessimistic row lock on order |
| **3** | **Self-Inflicted DoS via 2-Second Polling** | 40 concurrent operators trigger 1,200 dynamic requests/min | **Platform Degradation:** Database I/O spikes, session locking, CPU saturation | Exponential backoff polling or Server-Sent Events (SSE) |
| **4** | **Unbounded Reads & Missing Pagination in Worklist** | Per-row order/line loads iterate through unpaginated collection | **Admin DoS:** PHP Out-of-Memory (OOM), HTTP 504 timeouts, worklist SLA breach | Mandatory pagination (`pageSize=50`) & SQL join summary |
| **5** | **Channel-Specific Presentation Divergence** | PDF, storefront, email, and downstream sync compute figures independently | **Compliance / Legal:** Inconsistent tax invoices, customer disputes, accounting discrepancies | Enforce `RefundTotalCalculator::fromSnapshot()` as Single Source of Truth |
| **6** | **Unindexed Settlement Sweep Queries** | Daily sweep scans 20,000+ candidate rows on non-indexed columns | **DB Performance:** Full table scans, row lock contention during business hours | Add composite index `(status, created_at)` |

---

### In-Depth Analysis of Critical Risks

#### Risk 1: Synchronous Remote ERP Calls Inside Admin Controller
*   **Current State:** `RefundController::execute` receives the form submission, persists the refund to MariaDB, and synchronously calls `ErpRefundClient::create` with a 30-second timeout and 2 retries (up to 90 seconds total) before returning an HTTP response to the browser.
*   **Blast Radius (Enterprise-wide Platform Outage):**
    PHP-FPM worker pools are finite (typically 50–150 workers per node). If the external ERP stub/API experiences latency (e.g. 10-second response times) or network degradation during a promotional campaign (25 refunds/min), 30–50 PHP worker processes will block simultaneously waiting on external HTTP sockets. Once the PHP-FPM pool is exhausted, the web server (Nginx/Apache) will return **HTTP 502/504 Bad Gateway** errors to all customers attempting to browse or purchase on the storefront.
*   **Architectural Response:**
    Decouple the HTTP request boundary from external integration using the **Transactional Outbox Pattern**:
    1. The controller validates, calculates, writes the refund snapshot, and inserts an outbox task (`mp_refund_outbox`) in a single MariaDB transaction (`<50ms`).
    2. The controller immediately returns HTTP 200 to the browser: `Saved; ERP notification pending`.
    3. An asynchronous queue runner (CLI/cron daemon) claims outbox jobs using `SELECT ... FOR UPDATE SKIP LOCKED` and handles the ERP HTTP round-trip out of process.

#### Risk 2: Unprotected Concurrency on BR-05 ("One Active Refund Per Order")
*   **Current State:** The overview states that BR-05 is left to the application layer to validate, with database concurrency deferred to a future phase.
*   **Blast Radius (Financial Leakage & Seller Ledger Discrepancy):**
    If two CS operators simultaneously open and submit refunds for the same order, or if an operator double-clicks before the button disables, two concurrent web worker threads will execute the validation step at the exact same millisecond. Both threads read the same remaining refundable amount, both pass validation, and both insert refund records. The ERP receives two credit notes, and the customer is refunded twice.
*   **Architectural Response:**
    Enforce concurrency at the persistence layer:
    *   Acquire a pessimistic lock on the parent order record (`SELECT entity_id FROM sales_order WHERE entity_id = :id FOR UPDATE`) during the submission transaction; OR
    *   Add a conditional unique functional index in MariaDB on `order_id` where `status NOT IN ('failed', 'cancelled')`.

#### Risk 3: Aggressive 2-Second Client-Side Polling
*   **Current State:** The admin screen polls the refund status endpoint every 2 seconds per open browser tab.
*   **Blast Radius (Admin Server Exhaustion):**
    With 40 concurrent CS agents working throughout the day, this generates **20 requests/second (1,200 req/min)** purely for status polling. In Magento, each admin request bootstraps the ObjectManager, checks session locks, and queries the database. This creates continuous background CPU load and session lock contention.
*   **Architectural Response:**
    *   Transition from naive fixed 2s polling to **adaptive backoff polling**: poll at 3s, 6s, 12s, 30s, capping at 60s, and stop completely once the record reaches `cash_refund_pending` or a terminal state.
    *   Target-state enhancement: Implement a lightweight WebSocket or Server-Sent Events (SSE) notification endpoint that pushes state change events emitted by the outbox worker.

#### Risk 4: Worklist Performance Collapse & N+1 Queries
*   **Current State:** The worklist iterates through the entire refund collection, loading order and line models dynamically for each row.
*   **Blast Radius (Breach of SLA & Memory Exhaustion):**
    Iterating across thousands of records with per-row repository calls generates an N+1 query storm. Loading 500 rows triggers 1,000+ individual SQL queries. At production scale, this will trigger PHP fatal `Allowed memory size exhausted` errors and violate the mandatory 1.5-second page-load SLA.
*   **Architectural Response:**
    *   Mandate server-side pagination with a strict limit (`pageSize = 50`).
    *   Use database joins (`joinOrderSummary()`, `addLineSummary()`) in the collection class so the entire grid page is loaded in a single SQL query.
    *   For future scale (>100k records), implement a dedicated denormalized read model / flat index updated via domain events.

---

## 3. Target-State Architecture Extension

The target architecture replaces synchronous controller coupling with a high-throughput, event-driven outbox pipeline.

```text
                    MAGENTO ADMIN PANEL
                  (Knockout.js / jQuery UI)
                            |
                            |
                  Refund Submission Request
                            |
                            v
              +-----------------------------+
              | Magento Admin Controller    |
              | RefundController::Save      |
              +-----------------------------+
                            |
                            v
              +-----------------------------+
              | Refund Application Services |
              |                             |
              | - RefundValidator           |
              | - RefundTotalCalculator     |
              | - SellerLineResolver        |
              +-----------------------------+
                            |
                            v
              +-----------------------------+
              | Magento Database Transaction|
              |                             |
              | - Lock Sales Order          |
              | - Save Refund               |
              | - Save Refund Items         |
              | - Save Refund Event         |
              | - Insert Outbox Job         |
              +-----------------------------+
                            |
                            v
                  MARIADB DATABASE
              +-----------------------------+
              | mp_refund                   |
              | mp_refund_item              |
              | mp_refund_event             |
              | mp_refund_outbox            |
              +-----------------------------+
                            |
                 Transaction Committed
                            |
               +------------+------------+
               |                         |
               v                         v
       Immediate Admin Response     Magento Cron / CLI
       "ERP Sync Pending"           Outbox Worker
               |                         |
               v                         v
       Admin Refund Status       Outbox Job Processor
       Adaptive Polling                  |
                                         v
                               +----------------------+
                               | ERP Integration      |
                               | ErpRefundClient      |
                               |                      |
                               | - HTTP Timeout       |
                               | - Retry Handling     |
                               | - Idempotency        |
                               | - Circuit Breaker    |
                               +----------------------+
                                         |
                                         v
                                  EXTERNAL ERP API
                                         |
                                         v
                                  ERP Refund Response
                                         |
                                         v
                               Update Refund Lifecycle
                                         |
                                         v
                                    MARIADB
                                         |
                      +------------------+------------------+
                      |                  |                  |
                      v                  v                  v
                Admin Status       Core System Sync   Seller Settlement
                Presentation       / Domain Events    Export
```

### Key Target-State Subsystems

1.  **Transactional Outbox Engine (`Acme_SellerRefund::Outbox`):**
    *   Queue table: `mp_refund_outbox` containing `refund_id`, `operation` (`create`, `status_check`, `confirm`), `state` (`pending`, `done`, `dead`), `attempts`, `available_at`, and `last_error`.
    *   Worker concurrency: CLI daemon (`bin/magento acme:refund:outbox-work`) using `SELECT ... FOR UPDATE SKIP LOCKED` to allow multiple parallel workers without contention.
    *   Dead-Letter Queue (DLQ): Jobs exceeding max attempts (default: 5) transition to `dead` state, triggering PagerDuty/Slack alerts for manual review.
2.  **Circuit Breaker & Resilience Policy:**
    *   Bounded network sockets: Connect timeout 3s; read timeout 10s.
    *   Circuit breaker: Detect consecutive 5xx or connection timeouts; temporarily pause outbound dispatch to protect external infrastructure from stampeding retries.
    *   Honor HTTP 429 `Retry-After` headers explicitly in the outbox backoff schedule.
3.  **Read-Before-Confirm Automation:**
    *   Automate the status sweep using a scheduled cron task running every 5 minutes (`Cron/WorkOutbox.php`).
    *   Records in `cash_refunded` are swept, queried against `GET /erp-api/v1/refunds/{erp_refund_id}`, and advanced to `erp_confirm_pending` without requiring manual operator polling.
4.  **Canonical Unified DTO:**
    *   All surfaces (Receipt PDF, Storefront view model, Order History view model, Email observer, Core sync) consume `RefundFigures` produced by `RefundTotalCalculator::fromSnapshot()`.
    *   Strict separation of seller vs. core products is maintained across all DTO consumers via `SellerLineResolver`.

---

## 4. Pre-Sign-Off Governance Checklist

Before granting architectural sign-off for production implementation, the following empirical facts must be verified and technical questions resolved:

### 4.1 Facts to Verify in Staging & Telemetry
1.  **ERP Stub vs. Production Contract Parity:**
    Verify that the production ERP gateway strictly enforces `refund_no` idempotency across retries, correctly mirrors stub error structures, and supports HTTP 429 `Retry-After` headers.
2.  **MariaDB Transaction Concurrency Under Load:**
    Execute a load test simulating 25 concurrent submissions per minute on identical parent orders. Confirm that row-level locks prevent duplicate `mp_refund` rows and that zero deadlocks occur.
3.  **Worklist Latency with 20,000 Seeded Rows:**
    Verify through SQL slow query logs that `Worklist` collection loading executes in `<200ms` and returns the first page to the browser in `<1.0s` without triggering full table scans.
4.  **Qualified Invoice Tax Group Integrity:**
    Validate reissued PDF receipts with qualified tax accountants to verify that Japanese consumption tax rounding rules (10% standard, 8% reduced) conform to National Tax Agency guidelines.

### 4.2 Critical Questions for Stakeholders

| Stakeholder | Critical Questions to Resolve Before Sign-Off |
|---|---|
| **ERP / Integration Team** | 1. Does the ERP automatically expire or cancel `refund-pending` credit notes if `confirm` is not received within a specific time window?<br>2. What is the SLA and rate limit (RPS) of the production ERP endpoint?<br>3. Does the ERP support batch status lookups (`POST /refunds/status-batch`) to optimize the settlement sweep? |
| **Finance Team** | 1. What is the maximum acceptable delay between cash transfer execution and ERP confirmation?<br>2. In the event of a banking transfer failure (e.g. invalid account), what is the manual dispute/void procedure for an existing `mp_refund`? |
| **Customer Support / Operations** | 1. Do operators require bulk cash-registration capabilities, or is row-by-row processing sufficient?<br>2. Should an automated reminder trigger if a refund remains in `cash_refund_pending` for >7 days? |
| **Legal / Compliance** | 1. Does the reissued tax receipt require digital signing / PDF stamping for qualified invoice audits in Japan?<br>2. What is the statutory retention period for `mp_refund_event` audit logs? |
