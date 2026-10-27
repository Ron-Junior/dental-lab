# Migrações, filas e execução

## Migrações e seeders

- Confirme o escopo antes de criar ou editar uma migração: `database/migrations/` é central; `database/migrations/tenant/` é por laboratório; `database/migrations/dev/` é reservado a ferramentas de desenvolvimento.
- Migrações devem ser reversíveis e conter índices e chaves estrangeiras necessários ao padrão de consultas e relações existente.
- Seeders chamados pelo provisionamento devem ser seguros para reexecução, sem duplicar dados padrão.
- O pacote obtém caminhos e opções de migração tenant de `config/tenancy.php`; preserve essa fonte de verdade em vez de duplicar caminhos em comandos ou jobs.

## Jobs e fila

- O provisionamento tenant usa a fila configurada por `QUEUE_CONNECTION`; qualquer job assíncrono precisa de um worker ativo no ambiente onde é despachado.
- O Compose local inicia o worker junto com a aplicação, e o script em `deploy/sandbox/scripts/laravel-deploy.sh` inicia o worker no deploy sandbox. Mantenha os dois ambientes alinhados ao alterar o comando, timeout ou conexão da fila.
- O timeout do worker deve ser menor que `retry_after`, com margem suficiente para impedir que um job ainda em execução seja reservado novamente. Hoje o provisionamento usa timeout de 300 segundos e a fila database usa `retry_after` de 330 segundos.
- Não passe modelos Eloquent completos para os Jobs. Dê preferência a passar identificadores primitivos (IDs) ou DTOs/objetos pequenos, consultando o modelo no início do `handle()`. Isso previne payload pesado na fila, problemas de serialização e inconsistências de estado entre conexões/schemas.
- Preserve estado explícito de sucesso e falha nos jobs de provisionamento; não silencie exceções nem apresente uma conclusão bem-sucedida quando a execução falhar.

## Verificação

- Ao tocar em comandos de inicialização, workers ou configuração Compose, valide `docker compose config` e a sintaxe de scripts shell alterados.
- Não encerre nem recrie containers que não foram iniciados para esta tarefa. Se um ambiente necessário não estiver disponível, reporte a limitação em vez de alterar serviços ou dados alheios.
