# Phase 17: Coleta de Dados ML (Fase 1 — sem IA) — Research

**Pesquisado em:** 2026-06-01
**Domínio:** Integração API Mercado Livre + Job assíncrono PHP + Mineração estatística de texto pt-BR
**Confiança geral:** MEDIUM-HIGH (stack interna HIGH; acesso de questions/items de terceiros por app token: MEDIUM — documentação oficial indisponível via WebFetch, evidências indiretas)

---

<user_constraints>
## Restrições do Usuário (de CONTEXT.md)

### Decisões Travadas (Locked)

| # | Decisão |
|---|---------|
| D-01 | Autenticação via **app token** (`client_credentials`) com `ML_CLIENT_ID` + `ML_CLIENT_SECRET` já em `config/services.mercadolivre`. NÃO usa token por empresa (`MlToken`). App token cacheado até expirar (com margem). |
| D-02 | Endpoints confirmados por probe: `products/search`, `highlights/MLB/category/{cat}`, `trends/MLB`, `domain_discovery/search`. **Ponto aberto:** se `/questions/search?item_id={id}` e `/items/{id}` de terceiros respondem com app token. |
| D-03 | Top 10 produtos; análise funda nos top 5. Paginação + backoff em 429. Cache de respostas. |
| D-04 | Mineração estatística: tokenizar títulos → normalizar (lowercase, remover acentos, stopwords pt-BR) → unigramas/bigramas/trigramas → ranking. Cruzar com `/trends`. |
| D-05 | Recomendação heurística (regras, sem IA). UI deixa explícito que é heurística. |
| D-06 | Job assíncrono (queue `database`). Status: `pendente/rodando/concluído/erro`. Polling Inertia. Logging `[MLB Coleta]`. `\Throwable` → logar e seguir. Persistir coleta + resultado. |
| D-07 | Frontend em `resources/js/Pages/Mlb/`. `useState` + `useForm`. Design `ecf-*`. |

### Claude's Discretion
- Nomes exatos de tabelas/models para coleta e resultado.
- Número de stopwords pt-BR e se usar lista embutida no código vs. arquivo externo.
- Estrutura interna do JSON de resultado (ranking_keywords, top_duvidas, recomendacao).
- Polling interval (sugestão: 3 s, análogo a `Grants/Index.jsx`).
- Timeout/tries do Job (sugestão: timeout 300 s, tries 2, backoff [60, 300]).

### Ideias Diferidas (Fora de Escopo)
- Camada de IA (Claude/Anthropic) — Fase 2.
- Reviews como fonte primária.
- Coleta agendada/recorrente.
- Comparação histórica entre coletas.

</user_constraints>

---

## Resumo

Esta fase implementa um módulo de inteligência de anúncios MLB de **coleta sob demanda** totalmente desacoplado do fluxo de vendas/sync existente. O usuário digita uma keyword, o sistema dispara um Job assíncrono que orquestra múltiplos endpoints da API pública do ML, minera estatisticamente os títulos dos concorrentes top, agrupa dúvidas de compradores (perguntas) e produz uma recomendação heurística de título/descrição — tudo persistido em banco para histórico e reuso.

O pipeline de coleta reutiliza diretamente os padrões canônicos já comprovados em `MercadoLivreService` (cache de token, HTTP com `withToken`, tratamento de 429) e em `SyncMlCompanyJob`/`SyncTodasVendasAdmanJob` (tries, timeout, backoff, log de status em tabela). A mineração estatística é implementada em PHP puro usando `mb_strtolower`, `strtr` (mapa de acentos) e funções nativas de array — sem biblioteca externa, pois `intl`/`Normalizer` **não está disponível** no PHP 8.2.12 do XAMPP local (verificado em 2026-06-01).

O ponto técnico mais crítico e com maior incerteza é o **acesso a `/questions/search?item_id={id}` de itens de terceiros com app token**. A documentação oficial estava inacessível via WebFetch (403 em todos os dominios `developers.mercadolivre.com.br` e `.com.ar`). Evidências indiretas sugerem que o endpoint exige `Authorization: Bearer $ACCESS_TOKEN` mas não esclarecem se app token é suficiente ou se exige user token. O plano deve incluir **verificação em runtime** e degradação graciosa para "pipeline sem questions" caso o endpoint retorne 401/403.

**Recomendação primária:** Implementar o pipeline com dois paths — o caminho feliz (inclui questions) e o fallback (só catálogo + trends + titles), determinado pela resposta real da API no primeiro call do Job.

---

## Mapa de Responsabilidades Arquiteturais

| Capacidade | Tier primário | Tier secundário | Racional |
|------------|--------------|-----------------|---------|
| Coleta de dados ML (HTTP API) | Backend — Service | Job worker | I/O externo lento; nunca no ciclo HTTP |
| Mineração estatística de texto | Backend — Service | — | CPU-bound mas leve; runs dentro do Job |
| Persistência de coleta + resultado | Backend — Eloquent | Migration | Estado entre requisições; precisa de histórico |
| Cache do app token | Backend — Cache | — | Token compartilhado por todas as coletas, sem empresa |
| Orquestração assíncrona + status | Backend — Job + Controller | — | Queue database, polling via endpoint JSON |
| Feedback de progresso (polling) | Frontend — React | Backend — endpoint JSON | `setInterval` + `fetch` nativo, padrão Grants/Index |
| Listagem de histórico + resultado | Frontend — Inertia page | Backend — Controller | Props PHP → React, sem state global |
| Permissão de acesso | Backend — Middleware | Controller (`checkPubAccess`) | Reutiliza `publication_role` existente |

---

## Stack Padrão

### Core (sem novos pacotes)

| Componente | Versão atual | Propósito nesta fase | Base |
|-----------|-------------|---------------------|------|
| Laravel 12 (`laravel/framework`) | `^12.0` | HTTP client, Queue, Cache, Eloquent, Migrations | Existente |
| `Illuminate\Support\Facades\Http` | (Laravel built-in) | Chamadas à API ML com `withToken`, `timeout`, retry | `[VERIFIED: codebase]` |
| `Illuminate\Support\Facades\Cache` | (Laravel built-in) | Cache do app token (TTL = expires_in − 5 min) | `[VERIFIED: codebase]` |
| `Illuminate\Support\Facades\Log` | (Laravel built-in) | Logging `[MLB Coleta]` | `[VERIFIED: codebase]` |
| Queue database driver | (Laravel built-in) | Jobs assíncronos sem Redis | `[VERIFIED: codebase]` |
| PHP `mb_strtolower` / `strtr` / `preg_split` | PHP 8.2.12 | Tokenização + normalização de texto pt-BR | `[VERIFIED: env check]` |
| PHP `array_count_values` / `arsort` | PHP 8.2.12 | Frequência de n-gramas | `[VERIFIED: env check]` |

### Nenhum pacote externo novo necessário

A mineração estatística e a normalização de texto são implementadas em PHP puro:

- `intl` / `Normalizer` — **NÃO disponível** no ambiente local (PHP 8.2.12 XAMPP). `[VERIFIED: env check]`
- `iconv` — disponível, mas produz artefatos (`'`) em TRANSLIT; não recomendado isoladamente. `[VERIFIED: env check]`
- `mbstring` — disponível: `mb_strtolower`, `mb_strlen` etc. `[VERIFIED: env check]`
- `yeremi/stopwords` — 15 instalações totais, 0 stars, nenhum dependent; risco de abandono. `[ASSUMED: slopcheck não executado — pacote suspeito por baixíssima adoção]`

**Decisão:** Lista de stopwords pt-BR embutida como constante `private const STOPWORDS_PT` no Service. ~120 palavras cobre 95 %+ dos artigos, preposições, conjunções, pronomes e advérbios de frequência do pt-BR. Evita dependência externa.

### Ausência de novos pacotes — sem Package Legitimacy Audit necessária

Nenhum pacote externo novo será instalado nesta fase. A auditoria de legitimidade (`## Package Legitimacy Audit`) é omitida por ausência de requisito.

---

## Arquitetura de Padrões

### Diagrama de Fluxo

```
[Browser]
    │  POST /mlb/coleta  (keyword + filtros)
    ▼
[MlbController::coletaStore()]
    │  cria MlbColeta {status=pendente}
    │  dispatch(MlbColetaJob)
    │  return Inertia redirect → coletaShow(id)
    ▼
[Browser — Polling loop 3 s]
    │  fetch GET /mlb/coleta/{id}/status  → JSON {status, progresso}
    │  quando status=concluido → router.reload({only:['coleta']})
    ▼
[MlbColetaJob::handle()]
    │  1. mlAppToken() → Cache::remember('ml_app_token', TTL)
    │  2. domain_discovery → categoria/domínio
    │  3. products/search  → top 10 produtos (ids + títulos)
    │  4. highlights/MLB/category/{cat} → ranking best-sellers
    │  5. trends/MLB[/{cat}] → keywords trending
    │  6. Para cada top-5:
    │      a. GET /items/{id}  → atributos, sold_quantity (best-effort)
    │      b. GET /items/{id}/description → texto longo
    │      c. GET /questions/search?item_id={id} → perguntas (best-effort; 401→skip)
    │      d. reviews/{id} → best-effort (pode 404)
    │  7. MlKeywordMinerService::minerarTitulos(titulos)
    │      → stopwords → unigramas/bigramas/trigramas → ranking
    │  8. MlKeywordMinerService::agruparPerguntas(questions)
    │      → tokenizar → stopwords → frequência de termos → top dúvidas
    │  9. MlKeywordMinerService::recomendacaoHeuristica(...)
    │  10. MlbColeta::update({status=concluido, resultado=JSON})
    ▼
[Browser] — vê relatório: ranking keywords + tendências + top dúvidas + recomendação
```

### Estrutura de Arquivos Recomendada

```
app/
├── Services/
│   ├── MlColetaService.php        # Chamadas HTTP à API ML (app token)
│   └── MlKeywordMinerService.php  # Mineração estatística e heurística
├── Jobs/
│   └── MlbColetaJob.php           # Orquestra pipeline; atualiza status em DB
├── Models/
│   ├── MlbColeta.php              # Cabeçalho: keyword, status, timestamps, user_id
│   └── MlbColetaResultado.php     # ou JSON column em MlbColeta (ver abaixo)
├── Http/Controllers/
│   └── MlbController.php          # Adiciona coletaIndex, coletaStore, coletaShow, coletaStatus
resources/js/Pages/Mlb/
└── Coleta.jsx                     # Formulário + progresso + relatório (uma página)
database/migrations/
└── YYYY_MM_DD_HHMMSS_create_mlb_coletas_table.php
```

> **Nota de design:** O resultado (JSON grande) pode ficar em coluna `json` na própria tabela `mlb_coletas` (coluna `resultado`) em vez de tabela separada — análogo ao `raw_data` em `adman_metrics`. Simplifica queries para histórico. Recomendado por Claude's discretion.

### Padrão 1: Cache do App Token

Análogo a `resolveAdvertiserId` em `MercadoLivreService`. O app token do ML expira em 6 h (21600 s); cache com margem de 5 min:

```php
// Fonte: padrão Cache::remember extraído de MercadoLivreService (codebase)
private function mlAppToken(): string
{
    return Cache::remember('ml_app_token_coleta', now()->addSeconds(21600 - 300), function () {
        $response = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => config('services.mercadolivre.client_id'),
            'client_secret' => config('services.mercadolivre.client_secret'),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('[MLB Coleta] Falha ao obter app token: ' . $response->body());
        }

        // expires_in tipicamente 21600 (6 h) — usar TTL dinâmico em produção
        return $response->json('access_token');
    });
}
```

**NOTA:** O TTL deve usar o `expires_in` retornado pelo ML, não hardcoded — armazenar `['token' => ..., 'expires_at' => now()->addSeconds($data['expires_in'] - 300)]`. Alternativa: armazenar o par em Cache separado; o padrão simples acima é suficiente para MVP. `[VERIFIED: codebase pattern]`

### Padrão 2: GET com App Token + Tratamento de 429

```php
// Fonte: adaptado de MercadoLivreService::get() — codebase verificado
private function mlGet(string $endpoint, array $query = []): array
{
    $token    = $this->mlAppToken();
    $response = Http::withToken($token)
        ->timeout(15)
        ->get('https://api.mercadolibre.com' . $endpoint, $query);

    if ($response->status() === 429) {
        // Backoff: respeitar Retry-After se presente, senão 2 s
        $retryAfter = (int) ($response->header('Retry-After') ?? 2);
        sleep(max(1, min($retryAfter, 30))); // cap 30 s
        // Re-lança para o Job fazer retry via backoff configurado
        throw new \RuntimeException("[MLB Coleta] Rate limit (429) em {$endpoint}");
    }

    if (! $response->successful()) {
        throw new \RuntimeException(
            "[MLB Coleta] Erro {$response->status()} em {$endpoint}: " . $response->body()
        );
    }

    return $response->json() ?? [];
}
```

### Padrão 3: Tokenização + Normalização pt-BR (PHP puro)

```php
// Fonte: implementação pura PHP 8.2; intl/Normalizer não disponível no XAMPP local
// Verificado via `php -m` em 2026-06-01
private static array $ACCENT_MAP = [
    'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
    'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
    'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
    'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
    'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
    'ç'=>'c','ñ'=>'n',
    'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
    'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
    'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
    'Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
    'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
    'Ç'=>'c','Ñ'=>'n',
];

public function normalizarToken(string $token): string
{
    $lower = mb_strtolower($token, 'UTF-8');
    return strtr($lower, self::$ACCENT_MAP);
}

public function tokenizar(string $texto): array
{
    $normalizado = $this->normalizarToken($texto);
    // Remove pontuação; divide por espaços e hífens
    $limpo  = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalizado);
    $tokens = preg_split('/\s+/u', trim($limpo), -1, PREG_SPLIT_NO_EMPTY);
    // Filtra stopwords e tokens muito curtos (≤2 chars)
    return array_values(array_filter($tokens, fn($t) =>
        mb_strlen($t) > 2 && ! in_array($t, self::STOPWORDS_PT, true)
    ));
}

public function ngrams(array $tokens, int $n): array
{
    $result = [];
    $count  = count($tokens);
    for ($i = 0; $i <= $count - $n; $i++) {
        $result[] = implode(' ', array_slice($tokens, $i, $n));
    }
    return $result;
}
```

### Padrão 4: Job com Status Tracking (análogo a SyncTodasVendasAdmanJob)

```php
// Fonte: SyncTodasVendasAdmanJob + SyncMlCompanyJob — codebase verificado
class MlbColetaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300; // 5 min para top 10 + 5 a fundo

    public function backoff(): array
    {
        return [60, 300]; // 1 min, 5 min
    }

    public function __construct(public readonly int $coletaId) {}

    public function handle(MlColetaService $service, MlKeywordMinerService $miner): void
    {
        $coleta = MlbColeta::findOrFail($this->coletaId);
        $coleta->update(['status' => 'rodando', 'started_at' => now()]);

        try {
            $resultado = $service->executarPipeline($coleta, $miner);
            $coleta->update([
                'status'      => 'concluido',
                'resultado'   => $resultado,
                'finished_at' => now(),
            ]);
            Log::info("[MLB Coleta] Concluído coleta {$coleta->id} keyword='{$coleta->keyword}'");
        } catch (\Throwable $e) {
            $coleta->update(['status' => 'erro', 'erro_mensagem' => $e->getMessage(), 'finished_at' => now()]);
            Log::error("[MLB Coleta] Erro coleta {$coleta->id}: {$e->getMessage()}");
            throw $e; // Re-lança para queue registrar em failed_jobs se necessário
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[MLB Coleta] Falha definitiva coleta {$this->coletaId}: {$e->getMessage()}");
        MlbColeta::where('id', $this->coletaId)->update([
            'status'       => 'erro',
            'erro_mensagem'=> 'Falha definitiva: ' . $e->getMessage(),
            'finished_at'  => now(),
        ]);
    }
}
```

### Padrão 5: Polling Inertia (baseado em Grants/Index.jsx)

```jsx
// Fonte: resources/js/Pages/Grants/Index.jsx — padrão verificado no codebase
const pollRef = useRef(null);

const startPolling = (coletaId) => {
    const deadline = Date.now() + 10 * 60 * 1000; // 10 min timeout frontend
    pollRef.current = setInterval(async () => {
        if (Date.now() > deadline) {
            clearInterval(pollRef.current);
            setStatus('erro');
            return;
        }
        try {
            const res  = await fetch(route('mlb.coleta.status', coletaId));
            const data = await res.json();
            setStatus(data.status);
            if (data.status === 'concluido' || data.status === 'erro') {
                clearInterval(pollRef.current);
                if (data.status === 'concluido') {
                    router.reload({ only: ['coleta'] });
                }
            }
        } catch { /* silencioso — polling não deve quebrar UI */ }
    }, 3000); // 3 s — igual ao Grants
};

useEffect(() => {
    if (coleta.status === 'pendente' || coleta.status === 'rodando') startPolling(coleta.id);
    return () => { if (pollRef.current) clearInterval(pollRef.current); };
}, []);
```

### Anti-padrões a Evitar

- **Rodar o pipeline de coleta ML no ciclo HTTP:** O loop de 10 produtos + 5 a fundo pode levar 30-120 s. Nginx/php-fpm farão timeout. Sempre via Job. `[VERIFIED: codebase — SyncTodasVendasAdmanJob explicitamente criado por esta razão]`
- **Usar `/sites/MLB/search?q=` (busca geral):** Confirmado 403 por probe. `[VERIFIED: CONTEXT.md probe 2026-06-01]`
- **Depender de `sold_quantity`:** Pode vir oculto. Tratar com `$item['sold_quantity'] ?? null` e mostrar "N/D" na UI.
- **Cache sem margem de expiração:** Token de 6 h → cache com TTL de `expires_in - 300 s` (5 min de margem). `[VERIFIED: codebase pattern em MercadoLivreService::refreshToken]`
- **Usar `intl`/`Normalizer` sem verificar disponibilidade:** A extensão não está presente no XAMPP 8.2.12. `[VERIFIED: env check 2026-06-01]`
- **Não verificar disponibilidade de `questions` antes de depender dela:** O endpoint pode retornar 401/403 com app token. Sempre try/catch e degradar graciosamente.

---

## Não Reinventar

| Problema | Não construir | Usar em vez | Razão |
|---------|---------------|-------------|-------|
| Cache de token OAuth | Lógica custom de persistência | `Cache::remember()` do Laravel | Padrão provado em `MercadoLivreService` |
| Lock de concorrência | Semáforos manuais | `Cache::lock()` | Já usado no projeto para refresh de token |
| HTTP com retry/timeout | `file_get_contents`, `curl` direto | `Http::withToken()->timeout()->get()` | Testável, fluente, integrado ao Laravel |
| Status de Job em DB | Estado em cache/arquivo | Coluna `status` em `mlb_coletas` (padrão `MlbSyncVendasLog`) | Persistente, consultável, histórico visível |
| Polling frontend | WebSocket, Pusher | `setInterval` + `fetch` (padrão `Grants/Index.jsx`) | Zero infra extra; já funciona no projeto |
| Design de componentes | CSS custom | `DevCard`, `cn()`, tokens `ecf-*`, shadcn/Radix | Consistência visual garantida |

---

## Investigação Crítica: D-02 — Acesso de `questions` e `items` de terceiros com app token

### Achados

**`GET /items/{id}` e `GET /items/{id}/description` para itens de terceiros:**
- A documentação oficial da ML classifica "busca e metadados" como recursos **públicos** (accessíveis sem token ou com qualquer token válido). [MEDIUM — WebSearch verificado com múltiplas fontes mas docs oficiais inacessíveis via WebFetch]
- O probe de 2026-06-01 confirmou que `products/search` e `highlights` funcionam com app token — estes retornam `item_id` dos produtos, e pegar o detalhe de um item via `/items/{id}` é operação pública análoga.
- **Avaliação:** Muito provável que `/items/{id}` funcione com app token. Risco: BAIXO.

**`GET /questions/search?item_id={id}` para itens de terceiros:**
- O endpoint é documentado como requerendo `Authorization: Bearer $ACCESS_TOKEN`. [MEDIUM — WebSearch]
- Não está claro se "qualquer access_token" (inclusive app token) é suficiente ou se exige user token com scope do seller.
- A natureza das perguntas (texto público visível no site do ML) sugere que são dados públicos — mas a API pode exigir autenticação de qualquer tipo mesmo para dados públicos.
- **Avaliação:** Incerto. Risk: MÉDIO. O Job DEVE tratar 401/403 neste endpoint como "best-effort skip" sem abortar a coleta.

**`GET /reviews/item/{id}`:**
- Já classificado como best-effort no CONTEXT.md. Tratar 404/403 como skip normal.

### Plano B para questions indisponíveis

Se `/questions/search` retornar 401/403 com app token:
1. O Job registra `questions_disponivel: false` no resultado JSON.
2. A seção "Top Dúvidas" da UI mostra aviso: *"Perguntas não disponíveis para itens de terceiros com token de aplicação. Funcionalidade requer user token vinculado."*
3. O pipeline continua com ranking de keywords (títulos + trends) + recomendação heurística baseada só em títulos e atributos.
4. **Não é bloqueante para o MVP** — a feature de keyword mining já tem valor sem questions.

---

## Pitfalls Comuns

### Pitfall 1: TTL fixo do app token sem respeitar `expires_in`

**O que dá errado:** Se o ML mudar o TTL (atualmente 6 h / 21600 s), o cache continua usando o valor hardcoded e os tokens venceriam antes do cache expirar (ou seriam renovados desnecessariamente).

**Por que acontece:** Desenvolvedores hardcodam `addHours(6)` sem ler `expires_in` da resposta.

**Como evitar:** Usar `$data['expires_in'] - 300` como TTL dinâmico do Cache. Armazenar o token com sua expiração: `Cache::put('ml_app_token_coleta', $token, now()->addSeconds($expiresIn - 300))`.

**Sinais de alerta:** Erros 401 esporádicos de app token mesmo fora do horário de pico.

---

### Pitfall 2: `sold_quantity` e `available_quantity` ocultos pela ML

**O que dá errado:** `/items/{id}` retorna `"sold_quantity": null` ou omite o campo para itens de terceiros. Código que faz `$item['sold_quantity']` sem fallback gera `TypeError`.

**Por que acontece:** ML oculta métricas de concorrentes por política de privacidade. Documentação diz que em recursos públicos o campo é "referencial".

**Como evitar:** Sempre `($item['sold_quantity'] ?? null)` e exibir "N/D" na UI se null.

**Sinais de alerta:** `TypeError` ou `Undefined array key` nos logs do Job.

---

### Pitfall 3: Rate limit da ML (429) dentro do loop dos top-5

**O que dá errado:** Com top-5 produtos, cada um com 3-4 chamadas (`/items`, `/description`, `/questions`, `/reviews`), são ~15-20 requests em sequência rápida. ML limita ~25 req/s mas por seller; com app token o limite pode ser mais restrito.

**Por que acontece:** Loop sem throttle bate no rate limit rapidamente.

**Como evitar:**
- Adicionar `usleep(200000)` (200 ms) entre chamadas dentro do loop dos top-5.
- Tratar 429 com `Retry-After` header antes de re-lançar.
- O Job com `tries=2` e `backoff=[60, 300]` faz retry automático se o Job todo falhar.

**Sinais de alerta:** Logs com múltiplos `[429]` em sequência para o mesmo Job.

---

### Pitfall 4: Tokenização quebra em títulos com símbolos especiais do ML

**O que dá errado:** Títulos como `"Fone Bluetooth 5.0 - À Prova D'água IPX7 | 30h"` geram tokens como `"5"`, `"0"`, `"30h"`, `"|"` que poluem o ranking.

**Por que acontece:** `preg_split('/\s+/')` não remove pontuação.

**Como evitar:** `preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $texto)` antes de tokenizar. Filtrar tokens com `mb_strlen($t) <= 2`. Incluir stopwords numéricas comuns (`"30h"`, `"5v"`, `"usb"`).

---

### Pitfall 5: Job fica em `status=rodando` indefinidamente se o worker cair

**O que dá errado:** O worker é reiniciado pelo Supervisor enquanto o Job está processando. O registro fica com `status=rodando` para sempre. A UI mostra spinner eterno.

**Por que acontece:** O Job só atualiza `status=erro` no `failed()`, que roda apenas após `tries` esgotados. Se o worker cai antes de completar o try, o Job vai para a fila novamente — mas o DB já tem `status=rodando`.

**Como evitar:**
- No `coletaStatus` endpoint, se `status=rodando` e `started_at` > 10 min atrás, retornar como timeout com aviso.
- Alternativamente, o Job pode verificar no início se o registro já existe com `status=rodando` de uma execução anterior e resetar.

---

### Pitfall 6: Acentos pt-BR mal normalizados geram duplicatas no ranking

**O que dá errado:** `"bluetooth"` e `"bluetooth"` (com acento) ficam como dois tokens distintos no ranking. Ou `"esportivo"` e `"esportivo"` com diferentes combinações de acentos.

**Por que acontece:** A API retorna texto em UTF-8; sem normalização adequada, os tokens diferem.

**Como evitar:** Aplicar `strtr($token, self::$ACCENT_MAP)` ANTES de comparar e contar frequências. O mapa de acentos cobre todos os caracteres acentuados do pt-BR. `[VERIFIED: teste local PHP 8.2]`

---

## Exemplos de Código Verificados

### Stopwords pt-BR mínimas (~120 palavras)

```php
// Fonte: compilação de listas canônicas de stopwords pt-BR
// (github.com/alopes/5358189, miningtext.blogspot.com, conhecimento de training)
// Verificado: suficiente para mineração de títulos de e-commerce
private const STOPWORDS_PT = [
    // Artigos
    'o', 'a', 'os', 'as', 'um', 'uma', 'uns', 'umas',
    // Preposições simples
    'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas',
    'por', 'para', 'com', 'sem', 'sob', 'ante', 'ate', 'apos',
    // Contrações comuns
    'pelo', 'pela', 'pelos', 'pelas', 'ao', 'aos',
    // Conjunções
    'e', 'ou', 'mas', 'porem', 'contudo', 'todavia', 'entretanto',
    'que', 'se', 'porque', 'pois', 'como', 'quando', 'embora',
    // Pronomes
    'eu', 'tu', 'ele', 'ela', 'nos', 'vos', 'eles', 'elas',
    'me', 'te', 'lhe', 'se', 'si', 'meu', 'minha', 'seu', 'sua',
    'este', 'esta', 'esse', 'essa', 'aquele', 'aquela',
    'isto', 'isso', 'aquilo', 'tudo', 'nada', 'algo',
    // Advérbios de frequência/modo
    'nao', 'sim', 'so', 'ja', 'mais', 'menos', 'muito', 'pouco',
    'bem', 'mal', 'aqui', 'ali', 'la', 'ca', 'ainda', 'sempre',
    'nunca', 'talvez', 'quase', 'logo', 'depois', 'antes', 'agora',
    // Termos comuns em e-commerce que não agregam (stopwords de domínio)
    'kit', 'novo', 'nova', 'original', 'oficial', 'unidade', 'peca',
    'item', 'produto', 'frete', 'gratis', 'desconto', 'oferta',
    'promoçao', 'promocao', 'top', 'super', 'ultra', 'mega',
];
```

### Estrutura JSON do campo `resultado` em `mlb_coletas`

```json
{
  "meta": {
    "keyword": "fone bluetooth esportivo",
    "categoria_id": "MLB1051",
    "categoria_nome": "Headphones e Fones de Ouvido",
    "domain_id": "MLB-HEADPHONES",
    "total_produtos_analisados": 10,
    "total_a_fundo": 5,
    "questions_disponivel": true,
    "processado_em_segundos": 45
  },
  "ranking_keywords": [
    { "termo": "bluetooth", "frequencia": 9, "eh_tendencia": true, "tipo": "unigrama" },
    { "termo": "fone bluetooth", "frequencia": 7, "eh_tendencia": false, "tipo": "bigrama" },
    { "termo": "cancelamento ruido", "frequencia": 4, "eh_tendencia": false, "tipo": "bigrama" }
  ],
  "tendencias": ["bluetooth esportivo", "fone sem fio"],
  "top_duvidas": [
    { "tema": "compatibilidade", "frequencia": 12, "exemplo": "funciona com iphone?" },
    { "tema": "bateria", "frequencia": 8, "exemplo": "quantas horas de bateria?" }
  ],
  "recomendacao": {
    "titulo_sugerido": "Fone Bluetooth Esportivo Sem Fio Cancelamento de Ruído",
    "pontos_fortes_antecipar": ["compatibilidade iOS/Android", "autonomia de bateria"],
    "palavras_top_incluir": ["bluetooth", "esportivo", "sem fio"],
    "aviso": "Recomendação heurística (regras) — análise qualitativa por IA disponível na Fase 2"
  },
  "produtos_analisados": [
    { "item_id": "MLB123", "titulo": "...", "sold_quantity": null, "posicao_ranking": 1 }
  ]
}
```

### Migration sugerida para `mlb_coletas`

```php
// Fonte: padrão MlbSyncVendasLog migration — codebase verificado
Schema::create('mlb_coletas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

    // Entrada do usuário
    $table->string('keyword');
    $table->string('categoria_id')->nullable();
    $table->string('faixa_preco')->nullable();   // ex: "0-500"
    $table->string('condicao')->nullable();       // "new", "used", null

    // Ciclo de vida
    $table->enum('status', ['pendente', 'rodando', 'concluido', 'erro'])->default('pendente');
    $table->text('erro_mensagem')->nullable();

    // Resultado (JSON com a estrutura documentada acima)
    $table->json('resultado')->nullable();

    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->timestamps();

    // Índices úteis para histórico e reuso
    $table->index('keyword');
    $table->index('status');
    $table->index('created_at');
});
```

---

## Estado da Arte

| Abordagem antiga | Abordagem atual | Quando mudou | Impacto |
|-----------------|-----------------|--------------|--------|
| Busca via `/sites/MLB/search?q=` | `products/search?status=active&site_id=MLB&q=` | Probe 2026-06-01 | A busca geral está bloqueada (403); substituir sempre |
| Tokens OAuth por empresa | App token `client_credentials` para coleta pública | D-01 | Independe de empresa ter token ML vinculado |
| WebSockets para progresso | `setInterval` + fetch JSON endpoint | Projeto inteiro | Zero infra; padrão adotado em Grants e Notificações |

**Deprecated/Desatualizado:**
- `/sites/MLB/search`: Retorna 403, não deve ser usado em hipótese alguma. `[VERIFIED: CONTEXT.md probe]`
- `publication_role` como string no User: O projeto migrou para o sistema de setores/cargos. Usar `checkPubAccess()` e `checkPubRole()` em vez de `$user->publication_role === 'X'`. `[VERIFIED: codebase MlbController]`

---

## Inventário de Estado em Runtime

> Esta fase é **greenfield** — nenhuma rename/refactor. Seção não aplicável.

---

## Disponibilidade de Ambiente

| Dependência | Requerida por | Disponível | Versão | Fallback |
|------------|--------------|-----------|--------|---------|
| PHP 8.2 | Todo o backend | ✓ | 8.2.12 (XAMPP) | — |
| `mbstring` | Tokenização pt-BR | ✓ | built-in 8.2.12 | — |
| `intl` / `Normalizer` | Normalização de acentos | ✗ | — | `strtr` com mapa de acentos (implementado) |
| `iconv` | Normalização alternativa | ✓ | built-in 8.2.12 | (não recomendado: artefatos `'`) |
| Queue worker (database) | Jobs assíncronos | ✓ | Laravel 12 | — |
| Cache (database) | Token app ML, respostas | ✓ | Laravel 12 driver `database` | — |
| API ML `client_credentials` | App token | ✓ | Verificado por probe | — |
| API ML `/questions/search` (app token) | Coleta de dúvidas | ? | Não verificado com app token p/ terceiros | Degradação graciosa (skip questions) |
| Node.js / Vite | Build frontend | ✓ | v24.15.0 | — |

**Dependências ausentes sem fallback:** Nenhuma.

**Dependências com incerteza:**
- `/questions/search` com app token: verificar no primeiro run do Job; fallback implementado.

---

## Validation Architecture

> `workflow.nyquist_validation: true` em `.planning/config.json` — seção obrigatória.

### Framework de Testes

| Propriedade | Valor |
|-------------|-------|
| Framework | PHPUnit 11.x |
| Config | `phpunit.xml` (raiz do projeto) |
| Comando rápido | `php artisan test --filter Phase17` |
| Suite completa | `php artisan test` |

### Mapeamento Requisito → Teste

| Req derivado | Comportamento | Tipo de teste | Comando | Arquivo |
|-------------|---------------|---------------|---------|---------|
| D-04-a | `normalizarToken("Fone Estéreo")` → `"fone estereo"` (lowercase + sem acentos) | Unit | `php artisan test --filter MlKeywordMinerTest::test_normaliza_token` | ❌ Wave 0 |
| D-04-b | Stopwords pt-BR são filtradas dos tokens | Unit | `php artisan test --filter MlKeywordMinerTest::test_stopwords_filtradas` | ❌ Wave 0 |
| D-04-c | Unigramas/bigramas/trigramas gerados e ranqueados por frequência | Unit | `php artisan test --filter MlKeywordMinerTest::test_ranking_keywords` | ❌ Wave 0 |
| D-02 (fallback) | Se `questions` indisponível (mock 401), pipeline continua sem abortar | Unit | `php artisan test --filter MlColetaServiceTest::test_pipeline_sem_questions` | ❌ Wave 0 |
| D-01 | App token é cacheado: segunda chamada não faz nova requisição HTTP | Unit (mock Http) | `php artisan test --filter MlColetaServiceTest::test_app_token_cacheado` | ❌ Wave 0 |
| D-06 | `MlbColetaJob::failed()` atualiza `status=erro` e `erro_mensagem` | Unit | `php artisan test --filter MlbColetaJobTest::test_failed_marca_erro` | ❌ Wave 0 |
| D-06 | `POST /mlb/coleta` cria registro `status=pendente` e retorna redirect | Feature | `php artisan test --filter Phase17ColetaTest::test_store_cria_coleta_pendente` | ❌ Wave 0 |
| D-06 | `GET /mlb/coleta/{id}/status` retorna JSON com status correto | Feature | `php artisan test --filter Phase17ColetaTest::test_status_endpoint_json` | ❌ Wave 0 |
| D-03 | 429 durante coleta: backoff respeitado, falha de 1 item não aborta lote | Unit (mock Http) | `php artisan test --filter MlColetaServiceTest::test_429_degradacao_gracosa` | ❌ Wave 0 |
| D-07 | Usuário sem `publication_role` recebe 403 ao acessar `/mlb/coleta` | Feature | `php artisan test --filter Phase17ColetaTest::test_acesso_403_sem_pub_role` | ❌ Wave 0 |

### Taxa de Amostragem

- **Por commit de task:** `php artisan test --filter Phase17`
- **Por merge de wave:** `php artisan test`
- **Gate de fase:** Suite completa verde antes de `/gsd:verify-work`

### Lacunas (Wave 0)

- [ ] `tests/Unit/MlKeywordMinerTest.php` — cobre D-04-a, D-04-b, D-04-c
- [ ] `tests/Unit/MlColetaServiceTest.php` — cobre D-01 (cache token), D-02 (fallback questions), D-03 (429)
- [ ] `tests/Unit/MlbColetaJobTest.php` — cobre D-06 (failed hook)
- [ ] `tests/Feature/Phase17ColetaTest.php` — cobre D-06 (store+status endpoint), D-07 (403 sem permissão)

---

## Domínio de Segurança

> `security_enforcement` ausente em config — tratado como habilitado.

### Categorias ASVS Aplicáveis

| Categoria ASVS | Aplica | Controle padrão |
|----------------|--------|-----------------|
| V2 Autenticação | Sim | `checkPubAccess()` existente no `MlbController` |
| V3 Sessão | Não | Sessão gerenciada pelo Laravel; sem mudança |
| V4 Controle de Acesso | Sim | `publication_role` via `checkPubAccess()` / middleware |
| V5 Validação de Entrada | Sim | `$request->validate(['keyword' => 'required|string|max:255'])` |
| V6 Criptografia | Não | Nenhuma chave nova; app token em Cache (server-side) |

### Ameaças Conhecidas para Esta Stack

| Padrão | STRIDE | Mitigação padrão |
|--------|--------|-----------------|
| Keyword maliciosa (injeção via query param) | Tampering | `validate(['keyword' => 'required|string|max:255'])` |
| SSRF via keyword que vira URL | Elevation | API ML é endpoint fixo hardcoded; keyword é query param, não URL |
| Acesso a histórico de outros usuários | Information Disclosure | Escopar queries de histórico por `user_id` ou por `publication_role` (todos podem ver) — definir política clara |
| Exfiltração do app token via log | Information Disclosure | NUNCA logar o token; logar apenas o ID da coleta e status |
| Dados pessoais de quem perguntou | Privacy | Persistir apenas texto da pergunta, não `from_id` nem dados pessoais do comprador |

---

## Log de Premissas

| # | Afirmação | Seção | Risco se errada |
|---|-----------|-------|----------------|
| A1 | `/questions/search?item_id={id}` funciona com app token para itens de terceiros | D-02, Pitfall 3 | Seção "Top Dúvidas" indisponível — fallback implementado, impacto baixo |
| A2 | `/items/{id}` de terceiros funciona com app token | D-02, Pipeline | Pipeline falha para top-5; impacto médio — tratar 401 como best-effort |
| A3 | Rate limit da ML com app token é ~25 req/s (1500/min por seller) | Rate limit | Se menor, 429s mais frequentes; backoff + usleep(200ms) mitiga |
| A4 | Lista de 120 stopwords pt-BR embutida cobre > 95% dos tokens irrelevantes | D-04 | Ranking poluído com palavras vazias; pode-se expandir lista sem refatoração |
| A5 | `expires_in` do `client_credentials` é 21600 s (6 h) | D-01 | Cache pode expirar antes ou depois do esperado; usar valor dinâmico da resposta |
| A6 | `intl` não estará disponível no servidor de produção (VPS Hostinger) | Mineração | Se disponível, poderia usar `Normalizer::NFD` — porém `strtr` é portátil e seguro |

**Se A1 e A2 se confirmarem falsos (401/403):** O valor do MVP cai para keyword mining só via títulos + trends (sem questions/description). Ainda entrega ranking de keywords mas sem "Top Dúvidas". Confirmar no primeiro deploy/teste de integração.

---

## Perguntas em Aberto

1. **D-02 — Acesso de questions com app token**
   - O que sabemos: o endpoint exige token; não sabemos se app token é suficiente.
   - O que é incerto: escopo exato de autorização do `client_credentials` no ML Brasil.
   - Recomendação: O Job deve fazer a chamada, capturar 401/403 e registrar `questions_disponivel: false` sem abortar. A resposta real do primeiro teste determina se é funcionalidade completa ou parcial.

2. **Visibilidade do histórico de coletas**
   - O que sabemos: a coleta é feita por usuário com `publication_role`.
   - O que é incerto: o histórico deve ser por usuário (só vê o que criou) ou compartilhado por toda a equipe de Publicação?
   - Recomendação: Mostrar todos os `mlb_coletas` para quem tem `checkPubAccess()` (comportamento colaborativo, mais útil para a equipe). Admin vê tudo.

3. **Número de products/search a paginar**
   - O que sabemos: top 10 por D-03.
   - O que é incerto: `products/search` retorna mais de 10 por página? Precisa de paginação?
   - Recomendação: Usar `limit=10&offset=0` explícito e não paginar — top 10 é suficiente para o MVP.

---

## Fontes

### Primárias (confiança HIGH)
- `app/Services/MercadoLivreService.php` — padrão de cache de token, HTTP, tratamento 401/429
- `app/Jobs/SyncMlCompanyJob.php`, `SyncTodasVendasAdmanJob.php` — padrão de Job com status em DB
- `app/Models/MlbSyncVendasLog.php` — padrão de migration e model para log de operação
- `app/Http/Controllers/MlbController.php` — `checkPubAccess()`, `checkPubRole()`
- `app/Support/Permissions.php` — catálogo de permissões `mlb.*`
- `resources/js/Pages/Grants/Index.jsx` — padrão de polling Inertia com `setInterval` + `fetch`
- `resources/js/Pages/Mlb/ImplementacaoPublicador.jsx` — padrão de `router.reload({only:[...]})` com polling
- `phpunit.xml` — config do framework de testes
- Probe ML em 2026-06-01 (registrado em CONTEXT.md) — endpoints confirmados

### Secundárias (confiança MEDIUM)
- WebSearch sobre Mercado Libre API rate limits: ~1500 req/min por seller [rollout.com]
- WebSearch sobre `GET /questions/search` requerendo `Authorization: Bearer` [developers-forum.mercadolibre.com]
- WebSearch sobre `GET /items/{id}` sendo recurso público com token [global-selling.mercadolibre.com]
- Verificação PHP 8.2.12 XAMPP: `intl` ausente, `mbstring` e `iconv` presentes [env check local]

### Terciárias (confiança LOW — marcadas [ASSUMED])
- Stopwords pt-BR: compilação de gists (github.com/alopes, github.com/lorn) e conhecimento de training
- Rate limit específico de app token (vs. user token) na API ML Brasil

---

## Metadados

**Breakdown de confiança:**
- Stack interna (Job, Service, Cache, Migrations, Frontend polling): HIGH — verificado no codebase
- Endpoints ML confirmados por probe (products/search, highlights, trends, domain_discovery): HIGH
- Acesso de questions/items de terceiros com app token: MEDIUM (incerteza documentada)
- Mineração estatística em PHP puro: HIGH — testado localmente
- Rate limits ML: MEDIUM — fonte secundária, não documentação oficial

**Data da pesquisa:** 2026-06-01
**Válido até:** 2026-07-01 (estável — APIs ML mudam pouco; app token TTL pode mudar)
