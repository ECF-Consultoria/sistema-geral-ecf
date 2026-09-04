---
quick_id: 260904-jpn
slug: tabela-progressiva-fiel-a-referencia
date: 2026-09-04
status: done
---

# Tabela progressiva e área expandida fiéis à referência — Resumo

**Uma linha:** extraída uma subcomponente única `TabelaProgressivaFaixas` (grid `80px 1fr 160px`,
padding 12px/18px, texto 13px, raio 12px) no lugar de duas cópias de tabela HTML com densidade
pela metade, mais o ajuste de padding lateral da área expandida (20px → 22px).

## O que mudou

O usuário conferiu a tela `/administrativo/financeiro` em produção e relatou "fontes pequenas" e
"tabela progressiva não está igual" à referência (`design_handoff_fechamento/Fechamento.dc.html`).
A causa raiz era estrutural, não visual: `TabelaFaixasSection.jsx` tinha **duas cópias divergentes**
de tabela HTML (bloco do grupo — Fase 138 — e bloco do serviço — Fase 137), ambas desenhadas para
uma listagem compacta, não para a densidade da referência.

### Tarefa 1+2 — subcomponente única com a densidade da referência

`resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx`:

- Nova função `TabelaProgressivaFaixas({ faixas, faixaOrdemAtual })`, usada nos dois lugares onde
  antes havia tabela HTML (bloco do grupo e bloco do serviço).
- Grid `grid-cols-[80px_1fr_160px] gap-4` no lugar de tabela com larguras automáticas.
- Linhas: `px-[18px] py-3` (12px/18px), texto `text-[13px]` (era `text-[11px]`, quase metade).
- Cabeçalho: `px-[18px] py-2.5` (10px/18px), `bg-ecf-card-2` (superfície interna traduzida do
  hex #0F0F11 do handoff para o token do projeto — nunca o hex).
- Caixa: `rounded-xl` (12px de raio; era `rounded-lg`, 8-10px).
- "Faixa" e "Faturamento até" à esquerda (sem `text-right`); só "Mensalidade" à direita — antes
  "Faturamento até" estava indevidamente alinhada à direita.
- Faixa atual continua destacada (`bg-ecf-yellow/10` / `text-ecf-yellow`) — comportamento
  preservado, só a densidade mudou.
- Título "Tabela progressiva · <serviço/grupo>" bump de 11px para 12px/600 com
  `tracking-[0.05em]`, mais fiel ao handoff ("TABELA PROGRESSIVA · <serviço>", 12px/600 uppercase).

### Tarefa 3 — resto da área expandida

`resources/js/Pages/Admin/Financeiro.jsx`: o padding lateral da área expandida (três passos +
composição do grupo + tabela) foi corrigido de `px-5` (20px) para `px-[22px]`, batendo com o
padding 4px 22px 24px do handoff. Os três cards de passo (1 · Faturou no mês, 2 · Faixa do
contrato, 3 · Mensalidade a cobrar) já batiam com a referência antes desta correção — padding
16px/18px, raio 12px, rótulo 11px/600 maiúsculo, valor 22px, sub-linha 12px, grid `gap-3`
(12px) — não foram tocados.

### Tarefa 4 — cores traduzidas

Nenhum hex do handoff foi copiado. Onde a referência especifica uma superfície interna
(#0F0F11), usou-se o token do projeto (`ecf-card-2`). A cor do valor da mensalidade nas linhas
não-atuais permanece verde (`text-emerald-400/80`, convenção já usada em todo o resto da tela
para dinheiro — card 3 dos passos, composição do grupo), e não o cinza neutro #C9C9C4 do
protótipo: essa diferença de cor não estava na tabela de desvio medido do plano (que cobria
apenas texto/padding/colunas/alinhamento/divisor/raio) e a decisão de "dinheiro = verde" já é
convenção estabelecida em outras partes desta mesma tela.

## Decisão do usuário reafirmada, não reaberta

A fonte continua `font-mono` do Tailwind (não a fonte mono do handoff). A paleta continua nos
tokens `ecf-*`. Nenhuma referência a Instrument Sans, à fonte recusada ou a fonts.googleapis foi
introduzida — travado por teste novo.

## Testes

Novo arquivo `tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php` (13 testes, 23
asserções) trava:

- Existe **uma só** definição de `TabelaProgressivaFaixas`, reusada em pelo menos 2 lugares.
- Nenhuma tabela HTML residual no arquivo.
- "Faturamento até" aparece exatamente 2 vezes no arquivo (label do form de cadastro + cabeçalho
  único da grade) — era 3 antes (duas tabelas divergentes + o label).
- Grid 80px/1fr/160px com gap-4, padding das linhas (12px/18px) e do cabeçalho (10px/18px),
  texto 13px, raio 12px, alinhamento esquerda/esquerda/direita.
- Área expandida com padding lateral de 22px.
- Nenhuma classe de espaçamento com decimal fora da escala real do Tailwind (px-4.5 etc. — a
  armadilha registrada no plano que já mordeu dois executores anteriores desta fase).
- Frase de herança da tabela do grupo ("Este grupo está usando a tabela da empresa X" / "Quem
  manda é a empresa do grupo que mais faturou no mês") intacta.
- Sem fonte recusada / Instrument Sans / fonts.googleapis.

Durante a escrita do teste, dois comentários do próprio componente continham literalmente a
notação de tabela HTML (numa frase explicando que ela deixou de ser usada) e o nome da fonte
recusada — o que fazia o teste novo falsear positivo contra o próprio arquivo que ele deveria
validar. Reescritos sem os literais banidos (Deviations, abaixo).

**CSS compilado conferido via script Node** (não grep do shell, que escapa mal colchetes/barras
de seletor — armadilha registrada no plano): confirmada a presença real no app-*.css de
grid-template-columns:80px 1fr 160px, padding-left/right:18px, padding-left/right:22px,
gap:1rem (gap-4), padding-top/bottom:.625rem (py-2.5), padding-top/bottom:.75rem (py-3),
border-radius:.75rem (rounded-xl), font-size:13px, font-size:12px, letter-spacing:.05em,
background-color:#14161d (ecf-card-2). Nenhum caso da armadilha "classe escrita mas sem CSS
gerado".

## Gate (--filter="Phase122|Phase136|Phase137|Phase138|Phase139")

- **Antes:** 322 testes / 1694 asserções / 0 falhas (medido no início desta execução, árvore
  limpa — bate com o valor informado no PLAN.md).
- **Depois:** 335 testes / 1717 asserções / 0 falhas (322+13 testes, 1694+23 asserções — o
  incremento é exatamente o do novo arquivo de teste; nenhuma regressão).

`Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa_de_uma_empresa_gera_aviso_novo_so_sobre_ela`
(flaky pré-existente, registrado em `.planning/todos/pending/260904-teste-flaky-aviso-mudanca-faixa.md`)
passou tanto na suíte completa quanto isolado — não foi tocado.

## npm run build

Passou limpo (2x — antes e depois do ajuste de comentários). `public/build` é gitignored, nada
para commitar ali.

## Arquivos modificados

- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` — subcomponente única +
  densidade da referência + título do bloco.
- `resources/js/Pages/Admin/Financeiro.jsx` — padding lateral da área expandida (22px).
- `tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php` — novo, trava tudo acima.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Comentários do próprio componente continham os literais banidos pelo novo teste**
- **Encontrado durante:** escrita do teste `Phase139TabelaProgressivaFielTest`.
- **Problema:** um comentário explicando a mudança citava a notação de tabela HTML que deixou de
  ser usada, e outro citava o nome da fonte recusada pelo usuário — ambos como texto de
  comentário, não como render, mas o teste novo faz checagem bruta de substring no arquivo
  inteiro (mesmo padrão que `Phase139FechamentoUiContratoTest` já usa para `Financeiro.jsx`),
  então os próprios comentários derrubavam o teste que deveriam documentar.
- **Fix:** reescritos os dois comentários sem os literais banidos. Nenhuma mudança de
  comportamento.
- **Arquivos:** `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx`.
- **Commit:** `6d05fd11`.

Nenhum outro desvio — plano executado conforme escrito.

## Known Stubs

Nenhum.

## Threat Flags

Nenhuma superfície nova (sem endpoint, rota ou schema novo) — mudança é só de apresentação
(JSX/CSS) reaproveitando dados e rotas já existentes.

## Self-Check: PASSED

- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` — FOUND
- `resources/js/Pages/Admin/Financeiro.jsx` — FOUND
- `tests/Feature/Phase139/Phase139TabelaProgressivaFielTest.php` — FOUND
- Commit `5e290eb0` — FOUND (git log --oneline --all)
- Commit `2df1142e` — FOUND
- Commit `6d05fd11` — FOUND
