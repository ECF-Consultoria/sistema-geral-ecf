---
phase: 160
slug: publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incubadora
status: approved
shadcn_initialized: false
preset: none
created: 2026-10-02
revised: 2026-10-02 (layout do editor redesenhado do zero por pedido do usuário; revisão 1 do checker: tipografia em 4 tamanhos, sem 12px)
reviewed_at: 2026-10-02
---

# Fase 160 — Contrato de UI

> Contrato visual e de interação do Publicador interno (`/mlb/anuncios`). Gerado por gsd-ui-researcher, verificado por gsd-ui-checker.
> Fontes: `160-CONTEXT.md` (D12–D23, travadas), `160-RESEARCH.md` (§4, §6), identidade "ECF Admin Dark" (Stitch, projeto `15646202289570387715`), `tailwind.config.js`, `AppLayout.jsx`, e a **lógica** dos componentes do piloto em `resources/js/Components/Publicador/*`.
>
> **Correção do usuário (02/10/2026):** o layout "Anunciar (Redesign Focado)" (seções 01–08 + trilho de 340px) é do **Portal**. Para o `/mlb/anuncios` o usuário pediu **um layout novo, do zero**. Este contrato, portanto, **não herda** a composição do piloto: reaproveita-se a lógica e os componentes de campo (podem ser reestilizados e recompostos), mas a organização da tela, a navegação empresa → produtos → editor e o lugar de status e ações são desenho novo, definido abaixo.
>
> **Referências aprovadas (Stitch, projeto `15646202289570387715`, 02/10/2026):** editor = tela `3c7f275a9e9046338d1f6d2854b19c64` ("ECF Admin - Editor Publicador MLB"), **layout travado** pelo usuário; entrada = tela `6179e11dac77472884f60d724cdec375` ("ECF Admin - Publicador MLB"), **direção aprovada**, com o terceiro programa "Gestão" (D23) acrescentado e os itens inventados removidos. Onde o Stitch diverge do motor ou das regras da identidade, este contrato diz o que prevalece e lista a divergência em "Perguntas em aberto".
>
> **Lições do piloto que valem aqui (feedback do usuário):** nunca um rodapé alto e fixo listando pendências; nunca abas com contadores de alerta como estrutura principal; manter respiro; campos obrigatórios visíveis e opcionais recolhidos; estados calmos (sem vermelho de alarme enquanto se preenche).

---

## 1. Escopo das telas

| # | Tela | Rota (nome sugerido) | Componente | Origem |
|---|------|----------------------|-----------|--------|
| A | Entrada: seletor de programa + lista de empresas | `mlb.anuncios.index` | `Pages/Mlb/AnunciosEmpresas.jsx` (reescrita: cards viram lista) | D13, D17, D23 |
| B | Produtos da empresa | `mlb.anuncios.publicador.produtos` | `Pages/Mlb/Publicador/Produtos.jsx` (novo) | D15, D16 |
| C | Editor do produto ("mesa de anúncio": barra, faixa de produtos, cards, lateral) | `mlb.anuncios.publicador.editor` | `Pages/Mlb/Publicador/Editor.jsx` (casca nova) + novo `Components/Publicador/Mesa/*` compondo os componentes de campo existentes | D12, D14, D24 |
| D | Estado "conta não liberada" | transversal a A, B, C | `AvisoContaTravada` (novo) | D21 |
| E | Assistente antigo escondido | sem entrada principal | link discreto em B | D22 |

Todas dentro de `AppLayout` (sidebar 256px, recolhida 64px no hover). Só admin (D17): sem estado "sem permissão" desenhado, o servidor devolve 403. Nomes de rota/arquivo são sugestão; o contrato é o desenho.

---

## 2. Design System

| Propriedade | Valor |
|-------------|-------|
| Ferramenta | none (sem `components.json`; o projeto mantém primitivas estilo shadcn escritas à mão em `Components/ui/`, sobre Radix) |
| Preset | não se aplica |
| Biblioteca de componentes | Radix UI (`Components/ui/*`: dialog, popover, switch, progress, tabs só para as abas de rota) + HTML nativo para select (`Seletor`: Radix Select com `value=""` derruba a tela, regra do projeto) |
| Utilitário de classes | `cn()` de `@/lib/utils` em todo componente novo |
| Ícones | `lucide-react`: 16px em ações e selos, 18px em ícone de cabeçalho, 14px em linha de apoio |
| Fontes | **Manrope** (`font-display`) em título de página e números grandes; **Inter** (`font-sans`) em toda a interface; `font-mono` em SKU, MLB, IDs |
| Tema | dark-first; sem modo claro nesta fase |

Gate shadcn: não se pergunta ao usuário. O stack manual existente está decidido e introduzir `components.json` agora arriscaria reescrever primitivas em uso pelo sistema inteiro. Registrado como `Tool: none`.

---

## 3. Espaçamento

Escala de 8 pontos, múltiplos de 4.

| Token | Valor | Classe | Uso |
|-------|-------|--------|-----|
| xs | 4px | `gap-1` | Ícone + texto em selo; entre itens de checklist |
| sm | 8px | `gap-2` | Entre botões; padding vertical de célula densa |
| md | 16px | `gap-4` | Padding de linha/painel; entre campos de um grid |
| lg | 24px | `gap-6` | Padding interno do corpo de uma seção; entre blocos do cabeçalho |
| xl | 32px | `gap-8` | Entre seções do editor; margem lateral da página |
| 2xl | 48px | `py-12` | Respiro de estado vazio |
| 3xl | 64px | `py-16` | Não usado nesta fase |

Exceções declaradas:
- Alturas de controle: **40px** (inputs, selects, botões secundários, chips da faixa de produtos); botão primário de publicar do resumo também **40px** (o destaque vem da cor e da largura total do card); linha de lista **56px**; barra superior do editor **56px**; faixa de produtos **48px**. Alvos de toque nunca abaixo de 40px.
- Container das telas A e B: `max-w-[1240px] px-8 py-8`. Editor: coluna principal fluida até **800px** + 32px de gap + lateral fixa de **320px** (≥ 1360px de viewport); abaixo disso, coluna única. Painel "Como funciona" da tela A: 320px.
- Medidas fora da escala, **de propósito** (o executor não as "corrige" para 4/8/16/24/32/48): miniatura de foto **72px**; ponto de status **6px** (é indicador, não espaçamento); raio **10px** em tiles, chips e miniaturas (cards e faixas usam 12px; modais 16px); larguras **280px** (busca da tela A), **320px** (lateral do editor e painel "Como funciona"), **800px** (coluna principal do editor); `sticky top-[80px]` e `scroll-margin-top` de 80px (barra de 56px + 24px).
- Componentes de campo reaproveitados (`CampoAtributo`, `EditorDeEixos`, `GradeVariantes`, `FotosPorGrupo`, `Problemas`) podem manter o espaçamento interno atual; só se reestilizam onde o contrato pedir (seção 8.6).

---

## 4. Tipografia

Em tela **nova**: exatamente 4 tamanhos e 2 pesos.

| Papel | Tamanho | Peso | Altura de linha | Fonte |
|-------|---------|------|-----------------|-------|
| Display (título de página e do produto) | 24px | 700 | 1.2 | Manrope |
| Heading (título de seção, de painel e de modal) | 15px | 700 | 1.2 | Inter |
| Body (corpo, células, inputs, botões, mensagens) | 13px | 400 | 1.5 | Inter |
| Label (cabeçalho de coluna, rótulo de grupo, selo) | 11px, MAIÚSCULAS, tracking 0.05em, `text-white/40` | 700 | 1.4 | Inter |

Pesos: **400** e **700**. Em tela nova não usar `font-medium`, `font-semibold` nem `font-extrabold`.

Regras:
- Números (contagens, preços, "N de 8", progresso) em `tabular-nums`.
- SKU, MLB e IDs em `font-mono` no tamanho **Body (13px)**, ou **Label (11px, sem maiúsculas)** onde a densidade pedir (chips da faixa de produtos, selos). **Não existe 12px** nesta fase.
- **Normalização obrigatória:** os componentes reaproveitados (`CampoAtributo.jsx`, `EditorDeEixos.jsx`, `GradeVariantes.jsx`, `FotosPorGrupo.jsx`, `Problemas.jsx`, e o que se extrair de `EditorPublicador.jsx`) trazem 12, 12.5, 11.5px e `font-semibold`/`font-extrabold`/`font-medium`. Ao entrarem na mesa, todo texto vai para 13px (corpo) ou 11px (rótulo/selo) e todo peso para 400 ou 700, sem reescrever lógica. Exceção temporária: nenhuma; se algum arquivo não puder ser normalizado na fase, o plano o lista pelo nome como exceção de migração explícita.

---

## 5. Cor

Regra 60/30/10 sobre o "ECF Admin Dark":

| Papel | Valor | Uso |
|-------|-------|-----|
| Dominante 60% | `#050507` (`ecf-bg`) | Fundo da página |
| Secundário 30% | `#0F1116` (`ecf-card`); elevado `#14161D` (`ecf-card-2`); painéis `white/[0.03]` + borda `white/[0.08]` | Linhas de lista, seções, popovers, modais; input `#1A1C24` borda `#1B1E26` (o `CLASSE_INPUT` do piloto, `bg-white/[0.04]`, é aceito) |
| Acento 10% | `#FFE600` (`ecf-yellow`); gradiente de botão `#FFE600 → #F5D400`, texto `#252525` | Lista fechada abaixo |
| Destrutivo | `red-300` translúcido (`bg-red-500/[0.06]`, borda `red-500/30`) | Só falha real: publicação recusada, token expirado, erro de rede. **Nunca** para campo em falta nem para conta não liberada |

**Acento reservado EXCLUSIVAMENTE para:**
1. O botão "Publicar N anúncios": no card Resumo do lançamento da lateral em ≥ 1360px, e na barra superior abaixo disso (nunca os dois amarelos ao mesmo tempo; único botão amarelo sólido da fase, com o brilho do botão primário do sistema).
2. A aba ativa do `ModoAnuncioTabs` (fundo `ecf-yellow`, texto preto), como hoje.
3. A opção ativa do seletor de programa, em versão translúcida (`border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow`), para não competir com a aba.
4. O preenchimento da barra de "Prontidão de envio" da lateral (4px de altura) e o chip do produto ativo na faixa de produtos (versão translúcida, como o seletor de programa).
5. O anel de foco de teclado, 2px, em todo controle.
6. O ícone de cabeçalho de página, no padrão DevCard (quadrado 40px, fundo amarelo 12%, borda amarelo 20%).
7. Hover de links de texto ("trocar", "Copiar do Clássico").

**Proibido amarelo em:** "Publicar →" das linhas, "Sincronizar do Portal", "+ Produto", "Anunciar por IA", "Conferir no Mercado Livre", cards de indicadores da entrada (o Stitch destaca "Prontos para publicar" em amarelo: não), pílula do programa, selos de status, chips de SKU, linhas com problema (o Stitch tinge de vermelho: não).

Semânticas sempre translúcidas, nunca sólidas:

| Significado | Fundo / borda / texto | Onde |
|-------------|-----------------------|------|
| Sucesso | `emerald-500/10`, `emerald-500/30`, `emerald-400` | "Completo", conferido, publicado, conta ativa |
| Falta preencher | **neutro** `white/[0.04]` + ponto âmbar de 6px + texto `white/70` | Seção incompleta (estado calmo: não é alarme) |
| Atenção (ML) | `amber-300/10`, `amber-300/25`, `amber-300` | Pendências apontadas pelo Mercado Livre na conferência, parte publicada, reconectar conta |
| Informação | `sky-500/[0.06]`, `sky-500/25`, `sky-200` | Ofertas novas no Portal, resultado da IA, multi-depósito |
| Erro | `red-500/[0.06]`, `red-500/30`, `red-300` | Falha de publicação, token expirado, erro de rede |
| Neutro / calmo | `white/[0.04]`, `white/[0.08]`, `white/55` | Rascunho, "Sem Portal", **conta não liberada** |

Texto: primário `#E8EAEE`, secundário `#9BA0AA` (`ecf-dim`), terciário `#6A6F79` (`ecf-mute`). Estado nunca só por cor: todo selo leva ícone ou texto. Sem sombras em cards. Sem gradiente decorativo.

---

## 6. Tela A — Entrada (`/mlb/anuncios`)

Referência primária: tela Stitch "ECF Admin - Publicador MLB" (`6179e11dac77472884f60d724cdec375`), reconciliada com os dados que existem. Estrutura: cabeçalho com título + busca + seletor de programa, faixa de 4 indicadores, lista densa filtrável e painel lateral "Como funciona". A sidebar do Stitch é ignorada: vale o `AppLayout` real.

**Cabeçalho (sem card, uma linha, 32px de margem lateral):**
- Esquerda: ícone DevCard `Rocket` + `h1` "Publicador Mercado Livre" (Display 24px) + apoio 13px `text-white/55`: "Publique no Mercado Livre o que o cliente preparou no Portal."
- Direita: busca de 40px (`Search`, placeholder "Buscar empresa…", largura 280px) e o **seletor de programa**.
- **Seletor de programa:** controle segmentado `role="radiogroup"` com **Polos · Incubadora · Gestão** (o Stitch mostra só dois; **Gestão é obrigatória, D23**), ordem fixa, 40px, cada opção com contagem em `tabular-nums` `text-white/40` (empresas com conta ML do programa). Container `rounded-lg border border-white/[0.08] bg-ecf-card p-1`; opção ativa em amarelo translúcido. O programa vai na URL (`?programa=polos`); sem parâmetro abre em Polos. Troca por `router.get` com `preserveState`.
- Sem os botões "Publicar em Lote" e "Filtros Avançados" do Stitch: a publicação é por produto aqui, e não há filtro avançado nos dados.

**Faixa de 4 indicadores (cards `ecf-card`, raio 12px, 16px de padding, sem sombra, 16px de gap; 4 colunas em `lg`, 2 em telas menores).** Cada card: rótulo 11px maiúsculo, número Display 24px, nota 13px `text-white/55`. Todos se referem ao programa selecionado:

| Card | Número | Nota |
|------|--------|------|
| EMPRESAS | empresas com conta ML | "com conta conectada" |
| COM DADOS DO PORTAL | empresas ligadas ao Portal | "N% sincronizado" |
| PRONTOS PARA PUBLICAR | produtos conferidos (VALIDATED) | "produtos aptos" |
| PUBLICADOS NO MÊS | anúncios publicados no mês corrente | "anúncios no ar" |

Nenhum card é amarelo (o Stitch destaca "Prontos para publicar" com borda amarela: não; acento é reservado). Clicar em "Prontos para publicar" aplica o filtro correspondente da lista. Disponibilidade dos números: Q-UI-15.

**Lista "Empresas de {Programa}" (card único, título 15px + contador mono "8 exibidas"):**

Filtros em chips de 40px (o Stitch tem "Todos / Prontos / Erro de sincronização"; adaptado ao que existe): **Todos · Prontos para publicar · Precisam de atenção** (token expirado ou "autorizada — falta reconectar") **· Nunca sincronizado**. Chip ativo em amarelo translúcido.

Tabela, linha 56px, divisor `border-white/[0.06]`, **sem fundo avermelhado em linha com problema** (o Stitch tinge a linha de vermelho; aqui o estado fica só no selo, calmo):

| Coluna (11px maiúsculo) | Conteúdo |
|-------------------------|----------|
| EMPRESA | Nome (13px) + sublinha `font-mono` 11px com o identificador (CNPJ se existir; senão CUST/ID; Q-UI-12) |
| CONTA ML | Selo de token |
| PORTAL | Selo de situação |
| PRODUTOS | "N produtos" (`tabular-nums`); "—" se zero |
| PUBLICADOS | "N anúncios" (`tabular-nums`); "—" se zero |
| (ação) | "Publicar →" e, com Portal, "Sincronizar do Portal" |

Selo de token (reuso de `TokenBadge`, 11px): `ativo` "Conectada" (verde, ponto); `expirado` "Reconectar" (âmbar translúcido); `sem_token` "Falta reconectar" (âmbar translúcido). O rótulo longo "Conta ML ativa" continua como `title`.

Selo de Portal (11px): **Sincronizado há 2 h** (verde, ícone `RefreshCw`; mono para o tempo) · **Nunca sincronizado** (azul, itálico como no Stitch) · **Sem Portal** (neutro, `Minus`; não é falha). Estado intermediário "N ofertas novas": Q-UI-2.

Ações por linha (à direita, 8px entre elas):
- "Sincronizar do Portal": secundário 40px, só com `Company` ligada ao Portal; em andamento `Loader2` + "Sincronizando…"; ao fim o selo e as contagens da linha atualizam e uma linha de status `aria-live="polite"` diz "3 produtos novos do Portal." ou "Nada novo no Portal.".
- "Publicar →": secundário (nunca amarelo; o Stitch usa amarelo e isso é contra o acento reservado). Abre a tela B. Habilitado com token, mesmo `expirado` ou conta travada (a tela B explica). Com `sem_token`, vira "copiar link de reconexão" (reuso de `RodapePolos`, que preserva o aviso de que o clique deve vir do navegador do cliente).
- A linha inteira abre a tela B (Enter também); foco com anel amarelo.

**Conta não liberada (D21):** sob o nome, selo neutro "Publicação ainda não liberada" (`Lock` 14px, `white/55`). A linha continua abrindo.

**Painel "Como funciona" (coluna direita de 320px, só em viewport ≥ 1360px; abaixo disso vira um card recolhido no fim da página):** 3 passos numerados em círculos neutros de 24px, texto 13px:
1. "Escolha o programa" — "Polos, Incubadora ou Gestão: a lista mostra as empresas com conta do Mercado Livre."
2. "Sincronize do Portal" — "Traz os produtos, títulos e preços que o cliente preencheu. Só acrescenta, nunca apaga." (selo "Portal" neutro ao lado do título)
3. "Revise, confira e publique" — "Complete o anúncio, confira no Mercado Livre e publique na conta da empresa."
O painel é dispensável ("Ocultar", preferência guardada no navegador). Sem "SLA de Integração" nem "Dica do consultor" (conteúdo inventado, sem dado por trás).

**Estados:** carregando = faixa de 4 cards-esqueleto + 8 linhas de 56px; vazios em Copywriting. Gestão lista as `Company` com token fora de Polos/Incubadora. Paginação de 50 linhas, rodapé "Mostrando 1–50 de N".

---

## 7. Tela B — Produtos da empresa

**Cabeçalho (sem card):**
- Trilha 13px `text-white/55`: "Anunciar › {Programa} › {Empresa}" (os dois primeiros são links; o atual em `text-white`).
- `h1` com o nome da empresa (Display) + selo de token + selo de Portal + selo "Publicação ainda não liberada" quando for o caso.
- À direita do título: "Sincronizar do Portal" (só com Portal) e "+ Produto" (`Plus`), ambos secundários.

**Abas `ModoAnuncioTabs` (D14):** sob o cabeçalho, ordem Meus Anúncios · **Individual** · Em massa · Histórico. "Individual" é a ativa aqui e aponta para a rota do Publicador. Para `MlbEmpresa` **sem `Company`** (D23), Meus Anúncios, Em massa e Histórico ficam **desabilitadas, não escondidas** (geometria igual entre empresas): `aria-disabled="true"`, `opacity-40`, `cursor-not-allowed`, `title="Disponível só para empresas cadastradas no sistema"`. `ModoAnuncioTabs` ganha uma prop para esse caso e passa a aceitar o identificador da empresa do Publicador. As abas **não aparecem dentro do editor**, para a mesa ficar em foco.

**Lista de produtos (tabela densa, linha 56px):**

| Coluna | Conteúdo |
|--------|----------|
| SKU | `font-mono` 13px |
| PRODUTO | Nome 13px, uma linha truncada, `title` com o nome inteiro |
| ORIGEM | Pílula neutra "Portal" (`Link2`; `title`: "Título e preço seguem o Portal") ou "Publicador" (`PencilLine`) |
| SITUAÇÃO | Selo de status (abaixo) |
| ANÚNCIOS | `LinkMl` para cada MLB publicado; "N de M" se parcial; "—" se nenhum |
| ATUALIZADO | Data relativa, `text-white/40`, `tabular-nums` |
| (ação) | "Abrir produto" (secundário) → tela C; "Começar rascunho" se não houver rascunho |

Status (semântica de `EditorRascunhoService::prontidao()` e do `STATUS_RASCUNHO` do piloto), 11px, pílula translúcida com ícone:

| Status | Rótulo | Estilo |
|--------|--------|--------|
| DRAFT | Rascunho (ou "Faltam N itens" quando a prontidão trouxer bloqueios) | neutro com ponto âmbar quando faltam itens |
| VALIDATED | Conferido | verde, `CheckCircle2` |
| PUBLISHING | Publicando | neutro com `Loader2` girando, `aria-live="polite"` |
| PUBLISHED | Publicado | verde, `CheckCircle2` |
| PARTIALLY_PUBLISHED | Parte publicada | âmbar, `AlertTriangle` |
| FAILED | Não publicado | vermelho translúcido, `AlertTriangle` |
| sem rascunho | Sem rascunho | neutro |

Com algum produto em PUBLISHING, a lista recarrega sozinha a cada 5s (`router.reload({ only: ['produtos'] })`).

**Filtro e ordem:** ordenação padrão por atualização mais recente; chips de situação (Todos · Rascunho · Conferidos · Publicados · Com problema) de 40px com contagem; busca por SKU/nome.

**"+ Produto" (D15):** modal Radix Dialog (raio 16px, fundo `ecf-card`, sem sombra) com SKU (obrigatório, mono) e Nome do produto (obrigatório). Rodapé: "Voltar" (fantasma) e "Criar e abrir" (secundário). Salva e vai direto para a tela C. SKU repetido: aviso não bloqueante (Q-UI-5).

**"Sincronizar do Portal" (D16):** idempotente, só acrescenta, sem confirmação. "Sincronizando…" → linha de status `aria-live` que some em 6s: "3 produtos novos do Portal." / "Nada novo: todos os produtos do Portal já estão aqui." Produtos novos entram no topo com realce `bg-sky-500/[0.06]` que decai em 2s. Erro em linha vermelha traduzida, sem modal.

**Rascunhos do assistente antigo (D22):** havendo `MlAnuncioRascunho` abertos, rodapé discreto fora da tabela, 13px `text-white/40`: "N rascunhos do assistente antigo ainda abertos · Abrir no assistente antigo". Sem rascunhos, nada aparece. O assistente antigo **não** tem botão, aba nem card próprio.

**Conta não liberada:** seção 9. **Estados:** esqueleto de 6 linhas; vazios e erro em Copywriting.

---

## 8. Tela C — Editor: a "mesa de anúncio" (layout novo, do zero)

Referência primária: tela Stitch "ECF Admin - Editor Publicador MLB" (`3c7f275a9e9046338d1f6d2854b19c64`), reconciliada com o motor. Composição, de cima para baixo e da esquerda para a direita:

1. **Barra superior** fixa, 56px: contexto, salvamento e ações (IA, Conferir, Publicar).
2. **Faixa de produtos** (não fixa, 48px): troca de produto sem sair.
3. **Coluna principal** de cards (fluida, até 800px).
4. **Coluna lateral de 320px**: validação no Mercado Livre e resumo do lançamento, com o botão primário de publicar no fim do resumo; fixa ao rolar.

Sem abas, sem rodapé fixo, sem lista de pendências em faixa larga. A tela é pensada para 1440px: sidebar 256px + margens 32px + principal ≈ 768px + 32px + lateral 320px.

### 8.1 Barra superior (fixa, 56px)

`sticky top-0 z-20`, fundo `#050507`, borda inferior `white/[0.08]`, sem sombra. Da esquerda para a direita:

1. **Trilha:** "Publicador MLB / {Programa}" (13px; "Publicador MLB" é link para a tela A; programa em pílula neutra de 11px, não amarela como no Stitch).
2. **Chip da empresa:** nome (13px, truncado) + identificador `font-mono` 11px + selo de conta: "ML conectado" (verde) / "Reconectar" (âmbar). É clicável: volta à tela B da empresa. Serve para nunca publicar na conta errada ao alternar entre empresas. Conta travada acrescenta `Lock` neutro (seção 9).
3. **Salvamento:** "Salvo há 12s" (`Check` verde 14px, relativo, `tabular-nums`) / "Salvando…" (`Loader2`), `aria-live="polite"`. No lugar do "Rascunho salvo sozinho" do piloto.
4. **Ações** (direita, 8px entre elas): **"Anunciar por IA"** (fantasma, `Sparkles`) · **"Conferir no Mercado Livre"** (secundário, `RefreshCw`) · **"Publicar N anúncios"** (`Rocket`; singular "Publicar 1 anúncio"; parcial "Publicar o que faltou").

Todos os botões com 40px de altura. A barra **nunca passa de 56px e nunca lista pendências**.

**Dois botões de publicar, um só amarelo sólido por vez** (o layout aprovado tem "Publicar Anúncios" na barra e "Disparar Publicação" no resumo da lateral; os dois chamam a mesma ação, com o mesmo rótulo "Publicar N anúncios" e os mesmos estados):
- **≥ 1360px (lateral visível):** o botão **primário amarelo é o do card Resumo do lançamento** (8.5); o da barra aparece em estilo **secundário** (borda `white/[0.10]`, fundo `white/[0.03]`).
- **< 1360px (lateral recolhida):** o da barra passa a **primário amarelo** e o do resumo (agora dentro do card recolhido) fica secundário.
Ver Q-UI-9.

Estados da barra:
- Salvando: "Conferir" e "Publicar" desabilitados.
- Conferindo: "Conferir" com `Loader2` + "Conferindo…".
- Publicando: "Publicar" com `Loader2` + "Publicando…"; os demais desabilitados.
- Publicado: "Conferir" e "Publicar" somem; a barra mostra "Publicado no Mercado Livre" (verde) e o botão secundário "Voltar aos produtos".
- "Publicar" (nos dois lugares) desabilitado até a conferência valer (OK, ou AVISOS com "Estou ciente" marcado), com `opacity-40`.
- Conta travada: "Publicar" visível, desabilitado, com `Lock` no lugar de `Rocket` (seção 9).
- Abaixo de 1360px: "Anunciar por IA" vira só ícone com `aria-label`; o chip da empresa encurta para o nome.

### 8.2 "Anunciar por IA" (D14)

- **Lugar:** barra superior, fantasma, à esquerda de "Conferir"; não compete com "Publicar" (único amarelo sólido).
- **Estados:**
  1. Padrão: habilitado enquanto o rascunho não estiver publicando ou publicado.
  2. Rascunho já preenchido: confirmação (Dialog 16px): "Substituir o que já está preenchido? A IA vai reescrever categoria, características, títulos e descrição. Você poderá revisar tudo depois." Botões "Manter como está" (fantasma) e "Substituir com a IA" (secundário). Rascunho vazio: sem pergunta.
  3. Em andamento (job `high`: análise → títulos → descrição → ficha → rascunho): botão desabilitado com `Loader2` + "IA preparando…" e faixa azul curta no topo da coluna principal, `aria-live="polite"`, com a etapa ("Analisando o produto…", "Escrevendo títulos…", "Montando a ficha…"). O formulário segue editável e **o que a equipe digita vence**.
  4. Concluído: faixa azul fechável "A IA preencheu N seções. Revise antes de conferir no Mercado Livre."; a mesa rola até o primeiro card com pendência.
  5. Falha: faixa vermelha translúcida "A IA não conseguiu preparar este anúncio. Nada foi alterado. Tente de novo ou preencha à mão." + "Tentar de novo".
  6. Conta sem token ou não liberada: funciona (só preenche rascunho).
- Se a fatia de variações da IA ainda não existir: "A IA não montou as variações. Defina-as no card Variações." (informativo, não erro).
- O "Regenerar com IA" por campo do Stitch (card Descrição) **não está em D14**: fora do contrato (Q-UI-14).

### 8.3 Faixa de produtos (não fixa, 48px)

Logo abaixo da barra, `border-b border-white/[0.06]`, rolagem horizontal (`overflow-x-auto`, sem barra visível). Esquerda: rótulo 13px `text-white/55` "{M} produtos" (o Stitch diz "12 produtos no Portal"; aqui há também produtos do Publicador). Depois, um **chip por produto** da mesma empresa (altura 40px, raio 10px, 8px de gap):

- Conteúdo: ponto de status + nome truncado (máx. 22 caracteres, `title` completo) + SKU `font-mono` 11px + selo de 11px com o estado: "X/8" (seções prontas, ou "Falta N"), "Conferido", "Publicando", "Publicado", "Parte publicada", "Não publicado".
- Chip ativo: `border-ecf-yellow/40 bg-ecf-yellow/10`; os demais `white/[0.03]` com borda `white/[0.08]`.
- Último chip: "+ Produto" (tracejado, abre o modal da tela B).
- Mais de 12 produtos: o fim da faixa traz "Ver todos" (popover Radix de 360px com busca por SKU/nome e a lista com selos).
- Ao trocar de chip, salva o que está pendente e navega com `router.get` (sem recarregar a página inteira). Setas do teclado entre chips.

### 8.4 Coluna principal: cards

Todos os cards: fundo `ecf-card`, borda `white/[0.08]`, raio 12px, **24px de padding**, **32px entre cards**, sem sombra. Cabeçalho do card: ícone 16px em quadrado neutro de 32px + título (15px, 700) + apoio (13px `text-white/55`) + **chip informativo à direita** (11px; ex.: "6 de 6 obrigatórios", "Total a gerar: 4 anúncios"). Cada card tem chevron para recolher (aberto por padrão; **nunca recolhe sozinho**), `aria-expanded`. Obrigatórios sempre visíveis; opcionais num sub-bloco recolhido.

**Estado do card (calmo):** completo = chip verde "Completo"; incompleto = chip neutro com ponto âmbar de 6px "Falta 1" / "Faltam N". Campo obrigatório vazio: borda âmbar de 1px e apoio em `text-white/55`. Vermelho só para valor que o servidor recusou. Nada pisca.

**Card 1 — Produto e categoria** (check "Categoria")
- Linha de cima: pílula 11px maiúscula "ITEM SINCRONIZADO DO PORTAL" (verde translúcido) ou "CADASTRADO NO PUBLICADOR" (neutra) · "SKU base" + SKU `font-mono` 13px · "Atualizado há 45 min" · à direita, **Condição** como controle segmentado compacto (Novo · Usado · Recondicionado; ativo em amarelo translúcido). No Stitch a condição é só um chip fixo; aqui é editável porque o motor a exige (`rascunho.condicao`).
- `h1` com o nome do produto (Display 24px).
- Bloco "CATEGORIA NO MERCADO LIVRE" (11px maiúsculo): caminho completo com a folha em destaque (`text-white`, 700) e o ID mono; à direita o link "Alterar categoria" (hover amarelo). Alterar abre a busca inline (campo + lista com caminho e ID, como o piloto). Aviso de migração ("Ficaram de fora na categoria nova: …") em faixa âmbar suave no card.
- A linha "Comissão ML: 16% (Clássico) / 19% (Premium)" só aparece se o servidor entregar a taxa; senão fica de fora (Q-UI-13).

**Card 2 — Ficha técnica e atributos obrigatórios** (check "Características")
- Chip "N de M obrigatórios". Apoio: "Campos exigidos pela categoria {ID}."
- **Grade de 3 colunas de "tiles"** (`md:grid-cols-3`, 16px de gap): cada atributo obrigatório é um tile editável (`CampoAtributo` reestilizado): fundo `white/[0.03]`, borda `white/[0.08]`, raio 10px, rótulo 11px maiúsculo no topo, valor 13px embaixo, `Check` verde 14px à direita quando preenchido; vazio = borda âmbar.
- Linha recolhida "Ver N atributos opcionais" (`SlidersHorizontal` + chevron; o Stitch põe a nota "+10% relevância": **não** incluir, sem dado por trás).

**Card 3 — Variações e estoque** (checks "Variações" e "Estoque, SKU e código")
- Cabeçalho: chip "Total a gerar: N anúncios" (= tipos ativos × variações ativas).
- **Resumo dos eixos** (linha): "Eixos: Cor × Tamanho · 6 combinações" + botão "Editar eixos" que abre o `EditorDeEixos` inline (reuso). Sem eixos, o card mostra uma única linha de variante (o produto simples).
- **Um card de variante por combinação** (fundo `#0B0C10`-ish `white/[0.02]`, borda `white/[0.08]`, raio 12px, 16px de padding), no padrão do Stitch "Variação 1: Cor Preto":
  - Cabeçalho: bolinha de cor (só se o eixo for cor e houver hex) + "Variação N: {rótulo da combinação}" + chips mono: SKU `{sku}` · "GTIN {gtin}" + interruptor "Ativa".
  - Linha de dados (grid `sm:grid-cols-3`): **Estoque** (número; em conta multi-depósito, um campo por depósito com o nome do depósito e total somado — D11), **SKU** (mono), **GTIN/EAN** (mono) com, quando o GTIN estiver vazio, o seletor "Motivo de não ter código" (`EMPTY_GTIN_REASON`; o Stitch só mostra EAN e não cobre isso: Q-UI-16).
  - **Chip de fotos** da variante: "Fotos OK (4)" (verde) ou "Recomendado: 4+ fotos (tem 3)" (neutro com ponto âmbar), que rola até o grupo no card Fotos. Os valores mínimo/recomendado vêm do schema (`limites`), não do texto fixo do Stitch.
  - **Preços lado a lado** (grid `md:grid-cols-2`): "Clássico" e "Premium" (cada um com seu rótulo e taxa quando houver, como o Stitch), campo "Preço de venda" com prefixo "R$" mono `tabular-nums`; vazio mostra o da Precificação como placeholder e a dica "em branco = o da Precificação". Tipo desativado: campo desabilitado, `opacity-60`. Variante já publicada: preço travado.
  - **Diferença para o Stitch:** o título (máx. 60) **não** é por variante, é por tipo de anúncio (o motor tem um título por `listing_type`): ele fica no card 5 (Q-UI-10). O faixa "Regra ECF Ativada…" do Stitch é conteúdo inventado e não entra.

**Card 4 — Fotos** (check "Fotos")
- Reuso de `FotosPorGrupo`, reestilizado como no Stitch: um bloco por **grupo de foto**, em faixas de miniaturas de 72px (raio 10px), a primeira com selo "CAPA" (amarelo translúcido, 11px) e borda `ecf-yellow/40`; as demais mostram a legenda ("Lateral", "Ajustes", etc., se existir), e o último bloco é um quadrado tracejado "+ Foto".
- **Os grupos seguem o eixo que define a foto** (`defines_picture`), que nem sempre é a cor: o título do bloco é o valor do eixo ("Preto", "Tamanho M") ou "Geral" quando não há eixo de foto. O Stitch assume "por cor" (Q-UI-11).
- Apoio de regra: "Fundo branco, {largura}×{altura}px no mínimo" tirado de `schema.limites`, nunca fixo no front. Envio em andamento por grupo mostra `Loader2` na miniatura; foto recusada mostra a mensagem do servidor em âmbar sob a faixa; opções "incluir geral" e "fotos por variante" como interruptores do piloto.

**Card 5 — Anúncios Clássico e Premium** (check "Clássico e Premium")
- Dois blocos lado a lado (`md:grid-cols-2`): **Clássico** e **Premium (12x sem juros, se a conta oferecer)**, cada um com ativo/inativo, "No ar" + `LinkMl` se o MLB já existir, **Título ML (máx. 60)** com contador mono "58/60" (verde-neutro; só vermelho se passar do máximo), "Copiar do Clássico/Premium", e a dica "vem da aba Anúncios — digite para trocar" apenas quando o produto tem `oferta_id`.
- Abaixo: botão secundário "Quanto eu recebo?" com o resultado em linha mono (`preço − tarifa − frete = você recebe`), como o piloto.

**Card 6 — Logística, dimensões e garantia** (check "Envio, garantia e embalagem")
- Chip informativo da modalidade ("Mercado Envios", "Envio próprio"…) a partir de `estado.conta.modos_envio`; o Stitch fixa "Coleta elegível", que o motor não informa (Q-UI-17).
- **Tiles de 3 colunas editáveis:** "Dimensões da embalagem" (altura × largura × comprimento), "Peso bruto" (kg) e "Garantia e despacho" (tipo + tempo + unidade). Abaixo, "Forma de envio" (select nativo) e a caixa "Oferecer frete grátis". Apoio: "É com as medidas do pacote fechado que o Mercado Livre calcula o frete."

**Card 7 — Descrição** (check "Descrição")
- Área de **texto simples** (o Mercado Livre não aceita Markdown nem HTML, RN-72): `textarea` de 8 linhas, mínimo 160px, fonte mono 13px opcional só aqui para facilitar a leitura de quebras de linha; contador de caracteres mono; apoio "Texto simples, sem telefone, e-mail ou link." Sem o seletor "Markdown Limpo", sem abas "Destaques/Ficha/Embalagem", sem "Prévia Mobile" e sem "Regenerar com IA" (fora de D14/RN-72).

### 8.5 Coluna lateral (320px, fixa)

`sticky top-[80px]` (barra 56px + 24px), `max-h-[calc(100vh-104px)] overflow-y-auto`, 24px de gap entre os dois cards. O card de validação só tem links de navegação ("Ir para…") e a caixa "Estou ciente"; o único botão é o "Publicar N anúncios" do card de resumo. Resumo e botão ficam sempre visíveis porque o card de validação rola por dentro, se for alto.

**Card "Validação no Mercado Livre"**
- Cabeçalho: título 15px + chip "X de 8 prontos" (a contagem das 8 verificações do motor).
- **Barra de prontidão** 4px (`ecf-yellow` sobre `#171A21`) com rótulo "Prontidão de envio" e o percentual mono.
- **Checklist** das 8 verificações (linhas de 40px; ícone `CheckCircle2` verde ou ponto âmbar de 6px; nome; contagem à direita): Categoria · Características · Variações · Fotos · Estoque, SKU e código · Clássico e Premium · Envio, garantia e embalagem · Descrição. Clicar leva ao card (abre e rola; `scroll-margin-top` de 80px). A linha do Stitch "Código de Barras (GTIN/EAN) Válido" é a verificação "Estoque, SKU e código".
- **Nota calma** (o "Pendente calmo" do Stitch): quando faltar algo, mostra a primeira pendência em painel neutro (`white/[0.03]`, borda `white/[0.08]`, ponto âmbar), título 13px 700 "Falta pouco" e corpo 13px com a mensagem do servidor (ex.: "A variação Azul tem 3 fotos. O Mercado Livre recomenda 4 ou mais."). Sem ícone de alerta, sem fundo âmbar sólido.
- **Linha de conferência** (resultado do servidor): "Ainda não conferido no Mercado Livre" · "Conferindo cada anúncio com o Mercado Livre…" · "Conferido: o Mercado Livre aprovou. Você já pode publicar." · "Conferido, com avisos do Mercado Livre" · "O Mercado Livre apontou N pendência(s)" · "Editado depois da última conferência. Confira de novo." · "A conferência não terminou. Tente de novo." Com AVISOS: lista dos avisos (`Problemas`) e a caixa "Li os avisos do Mercado Livre e quero publicar assim mesmo." (o "Estou ciente"). Com pendências do ML: cada uma com o link "Ir para {card}"; âmbar translúcido, nunca vermelho.

**Card "Resumo do lançamento"** (o "Resumo de Lançamento em Lote" do Stitch; aqui é de um produto, não de lote; o título é "Resumo do lançamento")
- Pares rótulo/valor (13px; valor `text-white`, `tabular-nums`):
  - Conta de destino: apelido da conta do ML.
  - Modo logístico: "Mercado Envios" / "Envio próprio" / "A combinar".
  - Anúncios Clássico: "N anúncios (R$ min–máx)".
  - Anúncios Premium: "N anúncios (R$ min–máx)".
  - Total: "N anúncios".
  - Sem "Previsão de ativação" nem "via Webhook" (conteúdo inventado).
- **Botão primário "Publicar N anúncios"** (amarelo, 40px de altura, largura total do card, `Rocket`, brilho do botão primário; "Disparar Publicação (N Anúncios)" do Stitch vira este rótulo para falar igual à barra). Linha de apoio 13px `text-white/55` abaixo: "Libera quando o Mercado Livre aprovar a conferência." ou, com pendência local, "Complete os itens da validação e confira no Mercado Livre.".
- Conta travada: o botão fica desabilitado com `Lock` e, abaixo, a nota neutra (seção 9).
- **Depois de publicar**, o card ganha o bloco de andamento (substitui o das linhas acima, que ficam recolhidas): título de estado ("Publicando…", "Publicado no Mercado Livre", "Parte foi publicada", "Não foi publicado") e uma linha por anúncio (tipo · variação · `LinkMl`, ou "na fila" / "enviando" / "confirmando…" / "não publicado"), "reenviar descrição" e `plano_b` ("confira o estoque por depósito no ML") como no piloto. Falha de publicação é o único uso de vermelho translúcido aqui.

Sem "Dica do Mentor" / "Dica do ECF" (texto inventado, sem dado por trás).

### 8.6 Responsivo
- **≥ 1360px:** principal + lateral lado a lado (como acima).
- **< 1360px:** a lateral vira um **card único no topo da coluna principal**, recolhido numa linha: "X de 8 prontos · {estado da conferência}" com chevron para expandir validação e resumo; não fica fixo. A barra superior mantém 56px; "Anunciar por IA" vira ícone.
- Grades de 3 colunas viram 2 (≥ 768px) e 1 (abaixo); os dois blocos Clássico/Premium empilham abaixo de 768px.
- Sidebar recolhida a 64px não muda a largura útil mínima. **Conferir no navegador** se algum cabeçalho fixo do `AppLayout` exige ajustar o `top` da barra (critério 8 do ROADMAP).

### 8.7 Acessibilidade do editor
- Cada card é `section` com `h3`; chevron como `button` com `aria-expanded`/`aria-controls`.
- Faixa de produtos: `role="tablist"` não (são links de navegação): lista de links com `aria-current="page"` no ativo.
- Salvamento, conferência, IA e publicação em regiões `aria-live="polite"`.
- Estado nunca só por cor; texto terciário (`white/35`) só em dicas.
- Ordem de tabulação = ordem visual: barra → faixa de produtos → cards → lateral.

---

## 9. Estado "conta não liberada" (D21) — calmo, nunca alarme

Princípio: a empresa **pode ser trabalhada** (rascunho, fotos, IA, ficha); só a publicação real está fechada. É uma etapa do fluxo, não um erro.

- **Linguagem visual:** neutra. Fundo `white/[0.03]`, borda `white/[0.08]`, ícone `Lock` 16px em `white/55`, texto `white/55`. **Sem** vermelho, **sem** âmbar, **sem** `AlertTriangle`, **sem** animação.
- **Tela A:** selo neutro "Publicação ainda não liberada" na linha.
- **Tela B:** faixa logo abaixo do cabeçalho (16px de padding, raio 12px): título 13px 700 "Publicação ainda não liberada para esta conta"; corpo 13px "Você pode preparar os rascunhos e conferir os dados normalmente. A publicação no Mercado Livre é liberada conta a conta pelo time de desenvolvimento." Sem botão e sem "fechar".
- **Tela C:** o chip da empresa na barra ganha `Lock` neutro. No card Resumo do lançamento, o botão "Publicar N anúncios" **permanece na posição**, desabilitado, com `Lock` no lugar de `Rocket`, e logo abaixo entra a nota neutra: "Publicação ainda não liberada para esta conta. Você pode preparar o rascunho e conferir os dados normalmente." O botão equivalente da barra também fica desabilitado, com `title` "A publicação é liberada conta a conta. Peça ao time de desenvolvimento." e `aria-describedby` apontando para a nota. O resto da mesa funciona normalmente. Abaixo de 1360px a nota aparece no card recolhido do topo.
- O servidor continua sendo a trava real (`RegraViolada` em `PublicacaoService::iniciar`); a UI só explica. Se uma tentativa for recusada pelo servidor mesmo assim, a mensagem aparece como a faixa neutra acima, não como erro.
- Se "Conferir" também for travado, ver Q-UI-1.

---

## 10. Copywriting (pt-BR, tom direto e calmo)

| Elemento | Texto |
|----------|-------|
| CTA primário da fase | **"Publicar N anúncios"** (barra do editor); "Publicar 1 anúncio"; "Publicar o que faltou" |
| CTA de entrada | "Publicar →" (linha da lista) |
| Ações da tela B | "Sincronizar do Portal" · "+ Produto" · "Abrir produto" · "Começar rascunho" |
| Ações do editor | "Anunciar por IA" · "Conferir no Mercado Livre" · "Alterar categoria" · "Editar eixos" · "Voltar aos produtos" |
| Título da página A | "Publicador Mercado Livre" |
| Lateral — validação | Título "Validação no Mercado Livre" · chip "{X} de 8 prontos" · "Prontidão de envio" · nota "Falta pouco" (com a pendência do servidor) · sem pendência: "Tudo pronto. Pode conferir no Mercado Livre." |
| Lateral — resumo | Título "Resumo do lançamento" · "Conta de destino" · "Modo logístico" · "Anúncios Clássico" · "Anúncios Premium" · "Total" |
| Vazio — programa sem empresa com conta ML | Título: "Nenhuma empresa de {Programa} com conta do Mercado Livre." Corpo: "Quando uma empresa do programa autorizar o Mercado Livre, ela aparece aqui." |
| Vazio — busca sem resultado | "Nenhuma empresa encontrada para “{busca}”." + "Limpar busca" |
| Vazio — empresa sem produtos (com Portal) | Título: "Esta empresa ainda não tem produtos." Corpo: "Traga os produtos que o cliente listou no Portal ou cadastre o primeiro à mão." Ações "Sincronizar do Portal" e "+ Produto" |
| Vazio — empresa sem produtos (sem Portal) | Título: "Nenhum produto cadastrado." Corpo: "Cadastre o primeiro produto para começar a anunciar." Ação "+ Produto" |
| Erro — carregar lista | "Não foi possível carregar as empresas. Atualize a página; se continuar, avise o time de desenvolvimento." + "Tentar de novo" |
| Erro — sincronizar | "Não foi possível buscar do Portal. Nada foi alterado. Tente de novo em instantes." |
| Erro — abrir produto | "Não foi possível abrir o produto." (com a mensagem do servidor quando houver) |
| Erro — token expirado | "A conta do Mercado Livre precisa ser reconectada antes de conferir ou publicar." |
| Sucesso — sincronizar | "N produtos novos do Portal." / "Nada novo no Portal." |
| Conta não liberada | seção 9 |
| Salvamento (barra) | "Salvo há 12s" · "Salvando…" |
| Confirmação da IA | "Substituir o que já está preenchido? A IA vai reescrever categoria, características, títulos e descrição. Você poderá revisar tudo depois." Botões "Manter como está" / "Substituir com a IA" |
| Confirmação de publicação | Sem diálogo extra: "Conferir" → "Li os avisos…" → "Publicar" já é a confirmação. A confirmação do usuário antes de cada `POST /items` real na #459 é regra de processo, não de tela |
| Ações destrutivas | Nenhuma nova na UI. Trocar categoria mantém o aviso "Ficaram de fora na categoria nova: …". Excluir produto: Q-UI-4 |
| Abas desabilitadas (sem Company) | `title`: "Disponível só para empresas cadastradas no sistema" |

Termos: "conta do Mercado Livre", "conferir", "rascunho", "produto", "anúncio", "seção". Não usar "oferta" nas telas internas (conceito do Portal). Nunca "erro fatal", "bloqueado" ou "negado" para conta não liberada ou campo em falta.

---

## 11. Interação, estados e acessibilidade (resumo para o executor)

- Navegação A → B → C por `router.get` do Inertia; voltar pela trilha de navegação.
- Carregamento: esqueleto em listas e `Loader2` 16px inline em ações; sem spinner de tela cheia. No editor, esqueleto da barra, da faixa de produtos e de 3 cards.
- Toda ação assíncrona desabilita o próprio botão e usa gerúndio ("Sincronizando…", "Conferindo…", "IA preparando…").
- Polling de fila (conferência, publicação, IA): 2,5s no editor, 5s na lista; limite de 4 minutos com "Ainda processando no servidor. Recarregue a página em alguns minutos para ver o resultado."
- Salvamento automático do editor com espera de 900ms (lógica do piloto); resposta do servidor nunca pisa no que está sendo digitado. O texto "Salvo há Ns" atualiza a cada 10s.
- Teclado: seletor de programa com setas; linhas focáveis; modais e popovers prendem e devolvem foco (Radix); `Esc` fecha.
- Animação mínima: `animate-spin`, rotação do chevron, largura da barra de prontidão (300ms), decaimento do realce de linhas novas. Sem ilustrações, sem emoji, sem gradiente decorativo, sem modo claro.
- A UI não menciona a #459; ela aparece como qualquer empresa da Incubadora (D20).

---

## 12. Registry Safety

| Registry | Blocos usados | Safety Gate |
|----------|---------------|-------------|
| shadcn oficial | nenhum (sem `components.json`; primitivas manuais existentes) | não se aplica |
| Terceiros | nenhum | não se aplica |

Radix já instalado e usado: `dialog` (modal "+ Produto", confirmação da IA), `popover` ("Ver todos" da faixa de produtos), `progress` (se preferir à barra manual). Nenhuma dependência nova.

---

## 13. Componentes: reuso x novo

| Componente | Situação |
|-----------|----------|
| `CampoAtributo`, `EditorDeEixos`, `GradeVariantes`, `FotosPorGrupo`, `Problemas`, `apoio.js` | **Reuso da lógica e dos campos**; rotas parametrizadas (RESEARCH §6.1) e reestilização pontual para casar com a tabela de tipografia/cor |
| `EditorPublicador.jsx` | **Não é a base do layout.** Seu estado, salvamento com espera, polling de fila e derivados (`secaoDoProblema`, prontidão, conferência, publicação) são extraídos para um hook (`usePublicador`) que alimenta a mesa nova; a composição visual do piloto não é copiada |
| `Botao`, `Campo`, `CLASSE_INPUT`, `Seletor`, `LinkMl`, `fmtReais` (`Components/Portal/Estrutura/comum`) | Reuso (puros) |
| `ModoAnuncioTabs` | Alterar: "Individual" aponta para o Publicador; prop para empresa sem `Company` desabilita 3 abas |
| `TokenBadge`, `RodapePolos` (de `AnunciosEmpresas.jsx`) | Reuso (extrair para `Components/Mlb/` se a tela B também usar) |
| `SeletorPrograma`, `IndicadoresDoPrograma`, `PainelComoFunciona`, `SeloPortal`, `SeloStatusProduto`, `AvisoContaTravada`, `ModalNovoProduto` | **Novos** |
| `BarraDoEditor`, `FaixaDeProdutos`, `CardProduto`, `CardFichaTecnica` (tiles), `CardVariacoes` + `CartaoVariante`, `CardFotos`, `CardTiposEPrecos`, `CardLogistica`, `CardDescricao`, `LateralValidacao`, `LateralResumo`, `BotaoAnunciarPorIa` | **Novos** (`Components/Publicador/Mesa/*`) |
| `PainelAnunciarIa.jsx` e `AnunciarML.jsx` (wizard antigo) | Sem mudança visual; o wizard sem entrada principal (D22); o painel de IA antigo não é reutilizado como tela |

---

## 14. Perguntas em aberto (decisão de produto, não cobertas por D12–D23)

**Layout do editor — decidido.** O usuário aprovou a tela Stitch do editor ("por enquanto serve, principalmente o editor"): barra superior, faixa de produtos, coluna de cards com um card por variação (Clássico | Premium lado a lado) e lateral com validação, resumo e botão primário de publicar. Este contrato segue essa tela. Alternativas descartadas, só como registro: coluna única sem lateral (a lateral dá visão constante da prontidão e do resumo) e mestre-detalhe com lista de produtos fixa à esquerda (comprime o formulário; a faixa horizontal já resolve a troca).

**Divergências entre o Stitch e o motor/regras (a confirmar; o contrato já adota a escolha indicada):**
- **Q-UI-9 — Dois botões de publicar** (barra e resumo). O Stitch tem os dois em amarelo; a regra de acento pede um. Adotado: o do resumo é o primário em ≥ 1360px e o da barra, secundário; abaixo de 1360px inverte. Alternativa: manter só o do resumo e tirar o da barra.
- **Q-UI-10 — Título por variação.** O Stitch mostra título (máx. 60) e preço por variação e por tipo. O motor tem **um título por tipo de anúncio** (`listing_type`) e preço por variação × tipo. Adotado: preços no card de cada variação; títulos Clássico/Premium no card 5. Se o usuário quiser título por variação, é mudança de motor, fora desta fase.
- **Q-UI-11 — Fotos por cor.** O Stitch põe as fotos dentro de cada variação de cor. O motor agrupa por eixo que define a foto (`defines_picture`), que nem sempre é cor. Adotado: card Fotos por grupo, com um chip de fotos em cada variação que leva ao grupo.
- **Q-UI-12 — Identificador da empresa.** O Stitch mostra CNPJ; `MlbEmpresa` pode não ter CNPJ. Adotado: CNPJ se houver, senão CUST/ID mono.
- **Q-UI-13 — Comissão do ML por tipo** ("16% / 19%"). Só aparece se o servidor a fornecer; senão fica de fora.
- **Q-UI-14 — "Regenerar com IA" na Descrição.** Fora de D14; adotado: sem o botão. Se quiserem, é uma IA por campo e precisa de decisão e custo.
- **Q-UI-15 — Indicadores da entrada.** "Publicados no mês" e "Prontos para publicar" dependem de contagens por programa em `pub_*`; precisam existir no controller. Se algum não for barato, trocar por um número que exista (ex.: "produtos em rascunho").
- **Q-UI-16 — GTIN/EAN.** O Stitch mostra só o EAN; o motor tem `EMPTY_GTIN_REASON`. Adotado: seletor "Motivo de não ter código" quando o GTIN está vazio.
- **Q-UI-17 — "Mercado Envios Coleta elegível".** O motor não informa a elegibilidade de coleta; adotado o chip só com a modalidade que o servidor devolver.
- **Q-UI-18 — Itens inventados do Stitch ignorados:** "SLA de Integração MLB", "Dica do Consultor/Mentor", "Regra ECF Ativada…", "Prévia Mobile", Markdown na descrição (o ML aceita só texto simples, RN-72), "Previsão de ativação via Webhook", "+10% relevância", "Publicar em Lote", "Filtros Avançados". Confirmar que podem ficar de fora.

**Demais perguntas:**

- **Q-UI-1 — A trava de conta (D21) fecha também "Conferir no Mercado Livre"?** O contrato assume que **só a publicação** é travada e a conferência (validate, leitura) segue livre. A memória do projeto diz que conta de cliente tem no máximo leitura/validate "com 'pode'". Se a conferência também for travada: botão "Conferir" desabilitado com a mesma razão calma e faixa "Conferência ainda não liberada para esta conta".
- **Q-UI-2 — Quarto selo "N ofertas novas" no Portal.** D13 lista sincronizado / nunca sincronizado / sem Portal. Desenhado: quarto selo azul "N ofertas novas" quando houver oferta nova desde a última sincronização, e o botão "Sincronizar do Portal" aparece nos dois estados. Alternativa: manter "Sincronizado" e deixar o botão sempre disponível. (Mesma pergunta da Q7 da pesquisa.)
- **Q-UI-3 — Abas Meus/Massa/Histórico sem `Company`:** contrato escolhe **desabilitar** com explicação. Alternativa: esconder, deixando "Individual" sozinha.
- **Q-UI-4 — Excluir/arquivar produto cadastrado no Publicador.** Nenhuma decisão cobre. Este contrato **não desenha** exclusão nesta fase. Se entrar, é ação destrutiva: "Excluir produto: o produto e o rascunho serão removidos. Anúncios já publicados no Mercado Livre não são afetados.", botão em vermelho translúcido, só para produto sem publicação.
- **Q-UI-5 — SKU repetido numa mesma empresa.** Assumido: aviso não bloqueante (alinha com Q6 da pesquisa). Confirmar.
- **Q-UI-6 — Quem aparece em Gestão.** Assumido: `Company` com token ML fora de Polos e Incubadora. Uma `Company` ligada a Polos/Incubadora aparece só no programa dela (Q8 da pesquisa).
- **Q-UI-7 — Produtos vindos do Portal: nome e SKU seguem a oferta ao vivo** (recomendado na pesquisa e assumido aqui; D16 garante título planejado e preço). Se forem copiados uma vez, trocar a dica "Título e preço seguem o Portal" por "Importado do Portal".
- **Q-UI-8 — Cards abertos ao entrar** (como no Stitch; nada recolhe sozinho). Alternativa: cards completos entram recolhidos com resumo de uma linha.

---

## 15. Checker Sign-Off

- [x] Dimension 1 Copywriting: PASS
- [x] Dimension 2 Visuals: PASS
- [x] Dimension 3 Color: PASS
- [x] Dimension 4 Typography: PASS (revisão 1: 24/15/13/11px, pesos 400/700)
- [x] Dimension 5 Spacing: FLAG não bloqueante — exceções nomeadas na seção 3
- [x] Dimension 6 Registry Safety: PASS

**Aprovação:** aprovado em 2026-10-02 (gsd-ui-checker, 2ª verificação)
