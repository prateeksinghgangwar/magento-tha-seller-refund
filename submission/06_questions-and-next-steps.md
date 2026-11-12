# 06 - Open Questions, Assumptions, & Next Steps

This document details the architectural assumptions made during this assessment, open questions for key business and technical stakeholders, explicit trade-offs on where work was deliberately bounded, and immediate next steps for production delivery.

---

## 1. Open Questions for Domain Stakeholders

### 1.1 ERP & Integration Engineering
1.  **Credit Note Expiration & TTL:**
    *   *Question:* What is the ERP lifecycle TTL for a `refund-pending` credit note? If Finance takes 7–14 days to execute an offline bank transfer, does the ERP auto-expire the pending credit note?
    *   *Architectural Impact:* If credit notes auto-expire, `Read Refund Status` must detect expired credit notes and transition local records to a new state (e.g. `erp_expired`), requiring a re-opening flow before cash confirmation.
2.  **Batch Status & Bulk Endpoints:**
    *   *Question:* Does the ERP support a batch status query (e.g., `POST /erp-api/v1/refunds/batch-status` with an array of `erp_refund_id`s)?
    *   *Architectural Impact:* The daily settlement sweep scans up to 20,000 candidate records. Executing 20,000 individual HTTP GET requests sequentially takes ~30–60 minutes and places unnecessary load on the ERP stub/gateway. A batch endpoint reduces network round-trips by 99%.
3.  **Credit Note Void / Reversal Contract:**
    *   *Question:* What is the formal protocol if a refund must be voided *after* `POST /refunds` has succeeded but *before* cash confirmation?
    *   *Architectural Impact:* Currently, `failed` and `cancelled` are terminal states, but there is no outbound `DELETE` or `POST /refunds/{id}/cancel` defined in the contract.

### 1.2 Finance & Banking Operations
4.  **Bank Transfer Bounces & Asynchronous Reversals:**
    *   *Question:* What happens if Finance enters a transaction number and date, the store confirms settlement to the ERP (`erp_confirmed`), but the customer's bank rejects/bounces the transfer 3 business days later?
    *   *Architectural Impact:* Does Finance require a post-confirmation dispute mechanism, or does this trigger a separate manual adjustment ledger entry directly in the ERP?
5.  **Bulk Cash Registration:**
    *   *Question:* Will Finance operators manually input transaction references one by one for 5,000 monthly refunds, or do they require a daily batch CSV/API upload from their banking portal?
    *   *Architectural Impact:* If bulk upload is required, we should introduce a dedicated `acme:refund:import-bank-settlement` CLI command and admin import handler.

### 1.3 Legal, Tax & Procurement
6.  **Contractual Return Window Conflict:**
    *   *Question:* The FRD states in **BR-02** that refunds are allowed within **14 calendar days of delivery**, but the Procurement contract note explicitly mandates **7 days from delivery**. Which window is legally authoritative?
    *   *Architectural Impact:* The refund window duration is currently hardcoded in `RefundEligibility`. We should migrate this to a configurable core config setting (`seller_refund/general/eligibility_days`) while defaulting to the stricter contractual requirement.
7.  **Qualified Invoice Digital Retention:**
    *   *Question:* Under the Japanese Electronic Bookkeeping Act (電子帳簿保存法), do reissued PDF receipts stored or downloaded by customers require digital signatures, hash timestamps, or specific audit log retention periods?

---

## 2. Core Assumptions

1.  **Monetary Arithmetic & Currency Scope:**
    *   The store operates exclusively in Japanese Yen (JPY). All intermediate financial calculations are conducted in `decimal(20,4)` using PHP `bcmath` with a fixed scale of 4. Display amounts are rounded to integer currency units.
2.  **At-Least-Once Delivery & Idempotency:**
    *   The asynchronous Outbox processor operates under **at-least-once delivery** semantics. We assume the external ERP strictly adheres to its documented contract: `POST /erp-api/v1/refunds` is 100% idempotent on `refund_no`, and `POST /confirm` is 100% idempotent on `erp_refund_id`.
3.  **Outbox Worker Infrastructure:**
    *   In the absence of an external AMQP broker (RabbitMQ), MariaDB-backed queue leasing using `SELECT ... FOR UPDATE SKIP LOCKED` is assumed to be fully sufficient for the stated volume (25 submissions/minute is ~0.42 ops/sec, well within MariaDB's concurrency thresholds).
4.  **Operator Concurrency Model:**
    *   A maximum of 40 concurrent Customer Support operators work the admin panel simultaneously, with no more than 2 operators actively attempting actions on the same order record at any given second.

---

## 3. Deliberate Boundaries (Where We Stopped & Why)

Given the bounded time budget of this assignment, technical effort was prioritized on high-leverage architectural reasoning and fixing the core financial/presentation defect:

1.  **Did Not Introduce an External Message Broker (RabbitMQ):**
    *   *Reasoning:* Introducing RabbitMQ or Redis would violate the baseline constraint (*"no AMQP broker is configured; durable async work uses a DB outbox"*). The DB-backed Transactional Outbox pattern provides superior transactional atomicity with MariaDB without adding operational infrastructure complexity.
2.  **Did Not Rewrite Frontend to Modern SPA:**
    *   *Reasoning:* While Knockout/jQuery is legacy, refactoring to a modern framework is out of scope and carries immense regression risk. We preserved the existing widget architecture, repairing its in-flight locking and state handling.
3.  **Focused Part 3 on the Reissued Receipt (Qualified Invoicing & Mixed Orders):**
    *   *Reasoning:* PR-02 is fundamentally broken across multiple dimensions (breaks idempotency, deletes remaining-quantity checks, runs HTTP calls inside DB transactions). Rather than attempting to patch an irredeemable PR, we focused Part 3 on properly implementing the Japanese Qualified Invoice tax grouping on `main`, solving the root QA concern while safeguarding mixed orders and the single source of truth.

---

## 4. Next Steps (Production Roadmap)

If transitioning this subsystem from take-home evaluation to production deployment, the immediate implementation roadmap is:

### Phase 1: Immediate Hardening (Sprint 1)
1.  **Enforce Database-Level Concurrency on BR-05:**
    *   Add a conditional unique constraint or row-level lock on `sales_order` to physically prevent concurrent duplicate refunds at the database engine level.
2.  **Replace 2-Second Polling with Adaptive Backoff:**
    *   Update `refund-form.js` and `worklist.phtml` to replace the 2-second static interval with exponential backoff polling (3s -> 10s -> 30s -> stop).

### Phase 2: Telemetry & Resilience (Sprint 2)
3.  **Observability & Alerting:**
    *   Export Prometheus metrics for outbox queue lag (`acme_refund_outbox_pending_count`), dead-letter transitions (`acme_refund_outbox_dead_count`), and ERP stub response latencies.
    *   Configure PagerDuty alerts for any job transitioning to `dead` state.
4.  **Circuit Breaker Integration:**
    *   Add an in-memory/cache-backed circuit breaker to `ErpRefundClient` that temporarily halts outbound dispatch if the ERP returns 5 consecutive timeouts, preventing worker pool exhaustion.

### Phase 3: Operational Tooling (Sprint 3)
5.  **Admin Replay UI for Finance Administrators:**
    *   Provide a dedicated admin interface for `refund_admin` users to inspect dead-lettered outbox jobs, view redacted request/response payloads, and trigger one-click replays without database access.
6.  **Automated Daily Settlement Reconciliation:**
    *   Deploy the `ReconcileCommand` as a nightly cron job that compares local `erp_confirmed` refunds against ERP settlement logs to ensure 100% financial agreement.
