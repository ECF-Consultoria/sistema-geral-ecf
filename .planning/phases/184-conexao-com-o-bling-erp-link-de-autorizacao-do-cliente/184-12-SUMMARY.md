---
phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente
plan: 12
subsystem: publicador-configuracoes
tags: [bling, erp, publicador, configuracoes, front-end]

requires:
  - phase: 184-11
    provides: "PainelVisaoGeralService::integracoes()['bling'] (sete estados) e MlbPublicadorBlingController (7 rotas mlb.anuncios.publicador.bling.*)"
provides:
  - "BlingDaLoja.jsx — SecaoBling (pura) + BlingDaLoja (default, conectado) com os sete estados"
  - "useBlingDaLoja.js — hook das sete rotas, com polling com teto e confirmação de desconexão"
  - "resources/js/lib/dataHora.js — formatarDataHora compartilhada entre Configuracoes.jsx e BlingDaLoja.jsx"
  - "Configuracoes.jsx — seção do Bling encaixada, fora do LinhaSoLeitura, entre Conexões e Programa/responsável"
affects:
  - "184-13 (checkpoint humano): a tela está pronta para a conta real do Bling — nenhum cálculo de estado no front"
  - "Visão geral / BannerDeIntegracao.jsx / Cockpit: nenhum impacto — este plano não toca neles, e os três gates do ERP da v26.0 seguem com diff vazio"

tech-stack:
  added: []
  patterns:
    - "Parte pura de apresentação (SecaoBling) + wrapper conectado (BlingDaLoja), mesma separação de ConteudoConfiguracoes/CampoIdentidade — o teste de render exercita a pura, nunca o hook"
    - "Depois de uma ação que não devolve o bloco bling inteiro (gerar link, importar, estoque), o hook REFAZ o estado via GET em vez de remontar o formato à mão — nenhum cálculo de estado no front, mesma disciplina do servidor"
    - "Formatação de data extraída para módulo compartilhado (lib/dataHora.js) na primeira vez que duas telas precisariam da mesma função"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/BlingDaLoja.jsx
    - resources/js/Components/Mlb/Publicador/useBlingDaLoja.js
    - resources/js/lib/dataHora.js
  modified:
    - resources/js/Pages/Mlb/Publicador/Configuracoes.jsx
    - tests/js/publicador-bling-configuracoes.test.js

key-decisions:
  - "`bling` prop de ConteudoConfiguracoes é o dado CRU (`conexoes?.bling ?? null`, literal exigido pelo gate do plano) — a interatividade (agindo/erro/previa/ações) é resolvida DENTRO de `<BlingDaLoja conta={conta} inicial={bling} />`, que chama o hook. ConteudoConfiguracoes continua testável por render estático porque useBlingDaLoja não dispara nenhum efeito de montagem (bling nasce do `inicial`, sem fetch automático) — mesma garantia que já vale para useIdentidadeDaContaPorConta."
  - "`acoes?.gerarLink` em vez de `acoes.gerarLink` em todo onClick de SecaoBling — permite renderizar a seção com `acoes={}` (default) nos testes sem precisar simular as sete funções."
  - "Depois de gerarLink()/importar()/atualizarEstoque(), o hook reconsulta a rota `estado` (GET) em vez de remontar o bloco `bling` a partir da resposta da própria ação — essas três ações devolvem formatos diferentes (ex.: gerarLink devolve {url, gerado_em, expira_em}, não o bloco bling completo); refazer o estado evita uma SEGUNDA implementação do formato do bloco no front, espelhando a disciplina de fonte única do servidor (plano 11)."

requirements-completed: [BLING-28, BLING-29]

metrics:
  duration: "~70min"
  completed: 2026-10-14
---

# Phase 184 Plan 12: O bloco do Bling nas Configurações da loja Summary

A equipe agora vê, dentro de Configurações da loja no Publicador, uma seção própria "Bling" com os
sete estados da conexão (sem empresa, não configurado, a conectar, aguardando o cliente, conectado,
divergente, erro), os botões certos em cada um, "Copiar link" com reserva, a prévia da importação
antes de qualquer gravação e o teto de contas declarado — tudo lendo o bloco `bling` que o plano 11
já expõe, sem nenhum cálculo de estado no front.

## Performance

- **Duration:** ~70 min
- **Completed:** 2026-10-14
- **Tasks:** 3/3
- **Files modified:** 5 (3 criados, 2 modificados)

## Accomplishments

- `useBlingDaLoja.js` (hook, 196 linhas): pinta a partir do `inicial` do Inertia (zero requisição na
  primeira renderização); fala com as sete rotas `mlb.anuncios.publicador.bling.*`; `iniciarLeitura()`
  faz polling de progresso a cada 3s com teto (`MAX_TENTATIVAS=200`, ~10min) e limpeza de timer no
  desmonte (T-184-84); `desconectar()` pede confirmação (`window.confirm`) antes de chamar a rota
  (T-184-86); `agindo` guarda o nome da ação em curso para desabilitar só o botão clicado; todo erro
  vira a `message` que o servidor mandou, nunca o objeto cru.
- `BlingDaLoja.jsx` (384 linhas): `SecaoBling` (export nomeado, pura de apresentação) renderiza os
  sete estados com a tabela de botões exata do plano — zero botão em `sem_company`/`nao_configurado`,
  um em `a_conectar`, dois em `aguardando_cliente`/`divergente`/`erro`, três em `conectado`; "Copiar
  link" com `navigator.clipboard.writeText` + reserva por campo selecionável; prévia da importação
  (quatro totais, avisos, `nao_entraram` com teto de itens + "e mais N", botões Importar/Cancelar);
  linha discreta do teto de contas quando `conectadas >= limite`. `BlingDaLoja` (default) chama o hook
  e alimenta `SecaoBling`. Toda leitura do bloco `bling` usa `bling?.x` (17 ocorrências), e `estado`
  desconhecido ou `bling` ausente cai no ramo neutro sem lançar.
- `resources/js/lib/dataHora.js`: `formatarDataHora` extraída de `Configuracoes.jsx` (era função
  local) para módulo compartilhado, importado nos dois arquivos — a mesma formatação não vive em dois
  lugares.
- `Configuracoes.jsx`: `ConteudoConfiguracoes` ganha os parâmetros `bling` e `conta` (ambos com
  default); renderiza `<BlingDaLoja conta={conta} inicial={bling} />` entre "Conexões" e "Programa e
  responsável", fora do `LinhaSoLeitura`. A linha `<LinhaSoLeitura rotulo="ERP">` fica **intocada**. O
  comentário de cabeçalho (`:24-27` antes, agora com um bloco adicional) registra a revogação PARCIAL
  da regra "ERP nunca aparece como conectado" — só para o Bling, porque são dois fatos que não se
  sobrescrevem.
- 28 testes novos em `tests/js/publicador-bling-configuracoes.test.js` (7 por fonte do hook + 17 de
  render real da seção + 4 de encaixe na página).

## Task Commits

1. **Task 1: useBlingDaLoja — o hook das sete rotas** — `49071ccf` (feat)
2. **Task 2: BlingDaLoja — a seção com os sete estados** — `05e5c711` (feat)
3. **Task 3: Encaixe na página e build** — `422d940e` (feat)

## Files Created/Modified

- `resources/js/Components/Mlb/Publicador/useBlingDaLoja.js` — **criado**
- `resources/js/Components/Mlb/Publicador/BlingDaLoja.jsx` — **criado**
- `resources/js/lib/dataHora.js` — **criado**
- `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx` — seção do Bling encaixada + comentário atualizado
- `tests/js/publicador-bling-configuracoes.test.js` — **criado**, 28 testes

## Decisions Made

Ver frontmatter `key-decisions`. Destaque: o prop `bling` de `ConteudoConfiguracoes` é literalmente
`conexoes?.bling ?? null` (exigido pelo gate do plano por grep), e a interatividade real (ações,
`agindo`, `erro`, `previa`) mora inteiramente dentro de `<BlingDaLoja/>` — não em `ConteudoConfiguracoes`,
que continua pura e testável por render estático sem simular o hook.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — bloqueio do próprio objetivo] Prop `conta` acrescentado a `ConteudoConfiguracoes`**
- **Encontrado em:** Task 3, ao escrever `<BlingDaLoja conta={...} inicial={bling} />`.
- **Causa:** `BlingDaLoja` precisa de uma string de conta (`empresa.chave`) para montar as sete rotas
  via `route('...', {conta})`. O texto do plano lista a assinatura de `ConteudoConfiguracoes` como
  `{ identidade, conexoes, programa, responsavel, bling }` — sem `conta`. Sem esse prop, a seção do
  Bling renderizaria mas nenhuma ação funcionaria de verdade (rota montada com `conta=null`).
- **Fix:** acrescentado `conta = null` à assinatura de `ConteudoConfiguracoes`, e `Configuracoes`
  (wrapper) passa `conta={empresa.chave}` — mesmo padrão que já existe para `empresa.chave` alimentar
  `useIdentidadeDaContaPorConta` fora de `ConteudoConfiguracoes`. Puramente aditivo; nenhum prop
  existente mudou de nome ou de default.
- **Arquivos modificados:** `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx`
- **Commit:** `422d940e`

**2. [Rule 3 — gate do próprio Task 2] Extração de `formatarDataHora` tocou `Configuracoes.jsx` antes do Task 3**
- **Encontrado em:** Task 2, ao conferir o acceptance_criteria `grep -lc "lib/dataHora" BlingDaLoja.jsx Configuracoes.jsx` (que exige os DOIS arquivos já importando o módulo compartilhado).
- **Causa:** o `<files>` da Task 2 no plano lista só `BlingDaLoja.jsx`, `useBlingDaLoja.js` (sic, não
  relevante) e o teste — não `Configuracoes.jsx` — mas o próprio texto da Task 2 pede "extrair para
  `@/lib/dataHora.js` e importar NOS DOIS LUGARES", e o gate confere isso imediatamente, não só no
  fim do plano.
- **Fix:** a Task 2 já fez a extração mínima em `Configuracoes.jsx` (substituir a função local pelo
  import), sem tocar em mais nada daquele arquivo; a Task 3 fez o resto (seção, props, comentário).
- **Arquivos modificados:** `resources/js/Pages/Mlb/Publicador/Configuracoes.jsx`
- **Commit:** `05e5c711`

---

**Total deviations:** 2, ambas Rule 3 (bloqueio do próprio objetivo/gate do plano), ambas aditivas e
documentadas antes do commit correspondente.
**Impact on plan:** Nenhum impacto de escopo — o comportamento final é exatamente o que o plano
descreveu; as duas adições foram necessárias para o próprio plano funcionar/passar os gates que ele
mesmo definiu.

## Known Stubs

Nenhum. Todos os sete estados leem o bloco `bling` real do servidor (plano 11); nenhuma ação chama
código mockado.

## Threat Flags

Nenhum novo fora do `<threat_model>` do próprio `184-12-PLAN.md` (T-184-83 a T-184-91, T-184-SC) —
todas as superfícies novas (seção, hook, polling, confirmação, prévia) já estavam cobertas por ele.

## Issues Encountered

1. **Harness de esbuild do teste de render precisou do MESMO tratamento que `publicador-configuracoes-render.test.js` já usa**: `BarraDaConta.jsx` (dona de `textoSeguro`) importa `@inertiajs/react` de verdade, que arrasta `qs`/`object-inspect` (CJS incompatível com o bundle ESM) — resolvido com o mesmo stub inline de `Link`/`router`/`usePage` e os mesmos `external` (`@radix-ui/react-popover`, `@radix-ui/react-slot`, `lucide-react`, `axios`). Harness, não código de produção.
2. **Gate literal `bling\.[a-z_]+` = 0 pegou dois acessos sem `?.` que eu tinha escrito depois de um guard de `typeof`** (`bling.link_expira_em` dentro de um `typeof bling?.link_expira_em === 'string' ? new Date(bling.link_expira_em)...`): o gate é cego a controle de fluxo — corrigido para `bling?.link_expira_em` em ambos, sem mudar comportamento (o guard já garantia o tipo).
3. **O comentário de cabeçalho citava literalmente `` `LinhaSoLeitura` `` e o gate `grep -c "LinhaSoLeitura" BlingDaLoja.jsx = 0` contou o comentário** — reescrito para "a linha só-leitura" (mesmo significado, sem o identificador literal), mesma família de armadilha do learning "gates do redesign" (§4, item sobre grep casando com comentário).

Árvore compartilhada com a Fase 180 (outra sessão): confirmado por `git diff --stat` nos arquivos dela (`MlbAnuncioController.php`, `Publicacoes.jsx`, `MeusAnuncios.jsx`, `Components/Mlb/Publicador/publicacoes/`) desde o sha inicial — diff vazio nos quatro. Commits feitos sempre por `git add -- <paths>` (nunca `git add -A`).

## Gate dos acceptance_criteria

- `node --test tests/js/publicador-bling-configuracoes.test.js`: **28 passed**, exit 0.
- `npm run test:js` (suíte inteira): baseline medido ANTES de tocar em qualquer arquivo = **4398 pass / 1 fail** (a falha pré-existente de `estrutura-grade-glide.test.js:122`, confirmada não-regressão). Depois das três tasks: **4426 pass / 1 fail** (4398 + 28 novos), mesma falha pré-existente, exit 1 só por ela — **zero regressão**.
- `npm run build`: exit 0; `Configuracoes.jsx` confirmado no `public/build/manifest.json` (lido via `node --input-type=commonjs -e "require(...)"`, nunca por grep).
- `C:\xampp\php\php.exe artisan test --filter=Bling`: **210 passed** (657 assertions), exit 0 — mesmo total do plano 11, confirmando que nada em PHP mudou.
- `git diff --stat a3b43d9a..HEAD -- resources/js tests/js`: exatamente os 5 arquivos previstos (`BlingDaLoja.jsx`, `useBlingDaLoja.js`, `Configuracoes.jsx`, `lib/dataHora.js`, `publicador-bling-configuracoes.test.js`).
- **Os três gates do ERP da v26.0, com diff VAZIO desde o início da fase:** `git diff a3b43d9a..HEAD -- tests/js/publicador-visao-geral-acoes.test.js tests/Feature/Publicador/CabecalhoDoPublicadorTest.php tests/Feature/Publicador/CockpitDaContaTest.php` → **0 linhas**.
- `grep -oE "publicador\.bling\.[a-z.]+" useBlingDaLoja.js | sort -u | wc -l` → **7**.
- `grep -c "MAX_TENTATIVAS" useBlingDaLoja.js` → **4** (≥2); `grep -cE "clearInterval|clearTimeout"` → **1**; `grep -ciE "JSON\.stringify\(.*err"` → **0**; `grep -ciE "\boauth\b|\btoken\b|escopo|callback|endpoint"` → **0** (nos dois arquivos novos).
- `grep -cE "bling\.[a-z_]+" BlingDaLoja.jsx` → **0**; `grep -c "bling?\."` → **17** (≥5); `grep -c "LinhaSoLeitura" BlingDaLoja.jsx` → **0**.
- `grep -ciE "mercado ?livre|mercadolivre|shopee|magalu|amazon" BlingDaLoja.jsx` → **0**.
- `grep -c "navigator.clipboard"` → **2**; reserva (`select\(\)|fallback|reserva`) → **5**.
- `grep -lc "lib/dataHora" BlingDaLoja.jsx Configuracoes.jsx` → lista os dois.
- `grep -c "declarado no onboarding" Configuracoes.jsx` → **1**; `grep -c 'LinhaSoLeitura rotulo="ERP"'` → **1**; `grep -c "BlingDaLoja"` → **2** (import + uso); `grep -c "conexoes?.bling ?? null"` → **1**; `grep -ci "184"` → **1**; `grep -ci "revog"` → **1**.
- Território da Fase 180 (outra sessão) confirmado intocado, commit por commit (não por range): `git diff --stat a3b43d9a..HEAD -- app/Http/Controllers/MlbAnuncioController.php resources/js/Pages/Mlb/Publicador/Publicacoes.jsx resources/js/Pages/Mlb/Publicador/MeusAnuncios.jsx resources/js/Components/Mlb/Publicador/publicacoes/` → **vazio**.

## User Setup Required

None — nenhuma configuração externa. As credenciais reais do Bling e a prova fim-a-fim continuam
para o checkpoint humano do plano 13.

## Next Phase Readiness

- A tela está pronta para receber a conta de teste do Bling no plano 13: os sete estados, os botões,
  o link para copiar e a prévia da importação funcionam contra o contrato real do servidor (plano 11).
- Nenhum bloqueio.

---
*Phase: 184-conexao-com-o-bling-erp-link-de-autorizacao-do-cliente*
*Completed: 2026-10-14*

## Self-Check: PASSED
