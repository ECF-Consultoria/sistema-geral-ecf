---
phase: 137
slug: fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab
status: draft
shadcn_initialized: false
preset: none
created: 2026-09-02
---

# Phase 137 — UI Design Contract

> Contrato visual e de interação para a evolução da tela `/financeiro`
> (`resources/js/Pages/Admin/Financeiro.jsx`, `AdminController::fechamento()`).
> Esta fase **evolui** a tela existente — não é tela nova. Todo componente
> abaixo marcado "reaproveitar" já existe no arquivo e deve ser mantido com
> ajustes pontuais, não reescrito do zero.

---

## Design System

| Property | Value |
|----------|-------|
| Tool | none — sem `components.json`/CLI shadcn. O projeto já tem um sistema manual estilo shadcn (Radix + `class-variance-authority` + `cn()`) em `resources/js/Components/ui/`, maduro e usado em ~20 páginas. **Não inicializar o CLI shadcn nesta fase** — inconsistente com o restante do projeto e sem ganho aqui. |
| Component library | Radix UI (via primitivos manuais em `Components/ui/`: `dialog`, `button`, `input`, `label`, `textarea`, `select`, `badge`, `table`) |
| Icon library | `lucide-react` |
| Font | `Inter` (corpo, `font-sans`), `Manrope` (números de destaque, `font-display`) |

**Fonte da verdade de tokens:** `tailwind.config.js` (`theme.extend.colors.ecf`), não redeclarar.

---

## Spacing Scale

Escala de 8 pontos confirmada (apenas múltiplos de 4):

| Token | Value | Usage |
|-------|-------|-------|
| xs | 4px | Gaps entre ícone e texto, badges (`gap-1`, `gap-1.5`) |
| sm | 8px | Espaçamento compacto entre elementos de uma linha |
| md | 16px | Padding padrão de card/seção (`p-4`, `px-4`) |
| lg | 24px | Padding de seções maiores |
| xl | 32px | Gaps de layout entre blocos |

Exceções: nenhuma. Todo elemento novo desta fase (badge "Fechado/Aberto", form de faixas, aviso
de grupo com serviços divergentes, dialog de "Refazer") usa somente esta escala.

> **Débito de espaçamento conhecido — fora do escopo desta fase (nota não-normativa, não faz
> parte da escala acima):** a tela já tem `py-2.5` (10px, células de tabela do `ProgressaoModal`
> e seus cabeçalhos) e `py-0.5` (2px, badges/pills como `ServiceBadge`/`IntegrationBadge`/badge
> "Grupo · N"), que não são múltiplos de 4. O botão `RecebidoToggle` também usa alvo de toque de
> 24px (`w-6 h-6`), abaixo do mínimo de 44px recomendado para touch — padrão desktop-only já em
> produção. Nenhum desses três pontos é tocado por esta fase; registrados aqui apenas para não
> serem confundidos com a escala válida declarada acima.

---

## Typography

Tamanhos e pesos desta fase — **2 pesos no máximo**, mesma disciplina já travada na Fase 131
(mesma tela de admin, mesmo design system: "4 tamanhos e 2 pesos, não introduzir um terceiro
peso"):

| Role | Size | Weight | Line Height |
|------|------|--------|-------------|
| Label / micro | 11px | semibold (600) | 1.2 — uso em badges, rótulos uppercase (`tracking-wider`/`tracking-widest`) |
| Body | 13px | regular (400) | 1.5 — texto de linha, células de tabela, nome de empresa em contexto secundário |
| Heading | 15px | semibold (600) | 1.2 — nome da empresa na linha principal, título de modal |
| Display | 20px | semibold (600), `font-display` | 1.2 — valores monetários de destaque (KPIs do "Total consolidado") |

A diferenciação do valor de destaque (Display) vem do **tamanho** (20px) + `font-display`
(Manrope), não de um terceiro peso — `bold (700)` não é usado por esta fase.

**Exceção herdada (não introduzir em elementos novos):** cabeçalhos de tabela em 10px
(`ProgressaoModal`, `ContratosSection`) já existem na tela — mantidos por serem cabeçalho técnico
de tabela densa, não replicar o tamanho 10px em copy nova desta fase (usar 11px).

Elementos novos desta fase devem usar **apenas** os 4 tamanhos e os 2 pesos acima.

---

## Color

| Role | Value | Usage |
|------|-------|-------|
| Dominant (60%) | `#050507` (`ecf-bg`) | Fundo da página |
| Secondary (30%) | `#0f1116` (`ecf-card`) + `white/[0.02]`–`white/[0.05]` | Cards, linhas de tabela, painel do modal, seções expandidas (`bg-black/30`) |
| Accent (10%) | `#ffe600` (`ecf-yellow`) | Ver lista fechada abaixo |
| Destructive | `red-400` / `red-500` (Tailwind, já em uso: `text-red-400`, `border-red-500/[0.15]`) | Ver lista fechada abaixo |
| Positivo (semântica adicional já existente — preservar) | `emerald-400` | "Subiu de faixa", "Recebido", mensalidade a cobrar — **não** é o accent da marca, é uma terceira cor semântica que a tela já usa para "dinheiro recebido / evolução positiva"; manter separada do accent amarelo |

**Accent reservado para (lista fechada — elementos novos desta fase):**
1. Botão primário "Fechar [mês/ano]" (ex.: "Fechar agosto/2026").
2. Badge de status "Fechado" quando a competência já foi consolidada (reaproveita o padrão `bg-ecf-yellow/20 text-ecf-yellow` já usado no badge "atual" do `ProgressaoModal`).
3. Barra de progresso até a próxima faixa (`FaixaProgresso` — já existente, preservar).
4. Ícone/texto de "faixa máxima atingida" (já existente, preservar).
5. Botão "Cadastrar tabela de faixas" / "Editar tabela" na nova seção de faixas por empresa.
6. Badge "Grupo · N" (já existente, preservar).
7. Hover/focus de controles interativos padrão (outline buttons, selects — já existente, preservar).

**Destructive reservado para:**
1. Ação "Refazer fechamento" (botão + confirmação) — única ação genuinamente destrutiva desta fase (reabre um número já usado para cobrança).
2. Indicador "Desceu de faixa" (`EvolucaoBadge`, já existente — `text-red-400`).
3. Estado "Inadimplente" (já existente — preservar, não é novo desta fase).

Não usar destructive para "empresa sem tabela" ou "sem faturamento" — esses são estados de **ausência de dado**, não erro/perigo. Usar a paleta neutra (`white/40`, `white/25`, `amber-400` para pendência que exige ação — ver Copywriting).

---

## Foco Visual

Ponto focal ao entrar na tela: o olho lê primeiro o **nome da empresa** (15px semibold, `FechamentoRow`)
e, colado a ele, o **ícone de evolução** (`EvolucaoBadge` — verde/vermelho/cinza), porque é a única
cor semântica na linha crua antes de expandir. Em seguida o olho é puxado pela **mensalidade em
destaque** à direita da linha (`font-mono`, `emerald-400`). O accent amarelo (`ecf-yellow`) fica
deliberadamente fora da leitura linha-a-linha — concentrado no cabeçalho da página (badge de status
da competência + botão "Fechar") — para não competir com a varredura vertical da lista, que é a
tarefa principal desta tela.

---

## Copywriting Contract

⚠️ Regra sistêmica do projeto: nenhum termo técnico interno cru na tela — nada de "snapshot",
"congelado" (adjetivo), "rolling window", "override". Usar os termos de domínio que a própria
tela já usa (**"fechamento"**, **"faixa"**, **"mês"**) e nada além disso.

| Elemento | Copy |
|---|---|
| CTA primário (fechar) | `Fechar {mês}/{ano}` — ex.: "Fechar agosto/2026". Verbo + mês concreto, nunca "Fechar competência" genérico. |
| CTA secundário (refazer, mês já fechado) | `Refazer fechamento` — some o botão "Fechar" quando o mês já está fechado; aparece este no lugar, com tratamento visual destructive (não accent). |
| Badge de status — mês aberto | `Em aberto` — cor neutra (`white/50`), sem ícone de alerta (é o estado normal, não um problema). |
| Badge de status — mês fechado | `Fechado` — cor accent (`ecf-yellow`), com tooltip/subtexto: `Fechado em {data}. Os valores não mudam sozinhos.` |
| Dialog "Refazer fechamento" — título | `Refazer fechamento de {mês}/{ano}` |
| Dialog "Refazer fechamento" — corpo | `Os valores já cobrados ficam registrados no histórico. Ao confirmar, os números exibidos nesta tela passam a refletir o novo cálculo.` |
| Dialog "Refazer fechamento" — campo obrigatório | Label: `Motivo do reprocessamento` · placeholder: `Ex.: correção de faturamento na Adman após o fechamento.` · Botão de confirmar desabilitado até o campo ter conteúdo. |
| Confirmação "Fechar" (primeira vez, `confirm()` nativo — mesmo padrão já usado no arquivo para ações de contrato) | `Fechar {mês}/{ano}? Depois de fechado, os valores desta competência ficam registrados e não mudam sozinhos — use "Refazer fechamento" se precisar corrigir depois.` |
| Erro ao fechar a competência | `Não foi possível fechar {mês}/{ano}. Nada foi alterado — tente novamente ou avise o time técnico.` |
| Erro ao refazer a competência | `Não foi possível refazer o fechamento de {mês}/{ano}. O registro anterior continua valendo.` |
| Empresa sem tabela cadastrada e sem serviço com tabela | Placeholder: `Tabela de faixas: A DEFINIR` (reaproveita literalmente o placeholder `A DEFINIR` já usado em `ContratoPdfService::PLACEHOLDER` para o mesmo conceito de ausência visível) + subtexto: `Cadastre a tabela de faturamento desta empresa para ela entrar no fechamento.` + botão `Cadastrar tabela de faixas`. |
| Empresa sem faturamento no mês | `Sem faturamento neste mês` — nunca branco/traço silencioso; não desenha a barra de progresso de faixa quando não há faturamento. |
| Empresa com tabela herdada do serviço | `Tabela do serviço {nome do serviço}` (cor neutra, sem badge de alerta — é o caminho padrão). |
| Empresa com tabela própria (exceção) | `Tabela própria desta empresa` (badge neutro, não accent — accent é reservado para ação/status, não para metadado informativo) + subtexto: `Substitui completamente a tabela do serviço "{nome}".` |
| Erro ao salvar faixa (limites sobrepostos) | `Essa faixa se sobrepõe à faixa {ordem}. Ajuste o limite antes de salvar.` |
| Grupo com empresas de serviços diferentes | Banner de aviso (não erro): `Este grupo tem empresas com tabelas diferentes` + lista "empresa → tabela aplicada" por linha. Cor âmbar (`amber-400`, mesma família já usada em `IntegrationBadge`), não destructive. |
| Faturamento combinado ML + Shopee | Linha de detalhe abaixo do valor total: `Mercado Livre {valor} + Shopee {valor} = {total}` — nunca soma silenciosa sem abrir a composição. |
| Modal "Ver progressão" — subtítulo | `Progressão de faixa` (mantém título já existente) — remove a coluna "Acumulado" e a palavra "acumulado" de toda a UI; renomeia a coluna restante de "Fat. do mês" para apenas `Faturamento do mês` (já não há ambiguidade sem a coluna acumulada ao lado). |
| Erro de sincronização (já existente, preservar) | `Erro ao sincronizar: {mensagem}` |
| Empty state da lista principal (já existente, preservar) | `Nenhuma empresa ativa encontrada.` / `Cadastre uma empresa com status ativo para que ela apareça aqui.` |
| Destrutivo — remover linha de faixa no cadastro | `Remover a faixa "{ordem}ª faixa" desta tabela?` (segue o padrão `confirm()` nativo já usado em `ContratosSection`/`Companies/Show.jsx`) |

---

## Telas e Estados (contrato específico da Fase 137)

Não fazem parte do template padrão, mas são obrigatórios para esta fase por serem o motivo de
existir do UI-SPEC — cada estado abaixo precisa de tratamento visual explícito, nunca "cair" no
estado adjacente por omissão.

### 1. Linha de empresa/grupo na lista principal (`FechamentoRow`, reaproveitar)
- **Mês aberto, com faturamento, com tabela:** comportamento atual preservado — nome, `EvolucaoBadge`, faturamento, mensalidade calculada.
- **Mês fechado:** mesma linha, mas o valor exibido vem do registro congelado (não recalcula ao vivo); badge `Fechado` visível na altura do nome ou do cabeçalho da lista (nível de página, não por linha — a competência é uma unidade só).
- **Empresa sem tabela (própria ou do serviço):** substitui a mensalidade calculada por `A DEFINIR` + ícone de pendência (âmbar, não destructive) — nunca oculta a linha, nunca mostra R$ 0.
- **Empresa sem faturamento no mês:** substitui `{fmtBRL(faturamento)}` por `Sem faturamento neste mês` (cor `white/40`, sem formatação monetária de zero).
- **Grupo (várias empresas):** já existe `Grupo · N` — preservar. Fonte do agrupamento passa a ser `CompanyGroup`, badge não muda visualmente.
- **Grupo com serviços divergentes:** banner de aviso adicional dentro do accordion expandido (ver Copywriting).

### 2. Accordion expandido (`FechamentoAccordion`, reaproveitar + estender)
- Seção "Composição do grupo" (já existe) ganha, por linha de empresa-irmã: qual tabela foi aplicada (herdada/própria) quando os serviços divergem.
- Nova seção "Tabela de faixas aplicada" — mostra origem (herdada/própria) + link/botão para o cadastro (ver seção 4).
- `ProgressaoModal` perde a coluna Acumulado (ver Copywriting).

### 3. Cabeçalho da página / seletor de mês
- `MesSeletor` (já existe, preservar) ganha ao lado o badge `Em aberto`/`Fechado` do mês selecionado e o botão primário correspondente (`Fechar {mês}` ou `Refazer fechamento`).
- Enquanto o mês está aberto, todos os números da tela continuam ao vivo (comportamento atual). Ao fechar, a tela deve deixar claro visualmente (badge + eventual leve atenuação do botão "Sincronizar faturamento", que deixa de ter efeito sobre um mês fechado) que os números pararam de recalcular.

### 4. Cadastro da tabela de faixas por empresa (tela/seção nova, D-04)
Novo bloco dentro do accordion da empresa (não uma rota separada — mantém a interação "expandir e ver tudo" já estabelecida pela tela):
- **Empresa sem exceção (usa a do serviço):** lista somente leitura das faixas do serviço, com rótulo `Tabela do serviço {nome}` e um botão secundário `Criar tabela própria` (que abre o form vazio).
- **Empresa com exceção própria:** lista editável (linhas: ordem, limite superior, valor), rótulo `Tabela própria desta empresa`, aviso fixo "Substitui completamente a tabela do serviço" (D-13, sempre visível quando há exceção — nunca só na hora de salvar), botão `Adicionar faixa`, e por linha: editar/remover. Botão `Voltar a usar a tabela do serviço` (ação que apaga a exceção — precisa de `confirm()` porque remove dado).
- **Empresa sem tabela nenhuma (nem própria, nem serviço tem tabela):** estado `A DEFINIR` (ver Copywriting) com CTA único `Cadastrar tabela de faixas`.
- Form de faixa (linha): campos `Ordem` (número), `Faturamento até` (moeda, vazio = "sem teto" — rotular como `Sem limite superior`, nunca deixar o campo mudo sem explicação), `Valor da mensalidade` (moeda). Usa `Dialog` + `Input`/`Label` já existentes (mesmo padrão do modal de contrato já presente no arquivo). Erro de validação: ver "Erro ao salvar faixa" em Copywriting.

### 5. Fechar / Refazer competência
- Botão `Fechar {mês}/{ano}` — accent, só aparece quando o mês está aberto. Ação: `confirm()` nativo (copy acima) → POST. Falha: ver "Erro ao fechar a competência" em Copywriting.
- Após fechado: botão muda para `Refazer fechamento` — tratamento destructive (borda/texto vermelho, não preenchido — mesmo peso visual do botão "Desativar contrato" já existente, não um botão vermelho sólido chamativo). Ação abre `Dialog` com campo obrigatório de motivo (não usa `confirm()` porque precisa capturar texto). Falha: ver "Erro ao refazer a competência" em Copywriting.
- Nenhum botão de fechar/refazer aparece por linha de empresa — é uma ação de competência inteira, no cabeçalho da página.

---

## Inventário de Componentes

| Componente | Situação | Arquivo |
|---|---|---|
| `FechamentoRow`, `FechamentoAccordion`, `FechamentoList` | Reaproveitar, estender | `Financeiro.jsx` |
| `EvolucaoBadge`, `FaixaProgresso`, `ProgressaoModal` | Reaproveitar; `ProgressaoModal` perde a coluna Acumulado | `Financeiro.jsx` |
| `TotalConsolidado`, `GraficoFaixas`, `MesSeletor` | Reaproveitar sem mudança estrutural | `Financeiro.jsx` |
| `ServiceBadge`, `IntegrationBadge`, `ContratosSection` | Reaproveitar sem mudança | `Financeiro.jsx` |
| `StatusCompetenciaBadge` (Em aberto / Fechado) | **Novo** | `Financeiro.jsx` (componente local, mesmo padrão dos badges existentes) |
| `FecharCompetenciaButton` | **Novo** — `confirm()` nativo, accent | `Financeiro.jsx` |
| `RefazerFechamentoDialog` | **Novo** — usa `Dialog`/`Textarea`/`Label` de `Components/ui/` | `Financeiro.jsx` |
| `TabelaFaixasSection` (herdada/própria, CRUD de linhas) | **Novo** | `Financeiro.jsx` ou extrair para `Financeiro/TabelaFaixasSection.jsx` se crescer muito (decisão do executor conforme tamanho do arquivo — já tem 1255 linhas) |
| `FaixaFormDialog` (add/edit linha de faixa) | **Novo** — usa `Dialog`/`Input`/`Label` | idem |
| `AusenciaTabelaPendencia` (badge "A DEFINIR" + CTA) | **Novo** | idem |
| `AusenciaFaturamentoBadge` | **Novo** | idem |
| `GrupoServicosDivergentesBanner` | **Novo** | idem |
| `FaturamentoCombinadoBreakdown` (linha ML + Shopee = total) | **Novo** | idem |

Nenhum primitivo novo de `Components/ui/` é necessário — `dialog`, `input`, `label`, `textarea`,
`button` já cobrem todos os componentes novos listados acima.

---

## Registry Safety

| Registry | Blocks Used | Safety Gate |
|----------|-------------|-------------|
| shadcn official | não aplicável — nenhum bloco novo instalado via CLI nesta fase | not required |
| third-party | nenhum | not applicable |

Nenhuma dependência nova (`composer require`/`npm install`) — confirmado pelo RESEARCH.md
("Package Legitimacy Audit não se aplica").

---

## Checker Sign-Off

- [ ] Dimension 1 Copywriting: PASS
- [ ] Dimension 2 Visuals: PASS
- [ ] Dimension 3 Color: PASS
- [ ] Dimension 4 Typography: PASS
- [ ] Dimension 5 Spacing: PASS
- [ ] Dimension 6 Registry Safety: PASS

**Approval:** pending
