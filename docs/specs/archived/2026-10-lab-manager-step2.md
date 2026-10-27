# Especificação: Lab Manager - Etapa 2: Webhooks de Pagamento e Provisionamento de Tenant

- **Identificador:** `2026-10-lab-manager-step2`
- **Status:** Implementado
- **Data:** Outubro de 2026
- **Módulos Afetados:** Controllers Centrais (`PaymentWebhookController`), Jobs Assíncronos (`ProvisionTenant`), Rotas (`routes/web.php`).

---

## 1. Contexto & Motivação

Após a escolha de plano e pagamento pelo laboratório contratante, o gateway de pagamento (Stripe, Mercado Pago, Asaas, etc.) notifica o Dental Lab via webhook. O sistema deve processar o evento de forma confiável e idempotente, atualizando os registros financeiros e acionando o provisionamento imediato do ambiente isolado do laboratório.

---

## 2. Requisitos & Fluxos

### 2.1. Webhook de Pagamento (`PaymentWebhookController`)
- **Endpoint:** POST no domínio central configurado em `routes/web.php`.
- **Idempotência Rigorosa:** Verificar se a transação ou pagamento já possui status `paid`. Em caso afirmativo, responder HTTP 200 imediatamente sem duplicar ações de provisionamento.
- **Processamento sob Transação de Banco de Dados:**
  1. Atualizar o registro em `payments` para status `paid` e preencher `paid_at = now()`.
  2. Atualizar o `Tenant` correspondente de `pending_payment` para `active` (ou `provisioning`).
  3. Ativar a `Subscription` associada definindo `status = active` e calculando o período contratual (`current_period_start` e `current_period_end`).
  4. Disparar a Job assíncrona de criação do schema do laboratório.

### 2.2. Job de Provisionamento (`ProvisionTenant`)
- **Fila:** Implementa `ShouldQueue` com tentativas e tratamento de falhas.
- **Etapas de Execução:**
  1. Obter ou gerar o nome do schema único para o tenant (`tenant_{slug}`).
  2. Executar a criação do schema no PostgreSQL via `stancl/tenancy`.
  3. Rodar as migrações específicas do tenant (`database/migrations/tenant/`).
  4. Rodar os seeders essenciais (serviços protéticos base, status de OS, insumos padrão).
  5. Provisionar o usuário administrador do laboratório no schema do tenant.
  6. Confirmar o status operacional do tenant como `active` e disparar evento/notificação de boas-vindas.

---

## 3. Critérios de Aceite & Segurança

- **Tolerância a Falhas:** Em caso de exceção na criação do banco ou execução das migrações, a falha é registrada nos logs (`Log::error`), o tenant passa para status de erro/investigação e a exceção é reportada para o Sentry.
- **Logs de Auditoria:** Todo recebimento de webhook registra log de auditoria com ID do evento e ID do tenant.