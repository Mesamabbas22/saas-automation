# WhatsApp Order Bot — multi-tenant SaaS

Small businesses in Pakistan take orders on WhatsApp through a bot. Customers browse
products, pick a quantity, send an address and confirm, all inside WhatsApp. Owners manage
orders, products and customers in their own panel. A super admin manages businesses,
WhatsApp accounts and plans.

- **Laravel 13**, PHP 8.3, MySQL
- **Official WhatsApp Cloud API** (Meta Graph API) only, with no unofficial libraries
- **Filament 5** with two panels: `/admin` (super admin) and `/app/{business}` (owners and staff)
- **Queue:** `database` driver locally (free). Switch to Redis with one `.env` change.
- **Local dev cost: zero.** You need a Meta test number, ngrok's free plan and WAMP, Laragon or XAMPP.

---

## Contents

1. [How it works](#1-how-it-works)
2. [Local setup](#2-local-setup)
3. [Meta (WhatsApp Cloud API) setup](#3-meta-whatsapp-cloud-api-setup)
4. [Expose the webhook with ngrok](#4-expose-the-webhook-with-ngrok)
5. [Run everything](#5-run-everything)
6. [Default logins](#6-default-logins)
7. [.env reference](#7-env-reference)
8. [Tests](#8-tests)
9. [Troubleshooting](#9-troubleshooting)
10. [Going to production](#10-going-to-production)

---

## 1. How it works

```
Customer ──WhatsApp──▶ Meta ──POST /webhook──▶ VerifyWhatsAppSignature ──▶ WebhookController
                                                                              │  200 "EVENT_RECEIVED" right away
                                                                              ▼
                                         queue: ProcessIncomingMessage (one job per payload)
                                           • find tenant from metadata.phone_number_id
                                           • TenantContext::run($tenant, …)
                                           • skip duplicates (wa_message_id)
                                           • log to message_logs
                                           • BotEngine → Steps → WhatsAppClient ──▶ Graph API ──▶ Customer
```

**Bot flow** ([app/Services/Bot/](app/Services/Bot/)):

```
Menu ─any msg─▶ Product ─list pick─▶ Quantity ─1/2/3─▶ Address ─text─▶ Confirm ─✅─▶ order saved, order # sent
                                                                                ─❌─▶ reset
```
- Typing `menu`, `hi` or `salam` restarts the flow from any step. So does going silent for more than 24 hours.
- Replies are in simple Roman Urdu and English. Edit them in [BotReplies.php](app/Services/Bot/BotReplies.php).

**Multi-tenancy** (single database, every tenant-owned table has `tenant_id`):

| Piece | Role |
|---|---|
| [TenantContext](app/Services/TenantContext.php) | Holds the current tenant, with one instance per request or job |
| [BelongsToTenant](app/Models/Concerns/BelongsToTenant.php) | Global scope plus auto-fill of `tenant_id`. Refuses to write into another tenant |
| [SetTenantContext](app/Http/Middleware/SetTenantContext.php) | In `/app`, copies `Filament::getTenant()` into `TenantContext`, including on Livewire requests |
| `ProcessIncomingMessage` | Sets `TenantContext` from the webhook's `phone_number_id` |
| `User::canAccessPanel` / `canAccessTenant` | super_admin can use `/admin` only. owner and staff can use `/app` for their own active tenant only |

Inside `/app`, Filament's own tenancy scoping also applies. That gives two independent
layers, and the test suite checks both.

**24-hour window:** `conversation_states.last_message_at` is updated on every customer message.
Changing an order status in the panel sends a free-form text inside the window. Outside it,
the panel sends the approved utility template (see [3.6](#36-create-the-order-update-template)).

---

## 2. Local setup

**Requirements:**
- PHP 8.2 or newer, with the extensions `pdo_mysql`, `intl`, `zip`, `gd`, `fileinfo` and `openssl`
- Composer 2
- MySQL 8 (WAMP, Laragon or XAMPP)

Node is **not** required, because Filament's assets are published as static files.

```powershell
git clone <your-repo> saas-automation
cd saas-automation
composer install
copy .env.example .env
php artisan key:generate
```

**Create the database.** In phpMyAdmin or the MySQL console, run:

```sql
CREATE DATABASE wa_order_bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**Edit `.env`.** At minimum, set:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3308          # WAMP's MySQL default is 3308; Laragon/XAMPP use 3306
DB_DATABASE=wa_order_bot
DB_USERNAME=root
DB_PASSWORD=your-mysql-password
```

**Migrate, seed and link storage:**

```powershell
php artisan migrate --seed
php artisan storage:link     # needed for product image uploads
```

> **Xdebug users.** With Xdebug 3.3 in `develop` mode, PHP can crash with a segfault on HTTP
> connection errors. Set `xdebug.mode=off` (or `debug`) in your `php.ini`, or upgrade Xdebug to 3.4
> or newer. `composer test` already turns Xdebug off for the test run.

---

## 3. Meta (WhatsApp Cloud API) setup

Everything below is free. The test number can message up to 5 verified recipient numbers.

### 3.1 Create the app
1. Go to <https://developers.facebook.com/apps> and click **Create app**.
2. For the use case, pick **Other**, then the app type **Business**.
3. Name it (for example "Order Bot Dev") and create it.
4. On the app dashboard, find **WhatsApp** and click **Set up**. Choose or create a Meta Business portfolio.

### 3.2 Get the test number credentials
Open **WhatsApp → API Setup**. You will see:

| On the page | Put in `.env` as |
|---|---|
| **Temporary access token** (valid about 24h) | `DEMO_WA_ACCESS_TOKEN` |
| **Phone number ID** (under the "From" test number) | `DEMO_WA_PHONE_NUMBER_ID` |
| **WhatsApp Business Account ID** | `DEMO_WA_WABA_ID` |

In the **To** field, click **Manage phone number list** and add your own WhatsApp number.
Enter the code Meta sends you.

### 3.3 App Secret (webhook signature)
Go to **App settings → Basic → App Secret → Show**, then put it in `WHATSAPP_APP_SECRET`.

Every webhook POST is checked against it. If the secret is empty, all POSTs are rejected.

### 3.4 Verify token
Generate any random string and put it in `WHATSAPP_VERIFY_TOKEN`. You will type the same
string into Meta in step 4.

### 3.5 Load the demo values
```powershell
php artisan config:clear
php artisan db:seed      # attaches your test number + token to "Lahore Biryani House"
```

Check the connection. Either use the **Test** button in `/admin → WhatsApp accounts`, or send
the pre-approved `hello_world` template:

```powershell
php artisan whatsapp:send-test lahore-biryani 92XXXXXXXXXX --type=template
```

### 3.6 Create the order-update template
Status updates outside the 24-hour window need an approved template. Go to
**WhatsApp Manager → Message templates → Create template** and use these settings:

- **Category:** Utility
- **Name:** `order_status_update` (must match `WHATSAPP_ORDER_UPDATE_TEMPLATE`)
- **Language:** English (`en`, must match `WHATSAPP_TEMPLATE_LANGUAGE`)
- **Body:** `Assalam o Alaikum! Your order {{1}} status is now: {{2}}. Shukriya!`
  - `{{1}}` is filled with the order number and `{{2}}` with the status.

Approval usually takes minutes. Until it is approved, updates inside the 24-hour window still work.

### 3.7 Permanent token (when you outgrow the temporary one)
1. Go to **Business settings → Users → System users → Add** (role: Admin).
2. Click **Assign assets**, select your app and WhatsApp account, and give full control.
3. Click **Generate token**, select the app, and grant `whatsapp_business_messaging` and `whatsapp_business_management`. Set no expiry.
4. Paste the token into the business's WhatsApp account in `/admin`. It is stored encrypted.

---

## 4. Expose the webhook with ngrok

Meta needs a public HTTPS URL. ngrok's free plan provides one.

```powershell
# one-time: install from https://ngrok.com/download, then
ngrok config add-authtoken <your-free-authtoken>

ngrok http 8000
```

Copy the `https://….ngrok-free.app` URL. Then, in Meta, go to **WhatsApp → Configuration → Webhook → Edit** and enter:

| Field | Value |
|---|---|
| Callback URL | `https://<your-ngrok-domain>/webhook` |
| Verify token | the value of `WHATSAPP_VERIFY_TOKEN` |

Click **Verify and save**, then under **Webhook fields** click **Subscribe** next to **messages**.

> The free ngrok URL changes every time you restart ngrok. When it does, update the Callback URL in Meta.

---

## 5. Run everything

Use three terminals:

```powershell
php artisan serve                                  # http://localhost:8000
$env:XDEBUG_MODE='off'; php artisan queue:work     # processes webhooks; restart after code changes
ngrok http 8000
```

From your phone, send **hi** to the test number. You should get these in order:
1. the product list;
2. the quantity buttons;
3. an address request;
4. the order summary with Confirm/Cancel;
5. after you tap Confirm, the order number.

The order then appears in `/app/lahore-biryani/orders`. Use **Change status** there and the
update arrives on your phone.

To watch traffic, open **`/app/{business}/message-logs`** for the full JSON of every message in
and out, or read `storage/logs/whatsapp.log` for API errors.

---

## 6. Default logins

These come from `php artisan db:seed`. **Every password is `password`.** Change them before going live.

| Panel | URL | Email | Can see |
|---|---|---|---|
| Super admin | <http://localhost:8000/admin> | `admin@orderbot.test` | All businesses, WhatsApp accounts, plans, subscriptions, usage |
| Business: Lahore Biryani House | <http://localhost:8000/app> | `owner@lahore-biryani.test` | Only Lahore's orders, products, customers, logs |
| Business: Karachi Kapra Store | <http://localhost:8000/app> | `owner@karachi-kapra.test` | Only Karachi's data |

The super admin cannot open `/app`, and owners cannot open `/admin` (both get 403).
Opening another business's URL gives a 404.

**Adding a new business:** go to `/admin → Businesses → New`. One form creates the business,
the owner login, the WhatsApp account and the first subscription.

---

## 7. .env reference

| Key | Required | Description |
|---|---|---|
| `DB_*` | yes | MySQL connection |
| `QUEUE_CONNECTION` | yes | `database` locally. Use `redis` in production (also set `REDIS_*`) |
| `WHATSAPP_APP_SECRET` | yes | Meta App Secret. Used to verify `X-Hub-Signature-256` |
| `WHATSAPP_VERIFY_TOKEN` | yes | Any random string. Must match the Meta webhook setting |
| `WHATSAPP_GRAPH_VERSION` | no | Graph API version, default `v23.0` |
| `WHATSAPP_ORDER_UPDATE_TEMPLATE` | no | Utility template for status updates outside 24h, default `order_status_update` |
| `WHATSAPP_TEMPLATE_LANGUAGE` | no | Template language code, default `en` |
| `WHATSAPP_HTTP_TIMEOUT` | no | Seconds, default `15` |
| `DEMO_WA_PHONE_NUMBER_ID` / `DEMO_WA_WABA_ID` / `DEMO_WA_ACCESS_TOKEN` | local only | Seeder attaches these to the first demo business |

Per-business tokens are **not** in `.env`. They live encrypted in `whatsapp_accounts.access_token`,
encrypted with `APP_KEY`. If you change `APP_KEY`, you must re-enter every token.

---

## 8. Tests

```powershell
composer test
```

The suite has 127 tests and runs on in-memory SQLite. The Graph API is always mocked: the base
`TestCase` blocks any real HTTP request.

| Test file | Covers |
|---|---|
| `WebhookTest` | `GET /webhook` verification, signature check (missing, wrong, tampered body, no secret), CSRF exclusion |
| `ProcessIncomingMessageTest` | Tenant resolved from `phone_number_id`, duplicate `wa_message_id` ignored, delivery statuses |
| `WhatsAppClientTest` | Text, buttons, list, template and read-receipt payloads, error handling, token per tenant |
| `BotFlowTest` | Every bot step, `menu` reset, invalid input, 24h window, inactive and foreign products |
| `OrderFlowEndToEndTest` | Full order through the signed `POST /webhook` route, then the owner dispatches it from the panel. Meta retries don't duplicate anything |
| `TenantScopeTest` / `TenantIsolationTest` | Scope and auto-fill, every `/app` resource and widget isolated per tenant (owner and staff), bot isolation |
| `PanelAccessTest` | super_admin can use `/admin` only, owner and staff `/app` only, suspended tenants blocked |
| `BusinessPanelTest` / `AdminPanelTest` / `OrderStatusChangeTest` | Panel pages and actions, tenant onboarding form, token never shown, template fallback |

To run the suite against MySQL instead, first create a `wa_order_bot_test` database:

```powershell
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='wa_order_bot_test'; composer test
```

---

## 9. Troubleshooting

| Symptom | Fix |
|---|---|
| Meta says **"callback URL or verify token couldn't be validated"** | Check that `php artisan serve` and ngrok are running and that the token matches `.env`. Run `php artisan config:clear`. Test with `curl "https://<ngrok>/webhook?hub.mode=subscribe&hub.verify_token=<token>&hub.challenge=1"` |
| Webhook arrives but **no reply** | Is `queue:work` running? Check `storage/logs/laravel.log` and `whatsapp.log` |
| `whatsapp.log` shows **code 190** | The token expired. Temporary tokens last about 24h. Paste a new one, or use a permanent token ([3.7](#37-permanent-token-when-you-outgrow-the-temporary-one)) |
| **403 Invalid signature** in the logs | `WHATSAPP_APP_SECRET` is wrong, or config is cached. Run `php artisan config:clear` |
| **code 131047** | The customer hasn't messaged in 24h. Only templates are allowed. Status updates switch to the template automatically |
| **code 131030** "recipient not in allowed list" | Add the number under API Setup → "To" → Manage phone number list |
| Webhook logged as **"unknown phone_number_id"** | The business's Phone number ID in `/admin → WhatsApp accounts` doesn't match the number Meta is sending from |
| Product images don't show | Run `php artisan storage:link`, and check that `APP_URL` matches the URL you browse |
| `php artisan test` exits silently or **segfaults** | Xdebug `develop` mode. Use `composer test`, or see the note in [section 2](#2-local-setup) |
| Code changes not picked up by the bot | Restart `queue:work`. Workers keep old code in memory |

---

## 10. Going to production

- **Queue:** set `QUEUE_CONNECTION=redis` and run `php artisan queue:work --tries=3` under Supervisor (or a similar process manager). Nothing in the code changes.
- **Cache:** set `CACHE_STORE=redis`. The bot uses cache locks per customer.
- **Tokens:** use permanent System User tokens, one per business.
- **Domain:** use a real HTTPS domain for the webhook instead of ngrok.
- **Security:** change all seeded passwords and set `APP_DEBUG=false`.
- **Templates:** submit `order_status_update` in each language you use.
- **Limits:** plan message limits are shown on the `/admin` dashboard but not enforced. Add a check in `WhatsAppClient` before you sell plans.

---

## Project structure

```
app/
├── Enums/                         OrderStatus, PaymentMethod, UserRole, TenantStatus, …
├── Http/
│   ├── Controllers/WebhookController.php
│   └── Middleware/                VerifyWhatsAppSignature, SetTenantContext
├── Jobs/ProcessIncomingMessage.php
├── Models/                        Tenant, User, Product, Order, … (+ Concerns/BelongsToTenant.php)
├── Services/
│   ├── TenantContext.php
│   ├── TenantProvisioner.php      creates tenant + owner + WA account in one transaction
│   ├── OrderService.php           place order, change status (+ notify)
│   ├── WhatsApp/                  WhatsAppClient, MessageLogger, OrderStatusNotifier, SendResult, PhoneNumber
│   └── Bot/                       BotEngine, BotContext, IncomingMessage, BotReplies, Steps/
├── Filament/
│   ├── Admin/                     /admin: Tenants, WhatsAppAccounts, Plans, Subscriptions, usage widgets
│   └── App/                       /app:   Orders (+ Change status), Products, Customers, MessageLogs, widgets
config/whatsapp.php                Graph version, app secret, verify token, template name
```
