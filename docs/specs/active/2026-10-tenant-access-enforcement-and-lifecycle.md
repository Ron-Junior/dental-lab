# Especificação: Bloqueio de Acesso e Ciclo de Vida de Inadimplência, Expiração (Trial/Isenção) e Cancelamento

- **Identificador:** `2026-10-tenant-access-enforcement-and-lifecycle`
- **Status:** Ativo / Em Especificação
- **Data:** Outubro de 2026
- **Módulos Afetados:** Rotas Tenant (`routes/tenant.php`), Middleware (`EnsureTenantIsOperational`), Limites do Plano (`TenantLimitChecker`, `ResourceUsage`), Isenções (`ExemptionService`), Agendamento/Console (`routes/console.php`, `ExpireSubscriptionsAndExemptions`, `ExpireSubscriptionsCommand`), Perfil e Faturamento (`subscription-billing`, `plan-usage`).

---

## 1. Contexto & Motivação

Foram identificadas três vulnerabilidades críticas no controle de acesso e ciclo de vida de faturamento dos laboratórios (`Tenant`):

1. **Ausência de bloqueio de acesso nas rotas operacionais (`routes/tenant.php`):**
   - Não existe middleware validando se o `Tenant` está `Active`, se o período de testes (`Trial`) venceu (`current_period_end <= now()`), se uma isenção (`Exemption`) expirou ou foi revogada sem assinatura paga substituta, ou se a assinatura está inadimplente (`Overdue`) ou cancelada (`Cancelled`). Laboratórios suspensos, inadimplentes ou com trial/isenção vencidos continuam utilizando 100% dos módulos operacionais normalmente.
2. **Falha *Fail-Open* em `TenantLimitChecker::limitsFor` para clientes inadimplentes/cancelados:**
   - O método `limitsFor` filtra assinaturas estritamente por `whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trial])` sem verificar a vigência (`current_period_end`) e, caso a assinatura mude para `Overdue` ou `Cancelled`, retorna `[]`.
   - Como chave ausente em `limits` significa **recurso ilimitado**, qualquer laboratório que fique inadimplente (`Overdue`), tenha o trial vencido ou cancele a assinatura ganha imediatamente **usuários, armazenamento e ordens mensais ilimitados**.
3. **Ausência de rotina agendada (`routes/console.php`) e transição na revogação de isenção:**
   - Não existe comando agendado para encerrar períodos de `Trial` vencidos (`current_period_end <= now()`) ou transicionar assinaturas cujo benefício de `Exemption` atingiu `ends_at` (ou foi revogado em `ExemptionService::revoke`) sem método de pagamento recorrente ativo no Stripe.

---

## 2. Objetivos

- **Proteger as rotas operacionais do laboratório (`routes/tenant.php`)** através do middleware `EnsureTenantIsOperational` (alias `tenant.operational`), garantindo que apenas laboratórios com `TenantStatus::Active` e cobertura financeira válida (isenção vigente, trial vigente ou assinatura ativa em dia) acessem os módulos de negócio.
- **Preservar rotas essenciais de regularização e suporte** (`/perfil`, `/logout`, `/impersonate/{token}`, `/impersonate/leave`), permitindo que o administrador do laboratório regularize seu cartão/assinatura em `/perfil` e que sócios em modo de suporte (`impersonated_by_central`) possam inspecionar o ambiente.
- **Corrigir o *fail-open* em `TenantLimitChecker`:** Manter os limites do plano vinculado mesmo fora de `Active`/`Trial` e, principalmente, **bloquear qualquer criação de recursos (`assertCanCreate`)** quando o laboratório não possuir assinatura operacional vigente (`Overdue`, `Cancelled`, `Trial` expirado ou sem assinatura).
- **Automatizar a transição de vencimento de Trials e Isenções:** Criar Action e comando Artisan agendado em `routes/console.php` (`subscriptions:expire-lifecycle`), além de sincronizar a revogação manual em `ExemptionService::revoke`, para transicionar assinaturas descobertas para `Overdue` (ou cobrar via Stripe caso o cliente já tenha configurado cartão durante o Trial/Isenção).

---

## 3. Regras de Negócio

### 3.1. RN-01: Elegibilidade Operacional do Laboratório (`Tenant::canAccessOperationalFeatures`)

Um laboratório (`Tenant`) é considerado **operacionalmente ativo e adimplente** quando cumpre **todas** as condições abaixo:

1. **Status do Tenant:** `$tenant->status === TenantStatus::Active`.
   - Se o tenant estiver em `PendingPayment`, `Provisioning` ou `Failed`, o acesso operacional no subdomínio é negado.
2. **Cobertura de Assinatura ou Isenção:**
   - **Caso A — Isenção Ativa:** `$tenant->isExempt($now) === true` (`revoked_at === null` e `starts_at <= $now < ends_at`). O laboratório tem acesso liberado independentemente do status de faturas anteriores.
   - **Caso B — Período de Testes (Trial) Vigente:** A assinatura atual (`currentSubscription()`) possui `status === SubscriptionStatus::Trial` **e** `current_period_end !== null && $current_period_end->greaterThan($now)`.
   - **Caso C — Assinatura Ativa Vigente:** A assinatura atual possui `status === SubscriptionStatus::Active` **e** não se encontra com ciclo expirado sem renovação nem com isenção revogada/expirada descoberta (ou seja, se a assinatura foi criada sem `stripe_subscription_id` por conta de uma isenção que já expirou ou foi revogada, ela não é considerada cobertura válida até que o pagamento seja regularizado).
3. **Bloqueio Imediato para Estados Inadimplentes/Encerrados:**
   - Assinatura com `status === SubscriptionStatus::Overdue` ou `SubscriptionStatus::Cancelled`.
   - Assinatura em `Trial` com `current_period_end <= $now`.
   - Ausência de assinatura e ausência de isenção ativa.

### 3.2. RN-02: Middleware `EnsureTenantIsOperational` (`tenant.operational`) em `routes/tenant.php`

1. **Escopo de Aplicação:**
   - Aplicado ao grupo autenticado de rotas operacionais em `routes/tenant.php` (`dashboard`, `etapas`, `produtos/*`, `parceiros`, `dentistas`, `serviços`, `relatorios`, `demandas`, `serviços-solicitados`, `solicitações/{id}`).
2. **Rotas Isentas do Bloqueio (Allowlist no Subdomínio):**
   - Rotas públicas/autenticação (`login`, `esqueci-minha-senha`, `resetar-minha-senha`, `completar-cadastro`).
   - Rotas de impersonação (`/impersonate/{token}` e `/impersonate/leave`).
   - Rota de perfil e faturamento (`/perfil` — nome `profile`) e ação de logout, para que o responsável pelo laboratório possa visualizar o alerta de bloqueio, atualizar seu cartão de crédito ou reativar/regularizar sua assinatura.
3. **Bypass de Suporte (Impersonation Central):**
   - Se a sessão contiver `session('impersonated_by_central') === true`, o sócio autenticado via impersonation pode navegar pelas telas do laboratório para diagnóstico e suporte, visualizando um banner de aviso de que o laboratório encontra-se bloqueado para os usuários comuns.
4. **Comportamento de Redirecionamento / Resposta no Bloqueio:**
   - Se o `Tenant` não estiver `Active` (ex.: `PendingPayment` ou `Provisioning`):
     - Em requisições web, redireciona para a rota central correspondente (`onboarding.checkout` se `PendingPayment`, ou `onboarding.show` se `Provisioning`), ou retorna **HTTP 403** caso não aplicável.
   - Se o `Tenant` estiver `Active`, mas com assinatura `Overdue`, `Cancelled`, `Trial` vencido ou `Exemption` expirada/revogada:
     - **Requisições JSON/API (`$request->expectsJson()`):** Retorna **HTTP 402 (Payment Required)** ou **HTTP 403 (Forbidden)** com mensagem clara informando a suspensão por pendência de assinatura.
     - **Requisições Web/Livewire:** Redireciona para `route('profile')` (`/perfil`) com mensagem de alerta persistente indicando o motivo do bloqueio (Inadimplência, Período de Testes encerrado, Isenção expirada ou Assinatura cancelada) e instruindo a regularização no card **Assinatura & Faturamento**.
     - Caso o usuário logado seja um **Dentista** ou **Parceiro** (sem permissão administrativa de faturamento), a tela `/perfil` (ou banner de bloqueio) exibe aviso amigável: *"O acesso a este laboratório está temporariamente suspenso. Entre em contato com a administração do laboratório."*

### 3.3. RN-03: Correção do *Fail-Open* em `TenantLimitChecker`

1. **Bloqueio Estrito de Criação para Assinaturas Não-Operacionais (`assertCanCreate`):**
   - Antes de verificar limites numéricos, `TenantLimitChecker` deve validar se o `Tenant` possui cobertura operacional ativa (isenção ativa, `Trial` vigente com `current_period_end > now()`, ou `Active` vigente).
   - Se a assinatura estiver `Overdue`, `Cancelled`, com `Trial` vencido (`current_period_end <= now()`) ou inexistente (e o tenant não for isento), **`usageFor` / `buildUsage` deve retornar limite `0` (bloqueado, `UsageLevel::Exceeded`)** para todos os recursos (`PlanResource`), e **`assertCanCreate` deve lançar `PlanLimitExceededException` imediatamente** (*fail-closed* para inadimplência/cancelamento).
2. **Leitura dos Limites do Plano (`limitsFor`):**
   - Para exibição informativa de consumo (ex.: no painel central dos sócios ou no card de perfil), `limitsFor` deve carregar a assinatura mais recente do tenant (`$tenant->currentSubscription()`), preservando os limites do plano contratado mesmo que o status esteja `Overdue`, evitando que `limitsFor` retorne `[]` por mudança de status.
   - O comportamento *fail-open* (`return []` com `Log::error`) fica restrito **exclusivamente** a falhas técnicas de infraestrutura/conexão (`catch (Throwable)` ao consultar o banco central), nunca a estados de negócio inválidos (`Overdue`, `Cancelled`, `Trial` vencido).

### 3.4. RN-04: Encerramento Automático de Trials e Isenções (`routes/console.php` e `ExemptionService`)

1. **Action `App\Actions\ExpireSubscriptionsAndExemptions` e Comando `subscriptions:expire-lifecycle`:**
   - Agendado em `routes/console.php` com execução horária (`Schedule::command('subscriptions:expire-lifecycle')->hourly()->withoutOverlapping()`).
   - **Processamento de Trials Vencidos:**
     - Busca assinaturas com `status === SubscriptionStatus::Trial` e `current_period_end <= $now`.
     - Se o tenant possuir isenção ativa (`$tenant->isExempt($now)`), mantém a assinatura `Active` até o `ends_at` da isenção.
     - Se o tenant já tiver cadastrado cartão no Stripe durante o trial (`! empty($tenant->card_last_four)` e `! empty($tenant->stripe_customer_id)`) e ainda não possuir `stripe_subscription_id`:
       - Tenta criar a assinatura recorrente no Stripe (`StripeService::createSubscription`) para o plano atual:
         - Em caso de sucesso, atualiza `status = SubscriptionStatus::Active`, `stripe_subscription_id`, `current_period_start = $now`, `current_period_end = $plan->addBillingCycles($now, 1)` e marca o `Payment` pendente como `Paid`.
         - Em caso de falha na cobrança do cartão, transiciona a assinatura para `SubscriptionStatus::Overdue` e marca o `Payment` como `Failed`.
     - Se o tenant **não** cadastrou cartão até o fim do trial:
       - Transiciona a assinatura de `Trial` para `SubscriptionStatus::Overdue`.
       - Garante que exista um `Payment` `Pending` com `due_date <= $now` (para contabilização no filtro de inadimplentes do painel central).
   - **Processamento de Isenções Expiradas:**
     - Busca assinaturas com `status === SubscriptionStatus::Active`, sem assinatura recorrente ativa no Stripe (`whereNull('stripe_subscription_id')`), cujo `current_period_end <= $now` (ou cuja `Exemption` vinculada atingiu `ends_at <= $now`) e em que `$tenant->isExempt($now) === false`.
     - Se o tenant possuir cartão cadastrado no Stripe, tenta iniciar a assinatura paga no Stripe nos mesmos moldes acima; caso contrário (sem cartão ou falha na cobrança), transiciona a assinatura para `SubscriptionStatus::Overdue` e gera `Payment` `Pending` com `due_date = $now` no valor efetivo da assinatura (`effective_price_in_cents`).
2. **Transição Imediata ao Revogar Isenção Manualmente (`ExemptionService::revoke`):**
   - Quando um sócio revoga uma isenção ativa no painel central (`ExemptionService::revoke`), além de preencher `revoked_at = now()` e gravar o `AuditLog`, o serviço verifica se o tenant possui outra isenção ativa ou assinatura paga no Stripe (`stripe_subscription_id !== null`).
   - Caso não possua, a assinatura vigente do laboratório (que havia sido criada gratuitamente cobrindo a isenção) tem seu `current_period_end` ajustado para `$now`, `status` alterado para `SubscriptionStatus::Overdue` e um `Payment` `Pending` com `due_date = $now` é criado (caso não exista), efetivando imediatamente o retorno ao ciclo de cobrança e o bloqueio operacional até a regularização no Stripe.
3. **Regularização pelo Perfil (`/perfil`):**
   - No componente `livewire/profile/subscription-billing.blade.php`, quando a assinatura do laboratório estiver em `Overdue` ou `Cancelled` (ou `Trial` vencido / sem `stripe_subscription_id` após fim de isenção), ao cadastrar/atualizar o cartão de crédito (`updateCard`) ou clicar em **"Regularizar Assinatura Agora"**, caso a assinatura ainda não tenha `stripe_subscription_id` (ex.: originada de Trial ou Isenção expirada), o sistema cria a assinatura no Stripe via `StripeService::createSubscription`, quita o `Payment` pendente, restaura `status = SubscriptionStatus::Active`, renova `current_period_end` e desbloqueia imediatamente o acesso ao laboratório.

---

## 4. Requisitos de Interface (UI / UX)

- **Banner de Bloqueio Operacional em `/perfil`:**
  - Quando o usuário é redirecionado para `/perfil` pelo middleware `tenant.operational`, exibir um `<flux:callout variant="danger">` no topo da página explicando claramente o motivo da restrição:
    - **Inadimplência (`Overdue`):** *"Acesso suspenso por pendência financeira. Atualize seu cartão de crédito ou regularize sua fatura abaixo para restabelecer o acesso completo ao laboratório."*
    - **Trial Expirado:** *"Seu período de testes terminou. Cadastre um cartão de crédito abaixo para ativar sua assinatura e continuar utilizando o sistema."*
    - **Isenção Encerrada/Revogada:** *"Seu período de isenção foi encerrado. Cadastre uma forma de pagamento para manter sua assinatura ativa."*
    - **Assinatura Cancelada (`Cancelled`):** *"Sua assinatura encontra-se cancelada. Reative seu plano abaixo para voltar a acessar os módulos do laboratório."*
- **Ação de Regularização no Card `Assinatura & Faturamento`:**
  - Exibir botão de destaque (`variant="primary"`) **"Cadastrar Cartão e Ativar Assinatura"** / **"Regularizar Pagamento"** quando a assinatura estiver `Overdue` ou `Cancelled`.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de Rotas para `Overdue` e `Cancelled`:** Usuário autenticado de um tenant com assinatura `Overdue` ou `Cancelled` (e sem isenção ativa) ao tentar acessar `/dashboard`, `/serviços`, `/dentistas`, `/produtos` ou `/demandas` é redirecionado para `/perfil`.
2. **Bloqueio de Rotas para `Trial` Vencido e `Exemption` Expirada/Revogada:** Tenant com `SubscriptionStatus::Trial` e `current_period_end` no passado, ou com `Exemption` expirada/revogada sem assinatura Stripe ativa, é bloqueado nas rotas operacionais e redirecionado para `/perfil`.
3. **Bloqueio de Tenant Não-Ativo:** Tenant com `status !== TenantStatus::Active` (`PendingPayment`, `Provisioning`, `Failed`) não consegue acessar rotas operacionais do subdomínio.
4. **Acesso Permitido a Rotas de Regularização e Impersonation:** Usuário de tenant bloqueado consegue acessar `/perfil`, atualizar o cartão de crédito e fazer logout. Sócio em sessão de impersonation (`impersonated_by_central`) consegue navegar pelo tenant para suporte e sair da impersonation (`/impersonate/leave`).
5. **Fim do *Fail-Open* em `TenantLimitChecker`:** Para um tenant com assinatura `Overdue`, `Cancelled` ou `Trial` vencido (sem isenção), `TenantLimitChecker::assertCanCreate` lança `PlanLimitExceededException` para qualquer `PlanResource` e `isAllUnlimited()` retorna `false`.
6. **Comando Agendado `subscriptions:expire-lifecycle`:**
   - Transiciona assinaturas `Trial` com `current_period_end <= now()` (sem cartão) para `Overdue`.
   - Transiciona assinaturas `Active` sem `stripe_subscription_id` cuja isenção expirou (`ends_at <= now()`) para `Overdue` e gera `Payment` pendente.
   - Está devidamente registrado no scheduler em `routes/console.php`.
7. **Revogação de Isenção (`ExemptionService::revoke`):** Ao revogar a isenção ativa de um tenant sem assinatura paga no Stripe, a assinatura transiciona imediatamente para `Overdue` e o acesso operacional é bloqueado.
8. **Reativação no Perfil (`/perfil`):** Quando um tenant com Trial vencido, Isenção expirada ou `Overdue` informa um cartão válido no perfil, a assinatura é criada/regularizada no Stripe, o status volta para `Active` e o acesso ao `/dashboard` é imediatamente liberado.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas (Billing)](../../domain/billing.md)
- [Especificação: Segurança e Ciclo de Vida no Webhook de Pagamento](2026-10-webhook-security-and-lifecycle.md)
- [Especificação: Onboarding, Checkout e Assinaturas Stripe](2026-10-tenant-onboarding-and-billing.md)
- [Especificação Arquivada: Limites do Plano](../archived/2026-10-plan-limits.md)

