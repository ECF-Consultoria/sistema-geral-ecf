---
phase: 177-tokens-fonte-geist-e-o-publicadorheader-global
plan: 06
subsystem: ui
tags: [publicador, header-global, seletor-de-empresa, pill-de-conexao, popover, localStorage, fidelidade-de-pixel, render-real, node-test]

# Grafo de dependências
requires:
  - "177-01: camada `pub-*` no `theme.extend` (cores, `font-pub`/`font-pub-mono`, raios `pub-sub`/`pub-control`/`pub-panel`, `shadow-pub-popover`, `animate-pub-spin`) — consumida só por nome"
  - "177-02: `iniciais()`, `tomDoProduto()` e `normalizar()` de `identidade/identidade.js` — importados, nunca recriados (IDENT-08)"
  - "177-03: a prop `cabecalho.integracoes` e a rota `mlb.anuncios.publicador.empresas-header` (sem parâmetro de entrada, T-177-08)"
  - "177-04: a casca do header, o ponto de montagem da esquerda da linha 1 e o `fecharTudo` noop"
  - "177-05: as abas-pílula com `min-w-0` (quem encolhe é o seletor, não as abas)"
  - "Fase 173: `destinoParaItem()` de `SeletorEmpresaBusca.jsx` — a navegação que preserva a aba atual, importada e NÃO reimplementada"
  - "Fase 172: `textoSeguro()` de `BarraDaConta.jsx`; `haQuanto()` de `tempo.js`; o vocabulário de `SeloConta.jsx` e `SeloPortal.jsx`"
provides:
  - "`Components/Mlb/Publicador/header/SeletorDeEmpresaDoHeader.jsx`: botão de identidade de 38px (tile 28px + bolinha de status + nome + código + duplo-chevron) e dropdown de 360px com busca, RECENTES/TODAS/RESULTADOS, item de 46px, check amarelo e rodapé com o total REAL. Exports nomeados: `agrupar`, `PainelDeEmpresas`, `normalizarPayload`, `TITULO_SEM_CONTA`, `GRUPO_*`"
  - "`Components/Mlb/Publicador/header/ConexaoDoHeader.jsx`: pill de 28px nos 3 estados e popover de 320px com Mercado Livre, ERP (só declarado) e Portal + rodapé da coleta. Exports nomeados: `estadoDaConexao`, `linhasDaConexao`, `PainelDeConexoes`, `tomDoPortal`, `PILL`, `TITULO_SEM_COMPANY`, `AVISO_RECONEXAO`"
  - "`Components/Mlb/Publicador/recentesDoPublicador.js`: fonte ÚNICA das contas recentes no `localStorage` (`MAX_RECENTES`, `chaveRecentes`, `lerRecentes`, `gravarRecente`)"
  - "`textoUtil()` em `BarraDaConta.jsx`: irmão do `textoSeguro` para quando vazio NÃO vale (texto que vira rótulo e valor que vira parâmetro de URL)"
  - "`tests/js/publicador-header-conta-render.test.js`: 109 testes de render real + FID-01 travado elemento por elemento"
  - "A linha 1 do header COMPLETA — só o modal da Fase 178 fica de fora"
affects: [177-07, 177-08, 177-09, 178, 180]

# Rastreio técnico
tech-stack:
  added: []   # T-177-SC: nenhum pacote instalado; `@radix-ui/react-popover`, `axios` e `lucide-react` já eram dependências
  patterns:
    - "Corpo de popover como componente PURO com export nomeado, porque o renderizador de servidor do React não suporta portal — é a única forma de ter gate por render real do que só existe aberto"
    - "Uma casa por pergunta: `estadoDaConexao()` responde \"esta conta está conectada?\" para a pill E para a bolinha do tile; `recentesDoPublicador.js` responde \"quais as contas recentes?\" para a tela de entrada E para o dropdown"
    - "`textoUtil` vs `textoSeguro`: vazio é ausência quando o texto vira rótulo ou parâmetro de URL"
    - "Estado NÃO MEDIDO não desenha indicador nenhum (nem cinza): um dot colorido é afirmação sobre a conexão"
    - "Conversão de `strokeWidth` entre viewBox 16 (protótipo) e 24 (Lucide): fator 1,5"

key-files:
  created:
    - resources/js/Components/Mlb/Publicador/header/SeletorDeEmpresaDoHeader.jsx
    - resources/js/Components/Mlb/Publicador/header/ConexaoDoHeader.jsx
    - resources/js/Components/Mlb/Publicador/recentesDoPublicador.js
    - tests/js/publicador-header-conta-render.test.js
  modified:
    - resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx    # montagem dos dois + 3 strokeWidth + userId + fecharTudo documentado
    - resources/js/Components/Mlb/Publicador/header/AbasDoHeader.jsx        # só os 4 strokeWidth
    - resources/js/Components/Mlb/Publicador/BarraDaConta.jsx               # só o export novo `textoUtil`
    - resources/js/Pages/Mlb/AnunciosEmpresas.jsx                           # só a troca das 4 definições locais por um import
    - tests/js/publicador-entrada.test.js                                   # reaponte por asserção + 1 asserção nova
    - tests/js/publicador-header-render.test.js                             # 3 asserções de traço + 2 contagens atualizadas
    - tests/js/publicador-header-abas-render.test.js                        # 1 asserção de traço atualizada
    - tests/js/publicador-vocabulario-novo.test.js                          # só a constante ARQUIVOS_NOVOS (+3 arquivos)

key-decisions:
  - "A correção dos 7 ícones (mandato do usuário): `viewBox` 16 do protótipo × 24 do Lucide ⇒ fator 1,5. Os ícones estavam com dois terços da espessura desenhada"
  - "Onde o plano divergiu do protótipo, o PROTÓTIPO venceu em 5 medidas — o botão da conta é 38px/raio 10px (não 34px/9px) e os três tiles são raio 8px (não 10px)"
  - "O corpo do dropdown e o do popover saíram para exports nomeados: o renderizador de servidor do React não suporta portal, e gate por leitura de fonte foi o que o 177-04 teve de reescrever"
  - "`sem_token` é \"Falta reconectar\", não \"Sem conexão\": o docblock do `SeloConta` diz que essas contas autorizaram de verdade antes de 21/09/2026"
  - "\"Reconectar →\" leva à LISTA de empresas, nunca ao link de OAuth: clique interno da ECF autoriza a conta errada (caso Masitto, 27/08)"
  - "Estado não medido não desenha pill nem bolinha — \"não sabemos\" não é \"está tudo bem\""

patterns-established:
  - "Prova por mutação com restauração conferida por sha256: 125 mutações, 7 furos achados e fechados, 125/125 reprovando no fim"
  - "Asserção de cor/rótulo em PAR (texto + dot), não só o rótulo: foi assim que \"Token expirado em âmbar\" e \"Ofertas novas → Novidades\" passaram a reprovar"
  - "Classe condicional do `cn()` conferida pelo FIM da lista (`tag.endsWith('bg-X\">')`): `includes` casa o `hover:` e é asserção vazia"

requirements-completed: [HEAD-04, HEAD-05, HEAD-06, HEAD-07, HEAD-08, HEAD-12, HEAD-13, FID-01, FID-02, FID-03, FID-04, FID-06, REG-01, REG-02, REG-06, GATE-01, GATE-05, REND-01, REND-02, REND-03, REND-04]

# Métricas
duration: ~2h20min
completed: 2026-10-12
---

# Fase 177 Plano 06: seletor de empresa e pill de conexão do header — Summary

**A identidade da conta nasceu na linha 1 do header com a geometria medida no protótipo — botão de 38px com tile de 28px e bolinha de status de 10px, dropdown de 360px com busca que ignora acento e caixa, grupos RECENTES/TODAS/RESULTADOS, item de 46px com check amarelo e o total REAL do servidor no rodapé, mais a pill de 28px nos três estados com popover de 320px —, as contas recentes viraram um módulo só (lido pela tela de entrada e pelo dropdown), o ERP aparece como "Declarado: X" e nunca como "Conectado", "Atualizar agora" ENFILEIRA, e os 7 ícones já commitados do header tiveram o traço corrigido pela conta `viewBox` 16 × Lucide 24; o gate só foi aceito depois de 125 mutações, 7 furos achados e fechados e 125/125 reprovando.**

## Performance

- **Duração:** ~2h20min
- **Concluído:** 2026-10-12
- **Tarefas:** 3 de 3
- **Arquivos:** 4 criados, 8 modificados
- **Commits:** `9dbed9d8` (Task 1), `cae0c9c7` (ícones das abas), `d2ff1255` (Tasks 2+3) + o desta SUMMARY
- **Linhas no meu range (`90ca5935..HEAD`):** 2.383 adições, 111 remoções, **0 arquivos deletados**

## Baselines — medidas por mim, hoje, antes e depois

| Suíte | Antes (medido agora) | Depois | Leitura |
|---|---|---|---|
| JS inteiro (`npm run test:js`) | **2502 · 2501 pass · 1 fail** | **2631 · 2630 pass · 1 fail** | +129 testes (109 do gate novo, 12 do vocabulário sobre os 3 arquivos novos, 2 do reaponte dos recentes, 6 dentro dos gates existentes) |
| PHP `tests/Feature/Publicador` | — | **1277 · 0 failures** (8777 asserções) | plano só de cliente; `git diff --name-only 90ca5935..HEAD -- app routes tests/Feature` é **vazio** |

⚠️ O número de partida bate com o que o 177-05 deixou (2502). **O único fail é o mesmo
pré-existente** `tests/js/estrutura-grade-glide.test.js` → *"Características secundárias nasce
recolhido (é o grupo que mais infla)"*, de outro território e de outra fase — **não corrigido de
propósito**.

## ⚠️ A correção dos 7 ícones (tarefa extra mandatada pelo usuário)

### A conta

Os SVG do protótipo usam **`viewBox="0 0 16 16"`**; o Lucide usa **`0 0 24 24`**. O `stroke-width`
é medido **no sistema de coordenadas do viewBox**, então o mesmo número não dá o mesmo traço:

| | Conta | Traço renderizado |
|---|---|---|
| protótipo (`viewBox` 16, ícone 15px) | `1.6 × 15/16` | **1,5 px** |
| Lucide antes (`viewBox` 24, ícone 15px) | `1.6 × 15/24` | **1,0 px** |

Os ícones saíam com **dois terços** da espessura desenhada. **O fator é `24/16 = 1,5`.**

### Os 7 já commitados (waves 3 e 4) — antes / depois

| # | Ícone | Arquivo | Protótipo | Antes | **Depois** |
|---|---|---|---|---|---|
| 1 | `Search` (busca de 250px) | `PublicadorHeader.jsx` | `1.6` (dc.html:95) | `1.6` | **`2.4`** |
| 2 | `Settings` (Configurações, com conta) | `PublicadorHeader.jsx` | `1.4` (dc.html:100) | `1.4` | **`2.1`** |
| 3 | `Settings` (Configurações, sem conta) | `PublicadorHeader.jsx` | `1.4` (dc.html:100) | `1.4` | **`2.1`** |
| 4 | `Plus` (CTA "Nova publicação") | `PublicadorHeader.jsx` | `2` (dc.html:104) | `2` | **`3`** |
| 5-8 | `LayoutDashboard`, `Package`, `LayoutList`, `Zap` (as 4 abas) | `AbasDoHeader.jsx` | `1.6` (dc.html:113-117) | `1.6` | **`2.4`** |

(São 8 ocorrências para os "7 ícones" que o usuário contou — o `Settings` aparece **duas vezes** no
arquivo, no ramo com conta e no ramo desabilitado, e os dois foram corrigidos.)

### Os 3 que eu acrescentei nesta wave

| Ícone | Protótipo | Implementado |
|---|---|---|
| `Search` (busca do dropdown) | `1.6` (dc.html:45) | **`2.4`** |
| `ChevronsUpDown` (duplo-chevron do botão) | `1.7` (dc.html:40) | **`2.55`** |
| `Check` (conta atual) | `2` (dc.html:59) | **`3`** |

### ⚠️ O que NÃO entra na conta

O separador **"/"** da linha 1 é **SVG inline com o viewBox do próprio protótipo (16×22)**
(`PublicadorHeader.dc.html:32`), então o `1.4` dele já é o traço certo. Há asserção **nova**
travando isso, para ninguém "uniformizar" os traços depois e estragar o separador — e há mutação
provando (`separador "/" uniformizado por engano` → reprova).

### Asserções ATUALIZADAS (nenhuma apagada)

| Arquivo | Asserção | Antes | Depois |
|---|---|---|---|
| `publicador-header-render.test.js` | `exigirClasses(busca, …)` | `stroke-width="1.6"` | `stroke-width="2.4"` |
| idem | `exigirClasses(engrenagem, …)` | `stroke-width="1.4"` | `stroke-width="2.1"` |
| idem | `exigirClasses(mais, …)` | `stroke-width="2"` | `stroke-width="3"` |
| idem | **ACRESCENTADA** | — | o separador "/" continua `1.4` com `viewBox="0 0 16 22"` |
| `publicador-header-abas-render.test.js` | `contar(HTML_CHEIO, 'stroke-width=…')` | `"1.6"` = 4 | `"2.4"` = 4 |
| idem | **ACRESCENTADA** | — | `"1.6"` = **0** (nenhum ícone ficou para trás) |
| `publicador-header-conta-render.test.js` | — | — | os 3 novos travados por elemento + negativa de 1.6/1.7/2 na fonte |

## Tabela FID-01 — protótipo × implementado, com `arquivo:linha`

Protótipo = `new_layout_publicador/PublicadorHeader.dc.html`. A coluna "Implementado" é o valor
**real** que está no arquivo e que o gate trava por elemento.

| # | Dimensão | Protótipo | Implementado |
|---|---|---|---|
| 1 | largura | dropdown `360px` (:43) · popover `320px` (:77) · tile do botão `28px` (:35) · tile do item `30px` (:54) · tile do popover `30px` (:80) · dot do item `6px` (:57) · dot da pill/status `7px` (:74/:82) · bolinha de status `10px` (:36) | `w-[360px]` · `w-[320px]` · `w-[28px]` · `w-[30px]` · `w-[30px]` · `w-[6px]` · `w-[7px]` · `w-[10px]` |
| 2 | altura | botão `38px` (:34) · pill `28px` (:73) · input `38px` (:46) · item `46px` (:53) · linha do popover por conteúdo `py:12px` (:79) · lista `max-height:340px` (:48) · spinner — | `h-[38px]` · `h-[28px]` · `h-[38px]` · `h-[46px]` · `py-[12px]` · `max-h-[340px]` · `h-[12px]` |
| 3 | grid | item em `flex` com nome/meta em coluna (`flex-direction:column; gap:2px; min-width:0; flex:1`, :55) e check à direita (:59); tiles em `display:grid; place-items:center` (:35/54/80) | `flex` + `flex min-w-0 flex-1 flex-col gap-[2px]` + check no fim · `grid place-items-center` |
| 4 | spacing | bolinha em `right:-3px; bottom:-3px` (:36) · dropdown em `top:46px` = 38 + 8 (:43) · popover em `top:36px` = 28 + 8 (:77) | `right-[-3px] bottom-[-3px]` · `sideOffset={8}` nos dois |
| 5 | padding | botão `0 10px 0 5px` (:34) · pill `0 10px 0 8px` (:73) · busca `10px` (:44) · input `0 12px 0 36px` (:46) · lista `6px` (:48) · item `0 10px` (:53) · grupo `10px 10px 6px` (:51) · linha do popover `12px 14px` (:79) · rodapé do dropdown `10px 16px` (:65) · rodapé do popover `10px 14px` (:85) | `pl-[5px] pr-[10px]` · `pl-[8px] pr-[10px]` · `p-[10px]` · `pl-[36px] pr-[12px]` · `p-[6px]` · `px-[10px]` · `px-[10px] pb-[6px] pt-[10px]` · `px-[14px] py-[12px]` · `px-[16px] py-[10px]` · `px-[14px] py-[10px]` |
| 6 | gap | botão `10px` (:34) · pill `8px` com dots `3px` (:73/74) · item `10px` com meta `6px` (:53/57) · coluna nome/meta `2px` (:55) · linha do popover `12px` com status `6px` (:79/82) | `gap-[10px]` · `gap-2` + `gap-[3px]` · `gap-[10px]` + `gap-[6px]` · `gap-[2px]` · `gap-[12px]` + `gap-[6px]` |
| 7 | tipografia — família | Geist; código da conta, código do item e `conta_ml_id` em Geist Mono (:39/:57) | `font-pub` no `Popover.Content` de cada painel (o Portal sai da árvore do header) · `font-pub-mono` no código da conta e no de cada item |
| 8 | tipografia — tamanho | nome `14.5px` (:38) · código `11px` (:39) · tile do botão `10.5px` (:35) · input `13.5px` (:46) · grupo `11px` (:51) · nome do item `13.5px` (:56) · meta do item `11.5px` (:57) · código do item `11px` (:57) · tile do item `11px` (:54) · pill `12px` (:73) · nome do popover `13px` (:81) · meta do popover `11.5px` (:81) · tile do popover `10px` (:80) · status `12px` (:82) · rodapés `12px` (:65/:85) | `text-[14.5px]` `text-[11px]` `text-[10.5px]` `text-[13.5px]` `text-[11px]` `text-[13.5px]` `text-[11.5px]` `text-[11px]` `text-[11px]` `text-[12px]` `text-[13px]` `text-[11.5px]` `text-[10px]` `text-[12px]` `text-[12px]` |
| 9 | tipografia — peso | nome `600` (:38) · tile do botão `700` (:35) · grupo `600` (:51) · nome do item `500` (:56) · tile do item `600` (:54) · pill `500` (:73) · nome do popover `500` (:81) · tile do popover `700` (:80) · status `500` (:82) · links de rodapé `500` (:67/:87) | `font-semibold` `font-bold` `font-semibold` `font-medium` `font-semibold` `font-medium` `font-medium` `font-bold` `font-medium` `font-medium` |
| 10 | tracking | nome `-0.01em` (:38) · grupo `0.08em` (:51) | `tracking-[-0.01em]` · `tracking-[0.08em]` |
| 11 | raio | botão `10px` (:34) · dropdown `14px` (:43) · popover `12px` (:77) · input `9px` (:46) · item `9px` (:53) · os 3 tiles `8px` (:35/54/80) · pill e dots `50%`/`999px` (:73/74) | `rounded-pub-sub` · `rounded-[14px]` · `rounded-pub-panel` · `rounded-pub-control` · `rounded-pub-control` · `rounded-[8px]` · `rounded-full` |
| 12 | bordas | dropdown/popover `1px #2E2E2E` (:43/:77) · input `1px #2E2E2E` com foco `#FACC15` (:46) · tile do botão `1px #2E2E2E` (:35) · bolinha `2px #0A0A0A` (:36) · divisórias `1px #1F1F1F` (:44/:65/:79/:85) · pill conforme estado (:216) · spinner `1.5px` | `border-pub-line-strong` (×2) · `border-pub-line-strong` + `focus:border-pub-accent` · `border-pub-line-strong` · `border-2 border-pub-page` · `border-pub-line-subtle` · `border-pub-danger/[0.45]` \| `border-pub-warn/[0.45]` \| `border-pub-line` · `border-[1.5px]` |
| 13 | sombras | dropdown e popover `0 24px 60px rgba(0,0,0,0.7)` (:43/:77) | `shadow-pub-popover` (o token é byte a byte esse valor) |
| 14 | cores | fundo dos painéis `#111111` (:43/:77) · rodapés e input `#0D0D0D` (:65/:85/:46) · hover do item `#1A1A1A` (:53) · item atual `#1A1A1A` com tile `#FACC15`/`#0A0A0A` (:209) · check `#FACC15` (:59) · tile do botão `#1F1F1F` com texto `#FACC15` (:35) · tile ERP/PT `#1F1F1F`/`#D4D4D8` (:219) · código `#71717A` (:39) · grupo `#71717A` (:51) · meta `#8A8A90` (:57/:81) · "·" `#3F3F46` (:57) · dots ok/warn/erro `#22C55E`/`#F59E0B`/`#EF4444` · textos de status `#4ADE80`/`#FBBF24`/`#F87171` · hover do botão `#141414` com borda `#262626` (:34) · aberto `#141414`/`#2E2E2E` (:225) · hover da pill `#3F3F46` (:73) | `bg-pub-surface` · `bg-pub-sunken` · `hover:bg-pub-hover-3` · `bg-pub-hover-3` + `bg-pub-accent text-pub-page` · `text-pub-accent` · `bg-pub-line-subtle text-pub-accent` · `bg-pub-line-subtle text-pub-fase1` · `text-pub-txt-4` · `text-pub-txt-4` · `text-pub-txt-3` · `text-pub-line-off` · `bg-pub-ok`/`bg-pub-warn`/`bg-pub-danger` · `text-pub-ok-txt`/`text-pub-warn-txt`/`text-pub-danger-txt` · `hover:bg-pub-hover hover:border-pub-line` · `bg-pub-hover border-pub-line-strong` · `hover:border-pub-line-off` — **todos exatos, por nome de token** |
| 15 | alinhamento | rodapés `justify-between` (:65/:85) · status com `white-space:nowrap` (:82) · botão e item `text-align:left` (:34/:53) · nome do item com `text-overflow:ellipsis` (:56) · `white-space:nowrap` no botão, na pill e no item (:34/:73/:53) | `justify-between` · `whitespace-nowrap` · `text-left` · `truncate` · `whitespace-nowrap` nos três |
| 16 | ícones | busca `15px`/`1.6` (:45) · duplo-chevron `13px`/`1.7` (:40) · check `14px`/`2` (:59) | Lucide `Search size={15} strokeWidth={2.4}` · `ChevronsUpDown size={13} strokeWidth={2.55}` · `Check size={14} strokeWidth={3}` — **ver a conta do viewBox acima** |
| 17 | badges | tiles "ML"/"ERP" de 30px (:80) · código em mono (:39/:57) | linhas 1/8/9/11/14 desta tabela |
| 18 | tabelas | — | n/a |
| 19 | estados | botão fechado/aberto (:225) · hover do item (:53) · atual com check (:59) · 3 estados da pill (:216) · spinner de "Atualizar" | todos acima + `animate-pub-spin` |
| 20 | densidade / CTAs | "Ver todas →" no rodapé do dropdown (:67) · "Atualizar agora" no rodapé do popover (:87) | idem, à direita de cada rodapé |

## ⚠️ Onde o PROTÓTIPO venceu o plano — 5 medidas

A tabela FID-01 do plano é artefato derivado; o protótipo é a fonte. Nas cinco, medi e segui o
protótipo:

| # | O plano prescrevia | O protótipo tem | Implementado |
|---|---|---|---|
| 1 | botão da conta com `rounded-pub-panel` (**12px**) | `border-radius:10px` (**:34**) | `rounded-pub-sub` (10px) |
| 2 | tiles com `rounded-pub-sub` (**10px**) | `border-radius:8px` nos três tiles (**:35, :54, :80**) | `rounded-[8px]` — ⚠️ **não há token de 8px na camada**; é valor arbitrário de propósito (TOK-15 proíbe hex, não medida) |
| 3 | nada sobre o tamanho das iniciais do tile do item | `font-size:11px; font-weight:600` (**:54**) | `text-[11px] font-semibold` |
| 4 | "ícone `Search` absoluto" (sem coordenadas) | `left:22px; top:21px` (**:45**) | `absolute left-[22px] top-[21px]` |
| 5 | nada sobre `white-space` nem sobre `gap` da coluna nome/meta | `white-space:nowrap` no botão, na pill e no item (**:34, :73, :53**); `gap:2px` na coluna (**:55, :81**) | `whitespace-nowrap` nos três; `gap-[2px]` nas duas colunas |

E a **recado do 177-05 que não se confirmou na régua**: ele dizia "o seletor é outro controle de
34px: atualize as contagens de 8 para 9". **O protótipo mede 38px de altura e 10px de raio** para
esse botão (`:34`) — o "header 34px" do README vale para os controles da linha 1 da direita, não
para ele. Então as contagens `h-[34px]` e `rounded-pub-control` **continuam em 8**, e há asserção
NEGATIVA no gate novo (`proibirClasses(tag, ['h-[34px]', 'rounded-pub-control', 'rounded-pub-panel'])`)
para o número certo não ser "corrigido" de volta. As contagens que **de fato** subiram foram outras
duas — ver abaixo.

## Contagens ATUALIZADAS no gate do 177-04 (nenhuma apagada)

| Contagem | Antes | Depois | Por quê |
|---|---|---|---|
| `font-pub-mono` | 3 | **4** | o código da conta é mono de 11px (dc.html:39) |
| `text-[10.5px]` | 3 | **4** | as iniciais do tile do botão são 10,5px (dc.html:35) |
| `h-[34px]` | 8 | **8** (inalterada) | o botão da conta tem 38px, medido no protótipo |
| `rounded-pub-control` | 8 | **8** (inalterada) | o raio do botão da conta é 10px, não 9px |
| `font-pub[\s"]` | 1 | **1** (inalterada) | os dois popovers redeclaram a família, mas só renderizam ABERTOS (Portal) |
| `gap-[2px]` | 2 | **2** (inalterada) | as colunas nome/meta só existem dentro dos painéis |

## Divergências declaradas (FID-02 — cada uma com restrição concreta)

As 6 que o plano previu:

| Item | Protótipo | Implementado | Restrição concreta |
|---|---|---|---|
| Implementação do dropdown/popover | `position:absolute` + backdrop transparente `fixed inset-0 z-60` (:129) | `@radix-ui/react-popover` com `Portal`, Esc e clique-fora nativos | O header vive **dentro do `<main>` com `overflow-y-auto`** e D2 proíbe alterar o `AppLayout`: conteúdo absoluto ali é recortado pelo scrollport e rola com a página. O Radix já é dependência e é o que `BarraDaConta` usa hoje. HEAD-13 preservado — `onEscapeKeyDown`/`onPointerDownOutside` **não** foram desabilitados, e há gate negativo provando. |
| 2º dot da pill e âmbar da bolinha | ERP (:74, :226) | **Portal do Cliente** | O ERP é **só declarado** (`CabecalhoPublicadorService`, "honestidade 1"): não existe integração, logo não existe estado "conectado" para medir. O Portal tem estado real (`situacao`/`novas`) e é a capacidade que `SeloPortal` entrega hoje (HEAD-11). |
| Linha do ERP no popover | "Conectado · 32 SKUs · estoque atualizado há 25 min" (:219) | "Declarado: {nome}" / "Não informado" | Mesmo motivo. **Asserção NEGATIVA** de "Conectado" na linha do ERP — ver a prova abaixo. |
| Meta do ML | "Conta ECFDEV02 · token renova em 4 h" (:218) | "Conta {nome} · {ml_user_id}" | `empresaParaTela()` expõe `ativo|expirado|sem_token`, não o prazo. Gate: `doesNotMatch(/renova/i)` no HTML **e** na fonte. |
| 3ª linha do popover | 2 linhas (:217-220) | 3 linhas (ML, ERP, Portal) | HEAD-11: a informação do `SeloPortal` não pode se perder, e é o único segundo sinal de conexão REAL que existe. |
| Rodapé "48 empresas sob gestão" | número fixo do mock (:66) | `total` real do endpoint | HEAD-05/HEAD-10. Gate: sem total **nenhuma** contagem é escrita, e `doesNotMatch(FONTE, /48 empresas/)`. |

E **3 que eu acrescentei**, cada uma com motivo escrito:

| Item | Protótipo / plano | Implementado | Motivo |
|---|---|---|---|
| `"Reconectar →"` | o plano pedia `<a>` com o `link_reconexao` | `<Link>` para a **lista de empresas**, com `title`/`aria-label` avisando | O link é o link público do Onboarding: **quem clica tem de ser o CLIENTE, no navegador dele**. Um clique interno da ECF autoriza a conta errada — é o caso **Masitto, 27/08**, escrito no `title` de `LinkReconexao.jsx`. Na lista o link aparece num botão que **copia**, que é a forma segura. Gate: `doesNotMatch(html, /exemplo\/onboarding/)` e `doesNotMatch(FONTE, /href=\{[^}]*link_reconexao/)`. |
| `sem_token` | o plano prescrevia "Sem conexão" | **"Falta reconectar"** | `SeloConta.jsx` documenta que `sem_token` = "autorizou antes de 21/09/2026, quando o callback descartava a credencial: **autorizou de verdade**, falta reconectar". "Sem conexão" mandaria o consultor pedir autorização do zero. É o literal que já está no ar (REG-01). |
| estado NÃO medido | o plano não cobre | pill e bolinha **não são desenhadas** | Um dot colorido é afirmação sobre a conexão. Verde sem dado seria mentira e cinza seria enfeite. Mesma disciplina do contador nulo do 177-05 ("não medimos" ≠ "medimos e deu zero"). |

## ⚠️ Como o ERP aparece — e a prova de que nunca diz "Conectado"

| `integracoes.erp.declarado` | Meta | Status | Dot |
|---|---|---|---|
| `"Bling"` | `Declarado: Bling` | `Declarado` | `bg-pub-neutral-dot` (cinza) |
| `null` / ausente / `""` / `"   "` / objeto | `Não informado` | `Não informado` | `bg-pub-neutral-dot` (cinza) |

Provas no gate (`publicador-header-conta-render.test.js`):

1. A linha do ERP é **recortada do HTML** (do tile `>ERP</span>` até o início da linha seguinte) e
   dentro dela: `doesNotMatch(/Conectado/)`, `doesNotMatch(/Sincronizado/)`, `doesNotMatch(/SKU/)`,
   `doesNotMatch(/estoque/i)`.
2. Isso vale para os **5 formatos** de `declarado` (nome, `null`, `undefined`, `''`, objeto).
3. Na FONTE: `doesNotMatch(/Bling/)` e `doesNotMatch(/SKUs/)` — nada do mock do protótipo entrou.
4. `linhasDaConexao(valor)[1].rotulo !== 'Conectado'` para **toda** forma de entrada
   (`null`, `undefined`, `{}`, `'x'`, `7`, `[]`).
5. O dot do ERP é conferido **em par com o texto**: `text-pub-txt-2` + `bg-pub-neutral-dot`, nunca
   `bg-pub-ok`. Três mutações separadas tentam fazer o ERP dizer/ficar "conectado" e as três
   reprovam.

## A tabela do reaponte de asserções (GATE-01)

`publicador-entrada.test.js` — **nenhuma asserção foi apagada.** As que falam do I/O e da regra
mudaram de arquivo-alvo; as de renderização ficaram onde estavam; e uma foi **acrescentada**.

| Asserção | Antes | Depois | Justificativa |
|---|---|---|---|
| `publicador\.recentes\.\$\{userId\}` | `AnunciosEmpresas.jsx` | **`recentesDoPublicador.js`** | a chave do `localStorage` passou a ser declarada no módulo |
| `window.localStorage.getItem` / `setItem` | idem | **idem** | o I/O passou a ser do módulo |
| `>= 2` blocos `try {` + `catch { return []; }` | idem | **idem** | os dois `try/catch` vieram juntos, byte a byte |
| `MAX_RECENTES = 4` · `slice(0, MAX_RECENTES)` · `filter((r) => r?.chave !== item.chave)` · `[item, ...semDuplicata]` | idem | **idem** | a regra "até 4, sem duplicar, mais recente primeiro" é do módulo |
| `const item = { chave: e.chave, nome: …, identificador: …, programa: … }` (T-173-07, sem token) | `AnunciosEmpresas.jsx` | **FICOU** em `AnunciosEmpresas.jsx` | quem monta o item é a tela |
| `recentes.length > 0 &&` e `doesNotMatch(/Nenhum recente ainda/)` | `AnunciosEmpresas.jsx` | **FICARAM** | é renderização da tela |
| `gravarRecente` é chamado **ANTES** de navegar | implícito (provado pela proximidade do código) | **ACRESCENTADA** em `AnunciosEmpresas.jsx`: `indexOf('gravarRecente(userId, item)') < indexOf("router.get(route('mlb.anuncios.publicador.visao-geral'")` | depois do `import`, a ordem tem de ser DITA — o teste antigo a provava por as funções estarem logo acima da chamada |
| **ACRESCENTADAS** (anti-divergência) | — | a tela **importa** do módulo, `doesNotMatch(tela, /window\.localStorage/)` e `doesNotMatch(tela, /function (lerRecentes\|gravarRecente)/)` | sem isso, alguém poderia reimplementar as funções na tela e o gate passaria por omissão |

⚠️ **Nenhuma outra asserção de `publicador-entrada.test.js` foi tocada** — nem as três de aparência
(tipografia/peso/amarelo), nem o veto a `@/Components/ui/select`, nem o a `dangerouslySetInnerHTML`.
`AnunciosEmpresas.jsx` **não** é arquivo redesenhado nesta fase e **continua** na lista `ARQUIVOS`
do gate de tipografia antigo.

`git diff b36037f9..HEAD -- resources/js/Pages/Mlb/AnunciosEmpresas.jsx` mostra **só** a troca das 4
definições locais por um `import` (+5 / −35 linhas): nenhuma classe de estilo, nenhum texto de tela.

## O que foi acrescentado ao `ARQUIVOS_NOVOS`

Em `tests/js/publicador-vocabulario-novo.test.js`:

```
resources/js/Components/Mlb/Publicador/header/SeletorDeEmpresaDoHeader.jsx
resources/js/Components/Mlb/Publicador/header/ConexaoDoHeader.jsx
resources/js/Components/Mlb/Publicador/recentesDoPublicador.js
```

**Nada mais mudou nesse arquivo.** A lista é constante de propósito: sem isso o gate passaria **por
omissão**. Com ela, os 3 arquivos novos passaram a ser conferidos contra `text-sm`, `ecf-*`,
`font-display`, `border-white/[0.08]`, hex literal, breakpoint novo, `oklch(` fora de
`identidade.js` e a geometria do glyph (+18 testes).

## Prova por mutação — 125 mutações, 7 furos achados e fechados, 125/125 reprovando

| Rodada | Mutações | Reprovaram | Furos |
|---|---|---|---|
| 1ª | 114 | 109 | **5** |
| 2ª (as 5 + 11 novas em volta do endurecido) | 16 | 14 | **2** |
| 3ª (as mesmas 16, com o gate endurecido) | 16 | **16** | **0** |

Os **7 furos** foram defeitos de gate de verdade, não detalhe — e dois deles expuseram **bug no
código**:

| Furo | Por que o gate não viu | O que mudou |
|---|---|---|
| fundo da conta atual trocado de token | `tag.includes('bg-pub-hover-3')` casava o `hover:bg-pub-hover-3` da base — **asserção vazia** | passou a conferir pelo **FIM** da lista de classes (`tag.endsWith('bg-pub-hover-3">')`) + contagens exatas (4 total, 3 hovers) |
| chave da conta vazia voltando a ser conta | não havia caso com `chave: ''` | o loop de chave inesperada ganhou `''` e `'   '`, e o gate exige `aria-disabled` + nenhum `<button>` |
| `sem_token` contando como "Conectada" no item | a fixture não tinha conta `sem_token` | tabela de 7 tokens × rótulo × cor do dot |
| "Token expirado" ficando ÂMBAR | o gate conferia só o RÓTULO | tabela de 4 estados do ML com o **par** texto+dot |
| `novas` não numérico escrito na tela | "sem NaN" passava: `'tres'` virava *"tres ofertas novas"* | a meta é conferida como string EXATA, e 6 formatos de `novas` caem na frase sem número |
| chave do item indo crua | nenhum caso com chave "suja" que deveria casar | item com `chave: '  company-459  '` tem de ser a conta atual; 4 chaves inutilizáveis não podem ser |
| rótulo do Portal parafraseado | só a meta era conferida | tabela de 5 situações do Portal com o **par** texto+dot |

E os **2 bugs** que as mutações/testes acharam no caminho (Rule 1):

1. **`erp.declarado = ''` renderizava "Declarado: "** — um ERP declarado sem nome.
   `textoSeguro('', null)` devolve `''`, e `''` passa por todo `!== null`.
2. **`textoUtil` não aparava o texto**, apesar de o docblock prometer. Com isso
   `'  company-459  '` não casava com `'company-459'`: o dropdown mostraria a conta em que o usuário
   **está** como se fosse outra (sem check, sem tile amarelo, sem fundo), e
   `route('…', { conta: ' company-459 ' })` montaria um destino que não existe.

Amostra do que reprova hoje: `h-[38px]`→`h-[34px]` · `rounded-pub-sub`→`rounded-pub-control` ·
tile 8px→10px · bolinha `-3px`→`-2px` · âmbar→verde · bolinha desenhada sem dado · `text-[14.5px]`→
`text-sm` · código sem `font-pub-mono` · chevron de volta ao 1.7 · `Search`/`Settings`/`Plus`/abas de
volta aos números do protótipo · separador "/" uniformizado · dropdown 360→320 · raio 14→12 ·
painel sem `font-pub` · `sideOffset` 8→4 · input 38→40 · foco deixando de ser amarelo · ícone da
busca 22→12px · lista 340→320 · grupo fora da caixa alta · tracking 0.08→0.06em · item 46→50 ·
`text-pub-page`→`text-pub-txt` sobre amarelo (TOK-06) · check removido · nome sem `truncate` · dot
6→7px · total fixo "48" no JSX · `Number.isFinite`→`!== null` no total · sem `tabular-nums` · carga
a cada abertura (DoS) · `params` no endpoint (IDOR) · destino reimplementado (perde a aba) ·
debounce zerado · recentes reimplementados na tela · busca sem filtrar · busca sem `normalizar` ·
servidor na frente do filtro local · recente fantasma · lista vazia em silêncio · mensagem de erro
parafraseada · `aria-disabled` removido · `title` ≠ `aria-label` · pill 28→30 · pill deixando de ser
redonda · pill voltando a falar de ERP · 2º dot virando ERP · pill sem dado · dot do ML sempre
verde · Portal sempre verde · popover 320→300 · 1ª linha ganhando divisória · ERP dizendo
"Conectado" (3 formas) · prazo de renovação inventado · campo cru na meta · `Reconectar` sem link ·
link do OAuth virando `href` · "Sem Portal" virando "Conectado" · `router.post`→`router.put` ·
"nunca coletado" virando "há 0 min" · botão de coleta sem `disabled` · spinner 1.5→2px · D23
oferecendo coleta sem Company · literal de D23 parafraseado · Esc do Radix desabilitado · clique
fora desabilitado · `cabecalho?.` virando `cabecalho.` · pill desmontada do header · chave por
`String()` · `userId` sem guarda · `textoUtil` deixando de barrar vazio/objeto.

Os 5 arquivos foram restaurados de cópia pristina e a restauração conferida por **sha256** (`OK`
nos cinco), com os gates verdes depois. As fixtures temporárias (`tests/js/.header-*-stub-*.mjs`) e
o script de sondagem foram apagados — `git status --short | grep tests/js` mostra só os arquivos de
teste reais.

## Decisões técnicas

1. **`PainelDeEmpresas`, `PainelDeConexoes`, `agrupar()` e `linhasDaConexao()` são exports
   nomeados.** O renderizador de **servidor** do React **não suporta portal** ("Portals are not
   currently supported by the server renderer") e este projeto roda `node --test` sem jsdom. Com o
   corpo do dropdown e do popover só dentro de `Popover.Portal`, as 46 medidas das duas tabelas
   acima só poderiam ser afirmadas por leitura de fonte — que é exatamente o gate fraco que o
   177-04 teve de reescrever depois de 4 mutações passarem. Os painéis são componentes **puros** e o
   `Popover.Content` é uma casca em volta deles. Mesmo precedente do `criarOuvinteDeAtalhos`.
2. **`estadoDaConexao()` mora em `ConexaoDoHeader.jsx` e é importada pelo seletor.** A pergunta
   "esta conta está conectada?" tem UMA casa: a pill e a bolinha de 10px do tile são duas
   aparências do mesmo estado. Duas implementações divergiriam com o tempo — é o motivo pelo qual
   `recentesDoPublicador.js` existe, e vale igual aqui. A direção (seletor → conexão) evita ciclo.
3. **`textoUtil()` em `BarraDaConta.jsx`, não um helper novo em cada arquivo.** `BarraDaConta` é a
   casa estabelecida de "texto do servidor" (`textoSeguro`), e o seletor já importa de
   `ConexaoDoHeader` — um helper lá criaria import circular. ⚠️ É o único arquivo tocado **fora** da
   lista `files_modified` do plano, e a mudança é **aditiva** (um export novo; `textoSeguro` ficou
   byte a byte).
4. **O header lê `integracoes` e SÓ `integracoes` para o estado de conexão.** `empresa.token` tem o
   mesmo valor (`CabecalhoPublicadorService` literalmente faz `'estado' => $empresa['token']`), e
   seria tentador usá-lo como reserva quando `integracoes` falta numa recarga parcial. Não usei: um
   segundo caminho para a mesma pergunta é a divergência que este plano passou o tempo todo
   eliminando. Sem `integracoes`, a pill e a bolinha simplesmente não existem.
5. **Uma carga por montagem** (T-177-25): `jaPediu` é `useRef`, o resultado fica em estado e as
   aberturas seguintes não vão à rede. A busca no **servidor** só entra quando `total > limite`
   **e** o filtro local não achou nada, com 300 ms de espera — o mesmo debounce de
   `SeletorEmpresaBusca`.
6. **`pointer-events-none` no ícone da busca.** O protótipo não tem (lá o SVG bloquearia o clique no
   input). É correção funcional, não adaptação de estilo — e é o que `SeletorEmpresaBusca` já faz.
7. **Nenhum pacote instalado** (T-177-SC). `@radix-ui/react-popover`, `axios` e `lucide-react` já
   eram dependências.
8. **Nenhum `.map()` com variável de escopo do componente** (REND-04): `linha(item)` e
   `grupo(rotulo, itens)` recebem tudo por parâmetro, as tabelas de cor são do **módulo**, e cada
   linha do popover já chega pronta de `linhasDaConexao()`.

## Verificação executada

| # | Verificação | Resultado |
|---|---|---|
| 1 | `node --test` nos 5 gates do header (conta, casca, abas, vocabulário, entrada) | **428 pass · 0 fail** |
| 2 | `npm run test:js` | **2631 · 2630 pass · 1 fail** (o mesmo fail pré-existente da baseline) |
| 3 | `C:/xampp/php/php.exe vendor/bin/phpunit tests/Feature/Publicador` | **1277 · 0 failures** · 8777 asserções |
| 4 | `git diff --stat b36037f9..HEAD -- database app/Support/Publicador resources/js/Layouts/AppLayout.jsx` | **vazio** |
| 5 | `git diff --stat b36037f9..HEAD -- Pages/Mlb/Publicador/Editor.jsx Components/Publicador/Mesa` | **vazio** (REG-03, território do outro dev) |
| 6 | `git diff --name-only b36037f9..HEAD -- resources/js/Pages` | só `AnunciosEmpresas.jsx`, e só o `import` |
| 7 | `git diff --stat b36037f9..HEAD -- Components/Mlb/Publicador/SeletorEmpresaBusca.jsx` | **vazio** — `destinoParaItem` é importado, não alterado |
| 8 | `git diff --name-only 90ca5935 -- app routes tests/Feature tailwind.config.js app.blade.php identidade/ AppLayout.jsx database Editor.jsx Mesa/ ROADMAP.md STATE.md` | **vazio** — meus 3 commits só tocam os 12 arquivos do plano |
| 9 | `git diff --diff-filter=D --name-only 90ca5935..HEAD` | **vazio** — nenhum arquivo deletado |
| 10 | `git status --short \| grep '^??' \| grep -E 'tests/\|resources/'` | só `tests/Feature/CompanyPortfolioAccessTest.php`, que **não é meu** |

`npm run build` **não** foi rodado: é do plano 177-09, e nenhuma página consome o header ainda.

⚠️ **Não vi tela renderizada.** O que foi verificado é HTML de `react-dom/server` e classe por
classe contra as medidas do protótipo. **A conferência visual é do usuário**, inclusive: o traço
novo dos ícones (a conta é aritmética, mas "ficou bom" é olho), o `oklch` dos tiles de empresa, o
posicionamento real dos dois `Popover` do Radix dentro do `<main>` com `overflow-y-auto`, e o
comportamento do `autoFocus` do campo de busca ao abrir o dropdown.

## Para o próximo plano (177-07/08 — a troca das 9 páginas)

- O header já está **completo** menos o modal da Fase 178. Ao trocar cada página, passe
  `cabecalho` inteiro: o seletor e a pill se desenham sozinhos a partir dele.
- ⚠️ **`cabecalho` nulo é caso normal** em 3 páginas: o seletor vira "Nenhuma empresa aberta"
  (`aria-disabled`) e a pill **não é renderizada**. Nada a fazer na página.
- ⚠️ `BarraDaConta` e `SeletorEmpresaBusca` **continuam no ar e intactos** — `destinoParaItem` é
  importado de lá. Quando as páginas trocarem, confira se ainda há quem use `BarraDaConta` antes de
  pensar em removê-la: `textoSeguro` e `textoUtil` vivem nela e são importados pelo header, pelas
  abas, pelo seletor e pela pill.
- As contagens do gate do 177-04 agora são: `h-[34px]` **8**, `rounded-pub-control` **8**,
  `font-pub-mono` **4**, `text-[10.5px]` **4**, `font-pub[\s"]` **1**, `gap-[2px]` **2**. Quando uma
  página aplicar `font-pub` no corpo, o número do header **não** muda (o gate renderiza o header
  isolado).
- ⚠️ O gate do 177-04 agora precisa de `usePage` no stub do `@inertiajs/react` — quem criar gate
  novo que monte o `PublicadorHeader` tem de exportar `usePage` no stub, senão o esbuild falha com
  "No matching export".
- Se alguma página for exercitar o dropdown ABERTO, não tente abrir o `Popover`: renderize
  `PainelDeEmpresas` / `PainelDeConexoes` direto (o renderizador de servidor não suporta portal).

## Self-Check: PASSED

- `resources/js/Components/Mlb/Publicador/header/SeletorDeEmpresaDoHeader.jsx` — FOUND (419 linhas, ≥120 exigidas)
- `resources/js/Components/Mlb/Publicador/header/ConexaoDoHeader.jsx` — FOUND (429 linhas, ≥90 exigidas)
- `resources/js/Components/Mlb/Publicador/recentesDoPublicador.js` — FOUND (82 linhas; exporta `MAX_RECENTES`, `chaveRecentes`, `lerRecentes`, `gravarRecente`)
- `tests/js/publicador-header-conta-render.test.js` — FOUND (1241 linhas, 109 testes)
- Commits `9dbed9d8`, `cae0c9c7`, `d2ff1255` — FOUND em `git log`
- `key_links` do plano conferidos: `destinoParaItem` importado de `SeletorEmpresaBusca.jsx` ✓ · `router.post(route('mlb.anuncios.meus.atualizar'` em `ConexaoDoHeader.jsx` ✓ · `AnunciosEmpresas.jsx` importa `recentesDoPublicador` ✓
