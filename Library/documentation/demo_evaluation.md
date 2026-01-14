# Library System — Demo Evaluation & Compliance

Last updated: 2026-01-12

Purpose: evaluate the repository against the demo guidelines (SIA focus + ADS/Data Analytics), provide evidence, sample queries, and recommended next steps for a demonstration.

## Quick Verdict
- Short: Largely compliant. The system implements a single API gateway, server-side AI/chatbot, database integration and a STAR schema for analytics. Microservices are "logical" (PHP modules) rather than independently deployed services; convert to separate services if judges require true microservice deployments.

## Required Flow (UI → API Gateway → Microservices → Database)
- Status: Implemented.
  - UI pages call: `api/index.php` (single entry point).
  - Gateway file: `api/index.php` maps logical modules to PHP modules.
  - Modules (books, chatbot_admin, chatbot_client, admin_book_search, check_patron_limit, etc.) implement API logic and access the database.

## Evidence (where to look)
- API Gateway: `api/index.php` — maps modules and sets `API_GATEWAY` flag.
- UI that calls the gateway: `client/search_books.php`, `client/chatbot.php` — `fetch('../api/index.php?...')`.
- Module examples: `includes/api_books.php`, `includes/chatbot_api.php`, `includes/chatbot_api_client.php`, `admin/api_book_search.php`.
- Database config: `config/config.php` — MySQL connection and charset setting.
- STAR schema & ETL: `database_star_schema.sql` — dim & fact tables, ETL inserts, and sample analytics queries.

## Microservices: interpretation & compliance
- What exists: multiple modular PHP endpoints that the gateway includes or requires. Each module verifies `API_GATEWAY` and denies direct access.
- Compliance level: "logical microservices" — separate logical modules, clear interfaces via gateway, and access controls.
- Caveat: Not separate network-deployed microservices. If rubric requires separate processes/containers/HTTP services, you should split modules into separate services (e.g., containerize and proxy from the gateway).

## Data Analytics (ADS) — Option A (chosen)
- STAR schema present: `database_star_schema.sql` defines `dim_date`, `dim_book`, `dim_patron`, `dim_user`, and `fact_borrowings`.
- ETL: SQL blocks to populate dims and upsert fact table from operational tables (`borrowings`, `books`, `patrons`, `users`, `fines`).
- Sample SQL queries included (and useful for demo):
  - Monthly borrows (last 12 months): trend over time.
  - Top 10 most borrowed books: ranking.
  - Patron borrowing trend: comparisons across months for a patron.
  - Average days borrowed by category: comparisons.
  - Overdue rate per month: trend + KPI for operations.

### Example insights you can show (demo-ready)
- Trend: monthly borrowing rising/falling — indicate seasonal peaks.
- Ranking: top 10 books — use to prioritize acquisitions.
- Overdue rate: rising overdue_pct for recent months → tighten loan policies or notifications.

## AI Integration (Chatbot)
- Implementation: server-side chatbot handlers in `includes/chatbot_api.php` and `includes/chatbot_api_client.php`.
- External LLM calls are performed server-side (e.g., Generative API URL), not from the browser — satisfies the requirement that UI does not call public APIs directly.
- Access from UI: `client/chatbot.php` calls `api/index.php?module=chatbot_client`.

## Security & Gatekeeping
- Modules check `API_GATEWAY` and deny direct access — enforces flow.
- Gateway sets CORS and allows API token fallback; some modules support an `api_key` header as an alternate auth.

## Demo Script (quick commands)
1) Run ETL to populate STAR schema (MySQL):

```bash
# From your machine where MySQL is accessible
mysql -u root -p library_system < database_star_schema.sql
```

2) Demonstrate API Gateway → Books search (curl):

```bash
curl "http://localhost/Library/api/index.php?module=books&action=search&search=history"
```

3) Demonstrate Chatbot call via gateway (curl):

```bash
curl -X POST "http://localhost/Library/api/index.php?module=chatbot_client" \
  -H "Content-Type: application/json" \
  -d '{"input":"Recommend books on data analytics"}'
```

4) Run sample analytics query (MySQL):

```bash
mysql -u root -p -e "SELECT CONCAT(d.year,'-',LPAD(d.month,2,'0')) AS month, COUNT(*) AS borrows FROM fact_borrowings f JOIN dim_date d ON f.borrow_date_id = d.date_id WHERE d.dt >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY d.year,d.month ORDER BY d.year DESC,d.month DESC;" library_system
```

## Compliance Checklist (concise)
- Simple UI: Pass (client pages exist). 
- API Gateway (single entry): Pass (`api/index.php`).
- ≥4 Microservices: Pass logically (multiple modules) — Partial for separate deployments.
- Database integration: Pass (MySQL config + queries throughout code).
- STAR schema & Analytics: Pass (file contains dims, fact, ETL, sample queries).
- All APIs routed via gateway: Pass (modules require `API_GATEWAY`).
- AI chatbot accessed via gateway: Pass.

## Recommendations (prioritized)
1. If judges require true microservices, containerize at least four services (books, chatbot, analytics, user) and update `api/index.php` to proxy requests to service endpoints (HTTP). This demonstrates independent deployments.
2. Create a small analytics dashboard page (UI) that queries the gateway for analytics endpoints (wrap sample queries as read-only API endpoints) and show charts (monthly borrows, top books, overdue rate).
3. Add a small README describing the demo steps, ETL schedule, and where to find key queries and endpoints.
4. Sanitize & rotate any hard-coded API keys (there are keys seen in code); use env/config and avoid committing secrets.

## Suggested next deliverables I can create
- Convert `includes/api_books.php` into a standalone `books-service` (example Docker + small PHP server) and update gateway to proxy to it.
- Add read-only analytics endpoints (e.g., `api/index.php?module=analytics&action=monthly_borrows`) that return pre-aggregated results from the STAR schema for the dashboard.
- Produce a demo README with commands and expected outputs.

---
File: [documentation/demo_evaluation.md](documentation/demo_evaluation.md)

If you want, I can now implement one of the recommended next steps (pick one).
