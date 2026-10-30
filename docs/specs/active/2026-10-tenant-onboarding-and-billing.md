# Especificação: Onboarding do Laboratório, Checkout Stripe e Primeiro Acesso Protegido

- **Identificador:** `2026-10-tenant-onboarding-and-billing`
- **Status:** Ativo / Em Especificação
- **Data:** Outubro de 2026
- **Módulos Afetados:** Banco Central (`tenants`, `subscriptions`, `plans`, `payments`), Onboarding (`CreateLaboratory`, `ProvisionTenant`), Autenticação Tenant (`complete-registration`), Checkout Stripe (`StripeService`, `PaymentWebhookController`), Perfil Tenant (`subscription-billing`).

---

## 1. Contexto & Motivação

Atualmente, o fluxo de entrada de um laboratório realiza a coleta de senha diretamente no formulário central e provisiona o banco de dados antes da confirmação de pagamento ou associação de cartão. Esse modelo apresenta desafios:
1. **Segurança de Credenciais:** A senha do administrador do laboratório era coletada no banco central antes da existência do schema do laboratório, trafegando temporariamente em texto plano ou hash por tabelas centrais e filas.
2. **Ausência de Checkout:** Não havia interface de checkout integrada a um gateway real de pagamento (Stripe), impossibilitando a coleta do cartão para período de testes (Trial) e cobrança recorrente.
3. **Falta de Diferenciação de Isenção:** Laboratórios isentos (cortesia comercial concedida por sócios) e laboratórios pagantes passavam pelo mesmo fluxo rígido.
4. **Vulnerabilidade no Primeiro Acesso:** Links de primeiro acesso e criação de senha não possuíam trava vinculada ao estado de verificação do laboratório, permitindo reusos indevidos.

---

## 2. Objetivos

- **Dividir o fluxo de entrada em 3 etapas claras:**
  1. Cadastro de Informações (Self-service pelo laboratório ou cadastro por sócio, **sem senhas**);
  2. Checkout de Pagamento via Stripe (Com **bypass automático** para laboratórios isentos ou com período de teste concedido por sócio);
  3. Aguardo de Provisionamento do Tenant (Feedback em tempo real e e-mail com link de primeiro acesso).
- **Sem Período de Teste no Checkout:** O checkout público cobra a assinatura normal do plano escolhido, sem período de teste grátis.
- **Período de Teste de Concessão Exclusiva dos Sócios:** Apenas sócios/administradores centrais podem conceder períodos de teste (`SubscriptionStatus::Trial`) ao cadastrar laboratórios parceiros.
- **Isenção Exclusiva para Não-Clientes (Aquisição Comercial):** Isenção de pagamento (`exemptions`) só pode ser concedida no momento do cadastro do laboratório ou antes de possuir uma assinatura ativa. Laboratórios que já são assinantes ativos não podem receber isenções.
- **Repasse de Descontos ao Stripe:** Caso haja desconto aplicado pelo sócio ao laboratório no checkout, o sistema cria/utiliza um `coupon` no Stripe e vincula à assinatura, garantindo transparência no valor cobrado na fatura inicial.
- **Primeiro Acesso Protegido no Subdomínio:** A senha é definida pelo dono **apenas após o provisionamento**, diretamente no seu schema isolado, e o link só é válido enquanto o laboratório **não** tiver sido verificado (`verified_at === null`).
- **Persistência do Dono no Banco Central:** Salvar `owner_email` e `verified_at` na tabela `tenants`.
- **Gestão de Assinatura no Perfil do Laboratório:** Visualização do plano, **contador regressivo de dias restantes de Trial** (quando concedido por sócio), cartão cadastrado no Stripe e modal para atualizar a forma de pagamento.

---

## 3. Regras de Negócio

### 3.1. Etapa 1: Cadastro das Informações (Sem Senha)

1. **Campos Obrigatórios:** Nome do laboratório (`laboratory_name`), Endereço (`slug`), Nome do administrador inicial (`admin_name`) e E-mail do administrador (`email`). A escolha e contratação do plano são realizadas na etapa seguinte de Checkout.
2. **Proibição de Senhas no Banco Central:** Nenhum campo de senha é solicitado nesta etapa. O banco central nunca armazena senhas de usuários dos laboratórios.
3. **Persistência do Tenant:**
   - O `Tenant` é criado no banco central com status `pending_payment`.
   - `owner_email` recebe o e-mail do administrador.
   - `verified_at` é inicializado como `null`.
   - O domínio `{slug}.central_domain` é registrado.
   - A `Subscription` é inicializada com o primeiro plano ativo do sistema (podendo ser livremente selecionado ou alternado pelo cliente na etapa de checkout).
4. **Fluxo do Sócio:** Se cadastrado por um sócio autenticado, pode receber **Período de Testes (Trial)** ou **Isenção de Pagamento (`exemptions`)**, ambos com bypass imediato de checkout.
   - **Regra de Isenção:** A concessão de isenção só é permitida neste momento (aquisição/novo laboratório). Clientes já existentes com assinaturas ativas não podem ser isentados retroativamente.

### 3.2. Etapa 2: Checkout de Pagamento (Stripe)

1. **Bypass Automático (Isenção ou Período de Teste Concedido por Sócio):**
   - Se `$tenant->isExempt() === true` ou `$tenant->hasActiveTrial() === true`, a rota `/checkout/{tenant}` redireciona imediatamente para `/bem-vindo/{tenant}`. Nenhuma cobrança ou dados de cartão são solicitados.
2. **Laboratórios Pagantes (Sem Benefício de Sócio):**
   - Exibe a seleção visual de todas as opções de planos ativos da plataforma, permitindo ao usuário escolher ou alternar o plano antes de confirmar o pagamento.
   - Exibe o resumo da assinatura do plano selecionado (valor e ciclo: mensal ou anual). Se houver desconto aplicado, o valor líquido a pagar reflete o desconto no resumo.
   - **Sem período de teste no checkout público:** A ativação da assinatura e a cobrança ocorrem imediatamente de acordo com o plano contratado.
   - Utiliza **Stripe Elements** para coletar e tokenizar os dados do cartão via `SetupIntent` (client-side, sem expor dados sensíveis ao backend).
   - O backend recebe o `payment_method` gerado:
     1. Vincula o cartão ao cliente no Stripe (`stripe_customer_id`) e define-o como método de pagamento padrão (`invoice_settings.default_payment_method`).
     2. Salva os dados de exibição segura no banco central (`card_brand` e `card_last_four`).
     3. **Repasse de Desconto (Stripe Coupon):** Caso o laboratório possua um desconto pendente aplicado por sócio (`metadata.discount`), o backend cria/recupera um cupom correspondente no Stripe (`coupon` com `percent_off` ou `amount_off` e duração `once`) e o inclui nos parâmetros da assinatura (`discounts => [['coupon' => $couponId]]`).
     4. Cria a assinatura recorrente no Stripe através da API de Subscriptions (`subscriptions.create`), vinculando o cliente ao preço do plano contratado e ao eventual cupom de desconto.
     5. Caso o plano selecionado não possua `stripe_price_id` cadastrado no Stripe, o sistema provisiona automaticamente o Produto e o Preço recorrente na Stripe sob demanda, persistindo o ID no plano.
     6. Armazena o identificador da assinatura retornada (`sub_...`) na coluna `stripe_subscription_id` do registro `Subscription` local.
     7. Atualiza a cobrança pendente local (`Payment`) com status `paid`, data de pagamento e o ID da fatura gerada pela Stripe (`latest_invoice`).
     8. Inicia o provisionamento assíncrono do banco do tenant (`/bem-vindo/{tenant}`).


### 3.3. Etapa 3: Aguardo de Provisionamento & Primeiro Acesso

1. **Provisionamento Assíncrono (`ProvisionTenant`):**
   - Cria o schema PostgreSQL do laboratório (`CreateDatabase`).
   - Executa migrações (`tenants:migrate`) e seeds (`TenantProvisioningSeeder`).
   - Cria o usuário administrador com o `owner_email` e senha temporária aleatória segura (`Str::random(32)`).
   - Gera token de primeiro acesso (`InvitationToken`) e URL assinada temporária (48h) para a rota `/completar-cadastro` no subdomínio do laboratório.
   - Altera o status do tenant para `active`.
   - Envia e-mail de boas-vindas com o link de primeiro acesso (`TenantWelcomeNotification`).
2. **Tela de Boas-Vindas (`/bem-vindo/{tenant}`):**
   - Polling reativo a cada 3 segundos (`wire:poll.3s`).
   - Quando o status atinge `active`, libera o botão **"Acessar plataforma e criar senha"** apontando para a URL assinada no subdomínio.

### 3.4. Etapa 4: Conclusão do Primeiro Acesso no Subdomínio

1. **Condições Rígidas de Validação:**
   - Se `tenant()->verified_at !== null` (ou `tenant()->isVerified()`): **Acesso negado**. Redireciona imediatamente para `route('login')` informando que o laboratório já foi ativado.
   - Se o e-mail da URL não coincidir com `tenant()->owner_email`: **Acesso negado (HTTP 403)**.
2. **Ativação da Conta:**
   - O proprietário insere `password` e `password_confirmation`.
   - A senha com hash `Hash::make()` é salva na tabela `users` do banco do laboratório.
   - Marca `$user->markEmailAsVerified()`.
   - Marca o tenant como verificado: `tenant()->markAsVerified()` (atualiza `verified_at = now()`).
   - Invalida o token de convite (`InvitationToken`).
   - Autentica o usuário e redireciona para o `dashboard`.

### 3.5. Gestão de Faturamento no Perfil do Laboratório

1. **Card de Faturamento (`/perfil`):**
   - Exibe plano contratado e status da assinatura.
   - **Contador de Trial:** Exibe badge indicando quantos dias faltam para o término do trial (`subscription.current_period_end - now`).
   - **Cartão Cadastrado:** Exibe a bandeira e os últimos 4 dígitos salvos no Stripe.
   - **Atualização de Pagamento:** Modal com Stripe Elements para alterar o cartão diretamente no Stripe Customer.

### 3.6. Reenvio de Link de Primeiro Acesso (Expirado ou Pendente)


1. **Ação Centralizada (`ResendFirstAccessLink`):**
   - Valida se o laboratório não foi verificado (`!$tenant->isVerified()`). Se já verificado, lança exceção e bloqueia o reenvio.
   - Executa no banco isolado do tenant: invalida tokens anteriores do usuário dono (`$user->invitationToken()->delete()`), gera novo token e cria nova URL assinada temporária válida por 48 horas (`URL::temporarySignedRoute('complete-signup.show', ...)`).
   - Atualiza `first_access_url` nos metadados do `Tenant` no banco central.
   - Reenvia a notificação por e-mail `TenantWelcomeNotification` para o `owner_email`.
2. **Ponto de Entrada 1: Tela de Primeiro Acesso (`/completar-cadastro`):**
   - Se o token da URL estiver expirado (`expires_at < now()`) ou inexistente, a tela não exibe o formulário de senha e mostra card amigável avisando da expiração com botão reativo para **"Reenviar novo link para meu e-mail"**.
3. **Ponto de Entrada 2: Tela de Login do Laboratório (`/login`):**
   - Quando o tenant ainda não concluiu o primeiro acesso (`!tenant()->isVerified()`), exibe banner de aviso no topo da tela de login com atalho direto para **"Reenviar Link"**.
4. **Ponto de Entrada 3: Painel Central de Laboratórios (`/laboratorios`):**
   - Lista exibe badge destacando **"1º Acesso Pendente"** para laboratórios não verificados.
   - Botão **"Reenviar Acesso"** permite ao sócio disparar um novo link de primeiro acesso imediatamente com feedback visual via toast.

---

## 4. Requisitos de Interface (UI / UX)

- **Flux UI:** Utilização dos componentes de layout e tipografia oficiais do projeto (`<flux:heading>`, `<flux:card>`, `<flux:input>`, `<flux:modal>`).
- **Checkout com Stripe Elements:** Container dedicado para o cartão estilizado de forma harmoniosa com os temas claro e escuro.
- **Primeiro Acesso:** Formulário limpo com campos de senha e confirmação, com visualização do e-mail do proprietário em modo somente leitura.
- **Tela de Link Expirado:** Feedback claro, ícone de relógio e ação de reenvio de link com estado de confirmação após envio.

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Cadastro sem Senha:** Submissão em `/cadastro` e no painel admin não deve conter campos de senha e cria o tenant com `verified_at = null` e `owner_email` preenchido.
2. **Bypass de Isenção:** Tenant com isenção ativa pula a tela de checkout e vai direto para `/bem-vindo/{tenant}`.
3. **Trava de Verificação:** Acesso à rota `/completar-cadastro` em um tenant com `verified_at != null` é bloqueado e redirecionado para o login.
4. **Verificação com Sucesso:** Definição da senha no primeiro acesso salva a senha no schema tenant, seta `verified_at` no banco central e autentica o usuário.
5. **Cálculo de Trial no Perfil:** O perfil exibe a quantidade exata de dias restantes até o término do trial.
6. **Webhooks Stripe:** Processamento de webhook `invoice.payment_succeeded` atualiza status e período da assinatura com idempotência.
7. **Reenvio de Link Expirado:** Link expirado detectado em `/completar-cadastro`, banner em `/login` e botão no painel de laboratórios geram novo token de 48h e disparam e-mail via `ResendFirstAccessLink`.
8. **Criação de Assinatura no Stripe:** A submissão bem-sucedida do checkout público cria a assinatura recorrente no Stripe vinculada ao plano, popula `subscriptions.stripe_subscription_id` e registra o pagamento inicial.
9. **Repasse de Descontos ao Stripe:** Quando um laboratório com desconto aplicado conclui o checkout, a assinatura no Stripe é criada com cupom (`coupon`) correspondente, abatendo o valor na fatura gerada.
10. **Bloqueio de Isenção para Assinantes Ativos:** Tentativa de conceder isenção a um laboratório que já é cliente (com assinatura ativa ou pagamentos confirmados) é estritamente bloqueada no `ExemptionService` e na interface administrativa.

