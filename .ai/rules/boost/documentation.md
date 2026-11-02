# Regras de documentação (`docs/`)

Estas instruções valem ao **planejar** uma feature nova ou uma modificação de comportamento (inclusive no modo `/plan`) e substituem a regra genérica de não criar documentação: manter `docs/` atualizado faz parte do trabalho.

## Estrutura

- `docs/architecture/`: decisões técnicas transversais (stack, convenções de dados, acesso, tenancy, segurança).
- `docs/domain/`: regras de negócio e entidades por módulo (ex.: `orders.md`, `inventory.md`, `billing.md`).
- `docs/specs/active/`: especificação da feature em andamento, nomeada `AAAA-MM-slug.md`.
- `docs/specs/archived/`: specs concluídas, com o índice em `README.md`.

## Quando e o que atualizar

1. **Ao planejar** (antes de implementar): consulte `docs/` e crie/atualize a spec em `docs/specs/active/` com contexto, decisões, regras de negócio, UI e critérios de aceite. Em feature que altera regras de um módulo, atualize o arquivo correspondente em `docs/domain/`; se muda padrão técnico, auth, tenancy ou dados, atualize `docs/architecture/`.
2. **Ao concluir**: reflita no `docs/domain/` e `docs/architecture/` o que mudou de fato, mova a spec para `docs/specs/archived/` com `Status: Concluído / Implementado` e `Data de Conclusão`, e adicione-a ao índice de `docs/specs/archived/README.md`.
3. **Se o plano mudar** durante a implementação, atualize a spec e os docs afetados na mesma entrega.

## Estilo

- Português, objetivo, com cabeçalho no padrão dos documentos existentes (Identificador, Status, Data, Módulos Afetados nas specs).
- Não duplique conteúdo: linke para outros docs com caminhos relativos.
- Documente decisões e regras, não código linha a linha.
