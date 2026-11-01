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
- Ativas: [Cancelamento de ordens](specs/active/2026-10-order-cancellation.md), [Proteção de Propriedades Livewire (`#[Locked]`) e Validação Cruzada de Token em `complete-registration`](specs/active/2026-10-complete-registration-locked-properties-and-token-validation.md).
- Concluídas: [índice de arquivadas](specs/archived/README.md).

> Ao planejar uma feature ou modificação, atualize esta pasta conforme [`.ai/rules/boost/documentation.md`](../.ai/rules/boost/documentation.md).
