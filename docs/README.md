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
- Ativas: [Onboarding, Checkout e Assinaturas Stripe](specs/active/2026-10-tenant-onboarding-and-billing.md), [Preservação e Recálculo de Desconto no Checkout](specs/active/2026-10-checkout-discount-recalculation.md), [Segurança e Ciclo de Vida no Webhook de Pagamento](specs/active/2026-10-webhook-security-and-lifecycle.md), [Cancelamento de ordens](specs/active/2026-10-order-cancellation.md).
- Concluídas: [índice de arquivadas](specs/archived/README.md).

> Ao planejar uma feature ou modificação, atualize esta pasta conforme [`.ai/rules/boost/documentation.md`](../.ai/rules/boost/documentation.md).
