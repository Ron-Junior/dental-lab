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
- **`PartnerDemand`:** Sub-etapas delegadas para técnicos protéticos especializados parceiros (ex: fundição, fresagem, maquiagem). Quando concluída com foto comprobatória (`result_photo_path`), registra polimorficamente o arquivo em `files` para contabilização no limite de armazenamento do laboratório.
- **`File`:** Anexos polimórficos associados à ordem (`DentistRequest` — arquivos 3D STL, PLY, OBJ e tomografias DICOM compactados em ZIP) e às etapas de produção (`PartnerDemand` — fotos de resultado/comprovante).

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
2. **Preço Histórico no Item e Prevenção contra Adulteração Client-Side ([spec](../specs/archived/2026-11-dentist-request-service-price-tampering-protection.md)):**
   - O `unit_price` gravado em `request_services` deve refletir o valor cobrado no momento do pedido. Alterações futuras na tabela de preços do laboratório (`services`) não devem retroagir sobre ordens já emitidas.
   - Os campos de preço unitário nos modais de solicitação (`requesting.blade.php`) e edição de item (`update.blade.php`) são estritamente informativos (`readonly`). O servidor ignora qualquer valor monetário enviado pelo front-end no payload Livewire (`services.*.unit_price` ou `unitPrice`) e resolve o preço exclusivamente no banco de dados (`Service::whereKey(...)->value('price')` na criação ou troca de serviço, e preservação do `$requestService->unit_price` histórico quando apenas a quantidade é editada).
3. **Consumo Rastreável:**
   - Insumos utilizados em uma ordem devem registrar o `stock_batch_id` quando o produto possuir controle de lote, permitindo recall e rastreabilidade sanitária.
4. **Isolamento por Schema:**
   - Ordens de serviço pertencem estritamente ao schema do laboratório (`tenant_*`). Nenhum dado de paciente ou de dentista vaza entre laboratórios concorrentes.
5. **Autorização e Controle de Armazenamento em Anexos ([spec](../specs/archived/2026-10-storage-limit-and-s3-upload-security.md), [spec](../specs/archived/2026-11-livewire-server-side-authorization-enforcement.md)):**
   - Usuários com perfil `Dentist` só podem visualizar, listar (`loadRequest`), baixar (`download`), anexar ou excluir arquivos de solicitações (`DentistRequest`) vinculadas ao seu próprio registro de dentista (`$dentistRequest->dentist?->user_id === $user->id` em `DentistRequestPolicy::view`). Equipe interna (`Owner`, `Lab`, `LabManager`) gerencia arquivos de qualquer solicitação do laboratório; `LabPartner` não possui acesso aos anexos do pedido.
   - A exclusão de arquivos no S3 (`removeS3File`) exige autorização prévia sobre a `DentistRequest` e validação de que o `path` pertence a um registro `File` daquela solicitação antes de remover o objeto no bucket.
   - Todo upload de arquivo 3D/DICOM ou foto de conclusão de etapa (`PartnerDemand`) valida o incremento real em GB contra o limite `PlanResource::Storage` do plano e persiste o metadado de tamanho (`size`) na tabela `files`.
6. **Integridade Cadastral, Autorização Server-Side e Prevenção de Escalação de Privilégio em Parceiros e Dentistas ([spec](../specs/archived/2026-10-partner-dentist-privilege-escalation-and-invitation-security.md), [spec](../specs/archived/2026-11-livewire-server-side-authorization-enforcement.md)):**
   - Todos os métodos de criação, edição e exclusão em `dentists/store.blade.php`, `dentists/delete.blade.php`, `partners/store.blade.php` e `partners/delete.blade.php` (`edit`, `save`, `confirmDelete`, `delete`) executam `$this->authorize(...)` no servidor (`DentistPolicy` e `PartnerPolicy`, restritos a `Owner`, `Lab` e `LabManager`).
   - O cadastro de um novo `Partner` ou `Dentist` exige `email` único na tabela `users` e cria sempre um novo `User` com a role específica (`Rules::LabPartner` ou `Rules::Dentist`), disparando `SendInvitation`.
   - A edição de um `Partner` ou `Dentist` ignora o `user_id` vinculado na validação `unique:users,email` e atualiza diretamente o registro `User` já associado (`name` e `email`) sem jamais usar `User::updateOrCreate` por e-mail, impedindo a sobrescrita de `rule_id` de outros usuários (como `owner`/`lab`) e a criação de perfis duplicados.
7. **Integridade de Etapas, Preservação de Demandas de Parceiros e Edição de Itens ([spec](../specs/archived/2026-10-service-edition-pricing-and-demand-integrity.md), [spec](../specs/archived/2026-11-livewire-server-side-authorization-enforcement.md)):**
   - Os componentes de etapas (`step/store.blade.php`, `step/delete.blade.php` e `step/manage-partners.blade.php`) validam `$this->authorize(...)` via `ServiceStepPolicy` tanto na abertura do modal (`edit`, `open`) quanto na persistência (`store`, `save`, `delete`).
   - Etapas de serviços (`service_service_step`) vinculadas a demandas históricas (`PartnerDemand`) são protegidas contra exclusão acidental por integridade referencial (`restrictOnDelete`) e validação de negócio no catálogo.
   - A edição ou troca de serviço de um item (`RequestService`) só é permitida antes do início de qualquer etapa (`started_at === null`). Havendo demandas já iniciadas por protéticos parceiros, a edição é bloqueada para resguardar a cadeia produtiva e as comissões. Quando a troca é válida, as etapas anteriores são limpas e as novas demandas são sincronizadas.
8. **Fluxo de Demandas de Parceiros, Inspeção de Progresso, Conclusão Automática e Cancelamento de Serviços ([spec](../specs/archived/2026-10-partner-commissions-and-demand-workflow-integrity.md), [spec](../specs/archived/2026-11-livewire-server-side-authorization-enforcement.md)):**
   - **Autorização Server-Side na Atribuição, Início e Conclusão de Demandas:** Em `partners/assign-demand.blade.php`, `openModal()` e `assignDemand()` exigem `$this->authorize('assign', $this->partnerDemand)` (`PartnerDemandPolicy::assign`), não dependendo apenas do atributo `:disabled` no botão HTML. Usuários com perfil `LabPartner` atribuídos a uma demanda têm direito estrito de iniciar (`start`) e concluir (`complete`) suas próprias etapas sem depender de permissões administrativas de atribuição (`assign`).
   - **Autorização Server-Side no Progresso e Conclusão Manual de Serviços (`service/progress.blade.php`):** Como `service/progress.blade.php` é montado em `planned.blade.php` (acessível por dentistas), os métodos `open(int|string $requestServiceId)` e `complete()` exigem `$this->authorize('updateStatus', $requestService)` (`RequestServicePolicy`, restrita a `Owner`, `Lab` e `LabManager`), impedindo que dentistas inspecionem etapas/fotos de terceiros ou concluam serviços.
   - **Resiliência Cadastral de Comissões:** Ao abrir o modal de início de demanda, caso o técnico parceiro tenha sido desvinculado da etapa no catálogo de serviços (`manage-partners`), o sistema bloqueia o início defensivamente com notificação amigável, impedindo falhas por referência nula (`pivot` em `null`).
   - **Precisão Decimal de Comissões:** Comissões em `partner_demands.partner_commission` são persistidas como decimais (`DECIMAL(10, 2)`), garantindo exatidão para percentuais (ex: `12.5%`) e valores fixos em reais (ex: `R$ 45,50`), preservando os centavos nos relatórios de custos e lucratividade.
   - **Conclusão Automática em Cadeia:** Ao finalizar a última demanda de um serviço (`finish-demand`), o sistema dispara `CompleteService::handle` para marcar o `RequestService` como concluído (`completed_at = now()`). Caso todos os serviços do pedido estejam finalizados, a ordem `DentistRequest` é concluída automaticamente e a notificação é despachada ao dentista.
   - **Cancelamento e Robustez Numérica:** O cancelamento de serviços solicitados (`service::canceled`) é processado com validação de status e estorno de insumos/demandas pendentes. Na exibição de progresso (`planned.blade.php`), serviços cadastrados sem etapas são tratados com proteção contra divisão por zero.
9. **Soft Delete Obrigatório, Autorização e Integridade Histórica na Exclusão ([spec](../specs/archived/2026-10-deletion-integrity-and-authorization.md)):**
   - **Proibição de Hard Delete e Controle por Policy:** `User`, `Dentist`, `Partner`, `Service` e `ServiceStep` utilizam `SoftDeletes` (`deleted_at`) com `restrictOnDelete` nas chaves estrangeiras. A exclusão exige autorização explícita (`$this->authorize('delete', ...)`) restrita a `Owner`, `Lab` e `LabManager`, e `forceDelete` é permanentemente bloqueado.
   - **Sincronização de Conta e Preservação de Histórico (`withTrashed()`):** Ao excluir um `Dentist` ou `Partner`, o `User` vinculado também sofre Soft Delete na mesma transação (revogando sessões e convites e liberando assento no plano), enquanto todas as relações históricas (`DentistRequest::dentist`, `RequestService::service`, `PartnerDemand::partner`, `ServiceServiceStep::serviceStep`, `Dentist::user`, `Partner::user`) utilizam `->withTrashed()` para manter pedidos antigos, relatórios de faturamento/comissões e anexos em `files`/S3 100% íntegros.
   - **Proteção Operacional em Demandas e Etapas:** Parceiros com demandas em andamento (`started_at !== null && ended_at === null`) ou etapas vinculadas a serviços ativos têm o Soft Delete bloqueado até a conclusão/desvinculação; demandas pendentes não iniciadas de um parceiro excluído são desatribuídas (`partner_id = null`).
   - **Unicidade Apenas entre Registros Ativos (`whereNull('deleted_at')`):** A validação de nomes (`services`, `service_steps`) e e-mails (`users`) nos formulários ignora registros com Soft Delete (`whereNull('deleted_at')`), permitindo recadastrar um serviço, etapa, dentista ou parceiro com o mesmo nome ou e-mail de um registro anteriormente excluído.
