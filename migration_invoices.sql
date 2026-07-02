-- ============================================================================
-- migration_invoices.sql
-- Tabela faktur / historii rozliczeń per firma. Zasila zakładkę "Rozliczenia"
-- w widoku „Moje konto". Rekordy dodawane osobno (brak auto-generowania faktur).
--
-- Baza prod oakitpuware. CREATE TABLE IF NOT EXISTS — idempotentne.
-- ============================================================================

CREATE TABLE IF NOT EXISTS invoices (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    company_id   CHAR(36)      NOT NULL,
    number       VARCHAR(60)   NOT NULL,             -- numer faktury
    issued_at    DATE          NULL,                 -- data wystawienia
    period_start DATE          NULL,                 -- początek okresu rozliczeniowego
    period_end   DATE          NULL,                 -- koniec okresu rozliczeniowego
    amount       DECIMAL(10,2) NULL,                 -- kwota brutto
    currency     VARCHAR(3)    NOT NULL DEFAULT 'PLN',
    status       VARCHAR(20)   NOT NULL DEFAULT 'paid', -- paid / unpaid / cancelled
    file_url     VARCHAR(500)  NULL,                 -- link do PDF faktury (opcjonalnie)
    created_at   DATETIME      DEFAULT NOW(),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);
