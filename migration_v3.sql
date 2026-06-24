-- ============================================================
-- Migration v3: Support panel — tickets, impersonation, billing
-- Uruchom przez phpMyAdmin (zakładka SQL)
-- ============================================================

-- 1. Rola super_admin w users
ALTER TABLE users MODIFY COLUMN role ENUM('pracownik','admin','super_admin') NOT NULL DEFAULT 'pracownik';

-- 2. Daty rozliczeniowe w companies
ALTER TABLE companies
    ADD COLUMN billing_date    DATE DEFAULT NULL,
    ADD COLUMN next_billing_at DATE DEFAULT NULL;

-- 3. Tickety
CREATE TABLE IF NOT EXISTS tickets (
    id         INT          AUTO_INCREMENT PRIMARY KEY,
    company_id CHAR(36)     NOT NULL,
    user_id    CHAR(36)     NOT NULL,
    subject    VARCHAR(255) NOT NULL,
    status     ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    priority   ENUM('low','normal','high','urgent')           DEFAULT 'normal',
    created_at DATETIME     DEFAULT NOW(),
    updated_at DATETIME     DEFAULT NOW() ON UPDATE NOW(),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE
);

-- 4. Wiadomości ticketów
CREATE TABLE IF NOT EXISTS ticket_messages (
    id         INT      AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT      NOT NULL,
    author_id  CHAR(36) NOT NULL,
    is_support TINYINT(1) DEFAULT 0,
    message    TEXT     NOT NULL,
    created_at DATETIME DEFAULT NOW(),
    FOREIGN KEY (ticket_id) REFERENCES tickets(id)  ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id)    ON DELETE CASCADE
);

-- 5. Tokeny impersonacji
CREATE TABLE IF NOT EXISTS impersonation_tokens (
    token          CHAR(64)  NOT NULL,
    target_user_id CHAR(36)  NOT NULL,
    created_by     CHAR(36)  NOT NULL,
    created_at     DATETIME  DEFAULT NOW(),
    used           TINYINT(1) DEFAULT 0,
    expires_at     DATETIME  NOT NULL,
    PRIMARY KEY (token),
    FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)     REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- GOTOWE. Sprawdź:
--   SHOW TABLES LIKE 'ticket%';
--   SHOW TABLES LIKE 'impersonation%';
--   DESCRIBE companies;
-- ============================================================
