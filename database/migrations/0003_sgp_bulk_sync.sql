-- v1.2.0: sincronização em massa com o SGP (listagem paginada de títulos filtrada por vencimento)
INSERT IGNORE INTO settings (skey, svalue) VALUES
('sgp_sync_mode', 'bulk'),
('sgp_window_past_days', '45'),
('sgp_window_future_days', '15'),
('sgp_customer_refresh_hours', '3');

-- Consultas frequentes por documento e por telefone ainda não consultado
CREATE INDEX IF NOT EXISTS idx_customers_sync_doc ON customers (last_synced_at, document);
