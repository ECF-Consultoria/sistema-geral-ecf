# Phase 95: NPS Anti-Burlamento — UI de confiança admin-only - Research

**Researched:** 2026-07-16
**Domain:** Laravel 12 + Inertia + React — payload condicional por role dentro de uma página já existente (`/nps`)
**Confidence:** HIGH (100% baseado em leitura direta do código já entregue nas Fases 68-94; zero dependência nova, zero API externa)

## Summary

A Fase 95 NÃO cria página nova nem rota nova. Ela adiciona campos condicionais ao payload Inertia que **já existe** em `NpsController::index()` (`Inertia::render('Nps/Index', ...)`) e consome esses campos no componente **já existente** `resources/js/Pages/Nps/Index.jsx`. A listagem, os cards e o modal "Ver respostas" (`Dialog` `modalSurvey`) já recebem cada `survey` inteiro dentro do array paginado `surveys.data` — não existe endpoint de detalhe separado. Isso significa que a "Seção de auditoria no detalhe" (AB-95-2) é apenas **mais chaves no mesmo item do array**, não uma nova querystring/rota.

Toda a matéria-prima da Fase 94 já está persistida e pronta para leitura: `nps_surveys` tem o rastro de abertura (`first_opened_at`/`last_opened_at`/`open_count`/`open_ip_address`/`open_user_agent`), `nps_responses` tem o rastro de resposta + veredito (`response_ip_address`/`response_user_agent`/`response_duration_seconds`/`is_suspicious`/`suspicion_reasons` — este último já persistido como objeto `{reasons: string[], severity: 'nenhuma'|'media'|'alta'}`, pt-BR, pronto para consumo direto), e `nps_survey_events` tem a trilha completa (`generated`/`opened`/`submitted`/`expired`/`sent_email`/`sent_digisac`) com `metadata` json por evento. **Nada precisa ser recalculado** — a Fase 95 é 100% leitura + gating + apresentação.

O padrão de blindagem correto é já usado dentro do próprio `NpsController::index()` para a lista de responsáveis: por-role, a decisão fica no **backend**, nunca só no front. A rota `/nps` (`nps.index`) não tem middleware `role:admin` — é uma página compartilhada onde o controller decide o que cada `$user` recebe. O gate a copiar é `$request->user()->isAdmin()` (mesmo padrão usado em `index()` linha 129 e em `resources/js/Pages/Nps/Index.jsx` linha 861: `auth?.user?.role === 'admin'`).

**Primary recommendation:** Adicionar as chaves `confianca` (badge) e `auditoria` (seção detalhe) ao array retornado pelo `->through()` de `NpsController::index()` **somente dentro de um `if ($user->isAdmin())`** — nunca computá-las e depois esconder no front. Reaproveitar `NpsSurveyEvent` (já eager-load de `company`/`generatedBy`/`response.*`) para derivar canal de envio e timeline, evitando 2 queries extras contra `nps_email_envios`/`nps_digisac_envios`. Copiar o padrão de badge tri-estado de `StatusBadge` (Sugadores/Index.jsx) e o padrão de filtro validado via query string do próprio `mes_filtro` (linha 46-49 do controller) para o novo filtro `confianca`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Gate admin-only do payload (AB-95-4) | API/Backend (`NpsController::index()`) | — | Blindagem tem que nascer no servidor; o front só pode renderizar o que chegou |
| Cálculo do veredito de suspeita (tri-estado) | Database/Storage (já persistido pela Fase 94) | API/Backend (leitura + mapeamento pt-BR) | `NpsSuspicionService` já rodou no submit (Fase 94); Fase 95 só LÊ `is_suspicious`/`suspicion_reasons.severity` |
| Badge visual na listagem (AB-95-1) | Browser/Client (`Nps/Index.jsx`) | API/Backend (fornece `confianca.status`) | Componente puro de apresentação — cor deriva de um enum já resolvido no backend |
| Seção de auditoria no detalhe (AB-95-2) | API/Backend (monta objeto `auditoria`) | Browser/Client (renderiza dentro do `Dialog` já existente) | Mesmo modal atual (`modalSurvey`), sem rota nova |
| Filtro de confiança (AB-95-3) | API/Backend (valida querystring, aplica no `$baseQuery`) | Browser/Client (`<Select>` visível só se `isAdmin`) | Precisa ser server-side porque afeta paginação; validação de segurança tem que estar no backend |
| Canal de envio (email/Digisac/manual) | API/Backend (deriva de `nps_survey_events` já carregado) | Database/Storage (`nps_email_envios`/`nps_digisac_envios` como fallback, não usado por padrão) | Evita 2 queries extras; `events()` já é relação do model |

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AB-95-1 | Badge de confiança (verde/amarelo/vermelho) na listagem, só admin | `is_suspicious` + `suspicion_reasons.severity` já persistidos (Fase 94); mapeamento de severidade → cor documentado abaixo (§ Pattern 2); padrão de badge a copiar: `StatusBadge` em `resources/js/Pages/Sugadores/Index.jsx:96-107` |
| AB-95-2 | Seção "Auditoria" no detalhe (gerado em/por, aberto em, respondido em, tempo até resposta, IPs, user-agent, canal, motivos) | Todos os campos já existem em `nps_surveys`/`nps_responses`/`nps_survey_events` — nenhum precisa ser calculado; ver mapeamento completo campo-a-campo em § Code Examples |
| AB-95-3 | Filtro Todos/Confiáveis/Com alerta/Suspeitos, só admin, backend valida query string | Copiar exatamente o padrão de `$mesFiltro` (`NpsController.php:46-49`) — regex/whitelist, fallback silencioso, nunca erro |
| AB-95-4 | Payload de não-admin não pode conter nenhum campo de suspeita/auditoria | Já existe o padrão exato dentro do mesmo `index()`: `pode_filtrar_por_pessoa` é boolean sempre presente, mas os DADOS sensíveis (ex.: notas por pessoa) só aparecem quando a query já filtrou por carteira — aqui o padrão a copiar é literal: `if ($user->isAdmin()) { $item['confianca'] = ...; $item['auditoria'] = ...; }` dentro do `->through()`, nunca populando a chave para os demais roles |

</phase_requirements>

## Standard Stack

Nenhuma dependência nova. A fase reutiliza 100% do stack já instalado:

### Core (já em uso, nada a instalar)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/framework` | ^12.0 | Controller/Eloquent — leitura dos campos já persistidos | Já é o stack do projeto |
| `inertiajs/inertia-laravel` + `@inertiajs/react` | ^2.0 | Ponte payload → props React | Já é o stack do projeto |
| `@radix-ui/react-dialog` (via `Components/ui/dialog`) | já instalado | Reaproveitar o `Dialog` "Ver respostas" existente | Zero novo componente de modal |
| `lucide-react` | ^1.11.0 | Ícones do badge/seção de auditoria (ex.: `ShieldCheck`, `ShieldAlert`, `MapPin`, `Monitor`) | Já é o padrão de ícones do projeto |
| `clsx` + `tailwind-merge` (via `cn()`) | já instalado | Composição de classe do badge tri-estado | Padrão obrigatório do projeto (`resources/js/lib/utils.js`) |

### Alternativas Consideradas
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Ler `nps_survey_events` para canal de envio | Ler `nps_email_envios`/`nps_digisac_envios` diretamente | 2 queries extras (`whereIn('survey_id', ...)`) por página; `nps_survey_events` já vem na mesma eager-load array e é append-only (fonte de verdade da Fase 94) — mais barato e já ordenado |
| Filtro `confianca` server-side (novo query param) | Filtro client-side como o `activeStatus` (chips Todos/Respondidos/Pendentes) já existente | Client-side não pode ser "ignorado" com segurança pro não-admin (dado já estaria no payload) nem preserva paginação corretamente — CONTEXT exige validação backend, então precisa ser query string real |

**Instalação:** nenhuma (`composer install`/`npm install` não mudam).

## Package Legitimacy Audit

**Não aplicável** — esta fase não instala nenhum pacote novo (backend ou frontend). Nenhuma linha de `composer.json`/`package.json` muda. Gate do protocolo de legitimidade de pacotes: N/A, nada a auditar.

## Architecture Patterns

### System Architecture Diagram

```
GET /nps (nps.index, sem middleware role:admin)
        │
        ▼
NpsController::index(Request $request)
        │
        ├─ $user = $request->user()
        ├─ $mesFiltro = validado via regex (padrão já existente)
        ├─ NOVO: $confiancaFiltro = validado via whitelist (mesmo padrão do $mesFiltro)
        │         → se $user->isAdmin() === false: parâmetro é IGNORADO (nunca aplicado,
        │           nunca gera erro/403 — não pode revelar que o filtro existe)
        │
        ▼
$baseQuery = NpsSurvey::with([
        'company', 'generatedBy',
        'response.respostasCustomizadas', 'response.answers',
        'response.survey.template', 'response.scoreAssignments.user',
        NOVO: 'events',          ← eager-load da trilha (Fase 94, já relação hasMany)
      ])
      ->where(mês) ->orderBy(created_at desc)
        │
        ├─ if (!$user->isAdmin()) → escopo por carteira (já existe)
        ├─ $aplicarFiltrosSurveys($baseQuery)  (empresa/estrategista/analista/template — já existe)
        ├─ NOVO: if ($user->isAdmin() && $confiancaFiltro !== 'todos')
        │           → aplica whereHas('response', fn($q) => ... is_suspicious/severity ...)
        │
        ▼
$surveys = $baseQuery->paginate(20)->through(function ($s) use ($user, $notaDe, $extrasDe, $responsaveisDe) {
        $item = [ ...todos os campos atuais, IDÊNTICOS para todo mundo... ];

        if ($user->isAdmin()) {
            $item['confianca']  = $this->confiancaDe($s->response);   // NOVO — tri-estado
            $item['auditoria']  = $this->auditoriaDe($s);             // NOVO — seção detalhe
        }
        // Para não-admin: as chaves 'confianca'/'auditoria' SIMPLESMENTE NÃO EXISTEM no array.

        return $item;
      })
        │
        ▼
Inertia::render('Nps/Index', [
        ...props atuais (idênticos)...,
        NOVO: 'pode_ver_confianca' => $user->isAdmin(),   // flag pra UI mostrar filtro/coluna
        NOVO: 'filtros.confianca' => $confiancaFiltro,     // só populado quando isAdmin
      ])
        │
        ▼
Nps/Index.jsx
        ├─ TableCard: renderiza <ConfiancaBadge> na linha SÓ SE item.confianca existir
        │             (nunca `isAdmin && algumDadoQueJaVeioDoServidor` — o dado simplesmente
        │              não está lá pro não-admin, então `s.confianca &&` já resolve)
        ├─ GlassSelect novo: filtro Todos/Confiáveis/Com alerta/Suspeitos,
        │             renderizado só se `pode_ver_confianca` (mesmo padrão de
        │             `pode_filtrar_por_pessoa` já existente linha 1041)
        └─ Dialog "Ver respostas" (modalSurvey): nova seção "Auditoria" dentro do
                      mesmo modal, renderizada só se `modalSurvey.auditoria` existir
```

### Recommended Project Structure

Nenhum arquivo novo obrigatório — a fase é uma extensão cirúrgica de 2 arquivos já existentes:

```
app/Http/Controllers/NpsController.php   # index(): + 2 helpers privados (confiancaDe/auditoriaDe) + filtro confianca
resources/js/Pages/Nps/Index.jsx         # + componente ConfiancaBadge + filtro + seção Auditoria no Dialog
tests/Feature/Phase95/                   # NOVO diretório — nenhum teste desta fase existe ainda
  └─ NpsConfiancaPayloadTest.php         # cobre AB-95-1/AB-95-2/AB-95-4 (payload admin vs não-admin)
  └─ NpsConfiancaFiltroTest.php          # cobre AB-95-3 (filtro backend + ignorado por não-admin)
```

### Pattern 1: Blindagem de payload condicional por role (AB-95-4)

**What:** Construir o array do item SEMPRE igual para todo mundo, e só depois `if ($user->isAdmin())` adicionar chaves extras — nunca montar tudo e filtrar/esconder depois.
**When to use:** Qualquer payload Inertia com dado sensível a role.
**Example:**
```php
// Source: app/Http/Controllers/NpsController.php:242-264 (padrão já existente, estendido)
$surveys = $baseQuery->paginate(20)->withQueryString()->through(function ($s) use ($user, $notaDe, $extrasDe, $responsaveisDe) {
    $item = [
        'id'                 => $s->id,
        'token'              => $s->token,
        'company_name'       => $s->company->name,
        // ...todos os campos atuais, IDÊNTICOS para admin e não-admin...
    ];

    // AB-95-4 — só admin recebe estas duas chaves. Para outros roles elas
    // simplesmente NÃO EXISTEM no array (não são null, não são omitidas por
    // ->only() no front — nunca chegam a sair do servidor).
    if ($user->isAdmin()) {
        $item['confianca'] = $this->confiancaDe($s->response);
        $item['auditoria'] = $this->auditoriaDe($s);
    }

    return $item;
});
```

### Pattern 2: Tri-estado de confiança a partir do veredito já persistido

**What:** Mapear `is_suspicious` + `suspicion_reasons.severity` (já gravado pela Fase 94 como `{reasons: string[], severity: 'nenhuma'|'media'|'alta'}`) para os 3 estados do CONTEXT.
**When to use:** Helper privado `confiancaDe(?NpsResponse $response)` no controller.
**Regra exata (documentada nos SUMMARYs 94-01/94-02, `NpsSuspicionService::evaluate()`):**
- `severity === 'nenhuma'` (ou resposta sem `is_suspicious`) → **verde** = "Confiável"
- `severity === 'media'` → **amarelo** = "Atenção"
- `severity === 'alta'` → **vermelho** = "Suspeita"
- Resposta legada (sem os campos da Fase 94 — `is_suspicious` default `false`, `suspicion_reasons` `null`) → cai automaticamente em "Confiável" (comportamento correto: nada de suspeito foi detectado porque a captura não existia, e não há downgrade de confiança para dados antigos)
```php
// Source: app/Services/Nps/NpsSuspicionService.php:50 (regra travada da Fase 94)
private function confiancaDe(?NpsResponse $response): ?array
{
    if (! $response) {
        return null; // survey ainda pendente — sem resposta, sem veredito
    }
    $severity = $response->suspicion_reasons['severity'] ?? 'nenhuma';
    $status = match ($severity) {
        'alta'  => 'suspeita',
        'media' => 'atencao',
        default => 'confiavel',
    };
    return [
        'status'  => $status,
        'motivos' => $response->suspicion_reasons['reasons'] ?? [],
    ];
}
```

### Pattern 3: Filtro validado via query string (mesmo molde do `mes`)

**What:** Copiar EXATAMENTE o padrão de `$mesFiltro` (regex + fallback silencioso, nunca 422/403).
**When to use:** Novo filtro `confianca`.
```php
// Source: app/Http/Controllers/NpsController.php:46-49 (padrão já existente, adaptado)
$confiancaValidos = ['todos', 'confiavel', 'atencao', 'suspeita'];
$confiancaParam = $request->input('confianca', 'todos');
$confiancaFiltro = in_array($confiancaParam, $confiancaValidos, true) ? $confiancaParam : 'todos';

// AB-95-3 — não-admin NUNCA aplica o filtro, mesmo que mande o parâmetro na URL.
// Não retorna erro nem 403 (isso revelaria que o filtro existe) — o parâmetro
// é simplesmente ignorado e a query roda como se não tivesse vindo.
if ($user->isAdmin() && $confiancaFiltro !== 'todos') {
    $baseQuery->whereHas('response', function ($q) use ($confiancaFiltro) {
        if ($confiancaFiltro === 'confiavel') {
            $q->where(function ($qq) {
                $qq->whereNull('suspicion_reasons')
                   ->orWhereRaw("JSON_EXTRACT(suspicion_reasons, '$.severity') = 'nenhuma'")
                   ->orWhereRaw("JSON_EXTRACT(suspicion_reasons, '$.severity') IS NULL");
            });
        } elseif ($confiancaFiltro === 'atencao') {
            $q->whereRaw("JSON_EXTRACT(suspicion_reasons, '$.severity') = 'media'");
        } elseif ($confiancaFiltro === 'suspeita') {
            $q->whereRaw("JSON_EXTRACT(suspicion_reasons, '$.severity') = 'alta'");
        }
    });
}
```
**Nota de driver:** `JSON_EXTRACT` funciona em MySQL/MariaDB; SQLite (testes) também suporta `JSON_EXTRACT` nativamente desde SQLite 3.38+ (Laravel usa a extensão json1 automaticamente) — **validar no plano** se a versão do SQLite do ambiente de teste suporta, ou usar `whereJsonContains`/cast em PHP como fallback mais portátil (`$response->suspicion_reasons['severity']` já é array no model, então uma alternativa mais simples e cross-driver é filtrar em PHP depois do `get()` — mas isso quebra paginação server-side. Recomendação: usar os operators nativos do Eloquent para JSON (`->where('suspicion_reasons->severity', 'alta')`), que o Laravel já traduz corretamente por driver — **preferir esta forma** à `whereRaw` acima por ser cross-driver nativo do framework.

### Pattern 4: Auditoria — todos os campos já existem, é só ler

```php
// Source: campos confirmados em app/Models/NpsSurvey.php + NpsResponse.php + NpsSurveyEvent.php
private function auditoriaDe(NpsSurvey $s): array
{
    $eventos = $s->events; // já eager-loaded — 'events' na query principal
    $temEmail   = $eventos->contains('event_type', 'sent_email');
    $temDigisac = $eventos->contains('event_type', 'sent_digisac');
    $origemGerado = $eventos->firstWhere('event_type', 'generated')?->metadata['origem'] ?? null;

    $canal = match (true) {
        $temEmail && $temDigisac => 'Email + Digisac',
        $temEmail                => 'Email',
        $temDigisac              => 'Digisac',
        $origemGerado === 'manual' => 'Manual (link gerado por admin)',
        default                  => 'Não confirmado',
    };

    return [
        'gerado_em'         => $s->created_at->format('d/m/Y H:i'),
        'gerado_por'        => $s->generatedBy?->name,             // null = disparo mensal automático
        'aberto_primeira'   => $s->first_opened_at?->format('d/m/Y H:i'),
        'aberto_ultima'     => $s->last_opened_at?->format('d/m/Y H:i'),
        'aberto_contagem'   => $s->open_count,
        'respondido_em'     => $s->completed_at?->format('d/m/Y H:i'),
        'tempo_ate_resposta'=> $s->response?->response_duration_seconds,
        'ip_abertura'       => $s->open_ip_address,
        'ip_resposta'       => $s->response?->response_ip_address,
        'user_agent'        => $s->response?->response_user_agent ?? $s->open_user_agent,
        'canal'             => $canal,
        'motivos'           => $s->response?->suspicion_reasons['reasons'] ?? [],
    ];
}
```

### Anti-Patterns to Avoid

- **Gate só no React (`{isAdmin && <Coluna/>}` sem esconder o dado no backend):** viola literalmente AB-95-4. O teste automatizado exigido pelo CONTEXT (inspecionar payload) tem que provar ausência da CHAVE no JSON, não apenas ausência visual.
- **Recalcular severidade/suspeita no frontend:** a Fase 94 já persistiu o veredito — a Fase 95 é consumidora pura. Recalcular duplicaria a regra de negócio em 2 lugares (drift garantido quando a Fase 96 mudar a janela/IPs).
- **Retornar 403 quando não-admin manda `?confianca=suspeita`:** um 403 já denuncia que o filtro existe (uma pessoa curiosa descobre a feature escondida por tentativa/erro). O padrão correto (idêntico ao `$mesFiltro` inválido) é ignorar silenciosamente e continuar como se o parâmetro não existisse.
- **Adicionar 2 queries novas contra `nps_email_envios`/`nps_digisac_envios`:** desnecessário — `nps_survey_events` (já relação `hasMany` do `NpsSurvey`, fácil de eager-load junto com o resto) já contém tudo que se precisa para "canal de envio" via `event_type`.
- **Recomputar `response_duration_seconds` a partir de timestamps na Fase 95:** já existe a coluna gravada pela Fase 94 (`NpsResponse::response_duration_seconds`) — reusar, nunca recalcular via `diffInSeconds` (pitfall documentado na Fase 94: ordem do diff no Carbon 3 é signed e já foi uma armadilha real).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Matching de IP/CIDR interno | Parser de CIDR manual | Nada — já resolvido em `NpsSuspicionService` (Fase 94) via `Symfony\Component\HttpFoundation\IpUtils` | Fase 95 nunca precisa tocar em IP matching; só lê o resultado já persistido |
| Cálculo de severidade | Nova lógica de "quão suspeito é isso" | `suspicion_reasons.severity` já persistido | Regra de negócio pertence à Fase 94/96, não à camada de apresentação |
| Filtro de query string | Middleware novo / FormRequest novo só pra isso | Validação inline no controller (mesmo padrão do `$mesFiltro`) | Consistência com o resto do arquivo; menos uma classe pra manter |
| Badge tri-estado | Componente novo do zero | Copiar a estrutura de `STATUS_LABELS`/`STATUS_BADGE`/`StatusBadge` de `Sugadores/Index.jsx` | Já é o padrão visual pt-BR + tokens `ecf-*` estabelecido no projeto |

**Key insight:** Esta fase não introduz NENHUMA lógica de negócio nova — 100% do trabalho pesado (captura, avaliação, persistência) já foi feito na Fase 94. Qualquer plano que proponha recalcular, reavaliar ou reimplementar algo que já existe em `NpsSuspicionService`/`NpsSurveyEvent` está fora de escopo e deve ser cortado.

## Common Pitfalls

### Pitfall 1: Vazamento de dado sensível via renderização condicional no front
**What goes wrong:** Um dev implementa `{isAdmin && <BadgeConfianca survey={s} />}` no JSX, mas `s.confianca`/`s.auditoria` já vieram no payload JSON para TODO MUNDO (o React só decide não desenhar).
**Why it happens:** É o caminho mais rápido de "fazer funcionar visualmente" — parece certo porque o não-admin não VÊ nada.
**How to avoid:** A chave tem que estar ausente do array PHP antes de virar JSON. Teste automatizado deve inspecionar o payload bruto (`assertInertia`/`toArray()['props']`), nunca o DOM renderizado.
**Warning signs:** Se o teste de blindagem usa `assertDontSee()` (HTML) em vez de checar o array de props, ele não pega esse bug — Inertia não faz SSR de HTML tradicional, o JSON vai inteiro no `<script>` da página independente do que o React desenha depois.

### Pitfall 2: Rollup elimina variável de escopo usada dentro de `.map()` (memória do projeto)
**What goes wrong:** Flags booleanas derivadas fora do `.map()` (ex.: `const corBadge = ...` calculado no corpo do componente, referenciado dentro de um `.map()` de motivos/eventos) podem sumir no bundle de produção (`npm run build`), gerando `ReferenceError` só em produção, nunca em dev.
**Why it happens:** Otimização de escopo do Rollup em produção quebra referências de variáveis de componente reaproveitadas dentro de closures de `.map()`.
**How to avoid:** Toda flag/cor/label usada dentro de um `.map()` (ex.: ao mapear `auditoria.motivos` ou os eventos de uma timeline) precisa ser **computada dentro do próprio callback do map**, nunca herdada de uma variável externa ao componente. Já existe precedente CORRETO no próprio arquivo: `templates.map(t => { const value = String(t.id); ... })` (linha 1178) e `empresasElegiveis.map(c => { const value = String(c.id); ... })` (linha 1214) — ambos comentados explicitamente como "Pitfall 4 (Rollup): derivar value dentro do map".
**Warning signs:** Funciona perfeito em `npm run dev`, mas a tela quebra (tela branca ou erro no console) só depois de `npm run build` + deploy.

### Pitfall 3: N+1 ou query pesada ao adicionar `events` ao eager-load
**What goes wrong:** Adicionar `'events'` ao array de `with([...])` do `NpsSurvey::with([...])` sem `select` explícito pode trazer `metadata` (json, potencialmente grande) para TODAS as 20 surveys da página mesmo quando só um subconjunto é admin ou tem eventos relevantes.
**Why it happens:** Eager-load carrega tudo por padrão.
**How to avoid:** É aceitável (volume baixo — no máximo ~6 eventos por survey, 20 surveys/página = ~120 rows pequenas), mas **evite** repetir esse eager-load nas outras 3 queries do método (`$responsesMes`, a série de 12 meses) — essas já iteram sobre `NpsResponse`, não `NpsSurvey`, e não precisam de `events` (cards/gráfico não mostram auditoria).
**Warning signs:** Tempo de resposta do `/nps` sobe visivelmente quando `mes` filtra um período com muitas empresas.

### Pitfall 4: Query string revelando a existência do filtro para não-admin
**What goes wrong:** Se o backend responder com comportamento DIFERENTE quando um não-admin manda `?confianca=suspeita` (erro, redirect, ou até uma lista vazia diferente do padrão), a pessoa descobre que o filtro existe por tentativa e erro.
**Why it happens:** É tentador "validar e rejeitar" query strings desconhecidas.
**How to avoid:** Comportamento tem que ser **byte-idêntico** ao de não mandar o parâmetro — nem 403, nem 422, nem log de auditoria visível ao usuário. Mesmo padrão do `$mesFiltro` inválido (cai no mês atual, sem alarde).
**Warning signs:** Teste que faz GET com `?confianca=suspeita` como não-admin e o response difere em ORDEM ou CONTAGEM de itens do GET sem o parâmetro.

### Pitfall 5: Confundir `is_suspicious` com o único sinal de UI
**What goes wrong:** Usar só `is_suspicious` (boolean) para decidir cor do badge, ignorando `severity`, resultaria em só 2 estados (verde/vermelho) em vez dos 3 exigidos pelo CONTEXT (verde/amarelo/vermelho).
**Why it happens:** `is_suspicious` é o campo "óbvio" à primeira vista; `severity` está um nível mais fundo dentro do JSON `suspicion_reasons`.
**How to avoid:** Sempre ler `suspicion_reasons['severity']` (`'nenhuma'|'media'|'alta'`) para decidir a cor — `is_suspicious` é só `severity !== 'nenhuma'` (dado derivado, não precisa nem ser lido separadamente).

## Code Examples

### Componente de badge tri-estado (copiando o padrão `StatusBadge`)
```jsx
// Source: padrão de resources/js/Pages/Sugadores/Index.jsx:13-30,96-107
const CONFIANCA_LABELS = {
    confiavel: 'Confiável',
    atencao:   'Atenção',
    suspeita:  'Suspeita',
};
const CONFIANCA_BADGE = {
    confiavel: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30',
    atencao:   'bg-amber-500/15 text-amber-300 border-amber-500/30',
    suspeita:  'bg-rose-500/15 text-rose-300 border-rose-500/30',
};
function ConfiancaBadge({ confianca }) {
    if (!confianca) return null; // não-admin nunca recebe esta prop — nem chega a existir
    return (
        <span
            className={cn('inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border', CONFIANCA_BADGE[confianca.status])}
            title={confianca.motivos?.join(' ') || undefined}
        >
            {CONFIANCA_LABELS[confianca.status]}
        </span>
    );
}
```

### Teste de blindagem de payload (molde: `NpsResponsaveisPayloadTest.php`)
```php
// Source: molde tests/Feature/V16/NpsResponsaveisPayloadTest.php (propsDoIndex/surveyNoPayload)
#[Test]
public function test_payload_de_nao_admin_nao_contem_nenhum_campo_de_suspeita_ou_auditoria(): void
{
    // ...cria survey respondido com resposta marcada suspeita (severity='alta')...

    $naoAdmin = User::factory()->create(['role' => 'consultor', 'active' => true]);
    $props = $this->propsDoIndex($naoAdmin); // helper já existe no molde V16
    $item  = $this->surveyNoPayload($props, $survey->token);

    $this->assertNotNull($item);
    $this->assertArrayNotHasKey('confianca', $item);
    $this->assertArrayNotHasKey('auditoria', $item);
    // Blindagem redundante: nenhuma chave suspeita solta em qualquer nível do item.
    $json = json_encode($item);
    $this->assertStringNotContainsString('is_suspicious', $json);
    $this->assertStringNotContainsString('suspicion_reasons', $json);
    $this->assertStringNotContainsString('response_ip_address', $json);
    $this->assertStringNotContainsString('open_ip_address', $json);
}
```

## State of the Art

Não aplicável no sentido tradicional (não há "abordagem antiga vs nova" — é a primeira UI desta camada). Único ponto de evolução dentro da própria iniciativa:

| Estado anterior | Estado desta fase | Quando mudou | Impact |
|--------------|------------------|---------------|--------|
| Dados de suspeita existem no banco mas são invisíveis em qualquer UI (Fase 94) | Admin enxerga badge/filtro/auditoria; outros roles seguem cegos | Fase 95 (esta) | Primeira exposição visual da camada anti-burlamento — psicologicamente importante: se vazar pra não-admin, o valor de "detecção silenciosa" (Fase 96 vai endurecer) é perdido |

**Não confundir com:** a Fase 96 (endurecimento — bloqueio ativo, IPs pela UI, invalidação manual) é a PRÓXIMA fase, fora de escopo aqui.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Nome do novo query param proposto (`confianca`, valores `todos/confiavel/atencao/suspeita`) | Pattern 3 / Standard Stack | Baixo — é só uma escolha de nomenclatura; o CONTEXT não trava o nome exato, só o comportamento (Todos/Confiáveis/Com alerta/Suspeitos). O plano pode renomear sem impacto arquitetural |
| A2 | Derivar "canal de envio" de `nps_survey_events` (em vez de `nps_email_envios`/`nps_digisac_envios`) é mais barato e suficiente | Pattern 4 / Standard Stack (Alternativas) | Médio — se o plano decidir que precisa do `erro_msg`/`destinatario` completo do envio (não só a confirmação de sucesso), pode ser necessário fazer join extra com `NpsEmailEnvio`/`NpsDigisacEnvio`. Isso é exatamente a decisão que o CONTEXT deixou como "Claude's Discretion" — plano deve decidir explicitamente |
| A3 | Usar operador nativo do Eloquent para JSON (`->where('suspicion_reasons->severity', ...)`) funciona igual em MySQL/MariaDB e SQLite (testes) | Pattern 3 | Médio — precisa ser confirmado empiricamente no plano (rodar teste local antes de travar a abordagem); se não funcionar em SQLite, fallback é filtrar em PHP com `->get()->filter()` seguido de paginação manual (mais caro, mas cross-driver garantido) |

## Open Questions (RESOLVED)

> Resolução (plan-phase 2026-07-16): **Q1** → filtro server-side (query param real, afeta paginação) — plano 95-01 Task 2. **Q2** → seção de auditoria expõe só campos agregados, canal derivado de `nps_survey_events` sem query extra — plano 95-01 Task 1. **Q3** → topologia de proxy do VPS é pendência operacional pós-deploy (herdada da Fase 94), não bloqueia o código desta fase.

1. **Filtro de confiança: server-side (paginado) ou client-side (como os chips de status já existentes)?**
   - What we know: os chips `Todos/Respondidos/Pendentes/Expirados` existentes são 100% client-side (`activeStatus`, filtra `surveys.data` da página atual só).
   - What's unclear: o CONTEXT exige "validação no backend" para o filtro de confiança — isso implica que TEM que ser query string real (afetando a paginação), não um filtro client-side sobre os 20 itens da página atual.
   - Recommendation: implementar como filtro server-side (novo query param), consistente com `mes`/`empresa_id`/`template_id` já existentes — NÃO como um 5º chip client-side ao lado dos de status.

2. **A seção de auditoria mostra a timeline completa de eventos (`nps_survey_events` linha a linha) ou só os campos agregados listados no CONTEXT?**
   - What we know: o CONTEXT lista campos agregados específicos (gerado em/por, aberto em [first/last+contagem], respondido em/tempo, IPs, user-agent, canal, motivos) — não menciona uma timeline crua.
   - What's unclear: se o plano deve expor os 6 tipos de evento com timestamps individuais (mais rico, mais trabalho de UI) ou só os campos agregados (mais simples, cobre 100% do requisito escrito).
   - Recommendation: implementar só os campos agregados do CONTEXT (Pattern 4 acima já cobre 100% deles) — é a opção mais barata que satisfaz AB-95-2 literalmente. Se o usuário quiser a timeline crua depois, é uma extensão trivial de UI sobre dado que já vai estar carregado (`$s->events`).

3. **Verificação de topologia de proxy do VPS (Regra 1 do `NpsSuspicionService`) ainda está pendente pós-deploy da Fase 94.**
   - What we know: o SUMMARY 94-03 documenta que o IP real do cliente só será confiável em produção depois de uma verificação manual pós-deploy (`bootstrap/app.php` `trustProxies`) — não bloqueia o CÓDIGO da Fase 95, mas pode afetar a PRECISÃO do badge vermelho/amarelo em produção até essa verificação ser feita.
   - What's unclear: se essa verificação já foi feita entre a conclusão da Fase 94 (2026-07-16) e o início do planejamento desta fase.
   - Recommendation: o plano da Fase 95 não precisa bloquear por isso (é responsabilidade operacional, não de código), mas pode registrar um lembrete no VERIFICATION/STATE.md se ainda não tiver sido confirmado.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.50 (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `php artisan test --filter=Phase95` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AB-95-1 | Badge `confianca.status` correto (confiavel/atencao/suspeita) para admin, ausente para outros roles | Feature (Inertia payload) | `php artisan test --filter=NpsConfiancaPayloadTest` | ❌ Wave 0 |
| AB-95-2 | Objeto `auditoria` completo (todos os campos do CONTEXT) presente para admin | Feature (Inertia payload) | `php artisan test --filter=NpsConfiancaPayloadTest` | ❌ Wave 0 |
| AB-95-3 | Filtro `confianca` aplica corretamente para admin; ignorado (sem erro, sem diferença) para não-admin | Feature (HTTP + query string) | `php artisan test --filter=NpsConfiancaFiltroTest` | ❌ Wave 0 |
| AB-95-4 | Payload de não-admin NUNCA contém `is_suspicious`/`suspicion_reasons`/IPs/user-agent/timestamps de abertura | Feature (Inertia payload + assert JSON bruto) | `php artisan test --filter=NpsConfiancaPayloadTest` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Phase95`
- **Per wave merge:** `php artisan test --filter=Nps` (regressão completa do módulo NPS — hoje 250/250 pós-Fase 94)
- **Phase gate:** `php artisan test` completo (baseline atual da suite) + `npm run build` (fase toca `Nps/Index.jsx`) antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase95/NpsConfiancaPayloadTest.php` — cobre AB-95-1, AB-95-2, AB-95-4 (molde: `tests/Feature/V16/NpsResponsaveisPayloadTest.php`, reaproveitar helpers `propsDoIndex`/`surveyNoPayload`/`admin()`)
- [ ] `tests/Feature/Phase95/NpsConfiancaFiltroTest.php` — cobre AB-95-3
- [ ] Framework install: nenhum — PHPUnit já configurado, molde de teste já existe no projeto

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | não | Fora de escopo — autenticação já resolvida (Breeze/sessão) |
| V3 Session Management | não | Fora de escopo |
| V4 Access Control | **sim** | Gate `$user->isAdmin()` aplicado no backend antes de montar o array de resposta (nunca em middleware de rota, porque a página é compartilhada) |
| V5 Input Validation | **sim** | Novo query param `confianca` validado via whitelist fixa (`in_array` estrito), fallback silencioso para `'todos'` — mesmo padrão de `$mesFiltro` |
| V6 Cryptography | não | Nenhum dado é cifrado/decifrado nesta fase |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Vazamento de dado sensível (suspeita/IP/user-agent) via payload Inertia para role não autorizado | Information Disclosure | Construir o array condicionalmente no backend (`if ($user->isAdmin())`), nunca esconder só na renderização; teste automatizado que inspeciona o JSON bruto do payload, não o DOM |
| Descoberta de feature oculta via probing de query string (`?confianca=suspeita` como não-admin) | Information Disclosure | Parâmetro desconhecido/não autorizado é ignorado silenciosamente — resposta byte-idêntica à ausência do parâmetro, sem 403/422/log visível |
| Escalada de leitura via manipulação de `template_id`/`empresa_id` combinada com o novo filtro | Information Disclosure / Tampering | O escopo por carteira (`whereIn('company_id', ...)`) já é aplicado ANTES de qualquer filtro adicional (linha 129-132 do controller) — o novo filtro de confiança deve ser adicionado DENTRO do `if ($user->isAdmin())`, nunca substituindo o escopo de carteira existente |

## Sources

### Primary (HIGH confidence — leitura direta do código já entregue)
- `app/Http/Controllers/NpsController.php` (linhas 1-394) — `index()` completo, padrão de filtro/paginação/eager-load/gate de role já em produção
- `app/Models/NpsSurvey.php`, `app/Models/NpsResponse.php`, `app/Models/NpsSurveyEvent.php` — shape exato de colunas/relações
- `app/Services/Nps/NpsSuspicionService.php` — regra exata de severidade e textos pt-BR das 4 regras
- `config/nps.php` — configuração de IPs internos/janela (Fase 94, não tocada nesta fase)
- `database/migrations/2026_07_16_100002_*` e `2026_07_16_100003_*` — schema exato das colunas novas da Fase 94
- `app/Console/Commands/NpsDispararMensal.php` (linhas 240-410) — confirma que `sent_email` e `sent_digisac` podem coexistir na mesma survey (dispatch independente)
- `app/Models/NpsEmailEnvio.php`, `app/Models/NpsDigisacEnvio.php` — alternativa de fonte pro canal de envio (não escolhida como padrão)
- `resources/js/Pages/Nps/Index.jsx` (arquivo completo, 1339 linhas) — estrutura de tabela/modal/filtros já existente a estender
- `resources/js/Pages/Sugadores/Index.jsx` (linhas 13-30, 96-107) — padrão `StatusBadge`/`STATUS_LABELS`/`STATUS_BADGE` a copiar
- `tests/Feature/V16/NpsResponsaveisPayloadTest.php` — molde exato de teste de payload Inertia por role (`propsDoIndex`/`surveyNoPayload`/`admin()`)
- `.planning/phases/94-.../94-01-SUMMARY.md`, `94-02-SUMMARY.md`, `94-03-SUMMARY.md` — decisões travadas e shape final dos dados
- `.planning/ROADMAP.md` (seção Fase 94/95/96) — requisitos e success criteria oficiais
- `.planning/phases/95-.../95-CONTEXT.md` — decisões locked do usuário (PRD Express Path)
- `routes/web.php` (grep de rotas `nps.*`) — confirma ausência de middleware `role:admin` na rota `nps.index`
- `app/Models/User.php` (linha 60) — `isAdmin()` como método canônico de gate

### Secondary (MEDIUM confidence)
- Nenhuma — todo o research desta fase foi resolvido por leitura direta do código-fonte do próprio projeto, sem necessidade de fontes externas (nenhuma lib nova, nenhuma API externa).

### Tertiary (LOW confidence)
- Nenhuma.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero dependência nova, tudo já instalado e em uso
- Architecture: HIGH — padrão de gate condicional já existe no mesmo arquivo (`pode_filtrar_por_pessoa`), só precisa ser aplicado de forma mais estrita (chaves ausentes, não só flag booleana)
- Pitfalls: HIGH — pitfalls 1, 2, 4 confirmados por decisões/documentação já escritas no próprio código-fonte e nas memórias do projeto; pitfall 3 é uma extrapolação razoável (MEDIUM) sobre volume de dados

**Research date:** 2026-07-16
**Valid until:** Indefinido para os fatos internos (schema/models não mudam sem nova migration) — mas **reabrir se a Fase 96 (endurecimento) for planejada antes desta, ou se a verificação de topologia de proxy do VPS (Open Question 3) revelar que a Regra 1 está incorreta em produção**.
