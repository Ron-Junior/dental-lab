ATENÇÃO À REGRA MONETÁRIA:
Todos os valores monetários no banco de dados devem ser armazenados como NÚMEROS INTEIROS (bigInteger/integer) representando CENTAVOS. Exemplo: R$ 100,00 = 10000.

Gere os arquivos de migration para as seguintes tabelas:

1. plans:
- id (UUID, PK)
- name (string)
- price_in_cents (bigInteger) -> Valor em centavos
- billing_cycle (enum: monthly, annual)
- limits (jsonb) -> ex: {"users_limit": 10, "storage_gb": 50}
- is_active (boolean)
- timestamps

2. tenants:
- id (UUID, PK)
- name (string)
- domain (string, unique)
- status (enum: pending_payment, active, suspended, cancelled)
- db_name (string, nullable)
- domain_reserved_until (timestamp, nullable)
- timestamps

3. subscriptions:
- id (UUID, PK)
- tenant_id (FK para tenants, cascade delete)
- plan_id (FK para plans)
- status (enum: trial, active, overdue, canceled)
- current_period_start (timestamp)
- current_period_end (timestamp)
- timestamps

4. payments:
- id (UUID, PK)
- tenant_id (FK para tenants)
- gateway_transaction_id (string, nullable)
- amount_in_cents (bigInteger) -> Valor pago/devido em centavos
- status (enum: pending, paid, failed, expired)
- due_date (timestamp)
- paid_at (timestamp, nullable)
- timestamps

Por favor, escreva também os Models do Eloquent correspondentes, incluindo:
- Relacionamentos (belongsTo, hasMany);
- Cast do campo limits como array/json;
- Attribute Accessors/Mutators (ou Attribute Casts customizados no Laravel) para converter automaticamente de centavos para Reais/Decimal na leitura e de Reais/Decimal para centavos na escrita, mantendo o código limpo.

OBS: reaproveite o helper currency