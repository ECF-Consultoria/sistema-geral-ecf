---
phase: 126-client-clicksign-pdf-do-contrato-v22-0
plan: 08
subsystem: docs
tags: [clicksign, docx, decisao-produto, checkpoint]

# Dependency graph
requires:
  - phase: 126-client-clicksign-pdf-do-contrato-v22-0 (plano 126-04)
    provides: "ContratoPdfService::montarDados() como fonte de valores das variáveis"
provides:
  - "D-19: um envelope por empresa, servicos_snapshot vira {{servico_contratado}} concatenado"
  - "D-20: rodapé do .docx nomeia literalmente os 4 signatários do arranjo D-08 (sem variável)"
  - "126-VARIAVEIS-DO-MODELO.md §4 — Lista final: tabela definitiva de variáveis + o que fica literal"
affects: [126-09, 126-11, 127]

# Tech tracking
tech-stack:
  added: []
  patterns: []

key-files:
  created: []
  modified:
    - .planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-CONTEXT.md
    - .planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-VARIAVEIS-DO-MODELO.md

key-decisions:
  - "D-19 (opção B): servicos_snapshot concatenado numa variável só, um envelope por empresa — não um contrato por serviço, não um modelo por serviço, não tabela em loop"
  - "D-20 (opção B): rodapé nomeia literalmente os 4 signatários do arranjo D-08 (2 sócios como contratada, Comercial como testemunha), não variável, não rodapé genérico"

requirements-completed: [PDF-01, PDF-02]

# Metrics
duration: ~25min
completed: 2026-08-10
---

# Phase 126 Plan 08: Decisões D-19/D-20 e lista final de variáveis Summary

**Duas decisões de produto (Tasks 1 e 2, checkpoints respondidos pelo usuário) fecham como o `.docx` da Clicksign representa contrato com N serviços e quem aparece nomeado no rodapé — registradas como D-19/D-20 em `126-CONTEXT.md`, com a lista definitiva de 10 variáveis do modelo fechada em `126-VARIAVEIS-DO-MODELO.md` §4.**

## Performance

- **Duration:** ~25 min (Task 3 apenas — Tasks 1 e 2 foram checkpoints respondidos pelo usuário em sessão anterior)
- **Completed:** 2026-08-10
- **Tasks:** 3/3 completas (1 e 2 = checkpoint:decision respondidos; 3 = auto, executada nesta sessão)
- **Files modified:** 2

## Accomplishments

- **D-19 registrada** (`126-CONTEXT.md`, bloco de revisão): o usuário escolheu a opção B — serviços concatenados numa variável só (`{{servico_contratado}}`), um envelope por empresa, independente de quantos serviços ela tenha. As opções A (um contrato por serviço, custaria 30 chamadas contra a janela medida de 20/min), C (um modelo por serviço, manutenção manual em N arquivos) e D (tabela em loop, único caminho NÃO MEDIDO) ficam registradas como recusadas, com o motivo de cada uma.
- **Consequência da D-19 documentada explicitamente**: valor e vigência **por serviço** somem do documento — sobra só o total (`{{valor_mensal}}`) e a vigência consolidada (`{{vigencia_inicio}}`/`{{vigencia_fim}}`, variáveis que a proposta original nem tinha e que entraram agora por decorrência direta da D-19). As cláusulas 1 e 2 do contrato real citam "Mercado Livre" no corpo e precisam ser reescritas de forma genérica no `.docx` — instrução acrescentada ao §3 (passo 4) do `126-VARIAVEIS-DO-MODELO.md`.
- **D-20 registrada** (`126-CONTEXT.md`, bloco de revisão): o usuário escolheu a opção B — o rodapé nomeia literalmente (sem `{{variável}}`) os 4 signatários do arranjo da D-08: dois sócios como parte CONTRATADA, o Comercial como TESTEMUNHA, o cliente como CONTRATANTE. As opções A (rodapé genérico, perde identificação visual) e C (nomes como variável, mais um ponto de divergência) ficam registradas como recusadas.
- **Desalinhamento documentado**: no contrato real um dos sócios (Emerson) está nomeado como testemunha; no arranjo da D-08 ele assina como parte CONTRATADA. O §3 (passo 6) do `126-VARIAVEIS-DO-MODELO.md` avisa explicitamente para seguir o papel da D-08, não o do contrato antigo, ao escrever o rodapé.
- **Custo de manutenção da D-20 registrado**: trocar de sócio ou de pessoa do Comercial exige refazer o `.docx` e cadastrar modelo novo (conteúdo do modelo não é editável via API, só excluir e recriar).
- **`126-VARIAVEIS-DO-MODELO.md` ganhou a seção `## 4. Lista final`**, com: (4.1) tabela de 10 variáveis com nome, origem em `montarDados()`, exemplo formatado e se sai `A DEFINIR` hoje; (4.2) o que fica literal no `.docx`, incluindo o texto do rodapé por papel (D-20) e o aviso de que as cláusulas 1/2 precisam de reescrita (D-19); (4.3) nota de que a tabela em loop (opção D) não foi escolhida e segue NÃO MEDIDA; (4.4) nota de que a opção A (um envelope por serviço) não foi escolhida, então o problema de espaçamento de chamadas na Fase 127 não existe.
- **As três tensões do §2 marcadas RESOLVIDAS**, sem apagar o texto original: 2.1 já resolvida no `126-06-CHECKPOINT.md` (placeholder `A DEFINIR` mantido); 2.2 resolvida como D-19; 2.3 resolvida como D-20.
- **`{{servico_contratado}}` sinalizado como pendência de código para o plano 126-09**: `montarDados()` hoje devolve `['servicos'][0]['servico']` (só o primeiro item); a concatenação de todos os serviços do snapshot precisa ser produzida na ponte do plano 126-09, não existe ainda.
- **Nenhum e-mail em arquivo versionado** (T-126-37): as duas decisões descrevem só o ARRANJO (papéis — "dois sócios", "o Comercial"), nunca nome+e-mail; os dados reais continuam apontados para `config('services.clicksign.signatarios_ecf')`, lida do `.env`.

## Task Commits

1. **Task 1: decisão D-19 (serviços)** — respondida pelo usuário em checkpoint anterior a esta sessão, sem commit próprio (checkpoint:decision não gera commit).
2. **Task 2: decisão D-20 (rodapé)** — respondida pelo usuário em checkpoint anterior a esta sessão, sem commit próprio (checkpoint:decision não gera commit).
3. **Task 3: registrar D-19/D-20 + lista final** - `1b24b409` (docs)

**Plan metadata:** ver commit deste SUMMARY.

## Files Created/Modified

- `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-CONTEXT.md` — D-19 e D-20 acrescentadas ao bloco "REVISÃO DE 2026-08-10", entre D-18 e "O que a reversão NÃO muda"; nota de atualização acrescentada ao final do parágrafo "Três tensões abertas" apontando as três como resolvidas.
- `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-VARIAVEIS-DO-MODELO.md` — §2 com as três tensões marcadas RESOLVIDAS (texto original preservado); §3 revisado com os passos de reescrita de cláusula (D-19) e rodapé literal (D-20); §4 "Lista final" criada do zero (4.1 tabela, 4.2 literal, 4.3 nota tabela em loop, 4.4 nota custo de chamadas); §1 corrigida em dois pontos (nomes das testemunhas antigas → aponta para D-20; `servico_contratado` índice 0 → aponta para D-19) para não deixar informação contraditória entre a proposta original e a decisão final.

## Decisions Made

- **D-19 — servicos concatenados numa variável, um envelope por empresa** (opção B). Recusadas: A (custo de chamadas + dois documentos para o cliente assinar), C (manutenção manual em N arquivos), D (não medido, maior risco de erro que trocar uma palavra por variável).
- **D-20 — rodapé nomeia literalmente os 4 signatários do arranjo D-08** (opção B). Recusadas: A (perde identificação visual), C (mais um ponto de divergência entre config e documento).
- Ambas as decisões foram tomadas pelo usuário nos checkpoints das Tasks 1 e 2; esta sessão apenas registrou fielmente as respostas — nenhuma escolha de produto foi feita pelo executor (T-126-36).

## Deviations from Plan

**1. [Rule 2 — funcionalidade crítica ausente] Duas correções de consistência em §1 do `126-VARIAVEIS-DO-MODELO.md`, não pedidas explicitamente pela `<action>` da Task 3.**
- **Encontrado durante:** Task 3, ao escrever a Lista final e perceber que a tabela original do §1 ainda dizia `['servicos'][0]['servico']` (índice fixo, não concatenação) e citava "os nomes das duas testemunhas" do contrato antigo como literal — ambos contradizem D-19/D-20.
- **Risco se não corrigido:** uma sessão futura lendo só o §1 (sem chegar ao §4) sairia com a versão errada da variável de serviço e do rodapé — exatamente o tipo de perda de contexto entre sessões que este plano existe para evitar.
- **Fix:** duas notas curtas inseridas no §1, cada uma apontando para a seção do §4 que tem a versão definitiva. Texto original do §1 preservado, não reescrito.
- **Arquivos:** `126-VARIAVEIS-DO-MODELO.md`
- **Commit:** `1b24b409`

## Issues Encountered

Nenhum. Task 3 é documentação pura — sem código, sem teste, sem chamada à API.

## User Setup Required

None.

## Known Stubs

Nenhum. Este plano não escreve código, então não há stub de dado nem de UI a rastrear. O único item explicitamente não resolvido — a concatenação de `servico_contratado` em `montarDados()` — está documentado como pendência de código para o plano 126-09, não como stub silencioso.

## Next Phase Readiness

- O plano 126-09 (a ponte `montarDados()` → `template.data`) tem agora a lista fechada de 10 variáveis e sabe que precisa produzir `servico_contratado` (concatenação), `vigencia_inicio`/`vigencia_fim` (hoje não existem como variável, só como `montarDados()['vigencia']`) e `data_assinatura` por extenso (hoje `gerado_em` sai `d/m/Y H:i`).
- O plano 126-11 (montagem do `.docx` real e gate no sandbox) tem a especificação completa para o usuário montar o arquivo: variáveis exatas, o que fica literal, e as duas instruções de reescrita de texto (cláusulas 1/2 genéricas; rodapé com os papéis certos).
- A Fase 127 não precisa mais espaçar geração por causa de N serviços — D-19 elimina esse problema (sempre 1 envelope, 15 chamadas).

---
*Phase: 126-client-clicksign-pdf-do-contrato-v22-0*
*Completed: 2026-08-10*

## Self-Check: PASSED

Arquivos confirmados no disco (`126-CONTEXT.md`, `126-VARIAVEIS-DO-MODELO.md` com `## 4. Lista final`, `D-19`, `D-20`) e commit `1b24b409` confirmado em `git log --oneline`.
