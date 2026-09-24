-- Dados padrão: modelos de mensagem, regras e configurações iniciais.
-- INSERT IGNORE: nunca sobrescreve o que o administrador já editou.

INSERT IGNORE INTO templates (code, name, body) VALUES
('lembrete_vencimento', 'Lembrete antes do vencimento',
'Olá, {{primeiro_nome}}! 👋\n\nPassando para lembrar que sua fatura da *{{empresa}}* no valor de *R$ {{valor}}* vence em *{{vencimento}}* ({{dias_para_vencer}} dia(s)).\n{{#pix}}\n*PIX copia e cola:*\n{{pix}}\n{{/pix}}{{#linha_digitavel}}\n*Linha digitável:*\n{{linha_digitavel}}\n{{/linha_digitavel}}{{#link_boleto}}\n*Boleto:* {{link_boleto}}\n{{/link_boleto}}\nSe já pagou, por favor desconsidere esta mensagem.\nDúvidas: {{telefone_suporte}}');

INSERT IGNORE INTO templates (code, name, body) VALUES
('vence_hoje', 'Vencimento hoje',
'Olá, {{primeiro_nome}}!\n\nSua fatura da *{{empresa}}* no valor de *R$ {{valor}}* vence *hoje ({{vencimento}})*.\n{{#pix}}\n*PIX copia e cola:*\n{{pix}}\n{{/pix}}{{#linha_digitavel}}\n*Linha digitável:*\n{{linha_digitavel}}\n{{/linha_digitavel}}\nEvite a suspensão do serviço. Se já pagou, desconsidere.\nDúvidas: {{telefone_suporte}}');

INSERT IGNORE INTO templates (code, name, body) VALUES
('fatura_atrasada', 'Fatura em atraso',
'Olá, {{primeiro_nome}}.\n\nIdentificamos que a fatura da *{{empresa}}* no valor de *R$ {{valor}}*, vencida em *{{vencimento}}*, ainda está em aberto ({{dias_atraso}} dia(s) de atraso).\n{{#pix}}\n*PIX copia e cola:*\n{{pix}}\n{{/pix}}{{#linha_digitavel}}\n*Linha digitável:*\n{{linha_digitavel}}\n{{/linha_digitavel}}\nRegularize para evitar a suspensão da sua internet. Se já pagou, desconsidere.\nDúvidas: {{telefone_suporte}}');

INSERT IGNORE INTO templates (code, name, body) VALUES
('pagamento_confirmado', 'Pagamento confirmado',
'Olá, {{primeiro_nome}}! ✅\n\nConfirmamos o pagamento da sua fatura de *R$ {{valor_pago}}* (vencimento {{vencimento}}), recebido em {{data_pagamento}}.\n\nObrigado por ser cliente *{{empresa}}*!');

INSERT IGNORE INTO templates (code, name, body) VALUES
('contrato_suspenso', 'Contrato suspenso',
'Olá, {{primeiro_nome}}.\n\nO serviço de internet do contrato *{{contrato}}* foi *suspenso* por pendência financeira.\nApós o pagamento, a liberação é automática. Precisa da segunda via? Fale conosco: {{telefone_suporte}}');

INSERT IGNORE INTO templates (code, name, body) VALUES
('contrato_cancelado', 'Contrato cancelado',
'Olá, {{primeiro_nome}}.\n\nInformamos que o contrato *{{contrato}}* com a *{{empresa}}* foi *cancelado*.\nSe houver dúvidas, fale conosco: {{telefone_suporte}}');

INSERT IGNORE INTO templates (code, name, body) VALUES
('contrato_reativado', 'Contrato reativado',
'Olá, {{primeiro_nome}}! 🎉\n\nSeu contrato *{{contrato}}* foi *reativado* e a internet já está liberada.\nObrigado por continuar com a *{{empresa}}*!');

INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'lembrete_d3', 'Lembrete 3 dias antes', 'before_due', 3, id FROM templates WHERE code = 'lembrete_vencimento';
INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'vence_hoje', 'Vence hoje', 'due_today', 0, id FROM templates WHERE code = 'vence_hoje';
INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'atraso_d1', 'Atraso de 1 dia', 'after_due', 1, id FROM templates WHERE code = 'fatura_atrasada';
INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'atraso_d5', 'Atraso de 5 dias', 'after_due', 5, id FROM templates WHERE code = 'fatura_atrasada';
INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'pagamento', 'Pagamento confirmado', 'payment_confirmed', 0, id FROM templates WHERE code = 'pagamento_confirmado';
INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'suspensao', 'Suspensão', 'suspended', 0, id FROM templates WHERE code = 'contrato_suspenso';
INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'cancelamento', 'Cancelamento', 'cancelled', 0, id FROM templates WHERE code = 'contrato_cancelado';
INSERT IGNORE INTO rules (code, name, event, offset_days, template_id)
SELECT 'reativacao', 'Reativação', 'reactivated', 0, id FROM templates WHERE code = 'contrato_reativado';

INSERT IGNORE INTO settings (skey, svalue) VALUES
('mode', 'homologation'),
('company_name', 'Fiber Link'),
('support_phone', ''),
('test_number', ''),
('homologation_daily_limit', '30'),
('send_interval_seconds', '10'),
('send_max_per_hour', '200'),
('send_days', '1,2,3,4,5,6'),
('catchup_days', '1'),
('sgp_url', ''),
('sgp_app', ''),
('sgp_titles_path', '/api/ura/titulos/'),
('sgp_customer_path', '/api/ura/consultacliente/'),
('sgp_sync_interval_minutes', '30'),
('sgp_sync_batch', '200'),
('evolution_url', ''),
('evolution_instance', ''),
('evolution_version', 'v2'),
('log_retention_days', '90');

INSERT IGNORE INTO scheduled_tasks (name, description, interval_seconds) VALUES
('heartbeat', 'Sinal de vida do scheduler', 60),
('sgp_sync', 'Sincronização de clientes, contratos e faturas com o SGP', 1800),
('plan_due', 'Agendamento das mensagens de vencimento/atraso', 600),
('reconcile_payments', 'Reconciliação de pagamentos (cancela lembretes de faturas pagas)', 900),
('reconcile_status', 'Reconciliação de status de contratos', 900),
('requeue_stale', 'Devolve à fila mensagens travadas em processamento', 300),
('cleanup', 'Limpeza de temporários, logs e sessões antigas', 86400);
