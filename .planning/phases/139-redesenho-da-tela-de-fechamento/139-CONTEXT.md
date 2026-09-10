---
phase: 139
slug: redesenho-da-tela-de-fechamento
created: 2026-09-04
origem: handoff de design do usuário em `design_handoff_fechamento/`, após usar a tela em produção
---

# Fase 139 — Contexto

O usuário usou a tela de fechamento depois das Fases 137 e 138 e disse: **"as coisas estão
funcionando, mas a UI/UX está difícil de entender — tem que ser mais simples e intuitivo."**

Ele produziu um handoff de design completo e o entregou em `design_handoff_fechamento/`:

| arquivo | o que é |
|---|---|
| `README.md` | especificação completa: layout, tokens, comportamento, regras de cálculo |
| `Fechamento.dc.html` | protótipo (template + lógica). **Referência, não código de produção** |
| `tela-atual.png` | captura da tela antes do redesenho |

⚠️ **Nenhum arquivo do handoff vai para produção.** O trabalho é recriar o design no ambiente que já
existe, reusando componentes, helpers e camada de dados do ECF Admin.

---

## D-01 — Os widgets, decididos pelo usuário

| widget | decisão |
|---|---|
| Serviços contratados | **manter** — vira barra horizontal empilhada + legenda, sai o donut |
| Tipo de cobrança | **remover** |
| Distribuição de faixas | **remover** |
| Total consolidado | **reduzir a "Total a receber"** |
| Subiram de faixa | **criar** — widget em destaque |

**Por que o Total consolidado encolhe:** ele mostra hoje "Recebido", "Inadimplente" e "A receber", e
na captura de produção os três estão **vazios** — "0 pagadores com dados". O sistema **não sabe se o
cliente pagou**. Um terço da tela ocupado por informação que não existe. Fica só o que é sabido.

**Por que o widget de upgrades existe:** é a pergunta que o fechamento responde e a tela não
respondia — onde geramos mais resultado. Traz contagem, ganho somado em R$/mês e um atalho por
empresa que filtra e abre a linha dela.

---

## D-02 — Fidelidade visual: estrutura sim, paleta não

**Decisão do usuário (2026-09-04):** seguir **estrutura, hierarquia, widgets e comportamento** do
design com fidelidade, mas manter **cores e tipografia do ECF Admin**.

O handoff traz paleta e fontes próprias, próximas mas não idênticas às do projeto:

| | projeto (`tailwind.config.js`) | handoff |
|---|---|---|
| fundo | `#050507` (`ecf-bg`) | `#0B0B0C` |
| card | `#0f1116` (`ecf-card`) | `#131315` |
| acento | `#ffe600` (`ecf-yellow`) | `#E5DE3F` |
| fonte UI | Inter / Manrope | Instrument Sans |
| números | — | JetBrains Mono |

Adotar a paleta do handoff faria **esta tela destoar de todas as outras** — diferença que ninguém
aponta e todo mundo sente. O `CLAUDE.md` do projeto manda manter os tokens `ecf-*`.

**Traduzir, não copiar:** onde o handoff especifica um hex, usar o token `ecf-*` equivalente. Onde
especifica uma proporção, um espaçamento, uma hierarquia de tamanho ou um estado de hover, **seguir
com fidelidade** — é ali que mora a melhoria de UX.

> A fonte mono para números foi oferecida ao usuário e **recusada** junto com o resto da tipografia.
> Não introduzir `JetBrains Mono`.

---

## D-03 — O que a tela já recebe, medido em 2026-09-04

Não precisa ser construído — já vem de `AdminController::fechamento()`, nos **dois ramos** (ao vivo
e congelado):

`faturamento`, `faturamento_ml`, `faturamento_shopee`, `faixa`, `faixa_ordem`,
`faixa_limite_inferior`, `faixa_limite_superior`, `valor_faixa`, `valor_faixa_e_piso`,
`cobranca_mensal`, `evolucao`, `estado`, `servicos_contratados`, `faixas_por_servico`,
`faixas_por_grupo`, `tabela_grupo_nome`, `tabela_herdada_de_nome`, `competencia_fechada`,
`competencia_fechada_em`.

Com `faixa_limite_inferior`/`superior` já dá para calcular a barra de progresso dentro da faixa e o
"falta R$ X para a próxima" **sem dado novo**.

---

## D-04 — O que FALTA, medido no código (o risco desta fase)

O design pede quatro coisas que **não existem hoje**:

1. **`faixa_ordem_anterior` exposta.** O ramo ao vivo calcula `$ordemAnterior`
   (`AdminController.php` ~linha 311) mas **não emite como prop** — sem ela não dá para escrever
   "Faixa 2 → 3" no widget de upgrades.
2. **Mensalidade da faixa anterior.** O ganho do upgrade é
   `mensalidade atual − mensalidade da faixa anterior`, e esse segundo valor **não é calculado em
   lugar nenhum**.
3. **Totais do widget "Total a receber":** soma das mensalidades, contagem de empresas com cobrança,
   e o rodapé com **mês anterior**, **variação** e **faturamento gerado**.
4. **No ramo CONGELADO**, `fechamento_snapshots` guarda `evolucao` (string) mas **não** guarda a
   ordem da faixa anterior. Dá para reconstruir lendo o snapshot da competência anterior — que
   existe — mas isso precisa estar no plano, não ser descoberto na execução.

> ⚠️ Nesta fase já aconteceu três vezes de um dado atravessar quase todo o caminho e morrer no
> último trecho. Os quatro itens acima têm que chegar **na tela**, nos **dois ramos**.

---

## D-05 — Regressão zero sobre as Fases 137 e 138

`resources/js/Pages/Admin/Financeiro.jsx` tem ~1300 linhas e concentra trabalho recém-entregue e
**verificado em produção**. O redesenho não pode perder nada disto:

- estados de ausência: `A DEFINIR` (sem tabela) e "sem faturamento neste mês" — **coisas diferentes,
  que não podem virar `R$ 0` nem traço mudo**
- composição Mercado Livre + Shopee, nunca soma silenciosa
- `a partir de R$ X` na faixa sem teto (nunca o valor seco)
- `TabelaFaixasSection` com os quatro ramos, incluindo o bloco da tabela de grupo e a frase de
  herança nomeando a empresa
- estado da competência, fechar / refazer com motivo, e a **confirmação visível de sucesso** (quick
  `260903-la4` — foi um sucesso silencioso que fez o usuário clicar três vezes)
- `SyncFaturamentoBtn` atenuado quando a competência está fechada
- a palavra **"acumulado" não pode voltar** — há trava de teste

---

## Restrições permanentes

- Copy e comentários em **pt-BR**, **sem jargão** — quem lê é o time Administrativo. Proibidos na
  tela: "snapshot", "competência", "reconsolidação", "rollup", "âncora", "origem", "faixa piso".
- `npm run build` depois de mexer em JSX.
- Árvore compartilhada: nunca `git add -A` / `git add .` / `git commit -a` / `git stash`.
- Deploy só com autorização explícita.
- Gate atual: `--filter="Phase122|Phase136|Phase137|Phase138"` em **276 testes / 1452 asserções /
  0 falhas**.
