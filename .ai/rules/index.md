# Regras de IA do projeto

Estas regras complementam o `AGENTS.md` e as skills do Laravel Boost com decisões específicas deste repositório. Antes de alterar arquivos, leia todas as regras cujos caminhos se aplicam e confira também as regras por palavra-chave quando a tarefa atravessar mais de uma área.

| Caminhos | Regra |
| --- | --- |
| `app/Models/**` | [Modelos Eloquent](boost/models.md) |
| `app/Models/**`, `app/Jobs/**`, `app/Http/Controllers/Tenant*.php`, `app/Http/Requests/StoreTenant*.php`, `routes/**`, `config/tenancy.php`, `database/migrations/**`, `tests/**` | [Multitenancy](boost/multitenancy.md) |
| `database/migrations/**`, `database/seeders/**`, `app/Jobs/**`, `config/queue.php`, `docker-compose.yml`, `deploy/**` | [Migrações e filas](boost/migrations-and-queues.md) |
| `resources/views/**`, `app/Livewire/**`, `app/View/Components/**` | [Interface Livewire e Volt](boost/livewire-ui.md) |
| `tests/**`, `phpunit.xml` | [Testes](boost/testing.md) |
| `docs/**`, `app/**`, `routes/**`, `database/**`, `resources/views/**` (ao planejar feature nova ou modificação) | [Documentação](boost/documentation.md) |

Se uma mudança tocar várias áreas, aplique todas as regras correspondentes. Em caso de divergência, preserve primeiro o comportamento e os padrões já existentes; confirme a decisão no código e nos testes próximos.
