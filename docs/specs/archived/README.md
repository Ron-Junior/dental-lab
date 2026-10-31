# Especificações Arquivadas (Archived Specs)

Este diretório contém especificações de funcionalidades que já foram totalmente implementadas, validadas e incorporadas ao produto estável, servindo como histórico técnico de decisões arquiteturais e regras de negócio consolidadas.

---

## Índice de Especificações Arquivadas

- [2026-09-multitenant-architecture.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-09-multitenant-architecture.md): Base arquitetural multi-tenant com PostgreSQL schemas via `stancl/tenancy`, ambientes e isolamento de rotas.
- [2026-10-lab-manager-step1.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-lab-manager-step1.md): Estrutura de planos, assinaturas, pagamentos e persistência monetária em centavos inteiros no banco central.
- [2026-10-lab-manager-step2.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-lab-manager-step2.md): Fluxo de webhook de pagamento idempotente e provisionamento assíncrono de tenant (`ProvisionTenant`).
- [2026-10-super-admin-panel.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-super-admin-panel.md): Painel central de sócios/super admin para gestão de laboratórios, concessão de descontos e auditoria.
- [2026-10-plan-limits.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-plan-limits.md): Verificação de limites de consumo, alertas em níveis (warning, critical, exceeded) e bloqueio de criação.
- [2026-10-plan-management-and-profitability.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-plan-management-and-profitability.md): Gestão de planos comerciais, reajuste de preço com carência de 2 ciclos, aviso de redução de limites, cadastro de custos operacionais e calculadora de rentabilidade.
- [2026-10-storage-limit-and-s3-upload-security.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-storage-limit-and-s3-upload-security.md): Controle real de quota de armazenamento S3 em GB, autorização por instância em `DentistRequestPolicy` contra IDOR e exclusão arbitrária no S3, e rastreamento polimórfico de fotos de conclusão de demandas na tabela `files`.
- [2026-10-checkout-discount-recalculation.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-checkout-discount-recalculation.md): Preservação e recálculo automático de desconto concedido por sócio durante a troca de planos no checkout público.
- [2026-10-webhook-security-and-lifecycle.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-webhook-security-and-lifecycle.md): Validação de assinatura criptográfica `Stripe-Signature`, renovação alinhada ao ciclo do plano, transição para `Overdue`/`Cancelled` e trava contra re-provisionamento.
- [2026-10-plan-deactivation-and-price-schedule-conflict.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-plan-deactivation-and-price-schedule-conflict.md): Compatibilidade obrigatória de `billing_cycle` na desativação de plano, carência de 2 ciclos e sincronização de reajustes em planos de destino.
- [2026-10-tenant-onboarding-and-billing.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-tenant-onboarding-and-billing.md): Onboarding do laboratório sem senha central, checkout Stripe integrado com SetupIntent, bypass para isenções/trials de sócios e primeiro acesso protegido.
- [2026-10-tenant-access-enforcement-and-lifecycle.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-tenant-access-enforcement-and-lifecycle.md): Bloqueio de rotas operacionais por middleware para inadimplência, trials vencidos ou isenções revogadas, com exceção para perfil/regularização e modo suporte central.
- [2026-10-partner-dentist-privilege-escalation-and-invitation-security.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-partner-dentist-privilege-escalation-and-invitation-security.md): Prevenção de escalação de privilégio na gestão de parceiros e dentistas, vínculo criptográfico por token em convites contra Account Takeover e isolamento de primeiro acesso do proprietário.
- [2026-10-service-edition-pricing-and-demand-integrity.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-service-edition-pricing-and-demand-integrity.md): Persistência completa na edição de serviços do catálogo, proteção referencial (`restrictOnDelete`) e validação contra remoção de etapas com demandas históricas, bloqueio de edição de solicitações após início de etapas, sincronização de novas demandas ao trocar serviço, conversão monetária resiliente (`currency_to_float`) e ampliação de `request_services.quantity` para `unsignedInteger`.
- [2026-10-cmv-batch-cost-and-stock-consistency.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-cmv-batch-cost-and-stock-consistency.md): Correção da distorção de CMV, apuração contínua de Custo Médio Ponderado (CMP) para produtos sem lote (`products.unit_cost`) e lotes existentes (`stock_batches.cost_price`), integridade de movimentações de ajuste (`AdjustmentIn`/`AdjustmentOut`), baixa total de estoque (`lte`) e seleção FEFO em retiradas rápidas com proteção contra concorrência (`lockForUpdate`).

---

## Formato Padrão de Arquivamento

Quando uma especificação em `docs/specs/active/` for concluída, mova o arquivo correspondente para este diretório e adicione o cabeçalho de conclusão:

```markdown
- **Status:** Concluído / Implementado
- **Data de Conclusão:** AAAA-MM-DD
```
