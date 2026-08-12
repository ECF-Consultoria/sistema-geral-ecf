---
phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-
plan: 05
subsystem: ui
tags: [react, inertia, tailwind, desempenho, lancamento-manual, admin, vite]

# Dependency graph
requires:
  - phase: 136-03
    provides: "quality.faturamento_fonte / quality.margem_fonte propagados ate o snapshot congelado — a fonte do selo de D-04"
  - phase: 136-04
    provides: "Rotas admin-only desempenho.metricas-manuais.index/lancar e o contrato de props da grade"
provides:
  - "Pages/Desempenho/MetricasManuais.jsx — grade admin empresa x metrica, edicao inline por eixo, read-only em competencia consolidada"
  - "SELO_MANUAL_TEXTO / SELO_MANUAL_TITULO em desempenhoLabels.js"
  - "Marcador discreto por METRICA em EmpresasScoreTabela.jsx (vale para /performance/{user} e para o Relatorio de bonificacao)"
  - "Item de menu admin-only 'Metricas manuais' no grupo Gestao ECF"
affects: ["136-07"]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Celula editavel inline no ciclo do CustIdCell (clique -> input -> Enter salva / Escape cancela / blur salva) com os dois guards anti-request-inutil, aplicada a um valor monetario"
    - "Erro do backend renderizado NA CELULA (estado local por chave company_id:metrica) e, quando e recusa de competencia, tambem numa faixa no topo — nunca so no console"
    - "Selo de origem por EIXO lendo quality.*_fonte: sem prop nova, funciona igual nos dois consumidores da tabela"

key-files:
  created:
    - resources/js/Pages/Desempenho/MetricasManuais.jsx
  modified:
    - resources/js/lib/desempenhoLabels.js
    - resources/js/Components/Desempenho/EmpresasScoreTabela.jsx
    - resources/js/Layouts/AppLayout.jsx

key-decisions:
  - "Granularidade do selo de D-04 resolvida por METRICA, nao por linha (Open Question 1 do RESEARCH) — D-07 tornou os dois eixos independentes e um selo unico na linha mentiria sobre o eixo medido pela API"
  - "O selo manual e icone com title, nunca o badge de texto do padrao SELO_SHOPEE — sao mensagens diferentes: limitacao de plataforma versus origem do numero"
  - "api_aquecida = false renderiza 'ainda nao aquecido' com title explicando que nao e zero; nenhum caminho da tela imprime R$ 0,00 para valor ausente"
  - "Toggle para 'manual' sem valor guardado abre o input em vez de submeter — o backend exige valor quando ativo=true, e mandar vazio produziria 422 previsivel"
  - "Alternar para 'manual' com valor preservado religa a celula reaproveitando o numero (D-02/D-12: reverter para auto nunca apaga o lancamento)"

patterns-established:
  - "Grade admin de lancamento em lote: linha por empresa, coluna por metrica iterada a partir da whitelist `metricas` do backend — a tela nunca redigita as strings 'faturamento'/'margem_cmv' soltas no JSX"

requirements-completed: ["D-01", "D-02", "D-04", "D-07", "D-09"]

# Metrics
duration: 25min
completed: 2026-08-12
---

# Phase 136 Plan 05: Grade de lançamento manual e selo discreto por métrica Summary

**A tela: grade admin empresa × mês com os dois eixos alternando auto/manual de forma independente, competência consolidada abrindo read-only com o motivo escrito, e — do lado do profissional — um marcador discreto por métrica dizendo que aquele número específico foi lançado à mão, sem em momento nenhum dizer por quem.**

## Performance

- **Duration:** ~25 min (13:41 → 14:06 UTC, 2026-08-12)
- **Tasks:** 2/2
- **Files modified:** 1 criado, 3 modificados

## Accomplishments

- `resources/js/Pages/Desempenho/MetricasManuais.jsx` (490 linhas) — arquivo `.jsx` completo e autocontido, com `export default function MetricasManuais`. **Não** é wrapper de re-export (`grep -c "export { default } from"` = 0), e o chunk entrou no manifest do Vite (3 ocorrências de `Pages/Desempenho/MetricasManuais.jsx` em `public/build/manifest.json`).
- **Os dois eixos alternam separadamente (D-07):** cada célula tem seu próprio segmentado `auto | manual`. Loja Shopee vira só o CMV manual e deixa o faturamento na API — que é o formato de 10 dos 11 casos medidos em 2026-07.
- **A célula manual mostra o valor da API ao lado e sinaliza divergência sem reverter (D-02):** quando manual e API existem e diferem em ≥ R$ 0,01, aparece um `TriangleAlert` cujo `title` diz explicitamente que o valor manual continua mandando na nota e que voltar para automático é ato do administrador. Nada na tela reverte sozinho.
- **`api_aquecida = false` nunca vira "R$ 0".** A célula escreve `API: ainda não aquecido`, com `title` explicando que o valor não foi resolvido agora — não que a empresa faturou zero. Esse era o risco concreto do contrato do Plano 04: exibir zero aqui é mentir sobre um número que decide bônus.
- **Competência consolidada abre read-only com o porquê (D-09):** faixa âmbar com cadeado, os botões de alternância e a edição inline desabilitados, e **os valores continuam visíveis** — quem audita precisa vê-los. O read-only é conveniência; a recusa real continua sendo a validação com lock do controller, e quando ela dispara (consolidação vencendo a corrida) a mensagem do servidor aparece numa faixa vermelha no topo, além da própria célula.
- **Erro de validação aparece na tela**, por célula (`errosPorCelula` chaveado por `company_id:metrica`) e, para `mes_referencia`, também no topo. Estado de "salvando…" é por célula, não global.
- **Selo de D-04 por métrica**, não por linha: `CelulaFaturamento` renderiza quando `quality.faturamento_fonte === 'manual'`, `CelulaMargem` quando `quality.margem_fonte === 'manual'`. `CelulaEmpresa` ficou intocada (`git diff … | grep -c "CelulaEmpresa"` = 0). Como o sinal viaja dentro de `quality`, o selo funciona igual em `/performance/{user}` e no Relatório de bonificação **sem prop nova** — o docblock do componente exige que nada ali assuma uma tela específica.
- **Nenhum nome de autor em lugar nenhum do front** (T-136-08): `grep -c "lancado_por\|lancadoPor"` = 0 tanto na página nova quanto em `EmpresasScoreTabela.jsx`.
- Item de menu `Métricas manuais` no grupo **Gestão ECF**, logo abaixo de `Relatório de bonificação`, com o mesmo `excludeRoles` dos dois vizinhos e comentário pt-BR registrando que `excludeRoles` só esconde — a autorização é `role:admin` na rota mais o `authorize()` do FormRequest (T-136-18).

## Task Commits

Cada task foi commitada atomicamente, com staging por caminho explícito (nunca `git add .` / `-A` / `-u`):

1. **Task 1: Página da grade empresa × mês com edição inline por eixo** — `d03ff9b8` (feat)
2. **Task 2: Selo discreto por métrica, item de menu e build final** — `98008968` (feat)

## Files Created/Modified

- `resources/js/Pages/Desempenho/MetricasManuais.jsx` — página da grade: cabeçalho + seletor de competência (com sufixo "consolidada" por opção), faixa de aviso de competência congelada, busca com debounce de 400 ms devolvendo a prop `busca` ao input, tabela empresa × métrica com `CelulaMetrica` (edição inline + segmentado auto/manual + contexto da API + erros), estado vazio explicando o universo da grade
- `resources/js/lib/desempenhoLabels.js` — `SELO_MANUAL_TEXTO` (`'valor lançado manualmente'`, o texto de D-04) e `SELO_MANUAL_TITULO`, com o comentário longo registrando por que este selo é ícone e não badge, e por que a granularidade é por métrica
- `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` — sub-componente `SeloManual` (ícone `PencilLine` de 11px dentro de um `<span title=…>`, com `aria-label`), condicional em `CelulaFaturamento` e em `CelulaMargem`
- `resources/js/Layouts/AppLayout.jsx` — import de `PencilLine` e a entrada de menu admin-only

## Decisions Made

- **Open Question 1 do RESEARCH resolvida por métrica.** D-04 fala em "a linha da empresa recebe marcador", mas isso foi escrito antes de D-07 separar os eixos. Um selo único na linha diria "manual" para uma empresa cujo faturamento foi medido pela API e só o CMV foi digitado — enganando exatamente o público que D-04 quer tratar com honestidade. O sinal por eixo já existia desde o Plano 03, sem custo de schema.
- **Ícone, não badge de texto.** O padrão `SELO_SHOPEE_TEXTO` é um badge âmbar com frase completa porque avisa uma **limitação de plataforma que muda a leitura da nota** (1,00 ponto fixo). O selo manual identifica a **origem de um número que continua valendo integralmente** — são mensagens de peso diferente, e usar o mesmo formato para as duas achataria a distinção. D-04 pede "discreto".
- **O `title` do selo não cita autor nem competência.** Ele explica o mecanismo ("lançamento do administrador para a competência, não da API") e dá o caso concreto (a Shopee não fornece CMV). Nome de quem digitou é atrito interno e está fora do payload por desenho.
- **Toggle para `manual` sem valor guardado abre o input.** Submeter `ativo=true` com `valor=null` produziria um 422 do próprio FormRequest ("O valor é obrigatório quando a métrica está marcada como manual"). Abrir o input é o comportamento que o admin espera e economiza um round-trip previsível.
- **Parser de número tolerante a formato BR.** `paraNumero()` aceita `12345.67` e `12.345,67` — havendo vírgula, ela é o decimal e o ponto é milhar. Sem isso, um admin digitando no formato brasileiro mandaria `12.345,67` e o backend receberia lixo.
- **A alternância manda `valor: null` quando `ativo=false`.** O controller preserva o valor sozinho (`valor_anterior` vira `valor`); mandar o número de volta no payload de reversão seria redundante e criaria uma segunda fonte de verdade para o mesmo campo.

## Deviations from Plan

### Ajustes automáticos

**1. [Rule 2 - Correção crítica] `title` do selo num `<span>`, não no `<svg>` do ícone**
- **Encontrado em:** Task 2, ao montar o `SeloManual`
- **Problema:** o plano descreve "ícone pequeno de `lucide-react` com `title={SELO_MANUAL_TITULO}`". Passar `title` direto no componente do Lucide o repassa como **atributo do `<svg>`**, e `title` em SVG não é o `title` do HTML — os navegadores não renderizam tooltip a partir dele. O selo apareceria mudo, e um marcador sem explicação é pior que nenhum: sinaliza "tem algo diferente aqui" sem dizer o quê.
- **Correção:** o ícone ficou dentro de um `<span title={SELO_MANUAL_TITULO} aria-label={SELO_MANUAL_TEXTO}>`, com `aria-hidden` no `<svg>`. Mesmo padrão de tooltip por `title=` já usado pelo selo Shopee (que também está num elemento HTML, não num `<svg>`).
- **Arquivos:** `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx`
- **Commit:** `98008968`

**2. [Rule 2 - Correção crítica] "ainda não aquecido" estendido à célula em modo automático**
- **Encontrado em:** Task 1, ao escrever os estados da célula
- **Problema:** o plano prende a regra do "ainda não aquecido" à célula **manual**. Mas a célula em `auto` sem lançamento também recebe `api_valor = null` / `api_aquecida = false` do controller (por desenho — T-136-17, quem não tem lançamento não paga HTTP). Se essa célula renderizasse o valor formatado de um `null`, `formatCurrency(null)` devolve **`R$ 0,00`** — precisamente a mentira que o contrato do Plano 04 proíbe, só que no caminho mais comum da grade.
- **Correção:** a célula sem valor da API exibe `—` como número e o contexto textual apropriado; nenhum caminho da tela chama `formatCurrency` com valor nulo.
- **Arquivos:** `resources/js/Pages/Desempenho/MetricasManuais.jsx`
- **Commit:** `d03ff9b8`

**3. [Rule 3 - Bloqueio] `onMouseDown` com `preventDefault` nos botões de confirmar/cancelar da edição**
- **Encontrado em:** Task 1, ao combinar "blur salva" com botões clicáveis
- **Problema:** o ciclo do `CustIdCell` não tem `onBlur`; o plano pede blur salvando. Com `onBlur={salvar}` no input, clicar no botão "cancelar" dispara o blur **antes** do clique — o valor seria salvo apesar do cancelamento explícito.
- **Correção:** `onMouseDown={(ev) => ev.preventDefault()}` nos dois botões, que impede o input de perder o foco antes do `onClick`.
- **Arquivos:** `resources/js/Pages/Desempenho/MetricasManuais.jsx`
- **Commit:** `d03ff9b8`

## Issues Encountered

**Árvore compartilhada — nenhum arquivo da Fase 135 foi tocado.** `routes/web.php`, `app/Http/Controllers/OnboardingTemplateController.php`, `tests/Feature/Phase135/OnboardingTemplateVersionamentoTest.php`, `.claude/settings.local.json` e `prompt-metas-dev-v2.md` seguem exatamente como estavam: nenhum `git add .`/`-A`/`-u`, nenhum `git stash`, nenhum `reset`, nenhum `checkout --`. Os dois commits foram conferidos com `git show --stat` e contêm apenas os arquivos deste plano (1 e 3 arquivos respectivamente). `resources/js/Layouts/AppLayout.jsx` foi verificado limpo com `git status --short` **antes** de ser editado — a Fase 135 ainda não acrescentou o item de menu de `/onboarding/templates` lá, então não houve colisão e o arquivo pôde ser commitado inteiro.

**`routes/web.php` não foi tocado.** As duas rotas do Plano 04 já estavam commitadas (confirmado com `git show HEAD:routes/web.php | grep -c "metricas-manuais"` = 4); o `M` que aparece no `git status` é exclusivamente o hunk da Fase 135.

**Assets buildados não são versionados.** `git check-ignore` confirma que `/public/build` está no `.gitignore` — o build acontece na VPS durante o deploy. `npm run build` foi rodado (exit 0) para gerar o manifest local e provar que a página entrou nele, mas nada de `public/build` foi commitado.

**Nenhum `public/hot` órfão** no ambiente local (verificado antes do primeiro build).

## Verification

| Checagem | Resultado |
|---|---|
| `npm run build` | exit **0** (55,6 s na Task 1; 24,1 s na Task 2) |
| `grep -c "Pages/Desempenho/MetricasManuais.jsx" public/build/manifest.json` | **3** (≥ 1 exigido) |
| `artisan test --filter="Phase136"` | **60 passed**, exit **0** — igual ao número de entrada |
| Baseline `CarteiraPeriodoDiffTest\|DesempenhoPeriodoOficialTest\|DesempenhoShopeeScoreTest\|ConsolidarMesJanelaNpsTest\|JanelaNpsBonusTest` | **9 failed / 18 passed**, e os 9 nomes conferidos um a um contra `136-BASELINE-TESTES.md` — nenhuma falha nova, nenhuma fora da lista |

A regressão foi medida contra **9**, nunca contra zero, e por exit code (`EXIT=1` na baseline é o esperado; `EXIT=0` no filtro `Phase136`).

**A conferência de comportamento NÃO foi feita por `grep` no bundle**, deliberadamente: `grep` no bundle prova o deploy, não o funcionamento, e identificador livre sobrevive à minificação — ver o nome literal ali seria sinal de bug, não de sucesso (o projeto não tem ESLint; o build passa com identificador indefinido). O `grep` no manifest serve só para provar que a página gerou chunk. A conferência visual da grade, da alternância por eixo, do sinal de divergência, do read-only e do selo com tooltip é o **checkpoint do Plano 07**.

## Known Stubs

Nenhum. Todas as células da grade são alimentadas pelas props reais do `DesempenhoMetricasManuaisController`; não há valor hardcoded, mock ou texto de "em breve" em nenhum dos quatro arquivos.

## User Setup Required

None — nenhuma configuração de serviço externo necessária. **Nenhum deploy foi feito**, conforme a proibição do `CLAUDE.md`.

## Next Phase Readiness

- **Plano 07 (wave 5) liberado.** O checkpoint humano dele tem agora tela para abrir: `/desempenho/metricas-manuais` (item de menu **Métricas manuais**, grupo Gestão ECF, visível só para admin) e o selo em `/performance/{user}`.
- **A suíte `MetricaManualRotaAdminTest` continua com `withoutVite()` e `component(…, shouldExist: false)`.** Agora que `Desempenho/MetricasManuais.jsx` existe, os dois **podem** ser removidos — não foi feito porque não é obrigatório e mexer numa suíte verde sem necessidade é risco sem retorno. Se o Plano 07 quiser remover, o pré-requisito é que `public/build/manifest.json` esteja gerado no ambiente de teste.
- **Pendência herdada do Plano 02, ainda em pé:** a migration de `desempenho_metricas_manuais` precisa subir contra o MariaDB de produção no deploy.
- **Ponto de atenção para o deploy:** a VPS builda o front sozinha (`deploy.sh`), então o chunk novo nasce lá; nada de `public/build` viaja no git.

---
*Phase: 136-m-tricas-manuais-por-empresa-m-s-no-desempenho-override-api-*
*Completed: 2026-08-12*

## Self-Check: PASSED

- `resources/js/Pages/Desempenho/MetricasManuais.jsx` — FOUND
- `resources/js/lib/desempenhoLabels.js` — FOUND
- `resources/js/Components/Desempenho/EmpresasScoreTabela.jsx` — FOUND
- `resources/js/Layouts/AppLayout.jsx` — FOUND
- `.planning/phases/136-.../136-05-SUMMARY.md` — FOUND
- Commits `d03ff9b8`, `98008968` e `ec93f928` — FOUND em `git log --oneline --all`
- Árvore final contém apenas os arquivos da Fase 135 como pendências alheias — nenhum deles tocado
