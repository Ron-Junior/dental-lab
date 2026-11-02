# Especificação: Prevenção de Injeção SQL e Uso Estrito de Parameter Bindings em Consultas Raw (OWASP A03:2021)

- **Identificador:** `2026-11-sql-injection-prevention-and-parameter-bindings`
- **Status:** Concluído / Implementado
- **Data de Conclusão:** 2026-11-02
- **Data:** Novembro de 2026
- **Módulos Afetados:**
  - Componente Livewire de Relatórios Gerenciais (`resources/views/livewire/pages/reports/index.blade.php`)
  - Ação de Movimentação de Estoque (`app/Actions/StockMovement.php`)
  - Listagem Central de Laboratórios (`resources/views/livewire/pages/central/laboratories/index.blade.php`)
  - Testes Automatizados de Relatórios (`tests/Feature/ReportsTest.php`)

---

## 1. Contexto & Motivação

Na auditoria de segurança da informação baseada no **OWASP Top 10:2021 – A03: Injection (Injeção)**, foram inspecionadas todas as ocorrências de métodos raw (`whereRaw`, `orderByRaw`, `selectRaw`, `DB::raw`) no codebase da aplicação.

### 1.1. Consultas em Conformidade (Boas Práticas Validadas)

As seguintes consultas executam expressões SQL brutas mantendo estrita conformidade com o padrão seguro de *parameter bindings* posicionais (`?`):

1. **Priorização de Lotes em `app/Actions/StockMovement.php` (linhas 194 e 196):**
   ```php
   if (! empty($data['stockBatchId'])) {
       $batchQuery->orderByRaw('id = ? DESC', [$data['stockBatchId']]);
   } elseif (! empty($data['batchNumber'])) {
       $batchQuery->orderByRaw('batch_number = ? DESC', [trim($data['batchNumber'])]);
   }
   ```
   - O identificador ou código do lote informado pelo operador é tratado via *parameter binding*, impedindo que strings manipuladas interfiram na cláusula `ORDER BY`.

2. **Busca Case-Insensitive de Tenants em `resources/views/livewire/pages/central/laboratories/index.blade.php` (linha 164):**
   ```php
   $query->whereRaw('LOWER(name) LIKE ?', ['%'.$tenantName.'%'])
   ```
   - O termo de busca sanitizado é repassado via array de bindings, prevenindo que caracteres especiais ou fragmentos de consulta executem injeção SQL no schema central PostgreSQL.

### 1.2. Ponto de Melhoria Identificado: Interpolação de Enum em `reports/index.blade.php`

No componente Livewire Volt de relatórios (`resources/views/livewire/pages/reports/index.blade.php`), o método privado `partnerCommissionExpression()` (linhas 401–410) constrói a expressão condicional `CASE ... WHEN` utilizada para calcular o valor em centavos das comissões de parceiros terceirizados (`partner_demands`):

```php
private function partnerCommissionExpression(): string
{
    return "CASE
        WHEN partner_demands.partner_commission_type = '".CommissionTypes::Percentage->value."'
            THEN ROUND(partner_demands.partner_commission * request_services.unit_price * request_services.quantity / 100.0)
        WHEN partner_demands.partner_commission_type = '".CommissionTypes::Fixed->value."'
            THEN ROUND(partner_demands.partner_commission * 100)
        ELSE NULL
    END";
}
```

Essa expressão é consumida em múltiplas operações analíticas do relatório:
- `orders()`: via `$this->ordersQuery()->withSum(['partnerDemands as ...' => ...], DB::raw($this->partnerCommissionExpression()))` (linha 101);
- `profitabilitySummary()`: via `$completedPartnerDemands()->sum(DB::raw($this->partnerCommissionExpression()))` (linha 135);
- `partnerCommissionSummary()`: via `$completedDemands()->sum(DB::raw($this->partnerCommissionExpression()))` (linha 171);
- `partnerCommissionsByPartner()`: via `->selectRaw('SUM('.$this->partnerCommissionExpression().') AS commission_cents')` (linha 187);
- `partnerCommissionDetails()`: via `->selectRaw($this->partnerCommissionExpression().' AS commission_cents')` (linha 198).

**Análise de Risco:**
- Atualmente, essa interpolação **não é vulnerável** nem explorável por agentes externos, pois os valores interpolados provêm exclusivamente de constantes internas do Enum tipado em PHP (`CommissionTypes::Percentage->value = 'percentage'` e `CommissionTypes::Fixed->value = 'fixed'`).
- Contudo, a concatenação direta de strings dentro de fragmentos SQL viola as diretrizes de segurança de código defensivo, abre precedentes para práticas inseguras em manutenções futuras e contraria o princípio de *defesa em profundidade*.
- Recomenda-se parametrizar os valores utilizando placeholders (`?`) e vinculá-los explicitamente através de bindings do Query Builder do Laravel/PostgreSQL.

---

## 2. Objetivos

1. **Eliminar a concatenação manual de strings SQL em `reports/index.blade.php`:**
   - Substituir os literais de string `'".CommissionTypes::...->value."'` em `partnerCommissionExpression()` por placeholders posicionais `?`.
2. **Fornecer bindings explícitos em todas as chamadas analíticas e de agregação:**
   - Criar o método auxiliar `partnerCommissionBindings(): array` para centralizar os valores `[CommissionTypes::Percentage->value, CommissionTypes::Fixed->value]`.
   - Propagar os bindings em `selectRaw(..., $this->partnerCommissionBindings())`, em subconsultas de relação (`withSum` com `$query->addBinding($this->partnerCommissionBindings(), 'select')`) e em cálculos agregados de soma (`sum()`).
3. **Formalizar as diretrizes arquiteturais do projeto contra SQL Injection:**
   - Atualizar a documentação de arquitetura (`docs/architecture/tech-stack.md`) e regras de IA (`.ai/rules/boost/models.md` e `.ai/rules/boost/livewire-ui.md`) com a proibição terminante de interpolações de strings em métodos raw, tornando o uso de *parameter bindings* uma regra obrigatória em todo o repositório.
4. **Garantir cobertura total e integridade por testes automatizados (PHPUnit):**
   - Assegurar que `tests/Feature/ReportsTest.php` continue validando com precisão os cálculos de CMV, faturamento, comissões percentuais, comissões fixas e agrupamentos por parceiro, comprovando ausência de regressões na execução de queries PostgreSQL.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Proibição Estrita de Interpolação de Strings em Métodos Raw (Bindings Obrigatórios)

1. **Obrigatoriedade de Parameter Bindings (`?`):**
   - É expressamente vedado interpolar variáveis, inputs de requisições, atributos de modelos ou valores de Enums (ex.: `"... = '{$enum->value}'"`) diretamente em strings SQL passadas para:
     - `whereRaw(...)`
     - `orderByRaw(...)`
     - `selectRaw(...)`
     - `havingRaw(...)`
     - `groupByRaw(...)`
     - `DB::raw(...)`
2. **Passagem pelo Array de Bindings do Query Builder:**
   - Todos os valores dinâmicos ou condicionais devem ser representados pelo placeholder posicional `?` na expressão SQL e fornecidos no array associado de bindings aceito pelos métodos correspondentes do Eloquent / Query Builder.

### 3.2. RN-02: Expressão Parametrizada de Comissão de Parceiros (`reports/index.blade.php`)

1. **Assinatura da Expressão Parametrizada:**
   - A expressão SQL `partnerCommissionExpression()` deve conter exclusivamente placeholders posicionais `?` nos predicados de tipo de comissão:
     ```sql
     CASE
         WHEN partner_demands.partner_commission_type = ?
             THEN ROUND(partner_demands.partner_commission * request_services.unit_price * request_services.quantity / 100.0)
         WHEN partner_demands.partner_commission_type = ?
             THEN ROUND(partner_demands.partner_commission * 100)
         ELSE NULL
     END
     ```
2. **Mapeamento Centralizado de Bindings (`partnerCommissionBindings`):**
   - O array ordenado de bindings correspondente aos placeholders deve ser provido por:
     ```php
     private function partnerCommissionBindings(): array
     {
         return [
             CommissionTypes::Percentage->value,
             CommissionTypes::Fixed->value,
         ];
     }
     ```
3. **Consistência nos Métodos de Consulta:**
   - **`partnerCommissionDetails()`:** Utilizar `->selectRaw($this->partnerCommissionExpression().' AS commission_cents', $this->partnerCommissionBindings())`.
   - **`partnerCommissionsByPartner()`:** Utilizar `->selectRaw('SUM('.$this->partnerCommissionExpression().') AS commission_cents', $this->partnerCommissionBindings())`.
   - **`orders()` (agregação `withSum`):** Ao montar a subconsulta de `partnerDemands`, anexar os bindings de projeção via `$query->addBinding($this->partnerCommissionBindings(), 'select')` antes de invocar `DB::raw($this->partnerCommissionExpression())`.
   - **`profitabilitySummary()` e `partnerCommissionSummary()`:** Executar o cálculo de soma agregada de comissões garantindo a vinculação dos parâmetros de `select` no Query Builder PostgreSQL.

---

## 4. Detalhamento Técnico

### 4.1. Refatoração em `resources/views/livewire/pages/reports/index.blade.php`

#### 4.1.1. Métodos `partnerCommissionExpression()` e `partnerCommissionBindings()`

```php
private function partnerCommissionExpression(): string
{
    return "CASE
        WHEN partner_demands.partner_commission_type = ?
            THEN ROUND(partner_demands.partner_commission * request_services.unit_price * request_services.quantity / 100.0)
        WHEN partner_demands.partner_commission_type = ?
            THEN ROUND(partner_demands.partner_commission * 100)
        ELSE NULL
    END";
}

/**
 * @return array<int, string>
 */
private function partnerCommissionBindings(): array
{
    return [
        CommissionTypes::Percentage->value,
        CommissionTypes::Fixed->value,
    ];
}
```

#### 4.1.2. Aplicação em `orders()`

```php
#[Computed]
public function orders(): LengthAwarePaginator
{
    return $this->ordersQuery()
        ->with('dentist.user')
        ->withSum('requestServices as revenue_cents', DB::raw('unit_price * quantity'))
        ->withSum(
            ['stockMovements as cmv_cents' => fn (Builder $query) => $this->serviceConsumptionMovements($query)],
            DB::raw('quantity * unit_cost')
        )
        ->withSum(
            ['partnerDemands as partner_commissions_cents' => fn (Builder $query) => $this->completedPartnerDemands($query)
                ->addBinding($this->partnerCommissionBindings(), 'select')],
            DB::raw($this->partnerCommissionExpression())
        )
        // ...
        ->latest('created_at')
        ->latest('id')
        ->paginate(15);
}
```

#### 4.1.3. Aplicação em `profitabilitySummary()` e `partnerCommissionSummary()`

```php
#[Computed]
public function profitabilitySummary(): array
{
    // ...
    $completedPartnerDemands = fn (): Builder => $this->completedPartnerDemandsQuery();

    return [
        // ...
        'partner_commissions_cents' => (int) $completedPartnerDemands()
            ->addBinding($this->partnerCommissionBindings(), 'select')
            ->sum(DB::raw($this->partnerCommissionExpression())),
        // ...
    ];
}

#[Computed]
public function partnerCommissionSummary(): array
{
    $completedDemands = fn (): Builder => $this->completedPartnerDemandsQuery();

    return [
        'commission_cents' => (int) $completedDemands()
            ->addBinding($this->partnerCommissionBindings(), 'select')
            ->sum(DB::raw($this->partnerCommissionExpression())),
        'completed_demands_count' => $completedDemands()->count(),
        // ...
    ];
}
```

#### 4.1.4. Aplicação em `partnerCommissionsByPartner()` e `partnerCommissionDetails()`

```php
#[Computed]
public function partnerCommissionsByPartner(): Collection
{
    return $this->completedPartnerDemandsQuery()
        ->join('partners', 'partner_demands.partner_id', '=', 'partners.id')
        ->join('users', 'partners.user_id', '=', 'users.id')
        ->select('partners.id as partner_id', 'users.name as partner_name')
        ->selectRaw('COUNT(*) AS completed_demands_count')
        ->selectRaw('SUM('.$this->partnerCommissionExpression().') AS commission_cents', $this->partnerCommissionBindings())
        ->groupBy('partners.id', 'users.name')
        ->orderBy('users.name')
        ->get();
}

#[Computed]
public function partnerCommissionDetails(): LengthAwarePaginator
{
    return $this->completedPartnerDemandsQuery()
        ->select('partner_demands.*')
        ->selectRaw($this->partnerCommissionExpression().' AS commission_cents', $this->partnerCommissionBindings())
        ->with([
            'partner.user',
            'requestService.dentistRequest.dentist.user',
            'requestService.service',
            'serviceServiceStep.serviceStep',
        ])
        ->orderByDesc('partner_demands.ended_at')
        ->orderByDesc('partner_demands.id')
        ->paginate(15, ['partner_demands.*'], 'partnerPage');
}
```

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Substituição dos Literais por Placeholders Posicionais:**
   - A string retornada por `partnerCommissionExpression()` deve conter estritamente `partner_demands.partner_commission_type = ?`, não possuindo interpolação literal de strings `'percentage'` ou `'fixed'`.
2. **Exatidão dos Cálculos de Comissão (Percentual e Fixa):**
   - Demandas de parceiro concluídas configuradas com `CommissionTypes::Percentage` (ex.: 10% sobre R$ 200,00 = R$ 20,00) continuam sendo calculadas e exibidas com exatidão nos relatórios de pedidos, lucratividade geral, resumo de parceiros e paginação de demandas.
   - Demandas de parceiro concluídas configuradas com `CommissionTypes::Fixed` (ex.: R$ 50,00 fixo = 5000 centavos) continuam sendo calculadas e somadas com exatidão sem distorções numéricas.
3. **Conformidade de Consultas Raw Auditadas no Sistema:**
   - As consultas em `StockMovement.php` (`orderByRaw('id = ? DESC', [...])`) e `laboratories/index.blade.php` (`whereRaw('LOWER(name) LIKE ?', [...])`) continuam empregando bindings parametrizados.
4. **Execução com Sucesso da Suíte de Testes:**
   - Todos os 11 testes de `tests/Feature/ReportsTest.php` e os testes relacionados a estoque e administração central devem executar e passar sem falhas no ambiente Docker PostgreSQL.

---

## 6. Documentação Relacionada

- [Tech Stack & Convenções Técnicas — §3.6 Consultas SQL Raw e Prevenção de Injeção](../../architecture/tech-stack.md)
- [Domínio: Pedidos & Ordens de Serviço — §8 Fluxo de Demandas de Parceiros e Relatórios](../../domain/orders.md)
- [Regras de IA: Modelos Eloquent — Consultas Seguras](../../../.ai/rules/boost/models.md)
- [Regras de IA: Interface Livewire e Volt — Consultas Analíticas Seguras](../../../.ai/rules/boost/livewire-ui.md)
- [OWASP Top 10:2021 – A03: Injection](https://owasp.org/Top10/A03_2021-Injection/)

