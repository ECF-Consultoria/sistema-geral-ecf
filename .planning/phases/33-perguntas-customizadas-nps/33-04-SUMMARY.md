---
phase: 33-perguntas-customizadas-nps
plan: 04
subsystem: nps
tags: [nps, frontend, react, dialog, modal, perguntas-customizadas, lista-respostas]
requires: [phase-33-plan-01]
provides:
  - ui: modal "Abrir" em /nps lista com todas respostas (3 notas 1-5 + comentario + respostas extras)
  - component_local: NotaCard (label + valor 1-5 colorido, fallback —)
  - component_local: RespostaExtraValor (renderiza valor por tipo: escala_1_5, sim_nao, multipla, texto)
  - ux: botao olho (Eye lucide) so em linhas com status=completed
affects:
  - resources/js/Pages/Nps/Index.jsx
tech-stack:
  added: []
  patterns:
    - "Modal Dialog shadcn padrao do app (Radix UI primitives) com bg-ecf-card dark"
    - "Helpers locais (NotaCard/RespostaExtraValor) no mesmo arquivo do consumer — padrao Sugadores/Index.jsx do projeto"
    - "Le snapshot pergunta_texto (defesa contra edit/delete posterior da pergunta)"
    - "Payload flat (s.score_estrategista, s.comment, ...) — controller Plan 33-01 ja entrega pronto"
key-files:
  created: []
  modified:
    - resources/js/Pages/Nps/Index.jsx (import Eye, state modalSurvey, botao Abrir, helpers NotaCard+RespostaExtraValor, Dialog modal)
decisions:
  - "Botao Abrir aparece na coluna Link existente (nao cria nova coluna) — preserva header da tabela; status=pending mantem botao copy do link, status=completed ganha botao olho"
  - "NotaCard renderiza '—' quando score=null/undefined (analista em mentoria pura) — sem cores neutras, white/30"
  - "RespostaExtraValor formata por tipo (snapshot) com 4 variantes: escala_1_5 colorida 1-5 com '/5', sim_nao badge verde/vermelho com chk/x, multipla chip neutro com texto da opcao, texto whitespace-pre-wrap"
  - "Modal header usa s.company_name (controller ja achata) e s.completed_at (string formatada d/m/Y H:i pelo controller) — nao precisa formatDate helper"
  - "Bloco 'Respostas extras' so renderiza se array length>0 — survey antiga (sem perguntas custom) nao mostra bloco vazio"
  - "Pergunta_texto vem do snapshot (r.pergunta_texto = pergunta_texto_snapshot do model) — defesa contra pergunta editada/hard-deleted"
metrics:
  duration: ~8min
  completed_date: 2026-06-12
  tasks: 1 commit atomico
  files: 1 modificado (resources/js/Pages/Nps/Index.jsx)
  tests_added: 0 (UI puro — coberto por testes manuais smoke)
  tests_passing: 27/27 (Phase 31+33 backend zero regressao)
---

# Phase 33 Plan 04: Modal "Abrir" em /nps com todas respostas

## One-liner

Botao olho na coluna Link de cada linha respondida (`status=completed`) abre modal de 2 colunas com 3 NotaCards 1-5 (Estrategista/Analista/Empresa), comentario livre e respostas extras formatadas por tipo (escala/sim_nao/multipla/texto) — payload ja vinha pronto do controller (Plan 33-01), so consumo no JSX.

## O que foi entregue

### Botao "Abrir" na lista

Em `resources/js/Pages/Nps/Index.jsx`, na coluna `Link` da tabela:

- **`status === 'pending'`** → mantem botao copy do link (`LinkIcon`) — pre-existente.
- **`status === 'completed'`** → NOVO: botao `<Eye />` (7x7, hover bg-white/[0.06]) com title="Ver respostas". `onClick` seta o state `modalSurvey = s` que dispara o Dialog.

Sem nova coluna na tabela — header preservado. Survey `expired` nao tem nenhum botao (logica nao mudou).

### Modal Dialog (3 blocos)

`<Dialog open={!!modalSurvey} onOpenChange={(o) => !o && setModalSurvey(null)}>` com `<DialogContent className="max-w-2xl bg-ecf-card border border-white/[0.08]">`. Conteudo:

**Header:**
- DialogTitle: `{company_name} — Resposta NPS`
- Subtitulo cinza claro: `{respondent || 'Respondente nao informado'} · {completed_at}`

**Bloco 1 — Notas** (grid de 3 colunas):
3 `<NotaCard>` (Estrategista/Analista/Empresa). Componente local:
- Aceita `label` e `valor` (1-5 ou null/undefined).
- `valor = null/undefined` → renderiza `—` em `text-white/30` (analista em mentoria pura).
- Cor por nota: `1-2 = text-rose-400`, `3 = text-yellow-400`, `4-5 = text-emerald-400`.
- Format `{valor}/5` com `/5` em fonte menor cinza.

**Bloco 2 — Comentario:**
Card retangular `bg-white/[0.03]` com `whitespace-pre-wrap break-words` (preserva quebras de linha do textarea original). Se `comment` vazio: `<span className="italic text-white/30">Nao informado</span>`.

**Bloco 3 — Respostas extras** (condicional — so renderiza se `respostas_customizadas.length > 0`):
Lista vertical de cards, 1 por resposta:
- Header da pergunta em `text-xs text-white/60` (snapshot `pergunta_texto`).
- Valor formatado pelo helper `<RespostaExtraValor tipo={r.tipo} valor={r.valor} />`.

`<RespostaExtraValor>` formata por tipo (snapshot):
- `escala_1_5` → `{n}/5` em fonte 18 colorida 1-5 (mesma escala dos NotaCards).
- `sim_nao` → badge `inline-flex` com border colorida e icone `✓`/`✗`. Verde para `sim`, rosa para `nao`.
- `multipla` → chip cinza neutro `bg-white/[0.06] border border-white/[0.08] text-white/80` com texto da opcao.
- `texto` (ou fallback) → paragrafo `text-sm text-white/80 whitespace-pre-wrap break-words` (preserva linhas). Vazio cai em italic "Nao informado".

**Footer:**
Botao "Fechar" em `bg-white/[0.08]` que faz `setModalSurvey(null)`.

### Payload consumido (Plan 33-01 ja entrega)

Cada survey do paginate `surveys.data` ja tem (descoberto lendo `NpsController::index`):

```
{
  id, token, company_name, company_id, status,
  auto_generated, generated_by, created_at, expires_at,
  completed_at,           // 'd/m/Y H:i' formatado pelo controller
  score_estrategista,     // 1-5 ou null
  score_analista,         // 1-5 ou null (mentoria pura)
  score_empresa,          // 1-5 ou null
  respondent,             // nome ou null
  comment,                // texto ou null
  link,                   // route('nps.respond', token)
  respostas_customizadas: [{
    id, pergunta_id,
    pergunta_texto,       // SNAPSHOT (pergunta_texto_snapshot do model)
    tipo,                 // SNAPSHOT (tipo_snapshot do model)
    valor,                // string raw da resposta
  }, ...]
}
```

**Importante:** o payload e FLAT — campos NAO estao em `s.response.*` como o prompt do plan sugeria. O controller (Plan 33-01) ja achata via `'score_estrategista' => $s->response?->score_estrategista` etc. Isso simplifica o consumo no JSX.

## Decisoes Made

Listadas no frontmatter `decisions`. Destaques:

- **Botao Abrir reusa a coluna Link** — nao quebra o layout da tabela (10 colunas). Pendente mostra copy-link, respondida mostra olho. Expired sem botao.
- **Cores 1-5 alinhadas com `RatingPicker` do Respond.jsx** (Phase 31 Plan 03): `red/orange/yellow/lime/emerald`. Plan 04 simplifica para 3 zonas (rose <= 2, yellow = 3, emerald >= 4) por ser display compacto no card.
- **Snapshot e fonte canonica** — leio `r.pergunta_texto` (que ja vem do `pergunta_texto_snapshot`), nunca tento resolver via `pergunta_id` (que pode ser null se a pergunta foi hard-deleted).
- **`whitespace-pre-wrap` em comentario e em respostas texto** — cliente escreve com quebras (textarea), preservo no display. Sem isso, ficaria tudo em 1 linha.
- **Modal so abre quando `modalSurvey != null`** — `open={!!modalSurvey}` e `onOpenChange={(o) => !o && setModalSurvey(null)}` mantem o pattern Radix de "controlled dialog" sem precisar de useEffect.
- **Sem nova columa, sem mudanca de header** — minha alteracao no JSX e additive. Tela funciona EXATAMENTE como antes para quem nao clicar no olho.

## Commits

- `5cb7ad1` — feat(33-04): adiciona modal Abrir em Nps/Index com todas respostas

## Deviations from Plan

**Auto-fixed (Rule 1 — Bug / Rule 2 — Missing critical):**

1. **[Rule 1 - Bug] Payload FLAT (nao aninhado em `s.response`)** — Plan e prompt do usuario diziam para ler `modalSurvey?.response?.score_estrategista` / `modalSurvey?.response?.respondent_name` / `modalSurvey?.response?.respondida_em`. Ao ler o `NpsController::index` (linhas 69-98), confirmei que o payload e FLAT por design — controller acessa `$s->response?->score_estrategista` e expoe direto em `'score_estrategista' =>`. Mesmo padrao para `comment`, `respondent`, `completed_at`. **Nao precisei mexer no controller** — paylod completo, so consumi corretamente no JSX. Sem essa correcao, todos os campos do modal renderiam `undefined` (bug funcional silencioso).

2. **[Rule 2 - Missing critical] Sem helper `formatDate` necessario** — Plan sugeria importar `formatDate` para formatar `response.respondida_em`. Mas `s.completed_at` ja vem **pre-formatado** pelo controller (`'d/m/Y H:i'`). Nao precisei do helper — apenas concateno `· {modalSurvey.completed_at}`. Reducao de imports.

3. **[Rule 2 - Missing critical] `whitespace-pre-wrap break-words` nos comentarios e respostas texto** — Plan nao mencionava, mas usuario respondeu em textarea (multi-linha possivel via Phase 31 Plan 03). Sem isso, quebras de linha viram espacos e palavras longas explodem o modal. Aplicado em 2 sites (comentario + RespostaExtraValor tipo=texto).

4. **[Rule 2 - Missing critical] key prop usa `r.id ?? idx`** — `respostas_customizadas` tem `id` real (vem do DB), mas defensivamente caio em `idx` se algum item nao tiver id (nao deve acontecer com o payload atual mas e safeguard).

5. **NAO precisou ajustar `NpsController::index`** — payload ja estava completo apos Plan 33-01. Confirmei lendo o controller direto: linhas 80-84 expoem `score_estrategista/analista/empresa`, linhas 83-84 expoem `respondent/comment`, linha 79 expoe `completed_at`, linhas 87-97 expoem `respostas_customizadas`. Zero mudanca PHP — 1 commit so com Index.jsx.

**Nenhuma deviation arquitetural (Rule 4) — tudo dentro do escopo "consumir no JSX o payload do controller".**

## Como verificar

- `/nps` no browser:
  - Linha respondida (`status=completed`) tem botao olho na coluna Link.
  - Linha pendente (`status=pending`) mantem botao copy-link.
  - Linha expirada (`status=expired`) sem botoes.
- Click no olho:
  - Modal abre com nome empresa + respondente + data.
  - 3 NotaCards: notas com cor 1-5, "—" se analista null (mentoria pura).
  - Comentario: texto em card cinza ou "Nao informado" italic.
  - Respostas extras: so aparece se houver; cada pergunta com valor formatado por tipo.
- Click fora ou no botao "Fechar" fecha o modal.
- Survey sem respostas custom (legacy ou cliente nao respondeu nada extra) -> bloco "Respostas extras" oculto.

## Self-Check

- [x] `resources/js/Pages/Nps/Index.jsx` modificado (1 file changed, 142 insertions(+), 1 deletion(-))
- [x] Commit `5cb7ad1` no git log
- [x] `npm run build` verde (34.97s)
- [x] Suite Phase 31+33 backend = 27/27 verdes (151 assertions) — zero regressao
- [x] Controller `NpsController::index` NAO precisou ajustar (payload completo)
- [x] Import `Eye` adicionado de `lucide-react`
- [x] State `modalSurvey` adicionado
- [x] Helpers `NotaCard` e `RespostaExtraValor` no escopo do modulo
- [x] Botao Abrir condicional em `status === 'completed'`
- [x] Modal Dialog com 3 blocos (Notas / Comentario / Respostas extras)

## Self-Check: PASSED

## Threat Flags

Nenhuma — UI puro, sem novos endpoints, sem mudancas de auth, sem novos network calls.

---

*Phase: 33-perguntas-customizadas-nps*
*Plan: 04 — Modal Abrir em /nps lista*
*Completed: 2026-06-12*
