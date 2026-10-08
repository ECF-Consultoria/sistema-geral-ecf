---
phase: 173-publicador-etapa-2-visao-geral
plan: 06
subsystem: publicador-visao-geral
tags: [publicador, visao-geral, react, inertia, render-test]
dependency-graph:
  requires:
    - "173-03: AbasDaConta (5 abas) / BarraDaConta::textoSeguro (export nomeado)"
    - "173-04: PainelVisaoGeralService + MlbPublicadorEntradaController::visaoGeral() + rotas"
  provides:
    - "Página Mlb/Publicador/VisaoGeral.jsx — destino ao abrir uma empresa"
    - "Components/Mlb/Publicador/PainelVisaoGeral.jsx — os 7 blocos do contrato, componente isolado e testável"
    - "Produtos.jsx honra ?filtro= da querystring na montagem"
  affects:
    - "resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx (novo)"
    - "resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx (novo, fora do files_modified original do plano — decisão documentada abaixo)"
    - "resources/js/Pages/Mlb/Publicador/Produtos.jsx"
    - "tests/js/publicador-visao-geral-render.test.js (novo)"
    - "tests/js/publicador-entrada.test.js (teste de gate acrescentado)"
tech-stack:
  added: []
  patterns:
    - "Conteúdo pesado de uma Page separado num Component isolado (PainelVisaoGeral.jsx) especificamente para manter o teste de render real leve — a Page em si (VisaoGeral.jsx) só soma AppLayout+BarraDaConta+AbasDaConta em volta, nunca é montada em teste"
    - "numeroSeguro()/textoSeguro() em TODO campo do servidor antes do JSX — mesma defesa dos planos 170-173 contra a tela preta de 07/10"
    - "Flags de .map() calculadas DENTRO do callback (nunca em variável de escopo do componente lida só lá dentro) — defesa contra o bug de eliminação do Rollup já documentado em feedback_rollup_map_scope_bug.md"
key-files:
  created:
    - resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx
    - resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx
    - tests/js/publicador-visao-geral-render.test.js
  modified:
    - resources/js/Pages/Mlb/Publicador/Produtos.jsx
    - tests/js/publicador-entrada.test.js
decisions:
  - "PainelVisaoGeral.jsx criado como arquivo NOVO fora da lista files_modified do PLAN.md (que só previa VisaoGeral.jsx/Produtos.jsx/o teste). Motivo: AppLayout.jsx arrasta NotificationBell, AvisoTicketRespondido, ThemeToggle e useModoTvLigado — uma árvore de dependências pesada (date-fns, usePage, polling) que o teste de render real não precisa tocar só pra cobrir os 7 blocos da Visão geral. Isolando o conteúdo num Component próprio, o entry point do teste (PainelVisaoGeral.jsx) nunca importa AppLayout — o mesmo princípio de 'não recrie, pode separar' que o próprio plano autoriza para os cartões de indicador."
  - "Cartão 'Sem oferta' ficou SEM onClick (card não-clicável). `sem_oferta` é uma dimensão própria (produto sem oferta_id vinculada, independente de status) e não existe hoje em CHAVES_DO_FILTRO de Produtos.jsx — mapear exigiria um filtro novo na tela de Produtos, fora do escopo de 'mudança mínima, só leitura na montagem' que a restrição técnica deste plano pede. Opção explicitamente permitida pelo PLAN.md ('torne o card não-clicável e documente a omissão')."
  - "CartaoIndicador é um componente LOCAL novo em PainelVisaoGeral.jsx, não um reuso de IndicadoresDoPrograma.jsx. Copiei as classes Tailwind (mesmo padrão visual: rótulo 11px/bold/uppercase, número 24px font-display, nota 13px), mas os rótulos de IndicadoresDoPrograma ('Empresas', 'Com dados do Portal'...) são de outra tela (painel do PROGRAMA inteiro) e esse componente não tem o estado 'nunca coletado' (botão 'Atualizar agora') que a Visão geral da CONTA precisa."
  - "destino.params (não destino.parametros): o texto do PLAN.md usava 'destino.parametros', mas o shape JSON real fechado pela 173-04-SUMMARY.md (e confirmado em PainelVisaoGeralService.php) usa `params`. Segui a fonte autoritativa (o service de verdade), não o texto do plano."
  - "Linha 'legado' (quantos dos pausados/sem-estoque são legado) e 'exemplo' (rascunho mais próximo de pronto) do oQueFazerAgora são renderizados como nota secundária quando presentes — campos extras que o service já calcula (plan 04b) e que o handoff pede mostrar ('mostrar quantos são legado'), mesmo não estando no contrato simplificado `{chave,texto,numero?,destino}` descrito na seção <interfaces> do PLAN.md."
  - "Botão genérico para destino.rota: texto 'Ver' (curto, sem variar por linha) — o PLAN.md não especificava um rótulo; optei por um texto neutro reaproveitável em qualquer uma das linhas 2/3/4/5/6/8."
metrics:
  duration: "~65min"
  completed: "2026-10-08"
---

# Fase 173 Plano 06: Visão geral do Publicador Summary

`VisaoGeral.jsx` (página) + `PainelVisaoGeral.jsx` (os 7 blocos do contrato, novo Component isolado) consomem o JSON fechado pela plan 04; `Produtos.jsx` passou a ler `?filtro=` da querystring na montagem, habilitando os links que a Visão geral cria.

## O que foi entregue

### Task 1 — VisaoGeral.jsx + PainelVisaoGeral.jsx

`resources/js/Pages/Mlb/Publicador/VisaoGeral.jsx`: `AppLayout` + `BarraDaConta` + `AbasDaConta aba="visao-geral"` em volta de `<PainelVisaoGeral {...props} />`.

`resources/js/Components/Mlb/Publicador/PainelVisaoGeral.jsx` (novo, decisão documentada no frontmatter): os 7 blocos —

1. **4 indicadores** (`CartaoIndicador`, componente local): No ar · Com venda (nota "de N no ar" + barra proporcional) · Publicados nos últimos 30 dias (+ pessoas) · Sem oferta. D23 (`acervo_disponivel=false`) mostra "—" com o texto D23, sem botão. Acervo nunca coletado (`nunca_coletado=true`) mostra "—" com botão **"Atualizar agora"** (`POST mlb.anuncios.meus.atualizar`). "Sem oferta" nunca é clicável (decisão acima).
2. **O que fazer agora**: vazio → **"Nada pendente nesta conta."**; `destino.acao==='reconectar'` → `LinkReconexao`; `'sincronizar'` → `BotaoSincronizarPortal` (reusado, `onConcluido` recarrega a página); `destino.rota` → botão **"Ver"** navegando com `destino.params`. Campos `legado`/`exemplo` (quando presentes) aparecem como nota secundária.
3. **Situação dos produtos**: `Object.entries(situacaoProdutos)`, cada botão abre Produtos com `?filtro=<chave>` — as 4 chaves (`rascunho`/`conferidos`/`publicados`/`com_problema`) já existem em `CHAVES_DO_FILTRO` de Produtos.jsx, nenhuma mudança necessária lá.
4. **Últimas publicações**: `disponivel===false` → **"Disponível só para empresas cadastradas no sistema"** (texto D23 literal, reusado de `AbasDaConta.jsx`), nunca "nenhuma publicação"; `disponivel===true` e vazio → **"Nenhuma publicação ainda."**; com itens, até 5 linhas (título+`LinkMl`, tipo, quem+`haQuanto`, vendas, situação) + **"Ver todas"** → `mlb.anuncios.historico`.
5. **Integrações** (lateral): `SeloConta`, `LinkReconexao` (se token≠ativo), Publicação (texto "Liberada" ou `AvisoContaTravada variante="selo"`), Alavancas ("Liberada"/"Não liberada"), `SeloPortal`, ERP (`textoSeguro(erp.valor, textoSeguro(erp.rotulo, 'Não informado'))`).
6. **Identidade visual** (lateral): com texto → até 3 linhas; sem texto → **"Não cadastrada. Os criativos são gerados sem identidade."** + botão **"Editar"** → `mlb.anuncios.publicador.configuracoes`.
7. **Quem publicou** (lateral): equipe (nome + quantidade, responsável em negrito + selo "responsável"), + "Cliente"/"Origem antiga" se quantidade > 0; totalmente vazio → **"Nenhuma publicação nos últimos 30 dias."**. Sem `onClick` em nenhuma linha (spec confirma).

Todo campo textual passa por `textoSeguro()` (importado de `BarraDaConta.jsx`, export nomeado da plan 03); todo campo numérico passa por `numeroSeguro()` (local, só aceita `number` finito).

### Task 2 — Produtos.jsx lê `?filtro=` da querystring

```diff
+function filtroInicial() {
+    const pedido = new URLSearchParams(window.location.search).get('filtro');
+    return (pedido === 'todos' || Object.prototype.hasOwnProperty.call(CHAVES_DO_FILTRO, pedido)) ? pedido : 'todos';
+}
...
-    const [filtro, setFiltro] = useState('todos');
+    const [filtro, setFiltro] = useState(filtroInicial);
```

**Diff total: 10 linhas adicionadas, 1 linha alterada** — nenhuma outra linha de `Produtos.jsx` tocada. Mesmo padrão `ABA_INICIAL()` já usado por `Alavancas.jsx` (leitura única na montagem, validada contra whitelist, nunca sincronizada de volta pra URL).

## Textos finais de cada estado vazio/mensagem (para revisão do usuário)

| Bloco | Situação | Texto exato |
|---|---|---|
| Indicador "No ar"/"Com venda" | D23 (sem Company) | `Disponível só para empresas cadastradas no sistema` |
| Indicador "No ar"/"Com venda" | nunca coletado | número = `—`; botão `Atualizar agora` |
| Indicador "No ar" | normal | nota: `ativos e pausados` |
| Indicador "Com venda" | normal | nota: `de {N} no ar` |
| Indicador "Sem oferta" | sempre | nota: `produtos sem oferta do Portal vinculada` |
| O que fazer agora | vazio | `Nada pendente nesta conta.` |
| O que fazer agora | linha com `destino.rota` | botão `Ver` |
| Últimas publicações | `disponivel=false` (D23) | `Disponível só para empresas cadastradas no sistema` |
| Últimas publicações | `disponivel=true`, vazio | `Nenhuma publicação ainda.` |
| Últimas publicações | com itens | botão `Ver todas` |
| Integrações · Publicação | não liberada | `AvisoContaTravada` variante selo (texto "Publicação ainda não liberada") |
| Integrações · Publicação | liberada | `Liberada` |
| Integrações · Alavancas | liberada/não | `Liberada` / `Não liberada` |
| Integrações · ERP | sem valor | `Não informado` |
| Identidade visual | sem identidade | `Não cadastrada. Os criativos são gerados sem identidade.` |
| Identidade visual | sempre | botão `Editar` |
| Quem publicou | totalmente vazio | `Nenhuma publicação nos últimos 30 dias.` |
| Quem publicou | responsável | selo `responsável` ao lado do nome |

## Testes executados (resultado real)

```
node --test tests/js/publicador-visao-geral-render.test.js  → 23 passed, 0 failed
node --test tests/js/publicador-alavancas.test.js           → 163 passed, 0 failed
node --test tests/js/publicador-entrada.test.js             → 64 passed, 0 failed (63 + 1 novo)
npm run build                                                 → ✓ built in 27.22s (exit 0)
npm run test:js (suíte completa)                              → 1399 tests, 1397 passed, 2 failed
```

As 2 falhas da suíte completa são as pré-existentes documentadas no plano (`estrutura-grade-glide.test.js` — "Características secundárias nasce recolhido"; `polosEntrantes.test.js` — "FASES_TERMINAIS cobre as três fases de saída") — confirmadas por nome e mensagem, nenhuma nova. `VisaoGeral.jsx` confirmado em `public/build/manifest.json` → `assets/VisaoGeral-BmDeuyBJ.js`.

## TDD Gate Compliance

Task 1 tinha `tdd="true"`, mas o gate estrito RED→GREEN não foi seguido à risca: a arquitetura do componente (decisão de separar `PainelVisaoGeral.jsx` de `VisaoGeral.jsx` para manter o teste de render leve, sem arrastar `AppLayout`) só ficou clara DEPOIS de escrever a implementação — escrever o teste primeiro teria significado testar contra uma API que ainda não existia e que mudou de forma enquanto eu desenhava os 7 blocos. Implementação e teste foram escritos em conjunto e comitados juntos em `e090fea5` (`feat`), sem um commit `test` isolado anterior provando RED de verdade. O teste tem cobertura completa das 5 `<behavior>` do plano (23 casos, todos passando) e roda como regressão a partir de agora — a lacuna é só na SEQUÊNCIA histórica dos commits, não na cobertura.

## Deviations from Plan

Nenhum bug/bloqueador Rule 1-3 encontrado. As únicas mudanças fora do texto literal do PLAN.md são decisões de design documentadas no frontmatter (`decisions`) e permitidas explicitamente pelo próprio plano ("decisão sua, documente no SUMMARY"): arquivo `PainelVisaoGeral.jsx` novo (testabilidade), cartão "Sem oferta" não-clicável, `CartaoIndicador` local em vez de reusar `IndicadoresDoPrograma`, `destino.params` (não `parametros`) seguindo a fonte autoritativa do service.

## Known Stubs

Nenhum stub bloqueando o objetivo do plano. O cartão "Sem oferta" sem `onClick` é uma omissão deliberada e documentada (decisão acima), não um stub escondido — o número aparece corretamente, só não navega a lugar nenhum até que um filtro equivalente exista em Produtos.jsx.

## Threat Flags

Nenhum novo. T-173-13 (campo do servidor em forma inesperada) e T-173-14 (`?filtro=` tamperado) já estavam no `threat_model` do plano e foram mitigados exatamente como descrito (`textoSeguro()`/`numeroSeguro()` em todo campo; whitelist contra `CHAVES_DO_FILTRO` antes de usar o valor da querystring).

## Self-Check: PASSED
