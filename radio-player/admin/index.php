<?php
declare(strict_types=1);

/*
 * Painel do Rádio Player.
 *  - Master (dono da plataforma): cadastra clientes, vencimentos e limites; pode entrar no painel de qualquer cliente.
 *  - Cliente: edita só o próprio app (rádios, estilos, publicidade, aparência e senha).
 */

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/nowplaying.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('rpadmin');
session_set_cookie_params([
    'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
    'httponly' => true,
    'secure' => $https,
    'samesite' => 'Lax',
]);
session_start();

// ---------- Helpers ----------

function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf()) . '">';
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = [$msg, $type];
}

function go(string $query = ''): never
{
    header('Location: ./' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
}

function login_blocked(): int
{
    $a = rp_read_php(RP_LOGIN)[client_ip()] ?? null;
    if (!$a || $a['count'] < 5) {
        return 0;
    }
    $left = $a['first'] + 900 - time();
    return max(0, $left);
}

function login_failed(): void
{
    rp_locked(function () {
        $all = array_filter(rp_read_php(RP_LOGIN), fn ($a) => $a['first'] + 900 > time());
        $ip = client_ip();
        $all[$ip] = isset($all[$ip]) ? ['count' => $all[$ip]['count'] + 1, 'first' => $all[$ip]['first']] : ['count' => 1, 'first' => time()];
        rp_write_php(RP_LOGIN, $all);
    });
}

function login_reset(): void
{
    rp_locked(function () {
        $all = rp_read_php(RP_LOGIN);
        unset($all[client_ip()]);
        rp_write_php(RP_LOGIN, $all);
    });
}

function find_index(array $list, string $id): int
{
    foreach ($list as $i => $item) {
        if (($item['id'] ?? '') === $id) {
            return $i;
        }
    }
    return -1;
}

function move_item(array $list, string $id, int $dir): array
{
    $i = find_index($list, $id);
    $j = $i + $dir;
    if ($i >= 0 && $j >= 0 && $j < count($list)) {
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
    }
    return $list;
}

function img_url(?string $path): string
{
    if (!$path) {
        return '';
    }
    return preg_match('~^https?://~i', $path) ? $path : '../' . $path;
}

function base_url(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
}

function app_url(string $slug): string
{
    return base_url() . 'r/' . $slug . '/';
}

const CLIENT_STATES = ['active' => 'Ativo', 'suspended' => 'Suspenso', 'expired' => 'Vencido'];

/** Dias até o vencimento (negativo = vencido há N dias); null se não vence. */
function days_left(array $c): ?int
{
    if (empty($c['expires'])) {
        return null;
    }
    return (int) round((strtotime($c['expires']) - strtotime(date('Y-m-d'))) / 86400);
}

function due_label(array $c): string
{
    $d = days_left($c);
    if ($d === null) {
        return 'sem vencimento';
    }
    $date = date('d/m/Y', strtotime($c['expires']));
    return match (true) {
        $d < 0 => "venceu em {$date} (há " . -$d . ' dia' . ($d === -1 ? '' : 's') . ')',
        $d === 0 => "vence hoje ({$date})",
        $d === 1 => "vence amanhã ({$date})",
        default => "vence em {$d} dias ({$date})",
    };
}

// ---------- Estado ----------

$admin = rp_read_php(RP_ADMIN);
$clients = rp_clients();
$platform = rp_platform();
$setup = empty($admin['hash']);
$role = null;
if (!$setup && ($_SESSION['role'] ?? '') === 'master' && hash_equals((string) ($admin['uid'] ?? ''), (string) ($_SESSION['uid'] ?? ''))) {
    $role = 'master';
} elseif (($_SESSION['role'] ?? '') === 'client' && isset($clients[$_SESSION['tenant'] ?? ''])
    && hash_equals((string) $clients[$_SESSION['tenant']]['uid'], (string) ($_SESSION['uid'] ?? ''))) {
    $role = 'client';
}
$logged = $role !== null;
$master = $role === 'master';
// Cliente vê o próprio app; o master pode "entrar" no painel de um cliente.
$tenant = $role === 'client' ? $_SESSION['tenant'] : ($master ? ($_SESSION['as'] ?? null) : null);
if ($tenant !== null && !isset($clients[$tenant])) {
    unset($_SESSION['as']);
    $tenant = null;
}
rp_use_tenant($tenant);
$client = $tenant !== null ? $clients[$tenant] : null;
// Clientes antigos (sem a opção) têm a Web TV liberada.
$tvAllowed = $client !== null && ($client['allow_tv'] ?? true);
$fatal = null;

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function unread_requests(): array
{
    $items = rp_read_json(rp_tfile('requests'), ['items' => []])['items'];
    $new = array_filter($items, fn ($r) => $r['status'] === 'new');
    return ['unread' => count($new), 'last' => $items ? end($items)['id'] : 0,
        'latest' => $new ? array_map(fn ($r) => ['type' => $r['type'], 'name' => $r['name'], 'text' => $r['song'] ?: mb_substr($r['text'], 0, 80)], array_slice(array_values($new), -3)) : []];
}

if (isset($_GET['ajax'])) {
    if (!$logged || $tenant === null) {
        json_out(['error' => 'login'], 401);
    }
    if ($_GET['ajax'] === 'poll') {
        json_out(unread_requests());
    }
    if ($_GET['ajax'] === 'np_script') {
        $cfg = rp_config();
        $i = find_index($cfg['stations'], (string) ($_GET['id'] ?? ''));
        if ($i < 0 || empty($cfg['stations'][$i]['np_key'])) {
            json_out(['error' => 'Gere a chave primeiro.'], 404);
        }
        $st = $cfg['stations'][$i];
        $push = app_url($tenant) . 'api.php?action=np_push';
        if (($_GET['f'] ?? '') === 'bat') {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="iniciar-musica-no-app.bat"');
            $ascii = preg_replace('/[^A-Za-z0-9 ,.()-]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $st['name']));
            echo "@echo off\r\ntitle Musica tocando no app - {$ascii}\r\npowershell -NoProfile -ExecutionPolicy Bypass -File \"%~dp0musica-no-app.ps1\"\r\npause\r\n";
            exit;
        }
        $ps = <<<PS1
# Rádio Player — envia a música tocando no Zara Radio para o app de "{$st['name']}".
# Deixe esta janela aberta enquanto a rádio estiver no ar.

# 1) Ajuste aqui o arquivo que o Zara Radio grava com a música atual:
\$arquivo = "C:\\ZaraRadio\\currentsong.txt"

# 2) Não altere daqui para baixo.
\$url   = "{$push}"
\$id    = "{$st['id']}"
\$chave = "{$st['np_key']}"

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
\$ultima = ""
\$enviadoEm = Get-Date "2000-01-01"
Write-Host "Enviando a música tocando para o app. Deixe esta janela aberta." -ForegroundColor Green
Write-Host "Arquivo: \$arquivo"
while (\$true) {
    try {
        if (Test-Path -LiteralPath \$arquivo) {
            \$musica = ((Get-Content -LiteralPath \$arquivo -Raw -Encoding Default) -replace "`0", "").Trim()
            \$repetir = ((Get-Date) - \$enviadoEm).TotalSeconds -ge 120
            if (\$musica -and (\$musica -ne \$ultima -or \$repetir)) {
                \$r = Invoke-RestMethod -Uri \$url -Method Post -Body @{ id = \$id; key = \$chave; song = \$musica }
                if (\$musica -ne \$ultima) { Write-Host (Get-Date -Format "HH:mm:ss") " " \$r.song }
                \$ultima = \$musica
                \$enviadoEm = Get-Date
            }
        } else {
            Write-Host "Arquivo não encontrado: \$arquivo  (confira o caminho na linha 5 deste script)" -ForegroundColor Yellow
            Start-Sleep -Seconds 25
        }
    } catch {
        Write-Host "Falha ao enviar (vou tentar de novo): \$(\$_.Exception.Message)" -ForegroundColor Red
    }
    Start-Sleep -Seconds 5
}
PS1;
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="musica-no-app.ps1"');
        // BOM UTF-8 + CRLF: o PowerShell do Windows lê acentos corretamente.
        echo "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $ps);
        exit;
    }
    if ($_GET['ajax'] === 'np') {
        $cfg = rp_config();
        $i = find_index($cfg['stations'], (string) ($_GET['id'] ?? ''));
        if ($i < 0) {
            json_out(['error' => 'Rádio não encontrada.'], 404);
        }
        json_out(np_now($cfg['stations'][$i], !empty($cfg['settings']['np_art']), true));
    }
    if ($_GET['ajax'] === 'chat') {
        $data = rp_chat_since(max(0, (int) ($_GET['since'] ?? 0)), true);
        $data['online'] = rp_presence();
        json_out($data);
    }
    json_out(['error' => 'ação desconhecida'], 400);
}
try {
    rp_ensure_dirs();
} catch (RuntimeException $e) {
    $fatal = $e->getMessage();
}

// ---------- Ações (POST) ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$fatal) {
    $action = (string) ($_POST['action'] ?? '');
    $back = (string) ($_POST['back'] ?? '');

    if (!hash_equals(csrf(), (string) ($_POST['_csrf'] ?? ''))) {
        flash('Sessão expirada. Tente novamente.', 'err');
        go($back);
    }

    try {
        if ($setup && $action === 'setup') {
            $user = rp_clean($_POST['user'] ?? '', 60);
            $pass = (string) ($_POST['pass'] ?? '');
            if ($user === '' || mb_strlen($pass) < 8 || $pass !== ($_POST['pass2'] ?? '')) {
                throw new RuntimeException('Informe o usuário e uma senha de pelo menos 8 caracteres (repetida igual).');
            }
            rp_locked(function () use ($user, $pass) {
                if (!empty(rp_read_php(RP_ADMIN)['hash'])) {
                    throw new RuntimeException('O administrador já foi criado.');
                }
                rp_write_php(RP_ADMIN, ['user' => $user, 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'uid' => rp_id()]);
            });
            session_regenerate_id(true);
            $_SESSION['role'] = 'master';
            $_SESSION['uid'] = rp_read_php(RP_ADMIN)['uid'];
            flash('Administrador criado. Agora cadastre seu primeiro cliente.');
            go('tab=clientes&edit=new');
        }

        if (!$setup && $action === 'login') {
            if ($wait = login_blocked()) {
                throw new RuntimeException('Muitas tentativas. Aguarde ' . ceil($wait / 60) . ' min.');
            }
            $user = mb_strtolower(rp_clean($_POST['user'] ?? '', 80));
            $pass = (string) ($_POST['pass'] ?? '');
            $who = null;
            if (hash_equals(mb_strtolower((string) $admin['user']), $user) && password_verify($pass, (string) $admin['hash'])) {
                $who = ['master', null, $admin['uid']];
            } else {
                foreach ($clients as $slug => $c) {
                    if ($c['user'] !== '' && hash_equals(mb_strtolower($c['user']), $user)) {
                        if (password_verify($pass, (string) $c['hash'])) {
                            $who = ['client', $slug, $c['uid']];
                        }
                        break;
                    }
                }
            }
            if (!$who) {
                login_failed();
                usleep(600000);
                throw new RuntimeException('Usuário ou senha incorretos.');
            }
            login_reset();
            session_regenerate_id(true);
            [$_SESSION['role'], $_SESSION['tenant'], $_SESSION['uid']] = $who;
            unset($_SESSION['as']);
            if ($who[0] === 'master' && password_needs_rehash($admin['hash'], PASSWORD_DEFAULT)) {
                $admin['hash'] = password_hash($pass, PASSWORD_DEFAULT);
                rp_write_php(RP_ADMIN, $admin);
            }
            go();
        }

        if (!$logged) {
            go();
        }

        $masterActions = ['client_save', 'client_delete', 'client_enter', 'client_leave', 'client_renew', 'platform_save'];
        if (in_array($action, $masterActions, true) && !$master) {
            throw new RuntimeException('Ação permitida só para o administrador da plataforma.');
        }
        if (!in_array($action, [...$masterActions, 'logout', 'password'], true) && $tenant === null) {
            throw new RuntimeException('Escolha um cliente para editar.');
        }

        switch ($action) {
            case 'logout':
                $_SESSION = [];
                session_destroy();
                go();

            // ----- Clientes (master) -----
            case 'client_save':
                $orig = (string) ($_POST['orig'] ?? '');
                $isNew = $orig === '';
                $slug = $isNew ? strtolower(trim((string) ($_POST['slug'] ?? ''))) : $orig;
                $name = rp_clean($_POST['name'] ?? '', 80);
                $user = mb_strtolower(rp_clean($_POST['user'] ?? '', 80));
                $pass = (string) ($_POST['pass'] ?? '');
                if ($name === '') {
                    throw new RuntimeException('Informe o nome do cliente.');
                }
                if (!rp_valid_slug($slug)) {
                    throw new RuntimeException('Endereço inválido: use letras minúsculas, números e hífen (ex.: radio-nova-fm).');
                }
                if ($isNew && ($user === '' || mb_strlen($pass) < 8)) {
                    throw new RuntimeException('Informe o usuário e uma senha de pelo menos 8 caracteres.');
                }
                if ($pass !== '' && mb_strlen($pass) < 8) {
                    throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres.');
                }
                $status = ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
                rp_locked(function () use ($isNew, $slug, $name, $user, $pass, $status, $admin) {
                    $all = rp_clients();
                    if ($isNew && isset($all[$slug])) {
                        throw new RuntimeException('Já existe um cliente com esse endereço.');
                    }
                    if (!$isNew && !isset($all[$slug])) {
                        throw new RuntimeException('Cliente não encontrado.');
                    }
                    if ($user !== '') {
                        if (hash_equals(mb_strtolower((string) $admin['user']), $user)) {
                            throw new RuntimeException('Esse usuário já é usado pelo administrador.');
                        }
                        foreach ($all as $k => $c) {
                            if ($k !== $slug && mb_strtolower($c['user']) === $user) {
                                throw new RuntimeException('Esse usuário já é usado por outro cliente.');
                            }
                        }
                    }
                    $old = $all[$slug] ?? ['hash' => '', 'uid' => rp_id(), 'created_at' => date('Y-m-d'), 'user' => ''];
                    $c = [
                        'name' => $name,
                        'user' => $user,
                        'hash' => $pass !== '' ? password_hash($pass, PASSWORD_DEFAULT) : $old['hash'],
                        // Trocar usuário ou senha desconecta o cliente dos aparelhos onde estava logado.
                        'uid' => ($pass !== '' || $user !== $old['user']) && !$isNew ? rp_id() : $old['uid'],
                        'status' => $status,
                        'expires' => rp_date($_POST['expires'] ?? ''),
                        'max_stations' => min(999, max(0, (int) ($_POST['max_stations'] ?? 0))),
                        'allow_tv' => !empty($_POST['allow_tv']),
                        'max_tvs' => min(999, max(0, (int) ($_POST['max_tvs'] ?? 0))),
                        'phone' => rp_clean($_POST['phone'] ?? '', 40),
                        'notes' => rp_clean_text($_POST['notes'] ?? '', 500),
                        'created_at' => $old['created_at'],
                    ];
                    $all[$slug] = $c;
                    rp_save_clients($all);
                });
                if ($isNew) {
                    // Cria o app do cliente já com o nome dele.
                    rp_use_tenant($slug);
                    rp_update_config(function ($cfg) use ($name) {
                        $cfg['settings']['title'] = $name;
                        $cfg['settings']['short_name'] = mb_substr($name, 0, 12);
                        return $cfg;
                    });
                    rp_use_tenant($tenant);
                }
                if ($pass !== '') {
                    $_SESSION['cred'] = ['name' => $name, 'app' => app_url($slug), 'panel' => base_url() . 'admin/', 'user' => $user, 'pass' => $pass];
                }
                flash($isNew ? 'Cliente criado.' : 'Cliente atualizado.');
                go('tab=clientes');

            case 'client_delete':
                $slug = (string) ($_POST['id'] ?? '');
                if (!isset($clients[$slug]) || ($_POST['confirm'] ?? '') !== $slug) {
                    throw new RuntimeException('Para excluir, digite o endereço do cliente exatamente como aparece.');
                }
                rp_locked(function () use ($slug) {
                    $all = rp_clients();
                    unset($all[$slug]);
                    rp_save_clients($all);
                    $p = rp_platform();
                    if ($p['default_tenant'] === $slug) {
                        $p['default_tenant'] = '';
                        rp_save_platform($p);
                    }
                });
                rp_rmtree(RP_TENANTS . '/' . $slug);
                rp_rmtree(RP_UPLOADS . '/' . $slug);
                if (($_SESSION['as'] ?? null) === $slug) {
                    unset($_SESSION['as']);
                }
                flash('Cliente excluído, com o app, as imagens e as estatísticas.');
                go('tab=clientes');

            case 'client_renew':
                $slug = (string) ($_POST['id'] ?? '');
                $months = (int) ($_POST['months'] ?? 1) === 12 ? 12 : 1;
                $newDate = rp_locked(function () use ($slug, $months) {
                    $all = rp_clients();
                    if (!isset($all[$slug])) {
                        throw new RuntimeException('Cliente não encontrado.');
                    }
                    // Renova a partir do vencimento atual; se já venceu (ou não tinha), a partir de hoje.
                    $today = date('Y-m-d');
                    $base = ($all[$slug]['expires'] ?? '') > $today ? $all[$slug]['expires'] : $today;
                    $all[$slug]['expires'] = date('Y-m-d', strtotime("{$base} +{$months} month"));
                    rp_save_clients($all);
                    return $all[$slug]['expires'];
                });
                flash($clients[$slug]['name'] . ' renovado até ' . date('d/m/Y', strtotime($newDate)) . '.');
                go('tab=clientes');

            case 'client_enter':
                $slug = (string) ($_POST['id'] ?? '');
                if (!isset($clients[$slug])) {
                    throw new RuntimeException('Cliente não encontrado.');
                }
                $_SESSION['as'] = $slug;
                go('tab=inicio');

            case 'client_leave':
                unset($_SESSION['as']);
                go('tab=clientes');

            case 'platform_save':
                $def = (string) ($_POST['default_tenant'] ?? '');
                rp_locked(function () use ($def, $clients) {
                    $p = rp_platform();
                    $brand = rp_clean($_POST['brand'] ?? '', 60);
                    $p['brand'] = $brand !== '' ? $brand : 'Rádio Player';
                    $p['support_whatsapp'] = rp_clean($_POST['support_whatsapp'] ?? '', 40);
                    $p['default_tenant'] = isset($clients[$def]) ? $def : '';
                    rp_save_platform($p);
                });
                flash('Configurações da plataforma salvas.');
                go('tab=plataforma');

            // ----- Rádios -----
            case 'station_save':
                $id = (string) ($_POST['id'] ?? '');
                $name = rp_clean($_POST['name'] ?? '', 80);
                $stream = rp_url($_POST['stream'] ?? '');
                if ($name === '' || $stream === '') {
                    throw new RuntimeException('Informe o nome e uma URL de streaming válida (http/https).');
                }
                $limit = (int) ($client['max_stations'] ?? 0);
                if ($id === '' && $limit > 0 && count(rp_config()['stations']) >= $limit) {
                    throw new RuntimeException("Seu plano permite até {$limit} rádio(s). Fale com o suporte para ampliar.");
                }
                $upload = rp_save_upload('logo_file', 600);
                rp_update_config(function ($cfg) use ($id, $name, $stream, $upload) {
                    $i = $id !== '' ? find_index($cfg['stations'], $id) : -1;
                    $old = $i >= 0 ? $cfg['stations'][$i] : ['id' => rp_id(), 'logo' => ''];
                    $logo = $old['logo'];
                    if ($upload !== '' || !empty($_POST['logo_remove'])) {
                        rp_delete_upload($logo);
                        $logo = $upload;
                    }
                    $genreIds = array_column($cfg['genres'], 'id');
                    $st = [
                        'id' => $old['id'],
                        'name' => $name,
                        'stream' => $stream,
                        'logo' => $logo,
                        'genre' => in_array($_POST['genre'] ?? '', $genreIds, true) ? $_POST['genre'] : '',
                        'city' => rp_clean($_POST['city'] ?? '', 80),
                        'slogan' => rp_clean($_POST['slogan'] ?? '', 120),
                        'metadata' => rp_url($_POST['metadata'] ?? ''),
                        'np_key' => $old['np_key'] ?? '',
                        'active' => !empty($_POST['active']),
                    ];
                    if ($i >= 0) {
                        $cfg['stations'][$i] = $st;
                    } else {
                        $cfg['stations'][] = $st;
                    }
                    return $cfg;
                });
                flash('Rádio salva.');
                go('tab=radios');

            case 'np_key_new':
                rp_update_config(function ($cfg) {
                    $i = find_index($cfg['stations'], (string) $_POST['id']);
                    if ($i >= 0) {
                        $cfg['stations'][$i]['np_key'] = bin2hex(random_bytes(16));
                    }
                    return $cfg;
                });
                flash('Chave gerada. Baixe o script abaixo e rode no computador da rádio.');
                go('tab=radios&edit=' . rawurlencode((string) $_POST['id']));

            case 'station_delete':
                rp_update_config(function ($cfg) {
                    $i = find_index($cfg['stations'], (string) $_POST['id']);
                    if ($i >= 0) {
                        rp_delete_upload($cfg['stations'][$i]['logo'] ?? '');
                        array_splice($cfg['stations'], $i, 1);
                    }
                    return $cfg;
                });
                flash('Rádio excluída.');
                go('tab=radios');

            case 'station_move':
                rp_update_config(function ($cfg) {
                    $cfg['stations'] = move_item($cfg['stations'], (string) $_POST['id'], (int) $_POST['dir']);
                    return $cfg;
                });
                go('tab=radios');

            case 'station_toggle':
                rp_update_config(function ($cfg) {
                    $i = find_index($cfg['stations'], (string) $_POST['id']);
                    if ($i >= 0) {
                        $cfg['stations'][$i]['active'] = empty($cfg['stations'][$i]['active']);
                    }
                    return $cfg;
                });
                go('tab=radios');

            // ----- Web TV -----
            case 'tv_save':
                if (!$tvAllowed) {
                    throw new RuntimeException('A Web TV não está liberada no seu plano. Fale com o suporte.');
                }
                $id = (string) ($_POST['id'] ?? '');
                $name = rp_clean($_POST['name'] ?? '', 80);
                if ($name === '') {
                    throw new RuntimeException('Informe o nome do canal.');
                }
                $link = trim((string) ($_POST['link'] ?? ''));
                $wantType = (string) ($_POST['type'] ?? 'auto');
                [$type, $src] = rp_parse_tv($link, isset(TV_TYPES[$wantType]) ? $wantType : 'auto');
                $limit = (int) ($client['max_tvs'] ?? 0);
                if ($id === '' && $limit > 0 && count(rp_config()['tvs']) >= $limit) {
                    throw new RuntimeException("Seu plano permite até {$limit} canal(is) de TV. Fale com o suporte para ampliar.");
                }
                $upload = rp_save_upload('logo_file', 1280);
                rp_update_config(function ($cfg) use ($id, $name, $link, $wantType, $type, $src, $upload) {
                    $i = $id !== '' ? find_index($cfg['tvs'], $id) : -1;
                    $old = $i >= 0 ? $cfg['tvs'][$i] : ['id' => rp_id(), 'logo' => ''];
                    $logo = $old['logo'];
                    if ($upload !== '' || !empty($_POST['logo_remove'])) {
                        rp_delete_upload($logo);
                        $logo = $upload;
                    }
                    $tv = [
                        'id' => $old['id'],
                        'name' => $name,
                        'link' => mb_substr($link, 0, 2000),
                        'type_choice' => isset(TV_TYPES[$wantType]) ? $wantType : 'auto',
                        'type' => $type,
                        'src' => $src,
                        'logo' => $logo,
                        'genre' => in_array($_POST['genre'] ?? '', array_column($cfg['genres'], 'id'), true) ? $_POST['genre'] : '',
                        'description' => rp_clean($_POST['description'] ?? '', 160),
                        'active' => !empty($_POST['active']),
                    ];
                    if ($i >= 0) {
                        $cfg['tvs'][$i] = $tv;
                    } else {
                        $cfg['tvs'][] = $tv;
                    }
                    return $cfg;
                });
                flash('Canal salvo (' . TV_TYPES[$type] . ').');
                go('tab=tv');

            case 'tv_delete':
                rp_update_config(function ($cfg) {
                    $i = find_index($cfg['tvs'], (string) $_POST['id']);
                    if ($i >= 0) {
                        rp_delete_upload($cfg['tvs'][$i]['logo'] ?? '');
                        array_splice($cfg['tvs'], $i, 1);
                    }
                    return $cfg;
                });
                flash('Canal excluído.');
                go('tab=tv');

            case 'tv_move':
                rp_update_config(function ($cfg) {
                    $cfg['tvs'] = move_item($cfg['tvs'], (string) $_POST['id'], (int) $_POST['dir']);
                    return $cfg;
                });
                go('tab=tv');

            case 'tv_toggle':
                rp_update_config(function ($cfg) {
                    $i = find_index($cfg['tvs'], (string) $_POST['id']);
                    if ($i >= 0) {
                        $cfg['tvs'][$i]['active'] = empty($cfg['tvs'][$i]['active']);
                    }
                    return $cfg;
                });
                go('tab=tv');

            // ----- Pedidos -----
            case 'req_reply':
            case 'req_read':
            case 'req_unread':
            case 'req_delete':
                $rid = (int) ($_POST['id'] ?? 0);
                $reply = rp_clean_text($_POST['reply'] ?? '', 500);
                if ($action === 'req_reply' && $reply === '') {
                    throw new RuntimeException('Escreva a resposta.');
                }
                rp_locked(function () use ($action, $rid, $reply) {
                    $all = rp_read_json(rp_tfile('requests'), ['last' => 0, 'items' => []]);
                    foreach ($all['items'] as $k => &$r) {
                        if ($r['id'] !== $rid) {
                            continue;
                        }
                        if ($action === 'req_delete') {
                            unset($all['items'][$k]);
                        } elseif ($action === 'req_reply') {
                            $r['reply'] = $reply;
                            $r['reply_at'] = time();
                            $r['status'] = 'answered';
                        } else {
                            $r['status'] = $action === 'req_read' ? 'read' : 'new';
                        }
                        break;
                    }
                    unset($r);
                    $all['items'] = array_values($all['items']);
                    rp_write_atomic(rp_tfile('requests'), json_encode($all, JSON_UNESCAPED_UNICODE));
                });
                if (!empty($_POST['ajax'])) {
                    json_out(['ok' => true] + unread_requests());
                }
                flash(['req_reply' => 'Resposta enviada. O ouvinte vê no app.', 'req_delete' => 'Pedido excluído.'][$action] ?? 'Pedido atualizado.');
                go($back);

            case 'req_read_all':
            case 'req_clear':
                rp_locked(function () use ($action) {
                    $all = rp_read_json(rp_tfile('requests'), ['last' => 0, 'items' => []]);
                    if ($action === 'req_clear') {
                        $all['items'] = array_values(array_filter($all['items'], fn ($r) => $r['status'] === 'new'));
                    } else {
                        foreach ($all['items'] as &$r) {
                            if ($r['status'] === 'new') {
                                $r['status'] = 'read';
                            }
                        }
                        unset($r);
                    }
                    rp_write_atomic(rp_tfile('requests'), json_encode($all, JSON_UNESCAPED_UNICODE));
                });
                flash($action === 'req_clear' ? 'Pedidos lidos e respondidos apagados.' : 'Todos marcados como lidos.');
                go($back);

            case 'interact_save':
                rp_update_config(function ($cfg) {
                    foreach (['req_music', 'req_prayer', 'chat', 'chat_announce'] as $k) {
                        $cfg['settings'][$k] = !empty($_POST[$k]);
                    }
                    return $cfg;
                });
                flash('Configurações de interação salvas.');
                go($back);

            // ----- Chat (moderação) -----
            case 'chat_send':
                $text = rp_clean($_POST['text'] ?? '', 300);
                $nick = rp_clean($_POST['nick'] ?? '', 24) ?: 'Locutor';
                if ($text === '') {
                    json_out(['error' => 'Mensagem vazia.'], 422);
                }
                $msg = rp_chat_add(['kind' => 'host', 'nick' => $nick, 'text' => $text, 'device' => '']);
                json_out(['ok' => true, 'id' => $msg['id']]);

            case 'chat_delete':
                $mid = (int) ($_POST['id'] ?? 0);
                rp_locked(function () use ($mid) {
                    $chat = rp_read_json(rp_tfile('chat'), ['last' => 0, 'messages' => []]);
                    $chat['messages'] = array_values(array_filter($chat['messages'], fn ($m) => $m['id'] !== $mid));
                    $chat['deleted'] = array_slice([...($chat['deleted'] ?? []), $mid], -200);
                    rp_write_atomic(rp_tfile('chat'), json_encode($chat, JSON_UNESCAPED_UNICODE));
                });
                json_out(['ok' => true]);

            case 'chat_ban':
                $mid = (int) ($_POST['id'] ?? 0);
                $chat = rp_read_json(rp_tfile('chat'), ['messages' => []]);
                $target = null;
                foreach ($chat['messages'] as $m) {
                    if ($m['id'] === $mid) {
                        $target = $m;
                    }
                }
                if (!$target || empty($target['device'])) {
                    json_out(['error' => 'Não foi possível bloquear este autor.'], 422);
                }
                rp_locked(function () use ($target) {
                    $bans = rp_read_json(rp_tfile('bans'), []);
                    $bans['d:' . $target['device']] = ['nick' => $target['nick'], 'at' => time()];
                    rp_write_atomic(rp_tfile('bans'), json_encode($bans, JSON_UNESCAPED_UNICODE));
                    // Some com as mensagens de quem foi bloqueado.
                    $chat = rp_read_json(rp_tfile('chat'), ['last' => 0, 'messages' => []]);
                    $gone = array_column(array_filter($chat['messages'], fn ($m) => ($m['device'] ?? '') === $target['device']), 'id');
                    $chat['messages'] = array_values(array_filter($chat['messages'], fn ($m) => ($m['device'] ?? '') !== $target['device']));
                    $chat['deleted'] = array_slice([...($chat['deleted'] ?? []), ...$gone], -200);
                    rp_write_atomic(rp_tfile('chat'), json_encode($chat, JSON_UNESCAPED_UNICODE));
                });
                json_out(['ok' => true]);

            case 'chat_unban':
                rp_locked(function () {
                    $bans = rp_read_json(rp_tfile('bans'), []);
                    unset($bans[(string) ($_POST['id'] ?? '')]);
                    rp_write_atomic(rp_tfile('bans'), json_encode($bans, JSON_UNESCAPED_UNICODE));
                });
                flash('Ouvinte desbloqueado.');
                go('tab=chat');

            case 'chat_clear':
                rp_locked(function () {
                    $chat = rp_read_json(rp_tfile('chat'), ['last' => 0, 'messages' => []]);
                    $chat['deleted'] = array_slice([...($chat['deleted'] ?? []), ...array_column($chat['messages'], 'id')], -300);
                    $chat['messages'] = [];
                    rp_write_atomic(rp_tfile('chat'), json_encode($chat, JSON_UNESCAPED_UNICODE));
                });
                flash('Conversa apagada.');
                go('tab=chat');

            // ----- Estilos -----
            case 'genre_add':
                $name = rp_clean($_POST['name'] ?? '', 40);
                if ($name === '') {
                    throw new RuntimeException('Informe o nome do gênero.');
                }
                rp_update_config(function ($cfg) use ($name) {
                    foreach ($cfg['genres'] as $g) {
                        if (mb_strtolower($g['name']) === mb_strtolower($name)) {
                            throw new RuntimeException('Esse gênero já existe.');
                        }
                    }
                    $cfg['genres'][] = ['id' => rp_id(), 'name' => $name];
                    return $cfg;
                });
                flash('Gênero adicionado.');
                go('tab=estilos');

            case 'genre_rename':
                $name = rp_clean($_POST['name'] ?? '', 40);
                if ($name === '') {
                    throw new RuntimeException('Informe o nome do gênero.');
                }
                rp_update_config(function ($cfg) use ($name) {
                    $i = find_index($cfg['genres'], (string) $_POST['id']);
                    if ($i >= 0) {
                        $cfg['genres'][$i]['name'] = $name;
                    }
                    return $cfg;
                });
                flash('Gênero renomeado.');
                go('tab=estilos');

            case 'genre_delete':
                rp_update_config(function ($cfg) {
                    $id = (string) $_POST['id'];
                    $i = find_index($cfg['genres'], $id);
                    if ($i >= 0) {
                        array_splice($cfg['genres'], $i, 1);
                        foreach ($cfg['stations'] as &$st) {
                            if (($st['genre'] ?? '') === $id) {
                                $st['genre'] = '';
                            }
                        }
                    }
                    return $cfg;
                });
                flash('Gênero excluído. As rádios desse gênero ficaram sem gênero.');
                go('tab=estilos');

            case 'genre_move':
                rp_update_config(function ($cfg) {
                    $cfg['genres'] = move_item($cfg['genres'], (string) $_POST['id'], (int) $_POST['dir']);
                    return $cfg;
                });
                go('tab=estilos');

            // ----- Publicidade -----
            case 'ad_save':
                $id = (string) ($_POST['id'] ?? '');
                $position = (string) ($_POST['position'] ?? '');
                if (!isset(AD_POSITIONS[$position])) {
                    throw new RuntimeException('Escolha onde a publicidade vai aparecer.');
                }
                $starts = rp_date($_POST['starts'] ?? '');
                $ends = rp_date($_POST['ends'] ?? '');
                if ($starts && $ends && $ends < $starts) {
                    throw new RuntimeException('A data final é anterior à data inicial.');
                }
                $upload = rp_save_upload('image_file', 1600);
                rp_update_config(function ($cfg) use ($id, $position, $starts, $ends, $upload) {
                    $i = $id !== '' ? find_index($cfg['ads'], $id) : -1;
                    $old = $i >= 0 ? $cfg['ads'][$i] : ['id' => rp_id(), 'image' => '', 'created_at' => date('Y-m-d')];
                    $image = $old['image'];
                    if ($upload !== '' || !empty($_POST['image_remove'])) {
                        rp_delete_upload($image);
                        $image = $upload;
                    }
                    $title = rp_clean($_POST['title'] ?? '', 80);
                    if ($image === '' && $title === '') {
                        rp_delete_upload($upload);
                        throw new RuntimeException('Envie uma imagem ou preencha ao menos o título.');
                    }
                    $ad = [
                        'id' => $old['id'],
                        'title' => $title,
                        'text' => rp_clean($_POST['text'] ?? '', 160),
                        'image' => $image,
                        'link' => rp_url($_POST['link'] ?? '') ?: rp_whatsapp_link((string) ($_POST['link'] ?? '')),
                        'position' => $position,
                        'starts' => $starts,
                        'ends' => $ends,
                        'active' => !empty($_POST['active']),
                        'created_at' => $old['created_at'] ?? date('Y-m-d'),
                    ];
                    if ($i >= 0) {
                        $cfg['ads'][$i] = $ad;
                    } else {
                        $cfg['ads'][] = $ad;
                    }
                    return $cfg;
                });
                flash('Publicidade salva.');
                go('tab=publicidade');

            case 'ad_delete':
                rp_update_config(function ($cfg) {
                    $i = find_index($cfg['ads'], (string) $_POST['id']);
                    if ($i >= 0) {
                        rp_delete_upload($cfg['ads'][$i]['image'] ?? '');
                        array_splice($cfg['ads'], $i, 1);
                    }
                    return $cfg;
                });
                flash('Publicidade excluída.');
                go('tab=publicidade');

            case 'ad_toggle':
                rp_update_config(function ($cfg) {
                    $i = find_index($cfg['ads'], (string) $_POST['id']);
                    if ($i >= 0) {
                        $cfg['ads'][$i]['active'] = empty($cfg['ads'][$i]['active']);
                    }
                    return $cfg;
                });
                go('tab=publicidade');

            case 'ad_move':
                rp_update_config(function ($cfg) {
                    $cfg['ads'] = move_item($cfg['ads'], (string) $_POST['id'], (int) $_POST['dir']);
                    return $cfg;
                });
                go('tab=publicidade');

            case 'ads_samples':
                rp_update_config(function ($cfg) {
                    if (array_filter($cfg['ads'], fn ($a) => !empty($a['sample']))) {
                        throw new RuntimeException('Os anúncios de exemplo já estão cadastrados.');
                    }
                    $samples = [
                        ['top', 'Supermercado Bom Preço', '', 'samples/topo-supermercado.svg'],
                        ['top', 'Agropecuária Boi Gordo', '', 'samples/topo-agropecuaria.svg'],
                        ['top', 'Ótica Visão Clara', '', 'samples/topo-otica.svg'],
                        ['top', 'Auto Escola Via Certa', '', 'samples/topo-autoescola.svg'],
                        ['player', 'Pizzaria Forno a Lenha', '', 'samples/player-pizzaria.svg'],
                        ['player', 'Construmax Materiais', '', 'samples/player-construmax.svg'],
                        ['player', 'Auto Peças Silva', 'Peças com o melhor preço. Chame no WhatsApp!', ''],
                        ['popup', 'Farmácia Vida', '', 'samples/popup-farmacia.svg'],
                        ['popup', 'Festa do Peão de Vila Esperança', '', 'samples/popup-show.svg'],
                    ];
                    foreach ($samples as [$pos, $title, $text, $image]) {
                        $cfg['ads'][] = [
                            'id' => rp_id(), 'title' => $title, 'text' => $text, 'image' => $image, 'link' => '',
                            'position' => $pos, 'starts' => '', 'ends' => '', 'active' => true,
                            'created_at' => date('Y-m-d'), 'sample' => true,
                        ];
                    }
                    return $cfg;
                });
                flash('Anúncios de exemplo adicionados. Abra o app para ver.');
                go('tab=publicidade');

            case 'ads_samples_remove':
                rp_update_config(function ($cfg) {
                    $cfg['ads'] = array_values(array_filter($cfg['ads'], fn ($a) => empty($a['sample'])));
                    return $cfg;
                });
                flash('Anúncios de exemplo removidos.');
                go('tab=publicidade');

            case 'ad_reset':
                rp_locked(function () {
                    $stats = rp_read_json(rp_stats_file(), []);
                    unset($stats[(string) $_POST['id']]);
                    rp_write_atomic(rp_stats_file(), json_encode($stats));
                });
                flash('Contadores zerados.');
                go('tab=publicidade');

            // ----- Aparência -----
            case 'settings_save':
                $upload = rp_save_upload('logo_file', 1024);
                $bgUpload = rp_save_upload('background_file', 1920);
                rp_update_config(function ($cfg) use ($upload, $bgUpload) {
                    $s = &$cfg['settings'];
                    $s['theme'] = isset(RP_THEMES[$_POST['theme'] ?? '']) ? $_POST['theme'] : rp_theme($s);
                    $s['accent_custom'] = !empty($_POST['accent_custom']);
                    if ($bgUpload !== '' || !empty($_POST['background_remove'])) {
                        rp_delete_upload($s['background']);
                        $s['background'] = $bgUpload;
                    }
                    $title = rp_clean($_POST['title'] ?? '', 60);
                    $s['title'] = $title !== '' ? $title : 'Rádio Player';
                    $s['short_name'] = rp_clean($_POST['short_name'] ?? '', 20);
                    $s['description'] = rp_clean($_POST['description'] ?? '', 160);
                    $s['accent'] = rp_color($_POST['accent'] ?? '', $s['accent']);
                    $s['about'] = rp_clean_text($_POST['about'] ?? '', 1000);
                    $s['whatsapp'] = rp_clean($_POST['whatsapp'] ?? '', 120);
                    $s['instagram'] = rp_clean($_POST['instagram'] ?? '', 120);
                    $s['facebook'] = rp_clean($_POST['facebook'] ?? '', 120);
                    $s['youtube'] = rp_clean($_POST['youtube'] ?? '', 120);
                    $s['site'] = rp_url($_POST['site'] ?? '');
                    $s['ad_rotate'] = min(60, max(3, (int) ($_POST['ad_rotate'] ?? 8)));
                    $s['popup_delay'] = min(60, max(0, (int) ($_POST['popup_delay'] ?? 3)));
                    $s['np_art'] = !empty($_POST['np_art']);
                    if ($upload !== '' || !empty($_POST['logo_remove'])) {
                        rp_delete_upload($s['logo']);
                        $s['logo'] = $upload;
                        foreach (['icon-192.png', 'icon-512.png', 'maskable-512.png'] as $f) {
                            @unlink(rp_upload_dir() . '/app-' . $f);
                        }
                    }
                    if ($s['logo'] !== '') {
                        rp_make_icons($s['logo'], RP_THEMES[rp_theme($s)][2]);
                    }
                    return $cfg;
                });
                flash('Aparência salva. Quem já instalou o app verá as mudanças ao abri-lo de novo.');
                go('tab=aparencia');

            // ----- Conta -----
            case 'password':
                $isClient = $role === 'client';
                $current = $isClient ? $client['hash'] : $admin['hash'];
                if (!password_verify((string) ($_POST['current'] ?? ''), (string) $current)) {
                    throw new RuntimeException('Senha atual incorreta.');
                }
                $pass = (string) ($_POST['pass'] ?? '');
                if (mb_strlen($pass) < 8 || $pass !== ($_POST['pass2'] ?? '')) {
                    throw new RuntimeException('A nova senha precisa ter 8+ caracteres e ser repetida igual.');
                }
                $uid = rp_id(); // derruba as outras sessões abertas
                if ($isClient) {
                    rp_locked(function () use ($tenant, $pass, $uid) {
                        $all = rp_clients();
                        $all[$tenant]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
                        $all[$tenant]['uid'] = $uid;
                        rp_save_clients($all);
                    });
                } else {
                    $user = rp_clean($_POST['user'] ?? '', 60) ?: $admin['user'];
                    rp_locked(fn () => rp_write_php(RP_ADMIN, ['user' => $user, 'hash' => password_hash($pass, PASSWORD_DEFAULT), 'uid' => $uid]));
                }
                $_SESSION['uid'] = $uid;
                flash('Senha atualizada.');
                go('tab=conta');
        }
        go($back);
    } catch (RuntimeException $e) {
        if (!empty($_POST['ajax'])) {
            json_out(['error' => $e->getMessage()], 422);
        }
        flash($e->getMessage(), 'err');
        go($back);
    }
}

// ---------- Tela ----------

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$cred = $_SESSION['cred'] ?? null;
unset($_SESSION['cred']);
$cfg = $tenant !== null ? rp_config() : ['settings' => rp_defaults()['settings']];
$s = $cfg['settings'];
if ($tenant === null) {
    $s['title'] = $platform['brand'];
}
$edit = (string) ($_GET['edit'] ?? '');
if ($tenant !== null) {
    $tabs = ['inicio' => 'Início', 'radios' => 'Rádios'];
    if ($tvAllowed) {
        $tabs['tv'] = 'Web TV';
    }
    $tabs += ['pedidos' => 'Pedidos', 'chat' => 'Chat', 'aparencia' => 'Modelo e aparência', 'estilos' => 'Gêneros musicais', 'publicidade' => 'Publicidade'];
    if ($role === 'client') {
        $tabs['conta'] = 'Senha';
    }
} else {
    $tabs = ['clientes' => 'Clientes', 'plataforma' => 'Plataforma', 'conta' => 'Minha senha'];
}
$tab = (string) ($_GET['tab'] ?? array_key_first($tabs));
if (!isset($tabs[$tab])) {
    $tab = array_key_first($tabs);
}
$clientState = $client ? rp_client_state($client) : 'active';
$support = rp_whatsapp_link($platform['support_whatsapp']);
$back = h(http_build_query(array_filter(['tab' => $tab, 'edit' => $edit])));

/** Formulário de uma ação simples (um botão). */
function action_btn(string $action, string $id, string $label, string $class = 'btn sm', array $extra = [], string $confirm = ''): string
{
    $html = '<form method="post" class="inline"' . ($confirm ? ' onsubmit="return confirm(\'' . h($confirm) . '\')"' : '') . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="id" value="' . h($id) . '">';
    foreach ($extra as $k => $v) {
        $html .= '<input type="hidden" name="' . h($k) . '" value="' . h((string) $v) . '">';
    }
    return $html . '<button class="' . h($class) . '">' . $label . '</button></form>';
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Painel · <?= h($s['title']) ?></title>
  <link rel="icon" href="../icons/icon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="admin.css?v=<?= (int) @filemtime(__DIR__ . '/admin.css') ?>">
  <style>:root { --accent: <?= h(rp_accent($s)) ?>; }</style>
</head>
<body>
<?php if ($fatal): ?>
  <main class="auth"><div class="card"><h1>Configuração do servidor</h1><p class="alert err"><?= h($fatal) ?></p>
  <p class="hint">Exemplo: <code>chown -R www-data:www-data data uploads</code></p></div></main>
<?php elseif ($setup || !$logged): ?>
  <main class="auth">
    <form method="post" class="card">
      <img src="../icons/icon.svg" alt="" width="56" height="56">
      <h1><?= $setup ? 'Criar administrador' : h($platform['brand']) ?></h1>
      <?php if ($setup): ?><p class="hint">Primeiro acesso: defina o usuário e a senha do administrador da plataforma (você).</p>
      <?php else: ?><p class="hint">Entre com o usuário e a senha do seu painel.</p><?php endif; ?>
      <?php if ($flash): ?><p class="alert <?= h($flash[1]) ?>"><?= h($flash[0]) ?></p><?php endif; ?>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $setup ? 'setup' : 'login' ?>">
      <label>Usuário<input name="user" required autocomplete="username" value="<?= $setup ? 'admin' : '' ?>"></label>
      <label>Senha<input name="pass" type="password" required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" <?= $setup ? 'minlength="8"' : '' ?>></label>
      <?php if ($setup): ?><label>Repita a senha<input name="pass2" type="password" required minlength="8" autocomplete="new-password"></label><?php endif; ?>
      <button class="btn primary block"><?= $setup ? 'Criar e entrar' : 'Entrar' ?></button>
    </form>
  </main>
<?php else: ?>
  <header class="top">
    <a class="brand" href="./"><img src="<?= h($s['logo'] ? img_url($s['logo']) : '../icons/icon.svg') ?>" alt=""><span><?= h($s['title']) ?></span></a>
    <div class="top-actions">
      <?php if ($tenant !== null): ?><a class="btn sm" href="../r/<?= h($tenant) ?>/" target="_blank" rel="noopener">Abrir app ↗</a><?php endif; ?>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="btn sm ghost">Sair</button></form>
    </div>
  </header>
  <nav class="nav">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="?tab=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= $label ?><?php if ($k === 'pedidos'): $un = unread_requests()['unread']; ?><span class="count" id="reqCount"<?= $un ? '' : ' hidden' ?>><?= $un ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <main class="content">
    <?php if ($master && $tenant !== null): ?>
      <div class="as-bar">
        <span>Você está no painel de <b><?= h($client['name']) ?></b> (cliente).</span>
        <?= action_btn('client_leave', '', '← Voltar para clientes', 'btn sm') ?>
      </div>
    <?php endif; ?>
    <?php if ($tenant !== null && $clientState === 'active' && ($dl = days_left($client)) !== null && $dl <= 7): ?>
      <p class="alert warn">
        Seu plano <?= h(due_label($client)) ?>. Renove para o app continuar no ar.
        <?php if ($role === 'client' && $support): ?><a href="<?= h($support) ?>" target="_blank" rel="noopener">Falar com o suporte</a><?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($tenant !== null && $clientState !== 'active'): ?>
      <p class="alert err">
        <?= $clientState === 'expired' ? 'A assinatura deste app venceu' : 'Este app está suspenso' ?>: os ouvintes veem a mensagem "temporariamente indisponível".
        <?php if ($role === 'client' && $support): ?><a href="<?= h($support) ?>" target="_blank" rel="noopener">Fale com o suporte</a>.<?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($flash): ?><p class="alert <?= h($flash[1]) ?>"><?= h($flash[0]) ?></p><?php endif; ?>

<?php
// ======================= INÍCIO =======================
if ($tab === 'inicio'):
    $stats = rp_read_json(rp_stats_file(), []);
    $liveAds = array_filter($cfg['ads'], fn ($a) => rp_ad_is_live($a));
    $activeSt = array_filter($cfg['stations'], fn ($x) => !empty($x['active']));
    $views = array_sum(array_column($stats, 'views'));
    $clicks = array_sum(array_column($stats, 'clicks'));
    $appUrl = app_url($tenant);
?>
    <div class="head"><h2>Visão geral</h2><span class="plan">Plano: <b><?= h(due_label($client)) ?></b><?= $client['max_stations'] ? ' · até ' . (int) $client['max_stations'] . ' rádio(s)' : '' ?></span></div>
    <div class="stats">
      <a class="stat" href="?tab=radios"><strong><?= count($activeSt) ?></strong><span>rádios no ar<?= count($cfg['stations']) > count($activeSt) ? ' (de ' . count($cfg['stations']) . ')' : '' ?></span></a>
      <?php if ($tvAllowed): ?><a class="stat" href="?tab=tv"><strong><?= count(array_filter($cfg['tvs'], fn ($x) => !empty($x['active']))) ?></strong><span>canais de TV no ar</span></a><?php endif; ?>
      <a class="stat" href="?tab=pedidos&f=new"><strong><?= unread_requests()['unread'] ?></strong><span>pedidos não lidos</span></a>
      <a class="stat" href="?tab=aparencia"><strong><?= h(RP_THEMES[rp_theme($s)][0]) ?></strong><span>modelo do app · trocar</span></a>
      <a class="stat" href="?tab=publicidade"><strong><?= count($liveAds) ?></strong><span>anúncios no ar</span></a>
      <a class="stat" href="?tab=publicidade"><strong><?= number_format($clicks, 0, ',', '.') ?></strong><span>cliques em anúncios · <?= number_format($views, 0, ',', '.') ?> exibições</span></a>
    </div>

    <?php if (!$cfg['stations']): ?>
      <div class="card callout">
        <h3>Comece cadastrando sua primeira rádio</h3>
        <p>Você vai precisar da URL do streaming (ex.: <code>https://seuservidor.com:8000/live</code>).</p>
        <a class="btn primary" href="?tab=radios&edit=new">+ Cadastrar rádio</a>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Link do aplicativo</h3>
      <p>Divulgue este endereço. O ouvinte pode <b>ouvir direto no navegador</b> ou <b>instalar</b> no celular (Android e iPhone) e no computador (Windows, Mac, Linux).</p>
      <div class="copy"><input readonly value="<?= h($appUrl) ?>" onclick="this.select()"><button class="btn sm" type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Copiado!'">Copiar</button></div>
      <?php if (!$https): ?><p class="alert warn">Para os ouvintes poderem <b>instalar</b> o app, o site precisa estar em <b>HTTPS</b>.</p><?php endif; ?>
    </div>

<?php
// ======================= RÁDIOS =======================
elseif ($tab === 'radios'):
    $genreNames = array_column($cfg['genres'], 'name', 'id');
    if ($edit !== ''):
        $i = find_index($cfg['stations'], $edit);
        $st = $i >= 0 ? $cfg['stations'][$i] : ['id' => '', 'name' => '', 'stream' => '', 'logo' => '', 'genre' => '', 'city' => '', 'slogan' => '', 'metadata' => '', 'active' => true];
?>
    <div class="head"><h2><?= $st['id'] ? 'Editar rádio' : 'Nova rádio' ?></h2><a class="btn sm ghost" href="?tab=radios">← Voltar</a></div>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="station_save">
      <input type="hidden" name="back" value="<?= $back ?>">
      <input type="hidden" name="id" value="<?= h($st['id']) ?>">
      <div class="grid2">
        <label>Nome da rádio *<input name="name" required maxlength="80" value="<?= h($st['name']) ?>" placeholder="Ex.: Rádio Gospel FM"></label>
        <label>Gênero musical
          <select name="genre">
            <option value="">— sem gênero —</option>
            <?php foreach ($cfg['genres'] as $g): ?><option value="<?= h($g['id']) ?>" <?= $g['id'] === $st['genre'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?>
          </select>
          <small><a href="?tab=estilos">Gerenciar gêneros</a></small>
        </label>
      </div>
      <label>URL do streaming *<input name="stream" type="url" required value="<?= h($st['stream']) ?>" placeholder="https://servidor.com:8000/live">
        <small>Link direto do áudio (MP3/AAC) do seu servidor Shoutcast/Icecast. Use <b>https</b> para funcionar em todos os aparelhos.</small></label>
      <div class="grid2">
        <label>Slogan<input name="slogan" maxlength="120" value="<?= h($st['slogan']) ?>" placeholder="A rádio que toca você"></label>
        <label>Cidade<input name="city" maxlength="80" value="<?= h($st['city']) ?>" placeholder="Goiânia - GO"></label>
      </div>
      <label>URL dos metadados (opcional, avançado)<input name="metadata" type="url" value="<?= h($st['metadata']) ?>" placeholder="Deixe em branco: o nome da música é lido do próprio streaming">
        <small>O nome da música (RDS) é lido automaticamente do streaming Shoutcast/Icecast. Preencha só se o seu provedor indicar outra página de status.</small></label>
      <?php if ($st['id']): ?>
      <div class="np-test"><button class="btn sm" type="button" id="npTest" data-id="<?= h($st['id']) ?>">♫ Ver música tocando agora</button><span id="npResult" class="hint"></span></div>
      <?php endif; ?>

      <div class="upload">
        <?php if ($st['logo']): ?><img src="<?= h(img_url($st['logo'])) ?>" alt=""><?php endif; ?>
        <label>Logo da rádio (quadrado, ex.: 500×500)<input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
        <?php if ($st['logo']): ?><label class="check"><input type="checkbox" name="logo_remove" value="1"> Remover logo</label><?php endif; ?>
      </div>
      <label class="check"><input type="checkbox" name="active" value="1" <?= !empty($st['active']) ? 'checked' : '' ?>> Rádio no ar (aparece no aplicativo)</label>
      <div class="actions">
        <button class="btn primary">Salvar rádio</button>
        <?php if ($st['stream']): ?><button class="btn" type="button" id="testStream" data-src="<?= h($st['stream']) ?>">▶ Testar áudio</button><?php endif; ?>
      </div>
    </form>
    <?php if ($st['id']): ?>
    <div class="card form np-push">
      <h3>Música tocando pelo computador da rádio (Zara Radio)</h3>
      <p class="hint">Use se o nome da música não aparecer pelo streaming. Um script lê o arquivo que o Zara grava com a música atual e envia direto para o app, a cada troca de música. Funciona com qualquer programa de transmissão.</p>
      <?php if (empty($st['np_key'])): ?>
        <?= action_btn('np_key_new', $st['id'], 'Ativar envio pelo computador da rádio', 'btn primary') ?>
      <?php else: ?>
        <ol class="steps">
          <li>No Zara Radio, ative a gravação da música atual em arquivo e anote o caminho (ex.: <code>C:\ZaraRadio\currentsong.txt</code>).</li>
          <li>Baixe os dois arquivos e salve <b>na mesma pasta</b> no computador da rádio:
            <div class="actions">
              <a class="btn primary" href="?ajax=np_script&amp;id=<?= h($st['id']) ?>&amp;f=ps1">⬇ musica-no-app.ps1</a>
              <a class="btn" href="?ajax=np_script&amp;id=<?= h($st['id']) ?>&amp;f=bat">⬇ iniciar-musica-no-app.bat</a>
            </div></li>
          <li>Abra o <b>musica-no-app.ps1</b> no Bloco de Notas e troque o caminho da <b>linha 5</b> pelo arquivo do Zara. Salve.</li>
          <li>Dê dois cliques em <b>iniciar-musica-no-app.bat</b> e deixe a janela aberta. A cada música, ela mostra o nome enviado.</li>
          <li>Para iniciar junto com o Windows: copie o <b>.bat</b> para a pasta que abre com <code>Win + R</code> → <code>shell:startup</code>.</li>
        </ol>
        <p class="hint">A chave é secreta e só serve para esta rádio. Se vazar, gere outra (o script antigo para de funcionar).
          <?= action_btn('np_key_new', $st['id'], 'Gerar nova chave', 'btn sm ghost', [], 'Gerar nova chave? O script atual vai parar de funcionar.') ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
<?php else: ?>
    <div class="head"><h2>Rádios</h2><a class="btn primary" href="?tab=radios&edit=new">+ Nova rádio</a></div>
    <?php if (!$cfg['stations']): ?>
      <p class="empty">Nenhuma rádio cadastrada.</p>
    <?php else: ?>
    <div class="rows">
      <?php foreach ($cfg['stations'] as $n => $st): ?>
      <div class="row<?= empty($st['active']) ? ' off' : '' ?>">
        <span class="thumb"><?php if ($st['logo']): ?><img src="<?= h(img_url($st['logo'])) ?>" alt=""><?php else: ?><?= h(mb_strtoupper(mb_substr($st['name'], 0, 2))) ?><?php endif; ?></span>
        <div class="row-main">
          <strong><?= h($st['name']) ?></strong>
          <small><?= h($genreNames[$st['genre']] ?? 'Sem gênero') ?> · <span class="mono"><?= h($st['stream']) ?></span></small>
        </div>
        <span class="badge <?= !empty($st['active']) ? 'on' : '' ?>"><?= !empty($st['active']) ? 'No ar' : 'Fora do ar' ?></span>
        <div class="row-actions">
          <?= action_btn('station_move', $st['id'], '↑', 'btn sm icon', ['dir' => -1]) ?>
          <?= action_btn('station_move', $st['id'], '↓', 'btn sm icon', ['dir' => 1]) ?>
          <?= action_btn('station_toggle', $st['id'], !empty($st['active']) ? 'Tirar do ar' : 'Colocar no ar') ?>
          <a class="btn sm" href="?tab=radios&edit=<?= h($st['id']) ?>">Editar</a>
          <?= action_btn('station_delete', $st['id'], 'Excluir', 'btn sm danger', [], 'Excluir a rádio "' . $st['name'] . '"?') ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="hint">A ordem aqui é a ordem em que as rádios aparecem no aplicativo.</p>
    <?php endif; ?>
<?php endif; ?>

<?php
// ======================= WEB TV =======================
elseif ($tab === 'tv'):
    $genreNames = array_column($cfg['genres'], 'name', 'id');
    if ($edit !== ''):
        $i = find_index($cfg['tvs'], $edit);
        $tv = $i >= 0 ? $cfg['tvs'][$i] : ['id' => '', 'name' => '', 'link' => '', 'type_choice' => 'auto', 'type' => '', 'src' => '', 'logo' => '', 'genre' => '', 'description' => '', 'active' => true];
?>
    <div class="head"><h2><?= $tv['id'] ? 'Editar canal' : 'Novo canal de TV' ?></h2><a class="btn sm ghost" href="?tab=tv">← Voltar</a></div>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="tv_save">
      <input type="hidden" name="back" value="<?= $back ?>">
      <input type="hidden" name="id" value="<?= h($tv['id']) ?>">
      <div class="grid2">
        <label>Nome do canal *<input name="name" required maxlength="80" value="<?= h($tv['name']) ?>" placeholder="Ex.: TV Nova Geração"></label>
        <label>Gênero musical
          <select name="genre">
            <option value="">— sem gênero —</option>
            <?php foreach ($cfg['genres'] as $g): ?><option value="<?= h($g['id']) ?>" <?= $g['id'] === $tv['genre'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <label>Link da transmissão *
        <textarea name="link" rows="3" required placeholder="https://servidor.com/live/canal/playlist.m3u8&#10;ou https://www.youtube.com/watch?v=…&#10;ou o código &lt;iframe …&gt; do seu provedor"><?= h($tv['link']) ?></textarea>
        <small>Aceita link <b>.m3u8</b> (HLS), vídeo MP4, link do <b>YouTube</b> (vídeo, live ou canal ao vivo), Vimeo ou o <b>código de incorporação</b> (iframe) do seu provedor. Precisa ser <b>https</b>.</small>
      </label>
      <div class="grid2">
        <label>Tipo
          <select name="type">
            <?php foreach (TV_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $tv['type_choice'] === $k ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
          </select>
          <small><?= $tv['type'] ? 'Detectado: ' . h(TV_TYPES[$tv['type']]) . '.' : 'Deixe em "Detectar" — mude só se o canal não abrir.' ?></small>
        </label>
        <label>Descrição curta<input name="description" maxlength="160" value="<?= h($tv['description']) ?>" placeholder="Programação 24 horas"></label>
      </div>
      <div class="upload">
        <?php if ($tv['logo']): ?><img src="<?= h(img_url($tv['logo'])) ?>" alt="" class="wide"><?php endif; ?>
        <label>Imagem do canal (logo ou capa 16:9, ex.: 1280×720)<input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
        <?php if ($tv['logo']): ?><label class="check"><input type="checkbox" name="logo_remove" value="1"> Remover imagem</label><?php endif; ?>
      </div>
      <label class="check"><input type="checkbox" name="active" value="1" <?= !empty($tv['active']) ? 'checked' : '' ?>> Canal no ar (aparece no aplicativo)</label>
      <div class="actions"><button class="btn primary">Salvar canal</button></div>
    </form>
    <?php if ($tv['src']): ?>
    <div class="card">
      <h3>Prévia</h3>
      <div class="tv-preview" data-type="<?= h($tv['type']) ?>" data-src="<?= h($tv['src']) ?>"></div>
      <p class="hint">Se a prévia não carregar aqui, teste também no app: alguns provedores só liberam o player no seu domínio.</p>
    </div>
    <?php endif; ?>
<?php else: ?>
    <div class="head"><h2>Web TV</h2><a class="btn primary" href="?tab=tv&edit=new">+ Novo canal</a></div>
    <?php if (!$cfg['tvs']): ?>
      <p class="empty">Nenhum canal de TV cadastrado. Cadastre o link da sua web TV para ela aparecer no app.</p>
    <?php else: ?>
    <div class="rows">
      <?php foreach ($cfg['tvs'] as $tv): ?>
      <div class="row<?= empty($tv['active']) ? ' off' : '' ?>">
        <span class="thumb wide"><?php if ($tv['logo']): ?><img src="<?= h(img_url($tv['logo'])) ?>" alt=""><?php else: ?>TV<?php endif; ?></span>
        <div class="row-main">
          <strong><?= h($tv['name']) ?></strong>
          <small><?= h(TV_TYPES[$tv['type']] ?? $tv['type']) ?> · <?= h($genreNames[$tv['genre']] ?? 'Sem gênero') ?> · <span class="mono"><?= h($tv['src']) ?></span></small>
        </div>
        <span class="badge <?= !empty($tv['active']) ? 'on' : '' ?>"><?= !empty($tv['active']) ? 'No ar' : 'Fora do ar' ?></span>
        <div class="row-actions">
          <?= action_btn('tv_move', $tv['id'], '↑', 'btn sm icon', ['dir' => -1]) ?>
          <?= action_btn('tv_move', $tv['id'], '↓', 'btn sm icon', ['dir' => 1]) ?>
          <?= action_btn('tv_toggle', $tv['id'], !empty($tv['active']) ? 'Tirar do ar' : 'Colocar no ar') ?>
          <a class="btn sm" href="?tab=tv&edit=<?= h($tv['id']) ?>">Editar</a>
          <?= action_btn('tv_delete', $tv['id'], 'Excluir', 'btn sm danger', [], 'Excluir o canal "' . $tv['name'] . '"?') ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php
// ======================= PEDIDOS =======================
elseif ($tab === 'pedidos'):
    $filter = (string) ($_GET['f'] ?? 'all');
    $items = array_reverse(rp_read_json(rp_tfile('requests'), ['items' => []])['items']);
    $counts = ['all' => count($items), 'new' => 0, 'music' => 0, 'prayer' => 0];
    foreach ($items as $r) {
        $counts[$r['type']]++;
        if ($r['status'] === 'new') {
            $counts['new']++;
        }
    }
    $items = array_filter($items, fn ($r) => match ($filter) {
        'new' => $r['status'] === 'new', 'music' => $r['type'] === 'music', 'prayer' => $r['type'] === 'prayer', default => true,
    });
    $ago = function (int $t): string {
        $d = time() - $t;
        return $d < 60 ? 'agora' : ($d < 3600 ? floor($d / 60) . ' min' : ($d < 86400 ? floor($d / 3600) . ' h' : date('d/m H:i', $t)));
    };
?>
    <div class="head">
      <h2>Pedidos dos ouvintes</h2>
      <div class="actions">
        <button class="btn sm" type="button" id="enableNotif">🔔 Ativar avisos</button>
        <?= action_btn('req_read_all', '', 'Marcar todos como lidos', 'btn sm', ['back' => $back]) ?>
        <?= action_btn('req_clear', '', 'Apagar lidos', 'btn sm ghost', ['back' => $back], 'Apagar os pedidos já lidos e respondidos?') ?>
      </div>
    </div>
    <p class="hint">Deixe esta tela aberta durante o programa: quando chega pedido novo, o painel toca um aviso e mostra uma notificação (clique em "Ativar avisos" uma vez).</p>
    <nav class="filters">
      <?php foreach (['all' => 'Todos', 'new' => 'Não lidos', 'music' => 'Músicas', 'prayer' => 'Orações'] as $k => $label): ?>
        <a href="?tab=pedidos&f=<?= $k ?>" class="chip<?= $filter === $k ? ' active' : '' ?>"><?= $label ?> <b><?= $counts[$k] ?></b></a>
      <?php endforeach; ?>
    </nav>
    <?php if (!$items): ?>
      <p class="empty">Nenhum pedido <?= $filter === 'new' ? 'não lido' : 'ainda' ?>. Os pedidos feitos no app aparecem aqui na hora.</p>
    <?php endif; ?>
    <div class="reqs">
      <?php foreach ($items as $r):
          $wa = $r['phone'] ? rp_whatsapp_link($r['phone']) : '';
      ?>
      <article class="req <?= h($r['type']) ?><?= $r['status'] === 'new' ? ' is-new' : '' ?>">
        <header>
          <span class="req-type"><?= $r['type'] === 'prayer' ? '🙏 Oração' : '🎵 Música' ?></span>
          <strong><?= h($r['name']) ?></strong><?php if ($r['city']): ?> <small>· <?= h($r['city']) ?></small><?php endif; ?>
          <time><?= $ago($r['at']) ?></time>
          <?php if ($r['status'] === 'new'): ?><span class="badge on">novo</span><?php endif; ?>
        </header>
        <?php if ($r['song']): ?><p class="req-song"><?= h($r['song']) ?></p><?php endif; ?>
        <p class="req-text"><?= nl2br(h($r['text'])) ?></p>
        <?php if ($r['reply']): ?><p class="req-reply"><b>Sua resposta:</b> <?= nl2br(h($r['reply'])) ?></p><?php endif; ?>
        <form method="post" class="req-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="req_reply">
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="back" value="<?= $back ?>">
          <input name="reply" maxlength="500" placeholder="<?= $r['reply'] ? 'Mudar a resposta' : ($r['type'] === 'prayer' ? 'Ex.: Estamos orando por você!' : 'Ex.: Vai tocar daqui a pouco!') ?>" aria-label="Resposta">
          <button class="btn sm primary">Responder</button>
        </form>
        <footer>
          <?php if ($wa): ?><a class="btn sm" href="<?= h($wa) ?>" target="_blank" rel="noopener">WhatsApp <?= h($r['phone']) ?></a><?php endif; ?>
          <?= $r['status'] === 'new' ? action_btn('req_read', (string) $r['id'], 'Marcar como lido', 'btn sm', ['back' => $back]) : action_btn('req_unread', (string) $r['id'], 'Marcar como não lido', 'btn sm ghost', ['back' => $back]) ?>
          <?= action_btn('req_delete', (string) $r['id'], 'Excluir', 'btn sm danger', ['back' => $back], 'Excluir este pedido?') ?>
        </footer>
      </article>
      <?php endforeach; ?>
    </div>

    <form method="post" class="card form interact">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="interact_save">
      <input type="hidden" name="back" value="<?= $back ?>">
      <h3>O que aparece no app</h3>
      <label class="check"><input type="checkbox" name="req_music" value="1" <?= !empty($s['req_music']) ? 'checked' : '' ?>> Botão "Pedir música"</label>
      <label class="check"><input type="checkbox" name="req_prayer" value="1" <?= !empty($s['req_prayer']) ? 'checked' : '' ?>> Botão "Pedido de oração"</label>
      <label class="check"><input type="checkbox" name="chat" value="1" <?= !empty($s['chat']) ? 'checked' : '' ?>> Chat dos ouvintes</label>
      <label class="check"><input type="checkbox" name="chat_announce" value="1" <?= !empty($s['chat_announce']) ? 'checked' : '' ?>> Avisar no chat quando alguém pede música (pedidos de oração nunca aparecem no chat)</label>
      <div class="actions"><button class="btn primary">Salvar</button></div>
    </form>

<?php
// ======================= CHAT =======================
elseif ($tab === 'chat'):
    $bans = rp_read_json(rp_tfile('bans'), []);
?>
    <div class="head">
      <h2>Chat dos ouvintes</h2>
      <div class="actions">
        <span class="online" id="chatOnline">Ninguém online agora</span>
        <?= action_btn('chat_clear', '', 'Apagar conversa', 'btn sm ghost', [], 'Apagar todas as mensagens do chat?') ?>
      </div>
    </div>
    <?php if (empty($s['chat'])): ?><p class="alert warn">O chat está desligado no app. Ligue em <a href="?tab=pedidos">Pedidos → O que aparece no app</a>.</p><?php endif; ?>
    <div class="card chatbox">
      <div class="chat-log" id="chatLog" aria-live="polite"><p class="empty sm">Carregando…</p></div>
      <p class="chat-send-title">Responder no chat (aparece em destaque para todos os ouvintes)</p>
      <form class="chat-send" id="chatSend">
        <input id="chatNick" maxlength="24" value="Locutor" aria-label="Seu nome no chat" title="Seu nome no chat">
        <input id="chatText" maxlength="300" placeholder="Escreva para os ouvintes e aperte Enter…" autocomplete="off" aria-label="Mensagem">
        <button class="btn primary">Enviar</button>
      </form>
    </div>
    <p class="hint">Em cada mensagem: <b>Responder</b> (escreve "@nome" para você completar), <b>Apagar</b> ou <b>Bloquear</b> o autor. Os nomes em "Online agora" são os apelidos de quem está com o chat aberto no app.</p>
    <?php if ($bans): ?>
      <h3 class="section">Bloqueados</h3>
      <div class="rows">
        <?php foreach ($bans as $key => $b): ?>
          <div class="row"><div class="row-main"><strong><?= h($b['nick'] ?? 'Ouvinte') ?></strong><small>bloqueado em <?= date('d/m/Y H:i', (int) ($b['at'] ?? 0)) ?></small></div>
            <div class="row-actions"><?= action_btn('chat_unban', $key, 'Desbloquear') ?></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

<?php
// ======================= ESTILOS =======================
elseif ($tab === 'estilos'):
    $count = array_count_values(array_column($cfg['stations'], 'genre'));
?>
    <div class="head"><h2>Gêneros musicais</h2></div>
    <p class="hint">Os gêneros viram os filtros do aplicativo (só aparecem os que têm alguma rádio ou TV no ar). Para trocar o visual do app, use <a href="?tab=aparencia">Modelo e aparência</a>.</p>
    <form method="post" class="card add-inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="genre_add"><input type="hidden" name="back" value="<?= $back ?>">
      <input name="name" required maxlength="40" placeholder="Novo gênero (ex.: Pagode)">
      <button class="btn primary">Adicionar</button>
    </form>
    <div class="rows">
      <?php foreach ($cfg['genres'] as $g): ?>
      <div class="row">
        <form method="post" class="row-main rename">
          <?= csrf_field() ?><input type="hidden" name="action" value="genre_rename"><input type="hidden" name="id" value="<?= h($g['id']) ?>"><input type="hidden" name="back" value="<?= $back ?>">
          <input name="name" value="<?= h($g['name']) ?>" maxlength="40" required aria-label="Nome do gênero">
          <button class="btn sm">Renomear</button>
        </form>
        <span class="badge"><?= (int) ($count[$g['id']] ?? 0) ?> rádio(s)</span>
        <div class="row-actions">
          <?= action_btn('genre_move', $g['id'], '↑', 'btn sm icon', ['dir' => -1]) ?>
          <?= action_btn('genre_move', $g['id'], '↓', 'btn sm icon', ['dir' => 1]) ?>
          <?= action_btn('genre_delete', $g['id'], 'Excluir', 'btn sm danger', [], 'Excluir o gênero "' . $g['name'] . '"?') ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

<?php
// ======================= PUBLICIDADE =======================
elseif ($tab === 'publicidade'):
    $stats = rp_read_json(rp_stats_file(), []);
    if ($edit !== ''):
        $i = find_index($cfg['ads'], $edit);
        $ad = $i >= 0 ? $cfg['ads'][$i] : ['id' => '', 'title' => '', 'text' => '', 'image' => '', 'link' => '', 'position' => (string) ($_GET['pos'] ?? 'top'), 'starts' => '', 'ends' => '', 'active' => true];
?>
    <div class="head"><h2><?= $ad['id'] ? 'Editar publicidade' : 'Nova publicidade' ?></h2><a class="btn sm ghost" href="?tab=publicidade">← Voltar</a></div>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="ad_save">
      <input type="hidden" name="back" value="<?= $back ?>">
      <input type="hidden" name="id" value="<?= h($ad['id']) ?>">
      <fieldset class="positions">
        <legend>Onde vai aparecer *</legend>
        <?php foreach (AD_POSITIONS as $k => $label): ?>
          <label class="pos"><input type="radio" name="position" value="<?= $k ?>" <?= $ad['position'] === $k ? 'checked' : '' ?> required>
            <span><b><?= h($label) ?></b><small><?= ['top' => 'Imagem 1200×300 (4:1). Gira entre os anúncios.', 'player' => 'Imagem 1200×200 (6:1). Aparece enquanto ouvem.', 'popup' => 'Imagem 1080×1080 ou 1080×1350. Uma vez por visita.'][$k] ?></small></span></label>
        <?php endforeach; ?>
      </fieldset>
      <div class="upload">
        <?php if ($ad['image']): ?><img src="<?= h(img_url($ad['image'])) ?>" alt="" class="wide"><?php endif; ?>
        <label>Imagem do anúncio<input type="file" name="image_file" accept="image/png,image/jpeg,image/webp,image/gif"><small>JPG, PNG, WEBP ou GIF (até 4 MB).</small></label>
        <?php if ($ad['image']): ?><label class="check"><input type="checkbox" name="image_remove" value="1"> Remover imagem</label><?php endif; ?>
      </div>
      <div class="grid2">
        <label>Título / anunciante<input name="title" maxlength="80" value="<?= h($ad['title']) ?>" placeholder="Ex.: Supermercado Bom Preço"><small>Sem imagem, o anúncio aparece como texto.</small></label>
        <label>Link ao tocar<input name="link" value="<?= h($ad['link']) ?>" placeholder="https://… ou número de WhatsApp"></label>
      </div>
      <label>Texto curto (opcional)<input name="text" maxlength="160" value="<?= h($ad['text']) ?>" placeholder="Ofertas toda semana! Peça pelo WhatsApp."></label>
      <div class="grid2">
        <label>Começa em<input type="date" name="starts" value="<?= h($ad['starts']) ?>"></label>
        <label>Termina em<input type="date" name="ends" value="<?= h($ad['ends']) ?>"><small>Deixe em branco para não expirar.</small></label>
      </div>
      <label class="check"><input type="checkbox" name="active" value="1" <?= !empty($ad['active']) ? 'checked' : '' ?>> Ativo</label>
      <div class="actions"><button class="btn primary">Salvar publicidade</button></div>
    </form>
<?php else: ?>
    <div class="head"><h2>Publicidade</h2><a class="btn primary" href="?tab=publicidade&edit=new">+ Nova publicidade</a></div>
    <?php $hasSamples = (bool) array_filter($cfg['ads'], fn ($a) => !empty($a['sample'])); ?>
    <div class="card samples">
      <div>
        <strong>Anúncios de exemplo</strong>
        <p class="hint"><?= $hasSamples
            ? 'Os anúncios marcados como "Exemplo" são fictícios. Remova quando cadastrar os anunciantes de verdade.'
            : 'Preenche os 3 espaços com anunciantes fictícios para você ver como fica no app.' ?></p>
      </div>
      <?= $hasSamples
          ? action_btn('ads_samples_remove', '', 'Remover exemplos', 'btn danger', [], 'Remover todos os anúncios de exemplo?')
          : action_btn('ads_samples', '', 'Adicionar anúncios de exemplo', 'btn') ?>
    </div>
    <?php foreach (AD_POSITIONS as $pos => $label):
        $list = array_filter($cfg['ads'], fn ($a) => ($a['position'] ?? '') === $pos); ?>
      <h3 class="section"><?= h($label) ?> <a class="btn sm" href="?tab=publicidade&edit=new&pos=<?= $pos ?>">+ Adicionar</a></h3>
      <?php if (!$list): ?><p class="empty sm">Nenhum anúncio neste espaço.</p><?php endif; ?>
      <div class="rows">
        <?php foreach ($list as $ad):
            $live = rp_ad_is_live($ad);
            $v = (int) ($stats[$ad['id']]['views'] ?? 0);
            $c = (int) ($stats[$ad['id']]['clicks'] ?? 0);
            $status = $live ? 'No ar' : (empty($ad['active']) ? 'Pausado' : ((!empty($ad['ends']) && $ad['ends'] < date('Y-m-d')) ? 'Expirado' : 'Agendado'));
        ?>
        <div class="row<?= $live ? '' : ' off' ?>">
          <span class="thumb wide"><?php if ($ad['image']): ?><img src="<?= h(img_url($ad['image'])) ?>" alt=""><?php else: ?>Texto<?php endif; ?></span>
          <div class="row-main">
            <strong><?= h($ad['title'] ?: 'Sem título') ?><?php if (!empty($ad['sample'])): ?> <span class="badge">Exemplo</span><?php endif; ?></strong>
            <small><?= $ad['starts'] || $ad['ends'] ? h(($ad['starts'] ? date('d/m/Y', strtotime($ad['starts'])) : '…') . ' até ' . ($ad['ends'] ? date('d/m/Y', strtotime($ad['ends'])) : 'sem fim')) . ' · ' : '' ?>
              <?= number_format($v, 0, ',', '.') ?> exibições · <?= number_format($c, 0, ',', '.') ?> cliques<?= $v ? ' · ' . number_format($c / $v * 100, 1, ',', '.') . '%' : '' ?></small>
          </div>
          <span class="badge <?= $live ? 'on' : '' ?>"><?= $status ?></span>
          <div class="row-actions">
            <?= action_btn('ad_move', $ad['id'], '↑', 'btn sm icon', ['dir' => -1]) ?>
            <?= action_btn('ad_move', $ad['id'], '↓', 'btn sm icon', ['dir' => 1]) ?>
            <?= action_btn('ad_toggle', $ad['id'], !empty($ad['active']) ? 'Pausar' : 'Ativar') ?>
            <a class="btn sm" href="?tab=publicidade&edit=<?= h($ad['id']) ?>">Editar</a>
            <?= action_btn('ad_reset', $ad['id'], 'Zerar', 'btn sm ghost', [], 'Zerar exibições e cliques deste anúncio?') ?>
            <?= action_btn('ad_delete', $ad['id'], 'Excluir', 'btn sm danger', [], 'Excluir este anúncio?') ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
// ======================= APARÊNCIA =======================
elseif ($tab === 'aparencia'):
?>
    <div class="head"><h2>Modelo e aparência</h2></div>
    <form method="post" enctype="multipart/form-data" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="settings_save">
      <input type="hidden" name="back" value="<?= $back ?>">
      <h3>Modelo do aplicativo</h3>
      <p class="hint">Clique no modelo e depois em <b>Salvar aparência</b> (no fim da página). Pode trocar quando quiser: rádios, anúncios e pedidos continuam iguais. São <?= count(RP_THEMES) ?> modelos. Rádio única abre direto no player; com várias rádios ou TV, o app mostra a lista.</p>
      <div class="themes">
        <?php foreach (RP_THEMES as $key => [$tname, $tdesc, $tbg, $taccent, $tpreview]): ?>
          <label class="theme">
            <input type="radio" name="theme" value="<?= h($key) ?>" <?= rp_theme($s) === $key ? 'checked' : '' ?>>
            <?php if (is_file(__DIR__ . '/previews/' . $key . '.jpg')): ?>
            <span class="theme-preview"><img src="previews/<?= h($key) ?>.jpg?v=<?= (int) filemtime(__DIR__ . '/previews/' . $key . '.jpg') ?>" alt="" loading="lazy"></span>
            <?php else: ?>
            <span class="theme-preview" style="background: <?= h($tpreview) ?>">
              <span class="tp-art" style="border-color: <?= h($taccent) ?>"></span>
              <span class="tp-bar" style="background: <?= h($taccent) ?>"></span>
              <span class="tp-play" style="background: <?= h($taccent) ?>"></span>
            </span>
            <?php endif; ?>
            <span class="theme-text"><b><?= h($tname) ?></b><small><?= h($tdesc) ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="upload">
        <?php if ($s['background']): ?><img src="<?= h(img_url($s['background'])) ?>" alt="" class="wide"><?php endif; ?>
        <label>Imagem de fundo (opcional)<input type="file" name="background_file" accept="image/png,image/jpeg,image/webp">
          <small>Foto do estúdio, da igreja, do palco… Vertical (1080×1920) fica melhor. O tema aplica um tom por cima para o texto continuar legível.</small></label>
        <?php if ($s['background']): ?><label class="check"><input type="checkbox" name="background_remove" value="1"> Remover imagem de fundo</label><?php endif; ?>
      </div>

      <h3>Identidade</h3>
      <div class="grid2">
        <label>Nome do aplicativo *<input name="title" required maxlength="60" value="<?= h($s['title']) ?>"></label>
        <label>Nome curto (abaixo do ícone)<input name="short_name" maxlength="20" value="<?= h($s['short_name']) ?>" placeholder="Até 12 letras de preferência"></label>
      </div>
      <label>Descrição<input name="description" maxlength="160" value="<?= h($s['description']) ?>"></label>
      <div class="grid2">
        <div class="field">
          <label class="check"><input type="checkbox" name="accent_custom" value="1" <?= !empty($s['accent_custom']) ? 'checked' : '' ?>> Usar uma cor própria no lugar da cor do tema</label>
          <span class="color"><input type="color" name="accent" value="<?= h($s['accent']) ?>" aria-label="Cor própria"><code><?= h($s['accent']) ?></code></span>
        </div>
        <div class="upload">
          <?php if ($s['logo']): ?><img src="<?= h(img_url($s['logo'])) ?>" alt=""><?php endif; ?>
          <label>Logo / ícone do app (quadrado, 512×512 ou maior)<input type="file" name="logo_file" accept="image/png,image/jpeg,image/webp"></label>
          <?php if ($s['logo']): ?><label class="check"><input type="checkbox" name="logo_remove" value="1"> Voltar ao ícone padrão</label><?php endif; ?>
        </div>
      </div>
      <label class="check"><input type="checkbox" name="np_art" value="1" <?= !empty($s['np_art']) ? 'checked' : '' ?>> Mostrar a capa do álbum da música tocando (busca automática pelo nome da música)</label>
      <label>Sobre a rádio (aparece no fim do app)<textarea name="about" rows="3" maxlength="1000"><?= h($s['about']) ?></textarea></label>

      <h3>Contatos e redes sociais</h3>
      <div class="grid2">
        <label>WhatsApp<input name="whatsapp" value="<?= h($s['whatsapp']) ?>" placeholder="(62) 99999-0000"></label>
        <label>Instagram<input name="instagram" value="<?= h($s['instagram']) ?>" placeholder="@suaradio"></label>
        <label>Facebook<input name="facebook" value="<?= h($s['facebook']) ?>" placeholder="suaradio ou link"></label>
        <label>YouTube<input name="youtube" value="<?= h($s['youtube']) ?>" placeholder="@suaradio ou link"></label>
        <label>Site<input name="site" value="<?= h($s['site']) ?>" placeholder="https://suaradio.com.br"></label>
      </div>

      <h3>Publicidade</h3>
      <div class="grid2">
        <label>Trocar banner a cada (segundos)<input type="number" name="ad_rotate" min="3" max="60" value="<?= (int) $s['ad_rotate'] ?>"></label>
        <label>Popup aparece após (segundos)<input type="number" name="popup_delay" min="0" max="60" value="<?= (int) $s['popup_delay'] ?>"></label>
      </div>
      <div class="actions"><button class="btn primary">Salvar aparência</button></div>
    </form>

<?php
// ======================= CLIENTES (master) =======================
elseif ($tab === 'clientes'):
    if ($edit !== ''):
        $c = $clients[$edit] ?? null;
        $isNew = $c === null;
        $c ??= ['name' => '', 'user' => '', 'status' => 'active', 'expires' => date('Y-m-d', strtotime('+1 month')), 'max_stations' => 0, 'allow_tv' => true, 'max_tvs' => 0, 'phone' => '', 'notes' => ''];
        $suggestPass = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
?>
    <div class="head"><h2><?= $isNew ? 'Novo cliente' : 'Editar cliente' ?></h2><a class="btn sm ghost" href="?tab=clientes">← Voltar</a></div>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="client_save">
      <input type="hidden" name="back" value="<?= $back ?>">
      <input type="hidden" name="orig" value="<?= $isNew ? '' : h($edit) ?>">
      <h3>Cliente</h3>
      <div class="grid2">
        <label>Nome da rádio / cliente *<input name="name" id="cName" required maxlength="80" value="<?= h($c['name']) ?>" placeholder="Ex.: Rádio Nova Geração"></label>
        <label>WhatsApp do cliente<input name="phone" maxlength="40" value="<?= h($c['phone']) ?>" placeholder="(14) 99999-0000"></label>
      </div>
      <label>Endereço do app *
        <?php if ($isNew): ?>
          <span class="slug"><span><?= h(base_url()) ?>r/</span><input name="slug" id="cSlug" required maxlength="40" pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?" placeholder="radio-nova-geracao"><span>/</span></span>
          <small>Letras minúsculas, números e hífen. Não pode ser trocado depois.</small>
        <?php else: ?>
          <span class="slug"><a href="<?= h(app_url($edit)) ?>" target="_blank" rel="noopener"><?= h(app_url($edit)) ?></a></span>
        <?php endif; ?>
      </label>
      <h3>Acesso ao painel</h3>
      <div class="grid2">
        <label>Usuário (login) <?= $isNew ? '*' : '' ?><input name="user" maxlength="80" value="<?= h($c['user']) ?>" <?= $isNew ? 'required' : '' ?> autocomplete="off" placeholder="email ou nome de usuário"></label>
        <label><?= $isNew ? 'Senha *' : 'Nova senha (deixe em branco para manter)' ?>
          <span class="copy"><input name="pass" id="cPass" minlength="8" <?= $isNew ? 'required value="' . h($suggestPass) . '"' : '' ?> autocomplete="new-password"><button class="btn sm" type="button" onclick="document.getElementById('cPass').value='<?= h($suggestPass) ?>'">Gerar</button></span>
        </label>
      </div>
      <h3>Plano</h3>
      <div class="grid2">
        <label>Situação
          <select name="status">
            <option value="active" <?= $c['status'] !== 'suspended' ? 'selected' : '' ?>>Ativo</option>
            <option value="suspended" <?= $c['status'] === 'suspended' ? 'selected' : '' ?>>Suspenso (app fora do ar)</option>
          </select>
        </label>
        <label>Vencimento<input type="date" name="expires" id="cExpires" value="<?= h($c['expires']) ?>">
          <small>No dia seguinte a esta data o app sai do ar sozinho. Em branco: sem vencimento.
            <a href="#" onclick="const e=document.getElementById('cExpires');const d=new Date();d.setMonth(d.getMonth()+1);e.value=d.toISOString().slice(0,10);return false">+1 mês</a> ·
            <a href="#" onclick="const e=document.getElementById('cExpires');const d=new Date();d.setFullYear(d.getFullYear()+1);e.value=d.toISOString().slice(0,10);return false">+1 ano</a> ·
            <a href="#" onclick="document.getElementById('cExpires').value='';return false">sem vencimento</a></small></label>
        <label>Limite de rádios<input type="number" name="max_stations" min="0" max="999" value="<?= (int) $c['max_stations'] ?>"><small>0 = sem limite.</small></label>
        <div class="field">
          <label class="check"><input type="checkbox" name="allow_tv" value="1" <?= ($c['allow_tv'] ?? true) ? 'checked' : '' ?>> Permitir Web TV</label>
          <label>Limite de canais de TV<input type="number" name="max_tvs" min="0" max="999" value="<?= (int) ($c['max_tvs'] ?? 0) ?>"><small>0 = sem limite.</small></label>
        </div>
      </div>
      <label>Observações (só você vê)<textarea name="notes" rows="2" maxlength="500"><?= h($c['notes']) ?></textarea></label>
      <div class="actions"><button class="btn primary"><?= $isNew ? 'Criar cliente' : 'Salvar' ?></button></div>
    </form>
    <?php if (!$isNew): ?>
    <form method="post" class="card form danger-zone">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="client_delete">
      <input type="hidden" name="id" value="<?= h($edit) ?>">
      <input type="hidden" name="back" value="<?= $back ?>">
      <h3>Excluir cliente</h3>
      <p class="hint">Apaga o app, as rádios, os anúncios, as imagens e as estatísticas deste cliente. Não dá para desfazer.</p>
      <label>Digite <code><?= h($edit) ?></code> para confirmar<input name="confirm" autocomplete="off"></label>
      <div class="actions"><button class="btn danger">Excluir definitivamente</button></div>
    </form>
    <?php endif; ?>
    <script>
      (() => {
        const n = document.getElementById('cName'), s = document.getElementById('cSlug');
        if (!n || !s) return;
        let touched = false;
        s.addEventListener('input', () => { touched = true; });
        n.addEventListener('input', () => {
          if (touched) return;
          s.value = n.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
        });
      })();
    </script>
<?php else:
    $today = date('Y-m-d');
?>
    <?php if ($cred): $msg = "Olá! Seu aplicativo {$cred['name']} está pronto.\n\nApp para os ouvintes: {$cred['app']}\n\nPainel para editar: {$cred['panel']}\nUsuário: {$cred['user']}\nSenha: {$cred['pass']}"; ?>
      <div class="card cred">
        <h3>Dados de acesso de <?= h($cred['name']) ?></h3>
        <p class="hint">Copie e envie ao cliente agora. Por segurança, a senha não aparece de novo.</p>
        <textarea readonly rows="7" id="credMsg"><?= h($msg) ?></textarea>
        <div class="actions">
          <button class="btn primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('credMsg').value).then(()=>{this.textContent='Copiado!'})">Copiar mensagem</button>
        </div>
      </div>
    <?php endif; ?>
    <div class="head"><h2>Clientes</h2><a class="btn primary" href="?tab=clientes&edit=new">+ Novo cliente</a></div>
    <?php if (!$clients): ?>
      <p class="empty">Nenhum cliente ainda. Cadastre o primeiro para gerar o link do app e o acesso ao painel.</p>
    <?php else: ?>
    <div class="stats">
      <?php $counts = array_count_values(array_map('rp_client_state', $clients)); ?>
      <div class="stat"><strong><?= count($clients) ?></strong><span>clientes</span></div>
      <div class="stat"><strong><?= (int) ($counts['active'] ?? 0) ?></strong><span>ativos</span></div>
      <div class="stat"><strong><?= (int) ($counts['expired'] ?? 0) ?></strong><span>vencidos</span></div>
      <div class="stat"><strong><?= (int) ($counts['suspended'] ?? 0) ?></strong><span>suspensos</span></div>
    </div>
    <?php
        // Vencidos e suspensos primeiro, depois quem vence mais cedo; sem vencimento por último.
        uksort($clients, function ($a, $b) use ($clients) {
            $rank = fn ($c) => rp_client_state($c) === 'active' ? 1 : 0;
            $due = fn ($c) => $c['expires'] ?: '9999-12-31';
            return [$rank($clients[$a]), $due($clients[$a]), $clients[$a]['name']] <=> [$rank($clients[$b]), $due($clients[$b]), $clients[$b]['name']];
        });
    ?>
    <div class="rows">
      <?php foreach ($clients as $slug => $c):
          $state = rp_client_state($c);
          $cc = rp_read_json(RP_TENANTS . '/' . $slug . '/config.json', []);
          $nSt = count($cc['stations'] ?? []);
          $nTv = count($cc['tvs'] ?? []);
          $logo = $cc['settings']['logo'] ?? '';
          $dl = days_left($c);
          $soon = $state === 'active' && $dl !== null && $dl <= 7;
      ?>
      <div class="row<?= $state !== 'active' ? ' off' : '' ?>">
        <span class="thumb"><?php if ($logo): ?><img src="<?= h(img_url($logo)) ?>" alt=""><?php else: ?><?= h(mb_strtoupper(mb_substr($c['name'], 0, 2))) ?><?php endif; ?></span>
        <div class="row-main">
          <strong><?= h($c['name']) ?><?php if ($platform['default_tenant'] === $slug): ?> <span class="badge">endereço principal</span><?php endif; ?></strong>
          <small><a href="<?= h(app_url($slug)) ?>" target="_blank" rel="noopener" class="mono">/r/<?= h($slug) ?>/</a> · <?= $c['user'] !== '' ? 'login: ' . h($c['user']) : 'sem login de cliente' ?> · <?= $nSt ?> rádio(s)<?= $c['max_stations'] ? ' de ' . (int) $c['max_stations'] : '' ?><?= ($c['allow_tv'] ?? true) ? ' · ' . $nTv . ' canal(is) de TV' . (!empty($c['max_tvs']) ? ' de ' . (int) $c['max_tvs'] : '') : ' · sem TV' ?></small>
        </div>
        <span class="badge <?= $state === 'active' ? 'on' : 'bad' ?>"><?= CLIENT_STATES[$state] ?></span>
        <small class="due<?= $soon ? ' soon' : '' ?><?= $state === 'expired' ? ' late' : '' ?>"><?= h(due_label($c)) ?></small>
        <div class="row-actions">
          <?= action_btn('client_renew', $slug, 'Renovar +1 mês', 'btn sm', ['months' => 1], 'Renovar ' . $c['name'] . ' por 1 mês?') ?>
          <?= action_btn('client_renew', $slug, '+1 ano', 'btn sm', ['months' => 12], 'Renovar ' . $c['name'] . ' por 1 ano?') ?>
          <?= action_btn('client_enter', $slug, 'Entrar no painel', 'btn sm primary') ?>
          <a class="btn sm" href="?tab=clientes&edit=<?= h($slug) ?>">Editar</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php
// ======================= PLATAFORMA (master) =======================
elseif ($tab === 'plataforma'):
?>
    <div class="head"><h2>Plataforma</h2></div>
    <form method="post" class="card form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="platform_save">
      <input type="hidden" name="back" value="<?= $back ?>">
      <div class="grid2">
        <label>Nome da sua empresa / plataforma<input name="brand" maxlength="60" value="<?= h($platform['brand']) ?>"><small>Aparece na tela de login do painel.</small></label>
        <label>WhatsApp de suporte<input name="support_whatsapp" maxlength="40" value="<?= h($platform['support_whatsapp']) ?>" placeholder="(14) 99999-0000"><small>Aparece para clientes vencidos/suspensos e nos apps fora do ar.</small></label>
      </div>
      <label>App aberto no endereço principal (<?= h(base_url()) ?>)
        <select name="default_tenant">
          <option value="">— nenhum (mostra "nenhum aplicativo neste endereço") —</option>
          <?php foreach ($clients as $slug => $c): ?><option value="<?= h($slug) ?>" <?= $platform['default_tenant'] === $slug ? 'selected' : '' ?>><?= h($c['name']) ?> (/r/<?= h($slug) ?>/)</option><?php endforeach; ?>
        </select>
      </label>
      <div class="actions"><button class="btn primary">Salvar</button></div>
    </form>

<?php
// ======================= CONTA =======================
else:
?>
    <div class="head"><h2>Conta de acesso</h2></div>
    <form method="post" class="card form narrow">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <input type="hidden" name="back" value="<?= $back ?>">
      <?php if ($role === 'client'): ?>
        <label>Usuário<input value="<?= h($client['user']) ?>" readonly autocomplete="username"><small>Para trocar o usuário, fale com o suporte.</small></label>
      <?php else: ?>
        <label>Usuário<input name="user" value="<?= h($admin['user']) ?>" maxlength="60" autocomplete="username"></label>
      <?php endif; ?>
      <label>Senha atual<input type="password" name="current" required autocomplete="current-password"></label>
      <label>Nova senha<input type="password" name="pass" required minlength="8" autocomplete="new-password"></label>
      <label>Repita a nova senha<input type="password" name="pass2" required minlength="8" autocomplete="new-password"></label>
      <div class="actions"><button class="btn primary">Atualizar acesso</button></div>
    </form>
<?php endif; ?>
  </main>
<?php endif; ?>
<script>
  // Mostra o nome do arquivo escolhido e a prévia da imagem antes de salvar.
  document.querySelectorAll('input[type=file]').forEach((input) => input.addEventListener('change', () => {
    const f = input.files[0];
    const box = input.closest('.upload');
    if (!f || !box) return;
    let img = box.querySelector('img');
    if (!img) { img = document.createElement('img'); box.prepend(img); }
    img.src = URL.createObjectURL(f);
  }));
  document.querySelectorAll('input[type=color]').forEach((c) => c.addEventListener('input', () => { c.nextElementSibling.textContent = c.value; }));
  // ----- Avisos de pedidos novos (som + notificação do navegador) -----
  (() => {
    const tenant = <?= json_encode($tenant) ?>;
    if (!tenant) return;
    const key = 'rp-last-req:' + tenant;
    const badge = document.getElementById('reqCount');
    const baseTitle = document.title;
    let ctx = null;
    const beep = () => {
      try {
        ctx ??= new (window.AudioContext || window.webkitAudioContext)();
        [0, 0.18].forEach((delay, i) => {
          const o = ctx.createOscillator(), g = ctx.createGain();
          o.frequency.value = i ? 1046 : 784;
          g.gain.setValueAtTime(0.0001, ctx.currentTime + delay);
          g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + delay + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + delay + 0.3);
          o.connect(g).connect(ctx.destination);
          o.start(ctx.currentTime + delay); o.stop(ctx.currentTime + delay + 0.32);
        });
      } catch { /* sem áudio */ }
    };
    const btn = document.getElementById('enableNotif');
    if (btn) btn.addEventListener('click', async () => {
      beep();
      if ('Notification' in window) await Notification.requestPermission();
      btn.textContent = Notification?.permission === 'granted' ? '🔔 Avisos ativados' : '🔔 Som ativado';
    });
    let last = Number(localStorage.getItem(key) || 0);
    const poll = async () => {
      try {
        const r = await fetch('?ajax=poll', { cache: 'no-store' });
        if (!r.ok) return;
        const d = await r.json();
        if (badge) { badge.textContent = d.unread; badge.hidden = !d.unread; }
        document.title = (d.unread ? `(${d.unread}) ` : '') + baseTitle;
        if (last && d.last > last && d.latest.length) {
          beep();
          const n = d.latest[d.latest.length - 1];
          const title = (n.type === 'prayer' ? 'Pedido de oração' : 'Pedido de música') + ' de ' + n.name;
          if ('Notification' in window && Notification.permission === 'granted') {
            const nt = new Notification(title, { body: n.text, tag: 'pedido' });
            nt.onclick = () => { window.focus(); location.href = '?tab=pedidos&f=new'; };
          }
          if (location.search.includes('tab=pedidos')) setTimeout(() => location.reload(), 1200);
        }
        last = d.last;
        localStorage.setItem(key, String(last));
      } catch { /* offline */ }
    };
    poll();
    setInterval(poll, 12000);
  })();

  // ----- Chat do painel (moderação) -----
  (() => {
    const log = document.getElementById('chatLog');
    if (!log) return;
    const csrf = <?= json_encode(csrf()) ?>;
    let since = 0;
    const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const hhmm = (t) => new Date(t * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    const post = (data) => fetch('./', { method: 'POST', body: new URLSearchParams({ _csrf: csrf, ajax: '1', ...data }) }).then((r) => r.json());
    const add = (m) => {
      const el = document.createElement('div');
      el.className = 'msg ' + m.kind;
      el.dataset.id = m.id;
      el.innerHTML = m.kind === 'system'
        ? `<span class="sys">🎵 <b>${esc(m.nick)}</b>${m.city ? ' (' + esc(m.city) + ')' : ''} ${esc(m.text)}</span><time>${hhmm(m.at)}</time>`
        : `<b>${esc(m.nick)}${m.city ? ' <small>· ' + esc(m.city) + '</small>' : ''}</b><span>${esc(m.text)}</span><time>${hhmm(m.at)}</time>` +
          (m.kind === 'user' ? `<span class="msg-tools"><button type="button" class="reply" data-reply="${esc(m.nick)}">Responder</button><button type="button" data-do="chat_delete">Apagar</button><button type="button" data-do="chat_ban">Bloquear</button></span>` : '<span class="msg-tools"><button type="button" data-do="chat_delete">Apagar</button></span>');
      log.appendChild(el);
    };
    const load = async () => {
      try {
        const d = await (await fetch('?ajax=chat&since=' + since, { cache: 'no-store' })).json();
        if (since === 0) log.innerHTML = d.messages.length ? '' : '<p class="empty sm">Nenhuma mensagem ainda.</p>';
        (d.deleted || []).forEach((id) => log.querySelector(`[data-id="${id}"]`)?.remove());
        const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 60;
        if (d.messages.length) log.querySelector('.empty')?.remove();
        d.messages.forEach(add);
        since = d.last;
        if (atBottom || d.messages.length) log.scrollTop = log.scrollHeight;
        document.getElementById('chatOnline').textContent = d.online.length ? `Online agora (${d.online.length}): ` + d.online.slice(0, 8).join(', ') + (d.online.length > 8 ? '…' : '') : 'Ninguém online agora';
      } catch { /* tenta de novo */ }
    };
    log.addEventListener('click', async (e) => {
      const rb = e.target.closest('button[data-reply]');
      if (rb) {
        const t = document.getElementById('chatText');
        t.value = '@' + rb.dataset.reply + ' ';
        t.focus();
        return;
      }
      const b = e.target.closest('button[data-do]');
      if (!b) return;
      const msg = b.closest('.msg');
      if (b.dataset.do === 'chat_ban' && !confirm('Bloquear este ouvinte? As mensagens dele somem do chat.')) return;
      const r = await post({ action: b.dataset.do, id: msg.dataset.id });
      if (r.error) alert(r.error); else { msg.remove(); load(); }
    });
    document.getElementById('chatSend').addEventListener('submit', async (e) => {
      e.preventDefault();
      const t = document.getElementById('chatText');
      if (!t.value.trim()) return;
      const r = await post({ action: 'chat_send', text: t.value, nick: document.getElementById('chatNick').value });
      if (r.error) alert(r.error); else { t.value = ''; load(); }
    });
    load();
    setInterval(load, 3000);
  })();

  // Prévia do canal de TV no painel.
  document.querySelectorAll('.tv-preview').forEach((box) => {
    const { type, src } = box.dataset;
    if (type === 'youtube' || type === 'embed') {
      const f = document.createElement('iframe');
      f.src = src.replace('autoplay=1', 'autoplay=0');
      f.allow = 'autoplay; fullscreen; picture-in-picture; encrypted-media';
      f.allowFullscreen = true;
      box.appendChild(f);
      return;
    }
    const v = document.createElement('video');
    v.controls = true; v.playsInline = true; v.muted = true;
    box.appendChild(v);
    if (type === 'hls' && !v.canPlayType('application/vnd.apple.mpegurl')) {
      const sc = document.createElement('script');
      sc.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.15/dist/hls.min.js';
      sc.onload = () => { const hls = new Hls(); hls.loadSource(src); hls.attachMedia(v); };
      document.head.appendChild(sc);
    } else {
      v.src = src;
    }
  });
  const npBtn = document.getElementById('npTest');
  if (npBtn) npBtn.addEventListener('click', async () => {
    const out = document.getElementById('npResult');
    out.textContent = 'Consultando o streaming…';
    try {
      const d = await (await fetch('?ajax=np&id=' + encodeURIComponent(npBtn.dataset.id), { cache: 'no-store' })).json();
      out.innerHTML = '';
      if (d.art) { const im = document.createElement('img'); im.src = d.art; im.alt = ''; out.appendChild(im); }
      out.append(d.song ? 'Tocando agora: ' + d.song : 'O streaming não está enviando o nome da música. Ative o envio de título (metadados) no programa de automação da rádio.');
    } catch { out.textContent = 'Não foi possível consultar agora.'; }
  });
  const t = document.getElementById('testStream');
  if (t) {
    let a = null;
    t.addEventListener('click', () => {
      if (a) { a.pause(); a = null; t.textContent = '▶ Testar áudio'; return; }
      const src = t.closest('form').querySelector('[name=stream]').value || t.dataset.src;
      a = new Audio(src);
      t.textContent = 'Conectando…';
      a.addEventListener('playing', () => { t.textContent = '■ Parar (tocando)'; });
      a.play().catch(() => { t.textContent = '✕ Não tocou — verifique a URL'; a = null; });
    });
  }
</script>
</body>
</html>
