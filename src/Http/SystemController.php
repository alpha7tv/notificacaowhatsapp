<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Db;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Services\HealthService;
use App\Services\Scheduler;
use App\Services\ServerStatus;
use App\Services\SystemJobs;

final class SystemController
{
    public static function server(): never
    {
        Auth::require(true);
        View::page('system/server', [
            'title' => 'Sistema › Servidor',
            'st' => ServerStatus::collect(),
            'integrations' => HealthService::integrations(false),
            'tasks' => Db::all('SELECT * FROM scheduled_tasks ORDER BY name'),
            'lastDeploy' => Db::one('SELECT * FROM deployments ORDER BY id DESC LIMIT 1'),
        ]);
    }

    public static function runTask(): never
    {
        Auth::require(true);
        $name = Request::str('task');
        if (Db::value('SELECT name FROM scheduled_tasks WHERE name = ?', [$name])) {
            Scheduler::trigger($name);
            Audit::log('task.trigger', 'scheduled_task', $name);
            Response::flash('success', "Rotina \"$name\" agendada para o próximo minuto.");
        }
        Response::redirect('/sistema/servidor');
    }

    public static function updates(): never
    {
        Auth::require(true);
        $jobs = SystemJobs::recent(15);
        View::page('system/updates', [
            'title' => 'Sistema › Atualizações',
            'st' => ServerStatus::collect(),
            'jobs' => $jobs,
            'deployments' => Db::all('SELECT * FROM deployments ORDER BY id DESC LIMIT 10'),
        ]);
    }

    public static function requestJob(): never
    {
        $u = Auth::require(true);
        $type = Request::str('type');
        if ($type === 'update') {
            $upd = ServerStatus::statusFile('update');
            if (!($upd['update_available'] ?? false)) {
                Response::flash('error', 'Não há atualização disponível. Clique em "Verificar atualização" primeiro.');
                Response::redirect('/sistema/atualizacoes');
            }
            if (!Auth::confirmPassword((string) ($_POST['password'] ?? ''))) {
                Response::flash('error', 'Senha incorreta.');
                Response::redirect('/sistema/atualizacoes');
            }
        }
        [$ok, $msg] = SystemJobs::request($type, (int) $u['id']);
        Response::flash($ok ? 'success' : 'error', $msg);
        Response::redirect('/sistema/atualizacoes');
    }

    public static function job(string $id): never
    {
        Auth::require(true);
        $job = Db::one('SELECT j.*, u.name AS user_name FROM system_jobs j LEFT JOIN users u ON u.id = j.requested_by WHERE j.id = ?', [(int) $id]);
        if (!$job) {
            View::page('errors/404', ['title' => 'Não encontrado'], 404);
        }
        View::page('system/job', ['title' => 'Tarefa #' . $job['id'], 'job' => $job, 'log' => SystemJobs::logTail($job, 300)]);
    }

    public static function logs(): never
    {
        Auth::require(true);
        $cat = Request::str('categoria');
        $level = Request::str('nivel');
        $q = Request::str('q');
        $where = ['1=1'];
        $params = [];
        if (isset(Logger::CATEGORIES[$cat])) {
            $where[] = 'l.category = ?';
            $params[] = $cat;
        }
        if (in_array($level, Logger::LEVELS, true)) {
            $where[] = "FIELD(l.level,'debug','info','warning','error','critical') >= FIELD(?,'debug','info','warning','error','critical')";
            $params[] = $level;
        }
        if ($q !== '') {
            $where[] = 'l.message LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $w = implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM app_logs l WHERE $w", $params);
        $page = max(1, Request::int('p', 1));
        $pages = max(1, (int) ceil($total / 100));
        $offset = (min($page, $pages) - 1) * 100;
        $rows = Db::all("SELECT l.*, u.name AS user_name FROM app_logs l LEFT JOIN users u ON u.id = l.user_id WHERE $w ORDER BY l.id DESC LIMIT 100 OFFSET $offset", $params);
        View::page('system/logs', ['title' => 'Sistema › Logs', 'rows' => $rows, 'cat' => $cat, 'level' => $level, 'q' => $q, 'page' => min($page, $pages), 'pages' => $pages, 'total' => $total]);
    }

    public static function webhooks(): never
    {
        Auth::require(true);
        $source = Request::str('origem');
        $params = [];
        $where = '1=1';
        if (in_array($source, ['sgp', 'whatsapp'], true)) {
            $where = 'source = ?';
            $params[] = $source;
        }
        View::page('system/webhooks', [
            'title' => 'Sistema › Webhooks',
            'rows' => Db::all("SELECT * FROM webhook_events WHERE $where ORDER BY id DESC LIMIT 100", $params),
            'source' => $source,
        ]);
    }

    public static function settings(): never
    {
        Auth::require(true);
        View::page('system/settings', ['title' => 'Sistema › Configurações', 's' => Settings::all()]);
    }

    public static function saveSettings(): never
    {
        $u = Auth::require(true);
        $days = array_values(array_intersect(array_map('strval', (array) ($_POST['send_days'] ?? [])), ['1', '2', '3', '4', '5', '6', '7']));
        $values = [
            'company_name' => mb_substr(Request::str('company_name'), 0, 100) ?: 'Fiber Link',
            'support_phone' => mb_substr(Request::str('support_phone'), 0, 60),
            'send_interval_seconds' => (string) max(3, min(300, Request::int('send_interval_seconds', 10))),
            'send_max_per_hour' => (string) max(1, min(2000, Request::int('send_max_per_hour', 200))),
            'send_days' => implode(',', $days ?: ['1', '2', '3', '4', '5', '6']),
            'catchup_days' => (string) max(0, min(5, Request::int('catchup_days', 1))),
            'log_retention_days' => (string) max(7, min(3650, Request::int('log_retention_days', 90))),
            'attach_pdf' => isset($_POST['attach_pdf']) ? '1' : '0',
            'attach_pix_qr' => isset($_POST['attach_pix_qr']) ? '1' : '0',
            'attach_pix_code' => isset($_POST['attach_pix_code']) ? '1' : '0',
        ];
        foreach ($values as $k => $v) {
            Settings::set($k, $v, $u['id']);
        }
        Audit::log('settings.save', 'settings', 'general', $values);
        Response::flash('success', 'Configurações salvas.');
        Response::redirect('/sistema/configuracoes');
    }

    public static function users(): never
    {
        Auth::require(true);
        View::page('system/users', [
            'title' => 'Sistema › Usuários',
            'users' => Db::all('SELECT id, name, email, role, active, last_login_at, created_at FROM users ORDER BY name'),
            'edit' => Request::int('editar') ? Db::one('SELECT id, name, email, role, active FROM users WHERE id = ?', [Request::int('editar')]) : null,
        ]);
    }

    public static function saveUser(): never
    {
        $me = Auth::require(true);
        $id = Request::int('id');
        $name = mb_substr(Request::str('name'), 0, 120);
        $email = mb_strtolower(Request::str('email'));
        $role = Request::str('role') === 'operador' ? 'operador' : 'admin';
        $active = isset($_POST['active']) ? 1 : 0;
        $password = (string) ($_POST['password'] ?? '');
        $back = '/sistema/usuarios' . ($id ? '?editar=' . $id : '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::flash('error', 'Nome e e-mail válidos são obrigatórios.');
            Response::redirect($back);
        }
        if ((!$id && mb_strlen($password) < 10) || ($password !== '' && mb_strlen($password) < 10)) {
            Response::flash('error', 'A senha deve ter pelo menos 10 caracteres.');
            Response::redirect($back);
        }
        if (Db::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
            Response::flash('error', 'E-mail já cadastrado.');
            Response::redirect($back);
        }
        if ($id === (int) $me['id'] && ($role !== 'admin' || !$active)) {
            Response::flash('error', 'Você não pode remover seu próprio acesso de administrador.');
            Response::redirect($back);
        }
        $data = ['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active];
        if ($password !== '') {
            $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($id) {
            Db::update('users', $data, 'id = ?', [$id]);
        } else {
            $id = Db::insert('users', $data);
        }
        Audit::log('user.save', 'user', (string) $id, ['email' => $email, 'role' => $role, 'active' => $active, 'password_changed' => $password !== '']);
        Response::flash('success', 'Usuário salvo.');
        Response::redirect('/sistema/usuarios');
    }

    public static function audit(): never
    {
        Auth::require(true);
        View::page('system/audit', [
            'title' => 'Sistema › Auditoria',
            'rows' => Db::all('SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 200'),
        ]);
    }
}
