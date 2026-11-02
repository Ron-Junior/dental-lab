# Especificação: Prevenção de Account Takeover de Administradores Centrais em `register-invite`

- **Identificador:** `2026-10-central-register-invite-account-takeover-protection`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-31
- **Módulos Afetados:**
  - Autenticação e Convites Centrais (`resources/views/livewire/pages/auth/register-invite.blade.php`, `routes/web.php`)
  - Modelos e Migrações Centrais (`App\Models\CentralUser`, `App\Models\CentralInvitationToken`, `database/migrations/`)
  - Notificações e Seeders Centrais (`App\Notifications\CentralUserInviteNotification`, `Database\Seeders\Production\CentralUsersSeeder`)
  - Testes de Segurança (`tests/Feature/Auth/CentralAuthenticationTest.php`)

---

## 1. Contexto & Motivação

A demanda **2.2 ([CRÍTICA] Account Takeover de Administradores Centrais em `register-invite`)** identifica uma vulnerabilidade crítica de escalação de privilégio e sequestro de conta global no componente `resources/views/livewire/pages/auth/register-invite.blade.php` (rota `/convite/registrar`, `invite.registration`):

1. **Ausência de `#[Locked]` na propriedade `$email`:**
   - A propriedade pública `public string $email = ''` utiliza `#[Url]` sem o atributo `#[Locked]` (`Livewire\Attributes\Locked`).
   - Como o middleware `signed` valida a assinatura da URL apenas na requisição `GET /convite/registrar` inicial, um atacante pode interceptar a requisição subsequente `POST /livewire/update` e alterar o valor de `$email` no payload JSON para o e-mail de qualquer `CentralUser` existente.
2. **Ausência de Token Único de Convite com Expiração no Banco Central:**
   - Atualmente, o convite depende exclusivamente da URL assinada (`temporarySignedRoute`), sem persistir um token de uso único vinculado ao `CentralUser` no banco central.
   - Consequentemente, um link de convite dentro da janela de validade da URL pode ser reutilizado múltiplas vezes, não pode ser revogado no servidor e não valida vínculo criptográfico entre token e usuário no momento do `POST /livewire/update`.
3. **Sobrescrita Irrestrita de `CentralUser` já Cadastrado:**
   - O método `register()` executa diretamente `CentralUser::where('email', $this->email)->update(['name' => ..., 'password' => ...])` sem verificar se o administrador central já possui senha cadastrada (`password !== null`) ou cadastro concluído (`email_verified_at !== null`).
   - Isso permite que qualquer pessoa com um link de convite (ou manipulando `$email` no Livewire) sobrescreva a senha de qualquer sócio/super administrador (`CentralUser`) e obtenha acesso administrativo global no guard `central`.

---

## 2. Objetivos

1. Blindar as propriedades públicas de contexto e controle em `register-invite.blade.php` (`$email`, `$token` e `$isExpired`) com o atributo `#[Locked]` (`Livewire\Attributes\Locked`), impedindo qualquer mutação client-side em requisições `POST /livewire/update`.
2. Persistir tokens de convite de uso único com expiração no banco central (`central_invitation_tokens` / `App\Models\CentralInvitationToken`), vinculados estritamente ao `central_user_id`.
3. Validar o token de convite e o estado cadastral do `CentralUser` tanto no `mount()` quanto no `register()`, impedindo a sobrescrita de senha caso o `CentralUser` já tenha finalizado o cadastro (`password !== null` ou `email_verified_at !== null`) e invalidando o token imediatamente após o primeiro uso.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Imutabilidade de Propriedades Sensíveis via `#[Locked]`

1. Em `resources/views/livewire/pages/auth/register-invite.blade.php`, todas as propriedades públicas que controlam a identidade do convidado, o token e o estado de expiração devem utilizar obrigatoriamente o atributo `#[Locked]`:
   - `#[Url, Locked] public string $token = '';`
   - `#[Url, Locked, Validate('required|email|max:255')] public string $email = '';`
   - `#[Locked] public bool $isExpired = false;`
2. Qualquer tentativa de modificar `$email`, `$token` ou `$isExpired` via payload Livewire (`POST /livewire/update`) deve ser rejeitada pelo framework com `CannotUpdateLockedPropertyException`.
3. No template Blade, o campo de e-mail deve ser somente leitura (`:value="$email" readonly disabled`), sem `wire:model="email"`, evitando tentativas de sincronização client-to-server sobre propriedade bloqueada.

### 3.2. RN-02: Token de Convite Central de Uso Único com Expiração (`CentralInvitationToken`)

1. **Persistência no Banco Central:**
   - Criar a tabela central `central_invitation_tokens` (migração em `database/migrations/`) e o model `App\Models\CentralInvitationToken` utilizando a trait `CentralConnection`:
     - `id`: chave primária.
     - `central_user_id`: `foreignId('central_user_id')->constrained('central_users')->cascadeOnDelete()`.
     - `token`: `string('token')->unique()`.
     - `used_at`: `timestamp('used_at')->nullable()`.
     - `expires_at`: `timestamp('expires_at')`.
     - `timestamps()`.
   - O model `CentralUser` deve expor o relacionamento `invitationToken(): HasOne` para `CentralInvitationToken`.
2. **Emissão do Convite Central:**
   - Ao emitir um convite para um `CentralUser` (ex.: em `CentralUsersSeeder` ou action/método de convite central):
     - Remover eventuais tokens anteriores do usuário (`$centralUser->invitationToken()->delete()`).
     - Gerar um token único imprevisível e persistir em `central_invitation_tokens` com expiração de **24 horas** (`now()->addHours(24)`).
     - Gerar a URL assinada `URL::temporarySignedRoute('invite.registration', $expiresAt, ['email' => $centralUser->email, 'token' => $token])` e enviá-la via `CentralUserInviteNotification`.

### 3.3. RN-03: Bloqueio de Sobrescrita de `CentralUser` já Cadastrado e Validação Cruzada (`mount` e `register`)

1. **Verificação de Cadastro Já Concluído:**
   - Um `CentralUser` é considerado com cadastro já finalizado quando `$user->password !== null` ou `$user->email_verified_at !== null`.
   - Em **nenhuma hipótese** o fluxo `register-invite` pode atualizar `name` ou `password` de um `CentralUser` que já possua senha cadastrada.
2. **Validação no `mount()`:**
   - Buscar o `CentralUser` pelo `$this->email`:
     ```php
     $user = ! empty($this->email)
         ? CentralUser::query()->where('email', $this->email)->first()
         : null;
     ```
   - Se `$user` existir e já tiver finalizado o cadastro (`$user->password !== null || $user->email_verified_at !== null`), exibir toast de aviso (`"Este cadastro já foi concluído anteriormente."`) e redirecionar imediatamente para `route('central.login')`.
   - Consultar o token de convite no banco central exigindo correspondência estrita com `central_user_id`, `used_at` nulo e `expires_at` futuro:
     ```php
     $invitation = $user && ! empty($this->token)
         ? CentralInvitationToken::query()
             ->where('token', $this->token)
             ->where('central_user_id', $user->id)
             ->whereNull('used_at')
             ->where('expires_at', '>', now())
             ->first()
         : null;
     ```
   - Se `! $user || ! $invitation`, definir `$this->isExpired = true` e interromper a montagem do formulário.
   - Se válido, sincronizar `$this->email = $user->email` e pré-preencher `$this->name = $user->name` quando disponível.
3. **Revalidação Estrita e Consumo Único no `register()`:**
   - Se `$this->isExpired` for `true`, abortar imediatamente com toast de erro/aviso.
   - Executar `$fields = $this->validate()`.
   - Reconsultar o `CentralUser` no banco a partir de `$this->email` (protegido por `#[Locked]`):
     - Se `$user === null`, marcar `$this->isExpired = true` e abortar.
     - Se `$user->password !== null || $user->email_verified_at !== null`, bloquear a operação com toast de erro (`"Este usuário já concluiu o cadastro."`) e redirecionar para `route('central.login')` **sem sobrescrever a senha**.
   - Reconsultar o `CentralInvitationToken` filtrando por `token`, `central_user_id = $user->id`, `whereNull('used_at')` e `where('expires_at', '>', now())`:
     - Se `! $invitation`, marcar `$this->isExpired = true`, exibir toast de erro (`"Token de convite inválido ou expirado."`) e abortar a operação sem alterar o banco.
   - Dentro de `DB::transaction`:
     - Atualizar `$user` preenchendo `name`, `password = Hash::make($fields['password'])` e `email_verified_at = now()`.
     - Marcar o token como utilizado (`$invitation->update(['used_at' => now()])`) e remover os tokens pendentes do usuário (`$user->invitationToken()->delete()`).
   - Redirecionar para `route('central.login')` (ou autenticar/redirecionar conforme fluxo central) com toast de sucesso.

---

## 4. Detalhamento Técnico

### 4.1. Banco de Dados Central e Modelos
1. Criar migração central `database/migrations/2026_10_31_231000_create_central_invitation_tokens_table.php` com `central_user_id`, `token` (unique), `used_at` (nullable), `expires_at` e `timestamps`.
2. Criar model `App\Models\CentralInvitationToken` com `CentralConnection`, `#[Fillable(['central_user_id', 'token', 'expires_at', 'used_at'])]`, casts `datetime` para `expires_at` e `used_at`, e relacionamento `centralUser(): BelongsTo`.
3. Adicionar relacionamento `invitationToken(): HasOne` em `App\Models\CentralUser`.
4. Atualizar `Database\Seeders\Production\CentralUsersSeeder` para gerar e persistir o `CentralInvitationToken` de 24h para cada sócio convidado e incluir `token` nos parâmetros de `URL::temporarySignedRoute('invite.registration', ...)`.

### 4.2. Componente Livewire `resources/views/livewire/pages/auth/register-invite.blade.php`
1. Importar `Livewire\Attributes\Locked`, `App\Models\CentralInvitationToken` e `Illuminate\Support\Facades\DB`.
2. Declarar `#[Url, Locked]` em `$token` e `$email`, e `#[Locked]` em `$isExpired`.
3. Implementar `mount()` e atualizar `register()` seguindo as regras RN-01, RN-02 e RN-03.
4. Adicionar na view o estado visual de link expirado/inválido (`@if ($isExpired)`) com botão de retorno para `route('central.login')`, e substituir `wire:model="email"` por `:value="$email" readonly disabled`.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de Mutação de Propriedades `#[Locked]` (`$email`, `$token`, `$isExpired`):**
   - Tentar alterar `$email`, `$token` ou `$isExpired` via chamada Livewire (`->set('email', 'outro-socio@example.com')`, `->set('token', ...)` ou `->set('isExpired', false)`) deve lançar `Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException`.
2. **Prevenção de Sobrescrita de `CentralUser` que já Possui Senha:**
   - Caso um `CentralUser` já possua `password` preenchida (ou `email_verified_at !== null`), tanto o acesso via `mount()` quanto a submissão de `register()` (mesmo se houvesse um token válido no banco) são bloqueados, redirecionam para `central.login` e **jamais sobrescrevem** a senha ou o nome existentes do administrador central.
3. **Validação de Token de Uso Único e Expiração no Banco Central:**
   - Submeter `register()` sem token, com token inexistente, com token expirado (`expires_at <= now()`), com token já utilizado (`used_at !== null`) ou com token pertencente a outro `CentralUser` falha com `isExpired = true` e não altera nenhuma senha.
4. **Fluxo Completo e Consumo Único do Token:**
   - Um `CentralUser` recém-convidado (`password === null`) com `CentralInvitationToken` válido consegue concluir o cadastro em `register-invite`, tem sua senha e `email_verified_at` gravados, e o token é invalidado/removido no banco central, impedindo que o mesmo link seja utilizado uma segunda vez.

---

## 6. Documentação Relacionada

- [Arquitetura: Acesso Central (Sócios), Impersonation & Auditoria](../../architecture/central-admin-access.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Especificação Arquivada: Proteção de Propriedades Livewire (`#[Locked]`) e Validação Cruzada de Token em `complete-registration`](./2026-10-complete-registration-locked-properties-and-token-validation.md)

