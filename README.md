
pass = 5685

# Lanay Restaurant System

Lanay is a restaurant ordering system with a PHP 8.2 and MariaDB backend for staff sign-in, menu management, table orders, kitchen order status, inventory, and payments.

## What has been added and changed

### Backend and database

- Added the MariaDB schema and starter menu/table data in [`database/schema.sql`](./database/schema.sql).
- Added PHP API endpoints for staff sign-in and sessions, menu management, tables, order creation, and order status.
- Added hashed staff PINs, role-based access, CSRF protection, and login rate limiting.
- Added a command-line tool to create staff accounts: `tools/create_staff.php`.
- Order creation checks current menu prices, item availability, portions, and table capacity in a database transaction. Portions are reduced when an order is placed.
- Added staff editing, PIN resets, deactivation/reactivation, and protection against deactivating the last active owner.
- Added ingredient stock, low-stock thresholds, recipes, restock/adjustment history, and transactional ingredient deductions when orders are submitted. Orders are rejected if recipe stock is insufficient.
- Added full-order payments by cash, GCash, or card. Cash change is calculated by the server; a paid order cannot be paid again.
- Added per-table QR codes for customer menu access and ordering. Customer orders go directly to the kitchen and can be tracked from the ordering device. QR encoding is served locally.

### Connected screens

- Sign-in now uses the staff account API and routes staff according to their role.
- The menu, ordering, tables, kitchen, and owner menu-management screens use live backend data for their supported workflows.
- The owner staff screen lists accounts, creates and edits staff logins, and deactivates/reactivates accounts. PINs are validated, checked for duplicates within the role, and stored as password hashes.
- The kitchen can move orders from New to Preparing to Ready; waiters can mark ready orders as served.
- Cashiers can record a single full payment for a served order and open its receipt.
- Owners can manage ingredients, stock, recipes, staff accounts, and printable table QR codes. The owner menu overview now reads live menu and stock availability.
- Added visible keyboard focus indicators and subtle hover feedback; improved small-screen navigation, reports, and settings layouts.

### Fixes and current limits

- Fixed quick-note whitespace handling in the order screen.
- Removed the old “Clear this table” action because it implied an order was paid without recording a payment.
- Sales reports, delivery, senior/PWD discounts, and other prototype screens that still show sample data are not connected to backend workflows.

## Run locally with XAMPP

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Import [`database/schema.sql`](./database/schema.sql) in phpMyAdmin. It creates the `lanay_db` database and sample menu/table data. The SQL directory is blocked from direct web access.
3. If you already have a Lanay database, back it up and import [`database/migrations/001_add_staff_inventory_payment_and_qr_features.sql`](./database/migrations/001_add_staff_inventory_payment_and_qr_features.sql) once instead of re-importing the schema. The migration adds the inventory, payment, and customer QR-ordering tables and columns without replacing existing orders or accounts.
4. Copy `config.local.example.php` to `config.local.php` and set your local database credentials. `config.local.php` is ignored by Git. The example uses the standard XAMPP `root` account with no password for local development only; use a dedicated database account and password outside a private development machine.
5. From the project folder, create the first staff account in a terminal:

   ```powershell
   C:\xampp\php\php.exe tools\create_staff.php owner "Store Owner"
   ```

   Enter a unique 4-digit PIN when prompted. Repeat for waiter, kitchen, or cashier accounts as needed. PINs are stored as password hashes. After five failed sign-in attempts, the app locks sign-in for two minutes and shows a countdown. The 4-digit PIN is retained to match the prototype; production use should move to longer credentials, HTTPS, and a dedicated least-privilege database account.
6. Open `http://localhost/WTS_iot/` in your browser and sign in with the staff role and PIN.

## New workflows

- **Staff:** Open the owner Staff screen to edit a name or role, reset a PIN, deactivate an account, or reactivate it. Deactivated users lose access immediately; the final active owner cannot be deactivated.
- **Inventory:** Create ingredients with units in g, mL, or each; add stock; define menu recipes; and review low-stock warnings and stock movements. Recipe quantities are deducted in the same transaction as the order.
- **Payments:** Cashiers and owners can collect payment once an order is served. Cash, GCash, and card are supported; cash payments record the amount received and server-calculated change.
- **Customer QR orders:** Owners can print the codes from the Table QR screen. Customers scan a table's code, select menu items, and submit an order directly to the kitchen. The ordering browser keeps its order-tracking token locally; rotating a table code invalidates its previous code.

## Backend endpoints

- `POST /api/auth/login.php` and `POST /api/auth/logout.php`
- `GET /api/auth/session.php` reports the signed-in staff session and its CSRF token
- `GET /api/menu.php`; owner-only `POST /api/menu.php` creates, edits, archives, or changes availability
- Owner-only `GET` and `POST /api/staff.php` lists, creates, edits, deactivates, and reactivates staff accounts
- `GET /api/tables.php` returns table status for signed-in waiter, cashier, or owner accounts
- `GET` and `POST /api/orders.php` read and create orders; creation rechecks menu prices, availability, and portions in a database transaction
- `POST /api/orders/status.php` advances orders through kitchen and waiter status transitions
- `GET` and `POST /api/inventory.php` manage ingredients, stock movements, and recipes (owner only)
- `GET` and `POST /api/table-qr.php` list and rotate table QR tokens (owner only)
- `GET /api/customer-order.php` returns a menu for a valid table token; `POST /api/orders.php` accepts customer QR orders
- `GET /api/customer-order-status.php` returns order progress for its tracking token
- `GET` and `POST /api/payments.php` read and record payments (cashier or owner only)

The order, kitchen, cashier, inventory, owner, and customer QR-ordering screens use these APIs. Some unrelated report and delivery screens remain prototypes.
