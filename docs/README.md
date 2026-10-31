# Documentação do Dental Lab

## Arquitetura (`architecture/`)
- [Tech Stack](architecture/tech-stack.md): stack, multitenancy e convenções técnicas.
- [Convenções de Dados](architecture/data-conventions.md): dinheiro em centavos, datas e UTC.
- [Acesso Central, Impersonation & Auditoria](architecture/central-admin-access.md): painel dos sócios.

## Domínio (`domain/`)
- [Pedidos](domain/orders.md)
- [Estoque](domain/inventory.md)
- [Cobrança & Assinaturas](domain/billing.md)

## Especificações (`specs/`)
- Ativas: [Cancelamento de ordens](specs/active/2026-10-order-cancellation.md), [Edição de Serviços, Integridade de Demandas Históricas e Resiliência Monetária](specs/active/2026-10-service-edition-pricing-and-demand-integrity.md), [Correção de Distorção de CMV, Custo Médio Ponderado de Lote e Integridade de Movimentações de Estoque](specs/active/2026-10-cmv-batch-cost-and-stock-consistency.md), [Integridade de Comissões de Parceiros e Robustez no Fluxo de Demandas e Serviços](specs/active/2026-10-partner-commissions-and-demand-workflow-integrity.md).
- Concluídas: [índice de arquivadas](specs/archived/README.md).

> Ao planejar uma feature ou modificação, atualize esta pasta conforme [`.ai/rules/boost/documentation.md`](../.ai/rules/boost/documentation.md).
