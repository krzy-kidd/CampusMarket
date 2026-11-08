# CampusTrade

CampusTrade is a fixed-price buying and selling marketplace for the Western
Mindanao State University community. The application is split into a React
frontend and a Laravel REST API.

## Requirements

- Node.js 20.19+ or 22.12+
- PHP 8.3+ with OpenSSL, cURL, Fileinfo, Mbstring, PDO MySQL, PDO SQLite,
  and ZIP enabled
- Composer 2
- MySQL 8+ or MariaDB 10.3+ (including XAMPP's bundled MariaDB)

## Local setup

1. Configure the local PHP and MySQL runtimes for your machine. Copy
   `backend/.env.example` to `backend/.env`, set the MySQL username/password,
   and ensure the `campustrade` database exists. Do not commit `backend/.env`.
2. From `backend`, configure the PHP executable for your terminal. For the
   PHP 8.4 installation used on this machine, run:
   ```powershell
   $phpDir = "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe"
   $env:PHPRC = $phpDir
   $php = Join-Path $phpDir "php.exe"
   $composer = Join-Path $env:LOCALAPPDATA "Programs\Composer\composer.phar"
   & $php $composer install
   & $php artisan key:generate
   ```
   If PHP or Composer is installed elsewhere, update their paths accordingly.
3. From `frontend`, run `npm install` and copy `.env.example` to `.env.local` if
   you need to change the API URL.
4. In a backend terminal where `$php` points to PHP, start the API with
   `& $php artisan serve --host=127.0.0.1 --port=8000`.
5. In another terminal, start the frontend with
   `npm run dev -- --host 127.0.0.1`.
6. In another backend terminal, run `& $php artisan schedule:work` so expired
   seller unlock requests are processed automatically.
7. Open the Vite URL and visit `/health` to check the API and MySQL connection.
   In production, configure Laravel's scheduler to run `schedule:run` every
   minute.

## Production configuration (Phase 12, Step 1)

`backend/.env.example` is for local development; its debug, database, mail, and
storage settings are not production defaults. Set production values through the
hosting platform's environment or secret manager, and never commit real
credentials.

- Set `APP_ENV=production`, `APP_DEBUG=false`, a persistent `APP_KEY`, and
  `APP_URL` to the HTTPS API origin. Generate the key once for the deployment
  and preserve it; replacing it can invalidate encrypted sessions and data.
- For the Sanctum session-based SPA, serve the frontend and API from the same
  registrable domain. Set `FRONTEND_URL` to the exact allowed HTTPS frontend
  origin or comma-separated origins, and list the matching frontend hostnames
  in `SANCTUM_STATEFUL_DOMAINS` without schemes. The first `FRONTEND_URL` origin
  receives email verification and password-reset links. Set
  `SESSION_SECURE_COOKIE=true`; configure `SESSION_DOMAIN` for the shared
  parent domain only when the frontend and API use different subdomains.
- Set `VITE_API_BASE_URL` to the HTTPS API origin before building the frontend.
  Vite embeds this value in the client bundle, so it must not contain secrets.
- Use a dedicated production database and a restricted database account.
  Configure a real mail transport and sender address; `MAIL_MAILER=log` only
  writes email contents to application logs.
- Configure `LISTING_IMAGE_DISK` to use persistent storage that can serve
  listing images. COR files are stored separately on Laravel's private `local`
  disk and are served through authorized endpoints; keep that storage private
  and persistent as well.

The application does not choose a hosting provider or production domains.
After setting deployment environment variables, cache Laravel's configuration
for production and rebuild the frontend with its production API URL.

## Storage, backups, and operations (Phase 12, Step 2)

- Persist both listing images and private COR documents outside ephemeral
  application storage. `LISTING_IMAGE_DISK` selects the listing-image disk;
  COR documents always use Laravel's private `local` disk and must remain
  inaccessible through public URLs. If deploying multiple application
  instances, use shared persistent storage for COR documents and ensure every
  instance can serve the configured listing-image URLs.
- Back up the production database and both file stores on a schedule suited to
  the data-loss tolerance. Database records reference uploaded files, so retain
  a coordinated backup or a recoverable storage history. Encrypt backups,
  restrict access, set retention, and periodically test a restore into an
  isolated environment. Verify restored COR documents remain private and
  accessible to authorized users.
- The scheduler expires unanswered seller unlock requests every minute.
  Configure one reliable scheduler invocation per minute using
  `php artisan schedule:run` (or a persistent `php artisan schedule:work`
  process). The task uses `withoutOverlapping`; if multiple instances may run
  the scheduler, configure a shared cache/lock store for all of them. The
  application includes database cache and lock tables; a single instance can
  also use a persistent local cache store. For multiple application instances,
  use a shared session store (the database `sessions` table is included) or
  otherwise ensure sessions are available to every instance.
- Current mail notifications run synchronously; the application does not
  currently require a queue worker for mail or in-app notifications. If queued
  jobs are introduced or `QUEUE_CONNECTION` is changed from `sync`, configure
  and monitor a worker, provision the selected queue tables/backend, and
  monitor failed jobs.
- Configure logs to be writable and retained or shipped to the hosting
  platform's log service. Use rotation/retention appropriate to the
  deployment, restrict log access, and avoid logging credentials or COR
  contents. The `/health` endpoint checks database connectivity only; it does
  not verify mail delivery, file storage, backups, or scheduler execution.

## Release smoke tests (Phase 12, Step 3)

Before a release, run the backend test suite and PHP formatter check from
`backend`, plus `npm run lint` and `npm run build` from `frontend`. On the
deployment's allowed frontend origin, smoke-test `/health`, public marketplace
loading, sign-in and registration form labels, the guest redirect from
`/notifications`, and the marketplace at a mobile viewport. Confirm there are
no CORS or uncaught browser errors.

Use dedicated staging accounts to check email verification, COR submission and
admin review, listing photo upload and serving, messaging, a completed
buyer/seller transaction and reviews, notification read actions, and
transaction-dispute resolution. Confirm the scheduled unlock-expiry task runs
and that file/database backups can be restored. Do not use real student
accounts or COR documents for release testing.

The local release checks cover automated backend scenarios and public,
unauthenticated browser screens. Authenticated browser checks and restore tests
require a provisioned staging environment; complete them before a production
launch.

## Database schema (Phase 2)

The schema includes students and admins, academic years and COR verifications,
categories, listings and images, private conversations and messages, purchase
transactions and event history, reviews, reports, notifications, and audit
logs. It stores only fixed-price listings; there are no trading or offer
tables. Active transactions have a unique listing claim, academic year dates
are protected against overlap, and completed/cancelled history is retained
through restrictive foreign keys.
The configured seller unlock-response window is stored in
`platform_settings` and defaults to 24 hours.

Run `& $php artisan migrate` from `backend` to apply the migrations. This adds
tables to the configured database and does not reset or erase existing data.
The default seeder does not create sample users.

## Authentication and student verification (Phase 3)

Run the additive migrations with `& $php artisan migrate`; do not use
`migrate:fresh` on a database containing data. Student registration currently
accepts the `WMSU_EMAIL_DOMAIN` value from `backend/.env` (default
`wmsu.edu.ph`). Confirm the official student email domain before production.
COR uploads are limited by `COR_MAX_FILE_SIZE_KILOBYTES` (default 10 MB) and
are stored on Laravel's private `local` disk, not in the public web directory.
Set the PHP `upload_max_filesize` to at least `10M` and `post_max_size` to at
least `12M` so PHP accepts uploads within the application limit.
New account and reset passwords must be at least 8 characters and contain
letters and numbers only.

Browser authentication uses Sanctum's session cookies. Frontend requests first
fetch `/sanctum/csrf-cookie`, then send credentialed requests to the API. Email
verification and password-reset links return to the frontend. The example
environment uses `MAIL_MAILER=log`; locally, messages are written to
`backend/storage/logs/laravel.log`. Configure a real SMTP mailer before sending
messages to students.

Create the initial administrator from the backend terminal with
`& $php artisan campustrade:bootstrap-super-admin`. The command prompts for
the account email and password; it cannot be called through the public API.
Verification review endpoints require an active verification administrator or
Super Admin session. COR submission is unavailable until an academic year is
marked current; a Super Admin configures academic years from the Phase 4
dashboard.

The API registration/login, email verification, password reset, COR upload and
history, private document access, and admin review endpoints are under
`/api/auth`. Review outcomes are recorded in verification history, notifications,
and audit logs.

## Admin dashboard (Phase 4)

After signing in with an active administrator account, open `/admin` (also
linked from the account page). Verification Admins can view the admin overview
and review pending COR submissions. Super Admins can also configure academic
years and categories, manage student account statuses and administrators,
resolve reports, and inspect audit logs. Super Admin operations are enforced
by server-side role middleware, not only hidden in the interface.

Create the initial Super Admin with
`& $php artisan campustrade:bootstrap-super-admin` when no active Super Admin
exists. Existing Super Admins can create Verification Admin accounts and
transfer the Super Admin role; transfer keeps a single active Super Admin.
Academic-year date overlaps and deleting categories/years with dependent data
are rejected.

## Marketplace and listings (Phase 5)

Open `/marketplace` to browse available fixed-price listings. Verified students
can create and manage listings from `/my-listings`; publishing and relisting
require an active account, a verified WMSU email, and an approved COR for the
current academic year. Listings require a leaf category, a positive PHP price,
a condition rating from 1 (Very good) to 4 (Poor), and 1–5 actual product photos
(JPG, PNG, or WebP; up to 5 MB each). Sellers can edit or archive available
listings. Archiving preserves the listing and transaction history; active or
sold listings cannot be edited or removed.
For local PHP uploads, set `upload_max_filesize` to at least `5M` and
`post_max_size` to at least `30M` so multi-photo forms fit within PHP's request
limit.

Run `& $php artisan db:seed` to add any missing standard marketplace
categories and subcategories. The seeder is additive and does not create
accounts or remove existing categories. For local development, create the
public image URL link once with `& $php artisan storage:link`. Listing photos
use `LISTING_IMAGE_DISK=public` by default; production can point this setting at
a configured S3-compatible disk. COR documents continue to use Laravel's
private local disk and are not exposed through listing image storage.

Public listing routes are `GET /api/categories`, `GET /api/listings`, and
`GET /api/listings/{id}`. Session-authenticated listing management is under
`/api/auth/listings`; Super Admins can inspect listings and archive prohibited
items in the dashboard.

## Search and discovery (Phase 6)

The `/marketplace` page supports keyword search, category and subcategory
filters, minimum and maximum PHP price, condition, sorting, and pagination.
`GET /api/listings` accepts `search`, `category_id`, `subcategory_id`,
`min_price`, `max_price`, `condition_rating`, `sort` (`newest`, `price_asc`,
or `price_desc`), and `page`. Selecting a parent category includes its
subcategories; selecting a subcategory narrows the results further. Results
are limited to 12 per page and include pagination metadata alongside the
`listings` array. Only listings with `available` status are returned.

## Private messaging (Phase 7)

Signed-in students can start a private conversation from an available listing,
view their inbox at `/messages`, and exchange text messages in the thread.
Conversations are shared only with the buyer and listing seller; users cannot
message themselves or access another student's conversation. Starting a
conversation requires an active account and an available listing. Messages are
limited to 2,000 characters, unread messages are marked when opened, and older
history and inbox conversations are cursor-paginated. Sign-in is required to
send or read private messages.

## Transaction and meetup coordination (Phase 8)

Buyers can start a fixed-price purchase request from a listing conversation.
The listing is reserved while the request is active. Buyer and seller propose
or confirm the same future meetup date, time, and public location before the
listing is locked for handoff. Both participants must confirm completion before
the listing is marked sold; either can cancel before completion, which releases
the listing. Transaction activity is retained for participants. Disputes and
the configurable seller-unlock response workflow are handled in Phase 9.

## Disputes and seller unlock requests (Phase 9)

After a meetup is arranged, a seller can request to release the listing. The
buyer can decline and keep the meetup, or accept to cancel and release the
listing. If the buyer does not respond before the configured deadline, the
scheduled task cancels the transaction and releases the listing. Sellers
cannot bypass this response window by directly cancelling an arranged meetup;
buyers can still cancel before completion.

Either participant can report a problem with an arranged meetup. The transaction
and listing remain reserved while it is under Super Admin review. A Super Admin
can resume the meetup arrangement or cancel the transaction and release the
listing; dismissed disputes resume the meetup. Dispute activity is retained in
the transaction history and the report queue.

The seller unlock response window defaults to 24 hours. Super Admins can update
it in `/admin` under Platform settings; valid values are 1-168 hours. Changing
the setting affects new requests only. The Laravel scheduler must run every
minute for unanswered requests to expire automatically.

## Reviews and ratings (Phase 10)

After a transaction is completed, each participant can submit one 1–5 star
review of the other participant, with an optional comment up to 1,000
characters. Reviews cannot be edited or submitted for cancelled, disputed, or
active transactions. Participants can view both reviews on the completed
transaction. Marketplace listings display the seller's average rating and
review count; sellers with no reviews are identified as not yet rated.

## Student notifications (Phase 11)

Signed-in students can view their in-app notifications at `/notifications`,
open related messages, transactions, or account details, mark items read, and
mark all notifications read. The inbox is cursor-paginated and displays the
unread count. New messages, transaction updates, received reviews, and COR
review decisions create notifications. The notification APIs require an active
account and only expose or update that account's notifications. Email delivery
is used only for the existing email-verification and password-reset flows.

## Tests

- Frontend: `npm run lint` and `npm run build` (from `frontend`)
- Backend: `& $php artisan test` (from `backend`, after setting `$php` as above)
