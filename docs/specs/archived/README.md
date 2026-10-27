# Especificações Arquivadas (Archived Specs)

Este diretório contém especificações de funcionalidades que já foram totalmente implementadas, validadas e incorporadas ao produto estável, servindo como histórico técnico de decisões arquiteturais e regras de negócio consolidadas.

---

## Índice de Especificações Arquivadas

- [2026-09-multitenant-architecture.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-09-multitenant-architecture.md): Base arquitetural multi-tenant com PostgreSQL schemas via `stancl/tenancy`, ambientes e isolamento de rotas.
- [2026-10-lab-manager-step1.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-lab-manager-step1.md): Estrutura de planos, assinaturas, pagamentos e persistência monetária em centavos inteiros no banco central.
- [2026-10-lab-manager-step2.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-lab-manager-step2.md): Fluxo de webhook de pagamento idempotente e provisionamento assíncrono de tenant (`ProvisionTenant`).
- [2026-10-plan-limits.md](file:///root/projetos/dental-lab/docs/specs/archived/2026-10-plan-limits.md): Verificação de limites de consumo, alertas em níveis (warning, critical, exceeded) e bloqueio de criação.

---

## Formato Padrão de Arquivamento

Quando uma especificação em `docs/specs/active/` for concluída, mova o arquivo correspondente para este diretório e adicione o cabeçalho de conclusão:

```markdown
- **Status:** Concluído / Implementado
- **Data de Conclusão:** AAAA-MM-DD
```
