# Especificação: Entropia Criptográfica (CSPRNG) na Geração de Tokens de Convite e Primeiro Acesso

- **Identificador:** `2026-10-invitation-token-cryptographic-entropy`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-11-01
- **Módulos Afetados:**
  - Ações e Jobs de Convite e Primeiro Acesso (`app/Actions/SendInvitation.php`, `app/Jobs/ProvisionTenant.php`, `app/Actions/ResendFirstAccessLink.php`)
  - Modelos de Token de Convite (`app/Models/InvitationToken.php`)
  - Testes de Segurança, Provisionamento e Convites (`tests/Feature/ProvisionTenantTest.php`, `tests/Feature/ResendFirstAccessTest.php`, `tests/Feature/PartnerDentistSecurityAndInvitationTest.php`)

---

## 1. Contexto & Motivação

A demanda **2.4 ([MÉDIA] Baixa Entropia Criptográfica na Geração de Tokens de Convite)** identifica o uso de uma fonte de entropia previsível na geração de tokens de primeiro acesso e convites de usuários (`App\Models\InvitationToken`):

1. **Uso de `uniqid()` como Semente do Token:**
   - Em `app/Actions/SendInvitation.php` (linha 17), `app/Jobs/ProvisionTenant.php` (linha 134) e `app/Actions/ResendFirstAccessLink.php` (linha 37), os tokens de convite são gerados por meio da expressão `$token = Hash::make(uniqid());`.
   - A função nativa `uniqid()` do PHP é baseada exclusivamente no timestamp atual do sistema em microssegundos (`gettimeofday`) e **não** é um gerador de números pseudoaleatórios criptograficamente seguro (CSPRNG), conforme alerta a própria documentação oficial do PHP.
2. **Falsa Sensação de Segurança do `Hash::make()` sobre Entrada de Baixa Entropia:**
   - Aplicar `Hash::make()` (bcrypt) sobre o retorno de `uniqid()` produz uma string de aparência complexa (`$2y$12$...`), mas não aumenta o espaço de busca da semente original, que permanece restrito à janela temporal em microssegundos do momento em que o convite foi emitido.
   - Além disso, o uso de bcrypt para gerar tokens aleatórios introduz custo de CPU desnecessário (algoritmo propositalmente lento para senhas) e insere caracteres especiais (`$`, `/`, `.`) nos parâmetros de query string da URL assinada.
3. **Inconsistência com o Fluxo Central (`CentralUsersSeeder`):**
   - Enquanto os convites do painel central (`Database\Seeders\Production\CentralUsersSeeder`) já utilizam `Str::random(64)` (baseado em `random_bytes()`), os três fluxos de tenant (`SendInvitation`, `ProvisionTenant` e `ResendFirstAccessLink`) ainda utilizam `Hash::make(uniqid())`.

---

## 2. Objetivos

1. **Substituir `Hash::make(uniqid())` por `Str::random(64)`** em `SendInvitation.php`, `ProvisionTenant.php` e `ResendFirstAccessLink.php`, garantindo geração via CSPRNG (`random_bytes`) com 64 caracteres alfanuméricos URL-safe (~381 bits de entropia).
2. **Remover dependências desnecessárias da facade `Hash`** nos três arquivos afetados, eliminando o custo de hashing bcrypt na emissão de convites.
3. **Assegurar via testes automatizados** que todos os tokens persistidos em `invitation_tokens` possuem exatamente 64 caracteres alfanuméricos gerados por CSPRNG e permanecem integrados à validação de `/completar-cadastro`.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Geração Criptograficamente Segura de `InvitationToken`

1. **Padrão Único de Entropia (`Str::random(64)`):**
   - Todo token de convite ou primeiro acesso persistido na tabela `invitation_tokens` (`App\Models\InvitationToken`) deve ser gerado exclusivamente com `Str::random(64)` (que utiliza internamente o CSPRNG do PHP via `Random\Randomizer` / `random_bytes`).
   - É estritamente proibido o uso de `uniqid()`, `rand()`, `mt_rand()` ou `Hash::make(uniqid())` na geração de tokens de autenticação, convite ou primeiro acesso.
2. **Formato do Token:**
   - O token gerado deve ser uma string alfanumérica URL-safe de 64 caracteres (`/^[A-Za-z0-9]{64}$/`), livre de prefixos de hash (`$2y$`) e de caracteres reservados de URL.

### 3.2. RN-02: Preservação do Ciclo de Vida e Validação dos Convites

1. **Convite de Usuários do Laboratório (`SendInvitation::handle`):**
   - Remove tokens anteriores do usuário (`$user->invitationToken()->delete()`).
   - Gera `$token = Str::random(64)` com expiração em 48 horas (`now()->addHours(48)`).
   - Persiste o registro em `invitation_tokens` e envia `InviteNotification` contendo a URL assinada temporária (`complete-signup.show`).
2. **Provisionamento Inicial do Proprietário (`ProvisionTenant::handle`):**
   - Para laboratórios ainda não verificados cujo usuário proprietário possui `email_verified_at === null`, remove tokens anteriores (`$user->invitationToken()->delete()`), gera `$token = Str::random(64)` com expiração em 48 horas e envia `TenantWelcomeNotification` com a URL assinada temporária.
3. **Reenvio de Link de Primeiro Acesso (`ResendFirstAccessLink::handle`):**
   - Para laboratórios ainda não verificados (`! $tenant->isVerified()`), invalida tokens anteriores do proprietário (`$user->invitationToken()->delete()`), gera um novo `$token = Str::random(64)` válido por 48 horas e reenvia `TenantWelcomeNotification` com a nova URL assinada temporária.

---

## 4. Detalhamento Técnico

### 4.1. `app/Actions/SendInvitation.php`
1. Substituir `use Illuminate\Support\Facades\Hash;` por `use Illuminate\Support\Str;`.
2. Na linha 17, substituir:
   ```php
   $token = Hash::make(uniqid());
   ```
   por:
   ```php
   $token = Str::random(64);
   ```

### 4.2. `app/Jobs/ProvisionTenant.php`
1. Remover `use Illuminate\Support\Facades\Hash;` (`Illuminate\Support\Str` já está importado na classe).
2. Na linha 134, substituir:
   ```php
   $token = Hash::make(uniqid());
   ```
   por:
   ```php
   $token = Str::random(64);
   ```

### 4.3. `app/Actions/ResendFirstAccessLink.php`
1. Substituir `use Illuminate\Support\Facades\Hash;` por `use Illuminate\Support\Str;`.
2. Na linha 37, substituir:
   ```php
   $token = Hash::make(uniqid());
   ```
   por:
   ```php
   $token = Str::random(64);
   ```

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Entropia e Formato em `SendInvitation`:**
   - Ao executar `SendInvitation::handle($user)`, o token salvo em `invitation_tokens` e incluído na URL assinada possui 64 caracteres alfanuméricos (`/^[A-Za-z0-9]{64}$/`) e não inicia com `$2y$`.
2. **Entropia e Formato em `ProvisionTenant`:**
   - Ao executar `ProvisionTenant`, o token de primeiro acesso criado para o proprietário em `invitation_tokens` possui 64 caracteres alfanuméricos (`/^[A-Za-z0-9]{64}$/`), não utiliza bcrypt e permite concluir o primeiro acesso em `/completar-cadastro`.
3. **Entropia e Formato em `ResendFirstAccessLink`:**
   - Ao executar `ResendFirstAccessLink::handle($tenant)`, o token anterior é substituído por um novo token alfanumérico de 64 caracteres (`/^[A-Za-z0-9]{64}$/`) distinto do anterior e sincronizado com a URL assinada enviada por e-mail.

---

## 6. Documentação Relacionada

- [Domínio: Cobrança & Assinaturas — §6 Primeiro Acesso Protegido e Convites de Usuários](../../domain/billing.md)
- [Arquitetura: Acesso Central, Impersonation & Auditoria — §1.1 Convite e Conclusão de Cadastro de Sócios](../../architecture/central-admin-access.md)
- [Especificação Arquivada: Onboarding do Laboratório, Checkout Stripe e Primeiro Acesso Protegido](2026-10-tenant-onboarding-and-billing.md)
- [Especificação Arquivada: Proteção de Propriedades Livewire (`#[Locked]`) e Validação Cruzada de Token em `complete-registration`](2026-10-complete-registration-locked-properties-and-token-validation.md)

