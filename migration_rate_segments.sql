-- ============================================================================
-- migration_rate_segments.sql
-- Segmenty stawek w obrębie okresu rozliczeniowego — do proporcjonalnego
-- rozliczenia, gdy pakiet zmienił się w trakcie okresu (część okresu wg niższej,
-- część wg wyższej stawki).
--
-- Segment tworzony jest dopiero przy zmianie stawki w trakcie okresu (upgrade
-- self-service lub zmiana planu przez support). Gdy brak segmentów → rozliczenie
-- wg pojedynczej stawki period_rate (bez proracji).
--
-- Baza prod oakitpuware. CREATE TABLE IF NOT EXISTS — idempotentne.
-- ============================================================================

CREATE TABLE IF NOT EXISTS billing_rate_segments (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    company_id   CHAR(36)      NOT NULL,
    period_start DATE          NOT NULL,          -- billing_date okresu, do którego należy segment
    seg_start    DATE          NOT NULL,          -- od kiedy obowiązuje stawka
    rate         DECIMAL(10,2) NOT NULL,          -- stawka miesięczna
    created_at   DATETIME      DEFAULT NOW(),
    KEY idx_company_period (company_id, period_start),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);
