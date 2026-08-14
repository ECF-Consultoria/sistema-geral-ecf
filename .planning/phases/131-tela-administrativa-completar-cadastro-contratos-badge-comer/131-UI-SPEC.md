---
phase: 131
slug: tela-administrativa-completar-cadastro-contratos-badge-comer
status: approved
shadcn_initialized: false
preset: none
created: 2026-08-14
---

# Phase 131 — UI Design Contract

> Contrato visual e de interação da tela administrativa de contratos (completar cadastro + ações
> Clicksign + badge no Comercial + permissão `admin.contratos`). Gerado por gsd-ui-researcher,
> verificado por gsd-ui-checker.

Requisitos cobertos: ADM-01, ADM-02, ADM-03, UI-01, UI-02, UI-03, UI-04, UI-05, UI-06, CLICK-07,
CLICK-09, CLICK-10.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none — não há `components.json` (CLI do shadcn nunca foi rodada neste projeto) |
| Preset | não aplicável — o projeto já tem um design system "shadcn manual" maduro: primitivas Radix embrulhadas à mão em `resources/js/Components/ui/` (Table, Card, Badge, Dialog, Button, Input, Label), variantes via `class-variance-authority`, composição via `cn()` (clsx + tailwind-merge) |
| Component library | Radix UI, via `resources/js/Components/ui/` — reusar `Table`, `Card`/`CardContent`, `Badge`, `Dialog`/`DialogContent`/`DialogHeader`/`DialogTitle`/`DialogFooter`, `Button`, `Input`, `Label` |
| Icon library | `lucide-react` |
| Font | `font-sans` = Inter (corpo) · `font-display` = Manrope (títulos, `tailwind.config.js`) |

Esta fase **não** cria nenhum componente de design system novo. Toda peça visual é composição das
primitivas já existentes — o trabalho novo é layout, cópia e estados.

---

## Spacing Scale

Confirma a escala de 8 pontos já em uso no projeto (múltiplos de 4). Sem exceção nesta fase.

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | Gap entre ícone e texto (badges, botões pequenos) |
| sm | 8px | `gap-2`/`py-2` — espaçamento compacto entre elementos |
| sm+ | 12px | `px-3`/`gap-3`/`py-3` — padding de célula de tabela, cards de resumo (valor real dominante no projeto) |
| md | 16px | `px-4`/`gap-4` — padding de card, espaçamento padrão entre blocos |
| lg | 24px | `p-6` — padding de página (`<main className="p-6">`, padrão de todo `Admin/*.jsx`) |
| xl | 32px | Gaps entre seções grandes da tela de detalhe |
| 2xl | 48px | Quebras maiores (pouco usadas fora de telas de onboarding) |

Exceções: nenhuma.

---

## Typography

Projeto usa tamanhos arbitrários (`text-[Npx]`) e vários pesos; esta fase **fixa** 4 tamanhos e
2 pesos para tudo que for construído aqui — não introduzir um terceiro peso.

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Meta/Badge | 12px | 400 regular | 1.4 |
| Body | 13px | 400 regular | 1.5 |
| Heading (seção/card) | 15px | 600 semibold | 1.3 |
| Page title (H1) | 20px (`text-xl`) | 600 semibold | 1.2 |

> **Por que 12px e 13px ficam a só 1px de distância** (FLAG do checker, resolvida): a separação
> entre Meta/Badge e Body **não é feita por tamanho** — é feita por cor e por forma. Badge de
> estado sempre carrega cor semântica própria (o mapa de 7 cores) e fundo, e meta sempre vem em
> tom apagado (`text-white/40`). Afastar para 11px prejudicaria a legibilidade do badge, que é o
> elemento que o Administrativo lê primeiro para triar a lista. A escala real que o olho percebe
> é 12/13 → 15 → 20, e os dois primeiros nunca competem pelo mesmo papel na mesma linha.

Uso de peso: **400 regular** para corpo de texto, células de tabela, descrições, placeholders.
**600 semibold** para títulos, rótulos de botão, badges de estado, cabeçalhos de tabela
(`uppercase tracking-wide`). Não usar `font-medium` (500) em componentes novos desta fase — o
projeto tem essa variação em código legado, mas esta fase se restringe a 2 pesos.

---

## Color

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `ecf-bg` `#050507` | Fundo da página |
| Secondary (30%) | `ecf-card` `#0f1116` / `white/[0.02–0.03]` | Cards, linhas de tabela, painéis |
| Accent (10%) | `ecf-yellow` `#ffe600` | Ver lista abaixo — nunca em texto de corpo |
| Destructive | `red-500`/`red-400` (`bg-red-500/10 text-red-400 border-red-500/20`) | Ver lista abaixo |

**Accent (`ecf-yellow`) reservado para:**
- Botão primário "Gerar contrato" quando habilitado
- Card/chip de situação selecionado no resumo-filtro (borda + fundo `ecf-yellow/[0.06]`)
- Foco de input (`focus:border-ecf-yellow/40`)
- Ícone do cabeçalho da página (`Building2`/`FileSignature` ao lado do H1)
- Item de menu ativo (já é padrão do `AppLayout`, não recriar)

**Destructive (vermelho) reservado para:**
- Botão "Cancelar contrato" e seu modal de confirmação
- Badge dos estados `recusado` (D-04) e mensagens de erro de validação de formulário
- Faixa de destaque antes de confirmar liberação manual em causa problemática (D-11 — herdada da
  Fase 130, ver `Ações do contrato` abaixo)

**Mapa de cor por estado do contrato** (não é "segunda cor semântica" única — o projeto já usa
paleta multi-hue para listas de badges, ver `Comercial/EmpresasListagem.jsx`
`PENDENCIAS_CLS`/`SETOR_CLS`). Esta fase fixa as 7 cores abaixo; não inventar variação:

| Estado (enum `ContratoAssinatura::STATUS_*`) | Cor |
|---|---|
| `rascunho` | slate — `bg-white/[0.05] text-white/50 border-white/10` |
| `aguardando_assinaturas` | âmbar — `bg-amber-500/10 text-amber-300 border-amber-500/20` |
| `assinado` | esmeralda — `bg-emerald-500/10 text-emerald-400 border-emerald-500/20` |
| `recusado` | vermelho — `bg-red-500/10 text-red-400 border-red-500/20` |
| `expirado` | laranja — `bg-orange-500/10 text-orange-400 border-orange-500/20` |
| `cancelado` | mute — `bg-white/[0.04] text-white/35 border-white/10` |
| `erro` | rosa — `bg-rose-500/10 text-rose-400 border-rose-500/20` |

---

## Telas desta fase

D-01 trava **duas telas separadas** (não painel lateral, não edição inline na linha):

| Tela | Componente proposto | Rota proposta | Papel |
|------|---|---|---|
| Lista de contratos | `Admin/Contratos.jsx` | `admin.contratos` (index) | UI-01 — filtro por situação, busca, resumo de 7 contagens |
| Detalhe da empresa | `Admin/ContratoDetalhe.jsx` | `admin.contratos.show` (param: `company`) | ADM-01/02, UI-02/04, CLICK-07/09/10 |

Nomes de rota/arquivo são proposta — o planejamento pode ajustar, mas a divisão em duas telas é
contrato travado (D-01), não sugestão.

### Ponto focal de cada tela (FLAG do checker, resolvida)

Sem âncora declarada, o executor distribui peso visual por conta própria e as duas telas ficam
sem hierarquia. Fica travado:

| Tela | Ponto focal | Por quê |
|---|---|---|
| **Lista de contratos** | O **grid de resumo por situação** no topo, com a contagem em tamanho maior que o rótulo | É o que responde "onde as coisas estão paradas" antes de o Administrativo ler uma única linha. Também é o filtro (clique), então concentrar atenção nele é funcional, não decorativo. |
| **Detalhe da empresa — COM pendência** | O bloco **"Falta preencher para gerar" + botão desabilitado**, adjacentes | A D-03 exige que o caminho para destravar seja explícito; se a lista de pendências não for a primeira coisa lida, o botão cinza vira mistério em vez de instrução. |
| **Detalhe da empresa — SEM pendência** | O botão **"Gerar contrato"** ativo, em `ecf-yellow` | É a única ação primária da tela nesse estado; o amarelo de marca aparece aqui e não compete com nada. |

Regra derivada: **em nenhuma das duas telas o `ecf-yellow` aparece em mais de um elemento ao mesmo
tempo.** Dois amarelos na mesma tela destroem o ponto focal — e a lista de 5 usos fechados da
seção Color já pressupõe isso.

**Densidade e ordenação (Claude's Discretion do CONTEXT.md — resolvidas aqui, sem pergunta ao
usuário porque o CONTEXT já delega explicitamente):**

- **Densidade:** tabela compacta (linhas `text-[13px]`, badges `text-[10-11px]`), padrão análogo ao de
  `Comercial/EmpresasListagem.jsx` — Gestão sozinha já são 149 empresas, a lista precisa ser
  escaneável, não uma lista de cards larga.
- **Resumo por situação:** 7 cards/chips clicáveis no topo da lista, **análogo** ao grid de
  pendências de `EmpresasListagem.jsx`, **adaptado a 7 colunas**
  (`grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-2`).
  ⚠️ Não é o "mesmo" grid: `EmpresasListagem.jsx` usa `xl:grid-cols-8`. A divergência é correta —
  lá são 8 tipos de pendência, aqui são os 7 estados da D-04. Copiar o número de colunas de lá
  deixaria uma coluna vazia.
  Cada card mostra contagem + rótulo (D-04) e **funciona como filtro de situação ao mesmo tempo**
  (clique ativa/desativa o filtro) — evita duplicar resumo e filtro como dois controles distintos.
- **Ordenação padrão:** "mais tempo parado primeiro" — `desc` por dias parados (mesma base de
  `ContratosPresosService::dataBase()`/`diasParado()`, não `updated_at`). Justificativa: é uma
  fila de trabalho: quem está esperando há mais tempo deve aparecer primeiro para triagem, e a
  Fase 130 já resolveu esse cálculo — reusar, não recalcular.
- **Layout do detalhe:** página cheia (não painel lateral). D-01 rejeitou explicitamente edição
  inline por espremer o formulário; página cheia cabe: formulário de completar cadastro + painel
  de ações do contrato + (opcional) histórico de reemissões.

---

## Copywriting Contract

### 7 rótulos de estado (D-04 — redação final desta fase)

| Estado (enum) | Rótulo do badge | Ícone (`lucide-react`) |
|---|---|---|
| `rascunho` | **Não enviado** | `FileEdit` |
| `aguardando_assinaturas` | **Esperando assinatura** | `Send` |
| `assinado` | **Assinado** | `CheckCircle2` |
| `recusado` | **Cliente recusou** | `XCircle` |
| `expirado` | **Prazo venceu** | `TimerOff` |
| `cancelado` | **Cancelado** | `Ban` |
| `erro` | **Falha no envio** | `AlertTriangle` |

Estado adicional **só para exibição** (não é valor do enum — empresa que ainda não tem nenhum
`ContratoAssinatura` criado, i.e. ainda não passou por "Gerar contrato"):

| Situação | Rótulo |
|---|---|
| Sem contrato gerado ainda | **Aguardando Administrativo** |

### Badge do Comercial (D-08 — "situação + há quanto tempo", sem link)

Formato: `{Rótulo do estado} há {N} dia(s)` — atenção ao singular/plural em pt-BR.

- `N == 0` → **"{Rótulo} há menos de 1 dia"**
- `N == 1` → **"{Rótulo} há 1 dia"**
- `N >= 2` → **"{Rótulo} há N dias"**
- Estado "Aguardando Administrativo" → mesmo formato, usando `company.created_at` como base (não
  existe carimbo próprio ainda; simplificação aceitável, não introduzir coluna nova só para isto)
- Empresa de serviço **Polos** (D9 — isenta de contrato) → **não mostra badge**; célula com `—`
  cinza e `title="Este serviço não passa por contrato"` — nunca "Aguardando Administrativo"
  (viraria fila fantasma que nunca esvazia)
- Sem link em nenhum caso — o elemento não é clicável, não tem `<a>`/`<Link>`, sem hover de
  navegação (Comercial não tem `admin.contratos`; clique daria 403)

### Ações do contrato — CTA por estado

A tela **não recalcula elegibilidade no cliente**. O backend informa via prop se
`pode_gerar_contrato` é `true`/`false` e a lista de `faltantes()` (D-03) — a UI só renderiza.

| Ação | Rótulo do botão | Quando aparece |
|---|---|---|
| Gerar contrato | **"Gerar contrato"** | Sempre visível; **desabilitado** quando `pode_gerar_contrato === false`, com a lista de pendências (D-03) ao lado |
| Reenviar convite | **"Reenviar aviso"** | Por pessoa não assinada, só em `aguardando_assinaturas` (CLICK-07) |
| Ajustar quem assina | **"Ajustar"** | Por pessoa não assinada, só em `aguardando_assinaturas` (abre o fluxo D-06/D-07 abaixo) |
| Registrar cancelamento | **"Registrar cancelamento"** | Em `rascunho` e `aguardando_assinaturas` (CLICK-10). ⚠️ Rotulado como **registrar**, não "cancelar" — o sistema não cancela (medido 2026-08-14, §15.2 do empírico). Prometer "Cancelar contrato" num botão que não cancela é exatamente o tipo de mentira que a UI-06 existe para impedir |
| Liberar manualmente | **"Liberar manualmente"** | Sempre disponível quando não há liberação ainda — ação absorvida da Fase 130 (D-10) |
| Tentar novamente | **"Tentar novamente"** | Só em `erro` (D-05) |

Pendência ao lado do botão desabilitado (D-03), lista vinda de
`ContratoDadosMinimosService::faltantes()`:

> **Título:** "Falta completar antes de gerar o contrato"
> **Cada item:** usa `rotulo` vindo do backend, ex.: "CNPJ", "E-mail do cliente",
> "Data de início do contrato — Gestão"

### Estado `erro` (D-05 — assume a falha e oferece saída)

**Primeira falha:**
- Título: **"Não deu para enviar este contrato"**
- Corpo: **"O problema foi aqui do nosso lado, não com o cliente. Tente novamente — na maioria das vezes resolve."**
- CTA: **"Tentar novamente"**

**Falha após tentar de novo** (segunda falha consecutiva):
- Título: **"Continua sem enviar"**
- Corpo: **"Tentamos de novo e não deu certo. Avise o time técnico para verificar — pode levar um tempinho para resolver."**
- CTA secundário (expansível, não obrigatório de abrir): **"Ver detalhes técnicos"** — mostra a
  mensagem crua da exceção só para quem for investigar; nunca aparece por padrão aberto

### D-06 / D-07 — corrigir e-mail vs. trocar quem assina (dois ramos, gate #8 aberto)

Gatilho: botão **"Ajustar"** na linha da pessoa não assinada, só em `aguardando_assinaturas`.

Nunca usar as palavras "envelope", "webhook", "signatário" ou "Clicksign" em texto visível ao
usuário — usar "pessoa que assina"/"quem assina" (mesma linguagem de D-07).

> ## ⛔ ATUALIZADO EM 2026-08-14 — O GATE #8 FOI MEDIDO
>
> Este bloco foi reescrito **depois** da pesquisa da fase. O RAMO A foi **REMOVIDO**: ele descrevia
> uma tela que a API não permite construir. Medição registrada em
> `CLICKSIGN-SANDBOX-EMPIRICO.md` §15.1 — `PATCH` e `PUT` em
> `/envelopes/{id}/signers/{signerId}` devolvem **404** (HTML genérico de rota inexistente, não o
> 404 JSON:API). **Não existe endpoint de correção de e-mail na v3.**

**RAMO B — é o que vale (D-06 / D-14). O RAMO A não é construído.**

Não existe escolha entre duas opções — corrigir o e-mail e trocar a pessoa **colapsam no mesmo
caminho**. O botão "Ajustar" abre direto:

- Título: **"Não dá para só corrigir o e-mail"**
- Corpo: **"Depois que o contrato é enviado, não é possível trocar o e-mail de quem assina. Se foi só um erro de digitação, cancele este contrato e gere um novo com o e-mail certo."**
- CTA primário: **"Cancelar contrato"** (leva ao fluxo do CLICK-10 abaixo)
- CTA secundário: **"Voltar"**

### Cancelar contrato (CLICK-10) — registra aqui, cancela no painel (D-13)

> ## ⚠️ ATUALIZADO EM 2026-08-14 — CANCELAR NÃO É POSSÍVEL PELA API
>
> Medição em `CLICKSIGN-SANDBOX-EMPIRICO.md` §15.2: `DELETE` → **403** em `running` (funciona só em
> `draft`); `POST /cancel` → **404**; `PATCH status:"canceled"` → **400** com a mensagem literal da
> API: *"status deve estar em: draft, running"*. **Cancelar é operação de PAINEL**, igual assinar —
> o sistema não cancela, ele fica sabendo pelo webhook `cancel`.
>
> O modal abaixo **não executa o cancelamento**. Ele registra a intenção (autor + motivo + data) e
> instrui a concluir no painel. É o que preserva o valor real do CLICK-10 — *"informando o motivo"*.

Modal:
- Título: **"Registrar cancelamento deste contrato"**
- Corpo: **"O cancelamento em si precisa ser feito no painel da Clicksign — o sistema não consegue cancelar sozinho. Aqui você registra quem pediu e por quê, para ficar no histórico."**
- Campo: **"Motivo do cancelamento"** — textarea obrigatória, mínimo 10 caracteres, placeholder:
  **"Explique por que este contrato está sendo cancelado."**
- CTA confirmar (destructive/vermelho): **"Registrar e ir para a Clicksign"**
- CTA voltar: **"Voltar"**
- Sucesso: toast **"Cancelamento registrado. Agora conclua no painel da Clicksign."**

**Estado intermediário novo — "Cancelamento solicitado":**
Depois de registrar, a linha do contrato mostra um aviso persistente até o webhook confirmar:
- Texto: **"Cancelamento pedido por {nome} em {data} — ainda não concluído no painel da Clicksign."**
- Estilo: âmbar (mesmo tratamento de "aguardando algo externo", **não** vermelho de erro)
- Quando o webhook `cancel` chegar, o estado vira "Cancelado" (D-04) e o aviso some.

Motivo é texto livre (não há lista fechada de categorias de cancelamento no backend hoje — ao
contrário da liberação manual, que já tem `MOTIVOS_MANUAIS`). Se o planejamento decidir criar uma
lista fechada depois, é aditivo, não bloqueia esta fase.

⚠️ **Para o planejamento:** este fluxo provavelmente exige coluna(s) nova(s) para
motivo/autor/data do cancelamento solicitado — não existem hoje. Respeitar as armadilhas de
migration do projeto (índice nomeado à mão < 64 chars, `string()` nunca `enum()`, FK
`nullOnDelete()` exige `nullable()`).

### Liberar manualmente (D-10 — absorve a Fase 130, preserva D-11)

Reusa **literalmente** o texto já validado em `Admin/ContratosLiberacaoManual.jsx` — não reescrever:

- Motivo (select, lista fechada `ContratoLiberacao::MOTIVOS_MANUAIS_LABELS`):
  "O aviso automático da Clicksign não chegou" · "O cliente assinou fora do sistema" ·
  "Decisão comercial" · "Outro motivo"
- Detalhe (textarea obrigatória, mínimo 5 caracteres): placeholder **"Explique o que aconteceu, mesmo que seja um resumo curto."**
- CTA: **"Confirmar liberação"**

**Faixa de destaque vermelha ANTES de confirmar (D-11, preservar exatamente)** — aparece quando a
causa (`ContratosPresosService::causa()`) é `recusado_pelo_cliente`, `prazo_expirado`,
`cancelado` ou `erro_tecnico`:

| Causa | Texto da faixa |
|---|---|
| `recusado_pelo_cliente` | "Este contrato foi RECUSADO pelo cliente. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo." |
| `prazo_expirado` | "O PRAZO deste contrato EXPIROU sem todas as assinaturas. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo." |
| `cancelado` | "Este contrato foi CANCELADO. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo." |
| `erro_tecnico` | "Houve um ERRO TÉCNICO na integração com a Clicksign — ninguém recusou nada. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo." |

### Reenviar aviso — 429 (CLICK-07, resposta ESPERADA, não erro)

A Clicksign devolve 429 em **texto puro** para este endpoint especificamente — tratar como
resposta esperada, nunca como falha do sistema:

- Estilo: **neutro/âmbar**, não vermelho — não é um erro
- Texto: **"Aguarde um pouco antes de reenviar"** · corpo: **"Você reenviou recentemente. Espere alguns minutos e tente de novo — isso evita marcar o convite como spam."**
- Botão "Reenviar aviso" fica temporariamente desabilitado após o clique (evita repetir o 429 em
  sequência); não há contagem regressiva exata exigida — um `disabled` por alguns segundos com o
  texto acima é suficiente

### Primary CTA

| Element | Copy |
|---------|------|
| Primary CTA (tela de detalhe) | **"Gerar contrato"** |

### Estados vazios / carregando / erro genérico

| Elemento | Copy |
|---|---|
| Lista sem nenhum contrato (heading) | **"Nenhum contrato encontrado"** |
| Lista sem nenhum contrato (corpo) | "Ainda não há contratos administrativos registrados. Eles aparecem aqui assim que uma empresa completa o cadastro e o contrato é gerado." |
| Busca sem resultado | "Nenhuma empresa encontrada para \"{busca}\". Revise o termo buscado ou limpe o filtro de situação." |
| Carregando lista/detalhe | Spinner sem texto. ⚠️ **Correção factual (checker, 2026-08-14):** NENHUMA tela em `resources/js/Pages/Admin/*.jsx` usa skeleton hoje — o padrão existe em `Dev/`, `Mlb/`, `Performance/` e `Polos/`. O executor parte de spinner, não de skeleton reaproveitado. Se quiser skeleton, é trabalho novo: portar o padrão de uma dessas pastas. |
| Erro ao carregar (500/rede) | Título: **"Não deu para carregar"** · corpo: **"Tente atualizar a página. Se continuar, avise o time técnico."** · CTA: **"Tentar de novo"** |
| Botão em ação (busy) | Texto muda para gerúndio: "Gerando…" / "Cancelando…" / "Liberando…" / "Reenviando…" / "Salvando…" — botão `disabled`, sem novo texto de erro até a resposta voltar |
| Destructive confirmation | **Registrar cancelamento**: ver o modal da seção CLICK-10 — o texto NÃO promete cancelar, porque o sistema não cancela · **Liberar manualmente com causa problemática**: ver faixa de destaque acima |

---

## Regras de exceção (D9 — Polos não tem contrato)

- Empresas cujo único serviço é **Polos** **não aparecem** na lista de contratos (UI-01) — nem
  como pendente, nem com nenhum estado. Filtrar no backend antes de paginar, não esconder no
  client.
- Na listagem do Comercial (UI-03/D-08), empresa Polos mostra `—` na coluna de contrato, nunca um
  rótulo de estado nem "Aguardando Administrativo".
- Empresa com Polos **e** outro serviço que exige contrato (raro, ver `130-CONTEXT.md`/D10 da
  milestone) mostra o contrato do serviço que exige — nunca omite a empresa inteira.

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|--------------|
| shadcn official | não aplicável — projeto não usa o CLI do shadcn | not required |
| Terceiros | nenhum | not required — nenhum registry de terceiros declarado nesta fase |

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
