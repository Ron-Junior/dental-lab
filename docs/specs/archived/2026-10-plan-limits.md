# Especificação: Limites do Plano e Alertas Proativos

- **Identificador:** `2026-10-plan-limits`
- **Status:** Concluído / Implementado
- **Data:** Outubro de 2026
- **Data de Conclusão:** 2026-10-27
- **Módulos Afetados:** Billing (`Plan`, `Subscription`), Pedidos (`RequestService`), Parceiros/Equipe (`User`), Arquivos (`File`), Dashboard.

---

## 1. Contexto

O campo `plans.limits` (JSONB, banco central) define o que cada laboratório pode consumir. É preciso medir o consumo real no schema do tenant, alertar antes do teto e bloquear apenas a criação de novos itens ao atingi-lo.

## 2. Regras de Negócio

| Chave em `limits` | Recurso | Consumo medido |
| --- | --- | --- |
| `users_limit` | Equipe e parceiros | Usuários não-dentistas com senha definida ou convite pendente válido |
| `storage_gb_limit` | Armazenamento | Soma de `files.size` (bytes) / 1024³ |
| `monthly_exams_limit` | Itens mensais | `RequestService` criados no mês corrente (UTC) |

- Chave ausente/`null` ⇒ ilimitado. Sem assinatura `active`/`trial` ⇒ sem limite.
- Níveis: `normal` (<80%), `warning` (≥80%), `critical` (≥95%), `exceeded` (≥100%, bloqueia).
- Bloqueio atinge **somente criação** (novo parceiro, novo pedido, novo upload). Leitura e demais fluxos seguem normais.
- Pedido com N itens é bloqueado se `uso + N > limite`.
- Falha ao ler o plano não bloqueia o laboratório (fail-open, com log).
- Equipe vê: "limite atingido, faça upgrade". Dentista vê: "não foi possível solicitar, entre em contato com o laboratório".

## 3. Implementação

- `App\Services\PlanLimits\TenantLimitChecker` (uso, limite, porcentagem, nível), `ResourceUsage`, `PlanLimitExceededException` (403).
- Enums `PlanResource` e `UsageLevel`.
- Middleware `plan.limit:users|storage|monthly_items` (HTTP) e trait Livewire `EnforcesPlanLimits`.
- `Plan` e `Subscription` usam `CentralConnection`.
- Banners no dashboard (`x-plan-usage-alerts`) visíveis à equipe do laboratório.

## 4. Critérios de Aceite

- Níveis corretos nos limiares 80/95/100%.
- Em 100%, criar parceiro, pedido ou upload é recusado sem persistir; navegação continua funcionando.
- Dentista recebe mensagem genérica; equipe recebe mensagem de upgrade.
- Banners aparecem apenas para a equipe.
