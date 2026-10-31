# Especificação: Prevenção de Escalação de Privilégio na Gestão de Parceiros/Dentistas e Isolamento de Convites

- **Identificador:** `2026-10-partner-dentist-privilege-escalation-and-invitation-security`
- **Status:** Ativo / Em Especificação
- **Data:** Outubro de 2026
- **Módulos Afetados:** Gestão de Parceiros (`resources/views/components/partners/store.blade.php`, `Partner`), Gestão de Dentistas (`resources/views/components/dentists/store.blade.php`, `Dentist`), Autenticação e Convites Tenant (`resources/views/livewire/pages/auth/complete-registration.blade.php`, `SendInvitation`, `ResendFirstAccessLink`, `InvitationToken`, `User`), Onboarding (`Tenant`).

---

## 1. Contexto & Motivação

Foram identificadas três vulnerabilidades críticas de segurança, integridade cadastral e controle de acesso nos fluxos de criação/edição de parceiros e dentistas e na tela de conclusão de cadastro:

1. **Bloqueio de edição e validação estática de `email` (`partners/store.blade.php` e `dentists/store.blade.php`):**
   - A validação `#[Validate('required|email|max:255|unique:users,email')]` é estática e **não ignora o próprio `user_id`** vinculado ao parceiro ou dentista em edição.
   - Consequentemente, ao editar o nome ou telefone de um parceiro/dentista existente sem alterar seu e-mail, a validação falha informando que o e-mail já está em uso.
2. **Escalação/sobrescrita de privilégio e duplicação de registros via `User::updateOrCreate(['email' => $this->email], ...)`:**
   - Na persistência de `partners/store.blade.php` e `dentists/store.blade.php`, o uso de `User::updateOrCreate(['email' => $this->email], ['name' => $this->name, 'rule_id' => $rule->id])` sobrescreve cegamente o `rule_id` de qualquer usuário existente que possua aquele e-mail (por exemplo, rebaixando/alterando a role do próprio `owner`/`lab` ou convertendo um dentista em parceiro).
   - Além disso, se na edição o administrador alterar o e-mail para um endereço novo, `updateOrCreate` cria um **novo** `User` em vez de atualizar o `$partner->user` / `$dentist->user` existente, deixando o usuário anterior órfão ou desatualizado. E se `updateOrCreate` atingir um usuário existente durante uma criação (caso a validação fosse contornada ou em condição de corrida), cria registros duplicados de `Partner`/`Dentist`.
3. **Account Takeover (IDOR por manipulação de `$email`) e bloqueio global de convites em `complete-registration.blade.php`:**
   - **Sequestro de conta (Account Takeover):** Em `complete-registration.blade.php`, o `mount()` verifica a existência de um `InvitationToken` válido apenas por `where('token', $this->token)`, mas o `completeRegistration()` busca o usuário alvo por `User::where('email', $this->email)->firstOrFail()` (onde `$email` é uma propriedade pública `#[Url]`) **sem validar se o `$this->token` pertence àquele `$user->id`** e sem revalidar o token no POST. Um convidado (`partner` ou `dentist`) detentor de um token válido pode alterar a propriedade `$email` no payload Livewire para o `owner_email` do laboratório e redefinir a senha do proprietário, autenticando-se imediatamente como dono (`Auth::login($user)`).
   - **Conflito entre verificação do Tenant (`tenant()->isVerified()`) e convites de Parceiros/Dentistas:**
     - As linhas 38 e 89 de `complete-registration.blade.php` redirecionam para `login` sempre que `$currentTenant->isVerified()` for `true`. Porém, `SendInvitation` envia parceiros e dentistas para a mesma rota (`complete-signup.show`). Assim, após o dono do laboratório realizar seu 1º acesso (`verified_at !== null`), **todos os convites futuros de parceiros e dentistas ficam permanentemente bloqueados**!
     - Inversamente, se o laboratório ainda não tiver sido verificado pelo dono e um parceiro ou dentista aceitar um convite antes, a linha 105 (`$currentTenant->markAsVerified()`) marca indevidamente o `Tenant` inteiro como verificado, **bloqueando o link de 1º acesso do próprio dono do laboratório**.

---

## 2. Objetivos

- Permitir a edição normal de parceiros e dentistas ignorando o `user_id` vinculado na regra `unique:users,email`.
- Eliminar o uso de `User::updateOrCreate` por e-mail nos fluxos de criação e edição de `Partner` e `Dentist`, separando explicitamente a **criação** (`User::create` + perfil + convite) da **atualização** (atualizar o `User` já vinculado ao `Partner`/`Dentist`, mantendo sua `rule_id` intacta).
- Garantir na tela `complete-registration.blade.php` que o usuário cuja senha será definida seja resolvido **exclusivamente a partir do relacionamento do `InvitationToken` válido** (`$invitation->user`), impedindo qualquer manipulação do campo `$email` no request Livewire.
- Desacoplar a trava e a marcação de verificação do laboratório (`$currentTenant->isVerified()` / `$currentTenant->markAsVerified()`) dos convites de parceiros e dentistas, aplicando-as **estritamente** quando o convite pertencer ao proprietário do laboratório (`strcasecmp($user->email, $currentTenant->owner_email) === 0`).

---

## 3. Regras de Negócio

### 3.1. RN-01: Criação e Edição Segura de Parceiros e Dentistas (`partners/store.blade.php` e `dentists/store.blade.php`)

1. **Validação Dinâmica de E-mail Único (`Rule::unique`):**
   - Na criação (`$this->partner === null` ou `$this->dentistId === null`), validar `'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')]`.
   - Na edição, ignorar estritamente o `user_id` já pertencente ao registro sendo editado:
     ```php
     Rule::unique('users', 'email')->ignore($editingUserId)
     ```
   - Nenhum e-mail pertencente a outro usuário do schema (seja `owner`, `lab`, `lab_manager`, outro `lab_partner` ou outro `dentist`) pode ser aceito.
2. **Proibição de `User::updateOrCreate` por E-mail:**
   - **Fluxo de Criação:**
     - Verificar o limite do plano (`guardPlanLimit(PlanResource::Users)`) quando aplicável (criação de parceiro).
     - Obter a `Rule` específica (`Rules::LabPartner` para parceiro, `Rules::Dentist` para dentista).
     - Criar um **novo** registro `User::create(['name' => $this->name, 'email' => $this->email, 'rule_id' => $rule->id])`.
     - Criar o registro `Partner::create(['user_id' => $user->id, 'phone' => $this->phone])` ou `Dentist::create(['user_id' => $user->id, 'phone' => $this->phone])`.
     - Disparar o envio de convite `SendInvitation::handle($user)`.
   - **Fluxo de Edição:**
     - Carregar o `Partner` ou `Dentist` existente com seu relacionamento `user` (`findOrFail`).
     - Atualizar diretamente o usuário vinculado (`$entity->user->update(['name' => $this->name, 'email' => $this->email])`), **sem alterar `rule_id`** e **sem criar novo `User`**.
     - Atualizar os dados específicos do perfil (`$entity->update(['phone' => $this->phone])`).
     - Caso o e-mail de um usuário que ainda não concluiu o cadastro (`email_verified_at === null` e `password === null`) seja corrigido na edição, reenviar o convite (`SendInvitation::handle($entity->user)`) para o novo endereço.
3. **Limpeza de Estado ao Abrir o Modal de Criação:**
   - Garantir que o estado de edição (`$this->partner` / `$this->dentistId`) seja devidamente resetado ao fechar ou salvar o modal, evitando que uma criação subsequente reutilize acidentalmente o ID de uma edição anterior.

### 3.2. RN-02: Vinculação Criptográfica entre `InvitationToken` e `User` em `complete-registration.blade.php`

1. **Resolução do Usuário pelo Token (Proibição de Busca por `$this->email` do Payload):**
   - Tanto em `mount()` quanto em `completeRegistration()`, o token de convite deve ser consultado no banco com vínculo estrito ao usuário:
     ```php
     $invitation = InvitationToken::query()
         ->with('user.rule')
         ->where('token', $this->token)
         ->whereNull('used_at')
         ->where('expires_at', '>', now())
         ->first();
     ```
   - Se `$invitation === null` ou `$invitation->user === null`, marcar `$this->isExpired = true` e abortar a operação sem alterar nenhuma senha.
2. **Validação de Correspondência de E-mail:**
   - O e-mail exibido no formulário deve ser sincronizado com `$invitation->user->email` (ou, se `$this->email` for informado na URL e divergir de `$invitation->user->email`, o token deve ser considerado inválido/expirado ou rejeitado com erro 403/expirado).
   - Em `completeRegistration()`, o usuário cuja senha será definida **deve ser obrigatoriamente `$user = $invitation->user`**, nunca `User::where('email', $this->email)->first()`. Qualquer tentativa de adulterar a propriedade pública `$email` no request Livewire não surtirá efeito sobre outros usuários e, caso divirja de `$invitation->user->email`, deve abortar imediatamente com HTTP 403 (`abort(403)`).
3. **Invalidação do Token no Uso:**
   - Ao concluir o cadastro em `completeRegistration()`, além de salvar o hash da senha e marcar `$user->markEmailAsVerified()`, remover/invalidar o token utilizado (`$user->invitationToken()->delete()`).

### 3.3. RN-03: Isolamento entre Primeiro Acesso do Dono (`owner_email`) e Convites de Parceiros/Dentistas

1. **Identificação do Fluxo de Primeiro Acesso do Proprietário vs. Convite de Membro/Parceiro/Dentista:**
   - Um acesso a `/completar-cadastro` refere-se ao **Primeiro Acesso do Proprietário** somente quando:
     - O e-mail do usuário do convite (ou da URL quando o token já expirou) corresponde ao `owner_email` do tenant atual (`$currentTenant && strcasecmp($targetEmail, (string) $currentTenant->owner_email) === 0`), **ou** o usuário possui a role de laboratório/proprietário (`Rules::Lab` / `Rules::Owner`).
   - Convites destinados a `Rules::LabPartner`, `Rules::Dentist` ou outros membros convidados (`email !== $currentTenant->owner_email`) são **convites de usuário**, independentes da verificação inicial do `Tenant`.
2. **Regra de Bloqueio por `$currentTenant->isVerified()`:**
   - Em `mount()` e em `completeRegistration()`:
     - Se o acesso for do **proprietário** (`isOwnerFirstAccess`) e `$currentTenant->isVerified()` for `true`, bloquear o acesso com toast de aviso (`"O primeiro acesso deste laboratório já foi realizado."`) e redirecionar para `route('login')`.
     - Se o acesso for de um **parceiro ou dentista convidado** (com `InvitationToken` válido pertencente àquele usuário), **permitir** a definição de senha mesmo que `$currentTenant->isVerified()` já seja `true`.
     - Caso um usuário convidado (`email !== owner_email`) tente acessar um link cujo token já foi utilizado (`used_at !== null` ou token deletado) e o usuário já possua senha/e-mail verificado (`$user->email_verified_at !== null`), redirecionar ou exibir aviso de que o convite já foi utilizado, orientando a acessar a tela de login.
3. **Regra de Marcação de `$currentTenant->markAsVerified()`:**
   - Em `completeRegistration()`, `$currentTenant->markAsVerified()` só deve ser executado **se e somente se** o usuário que está concluindo o cadastro for o proprietário do laboratório (`$currentTenant && ! $currentTenant->isVerified() && strcasecmp($user->email, (string) $currentTenant->owner_email) === 0`).
   - Quando um `Partner` ou `Dentist` aceita seu convite, apenas o próprio `$user->markEmailAsVerified()` é executado; `tenants.verified_at` no banco central **não** deve ser alterado.
4. **Reenvio de Link na Tela de Expiração (`resendLink`):**
   - O botão de auto-atendimento **"Reenviar novo link para meu e-mail"** (`ResendFirstAccessLink`) aplica-se ao proprietário do laboratório (`owner_email`) quando o tenant ainda não foi verificado (`! $currentTenant->isVerified()`). Para convites expirados de parceiros/dentistas em laboratórios já verificados, a tela deve orientar o convidado a solicitar um novo convite à administração do laboratório ou ir para a tela de login.

---

## 4. Critérios de Aceite (Testes Automatizados)

1. **Edição de Parceiro e Dentista Mantendo o Mesmo E-mail:**
   - Editar o nome ou telefone de um `Partner` ou `Dentist` mantendo seu e-mail atual salva as alterações com sucesso no `User` e no `Partner`/`Dentist`, sem erro de validação `unique:users,email` e sem criar novos registros.
2. **Edição de Parceiro e Dentista Alterando o E-mail:**
   - Editar o e-mail de um `Partner` ou `Dentist` para um endereço livre atualiza o `email` do próprio `User` vinculado (`$partner->user_id`), sem criar um segundo `User`.
3. **Bloqueio de Escalação de Privilégio / Sobrescrita de Role:**
   - Tentar criar ou editar um `Partner` ou `Dentist` informando o e-mail do proprietário do laboratório (`Rules::Lab` / `Rules::Owner`) ou de outro usuário já cadastrado falha na validação (`unique:users,email`), preserva o `rule_id` original do usuário existente intacto e não cria registros duplicados de `Partner` ou `Dentist`.
4. **Prevenção de Account Takeover em `complete-registration`:**
   - Um parceiro ou dentista com um `InvitationToken` válido que altere a propriedade `$email` no componente `pages.auth.complete-registration` para o `owner_email` do laboratório tem a operação bloqueada (403) ou restrita exclusivamente ao usuário dono do token; a senha do proprietário (`owner`) permanece inalterada.
   - Submeter `completeRegistration` com um token inválido, expirado ou pertencente a outro usuário nunca altera a senha do e-mail informado.
5. **Convites de Parceiros e Dentistas Funcionam Após Verificação do Tenant:**
   - Em um laboratório já verificado pelo dono (`$tenant->verified_at !== null`), um novo `Partner` ou `Dentist` convidado via `SendInvitation` consegue acessar `/completar-cadastro`, definir sua senha, ter seu `email_verified_at` preenchido e ser autenticado normalmente, sem ser bloqueado pelo redirect de `isVerified()`.
6. **Convite de Parceiro/Dentista Não Verifica o Tenant Prematuramente:**
   - Caso um `Partner` ou `Dentist` conclua seu cadastro enquanto `$tenant->verified_at === null`, o `verified_at` do `Tenant` permanece `null`, preservando o fluxo de 1º acesso do proprietário do laboratório.

---

## 5. Documentação Relacionada

- [Especificação Ativa: Onboarding do Laboratório, Checkout Stripe e Primeiro Acesso Protegido](2026-10-tenant-onboarding-and-billing.md)
- [Domínio: Cobrança & Assinaturas (Billing)](../../domain/billing.md)
- [Domínio: Pedidos & Ordens de Serviço (Orders)](../../domain/orders.md)

