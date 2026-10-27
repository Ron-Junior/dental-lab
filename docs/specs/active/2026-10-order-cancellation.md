# Especificação: Cancelamento de Ordens de Serviço (Order Cancellation)

- **Identificador:** `2026-10-order-cancellation`
- **Status:** Ativo / Em Especificação
- **Data:** Outubro de 2026
- **Módulos Afetados:** Ordens (`DentistRequest`), Estoque (`StockMovement`), Financeiro (`Payment`), Parceiros (`PartnerDemand`).

---

## 1. Contexto & Motivação

Atualmente, quando uma ordem de serviço (`DentistRequest`) é criada pelo dentista ou recepção, não existe um fluxo formalizado e seguro para cancelamento. Eventos comuns na rotina odontológica exigem o cancelamento controlado:
1. Mudança no plano de tratamento do paciente ou desistência da cirurgia/prótese.
2. Erro de cadastro na recepção (duplicação de pedido).
3. Moldagem/escaneamento inviável que não permite execução técnica.

O cancelamento sem regras sistêmicas acarreta inconsistências: materiais já baixados no estoque ficam órfãos, ordens finalizadas são indevidamente estornadas e técnicos parceiros podem produzir peças que o cliente já cancelou.

---

## 2. Objetivos

- Permitir o cancelamento de uma ordem de serviço de acordo com seu estágio de produção.
- Estornar de forma transparente ou consolidar o consumo de estoque conforme o material tenha sido consumido ou não.
- Notificar e suspender demandas abertas com técnicos parceiros (`PartnerDemand`).
- Garantir rastreabilidade: registrar o motivo do cancelamento, usuário responsável e data/hora.

---

## 3. Regras de Negócio & Estados Elegíveis

### 3.1. Condições de Elegibilidade

| Estado da OS | Pode Cancelar? | Comportamento do Sistema |
| :--- | :---: | :--- |
| **Entrada / Pendente (Sem início)** | Sim | Cancelamento imediato. Estoque não é afetado (nenhum item foi baixado). |
| **Em Produção (Parcial)** | Sim (com confirmação) | Exige justificativa. Cancela etapas futuras. Permite ao operador decidir se estorna materiais ou registra como perda/descarte. |
| **Finalizada / Entregue (`completed_at != null`)** | Não | Uma ordem concluída não pode ser cancelada diretamente. Deve ser tratado via fluxo de Devolução / Garantia / Retrabalho. |
| **Já Cancelada** | Não | Ação bloqueada. |

### 3.2. Ações Automáticas no Cancelamento

1. **Atualização da Ordem:**
   - Registrar `cancelled_at = now()`.
   - Registrar `cancellation_reason` (texto livre obrigatório ou categoria).
   - Registrar `cancelled_by_user_id`.
2. **Estorno ou Descarte de Insumos (`StockMovement`):**
   - Caso insumos já tenham sido debitados via `RequestServiceConsumption`:
     - **Opção A (Material reutilizável):** Gera movimentação de entrada (`In`) com motivo `Return` associada ao lote de origem.
     - **Opção B (Material inutilizado/customizado, ex: bloco fresado):** Mantém o débito com justificativa de perda/descarte técnico.
3. **Parceiros Terceirizados (`PartnerDemand`):**
   - Se houver `PartnerDemand` em andamento, atualizar o status da demanda para `Cancelled` e notificar o protético parceiro imediatamente para evitar custos adicionais de mão de obra.
4. **Financeiro / Cobrança:**
   - Se a ordem possuir cobrança pendente ou agendada, cancelar faturas pendentes ou gerar nota de crédito para o dentista.

---

## 4. Requisitos de Interface (UI / UX)

- **Botão de Ação:** "Cancelar Ordem" disponível na visualização da OS apenas para usuários com permissão (`cancel(DentistRequestPolicy)`).
- **Modal de Confirmação (Flux UI):**
  - Alerta visual em tom destrutivo/aviso.
  - Seletor de motivo: "Erro de digitação", "Desistência do dentista/paciente", "Moldagem imprópria", "Outros".
  - Campo de observação textual obrigatório.
  - Seção para decisão sobre os materiais já baixados.
- **Feedback:** Toast de confirmação e atualização reativa do status da ordem na listagem Livewire.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Permissão:** Usuários sem autorização recebem HTTP 403 ao tentar cancelar uma ordem.
2. **Ordem Concluída:** Tentativa de cancelar ordem com `completed_at` preenchido lança exceção de validação de negócio.
3. **Estoque:** Insumos marcados para estorno retornam exatamente para os saldos dos lotes (`StockBatch.current_quantity`) correspondentes.
4. **Idempotência:** Ação repetida de cancelamento na mesma ordem não duplica estornos de estoque.
5. **Auditoria:** A ordem cancelada exibe quem cancelou, quando e o motivo cadastrado.
