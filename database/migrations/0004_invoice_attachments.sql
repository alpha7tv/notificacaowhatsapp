-- v1.3.0: anexos da cobrança (PDF da fatura, QR Code PIX e código copia e cola separado)
ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachments VARCHAR(60) NULL AFTER status_reason;

INSERT IGNORE INTO settings (skey, svalue) VALUES
('attach_pdf', '1'),
('attach_pix_qr', '1'),
('attach_pix_code', '1');

-- O código PIX passa a ir numa mensagem SEPARADA (fácil de copiar). Nos modelos padrão
-- (somente se ainda não foram editados), troca o bloco do PIX por um aviso.
UPDATE templates SET body = REPLACE(body,
  '{{#pix}}\n*PIX copia e cola:*\n{{pix}}\n{{/pix}}',
  '{{#pix}}\n👇 Logo abaixo enviamos a *fatura em PDF*, o *QR Code PIX* e o código *PIX copia e cola*.\n{{/pix}}')
WHERE code IN ('lembrete_vencimento', 'vence_hoje', 'fatura_atrasada');
