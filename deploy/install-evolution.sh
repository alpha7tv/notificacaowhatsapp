#!/usr/bin/env bash
# =============================================================================
#  Fiber Link Notificações — instala a Evolution API (WhatsApp) NESTA VPS
#
#  Uso:  sudo ./deploy/install-evolution.sh [--instance fiberlink] [--image IMAGEM]
#
#  - Docker (pacotes do Ubuntu) + PostgreSQL próprio da Evolution, em /opt/evolution
#  - Escuta SOMENTE em 127.0.0.1:8080 (não fica exposta na internet)
#  - Gera a API key automaticamente, cria a instância e já configura o sistema
#    (Integrações > WhatsApp). Depois basta abrir o painel em
#    Integrações > WhatsApp > "Conectar WhatsApp" e escanear o QR Code.
#  Pode ser executado novamente com segurança (chaves existentes são mantidas).
# =============================================================================
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy/lib.sh
source "${DEPLOY_DIR}/lib.sh"

INSTANCE="fiberlink"
IMAGE="${EVOLUTION_IMAGE:-evoapicloud/evolution-api:latest}"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --instance) INSTANCE="${2:-}"; shift ;;
    --image) IMAGE="${2:-}"; shift ;;
    -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
    *) die "Opção desconhecida: $1" ;;
  esac
  shift
done
[[ "$INSTANCE" =~ ^[a-zA-Z0-9_-]{2,40}$ ]] || die "Nome de instância inválido."

require_root
setup_logging evolution
enable_error_trap
acquire_lock evolution
[[ -f "$ENV_FILE" ]] || die "Instale o sistema primeiro (deploy/install.sh)."

EVO_DIR=/opt/evolution
EVO_ENV="${EVO_DIR}/.env"
EVO_URL="http://127.0.0.1:8080"

step "Instalando Docker (pacotes do Ubuntu)"
apt-get -y -q -o DPkg::Lock::Timeout=600 install docker.io docker-compose-v2 >/dev/null
systemctl enable --now docker >/dev/null 2>&1
ok "$(docker --version)"

step "Configurando Evolution API em ${EVO_DIR}"
install -d -m 700 "$EVO_DIR"
evo_get() { [[ -f "$EVO_ENV" ]] && grep -E "^$1=" "$EVO_ENV" | tail -n1 | cut -d= -f2- || true; }
API_KEY=$(evo_get AUTHENTICATION_API_KEY); API_KEY="${API_KEY:-$(gen_hex 24)}"
PG_PASS=$(evo_get POSTGRES_PASSWORD); PG_PASS="${PG_PASS:-$(gen_hex 16)}"
API_DOMAIN_NOW="$API_DOMAIN"

cat >"$EVO_ENV" <<EOF
# Gerado por deploy/install-evolution.sh — não versionar
POSTGRES_PASSWORD=${PG_PASS}
SERVER_TYPE=http
SERVER_PORT=8080
SERVER_URL=${EVO_URL}
AUTHENTICATION_API_KEY=${API_KEY}
AUTHENTICATION_EXPOSE_IN_FETCH_INSTANCES=false
DATABASE_ENABLED=true
DATABASE_PROVIDER=postgresql
DATABASE_CONNECTION_URI=postgresql://evolution:${PG_PASS}@evolution-postgres:5432/evolution?schema=public
DATABASE_CONNECTION_CLIENT_NAME=evolution_fiberlink
DATABASE_SAVE_DATA_INSTANCE=true
DATABASE_SAVE_DATA_NEW_MESSAGE=false
DATABASE_SAVE_MESSAGE_UPDATE=false
DATABASE_SAVE_DATA_CONTACTS=false
DATABASE_SAVE_DATA_CHATS=false
DATABASE_SAVE_DATA_LABELS=false
DATABASE_SAVE_DATA_HISTORIC=false
CACHE_REDIS_ENABLED=false
CACHE_LOCAL_ENABLED=true
CONFIG_SESSION_PHONE_CLIENT=FiberLink
CONFIG_SESSION_PHONE_NAME=Chrome
QRCODE_LIMIT=30
DEL_INSTANCE=false
WEBHOOK_GLOBAL_ENABLED=false
LANGUAGE=pt-BR
LOG_LEVEL=ERROR,WARN
LOG_BAILEYS=error
TELEMETRY_ENABLED=false
EOF
chmod 600 "$EVO_ENV"

cat >"${EVO_DIR}/docker-compose.yml" <<EOF
# Gerado por deploy/install-evolution.sh
services:
  evolution-api:
    image: ${IMAGE}
    container_name: evolution-api
    restart: always
    env_file: .env
    ports:
      - "127.0.0.1:8080:8080"
    extra_hosts:
      # Webhooks da Evolution chegam ao Nginx desta própria VPS
      - "${API_DOMAIN_NOW}:host-gateway"
    volumes:
      - evolution_instances:/evolution/instances
    depends_on:
      - evolution-postgres
  evolution-postgres:
    image: postgres:16-alpine
    container_name: evolution-postgres
    restart: always
    environment:
      POSTGRES_USER: evolution
      POSTGRES_PASSWORD: \${POSTGRES_PASSWORD}
      POSTGRES_DB: evolution
    volumes:
      - evolution_pgdata:/var/lib/postgresql/data
volumes:
  evolution_instances:
  evolution_pgdata:
EOF
chmod 600 "${EVO_DIR}/docker-compose.yml"

step "Baixando imagens e iniciando containers"
(cd "$EVO_DIR" && docker compose pull -q && docker compose up -d)
for _ in $(seq 1 60); do
  code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$EVO_URL/" || true)
  [[ "$code" == "200" ]] && break
  sleep 3
done
[[ "$code" == "200" ]] || { (cd "$EVO_DIR" && docker compose logs --tail 40 evolution-api); die "Evolution API não respondeu em ${EVO_URL}"; }
ok "Evolution API respondendo em ${EVO_URL} (somente localhost)"

step "Criando instância '${INSTANCE}'"
exists=$(curl -s --max-time 10 -H "apikey: ${API_KEY}" "${EVO_URL}/instance/fetchInstances?instanceName=${INSTANCE}" || true)
if printf '%s' "$exists" | grep -q "\"${INSTANCE}\""; then
  ok "Instância já existe"
else
  resp=$(curl -s --max-time 20 -X POST -H "apikey: ${API_KEY}" -H 'Content-Type: application/json' \
    -d "{\"instanceName\":\"${INSTANCE}\",\"integration\":\"WHATSAPP-BAILEYS\",\"qrcode\":false}" "${EVO_URL}/instance/create")
  printf '%s' "$resp" | grep -q "\"${INSTANCE}\"" || die "Falha ao criar instância: $(printf '%s' "$resp" | head -c 300)"
  ok "Instância criada"
fi

step "Configurando o sistema para usar esta Evolution API"
EVOLUTION_APIKEY="$API_KEY" console evolution:configure --url="$EVO_URL" --instance="$INSTANCE" --version=v2
ok "Integrações > WhatsApp preenchido automaticamente"

cat >/etc/logrotate.d/fiberlink-evolution <<'EOF'
/var/lib/docker/containers/*/*-json.log {
    daily
    rotate 7
    compress
    missingok
    copytruncate
}
EOF
chmod 644 /etc/logrotate.d/fiberlink-evolution
app_log info "Evolution API instalada/atualizada (instância ${INSTANCE})"

title "Evolution API pronta"
cat <<EOF

  Agora conecte o número da empresa:
    Painel > Integrações > WhatsApp > "Conectar WhatsApp"
    (escaneie o QR Code com o WhatsApp do celular: Aparelhos conectados > Conectar aparelho)

  Comandos úteis:
    cd ${EVO_DIR} && docker compose ps          situação dos containers
    cd ${EVO_DIR} && docker compose logs -f     logs da Evolution
    cd ${EVO_DIR} && docker compose pull && docker compose up -d   atualizar a Evolution
EOF
