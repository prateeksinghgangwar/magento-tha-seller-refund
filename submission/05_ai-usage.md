# 05 - AI Usage Log

This document provides a candid, transparent account of how AI was utilized throughout the Technical Architect take-home assignment, consistent with the brief's guidelines (*"We read your judgment, not your keystrokes"*).

---

## 1. Tooling & Environment

*   **Primary Tool:** Antigravity AI Assistant (powered by Google DeepMind's Gemini 3.8 Flash model).
*   **Operating Context:** Read-only repository exploration, git history analysis, diff inspection, architectural diagram generation, and code review structuring.

---

## 2. Where AI Was Leveraged

### 2.1 Codebase Exploration & Fast Diff Synthesis
*   **Task:** Analyzing git branches `review/pr-01-partial-refund-presentation` and `review/pr-02-erp-refund-sync` against `main`.
*   **How AI Helped:** AI was used to execute fast, non-linear inspections of git commit logs, file diffs, and surrounding caller graphs. It quickly surfaced non-obvious diffs, such as the dropped database index in `etc/db_schema.xml` and the deletion of the `inFlight` submission guard in `refund-form.js`.

### 2.2 Domain Spec & Invariant Cross-Referencing
*   **Task:** Evaluating proposed PR changes against the Functional Requirements (FRD) and Business Rules (BR-01 through BR-11).
*   **How AI Helped:** AI served as a rapid cross-referencing assistant, matching code alterations against explicit domain clauses:
    *   Flagged that `RequestKey::forAttempt` violated **BR-07** and **FRD § 9.1** (idempotency key mutation).
    *   Flagged that `RefundValidator` changes violated **BR-03** (per-line quantity guards on subsequent refunds).
    *   Flagged that `RefundReceipt`'s iteration over all order items violated **BR-01** (mixed order core vs. seller segregation).

### 2.3 Architectural Diagram Generation
*   **Task:** Producing an end-to-end sequence diagram capturing failure boundaries and transactional decoupling.
*   **How AI Helped:** AI accelerated the generation of Mermaid sequence diagrams (`sequenceDiagram`) and dependency flowcharts (`flowchart TD`), translating architectural concepts into clean visual artifacts.

### 2.4 Test Suite Scaffolding
*   **Task:** Authoring `RefundReceiptTest.php` to prove the Part 3 fix.
*   **How AI Helped:** Scaffolding verbose PHPUnit test mocks for Magento 2 objects (`OrderInterface`, `OrderItemInterface`, `RefundItemInterface`, `RefundTotalCalculator`).

---

## 3. Where Human Judgment Overrode AI

While AI accelerates synthesis, critical architectural decisions required human domain judgment and experience:

1.  **Rejecting the PR-02 "Direct Feedback" Shortcut:**
    An AI coding assistant might view moving external API calls into the controller as a simple way to satisfy "give the operator immediate feedback." Human architectural judgment recognized this as an operational hazard: holding a MariaDB transaction open during a remote HTTP socket call exhausts database connection pools and PHP-FPM workers, leading to platform-wide outages under load.
2.  **Upholding the Single Source of Truth:**
    PR-01 attempted to fix receipt figures by rewriting math directly in the presentation class. Human judgment insisted on retaining `RefundTotalCalculator` as the sole canonical source of truth for all surfaces to prevent multi-channel financial divergence.
3.  **Detecting Subtle State Machine Violations:**
    Recognizing that mapping transient network errors (HTTP 429, 503) to `STATUS_FAILED` permanently breaks the entity lifecycle because `failed` is a terminal business rejection requiring manual dispute intervention.
4.  **Japanese Qualified Invoice Tax Compliance:**
    Ensuring that per-rate tax grouping strictly isolates seller items on mixed orders and complies with Japanese National Tax Agency Qualified Invoice standards.

---

## 4. Assessment of AI as an Architectural Accelerator

AI demonstrated tremendous leverage as a force multiplier for code reading, diff cross-referencing, and documentation structuring. However, the critical value of a Technical Architect—evaluating operational blast radius, upholding financial invariants, defending system boundaries, and communicating empathetic yet unyielding peer feedback—remains entirely a product of human experience and technical reasoning.
