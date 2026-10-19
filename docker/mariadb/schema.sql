CREATE TABLE IF NOT EXISTS restaurant_tables (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(50)  NOT NULL,
    seats     TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    table_id         INT UNSIGNED NOT NULL,
    guest_name       VARCHAR(100) NOT NULL,
    guest_phone      VARCHAR(20)  NOT NULL,
    guests           TINYINT UNSIGNED NOT NULL,
    starts_at        DATETIME     NOT NULL,
    ends_at          DATETIME     NOT NULL,
    deposit_amount   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'minor units (kopecks)',
    deposit_currency CHAR(3)      NOT NULL DEFAULT 'BYN',
    status           ENUM('pending_payment', 'confirmed', 'cancelled') NOT NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_table_time (table_id, starts_at, ends_at),
    CONSTRAINT fk_bookings_table FOREIGN KEY (table_id) REFERENCES restaurant_tables (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per Idempotency-Key: the first request reserves the key, the result is stored for replays.
CREATE TABLE IF NOT EXISTS idempotency_keys (
    idempotency_key VARCHAR(64)  NOT NULL PRIMARY KEY,
    request_hash    CHAR(64)     NOT NULL,
    status          ENUM('processing', 'done') NOT NULL,
    response_code   SMALLINT UNSIGNED NULL,
    response_body   TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every delivered payment event, unique by provider event id: a redelivery is detected by the primary key.
CREATE TABLE IF NOT EXISTS payment_events (
    event_id    VARCHAR(64)  NOT NULL PRIMARY KEY,
    booking_id  INT UNSIGNED NOT NULL,
    amount      INT UNSIGNED NOT NULL,
    currency    CHAR(3)      NOT NULL,
    result      ENUM('applied', 'rejected') NOT NULL,
    reason      VARCHAR(255) NULL,
    received_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
