# Duas rotas HTTP criam MlbEmpresa por fora do EmpresaOperacionalRouter, sem gate

**Criado:** 2026-08-18
**Criticidade:** baixa hoje — sobe se algum dos dois passar a produzir tipo diferente de POLO
**Descoberto em:** pesquisa da Fase 133 (v22.0)

## O que está aberto

A Fase 133 fechou os três caminhos declarados no ROADMAP (FLUXO-01, FLUXO-02, FLUXO-09) — o
interruptor `administrativo_bloqueio_ativo` agora é respeitado por todos eles, com a exceção
por serviço (D-02) que isenta Polos. A pesquisa da fase encontrou, além desses três, **duas
rotas adicionais** que criam `MlbEmpresa` diretamente, sem passar pelo `EmpresaOperacionalRouter`
e sem consultar nem o interruptor nem `Servico::exigeContrato()`:

1. **`MlbImplementacaoController::criar()`** — rota `POST /mlb/implementacao`, nome de rota
   `implementacao.criar`, `app/Http/Controllers/MlbImplementacaoController.php:492-549`. Cria
   `MlbEmpresa` com `'tipo' => 'POLO'` **escrito literalmente no código** (linha 524). O
   `company_id` **não é setado** — a empresa nasce só com `nome`, digitado a mão pelo time de
   Publicação; não referencia nenhuma `Company` do módulo Comercial.

2. **`MlbController::storeEmpresa()`** — rota `POST /mlb/empresas`, nome de rota
   `mlb.empresas.store`, `app/Http/Controllers/MlbController.php:2508-2531` (linha pode variar
   após esta fase). O array `$request->validate([...])` **não inclui `tipo`** entre os campos
   aceitos, então o `MlbEmpresa::create([...$data, ...])` sempre cai no **default de schema**
   `'POLO'` da migration `database/migrations/2026_05_04_000004_add_tipo_to_mlb_empresas_table.php`.
   `company_id` também não está entre os campos validados, então nunca é setado por esta rota.

Nenhuma das duas seta `company_id` — ou seja, nenhuma das duas fichas criadas por essas rotas
consegue, hoje, ser vinculada a um `Company`/`ContratoServico` real, mesmo que alguém quisesse
checar contrato para elas.

## Por que não é urgente

`POLO` é isento de contrato por decisão de negócio (D9 da Fase 128 — `Servico::exigeContrato()`
para o serviço Polos é `false`). Como as duas rotas **sempre** produzem `tipo = 'POLO'`, nenhuma
das duas viola hoje a regra "contrato assinado é a porta de entrada do operacional" — Polos nunca
precisou de contrato para entrar.

As duas telas (`/mlb/implementacao` e o cadastro direto de `/mlb/empresas`) são acompanhamento
interno de projeto do time de Publicação — não fazem parte do pipeline
Comercial → Administrativo → Operacional que esta milestone (v22.0) constrói e trava. Fechá-las
seria estender o gate a um fluxo que nunca teve conceito de contrato, não corrigir uma lacuna do
gate que já existe.

## Quando vira problema

Qualquer um dos três gatilhos abaixo reabre a porta para um tipo NÃO isento passar sem checagem:

1. **Alguém acrescentar `tipo` à lista de campos validados de `storeEmpresa()`.** No dia em que
   `tipo` deixar de vir só do default de schema e passar a ser escolhido a mão (como já acontece
   em `ativarEmpresaPendente()`), a mesma porta dos fundos que a Fase 133 fechou lá reabre aqui,
   sem o gate correspondente.
2. **O default de schema de `mlb_empresas.tipo` mudar** (nova migration alterando o `default`
   da coluna, ou removendo o default). Hoje é `'POLO'`; se mudar, `storeEmpresa()` passaria a
   criar fichas de outro tipo sem que ninguém tenha tocado no controller.
3. **Alguém passar a vincular `company_id` nessas rotas.** No momento em que qualquer uma das
   duas ganhar um vínculo real com `Company`, torna-se possível — e esperado — consultar
   `contratosServico` como `ativarEmpresaPendente()` já faz; até lá, não há dado para checar.

## Também fora do gate (sem ação)

Além das duas rotas HTTP acima, três comandos artisan de bootstrap único também criam
`MlbEmpresa` por fora do router: `mlb:seed-polos-fase` (`SeedPolosFase.php`),
`mlb:importar-planilha` (`ImportarPlanilhaMLB.php`) e `mlb:importar-maycon`
(`ImportarPlanilhaMaycon.php`). Todos dependem de arquivos não versionados no repositório
(`import_mlb.json`, `maycon_data.json`) e exigem acesso direto ao servidor — risco operacional
baixo, e os próprios docblocks já avisam para não rodá-los de novo em runtime. Citados aqui só
por completude; não fazem parte da dívida D-06 propriamente dita.

## Decisão registrada

**D-06** (`.planning/phases/133-liga-o-bloqueio-ativa-o-real-v22-0/133-CONTEXT.md`) manteve as
duas rotas fora de escopo da Fase 133 de propósito: fechá-las hoje exigiria decidir uma
capacidade nova — contra qual contrato checar, se a ficha não tem `Company` nem `ContratoServico`
associado? — e não é a correção de uma lacuna no gate já desenhado (FLUXO-01/02/09). Fechar essas
duas rotas exige decisão explícita do usuário sobre o que elas devem checar, não uma extensão
automática da regra atual.

Ver também `133-RESEARCH.md`, Pitfall 2 e Open Question 1, e
`.planning/learnings/painel-polos-status-e-meta.md` §3 (por que "empresa polo" é `MlbEmpresa`,
não `Company` — este achado é uma confirmação direta desse padrão: a maior parte do histórico de
`mlb_empresas` nunca passou pelo Comercial).
