-- v1.5.0: fatura completa (PDF + QR Code PIX + copia e cola) só na regra marcada — por padrão, "antes do vencimento".
-- No dia do vencimento e nos atrasos vai apenas o texto de aviso. Suspensão continua só texto.
ALTER TABLE rules ADD COLUMN send_full TINYINT(1) NOT NULL DEFAULT 0 AFTER template_id;
UPDATE rules SET send_full = 1 WHERE event = 'before_due';

-- Textos padrão de "vence hoje" e "em atraso" sem anexos (só troca se o texto ainda for o padrão original).
UPDATE templates SET body = CONCAT(
  'Olá, {{primeiro_nome}}!\n\n',
  'Lembrando que sua fatura da *{{empresa}}* no valor de *R$ {{valor}}* vence *hoje ({{vencimento}})*.\n\n',
  'A fatura completa (PDF e PIX) foi enviada no lembrete anterior. Evite a suspensão do serviço. Se já pagou, desconsidere.\n',
  '{{#telefone_suporte}}Precisa da segunda via? Fale conosco: {{telefone_suporte}}{{/telefone_suporte}}'
), updated_at = NOW()
WHERE name = 'Vencimento hoje' AND body LIKE '%Logo abaixo enviamos a *fatura em PDF*%';

UPDATE templates SET body = CONCAT(
  'Olá, {{primeiro_nome}}.\n\n',
  'Identificamos que a fatura da *{{empresa}}* no valor de *R$ {{valor}}*, vencida em *{{vencimento}}*, ainda está em aberto ({{dias_atraso}} dia(s) de atraso).\n\n',
  'Regularize para evitar a suspensão da sua internet. Se já pagou, desconsidere.\n',
  '{{#telefone_suporte}}Precisa da segunda via? Fale conosco: {{telefone_suporte}}{{/telefone_suporte}}'
), updated_at = NOW()
WHERE name = 'Fatura em atraso' AND body LIKE '%Logo abaixo enviamos a *fatura em PDF*%';
