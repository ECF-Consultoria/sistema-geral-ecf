---
phase: 17
phase_name: Coleta de Dados ML (Fase 1 — sem IA)
gathered: 2026-06-01
status: Ready for planning
source: Decisões travadas com o usuário + probe ao vivo da API ML (2026-06-01)
---

# Phase 17 Context — Coleta de Dados ML (Fase 1 — sem IA)

<domain>
## Domain Boundary

Nova feature do **módulo Publicação/MLB**: dado uma palavra-chave de produto, o sistema
coleta dados de produtos ranqueados no Mercado Livre **via API oficial** (app token),
minera estatisticamente as keywords mais usadas pelos concorrentes top, agrupa as
principais dúvidas dos clientes (perguntas dos anúncios) e gera uma **recomendação
heurística** (baseada em regras, sem IA) de título/descrição para o nosso anúncio.

**Capacidade entregue:** Página nova no módulo MLB onde o usuário (acesso por
`publication_role`) digita uma keyword + filtros opcionais, dispara uma coleta
assíncrona, acompanha o progresso, e ao final vê um relatório com: ranking de keywords
dos concorrentes + tendências, top dúvidas/objeções das perguntas, e uma recomendação
heurística de título+descrição. Coletas ficam persistidas para histórico e reuso.

**Out of scope desta fase (Fase 1):**
- **Camada de IA** (Claude/Anthropic) — análise qualitativa e geração de texto pela IA é Fase 2. Nenhuma integração Anthropic é adicionada agora.
- **Reviews** como fonte garantida — best-effort; se indisponível, usa só perguntas.
- Scraping de HTML (proibido — só API oficial).
- Coleta agendada/automática (a Fase 1 é sob demanda, disparada pelo usuário).

</domain>

<decisions>
## Implementation Decisions

### D-01 — Autenticação: APP TOKEN (client_credentials), NÃO user token

**Locked.** A coleta usa um **app token** obtido via `grant_type=client_credentials`
contra `https://api.mercadolibre.com/oauth/token` com `ML_CLIENT_ID` + `ML_CLIENT_SECRET`
(já em `config/services.mercadolivre`). NÃO usa o token por empresa (`MlToken`) — a coleta
é independente de qualquer empresa conectada.

- O app token deve ser **cacheado** até expirar (com margem de segurança), evitando
  reemissão a cada chamada. Padrão de cache análogo ao `ml_advertiser_{id}` em `MercadoLivreService`.
- Verificado por probe (2026-06-01): client_credentials retorna token com scope amplo
  (`read`, ads, metrics, orders, etc.).

### D-02 — Endpoints da API ML (o que funciona)

**Locked — confirmado por probe ao vivo (2026-06-01):**

| Uso | Endpoint | Status |
|-----|----------|--------|
| ❌ Busca por keyword (NÃO USAR) | `GET /sites/MLB/search?q=` | **403 forbidden** (bloqueado pelo ML p/ todos) |
| Produtos por keyword (substitui a busca) | `GET /products/search?status=active&site_id=MLB&q={kw}` | ✅ 200 |
| Ranking best-sellers por categoria | `GET /highlights/MLB/category/{cat}` | ✅ 200 (posição 1..N) |
| Keywords mais buscadas | `GET /trends/MLB` e `GET /trends/MLB/{cat}` | ✅ 200 |
| Keyword → categoria/domínio | `GET /sites/MLB/domain_discovery/search?q={kw}` | ✅ 200 |
| Detalhe do item | `GET /items/{id}` e `GET /items/{id}/description` | ✅ 200 (próprio seller); confirmar p/ terceiros |
| Perguntas do anúncio | `GET /questions/search?item={id}` | confirmar acesso p/ itens de terceiros com app token |
| Reviews do anúncio | `GET /reviews/item/{id}` | best-effort — pode estar indisponível; cair p/ só perguntas |

> ⚠️ Ponto a validar na pesquisa/plano: se `/questions/search` e `/items/{id}` de itens de
> **terceiros** (não do próprio seller) respondem com app token. Se exigirem user token,
> ajustar o pipeline (a parte de catálogo/trends/highlights já está garantida com app token).

### D-03 — Volume e profundidade

**Locked.** Top **10 produtos** por coleta (via `products/search`/`highlights`);
análise a fundo (`/items`, `/items/.../description`, `/questions`, reviews best-effort)
nos **5 melhores**. Respeitar rate limit: paginar, **backoff em 429**, cachear respostas.

### D-04 — Mineração estatística (sem IA)

**Locked.** Tokenizar títulos dos top-ranqueados → normalizar (lowercase, remover acentos,
remover **stopwords pt-BR**) → contar frequência de **unigramas/bigramas/trigramas** →
ranking de keywords. Cruzar com `/trends` para sinalizar quais keywords são tendência.
A "análise de cliente" agrupa perguntas por frequência de termos/temas (estatístico).

### D-05 — Recomendação heurística (sem IA)

**Locked.** A recomendação de título/descrição da Fase 1 é **baseada em regras**:
ex. título sugerido = combinação das keywords top + atributos da categoria; objeções a
antecipar = perguntas mais frequentes. Deixar explícito na UI que é heurística (texto
redigido por IA é Fase 2).

### D-06 — Assíncrono + persistência + logging

**Locked.**
- Coleta roda em **Job** (queue driver `database`), nunca no ciclo HTTP. Padrão de
  `SyncMlCompanyJob` (`tries`, `timeout`, `backoff()`, `failed()`).
- Status visível na UI: **pendente / rodando / concluído / erro** (polling via Inertia,
  sem WebSockets — padrão do projeto).
- Logging com tag **`[MLB Coleta]`** incluindo id+nome da entidade.
- Capturar `\Throwable`, **logar e seguir** — falha de 1 item não derruba o lote.
- Persistir coleta + resultado em **migration + models** (histórico e reuso).

### D-07 — Frontend

**Locked.** Página nova em `resources/js/Pages/Mlb/`. Sem state global (só `useState` +
`useForm` Inertia). Design system `ecf-*` (dark `#050507`/`#0f1116`, amarelo `#ffe600`),
componente `DevCard`, util `cn()`, primitivos shadcn/Radix em `resources/js/Components/ui/`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Integração ML / app token / padrão de chamadas
- `app/Services/MercadoLivreService.php` — padrão de chamada HTTP autenticada, paginação, tratamento 401/erros, cache de `advertiser_id` (modelo p/ cachear o app token), tags de log
- `config/services.php` — `services.mercadolivre` (`client_id`, `client_secret`) usados p/ o app token

### Padrão de Job assíncrono
- `app/Jobs/SyncMlCompanyJob.php` — `tries`/`timeout`/`backoff()`/`failed()`; logging de falha definitiva
- `app/Models/MlbSyncVendasLog.php` e `app/Models/AdmanSyncLog.php` — padrões de log de sync persistido

### Módulo MLB (controller, rotas, páginas, acesso)
- `app/Http/Controllers/MlbController.php` — onde adicionar as actions; padrão `checkPubAccess()`/`publication_role`
- `routes/web.php` — rotas nomeadas do módulo MLB (Ziggy)
- `resources/js/Pages/Mlb/` (ex.: `Vendas.jsx`, `Publicacoes.jsx`, `Historico.jsx`) — padrão de página, status, tabelas
- `app/Support/Permissions.php` + `resources/js/Layouts/AppLayout.jsx` — gating por `publication_role` e item de navegação

### Design system
- `resources/js/Components/ui/` (shadcn/Radix), `resources/js/lib/utils.js` (`cn()`), `tailwind.config.js` (tokens `ecf.*`)

### Memória do projeto
- `project_ml_api_search_restriction` (auto-memory) — detalhes do probe e endpoints OK/bloqueados

</canonical_refs>

<specifics>
## Specific Ideas

- Entrada: 1+ palavra-chave (ex.: "fone bluetooth esportivo") + filtros opcionais
  (categoria, faixa de preço, condição novo/usado).
- Pipeline do Job: keyword → `domain_discovery` (keyword→categoria) → `products/search`
  (top 10) → `highlights` (ranking) → top 5 a fundo (`/items` + `/description` +
  `/questions` + reviews best-effort) → `/trends` → mineração estatística → persistir.
- `sold_quantity` e métricas podem vir **ocultas** — tratar graciosamente (não quebrar).
- Não persistir dados pessoais de quem perguntou/avaliou além do necessário.

</specifics>

<deferred>
## Deferred Ideas (Fase 2+)

- **Camada de IA (Claude/Anthropic)**: análise qualitativa de perguntas/reviews e geração
  de título/descrição em JSON estruturado com prompt caching (modelo a definir — Sonnet/Opus/Haiku).
- Reviews como fonte primária (se/quando o endpoint estiver disponível na conta).
- Coleta agendada/recorrente e comparação histórica entre coletas da mesma keyword.

</deferred>

---

*Phase: 17-coleta-de-dados-ml*
*Context gathered: 2026-06-01 — decisões travadas com o usuário + probe ML ao vivo*
