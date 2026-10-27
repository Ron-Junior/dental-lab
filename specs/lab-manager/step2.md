Estamos desenvolvendo o módulo de planos e assinaturas de um SaaS para laboratórios. Preciso que você implemente o fluxo de confirmação de pagamento via Webhook e o provisionamento automático do banco de dados do tenant.

Por favor, crie:

1. WebhookController:
- Um endpoint POST para receber o evento de pagamento aprovado do gateway (simule o evento, ex: 'invoice.payment_succeeded' ou 'charge.paid').
- O webhook DEVE SER IDEMPOTENTE (verificar se o pagamento já possui status 'paid' para evitar processamento duplicado).
- Ao receber o evento com sucesso:
  a) Atualizar o registro na tabela payments para status 'paid' e preencher o 'paid_at'.
  b) Atualizar o status do tenant correspondente de 'pending_payment' para 'active'.
  c) Criar ou atualizar a subscription associada para status 'active', definindo o período (ex: current_period_start e current_period_end de 30 dias).
  d) Disparar o Job assíncrono para criar o banco de dados do tenant.

2. Job de Provisionamento (Parcialmente implementado, valide se há algo faltante):
- Um Laravel Job assíncrono (deve implementar ShouldQueue).
- Ele deve receber o tenant recém-ativado.
- A lógica deve:
  a) Gerar um nome único para o schema do banco de dados do tenant (ex: tenant_{nome do tenant}).
  b) Criar o schema no PostgreSQL usando o pacote stancl/tenancy e rodar as migrations específicas do tenant (garantindo o isolamento).
  c) Validar se as migrações foram executadas com sucesso.
  d) Disparar um Job assíncrono para criar o usuário do tenant no banco de dados compartilhado.

Forneça os códigos limpos, com tratamento de exceções (try/catch), logs para auditoria e utilizando transações de banco de dados onde necessário.