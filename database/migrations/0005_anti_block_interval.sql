-- v1.4.0: intervalo anti-bloqueio entre clientes (aleatório entre mínimo e máximo, em minutos)
INSERT IGNORE INTO settings (skey, svalue) VALUES
('send_interval_min_minutes', '10'),
('send_interval_max_minutes', '15');
