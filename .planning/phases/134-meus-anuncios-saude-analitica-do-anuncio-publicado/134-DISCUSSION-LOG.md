# Phase 134: "Meus Anúncios" — saúde analítica do anúncio publicado - Discussion Log

> **Trilha de auditoria apenas.** Não usar como entrada para agentes de pesquisa, planejamento ou execução.
> As decisões estão em `134-CONTEXT.md` — este log preserva as alternativas consideradas.

**Date:** 2026-08-10
**Phase:** 134-"Meus Anúncios" — saúde analítica do anúncio publicado
**Areas discussed:** Acervo, Frescor, Leitura, Navegação

---

## Colocação da fase no roadmap

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Fase 134, tail solta | Anexada ao fim do ROADMAP.md como fase independente, fora da v22.0 | ✓ |
| Nova milestone v23.0 | Milestone própria para evolução do módulo Anunciar ML, com REQUIREMENTS-v23.md | |
| Dentro da v22.0 | Numera como 134 e declara parte de Administrativo + Clicksign | |

**User's choice:** Fase 134, tail solta
**Notes:** Administrativo/Clicksign é outro domínio; misturar sujaria o coverage map e a auditoria de milestone. Segue a convenção de anexar que o roadmap usa desde a v18.

---

## Acervo — o que a tela lista

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Conta inteira, marcando origem | Lista todos os itens da conta ML e sinaliza quais nasceram no ECF | ✓ |
| Só o que o ECF conhece | Cruza MlAnuncioRascunho publicado + Publicacao | |
| Só o que este módulo publicou | Apenas MlAnuncioRascunho status=publicado | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Por empresa, igual às outras | Mesma âncora {company}, entra pelo painel de cards | ✓ |
| Por empresa + sinal no painel de cards | Card da empresa mostra indicador de saúde | |
| Você decide | A cargo do planner | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Só ativos por padrão, resto por filtro | status=active default | ✓ |
| Ativos + pausados | Pausado entra por padrão por ser o problema-alvo | |
| Tudo, paginado | Ativo, pausado e encerrado desde o começo | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| 3 origens: ECF · time · cliente | Dois joins; traz vendas_qty e desconsiderado de Publicacao | ✓ |
| Binário: ECF × resto | Um join a menos | |
| Sem selo por ora | Trata todo anúncio igual | |

**User's choice:** conta inteira com selo de 3 origens, por empresa, ativos por padrão
**Notes:** A escolha de "conta inteira" tornou o join com `Publicacao` obrigatório, não opcional — é o que distingue anúncio do time de anúncio legado do cliente. O indicador por empresa no painel de cards foi descartado por exigir coleta de todas as empresas.

---

## Frescor — como a métrica do ML chega

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Snapshot em tabela + comando agendado | Tela lê só do banco; botão "Atualizar agora" enfileira job | ✓ |
| Híbrido: snapshot + refresh por anúncio | Lista do snapshot, item individual ao vivo | |
| Ao vivo com cache | Busca na hora, cache 10-30min, zero migration | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Diário, junto do ml:sync | D-1, padrão do resto do sistema | ✓ |
| 2x ao dia | Pega problema que surge durante o dia | |
| Só sob demanda | Nada agendado | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Estado atual + série enxuta com retenção | Upsert + série diária dos campos de tendência, ~90d | ✓ |
| Só o estado atual | Uma linha por anúncio, sobrescrita | |
| Série diária completa | Uma linha por anúncio por dia, com tudo | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Último snapshot com selo de defasagem | Nunca tela em branco | ✓ |
| Selo + aviso separado para token caído | Distingue lentidão de integração quebrada | |
| Você decide | A cargo do planner | |

**User's choice:** snapshot agendado diário, estado corrente + série enxuta, degradação com selo
**Notes:** O padrão de HTTP síncrono por empresa já levou `/dashboard` a 124s neste projeto — foi o argumento decisivo contra a busca ao vivo. A série enxuta existe porque "evolução" era queixa explícita do pedido original.

---

## Leitura — o que a tela responde primeiro

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Triagem acionável | "N anúncios precisam de você", agrupados por motivo, clicáveis | ✓ |
| Placar + distribuição, detalhe no drawer | Overview first, no padrão do Painel Polos | |
| Performance primeiro | Visitas, conversão e vendas no topo | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| As duas, lado a lado | health do ML + nota ECF de mlAnuncioRegras | ✓ |
| Só o health do ML | Não inventa régua nova | |
| Só nota ECF | Régua própria, independe da API | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Só leitura + atalhos | Permalink no ML + rascunho no wizard; zero write | ✓ |
| Leitura + marcação local | Marcar como tratado/ignorar, sem tocar no ML | |
| Você decide | A cargo do planner | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Por gravidade do problema | Determinística, funciona sem visitas | ✓ |
| Por impacto estimado | Muita visita e pouca venda no topo | |
| Por recentes | Igual ao Histórico atual | |

**User's choice:** triagem acionável no topo, saúde dupla, só leitura, ordem por gravidade
**Notes:** Antes da pergunta sobre saúde foi levantada a pegadinha do `nps_medio` ≠ `pontos_componentes.nps` — nota exibida que não fecha com a própria conta. A escolha pela nota dupla carrega a obrigação de a conta fechar.

---

## Navegação — abas e os Rascunhos

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| 4ª aba, e Meus Anúncios é a inicial | Histórico sobrevive; nova ordem na barra | ✓ |
| Meus Anúncios absorve o Histórico | 3 abas; lote vira filtro interno | |
| 4ª aba no fim, ordem atual mantida | Mudança mínima | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Sub-abas: Publicados \| Rascunhos | Estados com colunas diferentes; Publicar lote migra junto | ✓ |
| Uma lista só, rascunho como status | Visão de funil unificada | |
| Seção de rascunhos no topo | Tudo numa rolagem só | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Continua admin-only, sem menu | Fase não mexe no gate | ✓ |
| Abre para o time de publicação | Troca para permission:mlb.anunciar | |
| Admin-only agora, construído para abrir | Query já respeita responsavel_id | |

| Opção | Descrição | Selecionada |
|-------|-----------|-------------|
| Saúde do wizard fica intacta no aside | Só o bloco de Rascunhos sai | ✓ |
| Fica e ganha o espaço liberado | Mesma lógica, mais legível | |
| Você decide | A cargo do UI-phase | |

**User's choice:** 4 abas com Meus Anúncios como inicial, sub-abas Publicados/Rascunhos, admin-only, Saúde do wizard intacta
**Notes:** O Histórico sobrevive por ser a base do "Anunciar semelhante em massa" (`duplicarLoteComoTemplate`, Phase 86) — absorvê-lo traria risco de regressão sem ganho proporcional.

---

## Claude's Discretion

- Nomes de tabela/migration, comando artisan e job.
- Throttle e paginação da coleta (replicar o padrão de delay escalonado de `publicarLote`).
- Layout fino e escolha de gráfico da série temporal — a cargo do `/gsd:ui-phase`.

## Deferred Ideas

- Write na API do ML (pausar/editar/mover anúncio) — fase própria, alinhada ao todo `260626-acoes-ml-mover-sgi-pausar-via-api.md`.
- Abrir o módulo ao time de publicação (`role:admin` → `permission:mlb.anunciar`).
- Indicador de saúde por empresa no painel de cards de `/mlb/anuncios`.
- Marcação local de "já tratado" / "ignorar".
- Meus Anúncios absorver o Histórico.

## Zonas cinzentas levantadas e deixadas para pesquisa

Ao fechar a discussão foi oferecida uma rodada extra sobre **variações e catálogo**; o usuário optou por seguir para o CONTEXT. As duas foram registradas como perguntas em aberto A-01 e A-02 no `134-CONTEXT.md` para serem resolvidas com dado real no `134-RESEARCH.md`, não assumidas pelo planner.
