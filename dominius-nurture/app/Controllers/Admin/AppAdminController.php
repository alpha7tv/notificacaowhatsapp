<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Request;
use App\Services\AppService;

/**
 * Painel → Aplicativo: clientes e códigos de ativação, avisos e publicidade, configurações e versões do APK.
 * Restrito a administradores (rotas dentro do grupo AdminOnly).
 */
final class AppAdminController extends AdminController
{
    // ------------------------------------------------------------------ visão geral

    public function index(Request $request): void
    {
        $now = Database::now();
        $stats = [
            'active' => (int) Database::value("SELECT COUNT(*) FROM app_clients WHERE status = 'active' AND (expires_at IS NULL OR expires_at >= :n)", ['n' => $now]),
            'trial' => (int) Database::value("SELECT COUNT(*) FROM app_clients WHERE status = 'active' AND is_trial = 1 AND (expires_at IS NULL OR expires_at >= :n)", ['n' => $now]),
            'expired' => (int) Database::value("SELECT COUNT(*) FROM app_clients WHERE expires_at IS NOT NULL AND expires_at < :n", ['n' => $now]),
            'blocked' => (int) Database::value("SELECT COUNT(*) FROM app_clients WHERE status = 'blocked'"),
            'devices' => (int) Database::value('SELECT COUNT(*) FROM app_devices'),
            'notices' => (int) Database::value('SELECT COUNT(*) FROM app_notices WHERE is_active = 1'),
        ];
        $this->view('app/index', ['stats' => $stats, 'version' => AppService::latestVersion()], 'Aplicativo');
    }

    // ------------------------------------------------------------------ clientes

    public function clients(Request $request): void
    {
        $q = mb_substr(trim((string) ($request->query['q'] ?? '')), 0, 100);
        $filter = (string) ($request->query['f'] ?? '');
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = '(LOWER(c.name) LIKE :q1 OR LOWER(c.email) LIKE :q2 OR c.code LIKE :q3 OR LOWER(c.username) LIKE :q4 OR c.phone LIKE :q5)';
            foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) {
                $params[$k] = '%' . mb_strtolower($q) . '%';
            }
        }
        $now = Database::now();
        if ($filter === 'trial') {
            $where[] = 'c.is_trial = 1';
        } elseif ($filter === 'expired') {
            $where[] = 'c.expires_at IS NOT NULL AND c.expires_at < :now';
            $params['now'] = $now;
        } elseif ($filter === 'blocked') {
            $where[] = "c.status = 'blocked'";
        }
        $rows = Database::fetchAll(
            'SELECT c.*, (SELECT COUNT(*) FROM app_devices d WHERE d.client_id = c.id) AS devices
             FROM app_clients c' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.id DESC LIMIT 300',
            $params
        );
        $this->view('app/clients', ['rows' => $rows, 'q' => $q, 'f' => $filter], 'Clientes do app');
    }

    public function clientForm(Request $request, string $id = ''): void
    {
        $client = null;
        $devices = [];
        if ($id !== '') {
            $client = Database::fetch('SELECT * FROM app_clients WHERE id = :id', ['id' => (int) $id]);
            if (!$client) {
                flash('error', 'Cliente não encontrado.');
                redirect('/admin/meu-app/clientes');
            }
            $devices = Database::fetchAll('SELECT * FROM app_devices WHERE client_id = :c ORDER BY last_seen_at DESC', ['c' => $client['id']]);
        }
        $this->view('app/client_form', ['client' => $client, 'devices' => $devices, 'defaultServer' => AppService::cfg('default_server', '')], $client ? 'Editar cliente' : 'Novo cliente');
    }

    public function clientSave(Request $request, string $id = ''): void
    {
        $p = $request->post;
        $name = trim((string) ($p['name'] ?? ''));
        $user = trim((string) ($p['username'] ?? ''));
        $email = trim((string) ($p['email'] ?? ''));
        $back = $id !== '' ? '/admin/meu-app/clientes/' . (int) $id : '/admin/meu-app/clientes/novo';
        if ($name === '') {
            flash('error', 'Informe o nome do cliente.');
            redirect($back);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'E-mail inválido.');
            redirect($back);
        }
        $expires = self::localToUtc((string) ($p['expires'] ?? ''));
        $data = [
            'name' => mb_substr($name, 0, 150),
            'email' => $email !== '' ? $email : null,
            'phone' => ($t = trim((string) ($p['phone'] ?? ''))) !== '' ? mb_substr($t, 0, 30) : null,
            'server_url' => ($s = trim((string) ($p['server_url'] ?? ''))) !== '' ? AppService::cleanServer($s) : null,
            'username' => $user !== '' ? mb_substr($user, 0, 120) : null,
            'expires_at' => $expires,
            'is_trial' => !empty($p['is_trial']) ? 1 : 0,
            'status' => ($p['status'] ?? '') === 'blocked' ? 'blocked' : 'active',
            'max_devices' => max(1, min(10, (int) ($p['max_devices'] ?? 2))),
            'notes' => ($n = trim((string) ($p['notes'] ?? ''))) !== '' ? $n : null,
            'updated_at' => Database::now(),
        ];
        $pass = (string) ($p['password'] ?? '');
        if ($pass !== '') {
            $data['password_enc'] = Crypto::encrypt($pass);
        }
        if ($id !== '') {
            Database::update('app_clients', $data, 'id = :id', ['id' => (int) $id]);
            audit('app_client_update', 'app_client', (int) $id);
            flash('success', 'Cliente atualizado.');
            redirect('/admin/meu-app/clientes/' . (int) $id);
        }
        $data['code'] = AppService::newCode();
        $data['created_at'] = Database::now();
        $newId = Database::insert('app_clients', $data);
        audit('app_client_create', 'app_client', $newId);
        flash('success', 'Cliente criado. Código de ativação: ' . $data['code']);
        redirect('/admin/meu-app/clientes/' . $newId);
    }

    public function clientCode(Request $request, string $id): void
    {
        Database::update('app_clients', ['code' => AppService::newCode(), 'updated_at' => Database::now()], 'id = :id', ['id' => (int) $id]);
        audit('app_client_newcode', 'app_client', (int) $id);
        flash('success', 'Novo código gerado. O código antigo deixou de funcionar para novos aparelhos.');
        redirect('/admin/meu-app/clientes/' . (int) $id);
    }

    public function clientDevicesClear(Request $request, string $id): void
    {
        Database::delete('app_devices', 'client_id = :c', ['c' => (int) $id]);
        audit('app_client_devices_clear', 'app_client', (int) $id);
        flash('success', 'Aparelhos liberados. O cliente precisa digitar o código de novo.');
        redirect('/admin/meu-app/clientes/' . (int) $id);
    }

    public function clientDelete(Request $request, string $id): void
    {
        Database::delete('app_devices', 'client_id = :c', ['c' => (int) $id]);
        Database::delete('app_clients', 'id = :id', ['id' => (int) $id]);
        audit('app_client_delete', 'app_client', (int) $id);
        flash('success', 'Cliente excluído.');
        redirect('/admin/meu-app/clientes');
    }

    // ------------------------------------------------------------------ avisos e publicidade

    public function notices(Request $request): void
    {
        $rows = Database::fetchAll('SELECT * FROM app_notices ORDER BY is_active DESC, sort_order, id DESC');
        $this->view('app/notices', ['rows' => $rows], 'Avisos e publicidade');
    }

    public function noticeForm(Request $request, string $id = ''): void
    {
        $n = null;
        if ($id !== '') {
            $n = Database::fetch('SELECT * FROM app_notices WHERE id = :id', ['id' => (int) $id]);
            if (!$n) {
                flash('error', 'Aviso não encontrado.');
                redirect('/admin/meu-app/avisos');
            }
        }
        $this->view('app/notice_form', ['n' => $n], $n ? 'Editar aviso' : 'Novo aviso');
    }

    public function noticeSave(Request $request, string $id = ''): void
    {
        $p = $request->post;
        $back = $id !== '' ? '/admin/meu-app/avisos/' . (int) $id : '/admin/meu-app/avisos/novo';
        $title = trim((string) ($p['title'] ?? ''));
        if ($title === '') {
            flash('error', 'Informe o título.');
            redirect($back);
        }
        $link = trim((string) ($p['link_url'] ?? ''));
        if ($link !== '' && !preg_match('~^https?://~i', $link)) {
            flash('error', 'O link precisa começar com http:// ou https://');
            redirect($back);
        }
        $existing = $id !== '' ? Database::fetch('SELECT * FROM app_notices WHERE id = :id', ['id' => (int) $id]) : null;
        $image = $existing['image_path'] ?? null;
        if (!empty($p['remove_image'])) {
            $this->deleteFile($image);
            $image = null;
        }
        if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = AppService::saveUpload($_FILES['image'], 'image');
            if (!$up['ok']) {
                flash('error', $up['error'] ?? 'Falha no envio da imagem.');
                redirect($back);
            }
            $this->deleteFile($image);
            $image = $up['file'];
        }
        $kind = isset(AppService::NOTICE_KINDS[$p['kind'] ?? '']) ? $p['kind'] : 'info';
        $placement = isset(AppService::PLACEMENTS[$p['placement'] ?? '']) ? $p['placement'] : 'home';
        $audience = isset(AppService::AUDIENCES[$p['audience'] ?? '']) ? $p['audience'] : 'all';
        $data = [
            'kind' => $kind,
            'placement' => $placement,
            'audience' => $audience,
            'title' => mb_substr($title, 0, 150),
            'body' => ($b = trim((string) ($p['body'] ?? ''))) !== '' ? mb_substr($b, 0, 2000) : null,
            'image_path' => $image,
            'link_url' => $link !== '' ? mb_substr($link, 0, 255) : null,
            'link_label' => ($l = trim((string) ($p['link_label'] ?? ''))) !== '' ? mb_substr($l, 0, 40) : null,
            'starts_at' => self::localToUtc((string) ($p['starts'] ?? '')),
            'ends_at' => self::localToUtc((string) ($p['ends'] ?? '')),
            'is_active' => !empty($p['is_active']) ? 1 : 0,
            'sort_order' => (int) ($p['sort_order'] ?? 0),
            'updated_at' => Database::now(),
        ];
        if ($existing) {
            Database::update('app_notices', $data, 'id = :id', ['id' => (int) $id]);
            audit('app_notice_update', 'app_notice', (int) $id);
            flash('success', 'Aviso atualizado.');
        } else {
            $data['created_at'] = Database::now();
            $newId = Database::insert('app_notices', $data);
            audit('app_notice_create', 'app_notice', $newId);
            flash('success', 'Aviso criado.');
        }
        redirect('/admin/meu-app/avisos');
    }

    public function noticeToggle(Request $request, string $id): void
    {
        Database::query('UPDATE app_notices SET is_active = 1 - is_active, updated_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => (int) $id]);
        redirect('/admin/meu-app/avisos');
    }

    public function noticeDelete(Request $request, string $id): void
    {
        $n = Database::fetch('SELECT image_path FROM app_notices WHERE id = :id', ['id' => (int) $id]);
        if ($n) {
            $this->deleteFile($n['image_path']);
        }
        Database::delete('app_notices', 'id = :id', ['id' => (int) $id]);
        audit('app_notice_delete', 'app_notice', (int) $id);
        flash('success', 'Aviso excluído.');
        redirect('/admin/meu-app/avisos');
    }

    // ------------------------------------------------------------------ configurações

    public function config(Request $request): void
    {
        $values = [];
        foreach (AppService::cfgFields() as $k => [$label, $default]) {
            $values[$k] = AppService::cfg($k, $default);
        }
        $this->view('app/config', ['fields' => AppService::cfgFields(), 'values' => $values], 'Configurações do app');
    }

    public function configSave(Request $request): void
    {
        foreach (AppService::cfgFields() as $k => [$label, $default]) {
            $v = trim((string) ($request->post[$k] ?? ''));
            if ($k === 'support_whatsapp' || $k === 'downloader_code') {
                $v = preg_replace('/\D+/', '', $v) ?? '';
            }
            if ($k === 'default_server' && $v !== '') {
                $v = AppService::cleanServer($v);
            }
            if ($k === 'max_devices') {
                $v = (string) max(1, min(10, (int) $v));
            }
            AppService::setCfg($k, mb_substr($v, 0, 500));
        }
        audit('app_config');
        flash('success', 'Configurações salvas.');
        redirect('/admin/meu-app/configuracoes');
    }

    // ------------------------------------------------------------------ versões do APK

    public function versions(Request $request): void
    {
        $rows = Database::fetchAll('SELECT * FROM app_versions ORDER BY version_code DESC');
        $this->view('app/versions', ['rows' => $rows, 'nextCode' => (int) ($rows[0]['version_code'] ?? 0) + 1], 'Versões do app');
    }

    public function versionSave(Request $request): void
    {
        $p = $request->post;
        $code = (int) ($p['version_code'] ?? 0);
        $name = trim((string) ($p['version_name'] ?? ''));
        if ($code < 1 || $name === '' || !preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $name)) {
            flash('error', 'Informe o número e o nome da versão (ex.: 12 e 1.2.0).');
            redirect('/admin/meu-app/versoes');
        }
        if ((int) Database::value('SELECT COUNT(*) FROM app_versions WHERE version_code = :c', ['c' => $code]) > 0) {
            flash('error', 'Já existe uma versão com esse número. Use um número maior.');
            redirect('/admin/meu-app/versoes');
        }
        $up = AppService::saveUpload($_FILES['apk'] ?? null, 'apk');
        if (!$up['ok']) {
            flash('error', $up['error'] ?? 'Falha no envio do APK.');
            redirect('/admin/meu-app/versoes');
        }
        $id = Database::insert('app_versions', [
            'version_code' => $code,
            'version_name' => $name,
            'file_name' => $up['file'],
            'size_bytes' => $up['size'],
            'sha256' => $up['sha256'],
            'notes' => ($n = trim((string) ($p['notes'] ?? ''))) !== '' ? mb_substr($n, 0, 1000) : null,
            'mandatory' => !empty($p['mandatory']) ? 1 : 0,
            'created_at' => Database::now(),
        ]);
        audit('app_version_upload', 'app_version', $id);
        flash('success', "Versão {$name} publicada. O link /app já baixa esta versão.");
        redirect('/admin/meu-app/versoes');
    }

    public function versionDelete(Request $request, string $id): void
    {
        $v = Database::fetch('SELECT * FROM app_versions WHERE id = :id', ['id' => (int) $id]);
        if ($v) {
            $this->deleteFile($v['file_name']);
            Database::delete('app_versions', 'id = :id', ['id' => (int) $id]);
            audit('app_version_delete', 'app_version', (int) $id);
            flash('success', 'Versão excluída.');
        }
        redirect('/admin/meu-app/versoes');
    }

    // ------------------------------------------------------------------ utilidades

    private function deleteFile(?string $name): void
    {
        if ($name) {
            @unlink(AppService::apkDir() . '/' . basename($name));
        }
    }

    /** "2026-10-09T14:30" (horário de Brasília, campo datetime-local) → UTC, ou null se vazio/ inválido. */
    public static function localToUtc(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable(str_replace('T', ' ', $s), new \DateTimeZone('America/Sao_Paulo')))
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function utcToLocal(?string $utc): string
    {
        if (!$utc) {
            return '';
        }
        return (new \DateTimeImmutable($utc . ' UTC'))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d\TH:i');
    }
}
