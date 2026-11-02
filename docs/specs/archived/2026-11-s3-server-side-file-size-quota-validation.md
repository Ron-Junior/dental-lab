# Especificação: Validação Server-Side do Tamanho Real de Objetos no S3 (`Storage::size`) para Cálculo Autoritativo de Cota de Armazenamento

- **Identificador:** `2026-11-s3-server-side-file-size-quota-validation`
- **Status:** Concluído / Implementado
- **Data de Conclusão:** 2026-11-02
- **Data:** Novembro de 2026
- **Módulos Afetados:**
  - Solicitação de Serviços e Upload Direto S3 (`resources/views/components/service/requesting.blade.php`)
  - Verificador de Limites e Consumo do Plano (`app/Services/PlanLimits/TenantLimitChecker.php`)
  - Testes de Upload e Limites de Armazenamento (`tests/Feature/DentistRequestFileUploadTest.php`)

---

## 1. Contexto & Motivação

A demanda **4.3 ([MÉDIA] Confiança no Tamanho de Arquivo Informado pelo Cliente para Cálculo de Cota de Armazenamento)** identifica uma vulnerabilidade de integridade de quota (Storage Quota Bypass / Client-Side Size Spoofing) no fluxo de upload direto para o S3:

1. **Confiança Indevida em `$fileData['size']` no Componente `requesting.blade.php`:**
   - O consumo de armazenamento do laboratório (`PlanResource::Storage`) em `TenantLimitChecker::consumption()` (linha 200) é apurado somando a coluna `files.size` no banco de dados (`File::query()->sum('size') / 1024 ** 3`), a qual também alimenta o cálculo de custos reais de S3 na `PlanProfitabilityCalculator`.
   - No entanto, como o upload dos arquivos 3D/DICOM compactados (`.zip`) é realizado diretamente do navegador para o S3 via URL pré-assinada (`getPreSignedUploadUrl`), o método `addFilesToRequest(int $requestId, array $files)` (linhas 209–256 de `resources/views/components/service/requesting.blade.php`) confia no valor `'size' => (int) ($fileData['size'] ?? 0)` enviado no payload Livewire pelo cliente tanto para verificar o limite (`guardPlanLimit`) quanto para gravar o registro na tabela `files` (`'size' => (int) $fileData['size']`).
   - O servidor **não** consulta o tamanho real do objeto armazenado no bucket via `Storage::disk($diskName)->size($path)`.

2. **Impacto de Negócio e Financeiro (Bypass de Cota de Disco):**
   - Um usuário mal-intencionado pode solicitar uma URL pré-assinada informando `fileSize = 1` byte (ou valor mínimo), realizar o upload direto de um arquivo de centenas de megabytes ou gigabytes para o S3 e, em seguida, invocar `addFilesToRequest` enviando `'size' => 1` (ou `0` / número negativo caso não validado contra o objeto real).
   - Com isso, o registro em `files` passa a contabilizar apenas `1 byte` (ou `0`), permitindo burlar indefinidamente o limite `storage_gb_limit` contratado no plano do laboratório e distorcendo a apuração de custos operacionais (`OperationalCostType::PerStorageGb`) no painel central dos sócios.

---

## 2. Objetivos

1. **Obter e validar o tamanho real de cada arquivo diretamente no Storage (`Storage::disk($disk)->size($path)`) no servidor:**
   - Em `addFilesToRequest()`, ignorar qualquer valor `$fileData['size']` vindo do navegador e consultar obrigatoriamente a existência (`exists($path)`) e o tamanho real em bytes (`size($path)`) do objeto no disco oficial do sistema (`config('filesystems.default', 's3')`) antes de verificar a cota do plano e antes de persistir na tabela `files`.
2. **Validar escopo de disco, existência do objeto e tamanho estritamente positivo (`> 0`):**
   - Garantir que o disco utilizado seja restrito aos discos permitidos da aplicação (ou fixado em `config('filesystems.default', 's3')`), que o `path` pertença ao prefixo do tenant atual (`requests/{tenantId}/`) sem *path traversal* (`..`), que o objeto exista no bucket e que o tamanho real retornado pelo Storage seja um inteiro positivo (`$actualSize > 0`).
3. **Contabilizar `guardPlanLimit` e persistir `files.size` usando exclusivamente o tamanho real do Storage:**
   - Calcular `$totalBytes` e `$incomingGb` a partir da soma dos tamanhos reais obtidos via `Storage::disk($diskName)->size($path)`.
   - Caso o limite `PlanResource::Storage` seja excedido, remover do Storage todos os objetos do lote recém-enviados (evitando arquivos órfãos) e abortar a persistência.
   - Persistir `'size' => $actualSize` (e sanitizar `'original_size'` para um inteiro positivo `max($actualSize, (int) ($fileData['original_size'] ?? 0))`) na tabela `files`.
4. **Endurecer o cálculo de consumo em `TenantLimitChecker`:**
   - Garantir em `TenantLimitChecker::consumption(PlanResource::Storage)` que apenas tamanhos positivos (`where('size', '>', 0)`) sejam somados e que o retorno nunca seja negativo (`max(0.0, ...)`), protegendo o verificador contra eventuais registros legados inválidos.
5. **Assegurar cobertura por testes automatizados (PHPUnit):**
   - Verificar que tentativas de enviar `'size' => 0`, `'size' => -500` ou `'size' => 1` no payload de `addFilesToRequest` para um arquivo real de `100 MB` no S3 utilizam o tamanho real do objeto no S3 (`100 MB`) tanto para bloquear o excesso de cota (apagando o objeto órfão) quanto para gravar o valor exato na coluna `files.size`.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Resolução Autoritativa Server-Side do Tamanho do Objeto no S3 (`requesting.blade.php`)

1. **Descarte de `$fileData['size']` Enviado pelo Cliente:**
   - O campo `size` presente nos itens do array `$files` recebido por `addFilesToRequest(int $requestId, array $files)` **não é confiável** e jamais deve ser usado para cálculo de quota (`guardPlanLimit`) nem para gravação na coluna `files.size`.
2. **Validação de Prefixo, Disco e Existência no Storage:**
   - Para cada item em `$files`:
     - O caminho `$path = (string) ($fileData['path'] ?? '')` deve ser não-vazio, iniciar obrigatoriamente com `requests/{tenantId}/` e não conter `'..'`.
     - O disco `$diskName` deve ser resolvido de forma segura (utilizando `config('filesystems.default', 's3')` ou validando se consta entre os discos permitidos `['s3', config('filesystems.default', 's3')]`).
     - O servidor deve verificar se o arquivo realmente existe no Storage (`Storage::disk($diskName)->exists($path)`) e obter seu tamanho real em bytes via:
       ```php
       $actualSize = (int) Storage::disk($diskName)->size($path);
       ```
     - Caso o objeto não exista no Storage, ocorra falha ao ler seus metadados ou `$actualSize <= 0` (arquivo vazio/inválido), o servidor deve remover o objeto caso exista e lançar `ValidationException::withMessages(['uploadedFiles' => 'Arquivo inválido, vazio ou não encontrado no armazenamento.'])`.
3. **Verificação de Quota (`PlanResource::Storage`) com Tamanho Real do S3:**
   - A soma total de bytes do lote (`$totalBytes`) deve ser calculada exclusivamente com os tamanhos reais obtidos do Storage (`$actualSize`):
     $$\text{incomingGb} = \frac{\sum \text{actualSize}}{1024^3}$$
   - Se `$this->guardPlanLimit(PlanResource::Storage, $incomingGb)` retornar `false`:
     - Todos os objetos validados do lote devem ser imediatamente excluídos do Storage (`Storage::disk($diskName)->delete($path)`) para impedir que um upload que burlou `getPreSignedUploadUrl` (declarando tamanho menor na pré-assinatura) permaneça ocupando espaço físico no bucket S3.
     - Nenhum registro deve ser criado na tabela `files`.
4. **Persistência Autoritativa na Tabela `files`:**
   - Ao criar cada registro em `$dentistRequest->files()->create(...)`:
     - `'size'` recebe obrigatoriamente `$actualSize` (obtido via `Storage::disk($diskName)->size($path)`).
     - `'original_size'` (informativo para taxa de compressão ZIP na UI) deve ser sanitizado para que nunca seja negativo ou menor que o tamanho compactado real:
       ```php
       $reportedOriginalSize = isset($fileData['original_size']) ? (int) $fileData['original_size'] : null;
       $originalSize = ($reportedOriginalSize !== null && $reportedOriginalSize >= $actualSize)
           ? $reportedOriginalSize
           : $actualSize;
       ```

### 3.2. RN-02: Blindagem Defensiva na Apuração de Consumo (`TenantLimitChecker.php`)

1. **Soma Estrita de Tamanhos Positivos:**
   - Em `TenantLimitChecker::consumption(PlanResource::Storage)`, a consulta SQL na tabela `files` deve ignorar eventuais valores nulos ou não-positivos (`where('size', '>', 0)`) e garantir limite inferior zero:
     ```php
     PlanResource::Storage => max(0.0, (float) File::query()->where('size', '>', 0)->sum('size') / (1024 ** 3)),
     ```
   - Isso assegura que nenhum registro inválido possa reduzir artificialmente o consumo acumulado do laboratório.

---

## 4. Detalhamento Técnico

### 4.1. `resources/views/components/service/requesting.blade.php`

Atualizar `addFilesToRequest(int $requestId, array $files): void` para inspecionar os objetos diretamente no disco antes de verificar o limite e persistir:

```php
public function addFilesToRequest(int $requestId, array $files): void
{
    $dentistRequest = DentistRequest::findOrFail($requestId);

    $this->authorize('update', $dentistRequest);

    if ($files === []) {
        return;
    }

    $tenantId = tenant('id') ?? 'default';
    $tenantPrefix = "requests/{$tenantId}/";
    $defaultDisk = config('filesystems.default', 's3');
    $allowedDisks = array_unique(['s3', $defaultDisk]);

    $verifiedFiles = [];
    $totalBytes = 0;

    foreach ($files as $fileData) {
        if (! is_array($fileData)) {
            throw ValidationException::withMessages([
                'uploadedFiles' => 'Arquivo inválido ou fora do escopo do laboratório.',
            ]);
        }

        $path = (string) ($fileData['path'] ?? '');
        $diskName = (string) ($fileData['disk'] ?? $defaultDisk);

        if (
            $path === ''
            || ! str_starts_with($path, $tenantPrefix)
            || str_contains($path, '..')
            || ! in_array($diskName, $allowedDisks, true)
        ) {
            throw ValidationException::withMessages([
                'uploadedFiles' => 'Arquivo inválido ou fora do escopo do laboratório.',
            ]);
        }

        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'uploadedFiles' => 'Arquivo inválido ou não encontrado no armazenamento.',
            ]);
        }

        $actualSize = (int) $disk->size($path);

        if ($actualSize <= 0) {
            $disk->delete($path);

            throw ValidationException::withMessages([
                'uploadedFiles' => 'O arquivo enviado está vazio ou é inválido.',
            ]);
        }

        $reportedOriginalSize = isset($fileData['original_size']) ? (int) $fileData['original_size'] : null;
        $originalSize = ($reportedOriginalSize !== null && $reportedOriginalSize >= $actualSize)
            ? $reportedOriginalSize
            : $actualSize;

        $verifiedFiles[] = [
            'name' => (string) ($fileData['name'] ?? basename($path)),
            'path' => $path,
            'disk' => $diskName,
            'size' => $actualSize,
            'original_size' => $originalSize,
            'extension' => $fileData['extension'] ?? null,
            'mime_type' => $fileData['mime_type'] ?? 'application/zip',
        ];

        $totalBytes += $actualSize;
    }

    $incomingGb = $totalBytes / (1024 ** 3);

    if (! $this->guardPlanLimit(PlanResource::Storage, $incomingGb)) {
        foreach ($verifiedFiles as $verifiedFile) {
            Storage::disk($verifiedFile['disk'])->delete($verifiedFile['path']);
        }

        return;
    }

    foreach ($verifiedFiles as $verifiedFile) {
        $dentistRequest->files()->create($verifiedFile);
    }
}
```

### 4.2. `app/Services/PlanLimits/TenantLimitChecker.php`

No método `consumption(PlanResource $resource): float`, filtrar apenas registros com `size > 0`:

```php
private function consumption(PlanResource $resource): float
{
    return match ($resource) {
        PlanResource::Users => (float) $this->activeTeamUsersQuery()->count(),
        PlanResource::Storage => max(0.0, (float) File::query()->where('size', '>', 0)->sum('size') / (1024 ** 3)),
        PlanResource::MonthlyItems => (float) RequestService::query()
            ->whereBetween('created_at', [now()->utc()->startOfMonth(), now()->utc()->endOfMonth()])
            ->count(),
    };
}
```

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Ignorar Tamanho Manipulado pelo Cliente (`size = 0`, `size < 0` ou `size = 1`) e Persistir Tamanho Real do S3:**
   - Quando um objeto real de `3 MB` (`3145728` bytes) está armazenado no S3 e o cliente chama `addFilesToRequest` informando `'size' => 0`, `'size' => -1024` ou `'size' => 1` no payload Livewire, o sistema consulta `Storage::disk('s3')->size($path)` e grava `size = 3145728` na tabela `files`.
2. **Prevenção de Bypass de Quota (`PlanResource::Storage`) com Tamanho Adulterado no Payload:**
   - Dado um laboratório com limite de `1 GB` (`storage_gb_limit = 1`) e `950 MB` já ocupados em `files`: se o usuário enviar um objeto real de `100 MB` para o S3 e chamar `addFilesToRequest` falsificando `'size' => 1` (1 byte), o servidor detecta os `100 MB` reais via `Storage::disk('s3')->size($path)`, bloqueia a operação via `guardPlanLimit`, não cria registro na tabela `files` e apaga o objeto de `100 MB` do S3.
3. **Rejeição de Caminhos Não Existentes ou Arquivos Vazios no S3:**
   - Chamar `addFilesToRequest` apontando para um `path` que ainda não foi enviado ao S3 (ou com `0 bytes` no S3) lança `ValidationException` na chave `uploadedFiles` e não cria registro em `files`.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas (Billing) — §7 Limites do Plano](../../domain/billing.md)
- [Domínio: Pedidos & Ordens de Serviço (Orders) — §3 Regras de Negócio Críticas](../../domain/orders.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Especificação Arquivada: Controle de Limite de Armazenamento, Autorização de Arquivos S3 e Rastreamento de Fotos de Demandas](../archived/2026-10-storage-limit-and-s3-upload-security.md)

