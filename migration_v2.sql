-- ============================================================
-- Migration v2: Multi-tenancy, Plans, Pools, UUID user IDs
-- Uruchom JEDNORAZOWO na bazie produkcyjnej przez phpMyAdmin
-- (Wklej całość do zakładki SQL i kliknij "Wykonaj")
-- ============================================================

-- ── 1. Plany ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS plans (
    id            VARCHAR(50)   PRIMARY KEY,
    name          VARCHAR(100)  NOT NULL,
    max_tires     INT           DEFAULT 5 COMMENT 'NULL = bez limitu',
    has_customers TINYINT(1)    DEFAULT 0,
    has_actions   TINYINT(1)    DEFAULT 0,
    price_monthly DECIMAL(10,2) DEFAULT 0.00,
    is_active     TINYINT(1)    DEFAULT 1,
    sort_order    INT           DEFAULT 0
);

INSERT INTO plans (id, name, max_tires, has_customers, has_actions, price_monthly, sort_order) VALUES
('free', 'Free', 5,    0, 0, 0.00, 1),
('mid',  'Mid',  15,   1, 0, 0.00, 2),
('max',  'Max',  NULL, 1, 1, 0.00, 3)
ON DUPLICATE KEY UPDATE name=VALUES(name), max_tires=VALUES(max_tires),
    has_customers=VALUES(has_customers), has_actions=VALUES(has_actions);

-- ── 2. Firmy ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS companies (
    id            CHAR(36)      PRIMARY KEY,
    name          VARCHAR(255)  NOT NULL,
    nip           VARCHAR(20)   DEFAULT NULL,
    address       VARCHAR(255)  DEFAULT NULL,
    city          VARCHAR(100)  DEFAULT NULL,
    postal_code   VARCHAR(10)   DEFAULT NULL,
    phone         VARCHAR(50)   DEFAULT NULL,
    email         VARCHAR(255)  NOT NULL,
    plan_id       VARCHAR(50)   NOT NULL DEFAULT 'max',
    trial_ends_at DATETIME      DEFAULT NULL,
    status        ENUM('pending','active','suspended') DEFAULT 'active',
    notes         TEXT          DEFAULT NULL,
    created_at    DATETIME      DEFAULT NOW(),
    FOREIGN KEY (plan_id) REFERENCES plans(id)
);

-- ── 3. Domyślna firma dla istniejących danych ─────────────────
-- Tworzymy jedną firmę i przypisujemy do niej wszystkie obecne dane.
-- UUID jest generowany tu — zapamiętaj go lub sprawdź po migracji:
--   SELECT id FROM companies ORDER BY created_at LIMIT 1;

INSERT INTO companies (id, name, email, plan_id, status)
SELECT
    UUID(),
    'Firma domyślna',
    COALESCE((SELECT email FROM users ORDER BY id LIMIT 1), 'admin@firma.pl'),
    'max',
    'active';

-- Zapisz ID domyślnej firmy do zmiennej sesji
SET @dc = (SELECT id FROM companies ORDER BY created_at LIMIT 1);

-- ── 4. Modyfikacja tabeli users ───────────────────────────────
ALTER TABLE users
    ADD COLUMN uuid       CHAR(36)     NULL          AFTER id,
    ADD COLUMN company_id CHAR(36)     NULL          AFTER uuid,
    ADD COLUMN status     ENUM('active','inactive','suspended') DEFAULT 'active' AFTER company_id;

UPDATE users SET uuid = UUID(), company_id = @dc;

ALTER TABLE users
    MODIFY COLUMN uuid       CHAR(36) NOT NULL,
    MODIFY COLUMN company_id CHAR(36) NOT NULL;

ALTER TABLE users ADD UNIQUE KEY uk_users_uuid (uuid);
ALTER TABLE users ADD CONSTRAINT fk_users_company
    FOREIGN KEY (company_id) REFERENCES companies(id);

-- ── 5. Migracja user_permissions na UUID ─────────────────────
-- Dodaj kolumnę tymczasową
ALTER TABLE user_permissions ADD COLUMN user_uuid CHAR(36) NULL;

-- Wypełnij UUID-ami
UPDATE user_permissions up
    JOIN users u ON u.id = up.user_id
    SET up.user_uuid = u.uuid;

ALTER TABLE user_permissions MODIFY COLUMN user_uuid CHAR(36) NOT NULL;

-- Usuń stary klucz obcy (jeśli istnieje).
-- Jeśli poniższe polecenie zwróci błąd, uruchom najpierw:
--   SHOW CREATE TABLE user_permissions;
-- i wstaw właściwą nazwę klucza obcego.
SET @fk_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_permissions'
      AND COLUMN_NAME = 'user_id'
      AND REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);
SET @drop_fk = IF(@fk_name IS NOT NULL,
    CONCAT('ALTER TABLE user_permissions DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT ''Brak FK do usuniecia'' AS info'
);
PREPARE s FROM @drop_fk; EXECUTE s; DEALLOCATE PREPARE s;

-- Zamień kolumny
ALTER TABLE user_permissions DROP COLUMN user_id;
ALTER TABLE user_permissions CHANGE COLUMN user_uuid user_id CHAR(36) NOT NULL;

-- ── 6. Zmiana PK users z INT na UUID ─────────────────────────
ALTER TABLE users DROP PRIMARY KEY;
ALTER TABLE users DROP COLUMN id;
ALTER TABLE users CHANGE COLUMN uuid id CHAR(36) NOT NULL;
ALTER TABLE users ADD PRIMARY KEY (id);

-- Przywróć FK na user_permissions
ALTER TABLE user_permissions ADD CONSTRAINT fk_up_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ── 7. Dodaj company_id do tabel z danymi ─────────────────────
ALTER TABLE tire_entries     ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE customers        ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE templates        ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE email_templates  ADD COLUMN company_id CHAR(36) NULL;
ALTER TABLE actions          ADD COLUMN company_id CHAR(36) NULL;

UPDATE tire_entries    SET company_id = @dc;
UPDATE customers       SET company_id = @dc;
UPDATE templates       SET company_id = @dc;
UPDATE email_templates SET company_id = @dc;
UPDATE actions         SET company_id = @dc;

ALTER TABLE tire_entries     MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE customers        MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE templates        MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE email_templates  MODIFY COLUMN company_id CHAR(36) NOT NULL;
ALTER TABLE actions          MODIFY COLUMN company_id CHAR(36) NOT NULL;

-- ── 8. Scoping tabeli settings ────────────────────────────────
ALTER TABLE settings ADD COLUMN company_id CHAR(36) NOT NULL DEFAULT '';
UPDATE settings SET company_id = @dc;
ALTER TABLE settings DROP PRIMARY KEY;
ALTER TABLE settings ADD PRIMARY KEY (company_id, `key`);

-- ── 9. Pools (grupy funkcjonalności) ──────────────────────────
CREATE TABLE IF NOT EXISTS pools (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description TEXT         DEFAULT NULL,
    created_at  DATETIME     DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS pool_features (
    pool_id      INT          NOT NULL,
    feature_name VARCHAR(100) NOT NULL,
    PRIMARY KEY (pool_id, feature_name),
    FOREIGN KEY (pool_id) REFERENCES pools(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS pool_members (
    pool_id  INT      NOT NULL,
    user_id  CHAR(36) NOT NULL,
    added_at DATETIME DEFAULT NOW(),
    PRIMARY KEY (pool_id, user_id),
    FOREIGN KEY (pool_id) REFERENCES pools(id)  ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE
);

-- ── 10. Tabela rejestracji (oczekujące wnioski) ───────────────
-- Firmy ze statusem 'pending' to wnioski rejestracyjne.
-- Dodajemy tylko tabelę registration_tokens dla linku aktywacyjnego.
CREATE TABLE IF NOT EXISTS registration_tokens (
    token      CHAR(64)     PRIMARY KEY,
    company_id CHAR(36)     NOT NULL,
    user_id    CHAR(36)     NOT NULL,
    created_at DATETIME     DEFAULT NOW(),
    used       TINYINT(1)   DEFAULT 0,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE
);

-- ============================================================
-- GOTOWE. Sprawdź wynik:
-- SELECT id, name, status FROM companies;
-- SELECT id, username, company_id, status FROM users;
-- ============================================================
