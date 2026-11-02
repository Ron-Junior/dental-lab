# Especificação: Validação Antecipada de Tenant e Expiração no Token de Impersonation Antes de Mutar a Sessão

- **Identificador:** `2026-11-impersonation-token-tenant-validation-before-session`
- **Status:** Concluído / Implementado
- **Data:** Novembro de 2026
- **Data de Conclusão:** 2026-11-02
- **Módulos Afetados:**
  - Rotas de Impersonação do Tenant (`routes/tenant.php`)
  - Middleware de Elegibilidade Operacional (`app/Http/Middleware/EnsureTenantIsOperational.php`)
  - Testes de Painel Central e Ciclo de Vida Operacional (`tests/Feature/SuperAdminPanelTest.php`, `tests/Feature/TenantAccessEnforcementAndLifecycleTest.php`)

---

## 1. Contexto & Motivação

A demanda **3.5 ([MÉDIA] Validação Tardio de Tenant no Token de Impersonation)** identifica uma vulnerabilidade de contaminação prematura de sessão e possível bypass de bloqueio operacional (`EnsureTenantIsOperational`) na rota de consumo de tokens de impersonação em `routes/tenant.php`:

1. **Mutação da Sessão Antes da Validação de Vínculo com o Tenant Atual (`routes/tenant.php`, linhas 33–38):**
   - Atualmente, a rota `GET /impersonate/{token}` (`tenant.impersonate`) executa:
     ```php
     Route::get('/impersonate/{token}', function (string $tenant, string $token) {
         $tokenModel = ImpersonationToken::findOrFail($token);
         session()->put('impersonated_by_central', true);

         return UserImpersonation::makeResponse($tokenModel);
     })->name('tenant.impersonate');
     ```
   - A verificação de que `$tokenModel->tenant_id` pertence ao laboratório do subdomínio atual (`tenant('id')`) e de que o token não expirou (`TTL` de 60 segundos) só ocorre **dentro** de `UserImpersonation::makeResponse($tokenModel)`.
   - Quando `UserImpersonation::makeResponse($tokenModel)` detecta divergência de tenant (`(string) $token->tenant_id !== (string) tenant()->getTenantKey()`) ou expiração do token e lança `abort(403)`, a chave `session('impersonated_by_central') = true` **já foi gravada na sessão** do usuário e é persistida pelo middleware `StartSession` ao finalizar a resposta HTTP 403.

2. **Impacto de Segurança (Bypass do Middleware `EnsureTenantIsOperational`):**
   - O middleware `EnsureTenantIsOperational` (`app/Http/Middleware/EnsureTenantIsOperational.php`, linha 19) concede bypass irrestrito a todas as verificações de status (`PendingPayment`, `Provisioning`, `Failed`) e de adimplência financeira (`Overdue`, `Cancelled`, `Trial` vencido ou `Exemption` encerrada) sempre que `session('impersonated_by_central') === true`.
   - Consequentemente, se qualquer requisição no subdomínio de um laboratório bloqueado/inadimplente (Tenant A) acionar `/impersonate/{token}` utilizando um token pertencente a outro laboratório (Tenant B) — ou um token já expirado que ainda não tenha sido purgado da tabela `tenant_user_impersonation_tokens` —, a requisição retornará HTTP 403, mas deixará `impersonated_by_central = true` ativa na sessão daquele navegador para o Tenant A, permitindo que um usuário autenticado no Tenant A contorne o bloqueio operacional em requisições subsequentes.

---

## 2. Objetivos

1. **Validar explicitamente o vínculo do token com o tenant atual antes de modificar a sessão:** Executar `abort_unless((string) $tokenModel->tenant_id === (string) tenant('id'), 403)` imediatamente após carregar `$tokenModel = ImpersonationToken::findOrFail($token)` em `routes/tenant.php`.
2. **Garantir que a sessão (`impersonated_by_central`) só seja gravada após o sucesso da autenticação de impersonação:** Somente definir `session()->put('impersonated_by_central', true)` após a validação de `tenant_id` e a confirmação de `UserImpersonation::makeResponse($tokenModel)` (que também valida o TTL de 60s, autentica o usuário-alvo e consome o token), impedindo que tokens expirados ou de outro tenant contaminem a sessão.
3. **Assegurar cobertura por testes automatizados:** Validar via PHPUnit que tentativas de acesso a `/impersonate/{token}` com token de outro tenant ou token expirado retornam **HTTP 403** e **não** definem `impersonated_by_central` na sessão, mantendo o bloqueio de `EnsureTenantIsOperational` intacto.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Validação Estrita de Escopo de Tenant e Atomicidade da Sessão de Suporte

1. **Correspondência Obrigatória de `tenant_id`:**
   - Na rota `GET /impersonate/{token}` (`tenant.impersonate`), após buscar o registro via `ImpersonationToken::findOrFail($token)`, o sistema deve validar explicitamente se o token foi emitido para o tenant inicializado no domínio atual:
     ```php
     abort_unless((string) $tokenModel->tenant_id === (string) tenant('id'), 403);
     ```
2. **Proibição de Mutação Prematura de Sessão:**
   - A flag `impersonated_by_central` jamais deve ser gravada na sessão antes que:
     1. O `tenant_id` do token seja validado contra `(string) tenant('id')`; e
     2. `UserImpersonation::makeResponse($tokenModel)` conclua sem abortar (validando também a expiração do TTL de 60 segundos, autenticando o usuário no guard `web` e removendo o token de uso único).
   - Se a validação de tenant ou de TTL falhar (HTTP 403), a sessão atual deve permanecer inalterada (`session()->has('impersonated_by_central') === false`).

---

## 4. Detalhamento Técnico

### 4.1. `routes/tenant.php`

Atualizar a rota `tenant.impersonate` para validar `abort_unless($tokenModel->tenant_id === (string) tenant('id'), 403)` antes de qualquer alteração de sessão e gravar `session()->put('impersonated_by_central', true)` apenas após a execução bem-sucedida de `UserImpersonation::makeResponse($tokenModel)`:

```php
Route::get('/impersonate/{token}', function (string $tenant, string $token) {
    $tokenModel = ImpersonationToken::findOrFail($token);

    abort_unless((string) $tokenModel->tenant_id === (string) tenant('id'), 403);

    $response = UserImpersonation::makeResponse($tokenModel);

    session()->put('impersonated_by_central', true);

    return $response;
})->name('tenant.impersonate');
```

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Bloqueio de Token de Outro Tenant sem Contaminação de Sessão:**
   - Quando um token válido emitido para o `Tenant B` é utilizado na rota `GET /impersonate/{token}` sob o domínio do `Tenant A`, a requisição retorna **HTTP 403 (Forbidden)**, a sessão **não** recebe a chave `impersonated_by_central` (`session('impersonated_by_central') === null`) e o token do `Tenant B` não é consumido Indevidamente.
2. **Prevenção de Bypass de `EnsureTenantIsOperational` em Tenant Inadimplente/Bloqueado:**
   - Um usuário autenticado em um `Tenant A` bloqueado (ex.: assinatura `Overdue`), ao tentar acessar `/impersonate/{token}` com um token pertencente ao `Tenant B` (ou com um token expirado do próprio `Tenant A`), recebe **HTTP 403** e, ao acessar `/dashboard` em seguida, continua sendo bloqueado e redirecionado para `/perfil` pelo middleware `EnsureTenantIsOperational`.
3. **Preservação do Fluxo Legítimo de Impersonation Central:**
   - Quando um token válido e dentro do TTL pertencente ao `Tenant A` é consumido em `GET /impersonate/{token}` no domínio do `Tenant A`, o usuário-alvo é autenticado no guard `web`, a sessão recebe `impersonated_by_central = true`, o token é removido do banco e a resposta redireciona para `/dashboard`.

---

## 6. Documentação Relacionada

- [Arquitetura: Acesso Central (Sócios), Impersonation & Auditoria — §2 Impersonation](../../architecture/central-admin-access.md)
- [Domínio: Cobrança & Assinaturas (Billing) — §2.1 Bloqueio de Acesso Operacional](../../domain/billing.md)
- [Especificação Arquivada: Bloqueio de Acesso e Ciclo de Vida de Inadimplência, Expiração e Cancelamento](../archived/2026-10-tenant-access-enforcement-and-lifecycle.md)
- [Especificação Arquivada: Painel Central de Sócios (Super Admin)](../archived/2026-10-super-admin-panel.md)

