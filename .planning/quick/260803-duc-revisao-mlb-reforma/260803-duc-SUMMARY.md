---
quick_id: 260803-duc
slug: revisao-mlb-reforma
date: 2026-08-03
status: complete
commits:
  - 64a2173c feat(revisao) modelo de eventos (mlb_revisoes + mlb_pendencias)
  - 444a4bb4 feat(revisao) maquina de estados + backend em dois modos
  - 884c8e70 feat(revisao) tela nova (Fila e Supervisao)
  - 3863059b feat(revisao) score e Meu Painel leem pendencias
  - 7a434fd3 fix(revisao) desfazer aprovacao nao saia do lugar
---

# Quick 260803-duc — Reforma do módulo de Revisão MLB

## O que mudou

A revisão deixou de ser um punhado de flags na linha da publicação e passou a ser
um modelo de eventos com veredicto, autoria e ciclo fechado.

### Vocabulário

| Antes | Agora |
|---|---|
| `revisado` (bool, sem autor) | **Aprovado** / **Em ajuste** / **Reconferir** / Não revisado |
| "problema" do anúncio | Pendência · severidade `bloqueio` |
| "comentário" / "feedback" | Pendência · severidade `observacao` |
| — | Pendência · severidade `ajuste` (faixa do meio, que não existia) |

"Revisado" deixou de ser estado porque revisar não quer dizer que está correto —
era essa a leitura errada que o gestor fazia.

### Máquina de estados

```
nao_revisado ──┬── aprova ───────────────→ aprovado
               └── abre pendência ───────→ em_ajuste   (bola: publicador)
                                               │ publicador marca "Corrigi"
                                               ↓
                                          reconferir   (bola: líder)
                                       ┌───────┴────────┐
                                  confirma           reabre
                                       ↓                ↓
                                   aprovado         em_ajuste
```

O passo de reconferência não existia: o publicador dava o problema como resolvido
e ninguém conferia.

## Entregas

**Dados** — `mlb_revisoes` (trilha de transições com revisor e data) e
`mlb_pendencias` (severidade, categoria, texto, abertura, correção, resolução).
Cache em `mlb_publicacoes`: `status_revisao`, `revisado_por`, `revisado_em`,
`pendencias_abertas`. Status como `string`, não enum.

**Backfill** — 149 pendências criadas a partir de `problema`/`comentario`, com a
autoria histórica recuperada do `activity_log` (o campo "quem abriu o problema"
nunca existiu como coluna). No banco local: 440 aprovados, 85 em ajuste,
1.408 não revisados; 105 das 149 pendências nasceram com autor.

**Serviço** — `RevisaoService` centraliza as transições, registra cada evento e
recalcula o cache a partir das pendências reais. Faz dual-write nas colunas
legadas, então Histórico, Empresas, Publicações e Dashboard seguem funcionando.

**Tela** — `/mlb/revisao` reescrita, dois modos:
- *Fila* (líder): **três colunas por estado** — Não revisado / Em ajuste (bola com o
  publicador) / Reconferir (bola com você). Agrupa pelo que precisa acontecer, não
  pela loja. Cada coluna traz o total real, carrega no máximo 50 cards e declara o
  corte ("mostrando 50 de 306 — ver todos", que cai na lista paginada daquele estado).
  Aprovar / Pendência por card, aprovação em lote, severidade e idade visíveis.
- *Gráfico* (gestor): cobertura da competência, quem revisou o quê e quando,
  aging (até 2d / 3–7d / +7d), tempo médio de resolução, reaberturas e em que
  categoria o time mais erra.

O layout de linha larga foi descartado a pedido do usuário: a coluna da loja era
`flex-1` e o nome se repetia em toda linha sob um cabeçalho que já dizia a loja —
sobrava um vazio no meio. Quatro tratamentos foram comparados em mockup antes da
escolha (linha compacta, tabela densa, grade de cards, colunas por estado).

**Integrações** — eixo Qualidade do `PublicadorScoreService` lê pendências
(bloqueio pendente / pendências resolvidas), mantendo a régua 60-40. Meu Painel
lista pendências com severidade, categoria, idade e autor; "Marcar resolvido"
virou "Corrigi" e devolve para reconferência.

## Defeitos corrigidos no caminho

1. **Filtro de cargo não filtrava** — `whereHas('cargos')` consultava o catálogo
   de cargos do setor, sempre verdadeiro para qualquer membro de Publicação.
2. **KPIs mentiam** — eram contados sobre os 120 registros carregados. Agora
   contam no banco, com paginação real.
3. **`DATE_FORMAT` no WHERE** descartava o índice de `data` — trocado por range.
4. **Desfazer aprovação não saía do lugar** — `sincronizar()` decidia por
   "existe algum veredicto" e a aprovação antiga ressuscitava o estado.
5. **`idade_dias`** truncava float e emitia deprecation por linha listada.

## Decisão de produto registrada

O recorte por cargo **não** é aplicado na listagem. Com o filtro corrigido, mantê-lo
faria 1.146 de 1.933 publicações sumirem da revisão no banco local (só 5 usuários
têm cargo `publicador` no pivot). Um anúncio precisa ser revisado independentemente
do cargo que o autor tem hoje ou de ele ter saído. A segmentação por pessoa virou
filtro — escolha explícita, não omissão silenciosa.

## Verificação executada

- Ciclo completo das transições, incluindo reabertura e dupla reversão: correto,
  com dual-write consistente nas colunas legadas em cada passo.
- Ambos os modos da tela renderizados via Inertia: Fila (1.290 na competência,
  40,6% de cobertura, 766 na fila paginados de 60 em 60) e Supervisão (aging,
  tempo médio 57,5h, 15 pendências mais antigas).
- Regressão das telas vizinhas (Histórico, Publicações, Empresas, Dashboard,
  Vendas) e dos 4 adaptadores de rota antiga: todos OK.
- Score do publicador: eixo Qualidade 68,5 (94,5% sem bloqueio · 29,6% resolvidas).
- `npm run build` OK.

## Pendente / não feito

- **Não deployado** — exige autorização explícita.
- Autoria de revisão histórica veio vazia no local porque o `activity_log` local
  tem 31 registros de teste. Em produção o backfill deve recuperar de verdade —
  conferir após migrar.
- Colunas legadas de `mlb_publicacoes` mantidas em dual-write; remover só depois
  de validar em produção.
- "Problema da conta" (`mlb_empresas.problema`) segue com o nome antigo — renomear
  para "ocorrência da conta" ficou fora de escopo.
- Publicações e Meu Painel ainda chamam as rotas antigas (via adaptadores);
  migrar para os endpoints novos é o próximo passo natural.
- WIP anterior preservado em `git stash@{0}` ("wip revisao pre-reforma (260803-duc)").
