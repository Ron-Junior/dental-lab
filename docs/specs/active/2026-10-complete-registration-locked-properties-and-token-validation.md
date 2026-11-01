# Especificação: Proteção de Propriedades Livewire (`#[Locked]`) e Validação Cruzada de Token em `complete-registration`

- **Identificador:** `2026-10-complete-registration-locked-properties-and-token-validation`
- **Status:** Ativo / Em Especificação
- **Data:** Outubro de 2026
- **Módulos Afetados:**
  - Autenticação e Convites Tenant (`resources/views/livewire/pages/auth/complete-registration.blade.php`)
  - Modelos Eloquent (`App\Models\InvitationToken`, `App\Models\User`)
  - Testes de Segurança (`tests/Feature/PartnerDentistSecurityAndInvitationTest.php`)

---

## 1. Contexto & Status Atual da Demanda (Análise de Gap)

A demanda **2.1 ([CRÍTICA] Account Takeover em `complete-registration` via Propriedades Livewire Desprotegidas)** aponta três pontos de vulnerabilidade na tela de conclusão de cadastro de usuários convidados e primeiro acesso do laboratório (`resources/views/livewire/pages/auth/complete-registration.blade.php`).

Parte desta demanda já foi endereçada na especificação arquivada [`2026-10-partner-dentist-privilege-escalation-and-invitation-security`](../archived/2026-10-partner-dentist-privilege-escalation-and-invitation-security.md), restando lacunas específicas de blindagem do estado Livewire e de consulta explícita por `user_id`.

### 1.1. O que JÁ FOI implementado
- **Isolamento do `$currentTenant->markAsVerified()`:** Em `completeRegistration()` (linhas 177-196), a marcação do laboratório como verificado já está restrita exclusivamente ao proprietário/admin inicial (`if ($isOwner && $currentTenant && ! $currentTenant->isVerified())`). Convidados (`Dentist` ou `LabPartner`) não marcam mais o tenant como verificado.
- **Revalidação de `InvitationToken` no `completeRegistration()`:** O método `completeRegistration()` já deixou de confiar apenas em `$this->isExpired` ou `User::where('email', $this->email)` e passou a reconsultar o `InvitationToken` verificando `whereNull('used_at')` e `where('expires_at', '>', now())`.

### 1.2. O que AINDA NÃO FOI implementado (Escopo desta Spec)
1. **Ausência do atributo `#[Locked]` nas propriedades públicas de controle (`$token`, `$email`, `$isExpired`):**
   - Em `resources/views/livewire/pages/auth/complete-registration.blade.php` (linhas 18-30), as propriedades públicas `$token`, `$email` e `$isExpired` continuam **sem o atributo `#[Locked]`** (`Livewire\Attributes\Locked`).
   - Como o middleware `signed` atua apenas na requisição `GET /completar-cadastro` inicial, qualquer requisição subsequente `POST /livewire/update` permite que o cliente tente mutar `$token`, `$email` ou `$isExpired` diretamente no payload JSON do Livewire.
2. **Busca de `User` por `$this->email` e filtro explícito `where('user_id', $user->id)` na consulta de `InvitationToken`:**
   - Tanto em `mount()` (linhas 78-83) quanto em `completeRegistration()` (linhas 156-161), a query busca o `InvitationToken` apenas por `where('token', $this->token)` e só depois compara o e-mail em PHP (`strcasecmp`), sem resolver primeiramente o `User` ativo pelo `$this->email` e sem filtrar `where('user_id', $user->id)` diretamente na consulta SQL do `InvitationToken`.
3. **Remoção de `wire:model="email"` em campo `readonly`:**
   - Na view (linha 266), o input de e-mail utiliza `<flux:input type="email" label="E-mail" wire:model="email" readonly />`. Ao aplicar `#[Locked]` em `$email`, o Livewire bloqueia qualquer tentativa de sincronização client-to-server na propriedade; portanto, o campo deve apenas exibir o valor bloqueado (ou não permitir two-way binding mutável pelo cliente).

---

## 2. Objetivos

1. Blindar as propriedades públicas `$token`, `$email` e `$isExpired` com o atributo `#[Locked]` do Livewire, garantindo em nível de framework que qualquer tentativa de adulteração no payload de `POST /livewire/update` lance `CannotUpdateLockedPropertyException`.
2. Padronizar a validação do convite tanto no `mount()` quanto no `completeRegistration()` para buscar primeiro o `User` pelo `$this->email` e consultar `InvitationToken::where('token', $this->token)->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->first()`.
3. Manter a garantia já implementada de que apenas o proprietário/administrador inicial (`isOwnerFirstAccess`) pode acionar `$currentTenant->markAsVerified()`.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Imutabilidade de Estado Client-Side via `#[Locked]`

1. As propriedades públicas abaixo em `resources/views/livewire/pages/auth/complete-registration.blade.php` devem declarar explicitamente o atributo `#[Locked]` (`use Livewire\Attributes\Locked;`):
   - `#[Url, Locked] public string $token = '';`
   - `#[Url, Locked, Validate('required|email|max:255')] public string $email = '';`
   - `#[Locked] public bool $isExpired = false;`
2. Nenhuma ação Livewire (`completeRegistration` ou `resendLink`) aceitará mutações vindas do frontend sobre `$token`, `$email` ou `$isExpired` após a hidratação inicial validada pelo middleware `signed`.

### 3.2. RN-02: Resolução Estrita de `User` + `InvitationToken` por `user_id`

1. **No `mount()`:**
   - Verificar inicialmente se o tenant já está verificado para o fluxo de 1º acesso do proprietário (`isOwnerFirstAccess`).
   - Buscar o usuário correspondente a `$this->email`:
     ```php
     $user = ! empty($this->email)
         ? User::query()->with('rule')->where('email', $this->email)->first()
         : null;
     ```
   - Caso `$user` exista e `$this->token` não seja vazio, buscar o token vinculado estritamente àquele `$user->id`:
     ```php
     $invitation = $user && ! empty($this->token)
         ? InvitationToken::query()
             ->where('token', $this->token)
             ->where('user_id', $user->id)
             ->whereNull('used_at')
             ->where('expires_at', '>', now())
             ->first()
         : null;
     ```
   - Se `$user === null` ou `$invitation === null`:
     - Caso `$user` exista, não seja o proprietário em 1º acesso e já possua cadastro concluído (`$user->email_verified_at !== null && $user->password !== null`), redirecionar para `route('login')` com toast informativo.
     - Caso seja o proprietário e o tenant já esteja verificado (`$currentTenant->isVerified()`), redirecionar para `route('login')` com toast de aviso.
     - Nos demais casos (token expirado, token pertencente a outro `user_id`, token inexistente ou e-mail inválido), definir `$this->isExpired = true`.
   - Se válido, sincronizar `$this->email = $user->email`.

2. **No `completeRegistration()`:**
   - Caso `$this->isExpired` seja `true`, interromper imediatamente com toast de aviso.
   - Executar `$this->validate()`.
   - Buscar novamente o `User` ativo no banco a partir de `$this->email` (propriedade protegida por `#[Locked]`):
     ```php
     $user = User::query()->with('rule')->where('email', $this->email)->first();
     ```
   - Consultar o `InvitationToken` filtrando obrigatoriamente por `token`, `user_id`, `used_at` nulo e `expires_at` futuro:
     ```php
     $invitation = $user
         ? InvitationToken::query()
             ->where('token', $this->token)
             ->where('user_id', $user->id)
             ->whereNull('used_at')
             ->where('expires_at', '>', now())
             ->first()
         : null;
     ```
   - Se `! $user || ! $invitation`, definir `$this->isExpired = true`, exibir toast de erro (`"Token de convite inválido ou expirado."`) e abortar a operação sem alterar nenhuma senha.

### 3.3. RN-03: Preservação do Fluxo de Verificação do Tenant

1. Ao concluir o cadastro dentro da transação `DB::transaction`:
   - Atualizar a senha do `$user`, marcar `$user->markEmailAsVerified()`, registrar `used_at` e deletar os tokens de convite daquele usuário (`$user->invitationToken()->delete()`).
   - Executar `$currentTenant->markAsVerified()` **somente** se `$isOwner && $currentTenant && ! $currentTenant->isVerified()`.

---

## 4. Detalhamento Técnico

### 4.1. Alterações em `resources/views/livewire/pages/auth/complete-registration.blade.php`

1. Importar `Livewire\Attributes\Locked`.
2. Adicionar `#[Locked]` às propriedades `$token`, `$email` e `$isExpired`.
3. Atualizar `mount()` e `completeRegistration()` para resolver `$user = User::query()->with('rule')->where('email', $this->email)->first()` e filtrar `InvitationToken` com `->where('token', $this->token)->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->first()`.
4. Na seção Blade do formulário (linha 266), manter o campo de e-mail somente leitura sem permitir mutação pelo cliente (`:value="$email"` / `readonly`).

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de Mutação de Propriedades `#[Locked]` (`$email`, `$token`, `$isExpired`):**
   - Tentar alterar `$email`, `$token` ou `$isExpired` via chamada Livewire (`->set('email', ...)`, `->set('token', ...)` ou `->set('isExpired', false)`) após o `mount()` deve lançar `Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException`.
2. **Validação Cruzada `token` + `user_id` no `mount()` e no `completeRegistration()`:**
   - Acessar `complete-registration` passando na query string o `token` válido de um convidado (`parceiro@lab.test`) combinado com o `email` de outro usuário (`proprietario@lab.test`) resulta em `isExpired = true`, pois a busca `InvitationToken::where('token', $token)->where('user_id', $owner->id)` não retorna registro.
   - Se o token expirar ou for removido entre o `mount()` e o envio de `completeRegistration()`, a revalidação no banco impede a troca de senha e marca `isExpired = true`.
3. **Isolamento da Verificação do Tenant:**
   - Quando um `Dentist` ou `LabPartner` conclui o cadastro antes do proprietário, `tenants.verified_at` permanece `null`.
   - Quando o proprietário (`owner_email` / `Rules::Lab` / `Rules::Owner`) conclui o cadastro, `tenants.verified_at` é preenchido.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas — §6 Primeiro Acesso Protegido e Convites de Usuários](../../domain/billing.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Especificação Arquivada: Prevenção de Escalação de Privilégio na Gestão de Parceiros/Dentistas e Isolamento de Convites](../archived/2026-10-partner-dentist-privilege-escalation-and-invitation-security.md)
- [Especificação Arquivada: Onboarding do Laboratório, Checkout Stripe e Primeiro Acesso Protegido](../archived/2026-10-tenant-onboarding-and-billing.md)

