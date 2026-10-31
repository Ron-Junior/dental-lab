# Especificação: Edição de Serviços, Integridade de Demandas Históricas e Resiliência Monetária

- **Identificador:** `2026-10-service-edition-pricing-and-demand-integrity`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-31
- **Módulos Afetados:** Catálogo de Serviços (`resources/views/components/service/store.blade.php`, `Service`, `ServiceStep`, `ServiceServiceStep`, `ServicePolicy`), Ordens de Serviço (`resources/views/components/service/update.blade.php`, `resources/views/components/service/requesting.blade.php`, `resources/views/livewire/pages/services/planned.blade.php`, `DentistRequest`, `RequestService`), Terceirização & Parceiros (`PartnerDemand`), Migrations Tenant (`database/migrations/tenant/2026_09_21_133414_create_dentist_services_table.php`, `database/migrations/tenant/2026_09_27_140710_create_partner_demands_table.php`), Utilitários Globais (`CurrencyService`, `app/Helpers/currency.php`).

---

## 1. Contexto & Motivação

Durante a auditoria operacional do módulo de serviços do Dental Lab, foram identificadas falhas críticas em cascata que afetam desde o cadastro básico no catálogo até a integridade financeira de demandas executadas por técnicos protéticos terceirizados:

1. **Atualização Falha no Catálogo:** No componente de cadastro/edição de serviços (`store.blade.php`), a ação de salvar um serviço existente busca o registro no banco via `Service::whereId($this->serviceId)->first()`, mas omite a chamada a `->update(...)`. Como resultado, alterações de nome, descrição e preço nunca são gravadas no banco; apenas o `sync()` de etapas é executado.
2. **Destruição em Cascata do Histórico de Parceiros:** A tabela pivô `service_service_step` possui relação 1:N com `partner_demands` configurada com `cascadeOnDelete()`. Se o laboratório reordenar ou remover uma etapa de um serviço no catálogo, o `sync()` deleta o registro pivô, disparando a exclusão física imediata de todas as `PartnerDemand` históricas associadas. Isso destrói comprovantes, fotos de resultado, registros de auditoria e cálculos passados de comissões de técnicos parceiros.
3. **Inconsistência na Troca de Serviço Solicitado:** Ao editar uma solicitação existente em `update.blade.php`, é permitido alterar o `service_id`. Contudo, as demandas de parceiros (`PartnerDemand`) são instanciadas exclusivamente no evento `static::created` de `RequestService`. A alteração do serviço mantém as demandas da configuração antiga. Além disso, a view `planned.blade.php` condiciona a exibição do botão "Editar" a uma coluna inexistente no banco (`$requestService->step_id`), permitindo edição mesmo após demandas já terem sido iniciadas ou finalizadas por protéticos.
4. **Corrupção de Valores Monetários Formatados:** A conversão de inputs de moeda utiliza encadeamentos como `str($price)->replaceFirst('.', '')->replaceLast(',', '.')->toFloat()`. Para valores a partir de R$ 1.000.000,00 (que contêm múltiplos pontos de milhar, ex.: `1.500.000,00`), apenas o primeiro ponto é suprimido, resultando em strings inválidas (`1500.000.00`) que truncam ou corrompem o valor numérico. Ademais, essa lógica não utiliza a infraestrutura centralizada de `CurrencyService`.
5. **Estouro de Capacidade da Coluna `quantity`:** Na tabela `request_services`, a coluna `quantity` foi definida como `tinyInteger` (no PostgreSQL mapeado para `smallint`, com limite de 32.767; em MySQL/SQLite limitado a 127/255). A validação Livewire nos componentes permite valores até `1.000.000`, disparando exceções de banco de dados (`Numeric value out of range`) em pedidos de alta tiragem (ex.: alinhadores, bráquetes ou parafusos protéticos em lote).

---

## 2. Objetivos

- Garantir que a edição de serviços no catálogo persista com precisão `name`, `description` e `price`.
- Proteger o histórico operacional e financeiro de `partner_demands`, impedindo a deleção de registros pivô que possuam execuções associadas e eliminando o `cascadeOnDelete` perigoso.
- Assegurar que a alteração de um item de solicitação (`RequestService`) só seja permitida enquanto nenhuma demanda tiver sido iniciada por parceiros, sincronizando corretamente as novas etapas.
- Corrigir a view de listagem para validar o início real de etapas em vez de checar coluna inexistente (`step_id`).
- Centralizar a sanitização de strings monetárias no `CurrencyService`, garantindo robustez para qualquer magnitude de valor financeiro sem causar dupla multiplicação em mutators Eloquent.
- Adequar o tipo da coluna `quantity` na tabela `request_services` para suportar grandes volumes sem estouro de limite.

---

## 3. Regras de Negócio & Arquitetura de Solução

### 3.1. Persistência de Serviços no Catálogo (`resources/views/components/service/store.blade.php` & `ServicePolicy`)

- **Autorização:**
  - Adicionar método `update(User $user, Service $service): bool` em `ServicePolicy` (permitindo `Rules::Owner`, `Rules::Lab` e `Rules::LabManager`).
- **Operação de Salvar:**
  - Se `$this->serviceId` estiver preenchido:
    - Buscar o serviço com `Service::findOrFail($this->serviceId)` e autorizar via `$this->authorize('update', $service)`.
    - Executar `$service->update([...])` atualizando explicitamente `name`, `description` e `price` (convertido via `currency_to_float($this->price)`).
  - Se `$this->serviceId` for nulo:
    - Autorizar via `$this->authorize('create', Service::class)`.
    - Executar `Service::create([...])`.
- **Transação:** A atualização dos atributos cadastrais e a sincronização das etapas via `serviceSteps()->sync(...)` devem ocorrer dentro de `DB::transaction(...)`.

### 3.2. Proteção de Integridade e Histórico de Etapas (`PartnerDemand`)

- **Alteração de Foreign Key no Banco (Migration Tenant):**
  - Modificar a constraint da tabela `partner_demands`:
    - De: `foreignId('service_service_step_id')->constrained('service_service_step')->cascadeOnDelete()`
    - Para: `foreignId('service_service_step_id')->constrained('service_service_step')->restrictOnDelete()`
- **Validação de Negócio no Catálogo:**
  - Antes de executar `sync($syncData)` no serviço:
    - Identificar os IDs de `service_service_step` que seriam desanexados (removidos ou substituídos).
    - Verificar se qualquer um desses pivôs possui registros vinculados em `partner_demands`.
    - Caso possua, abortar a operação e exibir mensagem amigável ao usuário (ex.: *"Não é possível remover a etapa '{nome}', pois ela já possui histórico de demandas executadas em ordens de serviço. Desative-a ou mantenha a etapa no serviço"*).

### 3.3. Edição e Troca de Serviço Solicitado (`RequestService` & `planned.blade.php`)

- **Condição de Bloqueio na View (`planned.blade.php`):**
  - Remover a verificação da coluna inexistente `$requestService->step_id`.
  - O botão "Editar" deve ser desabilitado quando:
    - O serviço já estiver concluído (`completed_at !== null`), OU
    - O serviço possuir qualquer demanda já iniciada ou finalizada por parceiro (`partnerDemands()->whereNotNull('started_at')->exists()`).
  - Carregar preventivamente a contagem de demandas iniciadas via `withCount` no paginator para evitar query N+1:
    ```php
    'requestServices' => fn ($query) => $query->withCount([
        'partnerDemands as demands_completed' => fn ($q) => $q->whereNotNull('ended_at'),
        'partnerDemands as demands_started' => fn ($q) => $q->whereNotNull('started_at'),
    ]),
    ```
- **Sincronização em `update.blade.php`:**
  - Se o usuário alterar o `service_id`:
    - Validar no backend se `requestService->partnerDemands()->whereNotNull('started_at')->exists()`. Se existir, lançar `ValidationException`.
    - Em caso afirmativo (nenhuma etapa iniciada):
      - Excluir as `PartnerDemand` pendentes anteriores do item.
      - Carregar as etapas do novo serviço (`$newService->serviceSteps`).
      - Gerar as novas instâncias de `PartnerDemand` correspondentes ao novo serviço.
  - Atualizar `unit_price`, `quantity` e `service_id` atomicamente.

### 3.4. Resiliência na Conversão Monetária (`CurrencyService`)

- **Nova Função Utilitária no `CurrencyService`:**
  - Implementar método para converter string de moeda formatada (com `R$`, espaços, pontos e vírgulas) em `float` limpo:
    ```php
    public function toFloat(float|string|null $value): float
    {
        if (blank($value)) {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        // Remove tudo que não for dígito numérico ou vírgula decimal
        $clean = preg_replace('/[^\d,]/', '', (string) $value);
        $clean = str_replace(',', '.', $clean);

        return (float) $clean;
    }
    ```
  - Expor helper global `currency_to_float($value)` em `app/Helpers/currency.php`.
- **Compatibilidade com Mutators:**
  - Como `Service` e `RequestService` possuem mutators que multiplicam por 100 (`set: fn ($val) => (int) round($val * 100)`), o valor passado no create/update deve ser o float em reais (`currency_to_float(...)`), evitando o erro de passar centavos inteiros que seriam multiplicados novamente por 100.
- **Substituição dos Componentes:**
  - Substituir `str(...)->replaceFirst(...)->replaceLast(...)` em `store.blade.php`, `update.blade.php` e `requesting.blade.php` pelo método centralizado.

### 3.5. Correção do Tipo da Coluna `quantity`

- **Nova Migration Tenant:**
  - Alterar `request_services.quantity` de `tinyInteger` para `unsignedInteger`:
    ```php
    Schema::table('request_services', function (Blueprint $table) {
        $table->unsignedInteger('quantity')->default(1)->change();
    });
    ```
  - Isso garante alinhamento total com a regra de validação `max:1000000` em todos os drivers de banco de dados (PostgreSQL, MySQL, SQLite).

---

## 4. Requisitos de Interface (UI / UX)

- **Feedback na Edição de Serviço (`store.blade.php`):**
  - O modal de edição deve refletir os dados reais após o salvamento, com toast de confirmação informando "Serviço atualizado com sucesso!".
  - Caso o usuário tente remover uma etapa com histórico de demandas, exibir alerta visual impedindo a exclusão sem fechar o modal inesperadamente.
- **Status do Botão Editar em `planned.blade.php`:**
  - O botão "Editar" deve exibir tooltip explicativo quando desabilitado por demandas em andamento: *"Não é possível editar este serviço pois as etapas já foram iniciadas pelos técnicos parceiros"*.

---

## 5. Critérios de Aceite & Matriz de Testes

1. **Edição do Catálogo de Serviços:**
   - [x] Alterar nome, descrição e preço de um serviço existente atualiza os registros no banco de dados.
   - [x] Tentar remover uma etapa de serviço vinculada a demandas existentes é rejeitado por regra de negócio / restrição de integridade referencial.
2. **Edição de Solicitação de Serviço (`RequestService`):**
   - [x] Itens com demandas já iniciadas (`started_at !== null`) não podem ter seu serviço trocado nem ser editados.
   - [x] Itens sem demandas iniciadas permitem a troca de serviço, removendo as demandas antigas e instanciando as demandas do novo serviço.
3. **Conversão de Valores Monetários:**
   - [x] Valores formatados como `R$ 999,99`, `R$ 1.500,00` e `R$ 1.250.000,50` são convertidos e armazenados com exatidão em centavos no banco de dados.
4. **Volume / Quantidade:**
   - [x] Criação e edição de solicitações com `quantity` acima de 32.767 (ex.: 50.000 ou 100.000) são persistidas com sucesso no PostgreSQL sem exceções de range.

---

## 6. Documentação Relacionada

- [Domínio: Pedidos & Ordens de Serviço (Orders)](../../domain/orders.md)
- [Convenções de Dados Globais](../../architecture/data-conventions.md)


