# Phase 22: Wrapper expandido EcfDriveService (todos os endpoints + cache estratégico)

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-05
**Depende de:** Phase 20 (EcfDriveService já existe parcialmente)
**Milestone:** v8.0 — Integração Estratégica ECF Drive
**Bloqueia:** Phases 23, 24, 25, 26, 27, 28 (todas precisam do wrapper)

## Goal

Expandir o `EcfDriveService` existente (criado na Phase 20 com 4 métodos: `listGrants`, `cliente`, `ping`, `grantsExpirandoEm`) para cobrir **todos os domínios da API ECF Drive** documentados em `API-GUIDE.md`. Adicionar cache estratégico por endpoint conforme o "Padrão de uso recomendado" (seção 12 do guide) e tratamento defensivo (retry, timeout, erro pt-BR amigável).

**Não há UI nesta fase** — apenas infraestrutura reutilizável pelas Phases 23-28. Entregar o wrapper como serviço bem testado abre caminho para todas as features estratégicas que vêm em sequência.

## Origem da fase

API-GUIDE.md fornecido pelo usuário em 2026-06-05 revelou que a API ECF Drive expõe **10 domínios** além de `/clientes/grants` (único consumido hoje):

- `/auth/*` — não precisa wrapper específico (usado apenas pra `ping`)
- `/files/*` — arquivos brutos ML (CSV/XLSX) — usado raramente, sem cache
- `/clientes/*` — cadastro + grants (parcial Phase 20)
- `/sellers/*` — métricas operacionais (M1) — **mina de ouro estratégica**
- `/carteira/*` — agregados ECF (M2) — visão executiva
- `/signals/*` — alertas automatizados (M3) — caixa de entrada do comercial
- `/relatorios/*` — consolidados mensais (M4)
- `/sync/*` — status do worker SFTP — usado raramente
- `/etl/*` — status do processamento — usado raramente
- `/admin/*` — users, api-keys, webhooks (gestão interna do ECF Drive, fora de escopo desta fase)

## Decisões já travadas

### D-01: Cobertura desta fase

**Implementar wrappers** para os domínios diretamente úteis às Phases 23-28:
- `/clientes/*` (completar)
- `/sellers/*` (completo)
- `/carteira/*` (completo)
- `/signals/*` (completo)
- `/relatorios/*` (completo)

**NÃO implementar** nesta fase (escopo bloqueado):
- `/files/*` (audit raro — fica para fase futura se necessário)
- `/sync/*` e `/etl/*` (observabilidade interna do ECF Drive — não conecta às phases 23-28)
- `/admin/*` (gestão de keys/webhooks via UI do ECF Drive — não consumimos)

### D-02: Cache estratégico por endpoint

Seguir a tabela "Recomendação por endpoint" da seção 12 do API-GUIDE.md:

| Endpoint | Estratégia | TTL no wrapper |
|---|---|---|
| `/clientes/grants` | Cache curto (já existe) | 5 min |
| `/clientes/{custId}` | Proxy on-demand | Sem cache |
| `/clientes/{custId}/historico` | Proxy on-demand | Sem cache |
| `/clientes/acoes-pendentes` | Cache curto | 5 min |
| `/sellers/{custId}` | Proxy on-demand | Sem cache |
| `/sellers/{custId}/metricas/mensal` | Cache médio | 1 h |
| `/sellers/{custId}/metricas/diario` | Proxy on-demand | Sem cache |
| `/sellers/{custId}/medalhas` | Cache longo | 6 h |
| `/sellers/{custId}/signals` | Cache curto | 5 min |
| `/sellers/ranking` | Cache médio | 1 h |
| `/sellers/comparar` | Proxy on-demand | Sem cache |
| `/carteira/resumo` | Cache curto (refresh on view) | 5 min |
| `/carteira/historico` | Cache longo (pull diário) | 24 h |
| `/carteira/distribuicao/medalhas` | Cache médio | 1 h |
| `/carteira/breakdown` | Cache médio | 1 h |
| `/carteira/segmentacao` | Cache médio | 1 h |
| `/signals` | Cache muito curto | 1 min (até webhook na Phase 26) |
| `/signals/{id}/ack` (POST) | Sem cache (invalida lista) | — |
| `/relatorios/mensal/{periodo}` | Cache muito longo | 24 h |

### D-03: Estrutura do código

- Mantém **único service class** (`EcfDriveService`) — não dividir em sub-classes (evita explosão de DI).
- Métodos agrupados por comentário visual de seção: `// ─── Clientes ───`, `// ─── Sellers ───`, etc.
- Método HTTP base reutilizável: `private function get(string $path, array $params = []): array` que retorna `->json()` ou throws RuntimeException.
- Cache key consistente: `ecf.{dominio}.{operacao}.{hash-params}` — facilita invalidação seletiva.

### D-04: Tratamento de erros

Manter o padrão da Phase 20:
- `retry(2, 500, null, false)` — duas tentativas com backoff
- `timeout(15)` segundos
- `throw new RuntimeException("ECF Drive API erro: …")` em pt-BR amigável quando falha definitivamente
- Log `[EcfDriveService] {método} {status}` em erros

### D-05: Rate limit (120 req/min por IP)

Não implementar throttle ativo nesta fase — `retry(2, 500)` cobre 429 transitório. Se virar problema em prod (improvável: 120/min é generoso), adicionar Cache::lock global numa fase futura.

### D-06: Signature dos métodos

Padrão consistente:
- Listas: `listX(array $filters = []): array` — retorna `['data' => [...], 'total' => N]`
- Detalhe único: `getX(string $id): array` ou `getX(string $custId, string $periodo): array`
- Mutações (signals/ack): `ackX(int $id): array`
- Atalhos cacheados: `getY(...)` (já existe `grantsExpirandoEm(int $dias)`)

### D-07: Sem testes E2E contra API real

Todos os testes usam `Http::fake()`. Validação contra API real fica para o smoke do W4 humano (`ping` + 1 chamada por categoria).

## Success Criteria

1. **`EcfDriveService` expandido** com **18 métodos públicos novos** cobrindo `/clientes/*`, `/sellers/*`, `/carteira/*`, `/signals/*`, `/relatorios/*`.

2. **Cache estratégico aplicado** conforme tabela D-02 — testes verificam TTL correto para cada categoria de endpoint.

3. **Tratamento de erro consistente** — todos os métodos lançam `RuntimeException` com mensagem pt-BR em falhas; `ping()` único método que **retorna bool** sem lançar.

4. **Testes Feature** cobrem cada método: Http::fake retornando payload de exemplo (extraído do guide) → asserção de estrutura. Mínimo **20 testes** (1 caminho feliz + 1 cache hit + 1 erro/retry por domínio principal).

5. **Sem regressão Phase 20** — `EcfDriveServiceTest` original (6 testes) + `SyncGrantsFromEcfDriveTest` (10 testes) continuam verdes.

6. **Smoke em prod (W4)** — `ping()` + 1 chamada de cada domínio (`/clientes/grants?limit=1`, `/sellers/ranking?metrica=tgmv_lc&top=1`, `/carteira/resumo`, `/signals?acked=false&limit=1`, `/relatorios/mensal`) confirma que API key tem permissão e payloads chegam.

7. **Documentação inline**: cada método público tem docblock pt-BR explicando uso típico + link pro endpoint em `API-GUIDE.md` (formato `@see API-GUIDE.md §5.X`).

## Mapa de arquivos

### Modificados
- `app/Services/EcfDriveService.php` — adiciona ~18 métodos novos + helper `get()` privado + comentários de seção

### Novos
- `tests/Feature/Phase22/EcfDriveServiceClientesTest.php` (4 testes — listClientes, getCliente, getHistorico, listAcoesPendentes)
- `tests/Feature/Phase22/EcfDriveServiceSellersTest.php` (6 testes — getSeller, metricasMensal, metricasDiario, medalhas, signals do seller, ranking)
- `tests/Feature/Phase22/EcfDriveServiceCarteiraTest.php` (5 testes — resumo, historico, distribuicaoMedalhas, breakdown, segmentacao)
- `tests/Feature/Phase22/EcfDriveServiceSignalsTest.php` (3 testes — listSignals, ackSignal, cache curto 1min)
- `tests/Feature/Phase22/EcfDriveServiceRelatoriosTest.php` (2 testes — getRelatorioMensal + cache 24h)

### Não tocar
- `app/Console/Commands/SyncGrantsFromEcfDrive.php` (Phase 20 — continua usando `listGrants()` do wrapper)
- `app/Http/Controllers/GrantController.php` (continua igual)
- `routes/console.php`, `routes/web.php` (sem schedule novo nesta fase)
- Frontend (sem UI nesta fase — virá nas Phases 23-28)

## Pitfalls antecipados

1. **Payload da API ECF Drive mudar entre o guide e prod** — guide foi escrito em 2026-06-05. Mitigação: smoke em W4 confirma estrutura. Se mudar, ajustamos a fixture do teste.

2. **Cache TTL muito longo "esconder" mudanças** — `/relatorios/mensal` com 24h pode parecer cached eternamente após mudança no ECF Drive. Mitigação: adicionar comando `ecf-cache:flush {dominio?}` numa fase futura se virar dor.

3. **Métodos com nomes longos** — `getSellerMetricasMensal` vs `getMetricasMensalDeSellereee`. Decisão: usar `sellerMetricasMensal()`, `sellerMedalhas()`, `sellerSignals()` (dropa o `get` prefix em métodos do mesmo recurso).

4. **Filtros opcionais não-óbvios na assinatura** — `/sellers/ranking?metrica=&top=&programa=&asc=` tem 4 filtros. Decisão: `ranking(string $metrica, int $top = 20, ?string $programa = null, bool $asc = false)` — explícito vez de array.

5. **`/clientes/{custId}/historico` paginar pesado** — sem cache. Confiar no caller (Phase 25) cachear localmente.

## Não-objetivos

- Webhook receiver (Phase 26)
- Comandos artisan novos (cada phase decide)
- UI (todas as UIs vêm em Phases 23-28)
- Files / Sync / ETL / Admin domains
- Migrations / Models / activity log
- Forecast / análise / dashboards
- Substituição do `AdmanService` ou `AdmanMcpService` (decisão por uso, não por arquitetura — cada caller decide)

## Cross-cutting constraints

- pt-BR em comentários, mensagens, commits, docblocks
- Não há `npm run build` (sem JSX nesta fase)
- snake_case nas chaves dos payloads que mapeamos pro DB (caso aplique nas Phases 23-28)
- API key NUNCA no código (já está no `.env` desde Phase 20)
- Reusar config `services.ecf.*` (não duplicar config)
- Activity log Spatie não se aplica (sem CRUD)

## Referências

- [API-GUIDE.md](API-GUIDE.md) — fonte autoritativa (commit `ec08cd7`)
- Phase 20 — `EcfDriveService` original (`listGrants`, `cliente`, `ping`, `grantsExpirandoEm`)
- Memory `feedback_lean_planning.md` — pular research/discuss/plan-check
- Memory `feedback_project_priorities.md` — acertividade + praticidade

## Memory persistente relevante

- **Lean planning** — pular formalismos
- **GSD output em pt-BR**
- **Acertividade** — testes Feature cobrem payloads reais, validando que o que documentamos no docblock é o que a API realmente entrega
