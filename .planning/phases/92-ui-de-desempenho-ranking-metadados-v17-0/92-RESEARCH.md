# Fase 92: UI de Desempenho — ranking + metadados (v17.0) - Research

**Pesquisado em:** 2026-07-17
**Domínio:** Inertia/React (payload de controller + tabela de ranking) + correção de distorção estatística em PHP (comparação de pares)
**Confiança:** HIGH (todo o código relevante foi lido diretamente nesta sessão — sem dependência de lib externa nova, sem Context7 necessário)

## Summary

A Fase 91 já fez o trabalho pesado: `DesempenhoScoreService::compute()` retorna HOJE os 6 metadados de elegibilidade (`empresas_unicas`, `vinculos_servico`, `vinculos_financeiros`, `vinculos_sem_fonte_financeira`, `score_status`, `componentes_disponiveis`) e já força `nota_final=null` quando `score_status='blocked'`. Nenhum desses campos, porém, chega à UI — o `PerformanceController::index()` reconstrói manualmente o array do ranking (linhas 119-140) e simplesmente não repassa as 6 chaves novas; `Performance/Index.jsx` e `Performance/Show.jsx` não têm nenhuma menção a `score_status` (confirmado por grep — zero ocorrências). Esta fase é, portanto, majoritariamente um trabalho de **passthrough de payload + 1 badge + 1 filtro de auditoria**, mais uma **correção de bug real** herdada como pendência explícita da Fase 91: o bloco `comparacaoContextual` em `PortfolioController::show()` (linhas 1454-1567) trata um profissional `blocked` como se tivesse nota `0.0` na comparação com os pares, e conta esse profissional no `tamanho_amostra` mesmo quando ele é excluído do cálculo real da mediana — essa distorção é vista pelo PRÓPRIO profissional na FaixaBonusCard/comparação de `Portfolio/Show.jsx` (self-view), não é um detalhe de admin.

O filtro de auditoria por setor (DESEMP-08 SC3) deve reusar literalmente o padrão `?contexto=todos|performance|shopee` que a Fase 90 já estabeleceu em `PortfolioController::contextoFiltro()` — inclusive nomeando o parâmetro `contexto` (não `setor`, que já tem outro significado — cargo organizacional via `user_setores` — dentro do próprio `PerformanceController`). Este filtro é só de VISUALIZAÇÃO: o `score_status`/`nota_final` que chega da Fase 91 já é o cálculo único e correto — a Fase 92 não deve recomputar nada por setor, só condicionar o que aparece na tela via `CarteiraContextService::forUser($user, ['setor' => ...])` para exibição de detalhe.

**Primary recommendation:** Fazer passthrough dos 6 metadados no `PerformanceController::index()`/`show()` (sem tocar o service), adicionar badge de `score_status` na tabela e no card de detalhe com labels sem jargão, corrigir a distorção A/B do `comparacaoContextual` em `PortfolioController::show()` excluindo `blocked` da comparação de pares e separando `tamanho_amostra` do N real por componente, e adicionar o filtro `?contexto=` (view-only) na tela de ranking reusando o padrão da Fase 90.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Cálculo de `nota_final`/`score_status`/metadados de elegibilidade | API/Backend (`DesempenhoScoreService`) | — | Já implementado na Fase 91; Fase 92 é consumidora, não recalcula |
| Repasse dos metadados ao Inertia (`ranking[]`, `resultado`) | API/Backend (`PerformanceController`) | — | Controller monta arrays próprios (não usa o array do service direto) — precisa mapear as 6 chaves novas |
| Correção da comparação de pares (`comparacaoContextual`) | API/Backend (`PortfolioController::show`) | — | Bug de exibição em cálculo agregado feito no controller, não no service |
| Filtro `?contexto=` (visualização) | API/Backend (query param) + Browser (select) | — | Mesmo padrão da Fase 90: parseia no controller, aplica via `CarteiraContextService::forUser(..., ['setor'=>...])`, nunca recalcula score |
| Badges/labels de status (`Bloqueada`/`Parcial`/`Oficial`) | Browser (React) | — | Puramente apresentação — sem lógica de negócio |
| Página `/performance` (ranking) e `/performance/{user}` (drill-down) | Frontend Server (SSR via Inertia) | Browser | Inertia::render já entrega os dados calculados no backend; front só formata |

## User Constraints

Não existe `92-CONTEXT.md` nesta pasta de fase — não há `/gsd:discuss-phase` prévio registrado para a Fase 92. Os "constraints" efetivos vêm do ROADMAP.md (Success Criteria da Fase 92), do `REQUIREMENTS.md` (DESEMP-08) e do plano canônico `plano-carteira-desempenho-multi-servico.md` (seções "UI de Desempenho" e "Fase 5"), tratados abaixo como requisitos travados (não como preferências abertas de pesquisa).

### Decisões implícitas (tratadas como locked pelo ROADMAP/plano canônico)

- Ranking permanece ÚNICO — proibido bifurcar em telas/rankings por marketplace (SC1 da Fase 92; DESEMP-02 já travado na Fase 91).
- Cada linha do ranking exibe: empresas únicas, vínculos de serviço, vínculos sem fonte financeira, status da nota (oficial/parcial/bloqueada) — SC2.
- Filtro de auditoria por setor (Todos/Performance/Shopee) muda só a visualização — nunca recalcula nem persiste um segundo score oficial — SC3.
- Correção do `comparacaoContextual` (pendência formal da Fase 91, ver `91-02-SUMMARY.md` seção 3) é bloqueador explícito desta fase, mesmo não estando no texto do ROADMAP.

### Claude's Discretion

- Onde exatamente encaixar os 4 metadados na linha do ranking (coluna extra vs. tooltip vs. expandir) — research recomenda abaixo.
- Rótulo exato do badge de status (`Bloqueada` vs. `Sem nota (aguarda régua Shopee)`) — ver seção Pitfalls, regra do projeto "evitar jargão sem explicação".
- Se o filtro `?contexto=` fica em `/performance` (ranking) e/ou em `/performance/{user}` (drill-down) — research recomenda: ranking primeiro (SC3 fala de "UI de Desempenho" genericamente); drill-down é nice-to-have.

### Deferred Ideas (OUT OF SCOPE)

- Fonte financeira de Shopee (API/importação) — fora de escopo da milestone inteira.
- Régua de bônus para Shopee sem financeiro — decisão de diretoria pendente; esta fase só EXIBE o status `blocked`, não resolve a política.
- Reorganização de menu (Gestão ECF) — é a Fase 93, não tocar aqui.
- Reprocessamento de meses fechados (`desempenho:consolidar-mes` retroativo) — declaração de escopo explícita da Fase 91 (91-02-SUMMARY.md §4.1); jamais rodar automaticamente.

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| DESEMP-08 | A UI de Desempenho mantém ranking único e exibe os metadados por profissional (empresas únicas, vínculos, vínculos sem fonte, status da nota); filtros de auditoria por setor não criam segundo score oficial | Seções "Anatomia do ranking", "Status bloqueada na UI" e "Filtro de auditoria por setor" abaixo — mapeiam exatamente que chaves faltam no payload do `PerformanceController` e como reusar o padrão `?contexto=` da Fase 90 sem recalcular score |

</phase_requirements>

## Standard Stack

Nenhuma lib nova. Stack 100% herdada do projeto (Laravel 12 + Inertia + React 18 + Tailwind `ecf-*` + lucide-react + `cn()`). Não há `## Package Legitimacy Audit` porque nenhum pacote externo é instalado nesta fase.

## Architecture Patterns

### Diagrama de fluxo de dados (estado atual → o que a Fase 92 adiciona)

```
DesempenhoScoreService::compute()/computeCached()  [Fase 91 — JÁ RETORNA os 6 metadados]
        │
        │  shape completo (nota_final, score_status, empresas_unicas,
        │  vinculos_servico, vinculos_financeiros, vinculos_sem_fonte_financeira,
        │  componentes_disponiveis, ...)
        ▼
PerformanceController::index()  ── linha 98-141 `$rankingRaw = $users->map(...)`
        │
        │  ⚠ GARGALO ATUAL: reconstrói array próprio, NÃO repassa os 6
        │  metadados novos (só empresas_carteira/empresas_com_baseline/
        │  sem_carteira/nota_final/faixa_bonus/componentes.*)
        │
        │  ── Fase 92 adiciona: mapear as 6 chaves novas para o array
        │     de cada linha + aplicar `?contexto=` (opcional, view-only)
        ▼
Inertia::render('Performance/Index', ['ranking' => [...]])
        │
        ▼
Performance/Index.jsx → RankingConsultoria()  ── grid de colunas fixo
        │
        │  ⚠ zero menção a score_status/empresas_unicas/vinculos_* hoje
        │  ── Fase 92 adiciona: badge de status + metadados (coluna/tooltip)
        ▼
Browser (linha do ranking clicável → performance.show)


PortfolioController::show()  ── bloco comparacaoContextual (linhas 1454-1567)
        │
        │  ⚠ BUG: $minhaNota = (float)(nota_final ?? 0.0) — blocked vira 0.0
        │  ⚠ BUG: tamanho_amostra conta blocked que a mediana já exclui
        │
        │  ── Fase 92 corrige: excluir blocked da comparação de pares +
        │     tamanho_amostra = N real por componente (ou N de scoresPares
        │     elegíveis, documentado explicitamente)
        ▼
Inertia::render('Portfolio/Show', ['comparacao_contextual' => [...]])
        │
        ▼
Portfolio/Show.jsx  ── EXIBE "vs X analistas" (linha 1154) direto ao
                        PRÓPRIO profissional (self-view, não só admin)
```

### Recommended Project Structure

Nenhum arquivo novo de estrutura — a fase edita arquivos existentes:

```
app/Http/Controllers/
├── PerformanceController.php     # index() linhas 98-141, show() linhas 1018-1030 — mapear metadados novos + filtro ?contexto=
└── PortfolioController.php       # show() linhas 1454-1567 — corrigir comparacaoContextual (Distorção A + B)

resources/js/Pages/Performance/
├── Index.jsx                     # RankingConsultoria() — adicionar badge score_status + metadados (linha ~369-486)
└── Show.jsx                      # FaixaBonusCard() — mostrar badge score_status ao lado da nota (linha ~129-218)

tests/Feature/V17/                # convenção já usada pelas Fases 88-91 (não "Phase92")
└── (novo arquivo) — cobrir SC1-SC3 + a correção do comparacaoContextual
```

### Padrão 1: Passthrough de metadados sem recomputar

**O quê:** O `compute()`/`computeCached()` já retorna o shape completo. Em vez de o controller reconstruir manualmente cada chave (como faz hoje nas linhas 119-140 de `index()`), a Fase 92 só PRECISA adicionar as 6 chaves que faltam ao array já montado — sem tocar no service, sem recalcular nada.
**Quando usar:** Sempre que o controller já chama `computeCached()` e descarta parte do shape.
**Exemplo (chaves faltando hoje em `PerformanceController::index()`, dentro do `return [...]` de `$rankingRaw = $users->map(...)`, ~linha 119):**
```php
// Fase 91 já retorna estas 6 chaves em $resultado — só faltam no array final:
'empresas_unicas'               => (int) ($resultado['empresas_unicas'] ?? 0),
'vinculos_servico'               => (int) ($resultado['vinculos_servico'] ?? 0),
'vinculos_financeiros'           => (int) ($resultado['vinculos_financeiros'] ?? 0),
'vinculos_sem_fonte_financeira'  => (int) ($resultado['vinculos_sem_fonte_financeira'] ?? 0),
'score_status'                   => $resultado['score_status'] ?? 'blocked',
'componentes_disponiveis'        => $resultado['componentes_disponiveis'] ?? null,
```
ATENÇÃO: quando a linha vem do snapshot mensal (`$snap->breakdown_json`, linhas 104-109), essas chaves TAMBÉM já estão presentes — o `ConsolidarMesDesempenho` (consumidor #5 auditado na Fase 91) grava `$result` (shape completo do `compute()`) inteiro na coluna `breakdown_json` (91-02-SUMMARY.md linha "o shape completo do compute() é gravado inteiro na coluna"). Não há necessidade de fallback especial — só usar `?? default` como o resto do array já faz.

**`PerformanceController::show()` (linha 1018-1030) já passa `'resultado' => $resultado` INTEIRO** para o Inertia (sem desestruturar) — os 6 metadados JÁ CHEGAM em `Performance/Show.jsx` sem nenhuma mudança de backend. O trabalho ali é 100% frontend (consumir `resultado.score_status` etc., hoje ignorado).

### Padrão 2: Filtro `?contexto=` — reusar literalmente o padrão da Fase 90

**O quê:** `PortfolioController::contextoFiltro()` (linhas 78-85) já implementa exatamente o contrato pedido pelo SC3: whitelist explícita (`todos`/`performance`/`shopee`), nunca repassa valor cru, mapeia para `Servico::SETOR_PERFORMANCE`/`Servico::SETOR_SHOPEE`.
**Quando usar:** Copiar o MESMO método (ou extrair para um trait/helper compartilhado, à discrição do planner) para `PerformanceController`, nomeando o parâmetro `contexto` — não `setor`, que já tem outro significado em `PerformanceController::index()` (linha 30: `$setor = 'consultoria'` é o SETOR ORGANIZACIONAL da tela, não o setor do vínculo de serviço).
**Exemplo (código real já em produção, `PortfolioController.php` linhas 78-85):**
```php
// Source: app/Http/Controllers/PortfolioController.php:78-85
private function contextoFiltro(Request $request): array
{
    return match ($request->query('contexto')) {
        'performance' => ['param' => 'performance', 'setor' => Servico::SETOR_PERFORMANCE],
        'shopee'      => ['param' => 'shopee', 'setor' => Servico::SETOR_SHOPEE],
        default       => ['param' => 'todos', 'setor' => null],
    };
}
```
**Uso correto (view-only) em `PerformanceController`:** o filtro NÃO deve entrar em `DesempenhoScoreService::compute()` (que não tem parâmetro de setor e não deve ganhar um — DESEMP-02 proíbe segundo score). O uso correto é: (a) se o filtro serve só para destacar/ocultar linhas cujo `vinculos_sem_fonte_financeira`/`score_status` casem com o setor pedido, isso é um filtro CLIENT-SIDE em cima do `ranking` já calculado (React `.filter()`, análogo ao já existente `rankingFiltrado`/`semCarteira` em `Index.jsx` linhas 156-164); OU (b) se o SC3 exige uma auditoria mais profunda (ex.: "quais vínculos desse profissional são Shopee"), isso já existe em `CarteiraContextService::forUser($user, ['setor' => $contextoFiltro['setor']])` — mas essa chamada por-usuário é cara em loop (N+1) para a tela de RANKING (11-20 users); recomenda-se aplicar o filtro no DRILL-DOWN (`/performance/{user}`, 1 user só) e não no ranking agregado, OU fazer o filtro client-side no ranking usando os contadores já presentes no payload (sem nova query).

### Anti-Patterns to Avoid

- **Criar um segundo cálculo de score por setor:** proibido por DESEMP-02 (já travado/testado na Fase 91 via gate de ausência — `grep -rin "score_shopee\|score_ml"` deve continuar retornando 0). O filtro `?contexto=` é estritamente de exibição.
- **Chamar `CarteiraContextService::forUser()` dentro do loop de 11-20 users do ranking:** o service é rápido (queries locais, sem HTTP — ver docblock do próprio service, decisão #1), mas ainda assim é uma query por user; evitar N+1 desnecessário quando o filtro pode ser aplicado client-side sobre os contadores já calculados.
- **Reescrever `medianaPares`/`percentil` do zero:** a correção da Distorção A/B é cirúrgica — mudar só o predicado de inclusão de `blocked` e a fonte do `tamanho_amostra`, sem tocar no resto da lógica estatística já testada implicitamente em produção.
- **Rótulos técnicos crus na UI** (`blocked`/`partial`/`official` literais): viola a regra do projeto "evitar jargão sem explicação em qualquer UI" (feedback registrado 2026-07-07). Sempre mapear para label pt-BR.

## Don't Hand-Roll

Nada a "não hand-rollar" nesta fase — não há problema genérico (auth, validação de formulário, parsing de data) sendo resolvido aqui. É glue code de payload + 1 correção de agregação estatística já implementada localmente (mediana/percentil já existem em `PortfolioController.php`, só precisam de um predicado de exclusão a mais).

## A Pendência (comparacaoContextual) — análise detalhada

Localização exata: `app/Http/Controllers/PortfolioController.php`, método `show()`, bloco a partir da linha 1454 (comentário `// ── Phase 74 D-05 — Nota final v2 + comparacao contextual`) até a linha 1567.

### Distorção A — `null → 0.0` na comparação de pares (linha 1497)

```php
// app/Http/Controllers/PortfolioController.php:1497
$minhaNota = (float) ($meuResultado['nota_final'] ?? 0.0);
```

Quando `$meuResultado['score_status'] === 'blocked'` (ex.: profissional só-Shopee), `nota_final` é `null` (comportamento travado pela Fase 91, `91-01`). O coalesce transforma isso em `0.0` — na UI (`Portfolio/Show.jsx` linha 1154+), o profissional vê a si mesmo comparado como se tivesse tirado a pior nota possível, quando na verdade ele simplesmente não tem nota calculável ainda.

**Correção mínima recomendada:** excluir explicitamente os pares (e o próprio user, se for o caso) com `score_status === 'blocked'` de `$scoresPares` ANTES do bloco de comparação — não só do cálculo da mediana (que já filtra `is_numeric` implicitamente via `medianaPares`), mas da amostra inteira. Hoje o único filtro de exclusão de `$scoresPares` é `sem_carteira === true` (linha 1489); adicionar `|| ($resultadoPar['score_status'] ?? null) === 'blocked'` no mesmo `continue`.

Isso resolve a Distorção A por construção: se `blocked` nunca entra em `$scoresPares`, `$minhaNota` só é computado quando o próprio user não é `blocked` — e o `??0.0` deixa de mascarar um `null` real (vira defensivo puro, nunca acionado na prática por um blocked).

### Distorção B — `tamanho_amostra` conta blocked que a mediana exclui (linha 1547 vs. 1513-1523)

```php
// linha 1547
'tamanho_amostra' => $scoresPares->count(),

// linhas 1513-1523 — medianaPares() já filtra null por componente
$medianaPares = function (string $caminho) use ($scoresPares) {
    $valores = $scoresPares->map(fn ($r) => ...)->filter(fn ($v) => $v !== null)->values()->all();
    ...
};
```

Se a correção da Distorção A for aplicada (excluir `blocked` de `$scoresPares` na fonte), a Distorção B por `score_status='blocked'` desaparece junto — `tamanho_amostra` volta a refletir só profissionais com nota calculável. Resta, porém, o caso `partial` (tem financeiro elegível mas falta baseline em ALGUM componente): esses profissionais têm `nota_final` não-null (a média direta ainda soma os componentes disponíveis — ver `computeNotaFinal`), então CONTINUAM entrando corretamente em `tamanho_amostra` global, mas ainda podem faltar em `medianaPares('var_faturamento_pct')` especificamente se aquele componente for null para eles. Isso é esperado e não é bug — é a mesma semântica que `empresas_com_baseline` já usa no ranking (denominador do "usadas/total"). Não expandir o escopo da correção para tratar isso — é comportamento correto herdado, só documentar se o planner achar necessário um rótulo tipo "N pode variar por componente".

**Recomendação de implementação:** um único filtro adicional no loop que monta `$scoresPares` (linha ~1485-1493) resolve as duas distorções ao mesmo tempo, sem tocar `medianaPares`/`percentil`/`relativo`.

### O que o profissional blocked vê na própria tela

Hoje: nada de especial — ele simplesmente não entra em `$scoresPares` (com a correção), logo `comparacaoContextual` fica `null` OU (se `$scoresPares->count() < 2` após excluir blocked) o bloco de comparação inteiro não aparece (linha 1495: `if ($scoresPares->count() >= 2)`). Isso é aceitável e coerente com "sem nota oficial, sem base de comparação" — mas a experiência fica muda (nenhuma explicação do porquê o bloco sumiu). Recomenda-se (discrição do planner) um texto simples tipo "Sua nota ainda não é oficial — [motivo pt-BR, ver score_status]" no lugar do card de comparação quando `score_status==='blocked'`, reusando o padrão já existente em `FaixaBonusCard` (linha 195-199 de `Performance/Show.jsx`): `{nota == null && <p>Sem dados suficientes para classificação no mês selecionado.</p>}`.

## Status bloqueada na UI — labels e comportamento

### Mapa de status → label (regra do projeto: sem jargão sem explicação)

| `score_status` (backend) | Label recomendado (badge curto) | Texto de apoio (tooltip/subtítulo) |
|---------------------------|----------------------------------|-------------------------------------|
| `official`                | (nenhum badge — é o estado padrão/esperado) | — |
| `partial`                 | `Nota parcial`                  | "Algum dado financeiro do mês ainda não está disponível (empresa nova ou sem baseline do mês anterior)." |
| `blocked`                 | `Sem nota oficial`              | "Carteira sem vínculo com fonte financeira ainda (ex.: só Shopee) — aguardando régua de bônus da diretoria." |

Justificativa da escolha "Sem nota oficial" em vez de "Bloqueada": o feedback do projeto (`feedback_evitar_jargao_ui.md`, 2026-07-07, sobre o termo "Detrator" do NPS) estabelece que termos não auto-explicativos devem ser simplificados ou explicados. "Bloqueada" sozinho soa punitivo/técnico (sugere erro do profissional); "Sem nota oficial" descreve o ESTADO de dado sem insinuar culpa, e o tooltip completa o porquê. Este é uma recomendação de discrição do planner — não uma decisão travada; qualquer um dos dois nomes atende ao requisito, desde que acompanhado de explicação (não usar `blocked` cru).

### Onde a linha do ranking já teria tratamento parcial

`RankingConsultoria` em `Index.jsx` já formata `nota` como `—` quando `nota_final == null` (linha 390: `const nota = u.nota_final != null ? Number(u.nota_final).toFixed(2) : '—';`) e `PctToneCell`/NPS célula já mostram `—` para valores null. **O que falta é só o BADGE explícito** — hoje um `nota_final=null` por `blocked` é visualmente idêntico a qualquer outro "sem dado", sem comunicar a causa raiz (ex.: "profissional só-Shopee, aguardando régua"). A ordenação (linha 148: `sortByDesc(fn($r) => $r['nota_final'] ?? -1)`) já manda blocked para o fim corretamente — não precisa de nenhuma mudança de sort.

**Recomendação de posicionamento do badge:** ao lado da célula "Nota" (que hoje mostra só `—`), OU substituindo a célula inteira quando `blocked`. Evitar adicionar uma coluna nova ao grid (`RankingConsultoria` já usa `grid-cols-[2.5rem_minmax(0,1fr)_6rem_7.5rem_5rem_4.5rem_5rem_5rem_5rem_2rem]` — 10 colunas fixas; adicionar mais uma aperta a tabela em telas médias). Os metadados (`empresas_unicas`/`vinculos_servico`/`vinculos_sem_fonte_financeira`) cabem melhor como TOOLTIP na célula "Empresas" existente (linha 471-473, hoje só `empresas_com_baseline/empresas_carteira`) ou expandindo o `EvolucaoDrawer`/um novo drawer de detalhe ao clicar — não obrigatoriamente como colunas visíveis por padrão. O `Performance/Show.jsx` (drill-down) tem muito mais espaço (grid de `ParametroCard`s) e é o lugar natural para os 4 metadados completos sem apertar layout.

## Filtro de auditoria por setor (DESEMP-08 SC3)

Ver Padrão 2 acima (Architecture Patterns) para o código de referência. Resumo da decisão:

- **Nome do parâmetro:** `contexto` (não `setor`) — evita colisão semântica com o `$setor = 'consultoria'` já hardcoded em `PerformanceController::index()` linha 30 (que é o setor ORGANIZACIONAL da tela /performance vs. /publicacao/desempenho, um conceito totalmente diferente).
- **Valores:** `todos` (default) / `performance` / `shopee` — mesma whitelist da Fase 90.
- **Nunca chega ao `DesempenhoScoreService`** — o service não tem (e não deve ganhar) parâmetro de setor.
- **Aplicação recomendada:** client-side no `Index.jsx` sobre o `ranking` já calculado (evita N+1 de `CarteiraContextService::forUser()` por user no loop do ranking) — ex.: destacar/filtrar linhas onde o profissional tem `vinculos_sem_fonte_financeira > 0` (contexto=shopee) vs. só vínculos elegíveis (contexto=performance). Se o planner preferir fidelidade granular por vínculo individual (não só contadores agregados), aplicar via `CarteiraContextService::forUser($user, ['setor' => ...])` no DRILL-DOWN (`/performance/{user}`, 1 chamada), não no ranking agregado.

## Cache

`computeCached()` está na versão `v4` (bump feito na Fase 91, `desempenho.compute.v4.{user_id}.{Y-m}` — ver `DesempenhoScoreService.php` linhas 182-191). Os 6 metadados de elegibilidade JÁ FAZEM PARTE do array retornado por `compute()` (linhas 307-317 do service) — e `computeCached()` só envolve `compute()` inteiro em `Cache::remember` (linha 201-205: `fn () => $this->compute($user, $mesReferencia)`), então **os 6 metadados JÁ ESTÃO dentro do cache v4**, sem necessidade de novo bump de versão nesta fase. Confirmação adicional: o snapshot mensal (`ConsolidarMesDesempenho`, consumidor #5 da auditoria da Fase 91) grava `breakdown_json` com o array `$result` completo (`compute()` inteiro), então os metadados também sobrevivem no snapshot mensal — `PerformanceController::show()` linha 1011-1012 já usa `$snap->breakdown_json` como fonte quando existe, e essa fonte já contém os campos novos.

**Conclusão:** nenhuma mudança de cache é necessária nesta fase — é passthrough puro de um array que já está completo em ambas as fontes (cache Redis v4 e snapshot mensal).

## Common Pitfalls

### Pitfall 1: Sessão paralela em Fases 94/95 (NPS)

**O que da errado:** editar `resources/js/Pages/Nps/*.jsx` ou `app/Http/Controllers/NpsController.php` por engano, colidindo com trabalho paralelo de outra sessão/fase.
**Por que acontece:** `PerformanceController.php` e `Nps*` compartilham o mesmo domínio de dados (NPS entra como componente do score), mas são arquivos DIFERENTES.
**Como evitar:** Esta fase toca exclusivamente `PerformanceController.php`, `PortfolioController.php` (só o bloco `comparacaoContextual`), `Performance/Index.jsx`, `Performance/Show.jsx`. Nenhum arquivo `Nps*` deve ser tocado.
**Sinais de alerta:** qualquer diff em `app/Http/Controllers/NpsController.php`, `app/Services/Nps/*`, `resources/js/Pages/Nps/*.jsx` durante esta fase é fora de escopo.

### Pitfall 2: Esquecer `npm run build`

**O que da errado:** mudanças em `.jsx` não aparecem em produção porque o bundle Vite não foi recompilado.
**Como evitar:** rodar `npm run build` após qualquer edição de `Index.jsx`/`Show.jsx`, conforme convenção do projeto (`CLAUDE.md` / Platform Requirements).

### Pitfall 3: `PublicacaoDesempenhoRouteTest` pré-existente já falha (não é regressão desta fase)

**O que da errado:** rodar a suite completa e ver 1 teste vermelho (`PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200`, 403≠200) e achar que foi introduzido por esta fase.
**Por que acontece:** falha de permissão de rota `mlb.dashboard`, documentada como pré-existente desde `91-01-SUMMARY.md` e reconfirmada em `91-02-SUMMARY.md` — ortogonal a `computeUniverso`/`CarteiraContextService`/cache/`comparacaoContextual`.
**Como evitar:** ao medir regressão desta fase, comparar contra o baseline "75/76 verde (1 falha pré-existente)" documentado na Fase 91, não contra "76/76".

### Pitfall 4: `dashboardCarteira()` também usa `computeCached()` — mas com shape diferente (não é "ranking")

**O que da errado:** assumir que `dashboardCarteira()` (linha 265+ de `PerformanceController.php`, consumidor #2 auditado na Fase 91) precisa do mesmo tratamento de badge/metadados que o ranking `/performance`.
**Por que acontece:** ambos usam `computeCached()`, mas `dashboardCarteira()` é o dashboard OPERACIONAL do próprio analista/estrategista (widget dentro do `/dashboard/mercadolivre`), não a TELA de ranking desta fase. O ROADMAP/SC desta fase fala especificamente da "UI de Desempenho" (`/performance`), não do dashboard geral.
**Como evitar:** manter o escopo desta fase em `Performance/Index.jsx` (ranking) + `Performance/Show.jsx` (drill-down) + `Portfolio/Show.jsx` (correção do comparacaoContextual, que É consumido lá). Se o planner decidir estender o badge de `score_status` ao widget de `dashboardCarteira()` por consistência, é uma extensão de escopo válida mas não obrigatória pelos SC do ROADMAP — registrar como decisão explícita, não copiar sem avaliar.

### Pitfall 5: `sem_carteira` e `blocked` são estados DIFERENTES — não confundir tratamento

**O que da errado:** tratar `blocked` como se fosse `sem_carteira` (que já é filtrado do ranking, linha 144: `$rankingRaw->reject(fn ($r) => $r['sem_carteira'] === true)`).
**Por que acontece:** os dois têm em comum "sem nota calculável", mas a regra DESEMP-07 (travada na Fase 91) é explícita: `sem_carteira` = ZERO vínculos de qualquer setor (some do ranking); `blocked` = TEM vínculo (ex.: só Shopee) mas sem elegibilidade financeira (PERMANECE no ranking, com `nota_final=null` e badge de status).
**Como evitar:** o badge de status desta fase é só para `blocked`/`partial` — `sem_carteira` continua tratado pelo bloco de transparência já existente ("Excluídos do ranking · sem carteira no período", linhas 322-345 de `Index.jsx`), sem mudança.

## Code Examples

### Exemplo real — padrão de badge já usado no projeto (reusar estilo, não reinventar)

```jsx
// Source: resources/js/Pages/Performance/Index.jsx:42-53 (padrão FAIXA_BADGE_CLS)
const FAIXA_LABEL = {
    sem_bonus:     'Sem bônus',
    basico:        'Básico',
    intermediario: 'Intermediário',
    maximo:        'Máximo',
};
const FAIXA_BADGE_CLS = {
    sem_bonus:     'bg-white/[0.04] text-white/60 border-white/[0.08]',
    basico:        'bg-sky-500/15 text-sky-300 border-sky-500/30',
    intermediario: 'bg-violet-500/15 text-violet-300 border-violet-500/30',
    maximo:        'bg-ecf-yellow/15 text-ecf-yellow border-ecf-yellow/40',
};
// Badge renderizado (linha 426-432):
// <span className={cn('inline-flex items-center text-[10px] font-semibold px-2 py-0.5 rounded-full border', faixaCls)}>
//     {faixaLbl}
// </span>
```
Recomendação: criar `SCORE_STATUS_LABEL`/`SCORE_STATUS_BADGE_CLS` no mesmo padrão (ex.: `blocked` → tom âmbar/cinza de alerta suave, `partial` → tom âmbar claro, `official` → sem badge).

### Exemplo real — correção recomendada do comparacaoContextual (diff conceitual)

```php
// app/Http/Controllers/PortfolioController.php — dentro do foreach que monta $scoresPares (~linha 1485-1493)
foreach (User::whereIn('id', $paresIds)->get() as $par) {
    $resultadoPar = $this->scoreService->computeCached($par, $mesReferencia);
    // Sem carteira → filtra do grupo (não entra na mediana). [já existente]
    if (($resultadoPar['sem_carteira'] ?? false) === true) {
        continue;
    }
    // NOVO (Fase 92) — blocked não tem nota calculável, não pode comparar.
    if (($resultadoPar['score_status'] ?? null) === 'blocked') {
        continue;
    }
    $scoresPares->put($par->id, $resultadoPar);
}
```
Com essa mudança, `$minhaNota = (float) ($meuResultado['nota_final'] ?? 0.0)` (linha 1497) deixa de ser acionado por um `blocked` real (defensivo puro daqui pra frente), e `tamanho_amostra` (linha 1547, `$scoresPares->count()`) passa a refletir só profissionais com nota oficial/parcial calculável.

## State of the Art

Não aplicável — não há mudança de "abordagem antiga vs. nova" de biblioteca/framework nesta fase; é evolução incremental do mesmo motor v2 (Fase 74) + elegibilidade (Fase 91).

## Assumptions Log

| # | Claim | Seção | Risco se errado |
|---|-------|-------|------------------|
| A1 | "Sem nota oficial" é um label melhor que "Bloqueada" para `score_status='blocked'` | Status bloqueada na UI | Baixo — é recomendação de discrição, não decisão travada; o planner/usuário pode preferir "Bloqueada" mesmo, ambos atendem ao requisito de não usar o termo técnico cru |
| A2 | O filtro `?contexto=` deve ser aplicado client-side no ranking (não via `CarteiraContextService` em loop) | Filtro de auditoria por setor | Médio — se o SC3 exigir granularidade por vínculo individual (não só contadores agregados) na tela de ranking, a abordagem client-side não é suficiente e precisaria de uma query adicional; recomenda-se validar com o usuário/CONTEXT.md antes de implementar se a UI precisa mostrar vínculo-a-vínculo no ranking ou só nos contadores agregados |
| A3 | O badge de metadados cabe melhor como tooltip/drawer do que como coluna nova no grid do ranking | Status bloqueada na UI | Baixo — decisão de layout, reversível sem impacto de dado; SC2 só exige que os metadados "sejam exibidos", não especifica onde |

**Nenhuma claim de arquitetura, cálculo ou dado foi assumida sem verificação de código nesta pesquisa** — as claims acima são todas de UX/apresentação, não de comportamento de backend.

## Open Questions

1. **O badge de `score_status` deve aparecer também em `dashboardCarteira()` (widget do dashboard operacional)?**
   - O que sabemos: `dashboardCarteira()` também consome `computeCached()` e já tem os metadados disponíveis no payload (`$data`).
   - O que está incerto: o ROADMAP/SC da Fase 92 fala especificamente de "UI de Desempenho" (`/performance`), não do dashboard geral — pode ser interpretado como incluído ou não.
   - Recomendação: escopo mínimo = só `/performance` (Index + Show) + a correção do `comparacaoContextual` em `Portfolio/Show.jsx`. Se o usuário quiser o badge também no dashboard, é extensão barata (mesmo padrão) mas deve ser decisão explícita do planner/CONTEXT.md, não assumida.

2. **O filtro `?contexto=` deve persistir na URL (como `?mes=`/`?cargo=` já fazem) ou é só client-side (useState, sem query param)?**
   - O que sabemos: os filtros existentes (`mes`, `cargo`) usam `router.get(...)` com `preserveState: true`, persistindo na URL.
   - O que está incerto: se `?contexto=` deve seguir o mesmo padrão (reload parcial Inertia) ou ser puramente um filtro de array em `useMemo` no client, já que a Fase 90 usa `?contexto=` como query real que afeta uma QUERY no backend (`CarteiraContextService::forUser`), enquanto aqui (se a Recomendação client-side for adotada) não precisaria de round-trip ao servidor.
   - Recomendação: se o filtro é puramente sobre o `ranking` já carregado (contadores já vêm no payload), manter em `useState` local (sem query param) é mais simples e mais rápido para o usuário — mas registrar como decisão do planner, pois quebra a convenção visual de "todo filtro é um `?query=`" já estabelecida na tela.

## Environment Availability

Não aplicável — esta fase não introduz nenhuma dependência externa nova (sem CLI, sem serviço, sem runtime adicional). Toda a infraestrutura (Laravel, MySQL/SQLite, Redis para cache, Vite) já está em uso pelas fases anteriores da mesma milestone.

## Validation Architecture

### Test Framework

| Propriedade | Valor |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config `phpunit.xml` |
| Config file | `phpunit.xml` (raiz do projeto) |
| Quick run command | `C:\xampp\php\php.exe vendor/bin/phpunit --filter="Desempenho|Bonus|ComparacaoContextual"` |
| Full suite command | `C:\xampp\php\php.exe vendor/bin/phpunit` |

Convenção de diretório observada nas Fases 88-91 (v17.0): `tests/Feature/V16/*` (nome do diretório é herdado da milestone anterior mas continua sendo usado para testes v17.0 — ex.: `CarteiraContextServiceTest.php` é Fase 88/v17.0 mas mora em `tests/Feature/V16/`). Recomenda-se seguir essa mesma convenção por consistência (não criar `tests/Feature/V17/` nem `tests/Feature/Phase92/` isolado, salvo decisão explícita do planner de padronizar a nomenclatura).

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|--------|----------|-----------|-------------------|-------------|
| DESEMP-08 (SC1) | Ranking permanece único — nenhuma rota/tela nova por marketplace | unit/feature (gate de ausência via grep, mesmo padrão da Fase 91) | `grep -rin "score_shopee\|score_ml\|ranking_shopee\|ranking_ml" app/ resources/js/` deve retornar 0 | ❌ Wave 0 (comando ad-hoc, não teste PHPUnit) |
| DESEMP-08 (SC2) | Payload do ranking (`Performance/Index`) inclui os 6 metadados por linha | feature (assert Inertia props) | `phpunit --filter=PerformanceIndexMetadadosTest` | ❌ Wave 0 — criar `tests/Feature/V16/PerformanceIndexMetadadosTest.php` |
| DESEMP-08 (SC2) | `score_status='blocked'` renderiza sem quebrar (nota null tratada) | feature (fixture com profissional só-Shopee, reusar padrão de `DesempenhoElegibilidadeTest`) | mesmo arquivo acima | ❌ Wave 0 |
| DESEMP-08 (SC3) | `?contexto=performance/shopee/todos` não altera `nota_final`/`score_status` retornado | feature (assert response idêntico exceto flags de exibição) | mesmo arquivo acima ou dedicado | ❌ Wave 0 |
| Pendência Fase 91 | `comparacaoContextual` exclui `blocked` da amostra + `tamanho_amostra` bate com N real | feature (fixture: 1 user official + 1 blocked no mesmo cargo, assert blocked fora de `scoresPares`) | `phpunit --filter=ComparacaoContextualBlockedTest` | ❌ Wave 0 — criar `tests/Feature/V16/ComparacaoContextualBlockedTest.php` (ou nome equivalente) |

### Sampling Rate

- **Por commit de task:** `php vendor/bin/phpunit --filter="Desempenho|Bonus|ComparacaoContextual|PerformanceIndex"`
- **Por merge de wave:** suite completa `php vendor/bin/phpunit`
- **Gate de fase:** suite completa verde (baseline: 75/76 pré-existente, ver Pitfall 3) antes de `/gsd:verify-work`; checkpoint visual humano obrigatório (UI hint: yes no ROADMAP) cobrindo o badge de status + o filtro `?contexto=` + a tela de comparação (`Portfolio/Show.jsx` self-view).

### Wave 0 Gaps

- [ ] `tests/Feature/V16/PerformanceIndexMetadadosTest.php` — cobre SC1/SC2/SC3 do payload de `/performance`
- [ ] `tests/Feature/V16/ComparacaoContextualBlockedTest.php` (ou nome equivalente escolhido pelo planner) — cobre a correção da Distorção A/B, fixture com pelo menos 1 `blocked` + 1 `official` no mesmo cargo
- [ ] Nenhuma instalação de framework necessária — PHPUnit 11 já configurado e em uso pelas suites de Desempenho existentes

## Security Domain

`security_enforcement` não está desabilitado explicitamente no `.planning/config.json` (chave ausente = habilitado por padrão). Aplicando o gate:

### Applicable ASVS Categories

| Categoria ASVS | Aplica | Controle padrão |
|---------------|---------|-----------------|
| V2 Autenticação | Não (herdado — rota já protegida por middleware de sessão existente) | — |
| V3 Gestão de Sessão | Não (sem mudança) | — |
| V4 Controle de Acesso | Sim (indireto) | Rotas `/performance` e `/performance/{user}` já usam os middlewares/policies existentes — esta fase não adiciona nem remove nenhum gate de acesso, só payload/UI. Não introduzir novo endpoint sem revisar autorização. |
| V5 Validação de Entrada | Sim | Query param `?contexto=` DEVE seguir o mesmo padrão whitelist já usado em `PortfolioController::contextoFiltro()` (match explícito, nunca repassar valor cru pra query/lógica) — nunca interpolar o valor do query string diretamente em SQL ou em chamada ao service. |
| V6 Criptografia | Não | — |

### Known Threat Patterns for esta fase

| Padrão | STRIDE | Mitigação padrão |
|---------|--------|---------------------|
| Injeção via `?contexto=` não sanitizado (ex.: valor arbitrário propagado para uma query ou log sem whitelist) | Tampering | Reusar o `match()` com whitelist explícita da Fase 90 (retorna sempre `todos` como default seguro) — nunca `$request->query('contexto')` cru em nenhuma query builder |
| Exposição de dados de OUTROS profissionais via `comparacaoContextual` (ex.: nota de um par vazando quando ele deveria estar oculto) | Information Disclosure | Nenhuma mudança de escopo de autorização nesta fase — `$paresIds` já é filtrado por `cargo_slug` + `active=true`; a correção da Distorção A/B não altera QUEM entra na amostra por permissão, só QUEM entra por elegibilidade de dado (`blocked`) |

## Sources

### Primary (HIGH confidence — leitura direta do código nesta sessão)

- `app/Services/DesempenhoScoreService.php` (completo, 1193 linhas) — shape de retorno de `compute()`/`computeCached()`, cache v4, `computeScoreStatus`
- `app/Http/Controllers/PerformanceController.php` (linhas 1-265, 970-1032) — `index()`, `show()`, mapeamento atual do payload
- `app/Http/Controllers/PortfolioController.php` (linhas 60-100, 1440-1600) — `contextoFiltro()`, bloco `comparacaoContextual`
- `app/Services/Portfolio/CarteiraContextService.php` (completo) — `forUser()`, `contadores()`
- `resources/js/Pages/Performance/Index.jsx` (linhas 1-1050) — `RankingConsultoria`, formatação de nota/badges existentes
- `resources/js/Pages/Performance/Show.jsx` (linhas 1-220) — `FaixaBonusCard`, formatação de nota
- `resources/js/Pages/Portfolio/Show.jsx` (grep dirigido) — consumo de `comparacao_contextual` (self-view do profissional)
- `.planning/phases/91-.../91-02-SUMMARY.md` (completo) — auditoria dos 9 consumidores, pendência formal da Fase 92, declarações de escopo
- `.planning/ROADMAP.md` (linhas 625-699) — Success Criteria da Fase 92, dependências
- `.planning/REQUIREMENTS.md` (completo) — DESEMP-08, traceability
- `plano-carteira-desempenho-multi-servico.md` (linhas 200-300, 560-598) — spec canônica da "UI de Desempenho"

### Secondary (MEDIUM confidence)

- Nenhuma — toda a informação relevante desta fase estava disponível em arquivos locais já lidos diretamente; não houve necessidade de WebSearch/Context7 (não é domínio de lib externa nova).

### Tertiary (LOW confidence)

- Nenhuma.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero libs novas, stack 100% herdada e confirmada por leitura direta de `composer.json`/`package.json` implícito via CLAUDE.md
- Architecture: HIGH — payload gap e bug do `comparacaoContextual` confirmados por leitura literal de código, não por inferência
- Pitfalls: HIGH — todos os 5 pitfalls vêm de evidência documental (91-02-SUMMARY.md) ou de grep direto nesta sessão

**Research date:** 2026-07-17
**Valid until:** Válido enquanto `DesempenhoScoreService`/`PerformanceController`/`PortfolioController` não sofrerem outra mudança de shape — recomenda-se reler este research se a Fase 93 (menu) ou qualquer hotfix tocar esses 3 arquivos antes do plan-phase consumir esta pesquisa.
