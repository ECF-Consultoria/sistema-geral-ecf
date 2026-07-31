---
phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0
plan: 05
subsystem: operacional
tags: [gate, desempenho, comparador, decisao-do-usuario, producao, adman]

# Dependency graph
requires:
  - phase: 121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0 (Plano 04)
    provides: "comando desempenho:comparar-score-empresa completo (coleta + decomposição + relatório reconsultável por --run=)"
provides:
  - "Medição real persistida: run_id=03787204-51a7-49fb-8478-da56a5b07e2a, competência alvo 2026-06 + históricas 2026-05 e 2026-04"
  - "GATE do ROADMAP satisfeito: decisão explícita do usuário sobre o delta, registrada com o fato que a embasou"
  - "Distribuição real de margem_var_pp medida em três competências (ROLL-03)"
  - "7 amostras de risco conferidas (ROLL-02), incluindo prova discriminante de ausência da empresa invalidada"
affects: [122]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Checagem empírica de janela antes de tocar produção (date + ps aux + fila) em vez de confiar no horário nominal do cron — foi ela que detectou o adman:sync em voo às 11:03 e adiou a rodada"
    - "Pré-aquecimento competência a competência com verificação de 429 ENTRE elas, nunca em paralelo"
    - "Conferência oficial por reconsulta ao banco pelo run_id; stdout tratado como espelho"

key-files:
  created:
    - .planning/phases/121-compara-o-antigo-novo-e-valida-o-da-r-gua-em-pp-v21-0/121-05-SUMMARY.md
  modified: []

requirements: [ROLL-02, ROLL-03]
---

# Plano 05 — GATE: execução real e decisão sobre o delta

Única entrega da fase que não é código. As quatro waves anteriores construíram o instrumento; esta o apontou para dados reais de produção e colheu a decisão.

## A DECISÃO DO USUÁRIO (D-04)

**Decisão: ACEITO COM RESSALVAS.**

**Ressalva registrada, nas palavras do que foi escolhido:** investigar o resíduo não-decomposto de Douglas e Danilo — nesses dois casos o comparador mede a queda com confiança mas **não consegue atribuir a causa** (no Douglas o resíduo é −1,22 de um delta de −1,36; no Danilo, −0,70 de −1,07). Nos outros 9 profissionais a decomposição explica.

**Fato que embasou a decisão:** *"a queda atingiu todos igual"* — o componente P2 (régua-por-empresa × régua-da-média) veio **negativo para os 11 profissionais**, entre −0,35 e −0,84. Isso caracteriza deslocamento sistemático causado pela mudança de método, não ruído nem sorte de carteira.

**Evidência reconsultável:** `run_id=03787204-51a7-49fb-8478-da56a5b07e2a`
Reimprimir com `php artisan desempenho:comparar-score-empresa --run=03787204-51a7-49fb-8478-da56a5b07e2a` (0,302s, sem tocar a Adman).

**A flag continua desligada.** `config('metrics.performance_company_first_score')` conferido em produção = `false`; `grep PERFORMANCE_COMPANY_FIRST_SCORE .env .env.example` = 0 linhas. Esta fase produziu evidência, não ativação. O GATE MPP-04 permanece `reprovado` — aprovar o delta aqui não liga a flag sozinho.

## A medição

`--mes=2026-06 --historico=2`, executado em 31/07/2026 14:20:10 BRT. 12m17s. **11 profissionais, 0 falhas, 1 sem carteira**, nas três competências.

| Profissional | Antiga | Nova | Delta | Faixa pré-promoção |
|---|---|---|---|---|
| Douglas | 4.00 | 2.64 | −1.36 | basico → sem_bonus |
| Danilo | 4.55 | 3.48 | −1.07 | intermediario → sem_bonus |
| Gabriela Aguiar | 4.49 | 3.43 | −1.06 | basico → sem_bonus |
| Luiz Henrique | 4.36 | 3.37 | −0.99 | basico → sem_bonus |
| Stefani | 4.56 | 3.60 | −0.96 | intermediario → sem_bonus |
| Ana Julia | 4.37 | 3.49 | −0.88 | basico → sem_bonus |
| Rubens | 4.91 | 4.06 | −0.85 | intermediario → basico |
| Nathalia Martins | 4.03 | 3.58 | −0.45 | basico → sem_bonus |
| Matheus Estrela | 3.31 | 2.97 | −0.34 | sem_bonus → sem_bonus |
| Felipe | 3.21 | 3.21 | 0.00 | sem_bonus → sem_bonus |
| Gustavo | 2.76 | 3.04 | +0.28 | sem_bonus → sem_bonus |

**8 dos 11 mudam de faixa; 7 caem para `sem_bonus`.** É o efeito prático mais direto da mudança de método, e a razão de o GATE existir.

**Delta zero mas comportamento mudou:** Felipe (3.21 → 3.21) muda de `official` para `partial`. Matheus e Gustavo também viram `partial`. Confirma em produção o caso que já tinha aparecido nos espelhos da Fase 120.

## Conferência oficial (por reconsulta ao banco, nunca por stdout)

| Competência | Profissionais | Distintos | Falhas | Linhas de empresa | Empresas distintas |
|---|---|---|---|---|---|
| 2026-04 | 11 | 11 | 0 | 288 | 130 |
| 2026-05 | 11 | 11 | 0 | 288 | 130 |
| 2026-06 (alvo) | 11 | 11 | 0 | 286 | 129 |

**Invalidação (verificação discriminante):** `company_id=184`, invalidada na competência 2026-06, tem **0 linhas em 2026-06** e **4 linhas em 2026-04/2026-05**. A exclusão é por competência, não ausência acidental — que é a forma forte da prova.

## 7 amostras de risco (ROLL-02)

1. Poucas empresas: Matheus Estrela (15) · 2. Muitas: Nathalia Martins (34)
3. Queda severa de faturamento: Prisciele (−76,93%); 75 empresas em nota 1 de faturamento
4. pp positivo: CLICK_DECOR (+29,10 pp) · 5. Sem baseline: Empresa teste
6. Invalidada: veredito de ausência confirmado (ver acima)
7. Shopee: Gustavo (10 empresas Shopee) — o placeholder de margem 1.0 puxa a nota para baixo

**Sanidade:** carteira do Luiz ≈ −0,59 pp → nota 3 na régua reusada, contra régua 5 no snapshot congelado e régua 1 no cálculo local revertido. Bate com o CONTEXT.

## Distribuição de margem_var_pp (ROLL-03)

Concentração nas notas 3+4: **60,7%** (2026-04, 89 empresas) · **62,8%** (2026-05, 113) · **59,5%** (2026-06, 111) · **61,0% consolidado**.

Alta nas três competências: a compressão aceita conscientemente ao reusar a régua (D2 da milestone) está **confirmada em número**. Recalibrar a régua é pauta de diretoria, fora do escopo desta milestone.

## Execução operacional

- **Rodada adiada por janela.** Às 11:03 BRT a checagem no VPS encontrou o `adman:sync` das 11:00 com jobs enfileirados (7s de escalonamento por empresa). O `deploy.sh` termina em `supervisorctl restart ecf-worker:*`, que os mataria em voo — o mesmo modo de falha do gap de sync de 12–13/06. Adiado para depois das 13:45 BRT com aprovação do usuário. **Foi a decisão certa:** a rodada final saiu com 0 falhas e zero 429 no dia inteiro.
- **Deploy** autorizado explicitamente pelo usuário e executado às 13:5x BRT com a janela verificada (fila zerada, nenhum cron ativo).
- **Pré-aquecimento** das duas competências históricas, uma de cada vez com verificação de 429 entre elas: 2026-05 → `OK=81 FAIL=0` em 741,5s; 2026-04 → `OK=81 FAIL=0` em 645,8s.
- **Zero 429 / rate-limit no log do dia inteiro.** O número que embasou a decisão **não** foi medido sob contenção — que é exatamente a condição que o gate FIXMARG-03 recusa.
- Nada proibido foi tocado: sem `cache:clear`, sem alterar `config/metrics.php`, sem alterar snapshots, sem `desempenho:consolidar-mes`.

## Desvios

1. **`Nothing to migrate` no deploy.** A migration das tabelas do comparador já tinha subido junto de um deploy de outra sessão, depois do push das 11:10. Em vez de confiar na mensagem, o schema das duas tabelas foi conferido por consulta direta no VPS (`Schema::hasTable` + `getColumnListing`) — ambas presentes e corretas.
2. **Árvore compartilhada.** Durante a fase, outra sessão publicou 6 commits (2 quicks de polos, precificação, revisão). Integrados por merge/fast-forward; o deploy publicou esse trabalho junto, como é inerente à árvore compartilhada.
3. **Task 2 e 3 conduzidas inline pelo orquestrador**, não por subagente executor. O plano não modifica arquivo de código (`files_modified: []`) e envolve comandos com efeito em produção mais dois checkpoints humanos — manter a autorização do usuário e o controle dos comandos no mesmo contexto foi mais seguro que delegar.

## Self-Check: PASSED

- [x] Medição real na última competência fechada (2026-06) mais as duas anteriores
- [x] 7 amostras de risco conferidas (ROLL-02)
- [x] Distribuição real de margem_var_pp medida e apresentada (ROLL-03)
- [x] GATE satisfeito: decisão explícita do usuário registrada, com o fato que a embasou e o run_id que permite reconsultar
- [x] Flag permanece desligada
