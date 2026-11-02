# Especificação: Controle de Limite de Armazenamento, Autorização de Arquivos S3 e Rastreamento de Fotos de Demandas

- **Identificador:** `2026-10-storage-limit-and-s3-upload-security`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-31
- **Módulos Afetados:** Solicitações de Serviço (`resources/views/components/service/requesting.blade.php`, `DentistRequest`, `DentistRequestPolicy`), Demandas de Parceiros (`resources/views/components/partners/finish-demand.blade.php`, `PartnerDemand`), Armazenamento e Limites de Plano (`File`, `TenantLimitChecker`, `EnforcesPlanLimits`, `PlanProfitabilityCalculator`).

---

## 1. Contexto & Motivação

Foram identificadas três vulnerabilidades de negócio, segurança e contabilização de custos no fluxo de armazenamento de arquivos (S3) dos laboratórios:

1. **Bypass do limite de armazenamento (`PlanResource::Storage`) no upload direto para S3 (`requesting.blade.php`):**
   - Nos métodos `getPreSignedUploadUrl` (linha 139) e `addFilesToRequest` (linha 194), a verificação de limite é invocada como `$this->guardPlanLimit(PlanResource::Storage, 0)`, passando **`0`** como incremento de consumo (`$incoming = 0`), embora `$fileSize` (em bytes) seja recebido em `getPreSignedUploadUrl` e os tamanhos dos arquivos (`size`) estejam presentes no payload de `addFilesToRequest`.
   - Em `TenantLimitChecker::assertCanCreate`, quando `$incoming === 0`, o bloqueio só ocorre se o uso atual já estiver em `UsageLevel::Exceeded` ($\ge 100\%$). Consequentemente, um laboratório com $4{,}99\text{ GB}$ usados de um limite de $5\text{ GB}$ consegue enviar um arquivo de $50\text{ GB}$ sem qualquer bloqueio.
2. **Falhas de autorização (IDOR / Broken Access Control) em `addFilesToRequest` e `removeS3File` (`requesting.blade.php`):**
   - `addFilesToRequest` verifica apenas a permissão genérica `$this->authorize('create', DentistRequest::class)`, sem validar se a `$dentistRequest` alvo pertence ao dentista autenticado (`Rules::Dentist`), permitindo anexar arquivos a solicitações de terceiros.
   - `removeS3File` (linha 82, acionado pelo evento Livewire `remove-s3-file`) **não possui nenhuma verificação de autorização** e executa `Storage::disk('s3')->delete($path)` antes mesmo de verificar se o arquivo pertence à solicitação ou ao usuário logado. Qualquer usuário autenticado pode disparar o evento passando um `path` arbitrário e apagar objetos do bucket S3, além de causar erro fatal (`Call to a member function delete() on null`) caso o registro `File` não exista.
3. **Fotos de conclusão de demanda fora da tabela `files` e da calculadora de rentabilidade (`finish-demand.blade.php`):**
   - Ao finalizar uma demanda de parceiro (`PartnerDemand`), a foto do resultado (`$this->image`) é salva diretamente no Storage e apenas o caminho é gravado em `partner_demands.result_photo_path`, sem verificar o limite `PlanResource::Storage` e sem criar o registro correspondente na tabela `files`.
   - Como `TenantLimitChecker` calcula o consumo de `PlanResource::Storage` via `File::query()->sum('size') / 1024 ** 3`, essas imagens nunca entram no consumo do limite do plano nem nos custos de S3 calculados pela `PlanProfitabilityCalculator`.

---

## 2. Objetivos

- Garantir que `getPreSignedUploadUrl` e `addFilesToRequest` validem o incremento real de armazenamento em gigabytes ($\text{bytes} / 1024^3$) contra o limite `PlanResource::Storage` do plano.
- Remover objetos recém-enviados do S3 caso a verificação de limite ou autorização falhe em `addFilesToRequest`, evitando arquivos órfãos no bucket.
- Implementar controle de acesso por instância em `DentistRequestPolicy` (`view`, `update`, `deleteFile`) e aplicá-lo em `addFilesToRequest`, `removeS3File` e `request-files.blade.php`.
- Proteger `removeS3File` para que somente exclua objetos no S3 após autorizar o usuário na `DentistRequest` e localizar o registro `File` legítimo vinculado àquela solicitação.
- Validar o limite de armazenamento e registrar polimorficamente na tabela `files` todas as fotos de conclusão de demanda (`PartnerDemand`), integrando-as automaticamente ao `TenantLimitChecker` e à `PlanProfitabilityCalculator`.

---

## 3. Regras de Negócio

### 3.1. RN-01: Validação Real de Quota de Armazenamento no Upload S3 (`requesting.blade.php`)

1. **Conversão de Bytes para Gigabytes (`GB`):**
   - Como `TenantLimitChecker` mede `PlanResource::Storage` em gigabytes (`1024 ** 3` bytes), todo tamanho de entrada em bytes deve ser convertido pela fórmula:
     $$\text{incomingGb} = \frac{\text{bytes}}{1024^3}$$
2. **Validação em `getPreSignedUploadUrl(string $filename, string $contentType = '', ?int $fileSize = null)`:**
   - O parâmetro `$fileSize` deve ser obrigatório e positivo (`$fileSize !== null && $fileSize > 0`). Caso `$fileSize === null || $fileSize <= 0`, lançar `ValidationException` no campo `uploadedFiles` impedindo a geração de URL pré-assinada sem tamanho declarado.
   - Calcular `$incomingGb = $fileSize / (1024 ** 3)` e invocar `$this->guardPlanLimit(PlanResource::Storage, $incomingGb)`.
   - Se `guardPlanLimit` retornar `false` (uso atual + arquivo excede `storage_gb_limit`), lançar `ValidationException::withMessages(['uploadedFiles' => ...])` sem gerar a URL pré-assinada.
3. **Validação em `addFilesToRequest(int $requestId, array $files)`:**
   - Calcular a soma total em bytes de todos os arquivos recebidos no lote:
     ```php
     $totalBytes = collect($files)->sum(fn (array $file) => max(0, (int) ($file['size'] ?? 0)));
     $incomingGb = $totalBytes / (1024 ** 3);
     ```
   - Cada item de `$files` deve conter um `size` inteiro positivo (`> 0`) e um `path` iniciado obrigatoriamente pelo prefixo do tenant atual (`requests/{tenantId}/`).
   - Caso `$this->guardPlanLimit(PlanResource::Storage, $incomingGb)` retorne `false`:
     - Remover do disco S3 os objetos do lote cujos caminhos pertençam comprovadamente ao prefixo do tenant (`requests/{tenantId}/`), evitando que arquivos enviados diretamente pelo navegador fiquem órfãos no bucket quando o limite é atingido em uploads concorrentes.
     - Interromper a execução sem criar registros na tabela `files`.

### 3.2. RN-02: Autorização de Acesso e Propriedade sobre `DentistRequest` e `File`

1. **Política de Acesso (`DentistRequestPolicy`):**
   - Definir os métodos de autorização por instância em `App\Policies\DentistRequestPolicy`:
     - **`view(User $user, DentistRequest $dentistRequest): bool`**:
       - Permitido para equipe interna do laboratório (`Rules::Owner`, `Rules::Lab`, `Rules::LabManager`).
       - Para `Rules::Dentist`, permitido **somente** quando `$dentistRequest->dentist_id !== null && $dentistRequest->dentist_id === $user->dentist?->id`.
     - **`update(User $user, DentistRequest $dentistRequest): bool`**:
       - Mesma regra de propriedade de `view`: equipe interna do laboratório pode gerenciar arquivos de qualquer solicitação do tenant; um usuário `Rules::Dentist` só pode anexar ou remover arquivos de solicitações vinculadas ao seu próprio `Dentist` (`$dentistRequest->dentist_id === $user->dentist?->id`).
2. **Autorização em `addFilesToRequest(int $requestId, array $files)`:**
   - Carregar `$dentistRequest = DentistRequest::findOrFail($requestId)` **antes** de manipular qualquer dado.
   - Executar `$this->authorize('update', $dentistRequest)`. Se o usuário logado for um dentista tentando anexar arquivos à solicitação de outro dentista, lançar `AuthorizationException` (HTTP 403).
3. **Autorização e Integridade em `removeS3File(int|string $requestId, string $path)`:**
   - Carregar `$dentistRequest = DentistRequest::findOrFail((int) $requestId)`.
   - Executar `$this->authorize('update', $dentistRequest)`.
   - Buscar o registro `File` estritamente através do relacionamento polimórfico da solicitação:
     ```php
     $file = $dentistRequest->files()->where('path', $path)->firstOrFail();
     ```
   - Somente após confirmar a autorização e a existência do registro `File` pertencente àquela `DentistRequest`:
     1. Apagar o objeto no storage usando o disco registrado no próprio model (`Storage::disk($file->disk ?: config('filesystems.default', 's3'))->delete($file->path)`).
     2. Excluir o registro `$file->delete()`.
   - Nunca apagar um `path` arbitrário diretamente no S3 sem antes validar que ele consta na tabela `files` vinculado à `DentistRequest` autorizada.

### 3.3. RN-03: Rastreamento de Fotos de Conclusão de Demanda (`finish-demand.blade.php` e `PartnerDemand`)

1. **Relacionamento Polimórfico em `PartnerDemand`:**
   - Adicionar ao model `App\Models\PartnerDemand` o relacionamento polimórfico com `App\Models\File`:
     ```php
     public function files(): MorphMany
     {
         return $this->morphMany(File::class, 'fileable');
     }
     ```
2. **Verificação de Limite antes do Upload (`finish-demand.blade.php`):**
   - O componente `finish-demand.blade.php` deve usar a trait `EnforcesPlanLimits`.
   - Em `finishDemand()`, após `$this->authorize('complete', $this->partnerDemand)` e `$this->validate(...)`, quando `$this->image` estiver presente:
     - Obter o tamanho do arquivo em bytes (`$fileSize = (int) $this->image->getSize()`) e calcular `$incomingGb = $fileSize / (1024 ** 3)`.
     - Executar `if (! $this->guardPlanLimit(PlanResource::Storage, $incomingGb)) { return; }`.
     - Caso o limite de armazenamento seja excedido, a imagem **não** é enviada ao Storage e a demanda **não** é finalizada (permitindo ao usuário concluir sem foto ou liberar espaço/fazer upgrade).
3. **Persistência na Tabela `files`:**
   - Quando `$this->image` for enviada com sucesso ao disco (`$disk = config('filesystems.default', 's3')`), armazenar no caminho padronizado `requests/{tenantId}/demands/{request_service_id}` (ou `requests/{request_service_id}` no disco padrão) e criar o registro polimórfico em `files` dentro de transação:
     - `fileable_type`: `PartnerDemand::class`
     - `fileable_id`: `$this->partnerDemand->id`
     - `name`: `$this->image->getClientOriginalName()`
     - `path`: `$path`
     - `disk`: `$disk`
     - `mime_type`: `$this->image->getMimeType()`
     - `extension`: `strtolower($this->image->getClientOriginalExtension())`
     - `size`: `$fileSize`
     - `original_size`: `$fileSize`
   - Caso a demanda já possuísse arquivos anteriores registrados em `files`, removê-los do Storage e do banco antes de registrar a nova foto.
   - Manter `result_photo_path = $path` sincronizado em `PartnerDemand` para retrocompatibilidade com a visualização em `progress.blade.php` (`$demand->result_photo_url`).
4. **Contabilização em `TenantLimitChecker` e `PlanProfitabilityCalculator`:**
   - Como `TenantLimitChecker::consumption(PlanResource::Storage)` soma `File::query()->sum('size') / 1024 ** 3`, os registros `File` criados para `PartnerDemand` passam automaticamente a compor:
     - O consumo e os alertas de limite de armazenamento do laboratório (`PlanResource::Storage`);
     - O cálculo de armazenamento real (`totalStorageGb`, `averageStorageGb`) e de custos de S3 (`OperationalCostType::PerStorageGb`) na `PlanProfitabilityCalculator`.

---

## 4. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de `getPreSignedUploadUrl` por Limite de Storage:**
   - Dado um tenant com limite de `1 GB` (`storage_gb_limit = 1`) e `950 MB` já ocupados na tabela `files`, chamar `getPreSignedUploadUrl('scan.stl.zip', 'application/zip', 100 * 1024 * 1024)` (`100 MB`) lança `ValidationException` (e exibe toast de limite atingido), sem retornar URL pré-assinada.
   - Chamar `getPreSignedUploadUrl` com `$fileSize = null` ou `$fileSize = 0` também é rejeitado com `ValidationException`.
2. **Bloqueio de `addFilesToRequest` por Limite de Storage e Limpeza de Órfãos:**
   - Dado um tenant próximo ao limite de `PlanResource::Storage`, chamar `addFilesToRequest` com um lote cujo `size` somado ultrapasse o limite do plano não cria registros em `files` e apaga do disco S3 os objetos do lote recém-enviados.
3. **Proteção contra IDOR em `addFilesToRequest`:**
   - Um usuário `Rules::Dentist` (Dentista A) tentando chamar `addFilesToRequest` para uma `DentistRequest` pertencente ao Dentista B recebe `AuthorizationException` (403) e nenhum registro `File` é criado.
4. **Proteção contra IDOR e Exclusão Arbitrária em `removeS3File`:**
   - Um usuário `Rules::Dentist` (Dentista A) disparando `remove-s3-file` com `requestId` do Dentista B recebe 403 e o arquivo no S3 permanece intacto.
   - Um usuário autorizado para `requestId` passando um `path` que não pertence àquela `DentistRequest` (ex.: caminho de outro pedido ou arquivo de outra entidade) recebe `ModelNotFoundException` (404) e o objeto arbitrário no S3 **não** é apagado.
5. **Contabilização e Limite nas Fotos de Conclusão de Demanda (`finish-demand`):**
   - Ao finalizar uma `PartnerDemand` enviando uma foto (`image`) de `2 MB`, um registro na tabela `files` é criado com `fileable_type = PartnerDemand::class`, `fileable_id = $demand->id` e `size` igual ao tamanho da imagem em bytes.
   - `TenantLimitChecker::consumptionFor(PlanResource::Storage)` e `PlanProfitabilityCalculator::overview()` passam a refletir o tamanho da foto da demanda.
   - Se o envio da foto em `finishDemand` ultrapassar o `storage_gb_limit` do plano do laboratório, a conclusão é bloqueada pelo `guardPlanLimit`, a foto não é persistida no Storage nem em `files`, e a demanda permanece em andamento (`ended_at = null`).

---

## 5. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas (Billing)](../../domain/billing.md)
- [Domínio: Pedidos & Ordens de Serviço (Orders)](../../domain/orders.md)
- [Especificação Arquivada: Limites do Plano e Alertas Proativos](../archived/2026-10-plan-limits.md)
- [Especificação Arquivada: Gestão de Planos e Calculadora de Rentabilidade](../archived/2026-10-plan-management-and-profitability.md)

