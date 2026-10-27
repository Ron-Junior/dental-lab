# Domínio: Pedidos & Ordens de Serviço (Orders)

As ordens de serviço (no código modeladas como `DentistRequest`) representam o coração da operação de um laboratório de prótese dentária. Elas conectam o cirurgião-dentista solicitante aos serviços protéticos executados pelo laboratório e seus parceiros técnicos.

---

## 1. Entidades Principais

- **`Dentist`:** O dentista ou clínica parceira que envia a demanda protética.
- **`DentistRequest`:** A ordem de serviço principal.
  - Possui um código público imutável gerado por ULID (`code`).
  - Possui timestamp de finalização (`completed_at`).
  - Relaciona-se com múltiplos itens/serviços (`RequestService`).
- **`RequestService`:** O serviço específico solicitado dentro da ordem (ex: "Coroa Cerâmica E-Max dente 21").
  - Guarda `unit_price` (em centavos), quantidade (`quantity`) e `completed_at`.
  - Vincula-se a etapas de produção (`ServiceStep` e demandas a parceiros).
  - Registra o consumo real de insumos do estoque (`RequestServiceConsumption`).
- **`PartnerDemand`:** Sub-etapas delegadas para técnicos protéticos especializados parceiros (ex: fundição, fresagem, maquiagem).
- **`File`:** Anexos polimórficos associados à ordem (arquivos STL, fotos da boca do paciente, prescrições escaneadas, PDFs).

---

## 2. Ciclo de Vida da Ordem de Serviço

```
[ Entrada / Criação ] ──> [ Em Produção ] ──> [ Controle de Qualidade ] ──> [ Concluído / Entrega ]
        │                       │
        ▼                       ▼
  [ Cancelamento ]     [ Retrabalho / Ajuste ]
```

1. **Criação / Entrada:**
   - O dentista envia o pedido (ou a recepção cadastra no sistema).
   - O código ULID é gerado automaticamente no evento `creating`.
   - São definidos os serviços solicitados, prazos de entrega e anexados os arquivos escaneados (STL / fotos).
2. **Execução & Etapas (`ServiceStep`):**
   - Cada serviço passa pelas etapas configuradas no laboratório (ex: Modelo -> Enceramento -> Fundição -> Acabamento).
   - Caso uma etapa seja terceirizada, é gerada uma `PartnerDemand` para o parceiro técnico.
3. **Consumo de Estoque:**
   - Durante as etapas ou no fechamento do serviço, insumos de estoque são baixados e associados via `RequestServiceConsumption`.
4. **Conclusão:**
   - Quando todos os serviços do pedido são finalizados, o campo `completed_at` da ordem é preenchido.
   - O status é consolidado para faturamento ou cobrança.

---

## 3. Regras de Negócio Críticas

1. **Imutabilidade do Identificador:**
   - O campo `code` (ULID) nunca deve ser alterado após a criação, garantindo que QR codes impressos e links enviados ao dentista permaneçam válidos.
2. **Preço Histórico no Item:**
   - O `unit_price` gravado em `request_services` deve refletir o valor cobrado no momento do pedido. Alterações futuras na tabela de preços do laboratório (`services`) não devem retroagir sobre ordens já emitidas.
3. **Consumo Rastreável:**
   - Insumos utilizados em uma ordem devem registrar o `stock_batch_id` quando o produto possuir controle de lote, permitindo recall e rastreabilidade sanitária.
4. **Isolamento por Schema:**
   - Ordens de serviço pertencem estritamente ao schema do laboratório (`tenant_*`). Nenhum dado de paciente ou de dentista vaza entre laboratórios concorrentes.
