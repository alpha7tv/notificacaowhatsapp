#!/usr/bin/env bash
# =============================================================================
#  Pacotes do sistema exigidos por ESTA versão da aplicação.
#  Executado pelo deploy.sh a partir da release NOVA (antes de ativá-la), para que
#  dependências novas sejam instaladas já no primeiro deploy da versão que as exige.
# =============================================================================
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l
REQUIRED=(qrencode)
missing=()
for pkg in "${REQUIRED[@]}"; do
  dpkg -s "$pkg" >/dev/null 2>&1 || missing+=("$pkg")
done
if (( ${#missing[@]} )); then
  echo "  Instalando pacotes exigidos pela nova versão: ${missing[*]}"
  apt-get -y -q -o DPkg::Lock::Timeout=600 install "${missing[@]}" >/dev/null
fi
