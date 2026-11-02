# Especificação: Prevenção de Adulteração de Preços (Price Tampering) na Solicitação e Edição de Serviços

- **Identificador:** `2026-11-dentist-request-service-price-tampering-protection`
- **Status:** Concluído / Implementado
- **Data:** Novembro de 2026
- **Data de Conclusão:** 2026-11-01
- **Módulos Afetados:**
  - Solicitação e Edição de Serviços em Ordens de Serviço (`resources/views/components/service/requesting.blade.php`, `resources/views/components/service/update.blade.php`)
  - Modelos de Catálogo e Itens de Pedido (`app/Models/Service.php`, `app/Models/RequestService.php`)
  - Testes de Segurança e Integridade de Preços (`tests/Feature/ServiceEditionPricingAndDemandIntegrityTest.php`)

---

## 1. Contexto & Motivação

A demanda **3.2 ([ALTA] Adulteração de Preços (Mass Assignment / Price Tampering) por Dentistas)** identifica uma vulnerabilidade de integridade financeira nos componentes Livewire de criação e edição de itens de ordens de serviço (`RequestService`):

1. **Confiança Indevida em `services.*.unit_price` no Componente `requesting.blade.php`:**
   - Na view `resources/views/components/service/requesting.blade.php` (linha 340), o campo de preço unitário é exibido apenas para leitura com `<flux:input mask:dynamic="$money($input, ',', '.')" label="Preço Unitário" wire:model="services.{{ $index }}.unit_price" readonly />`.
   - Contudo, o atributo HTML `readonly` restringe apenas a edição visual no navegador e não impede a modificação da propriedade pública `$services[$index]['unit_price']` no payload JSON da requisição `POST /livewire/update`.
   - No método `save()` (linhas 291–298), o servidor persiste diretamente `'unit_price' => currency_to_float($service['unit_price'])` recebido do front-end em `RequestService::create(...)`, em vez de consultar o preço oficial do serviço (`Service::whereKey($service['service_id'])->value('price')`) no banco de dados.
2. **Exposição Equivalente de `unitPrice` no Componente `update.blade.php`:**
   - Em `resources/views/components/service/update.blade.php` (linha 146), o campo `<flux:input mask:dynamic="$money($input, ',', '.')" label="Preço Unitário" wire:model="unitPrice" readonly />` também é somente leitura na interface.
   - No entanto, o método `save()` (linhas 115–119) grava diretamente `'unit_price' => currency_to_float($this->unitPrice)` enviado pelo cliente ao atualizar o `RequestService`, permitindo que o valor seja adulterado no payload Livewire durante a edição de um item ou troca de serviço.
3. **Impacto Operacional e Financeiro:**
   - Um dentista mal-intencionado (ou qualquer usuário manipulando a requisição Livewire) pode alterar `unit_price` para `0,01` ou `0,00` ao solicitar serviços protéticos.
   - Isso compromete diretamente o faturamento do laboratório, os indicadores de receita em relatórios (`revenue_cents`) e o cálculo de comissões percentuais de técnicos parceiros (`ROUND(partner_demands.partner_commission * request_services.unit_price * request_services.quantity / 100.0)`).

---

## 2. Objetivos

1. **Resolver o preço unitário exclusivamente no servidor em `requesting.blade.php`:** Ignorar qualquer valor de `$service['unit_price']` vindo do front-end durante o método `save()` e buscar o preço oficial diretamente no banco de dados via `Service::whereKey($service['service_id'])->value('price')`.
2. **Resolver o preço unitário exclusivamente no servidor em `update.blade.php`:** Ignorar `$this->unitPrice` vindo do front-end durante o método `save()`, aplicando o preço oficial do catálogo (`Service::whereKey($this->serviceId)->value('price')`) quando o serviço for substituído e preservando o preço histórico do item (`$requestService->unit_price`) quando apenas a quantidade for alterada.
3. **Remover validações dependentes de preço enviado pelo cliente:** Retirar a exigência de `services.*.unit_price` em `requesting.blade.php` e de `unitPrice` em `update.blade.php` nos métodos `rules()`, já que o preço não é um dado de entrada confiável do usuário.
4. **Assegurar cobertura por testes automatizados:** Garantir via PHPUnit que tentativas de envio de `unit_price` adulterado (`0,01`, `0,00` ou valores arbitrários) em `service.requesting` e `service.update` sejam descartadas e que o banco de dados grave sempre o valor oficial em centavos.

---

## 3. Regras de Negócio & Segurança

### 3.1. RN-01: Resolução Server-Side Obrigatória na Solicitação de Serviços (`requesting.blade.php`)

1. **Exibição Informativa na UI:**
   - Ao selecionar um serviço no formulário (`updatedServices`), o componente continua preenchendo `$this->services[$index]['unit_price']` formatado em reais apenas para visualização imediata pelo usuário no campo `readonly`.
2. **Ignorar `unit_price` do Cliente no `save()`:**
   - Independentemente do perfil do usuário (`Rules::Dentist`, `Rules::Owner`, `Rules::Lab` ou `Rules::LabManager`), visto que o campo não é editável no fluxo de solicitação, o método `save()` **nunca** deve ler `$service['unit_price']` para persistência.
   - Para cada item de `$this->services`, o preço unitário deve ser consultado diretamente no banco de dados a partir do `service_id` validado:
     ```php
     $officialPrice = Service::whereKey($service['service_id'])->value('price');
     ```
   - O valor retornado pelo model `Service` (convertido pelo accessor `Service::price` para `float` em reais) é passado para `RequestService::create(['unit_price' => $officialPrice, ...])`, cujo mutator `RequestService::unitPrice` converte e armazena o valor exato em centavos inteiros.

### 3.2. RN-02: Resolução Server-Side e Preservação de Preço Histórico na Edição (`update.blade.php`)

1. **Ignorar `unitPrice` do Cliente no `save()`:**
   - Como o campo `unitPrice` em `update.blade.php` também é `readonly`, o método `save()` deve ignorar o valor contido em `$this->unitPrice` enviado pelo front-end.
2. **Cálculo Autoritativo na Atualização do `RequestService`:**
   - **Quando houver troca de serviço (`$serviceChanged === true`):** O novo `unit_price` deve ser extraído diretamente da instância `$newService->price` (ou `Service::whereKey($this->serviceId)->value('price')`) carregada do banco de dados.
   - **Quando o serviço não for alterado (`$serviceChanged === false`):** O `unit_price` deve manter o valor histórico já persistido em `$requestService->unit_price` (com fallback para `Service::whereKey($this->serviceId)->value('price')` caso estivesse nulo), respeitando a regra de negócio de imutabilidade do preço histórico de ordens já emitidas e impedindo qualquer adulteração via payload Livewire.

---

## 4. Detalhamento Técnico

### 4.1. `resources/views/components/service/requesting.blade.php`

1. **Em `rules()` e `messages()`:**
   - Remover `'services.*.unit_price' => 'required'` de `rules()` e `'services.*.unit_price.required'` de `messages()`, pois o preço passa a ser determinado integralmente no servidor a partir de `services.*.service_id`.
2. **Em `save()`:**
   - Substituir a criação baseada no input do cliente:
     ```php
     foreach ($this->services as $service) {
         $officialPrice = Service::whereKey($service['service_id'])->value('price');

         RequestService::create([
             'dentist_request_id' => $dentistRequest->id,
             'service_id' => $service['service_id'],
             'unit_price' => $officialPrice,
             'quantity' => $service['quantity'],
         ]);
     }
     ```

### 4.2. `resources/views/components/service/update.blade.php`

1. **Em `rules()`:**
   - Remover `'unitPrice' => ['required']` de `rules()`.
2. **Em `save()`:**
   - Resolver o preço oficial no servidor sem utilizar `currency_to_float($this->unitPrice)`:
     ```php
     $officialUnitPrice = $serviceChanged
         ? $newService->price
         : ($requestService->unit_price ?? Service::whereKey($this->serviceId)->value('price'));

     $requestService->update([
         'unit_price' => $officialUnitPrice,
         'quantity' => $this->quantity,
         'service_id' => $this->serviceId,
     ]);
     ```

---

## 5. Critérios de Aceite (Testes Automatizados)

1. **Prevenção de Adulteração de Preço por Dentista em `service.requesting`:**
   - Quando um usuário com perfil `Rules::Dentist` submete `service.requesting` modificando `services.0.unit_price` para `'0,01'` ou `'0,00'` em um serviço cujo preço de catálogo no banco é `R$ 450,00` (`45000` centavos), o registro criado em `request_services` persiste `unit_price = 45000` (ignorando o valor adulterado).
2. **Prevenção de Adulteração de Preço em Múltiplos Itens e Outros Perfis em `service.requesting`:**
   - Ao solicitar múltiplos serviços na mesma `DentistRequest` com valores `unit_price` manipulados ou vazios no payload, cada `RequestService` criado grava exatamente o `price` oficial do respectivo `Service` cadastrado no banco.
3. **Prevenção de Adulteração de Preço em `service.update`:**
   - Ao editar um `RequestService` sem demandas iniciadas (trocando ou mantendo o `serviceId`) e adulterando a propriedade `unitPrice` para `'0,01'` ou `'0,00'`, o `save()` ignora o valor manipulado e grava o preço oficial do novo serviço (ou preserva o preço histórico original quando o serviço não foi trocado).

---

## 6. Documentação Relacionada

- [Domínio: Pedidos & Ordens de Serviço (Orders) — §3 Regras de Negócio Críticas](../../domain/orders.md)
- [Arquitetura: Tech Stack & Convenções Técnicas — §3.3 Livewire & Volt](../../architecture/tech-stack.md)
- [Arquitetura: Convenções de Dados Globais — §1 Valores Monetários](../../architecture/data-conventions.md)
- [Especificação Arquivada: Edição de Serviços, Integridade de Demandas Históricas e Resiliência Monetária](../archived/2026-10-service-edition-pricing-and-demand-integrity.md)

