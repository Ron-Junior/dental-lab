# Regras de multitenancy

## Limites de dados

- Este SaaS usa `stancl/tenancy` com schema PostgreSQL dedicado por laboratório. Não introduza tenancy por coluna nem consultas que misturem dados centrais e operacionais.
- Dados globais, como tenants, domínios, sessões centrais e filas, pertencem à conexão central. Dados operacionais do laboratório pertencem ao contexto tenant.
- Mantenha as migrações operacionais em `database/migrations/tenant/`. Migrações em `database/migrations/` alteram somente o banco central.
- Use o modelo e as abstrações já configurados em `config/tenancy.php`; não monte nomes de schema nem `search_path` manualmente.

## Rotas e contexto

- Cadastre landing page, cadastro e acompanhamento de onboarding em `routes/web.php`; cadastre autenticação e funcionalidades do laboratório em `routes/tenant.php`.
- Rotas tenant devem inicializar o contexto via middleware do pacote antes de acessar modelos operacionais. Não confie somente no slug enviado pelo cliente para determinar um tenant.
- Use `route()` e nomes de rota para gerar URLs. O domínio central vem de `CENTRAL_DOMAIN`; nunca hardcode hostnames de produção, sandbox ou laboratórios.
- Não faça joins entre conexão central e tenant. Para uma operação central sobre um tenant específico, use os mecanismos do pacote para executar no contexto desse tenant e mantenha explícito o limite da operação.

## Onboarding

- O cadastro central deve validar o slug e persistir o estado inicial do tenant antes de enfileirar o provisionamento.
- Provisionamento permanece assíncrono: criar o banco/schema, executar migrações e seeders tenant, criar o administrador, marcar o tenant como ativo e notificar o responsável.
- Mantenha o job idempotente e trate falha terminal atualizando o estado do tenant. Não mova provisionamento para a requisição HTTP nem marque como ativo antes de concluir as etapas necessárias.
- Segredos do responsável usados temporariamente no provisionamento devem permanecer protegidos e ser removidos assim que deixarem de ser necessários.

## Compatibilidade e testes

- PostgreSQL é o ambiente principal e usa schemas; SQLite é usado nos testes e possui comportamento de isolamento diferente. Não trate um teste SQLite como prova de comportamento específico do PostgreSQL.
- Preserve a fixture multi-tenant de `tests/TestCase.php`. Testes que exercitam apenas o banco central devem declarar `protected bool $usesTenantDatabase = false;`.
- Ao alterar rotas ou persistência tenant, verifique os testes relevantes de onboarding, inicialização do tenant e isolamento entre tenants.
