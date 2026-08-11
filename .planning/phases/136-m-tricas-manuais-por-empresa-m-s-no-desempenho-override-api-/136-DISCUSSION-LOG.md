# Phase 136: Métricas manuais por empresa/mês no Desempenho - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-11
**Phase:** 136-metricas-manuais-por-empresa-mes-no-desempenho
**Areas discussed:** Precedência e ciclo de vida do valor, Baseline do mês anterior

**Áreas oferecidas e não escolhidas:** Fronteira de "não consolidada", Alcance e retroatividade do desempate — delegadas ao Claude (ver "Claude's Discretion").

---

## Precedência e ciclo de vida do valor

### Q1 — Quando existe lançamento manual E a API também devolve número, quem ganha?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Seleção explícita de fonte por célula | Cada célula empresa×mês guarda `auto` (default) ou `manual`; só `manual` ignora a API. É o que o goal do ROADMAP descreve e deixa a intenção auditável | ✓ |
| Só preenche lacuna | O manual entra apenas quando a API não devolve número. Não resolve "a API devolve número errado" | |
| Manual sempre sobrescreve | Existindo linha manual, ela ganha sem marcação. Risco: lançamento esquecido continua mandando meses depois | |

**User's choice:** Seleção explícita de fonte por célula
**Notes:** Separa "não lancei" de "lancei e mandei usar" — é o que permite auditar intenção depois. → D-01

### Q2 — Célula `manual` e a API passa a devolver dado (Tuki Pet, OAuth em 28/07). O que acontece?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Nada muda sozinho; a tela sinaliza a divergência | `manual` continua mandando; a grade exibe o valor da API ao lado e marca divergência; voltar para `auto` é ato do admin | ✓ |
| Volta para `auto` sozinho | A marcação expira quando a API tem dado. Autolimpante, mas muda nota sem intervenção humana | |
| Nada muda e a tela não avisa | Mais simples; o lançamento fica invisível | |

**User's choice:** Nada muda sozinho; a tela sinaliza a divergência
**Notes:** Argumento decisivo apresentado: OAuth conectado dia 28 dá dado de 4 dias, não do mês — reverter sozinho trocaria número bom por número parcial. → D-02

### Q3 — O que sobra depois de `desempenho:consolidar-mes`?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Trava a célula e grava o rastro no snapshot | Célula read-only e marcação em `desempenho_company_score_snapshots` de que o número foi digitado. Custo: uma migration | ✓ |
| Trava a célula, sem marcação no snapshot | Zero migration; o rastro vive só na tabela de lançamentos | |
| Permite editar mês consolidado e reconsolidar | Contraria o goal e learnings §2 — reconsolidar mexe em pagamento | |

**User's choice:** Trava a célula e grava o rastro no snapshot
**Notes:** Quem auditar junho em dezembro precisa distinguir número medido de número digitado — esse número decidiu bônus. → D-03

### Q4 — A empresa com número manual aparece marcada em `/performance/{user}`?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Selo discreto na linha da empresa | Ícone + tooltip "valor lançado manualmente", sem nome de quem lançou | ✓ |
| Indistinguível; visível só na tela admin | Menos ruído; custo é a pergunta "por que estava escondido" | |
| Transparência total — quem lançou e quando | Máxima auditabilidade; expõe nominalmente quem digitou número que decide bônus de outro | |

**User's choice:** Selo discreto na linha da empresa
**Notes:** Meio-termo entre esconder a origem (destrói confiança) e expor o autor (vira atrito interno). → D-04

---

## Baseline do mês anterior

Área apresentada como estrutural, não cosmética: a nota lê variação (`faturamento_var_pct`, `margem_var_pp`), nunca valor absoluto. Mecânica confirmada no código antes de perguntar — para o mês corrente o `MetricPeriodResolver` usa `comparison_mode='same_interval_previous_month'` (em 11/08: 01–11/08 contra 01–11/07).

### Q1 — Como o valor lançado à mão se compara, se a API compara recorte de dias?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Célula manual compara mês-cheio × mês-cheio | A célula carrega a própria janela e um `diff_source` próprio, sinalizado. Lança-se uma vez, no fechamento. Custo: inconsistência de janela declarada dentro da mesma carteira | ✓ |
| Lançar sempre a janela vigente da API | Coerência total; inviável na prática — o número mudaria todo dia e CMV não existe em recorte de 11 dias | |
| Lançar a variação direto (var% e pp) | Elimina o problema de janela; perde o número auditável que o goal pede | |

**User's choice:** Célula manual compara mês-cheio × mês-cheio
**Notes:** Consequência explicitada e aceita: valor de mês cheio só existe depois do fim do mês, então o lançamento acontece na janela entre fim do mês e consolidação — o "em curso" do goal faz menos trabalho do que aparenta. → D-05, e insumo de D-09

### Q2 — De onde vem o lado BASE da comparação?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Cascata: manual do mês anterior → API mês cheio → sem nota | Menos digitação, os dois lados em mês cheio, base continua sendo número medido quando existe | ✓ |
| Sempre manual nos dois lados | Controle total; dobra a digitação e faz redigitar número que a API já tem | |
| Sempre API no lado base | Regra única; empresa sem API em nenhum mês nunca fecha variação — o caso que motivou a fase | |

**User's choice:** Cascata: manual do mês anterior → API mês cheio → sem nota
**Notes:** → D-06

### Q3 — O toggle é por métrica ou por célula inteira?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Dois eixos independentes: faturamento e margem separados | Loja Shopee só precisa de CMV (10 dos 11 casos); empresa sem OAuth precisa de faturamento. É o que o goal descreve | ✓ |
| Um toggle por empresa/mês | Uma regra só; obriga redigitar faturamento que a API já entrega correto | |
| Só margem é manual; faturamento sempre da API | Escopo mínimo; deixa as 10 empresas sem OAuth sem solução | |

**User's choice:** Dois eixos independentes
**Notes:** → D-07

### Q4 — De qual faturamento sai a margem derivada do CMV?

| Opção | Descrição | Selecionada |
|--------|-------------|----------|
| Faturamento efetivo da célula em mês cheio | Manual se aquele eixo estiver `manual`, senão API mês cheio; sem faturamento o CMV não produz margem | ✓ |
| Sempre API mês cheio | Imune a erro de digitação no faturamento; empresa com dois eixos manuais nunca fecha margem | |
| Permitir lançar a margem % direto como escape | Cobre 100% dos casos; reintroduz número sem lastro e cria dois caminhos para manter | |

**User's choice:** Faturamento efetivo da célula em mês cheio
**Notes:** → D-08

---

## Claude's Discretion

O usuário optou por não discutir estas áreas e delegou a decisão. Registradas em CONTEXT.md com o raciocínio completo, para contestação:

- **D-09 · Fronteira de "não consolidada"** — ausência de linha `origem='consolidar_mes'` no mês, o mesmo sinal que a trava do `CompanyScoreSnapshotWriter` já usa. Inclui uma leitura deliberada do goal: "em curso **e** não consolidada" aplicado como "não consolidada", porque julho/2026 está fechado e não consolidado e é justamente o caso a atender.
- **D-10 · Alcance da correção do desempate** — corrigir os três call-sites (`CompanyScoreService:174`, `DesempenhoScoreService:915`, `PortfolioController:125`) com resolvedor único. O critério de "tem conta Adman" fica como pergunta para o RESEARCH.
- **D-11 · Retroatividade** — só daqui para frente; a fase entrega relatório read-only de impacto e não reconsolida competência fechada.
- **D-12 · Trilha de auditoria** — autor/timestamp/valor anterior na tabela + `activitylog`; a tela não expõe o nome.

Também registrado como discrição documentada: **D-EXC-01**, a exceção estreita ao hotfix de 2026-07-24 ("variação de margem sempre do valor nativo, nunca cálculo local"), que margem derivada de CMV manual necessariamente viola — declarada, marcada por `diff_source` próprio, e válida só para célula `manual`.

## Deferred Ideas

Nenhuma ideia de escopo novo surgiu na discussão — o usuário manteve foco no domínio da fase. Os itens em `<deferred>` do CONTEXT.md vieram da leitura dos learnings e dos todos pendentes, não de scope creep:

- Reconsolidação das competências fechadas (decisão separada, com backup)
- Lock global por mês do `WarmDesempenhoDispatcher` (defeito conhecido, sem fase)
- Exigência de cobertura mínima de dimensões para valer bônus (decisão de negócio, não tomada)
- Unificar as réguas duplicadas entre os dois services (C-03 da Fase 119)
- Calibrar a régua / reduzir fragilidade de fronteira (pauta de diretoria)
