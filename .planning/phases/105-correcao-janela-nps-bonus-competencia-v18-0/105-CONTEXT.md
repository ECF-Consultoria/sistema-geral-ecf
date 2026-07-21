# Fase 105 — CONTEXT (decisões travadas)

**Definido:** 2026-07-21 (discuss inline com o usuário, substituindo discuss-phase)

## Modelo de negócio (explicado pelo usuário — a base de tudo)

Uma **janela de bônus dura DOIS meses**:
- **Mês M** — coleta o resultado FINANCEIRO das empresas (faturamento/margem), comparado com M-1.
- **Mês M+1** — coleta os **NPS** (o cliente só consegue avaliar o trabalho DEPOIS de realizado; o NPS de M é enviado/respondido em M+1).

**Bônus de competência M = financeiro(M) + NPS(coletado em M+1).** Só o NPS desloca +1 mês; o financeiro fica em M.

Prova em prod (2026-07-21): Felipe competência junho → NPS coletado em junho = 0 respostas → 0.0 → nota 1.5 (ERRADO); NPS coletado em julho = 13 respostas → 4.97 → nota correta ~3.5.

## D1 — Escopo: só o bônus oficial (mês fechado). TRAVADO.

O deslocamento +1 do NPS vale SÓ no caminho **oficial / mês fechado** (o "Bônus atual").
- A tela **"Em curso"** (mês corrente) **NÃO terá NPS** — e isso é **CORRETO e ESPERADO**, não um gap: o NPS do mês corrente só é coletado no mês seguinte, ainda não existe. O card de NPS simplesmente não aparece no "Em curso".
- O caminho operacional (mês em curso, byte-idêntico à v17) NÃO deve ganhar o deslocamento — mas o componente NPS do mês em curso passa a ser null/ausente por natureza (não há M+1 ainda). Mapear o efeito no operacional com cuidado: hoje o operacional lê NPS do mês corrente; pela regra, o mês corrente não tem NPS de bônus. A UI "Em curso" deve refletir "NPS ainda em coleta" sem tankar a nota (excluir o componente, não 0.0).

## D2 — Timing do congelamento: fim do mês de coleta. TRAVADO.

O `desempenho:consolidar-mes` (que congela o snapshot mensal) roda hoje **dia 1 do mês seguinte, 14h** — no COMEÇO da coleta de M+1, quando quase nenhum NPS de M+1 existe. Aplicar +1 sem mudar o cron congelaria **0 NPS todo mês** (pior que o bug).

Decisão: **congelar no FIM do mês de coleta.** A competência M fecha ao fim de M+1 (junho fecha ~31/07, quando a coleta de julho termina). O cron passa a rodar no fim do mês, consolidando a competência = (mês corrente − 1) cujo NPS acabou de ser coletado.
- Consequência aceita: o pagamento de junho desliza pro fim de julho / início de agosto (não começo de julho).
- Sem snapshot provisório + refechamento (o usuário descartou o risco de "meu bônus mudou depois de pago").

## Escopo técnico (derivado)

1. `computeNpsMedio` para competência M lê as respostas coletadas em M+1 (janela de NPS separada da financeira, +1 mês). A query já usa `nps_surveys.completed_at` (fonte certa, DEC-80-B0). Ponto de injeção: onde `compute()` chama `computeNpsMedio($user, $mes)` — passar o mês+1 para o NPS, mantendo $mes para o financeiro. SÓ no caminho oficial/fechado (D1).
2. Ajustar o cron `desempenho:consolidar-mes` (routes/console.php) para congelar no fim do mês de coleta, consolidando a competência certa (D2).
3. Bump de cache v5→v6 (a nota muda; sem bump, Redis serve v5 por até 7 dias).
4. `computeNpsMedio` retornando 0.0 pra vazio ('decisão da diretoria') — reavaliar no contexto do oficial: se M+1 tem 0 NPS de verdade (competência sem nenhuma resposta), o 0.0 ainda vale? Ou excluir? Levar ao planner como sub-questão (provável: manter 0.0 só quando M+1 já fechou e teve 0; excluir enquanto M+1 ainda coleta).

## Preservar
- Score único; elegibilidade financeira (Fase 91); dual-path do NPS (Fase 80) intocado na LÓGICA de atribuição (só a JANELA de leitura muda); financeiro na competência M (Fase 102/103).
- Sessão paralela (NPS anti-burlamento 94-96) mexeu muito em NPS — validar que `completed_at` ainda é a fonte antes de codar.

## Validação pós-fix (prod)
Felipe competência junho → ~3.5 (NPS julho 4.97 + margem -2.37%), não 1.5. Rodar por profissional antes de comunicar ao time.
