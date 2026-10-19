# booking-api

[![CI](https://github.com/bygagashek/booking-api/actions/workflows/ci.yml/badge.svg)](https://github.com/bygagashek/booking-api/actions/workflows/ci.yml)

A table booking REST API in plain PHP 8.2 + MariaDB, without a framework. The point of the project is not CRUD
but the parts that break in production:

- **No double booking under concurrency.** Creation locks the table row (`SELECT … FOR UPDATE`), then checks
  for overlapping bookings. Two simultaneous requests for the same slot are serialized; the second one gets `409`.
- **Safe retries.** `POST /bookings` requires an `Idempotency-Key`. A retried request (timeout, double click,
  mobile network) gets the original response instead of a second booking.
- **Payment webhooks you can trust.** HMAC-SHA256 signature over timestamp + raw body, a 5-minute replay window,
  amount and currency checked against the booking, and each event applied at most once even when the provider
  delivers it twice in parallel.
- **Money as integers.** Amounts are stored in minor units (kopecks), never as floats.

## Run

```bash
docker compose up --build
```

The API listens on `http://localhost:8080`, the schema and four demo tables are loaded on first start.

```bash
curl -i -X POST localhost:8080/bookings \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: 6f1c2a9e-booking-1' \
  -d '{"table_id": 3, "guest_name": "Anna", "guest_phone": "+375291234567", "guests": 4,
       "starts_at": "2030-01-02T19:00:00+03:00", "ends_at": "2030-01-02T21:00:00+03:00",
       "deposit_amount": 5000, "deposit_currency": "BYN"}'
```

Run the same command again: the response is identical and carries `Idempotent-Replayed: true`.
Change the body and keep the key: `422`.

## API

| Method | Path | Description |
|---|---|---|
| `POST` | `/bookings` | Create a booking. Requires `Idempotency-Key`. `201`, `409` (slot taken), `422` (validation). |
| `GET` | `/bookings/{id}` | Get a booking. |
| `POST` | `/bookings/{id}/cancel` | Cancel a booking. Repeating the call is harmless. |
| `POST` | `/webhooks/payments` | Payment provider callback. Requires `X-Timestamp` and `X-Signature`. |

A booking with a deposit starts as `pending_payment` and becomes `confirmed` after a successful payment event.
Without a deposit it is `confirmed` at once.

Errors share one format:

```json
{"error": "Validation failed.", "fields": {"guests": "Must be an integer from 1 to 20."}}
```

### Webhook signature

```
X-Timestamp: 1767225600
X-Signature: sha256=<hex HMAC-SHA256(WEBHOOK_SECRET, "<X-Timestamp>.<raw body>")>
```

```json
{"event_id": "evt_123", "booking_id": 1, "amount": 5000, "currency": "BYN", "status": "succeeded"}
```

An event that cannot be applied (wrong amount or currency, booking cancelled or already paid) is stored as
`rejected` with a reason and answered with `200`: retrying it would not change the result, and the record is
what a person reviews to issue a refund.

## Design notes

**Why lock the table row and not the bookings.** Overlap is a check for the *absence* of rows. Locking
existing bookings does not stop a concurrent insert into the gap. Locking the parent `restaurant_tables` row
gives every booking of one table a single queue, while different tables do not block each other.

**Idempotency key lifecycle.** The key is reserved with an `INSERT` before the work starts, so the primary key
decides which of two concurrent requests runs. A retry that arrives while the first request is still running
gets `409`. Client errors (`4xx`) are stored and replayed, because the same request would fail the same way.
Server errors release the key, so the client can retry.

**Webhook deduplication.** The event id is the primary key of `payment_events`, written in the same
transaction as the booking status. The booking row is locked first, so two deliveries of one event are
serialized, and the second one sees the first one's record.

## Tests

Unit tests need nothing; integration tests run against a real MariaDB, because row locks and duplicate-key
errors cannot be faked. One test holds a lock from a second connection and checks that booking creation
waits for it.

```bash
docker compose up -d mariadb
composer install
DB_HOST=127.0.0.1 vendor/bin/phpunit
vendor/bin/phpstan analyse
```

CI runs both suites on PHP 8.2–8.4 with a MariaDB service container.

## Layout

```
src/
  App.php                       routes and error handling
  Http/                         Request, Response, Router, HttpException
  Booking/CreateBooking.php     input validation
  Booking/BookingService.php    create / get / cancel with row locking
  Idempotency/IdempotencyStore.php
  Payment/SignatureVerifier.php
  Payment/PaymentWebhookHandler.php
docker/mariadb/                 schema and seed data
```

## License

MIT
