# Regras de testes

- O projeto usa PHPUnit. Leia testes vizinhos e a skill `testing-best-practices` antes de adicionar cobertura.
- Prefira testes de feature para comportamento acessível por HTTP ou Livewire e teste os resultados observáveis, incluindo persistência, resposta e efeitos colaterais relevantes.
- A classe base `Tests\TestCase` cria contexto e banco tenant por padrão. Para testes que devem permanecer exclusivamente centrais, defina `protected bool $usesTenantDatabase = false;`; não contorne a fixture nos demais testes.
- Use factories existentes e fakes do Laravel para filas, notificações, storage, eventos e outros efeitos externos quando apropriado. Não faça chamadas de rede reais nos testes.
- Rode primeiro os arquivos de teste relacionados; depois, se necessário, amplie para a suíte. No ambiente local do projeto, prefira `docker exec -t laravel_dev php artisan test --compact <caminho-do-teste>` quando o container estiver ativo.
- Se os testes falharem, diferencie falhas introduzidas pela mudança de falhas preexistentes. Não apague testes nem enfraqueça assertions para obter uma suíte verde.
- Mudanças somente de documentação não precisam de testes. Mudanças PHP devem ser formatadas com Laravel Pint conforme o `AGENTS.md`.
