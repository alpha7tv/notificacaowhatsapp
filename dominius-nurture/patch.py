#!/usr/bin/env python3
"""Aplica as alterações da sequência de nutrição nos arquivos existentes (idempotente, com .bak-nurture)."""
import re
import shutil
import sys

import os
ROOT = os.environ.get('DP_ROOT', '/var/www/dominius-play/')


def read(rel):
    with open(ROOT + rel, encoding='utf-8') as f:
        return f.read()


def write(rel, content):
    shutil.copy2(ROOT + rel, ROOT + rel + '.bak-nurture')
    with open(ROOT + rel, 'w', encoding='utf-8') as f:
        f.write(content)


def once(rel, marker, find, repl, regex=False):
    src = read(rel)
    if marker in src:
        print('[ok, já aplicado] ' + rel + ' :: ' + marker)
        return
    n = len(re.findall(find, src)) if regex else src.count(find)
    if n != 1:
        sys.exit('ERRO: em %s o trecho esperado apareceu %d vez(es) (deveria ser 1): %s' % (rel, n, find[:70]))
    new = re.sub(find, repl, src, count=1) if regex else src.replace(find, repl, 1)
    write(rel, new)
    print('[alterado] ' + rel + ' :: ' + marker)


# 1) Consentimento de marketing gravado junto da solicitação de teste
once('app/Services/TestRequestService.php', 'markMarketing($id, $request)',
     'self::sendVerification($id, false);',
     'self::markMarketing($id, $request);\n        self::sendVerification($id, false);')
once('app/Services/TestRequestService.php', "markMarketing((int) $pending['id']",
     "$sent = self::sendVerification((int) $pending['id'], true);",
     "self::markMarketing((int) $pending['id'], $request);\n            $sent = self::sendVerification((int) $pending['id'], true);")
once('app/Services/TestRequestService.php', 'function markMarketing',
     'private static function recordBlocked(',
     """private static function markMarketing(int $id, Request $request): void
    {
        try {
            if ($request->bool('marketing')) {
                Database::query('UPDATE test_requests SET marketing_consent_at = UTC_TIMESTAMP() WHERE id = :id AND marketing_consent_at IS NULL', ['id' => $id]);
            }
        } catch (\\Throwable $e) {
            Logger::warning('Falha ao registrar consentimento de marketing', ['e' => $e->getMessage()]);
        }
    }

    private static function recordBlocked(""")

# 2) Matrícula na sequência quando o teste é gerado com sucesso
once('app/Services/TestGenerationService.php', 'NurtureService::enroll',
     r"self::deliver\(\$requestId\);(\s*)return 'generated';",
     "self::deliver($requestId);\n            NurtureService::enroll($requestId);\\1return 'generated';",
     regex=True)

# 3) Worker: envios da sequência a cada minuto
once('bin/worker.php', 'NurtureService::runDue',
     '    // 6) Manutenção diária: retenção LGPD e limpeza',
     """    // 5b) Sequência de nutrição (WhatsApp de entrada + e-mails a cada 2 dias)
    $nurtured = \\App\\Services\\NurtureService::runDue(20);
    if ($nurtured) {
        $out("sequência de nutrição: {$nurtured} envio(s)");
    }

    // 6) Manutenção diária: retenção LGPD e limpeza""")

# 4) Rotas de descadastro
once('routes/web.php', 'NurtureController',
     "$router->get('/obrigado', [TestController::class, 'thanks']);",
     "$router->get('/obrigado', [TestController::class, 'thanks']);\n"
     "$router->get('/descadastrar/{token:[a-f0-9]+}', [\\App\\Controllers\\NurtureController::class, 'show']);\n"
     "$router->post('/descadastrar/{token:[a-f0-9]+}', [\\App\\Controllers\\NurtureController::class, 'confirm'], [VerifyCsrf::class]);")

# 5) Checkbox de marketing no formulário do teste
once('app/Views/pages/test-form.php', "partial('marketing-consent')",
     "<?= partial('consent') ?>",
     "<?= partial('consent') ?>\n          <?= partial('marketing-consent') ?>")

print('\nPronto.')
