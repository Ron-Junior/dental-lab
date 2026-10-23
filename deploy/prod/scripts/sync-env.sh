#!/bin/bash
set -e

COMPOSE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$(dirname "$COMPOSE_DIR")")"
ENV_FILE="$PROJECT_DIR/.env"

echo "=================================================="
echo "Sincronizando variáveis de ambiente do Bitwarden..."
echo "=================================================="

if [ -z "$BW_SESSION" ]; then
  echo "Erro: A variável BW_SESSION não está definida."
  echo "Por favor, rode 'export BW_SESSION=\"\$(bw unlock --raw)\"' antes de executar este script."
  exit 1
fi

echo "Baixando segredos para $ENV_FILE..."
bw get item "protho-prod-env" | jq -r '.notes' > "$ENV_FILE"

chmod 600 "$ENV_FILE"

echo "=================================================="
echo "Arquivo .env atualizado e protegido com sucesso!"
echo "Dica: Para aplicar as novas variáveis, rode o deploy ou reinicie o container:"
echo "cd $COMPOSE_DIR && docker compose up -d --force-recreate"
echo "=================================================="