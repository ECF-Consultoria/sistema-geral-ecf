---
phase: 135
slug: onboarding-geral-por-servico-motor-dirigido-por-template-com
status: draft
shadcn_initialized: false
preset: none — projeto usa componentes hand-rolled estilo shadcn (Radix + cva), sem `components.json`/CLI
created: 2026-08-11
---

# Phase 135 — UI Design Contract

> Contrato visual e de interação para as 3 superfícies novas da Fase 135: **Painel operacional**
> (interno, `/onboarding`), **CRUD de template** (admin) e **Portal do cliente** (público, por
> token). Gerado por `gsd-ui-researcher`, verificado por `gsd-ui-checker`.
>
> **Escopo travado (não reabrir):** v1 cobre só o template de **Gestão (Performance)**, 13 passos,
> 5 automáticos (D-08). O onboarding de Polos (`mlb_implementacoes`, `/mlb/implementacao`,
> `/implementacao/{token}`) é **intocado** (D-02/SC-02) — usado aqui só como referência de forma,
> nunca de reuso de código.
>
> Este documento não inventa um design system novo. O projeto já tem um, maduro e em produção
> (`CLAUDE.md`, seção Tailwind/Design System) — cada token e componente abaixo é citado por
> `arquivo:linha` de onde foi lido.

---

## Design System (existente — catalogado, não recriado)

| Property | Value |
|----------|-------|
| Tool | manual — componentes estilo shadcn (Radix UI + `class-variance-authority`), sem `components.json`/CLI shadcn (`ls components.json` → não encontrado) |
| Component library | Radix UI, embrulhado em `resources/js/Components/ui/`: `avatar.jsx`, `badge.jsx`, `button.jsx`, `card.jsx`, `checkbox.jsx`, `dialog.jsx`, `dropdown-menu.jsx`, `input.jsx`, `label.jsx`, `progress.jsx`, `select.jsx`, `separator.jsx`, `source-badge.jsx`, `table.jsx`, `tabs.jsx`, `textarea.jsx` |
| Icon library | `lucide-react` (usado em toda página nova lida — `Servicos/Index.jsx:10`, `Polos/Painel.jsx:4-10`, `Nps/Configuracao.jsx:4`) |
| Font | `Inter` (`font-sans`, corpo) + `Manrope` (`font-display`, títulos) — `tailwind.config.js:17-19` |
| Utilitário de classes | `cn()` (clsx + tailwind-merge) — `resources/js/lib/utils.js:1-6`, usado em 100% dos componentes lidos |
| Formatação | `formatCurrency`/`formatCurrencyCompact`/`formatPercent`/`formatDate`/`formatDateTime`/`formatTime` — `resources/js/lib/utils.js:8-83` (fuso `America/Sao_Paulo` fixo, linha 57) |

**Nenhum componente novo de UI primitiva precisa ser criado.** As 3 telas desta fase se apoiam
inteiramente no inventário acima + Radix já instalado (`@radix-ui/react-*` no `package.json`).

---

## Spacing Scale

Escala de 4px já em uso em toda a base (`px-2 py-1.5`, `gap-3`, `p-6`, `space-y-4` etc. —
onipresente em `Servicos/Index.jsx`, `Nps/Configuracao.jsx`, `Polos/Painel.jsx`). Sem invenção:

| Token | Value | Uso nesta fase |
|-------|-------|-----------------|
| xs | 4px | gap entre ícone e label em badges/pills (`gap-1`, `gap-1.5` — `StatusBadge.jsx:11`) |
| sm | 8px | padding interno de badges e chips (`px-2 py-0.5` — `badge.jsx:6`) |
| md | 16px | padding de card (`p-4`/`p-6` — `Nps/Configuracao.jsx:66`, `card.jsx:10`) |
| lg | 24px | padding de página/seção (`p-6 space-y-6` — `Nps/Configuracao.jsx:403`) |
| xl | 32px | gap entre coluna do editor e preview lateral (`gap-6` no grid 2 colunas — `Nps/Configuracao.jsx:464`) |
| 2xl | 48px | — (não usado nesta fase) |
| 3xl | 64px | — (não usado nesta fase) |

**Exceções:** nenhuma. O painel operacional (grade densa por empresa) usa `sm`/`md` como o
Painel Polos usa em suas células (`CELL` — `Polos/Painel.jsx:69`, `px-2 py-1.5`), mas isso é a
MESMA escala, não uma exceção.

---

## Typography

### ⚠ Exceção deliberada ao contrato de design: 3 pesos de fonte, não 2

O contrato genérico de UI-SPEC do GSD limita a **2 pesos**. Esta fase **assume
explicitamente a exceção e opera com 3** — `font-normal` (400), `font-semibold` (600) e
`font-bold` (700). Não é herança passiva nem descuido: é uma escolha tomada e assumida
aqui, com dono e motivo.

**Quem decide isto não é esta fase.** O `CLAUDE.md` do projeto lista, entre as
*Constraints* de nível de projeto:

> **Design**: Tailwind com tokens `ecf-*`, dark theme, componente `DevCard` e `cn()` já
> existentes — **manter consistência**

Os 3 pesos já estão **em produção** nas páginas irmãs do mesmo grupo de navegação —
Serviços (`Servicos/Index.jsx`), NPS (`Nps/Configuracao.jsx`) e Polos (`Polos/Painel.jsx`)
— e cada um está citado com `arquivo:linha` na tabela abaixo. Reduzir para 2 **apenas nas
telas desta fase** produziria o pior dos dois mundos: telas novas visivelmente diferentes
das vizinhas, sem corrigir nada no resto do app.

**Como a exceção deixaria de valer:** unificar a tipografia é um trabalho de design system
que atravessa ~40 páginas existentes. Se o projeto decidir fazê-lo, é fase própria e estas
telas vêm junto — não o contrário. Enquanto isso não acontecer, **3 pesos é o padrão
correto aqui**, e a alternativa é que seria a regressão.

**Escopo da exceção:** limitada a peso de fonte. Todas as demais dimensões do contrato
(espaçamento, cor, copy, hierarquia) seguem sem exceção — ver "Exceções: nenhuma" na
seção Spacing e a lista fechada de 5 usos do accent na seção Color.

| Role | Size | Weight | Line Height | Evidência |
|------|------|--------|-------------|-----------|
| Label (badges, pills, meta-dado denso) | 11px (`text-[11px]`) | 600 semibold | 1.2 | `Polos/components/StatusBadge.jsx:11` |
| Body (texto corrido, células de tabela, inputs) | 13px (`text-[13px]`) | 400 regular | 1.5 | `Mlb/ImplementacaoPublica.jsx:566` (Select trigger), `Polos/Painel.jsx:70` (`CELL_TXT`) |
| Heading (título de card/seção) | 18px (`text-lg`), `font-display` | 700 bold | 1.2 | `Mlb/ImplementacaoPublica.jsx:83` (`text-white font-display font-bold text-lg`) |
| Display (título de página) | 24px (`text-2xl`), `font-display` | 700 bold | 1.2 | `Nps/Configuracao.jsx:409` (`font-display font-bold text-2xl tracking-tight`) |

Peso intermediário (600) usado especificamente em badges de status/dono (ver seção Cor) e em
subtítulos de bloco (`text-[11px] font-semibold uppercase tracking-wider` — padrão visto em
`Nps/Configuracao.jsx:438,506`).

---

## Color

Nenhuma cor nova é introduzida. Tudo abaixo já existe em `tailwind.config.js:65-75` ou é
convenção de opacidade já usada em produção.

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#050507` (`ecf-bg`) | Fundo de página — `tailwind.config.js:69` |
| Secondary (30%) | `#0f1116` (`ecf-card`) / `#14161d` (`ecf-card-2`) | Cards, header sticky do portal, painéis — `tailwind.config.js:70-71` |
| Accent (10%) | `#ffe600` (`ecf-yellow`) | Ver lista fechada abaixo — `tailwind.config.js:67` |
| Destructive | `red-400`/`red-500`/`rose-300` (Tailwind stock, já usado assim no projeto) | Ações destrutivas — `Servicos/Index.jsx:159` (`text-red-400 hover:text-red-300`) |

**Accent (`ecf-yellow`) reservado exatamente para:**
1. Botão de CTA primário de cada tela (ex.: "Publicar versão", "Confirmar responsável") — mesmo padrão de `Nps/Configuracao.jsx:96` (`bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90`)
2. Estado marcado/ativo de `Checkbox`/controles Radix (`data-[state=checked]:bg-ecf-yellow` — `checkbox.jsx:18`)
3. Selo "resolvido automaticamente pelo sistema" no passo (dono=sistema, ver Screen 1) — distintivo, não decorativo
4. Aba/opção selecionada e anel de foco (`focus:ring-ecf-yellow/30` — `Nps/Configuracao.jsx:87`)
5. Ícone de destaque em cabeçalho de widget (`text-ecf-yellow` em círculo — `Nps/Configuracao.jsx:69`)

**Nunca usar accent para:** texto de corpo, fundo de card padrão, ou qualquer elemento que não seja um destes 5.

**Paleta semântica de status do onboarding** (nova combinação de domínio, mas com cores
já-estabelecidas no projeto — não são hex novos):

| Estado do passo | Cor | Precedente |
|---|---|---|
| Bloqueado por dependência | `text-white/30 bg-white/[0.04]`, borda tracejada | `estagioBadge.js:6` (`'Não Listado'`) |
| Aberto e parado — dentro do SLA | `text-amber-300 bg-amber-500/10 border-amber-500/20` | `Polos/Painel.jsx:55` (`STATUS_ENVIO_BADGE.enviado`) |
| Aberto e parado — vencido (fora do SLA) | `text-red-300 bg-red-500/10 border-red-500/20` (ou `rose-300`) | `Polos/Painel.jsx:54` (`STATUS_ENVIO_BADGE.falta_enviar`) |
| **Aguardando coleta** (resolver disparado, job assíncrono ainda não voltou) | `text-sky-300 bg-sky-500/10 border-sky-500/20` | Cor nova NESTA combinação — reaproveita `sky` já usado como tom de fase inicial em `Polos/Painel.jsx:76` (`M1: 'text-sky-300'`), nunca confundir com amber/red |
| Concluído — automático (sistema) | `text-emerald-300 bg-emerald-500/10 border-emerald-500/20` + selo accent (ver item 3 acima) | `Polos/Painel.jsx:56` (`STATUS_ENVIO_BADGE.concluido`) |
| Concluído — manual (cliente/interno) | `text-emerald-300 bg-emerald-500/10 border-emerald-500/20`, sem selo accent | idem, sem o distintivo de automação |
| Indeterminado (sonda 429/timeout — D-18/D-11) | `text-amber-400 bg-amber-500/10`, ícone `AlertTriangle` | Não tem precedente direto; usa o mesmo tom "atenção" de `Polos/Painel.jsx:55` mas com ícone distinto para não ser lido como "aberto e parado" |

---

## Copywriting Contract

| Element | Copy |
|---------|------|
| Primary CTA — Painel operacional | **"Confirmar responsável"** (transição rascunho→andamento, SC-04) |
| Primary CTA — CRUD de template | **"Publicar versão"** (nunca "Salvar" genérico — precisa comunicar o versionamento de D-07) |
| Primary CTA — Portal do cliente (passo manual) | **"Marcar como feito"** |
| Primary CTA — Portal do cliente (passo auto, ex. OAuth) | **"Autorizar acesso"** (sem checkbox — ver Screen 3) |
| Empty state — Painel (nenhum onboarding ainda) | Heading: "Nenhum onboarding em andamento" · Body: "Onboardings nascem automaticamente quando um contrato de serviço é criado. Assim que o primeiro chegar, ele aparece aqui." |
| Empty state — CRUD (serviço sem template publicado) | Heading: "Gestão ainda não tem template publicado" · Body: "Monte os passos do checklist e clique em Publicar versão para ativar o onboarding automático deste serviço." |
| Empty state — Portal (nada pendente do cliente) | Heading: "Tudo certo por aqui!" · Body: "Nossa equipe foi notificada e vai continuar com as próximas etapas do seu onboarding." |
| Error state — Painel (resolver indeterminado) | "Não foi possível confirmar agora — vamos tentar de novo automaticamente." (nunca "Erro". Nunca soa como falha do cliente/interno) |
| Error state — CRUD (ciclo de dependência) | Ver seção "Guarda de ciclo" no Screen 2 — texto exato especificado lá |
| Error state — Portal (token não encontrado) | Heading: "Link inválido" · Body: "Este link de onboarding não foi encontrado. Verifique se copiou o endereço completo ou entre em contato com a ECF Consultoria." (tom de `Nps/Expired.jsx:8-9`) |
| Destructive — Publicar versão com onboardings vivos | "Publicar nova versão do template": "Onboardings em andamento continuam na versão atual; só onboardings NOVOS usam esta versão. Para migrar os existentes, use a ação 'Migrar' na lista de onboardings pendentes." |
| Destructive — Migrar onboarding para versão nova | "Migrar {N} onboarding(s) para a versão {N+1}?": "Passos já concluídos permanecem como estão. Passos novos do template (se houver) nascem pendentes." |
| Destructive — Remover passo do template (em edição, ainda não publicado) | Sem modal de confirmação — remoção é reversível até o "Publicar" (mesmo espírito de `CustIdCell.jsx:88-96`, onde esvaziar e cancelar não grava nada) |

---

## Registry Safety

**Não aplicável.** Projeto não usa shadcn CLI (`components.json` ausente) — componentes são
hand-rolled sobre Radix UI diretamente. Nenhum registry de terceiros foi declarado ou é
necessário para esta fase; nenhum bloco novo de UI é importado de fora do repositório.

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| — | nenhum | não aplicável |

---

## Tela 1 — Painel operacional de onboarding (interno)

**Rota proposta:** `/onboarding` (discricionário — nav em grupo "Comercial", ícone `ListChecks`
como o item "Onboarding" de Polos — `AppLayout.jsx:115` — mas permission NOVA, ver "Gate de
acesso" abaixo).

### O que esta tela recusa fazer

Critério de sucesso 11 é uma rejeição explícita do padrão `feitos/total` do Polos
(`MlbImplementacao::infoPrazo()`, `MlbImplementacao.php:586-605` — prazo fixo de 5 dias,
`pct === 100` como único critério de "concluído"). **Nenhuma barra de progresso (`Progress` —
`Components/ui/progress.jsx:5-13`) é o elemento central de nenhuma visão desta tela.** `Progress`
não é proibido como componente (pode aparecer como detalhe secundário, ex. dentro de um tooltip),
mas nunca como a resposta visual principal a "como está o onboarding".

### Gap de dado que esta tela expõe (para o planner)

O esquema proposto no `135-CONTEXT.md`/`135-RESEARCH.md` para `onboarding_passos` é
`status, valor(json), feito_por, feito_em, auto_em` — **não existe campo de "ficou acionável
em"** (quando as dependências foram satisfeitas E o onboarding saiu de rascunho). Sem esse
timestamp, a pergunta central desta tela — **"há quantos dias"** — não tem resposta correta para
nenhum passo que dependa de outro: o SLA por passo (`sla_dias`) só faz sentido contado a partir
do momento em que o passo *abriu*, não da criação do onboarding. **Esta tela exige um campo
adicional** (proposta de nome: `onboarding_passos.disponivel_em`) preenchido quando: (a) o
onboarding sai de `rascunho` para `andamento`, para passos sem dependência; ou (b) todas as
`depende_de` daquele passo mudam para `concluido`, para passos dependentes. Sem isso, o
critério de sucesso 11 não pode ser cumprido com precisão — é uma lacuna estrutural, não um
detalhe visual, e deve ir para o `PLAN.md`.

### Estrutura da tela

**Nível 1 — lista agrupada por empresa** (D-01 exige agrupamento por empresa; v1 só tem o
serviço Gestão, mas a lista já nasce genérica porque D-06/D-10 preveem mais serviços por
empresa no futuro).

Cada linha/card de empresa mostra, sem porcentagem:
- Nome da empresa + badge do(s) serviço(s) com onboarding ativo (v1: sempre "Gestão")
- **Situação agregada** (chip único, vocabulário próprio desta fase — inspirado na FORMA do
  `situacaoDe()`/`SITUACAO_LABEL` de `Polos/Painel.jsx:107-126`, mas com rótulos e regras
  novas, não copiados):
  - `Rascunho — aguardando responsável` (neutro/tracejado) — `onboardings.status = rascunho`
  - `Vencido` (vermelho) — ao menos 1 passo aberto-e-parado fora do SLA
  - `Aguardando {dono}` (âmbar) — ao menos 1 passo aberto-e-parado dentro do SLA, nenhum vencido
  - `Coletando dados` (sky) — só passos em "aguardando coleta" pendentes
  - `Pronto para concluir` (esmeralda com aviso) — todos os passos operacionais concluídos, falta só passo administrativo (ex. pagamento, D-15) — ver nota abaixo
  - `Concluído` (esmeralda) — `onboardings.status = concluido`
- **O passo que mais trava**: o passo aberto-e-parado com maior `dias_decorridos` (não o mais
  antigo por ordem de criação — o mais velho SEM avançar), com dono e dias
- Responsável do onboarding (avatar + nome — `Components/ui/avatar.jsx:5-18`) ou, se `rascunho`
  sem vínculo, "Sem responsável" + CTA "Confirmar responsável"

**Nível 2 — detalhe do onboarding** (drawer lateral ou página própria — decisão do planner;
funcionalmente equivalente, este contrato não exige uma sobre a outra). Lista completa dos 13
passos do template de Gestão, cada um como uma linha/card com:

| Campo visual | Conteúdo |
|---|---|
| Título do passo | `template_passos.titulo` |
| Badge de **dono** | `cliente` (sky-300) · `interno` (violet-300, + `setor_id` se houver, ex. "interno · financeiro") · `sistema` (ecf-yellow/70) — eixo independente de automação (D-19) |
| Selo de **automação** (separado do dono) | Ícone `Zap` accent SÓ quando o passo tem `auto_fonte` preenchido — aparece mesmo em passos `dono=cliente` (ex. passo 5: cliente autoriza, sistema fecha sozinho). **Obrigatório:** `aria-label="Passo verificado automaticamente pelo sistema"` + `title` com o mesmo texto — é o único indicador de status sem rótulo textual ao lado, então precisa do rótulo acessível |
| Estado | Um dos 7 da paleta semântica (seção Color) — nunca um número solto tipo "0/1" |
| Dias | "há X dias" contado desde `disponivel_em` (ver gap acima); passos bloqueados NÃO mostram contador de dias (não é uma pendência ainda) |
| SLA | "{sla_dias}d" ao lado do contador, só em passos abertos-e-parados |
| Dependências | Se bloqueado: "Aguarda: {título do(s) passo(s) `depende_de`}" com ícone `Lock` |
| Condição (se houver) | Nota discreta "Só se aplica quando: {descrição legível da condição}" — nunca a expressão crua |

**Nota "Pronto para concluir" (D-15):** quando todos os passos não-administrativos estão
concluídos mas a conclusão do onboarding está presa só por causa de "Confirmação de pagamento"
(passo 6, dono interno·financeiro), a tela **não pode mentir dizendo "Concluído"** nem
esconder essa pendência dentro da lista genérica — precisa de um chip de situação próprio
("Pronto para concluir") citado acima, para não confundir "trabalho de mapeamento parado" com
"questão administrativa parada" (são duas categorias diferentes de bloqueio, com dono e
urgência diferentes).

### Terceiro estado — "aguardando coleta" (o mais fácil de errar)

Este é o estado que D-11/Pitfall 3 do `RESEARCH.md` existe para proteger. Regras de
renderização, sem exceção:

1. **Nunca** renderizar um valor numérico definitivo (ex. "0 anúncios inativos") enquanto o
   status do passo é "aguardando coleta" — o valor só aparece quando o status vira `concluido`.
2. Ícone `RefreshCw` com animação sutil (`animate-spin` ou pulse discreto — já existe
   `animate-fade-in`/keyframes customizados em `tailwind.config.js:82-103`, reaproveitável;
   nenhum keyframe novo precisa ser criado além de um simples `animate-spin` do Tailwind core)
3. Texto: "Coletando dados automaticamente…" — sem contagem de dias alarmante (não é uma
   pendência humana), mas com timestamp discreto "iniciado há X min" para transparência
4. **Watchdog visual:** se a coleta estiver "aguardando" por mais tempo do que o esperado (a
   camada barata do `mlb:sync-acervo` tem `timeout=1800` — `SyncMlAcervoCompanyJob.php:41`,
   citado no `135-RESEARCH.md` Achado A.4), a tela precisa de um estado de alerta distinto —
   "Coleta demorando mais que o esperado" (ícone `AlertTriangle`, tom âmbar) — para não deixar
   o operador acreditar silenciosamente que está tudo indo bem quando o job pode ter falhado

### Gate de acesso (recomendação, não travado pelo CONTEXT)

D-04 trava `role:admin` para o CRUD de template — mas o painel operacional (onde a Coordenação
confirma responsável, D-05) não tem mandato explícito. O `135-RESEARCH.md` (Open Question 2)
deixou isso como pendência. **Recomendação desta spec:** seguir o padrão de permission dedicada
já usado no projeto para telas operacionais não-admin (`permission:core.empresas` —
`routes/web.php:605`; `permission:mlb.implementacao` no item de nav de Polos —
`AppLayout.jsx:115`), não `role:admin` puro — coordenação de onboarding provavelmente envolve
consultores/estrategistas, não só admins. Se essa leitura estiver errada, é decisão de
roteamento, não muda nenhum contrato visual desta spec.

---

## Tela 2 — CRUD de template (admin)

**Rota proposta:** `/onboarding/templates` (discricionário), `role:admin` explícito (D-04).
Molde estrutural (forma, não código — D-02 exige originalidade só em relação a Polos, e o NPS
não é Polos): `Nps/Configuracao.jsx` — duas telas por estado (`list`/`edit`), sem sidebar de
templates fixa.

### Modo lista

Como v1 só tem 1 serviço com template (Gestão), a grade é pequena, mas desenhada para escalar:
card por serviço mostrando nome, versão publicada atual, quantos onboardings ativos rodam nela,
data de publicação, botão "Editar template" (abre a versão atual **em modo de edição, que ao salvar cria
N+1** — nunca edita in-place) e "Nova versão" (mesmo botão, rótulo alternativo se preferir).
Serviços sem template publicado mostram o empty state já especificado na Copywriting Contract.

### Modo edição — builder de passos

Cada passo é um card/linha expansível com estes campos:

| Campo | Controle | Regra |
|---|---|---|
| `titulo` | `Input` (`Components/ui/input.jsx`) | obrigatório |
| `chave` | `Input`, auto-sugerido do título (slug), editável | único dentro do template; nasce mesmo sem uso funcional na v1 (D-10) |
| `dono` | Segmented/radio de 3 opções (`cliente` / `interno` / `sistema`) — **nunca** um `Select` solto, são só 3 valores fixos e a escolha errada aqui é a mais cara do formulário | cores da badge conforme paleta de dono acima |
| `setor_id` | `Select` (`Components/ui/select.jsx`), nullable | **usar sentinela `SEM_VALOR`, nunca `<SelectItem value="">`** — ver Armadilha abaixo |
| `depende_de` | Multi-select por chips (não é single-value — passos podem depender de vários, ex. passo 7 depende de 3 E 5) — lista de outros passos do MESMO template, excluindo o próprio passo | ver Guarda de ciclo |
| `sla_dias` | `Input type="number"` | obrigatório se `dono` != `sistema` puro sem cobrança; recomendação: sempre obrigatório, mesmo passos automáticos têm SLA nominal |
| `auto_fonte` | `Select`, nullable, **opções vêm do catálogo fechado registrado em código (D-09)** | ver "Catálogo fechado" abaixo |
| `condicao` | `Select` de condições pré-registradas (mesmo padrão fechado de `auto_fonte` — D-12 não pede motor de regras livre, só "passo nasce se X") | nullable, `SEM_VALOR` por padrão |
| `obrigatorio` | `Checkbox` (`Components/ui/checkbox.jsx`) | — |

### Armadilha registrada — `SelectItem value=""` derruba o render

`<SelectItem value="">` do Radix lança erro em runtime e derruba a tela inteira (tela preta) —
já documentado em memória do projeto e confirmado em código:
`resources/js/Pages/Mlb/OnboardingFicha.jsx:32-37` define `const SEM_VALOR = '__none__'` +
`limparSemValor()` para mapear de volta a `''` antes de enviar ao backend, e usa isso em
**todo** `Select` opcional do arquivo (ex. linhas 570-577, 662-669, 787-791). **Esta fase deve
copiar exatamente esse padrão** para `setor_id`, `depende_de` (se implementado como Select
único em vez de multi-chip) e `auto_fonte` — os 3 campos opcionais do formulário de passo.

### Catálogo fechado de `auto_fonte` — UI precisa deixar óbvio que não é texto livre

D-09 exige que `auto_fonte` seja um catálogo fechado, nunca campo de texto. Contrato visual:

- O campo é **sempre** um `Select` (nunca `Input`/`Textarea`) — a ausência de qualquer campo de
  texto livre ao lado é o que comunica "isto é fechado"
- Cada opção do dropdown mostra um rótulo legível (não o nome da classe PHP), ex.: "Automático —
  `adman_account_id` preenchido" em vez de `AdmanAccountIdResolver`
- Ao selecionar uma opção, uma linha de ajuda aparece abaixo do campo (`text-white/50 text-xs`,
  mesmo estilo de `Nps/Configuracao.jsx:74` `IpsInternosWidget`) explicando **o quê** o resolver
  verifica e **quando** ele roda (síncrono/assíncrono) — importante porque alguns resolvers
  (grant Adman, `ml_acervo_itens`) rodam em Job e podem levar minutos; o admin que monta o
  template precisa saber disso antes de publicar
- Opção "Nenhum (`SEM_VALOR`)" sempre disponível — nem todo passo é automático

### Guarda contra ciclo em `depende_de` (critério 8)

Não existe precedente de detecção de ciclo no projeto (`135-RESEARCH.md`, Seção C — grep por
"ciclo"/"cycle" não encontrou nada relacionado). Contrato de UI para quando o backend rejeita
por ciclo ao tentar publicar:

1. **Erro de campo**, sob o seletor `depende_de` do(s) passo(s) envolvido(s): "Isto criaria um
   ciclo de dependência." — `text-red-400 text-xs` (mesmo padrão de `errors.nome` em
   `Servicos/Index.jsx:200`)
2. **Banner de página**, fixo no topo do editor até o ciclo ser corrigido: ícone
   `AlertTriangle`, fundo `bg-red-500/10 border-red-500/20 text-red-300` (paleta de
   `Polos/Painel.jsx:54`), texto: **"Não foi possível publicar: ciclo de dependência entre
   {chave A} → {chave B} → {chave A}. Ajuste as dependências e tente novamente."** — o caminho
   do ciclo precisa aparecer por extenso; um "erro genérico" não ajuda o admin a encontrar 2
   passos entre 13
3. A validação bloqueia o submit (nunca salva um estado parcialmente inconsistente) — mesmo
   padrão de `FormRequest`/`ValidationException` já usado em todo o projeto (`CLAUDE.md`, Error
   Handling)

### Versionamento visível (D-07/critério 9)

- Cabeçalho do editor sempre mostra: **"Versão {N} · publicada em {data}"** quando editando uma
  versão existente, ou **"Nova versão (ainda não publicada)"** durante a primeira criação
- **Aviso antes de publicar** (só aparece quando há impacto real — mesma disciplina de
  confirmação condicional já usada em `ServicoController::destroy()`/`Servicos/Index.jsx:87`,
  que só avisa quando "houver contratos ativos vinculados"): se existe pelo menos 1 onboarding
  em `rascunho`/`andamento` na versão anterior, o clique em "Publicar versão" abre um `Dialog`
  (`Components/ui/dialog.jsx`) com o texto exato da Copywriting Contract acima. Se não houver
  nenhum onboarding na versão anterior (ex. primeiro publish do serviço), publica direto com um
  toast simples "Versão {N} publicada." — sem dialog, sem fricção artificial
- **Migração é ação separada**, nunca uma checkbox dentro do dialog de publicar (D-07: "ação
  explícita"). Vive numa lista própria — "Onboardings em versões anteriores" — visível a partir
  da tela do template (ou do painel operacional). Cada item tem botão "Migrar onboarding", que abre seu
  próprio `Dialog` com o texto de confirmação já especificado na Copywriting Contract

---

## Tela 3 — Portal do cliente (público, por token)

**Rota proposta:** prefixo novo e distinto de `implementacao/*` (já reservado e isento de CSRF
para Polos — `bootstrap/app.php:21-24`), ex. `onboarding-cliente/{token}` (decisão de
roteamento do planner). Layout público, sem `AppLayout`/sidebar — molde de forma:
`Mlb/ImplementacaoPublica.jsx` (cabeçalho sticky `ProgressHeader`, `ImplementacaoPublica.jsx:74-96`)
e o tom de página de erro de `Nps/Expired.jsx:4-13`/`Nps/Blocked.jsx:4-17` (ícone centralizado +
h1 + parágrafo mudo — `text-muted-foreground`).

### Estrutura

- Header fixo: nome da empresa + legenda pequena "ECF Consultoria · Onboarding" (mesmo padrão
  textual de `ImplementacaoPublica.jsx:82`), fundo `ecf-card`, sem barra de progresso central —
  aqui, diferente da Tela 1, uma indicação leve de "quantos passos faltam" é aceitável porque é
  o próprio cliente monitorando seu progresso pessoal (não é a métrica operacional que o
  critério 11 rejeita); se usada, deve ser secundária ao conteúdo, nunca o elemento dominante
- Corpo: lista de passos `dono=cliente`, **agregados por `chave` entre todos os onboardings
  ativos da empresa** (D-06/D-10) — um card por `chave`, nunca um card por `onboarding_passo`.
  Como a v1 só tem o template de Gestão, essa agregação não tem um segundo template para
  colidir de verdade ainda, mas a tela precisa ser escrita para agrupar por `chave`, não por
  `onboarding_id`, desde o primeiro commit

### Distinção crítica — passo manual vs. passo que se resolve sozinho (D-19)

Nem todo passo `dono=cliente` tem checkbox de "marcar como feito". O template de Gestão tem os
dois tipos:

| Passo | Dono | `auto_fonte` | Como aparece no portal |
|---|---|---|---|
| 2 — Acesso colaborador ML | cliente | não | Card com instrução + botão **"Marcar como feito"** (checkbox/CTA manual) |
| 5 — Grant com o Sistema ECF (OAuth) | cliente | sim (`ml_tokens.status=active`) | Card com botão **"Autorizar acesso"** (leva ao fluxo OAuth) — **sem** checkbox manual; quando `ml_tokens.status` vira `active`, o card muda sozinho para o estado concluído (emerald, sem selo de ação do usuário) na próxima carga/poll da página |
| 10 — Custos no App ECF | cliente | não | Card com instrução + botão "Marcar como feito" |

Renderizar um checkbox manual no passo 5 violaria D-19 (o sistema é quem confirma, nunca o
clique do cliente) — o card desse passo é visualmente diferente dos outros dois (CTA de ação
externa, não de confirmação).

### Passo 1 (ficha do cliente) — nuance intencional entre D-16 e o `dono` do passo

O passo 1 ("Ficha do cliente recebida") tem `dono=interno` na tabela do template
(`135-CONTEXT.md`, linha do passo 1) — **não** `dono=cliente`. Isso significa que, pela regra
geral desta tela ("agrega passos `dono=cliente`"), o passo 1 não deveria aparecer na lista
principal do portal. Mas D-16 exige que a ficha seja recebida como **anexo**, e o cliente é
naturalmente quem tem o arquivo. Resolução adotada por esta spec (discricionária, não travada
pelo CONTEXT — documentada aqui para o planner/checker confirmarem): **capacidade de anexar ≠
autoridade de confirmar.** O portal ganha um bloco fixo, visualmente separado da lista de
"passos seus" (card no topo, sem numeração de checklist, sem contador), com o texto "Envie sua
ficha cadastral aqui" + upload de arquivo — mas **sem checkbox de conclusão**. Quem marca o
passo 1 como "recebida" é sempre um usuário interno na Tela 1 (respeitando `dono=interno`), ao
revisar o anexo. Se essa leitura estiver errada, é uma correção de produto, não uma mudança de
arquitetura desta tela.

### Trava anti-check-vazio (D-16, aplicada aos passos manuais)

Padrão já em produção: `MlbImplementacao::itemTemConteudo()` (`app/Models/MlbImplementacao.php:448-459`,
espelhado em `resources/js/Pages/Mlb/ImplementacaoPublica.jsx:1564` — comentário explícito na
linha 1560-1563 de que as duas cópias precisam ser sincronizadas manualmente) — só itens onde o
cliente **digita/seleciona/monta** algo exigem conteúdo mínimo antes de liberar "Marcar como
feito"; itens de **ação pura** (acessar link, dar acesso, declarar) ficam sempre liberados
(`MlbImplementacao.php:439-440`).

**Como isso se aplica ao template de Gestão:** os dois passos manuais `dono=cliente` (2 — Acesso
colaborador ML, 10 — Custos no App ECF) são, pela leitura desta pesquisa, do tipo "declaração de
ação" — não têm campo de texto/seleção associado (o cliente só confirma que fez algo fora do
sistema). **Para eles, a trava anti-check-vazio não morde na v1** — o botão "Marcar como feito"
fica sempre liberado. A trava só entra em cena se um `tipo` de passo futuro pedir dado digitado
do cliente (texto/link/select). Se isso acontecer, replicar `itemTemConteudo` — mesma função,
mesma disciplina de sincronia manual documentada no próprio comentário da fonte — tanto no
backend quanto no resolver do formulário público novo.

### Estados da tela

| Estado | Copy | Visual |
|---|---|---|
| Token não encontrado | Ver Copywriting Contract | Página cheia, ícone `AlertTriangle` centralizado, mesmo layout de `Nps/Expired.jsx:4-13` |
| Nenhum onboarding saiu de `rascunho` ainda (empresa sem nada `dono=cliente` visível) | "Ainda não há nada pendente da sua parte" / "Em breve entraremos em contato para dar continuidade ao seu onboarding." | Mesma página, sem lista, tom neutro — nunca expor que existe um onboarding em rascunho aguardando responsável (SC-04: rascunho não expõe informação operacional) |
| Todos os passos `dono=cliente` concluídos | Ver Copywriting Contract ("Tudo certo por aqui!") | Estado positivo de fechamento, não um formulário vazio |
| Passo 5 aguardando callback do OAuth | "Conectando…" transitório entre o clique em "Autorizar acesso" e o retorno do fluxo — evitar qualquer estado onde a tela pareça travada sem feedback | Spinner discreto, mesmo padrão de `RefreshCw`/`animate-spin` da Tela 1 |

### Sem expiração de token (risco herdado, não novo)

Mesmo princípio já aceito para Polos: token de alta entropia (`Str::random(48)`, `unique()` no
banco — `MlbImplementacaoController.php:576-590`), sem coluna de expiração. Este é um risco já
aceito no precedente, não uma regressão introduzida por esta fase — se o produto quiser
expiração para o motor novo, é decisão nova a levantar separadamente, não algo que este UI-SPEC
resolve por conta própria.

---

## Inventário de componentes — referência cruzada

| Componente | Onde vem | Usado em |
|---|---|---|
| `Card`, `CardContent`, `CardHeader` | `Components/ui/card.jsx:4-32` | Telas 1 e 2 (cards de empresa/passo) |
| `Badge` | `Components/ui/badge.jsx:5-26` | Base para chips de status (a paleta semântica desta fase usa classes próprias em cima do componente, não as `variant` default — nenhuma das 6 `variant` cobre "aguardando coleta"/"bloqueado") |
| `Select`, `SelectTrigger`, `SelectContent`, `SelectItem` | `Components/ui/select.jsx:6-100` | Tela 2 (`setor_id`, `auto_fonte`, `condicao`) — **com sentinela `SEM_VALOR`** |
| `Dialog`, `DialogContent`, `DialogHeader`, `DialogFooter` | `Components/ui/dialog.jsx:6-61` | Tela 2 (aviso de publicar versão, confirmação de migrar) |
| `Checkbox` | `Components/ui/checkbox.jsx:11-28` | Tela 2 (`obrigatorio`) |
| `Avatar`, `AvatarFallback` | `Components/ui/avatar.jsx:5-20` | Tela 1 (responsável do onboarding) |
| `Button` | `Components/ui/button.jsx:29-33` | Todas — variant `default` para CTA primário (herda `bg-primary`; equivalente ao padrão manual `bg-ecf-yellow` já usado em `Nps/Configuracao.jsx:96`, confirmar qual convenção o planner escolhe — ambas já coexistem no projeto) |
| `Input`, `Label`, `Textarea` | `Components/ui/input.jsx`, `label.jsx`, `textarea.jsx` | Tela 2 (campos de texto/número do passo) |
| `Table`, `TableHeader`, `TableRow`, `TableCell` | `Components/ui/table.jsx` | Tela 2, modo lista (se optar por tabela em vez de grid de cards — ambos precedentes existem: `Servicos/Index.jsx:111-181` usa tabela, `Nps/Configuracao.jsx` usa grid de cards via `TemplatesGrid`) |
| `StatChip` | `resources/js/Components/StatChip.jsx:16-31` | Tela 1 (contadores clicáveis — "3 vencidos", "2 aguardando cliente" — reaproveitável tal como está, já é `tone`-based e cross-domínio) |
| `RefreshCw`, `Lock`, `Zap`, `AlertTriangle`, `CheckCircle2` (ícones) | `lucide-react` | Tela 1 (estados de passo) |

---

## Perguntas genuinamente em aberto (não bloqueiam este UI-SPEC)

Nenhuma pergunta de contrato visual ficou sem resposta — CONTEXT + RESEARCH + os padrões
existentes cobriram tipografia, cor, spacing e copy. As únicas duas pendências identificadas
são de **roteamento/permissão**, não de design, e não travam a construção desta fase:

1. **Gate de acesso do painel operacional** (Tela 1) — `role:admin` puro ou `permission:`
   dedicada? Recomendação registrada acima; não muda nenhum componente ou cor.
2. **Nome exato do prefixo de rota pública** (Tela 3) — qualquer prefixo isento de CSRF serve
   ao contrato visual; só não pode ser `implementacao/*` (reservado a Polos).

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
