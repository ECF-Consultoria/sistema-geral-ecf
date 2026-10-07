---
quick_id: 261007-kit2
slug: kit-de-duas-imagens
type: quick
date: 2026-10-07
status: concluido
---

# Kit de duas imagens — Summary

**Uma linha:** o kit de IA do Publicador passou de 7 slots para 2 (principal + uma com texto quando o cadastro sustenta, senão uma visual), e `minimo_aprovadas` deixou de bloquear aprovação/publicação em qualquer kit — novo ou antigo com o valor congelado em 3.

## O que foi feito

### Tarefa 1 — Dois slots em vez de sete

- `MlAnuncioCriativoKit::SLOTS_PADRAO` 7->2; `MAX_IMAGENS` 14->4; `MAX_REGENERACOES_KIT` 7->2 (mesma proporção de antes: base + regenerações = teto); `MAX_REGENERACOES_ASSET` ficou em 3 (teto por imagem, independente do tamanho do kit).
- `config('services.creative.kit.*')` e `.env.example` (`CREATIVE_KIT_SLOTS`, `MAX_IMAGENS`, `MAX_REGEN_KIT`, `MINIMO_APROVADAS`) atualizados com os mesmos números e comentários corrigidos.
- Comprovado por teste (não só por leitura do código) que `CreativeSlotCatalog::elegiveis()` já produz o comportamento pedido com `$quantidade=2`, sem mudança de lógica: hero primeiro, depois os `COM_FATO` elegíveis (que são os que aceitam texto), depois o resto dos `SEM_FATO`. Ver "Confirmações por teste" abaixo.
- Comentários que afirmavam "PLAN-01 (mínimo 7)" corrigidos em `CreativeSlotCatalog` e `MlAnuncioCriativoKit` (não em todo lugar — ver "Pendências" abaixo).

### Tarefa 2 — A IA deixa de ser condição para publicar

- `CreativeKitPublicacao::conferir()` (gate PUB-03, chamado dentro de `MlPublicacaoService::publicar()` — usado pelo assistente antigo, `MlAnuncioRascunho`) parou de comparar `aprovadas()` contra `minimo_aprovadas`. Continua exigindo que o kit esteja `aprovado` (decisão do operador), mas não exige mais nenhuma contagem mínima.
- `PublicadorCriativoAprovacaoService::aprovarKit()` (usado pelo Publicador novo, `PubRascunho`) trocou o gate `disponiveis < minimo_aprovadas` por `disponiveis < 1`, e a condição para fechar o kit (`status = aprovado`) trocou `aprovadas() >= minimo_aprovadas` por `aprovadas() >= 1`.
- As duas correções leem só o estado atual dos slots, nunca a coluna `minimo_aprovadas` — por isso valem também para kits antigos com o valor congelado em 3 (da época do kit de 7), sem precisar reescrever nenhum dado histórico.
- `resources/js/Components/Publicador/Mesa/PainelCriativos.jsx`: removido `podeUsarKit` e o aviso "Para usar o kit inteiro são precisas ao menos N imagens prontas" — o botão "Usar todas as prontas no anúncio" já só aparece quando `kit.prontas > 0`, que é exatamente a nova régua do backend.

## Confirmações por teste (pedidas no briefing)

(a) Com fato, saem principal + slot com texto: `tests/Unit/Phase161/CreativePlannerTest.php::test_dimensions_aparece_quando_ha_atributo_de_dimensao_e_o_llm_propoe` já provava isso com `$quantidade=7`; a lógica de reconciliação é independente de N (não foi alterada) e os testes de planejamento ponta-a-ponta (`CriativoKitPlanejamentoTest`, `KitIdempotenciaTest`, `FatiaFinaPontaAPontaTest`) agora passam com `$quantidade=2` de verdade (via config), confirmando hero + 1 slot — nestes três, o dublê de `gerarTexto()` propõe `hero` + `white_background` (os dois SEM_FATO, sem atributo nenhum no cenário de teste), então o 2º slot sai visual por falta de fato, não por falta de prioridade a texto.

(b) Sem fato, saem principal + um visual, sempre 2: confirmado pelos mesmos três testes acima (`test_job_cria_kit_com_2_slots_e_portador_vira_referencia`, `test_planejar_devolve_202_e_fica_planejado_com_2_slots`, ponta-a-ponta) — produto sem nenhum atributo cadastrado, kit sai com exatamente 2 slots (`hero` + `white_background`), nunca 1, nunca bloqueado.

(c) Publicação não é mais barrada por imagem de IA, inclusive em kit antigo com `minimo_aprovadas = 3`:
- `CriativoKitPublicacaoGateTest::test_kit_aprovado_abaixo_do_minimo_congelado_conferir_nao_lanca_mais` — kit aprovado com 2 de 3 "mínimo congelado" -> `conferir()` não lança, `aplicarPictures()` escreve as 2 fotos.
- `AprovacaoParaPubImagensTest::test_aprovar_kit_com_dois_prontos_e_minimo_tres_congelado_nao_bloqueia_mais` — kit do Publicador com `minimo_aprovadas` forçado para 3 e só 2 prontas -> `aprovarKit()` aprova as 2 e fecha o kit.
- `RegenerarEAprovarKitTest::test_aprovar_kit_com_um_slot_em_erro_e_minimo_congelado_nao_bloqueia_mais` — kit de 3 slots (minimo congelado 3), 1 em erro, 2 prontas -> aprova as 2 e fecha o kit via HTTP.

(d) Kit de 7 já existente continua legível: nenhum teste que lê/exibe um kit por fixture manual (`kitComSlotsProntos()`, `kitProntoDoPublicador()`, `rascunhoComKit()` — todos continuam criando `total_slots: 7` ou qualquer N explícito à mão) foi alterado nem quebrou; `CriativoKitAprovacaoTest`, `AprovacaoComValidacaoTest`, `KitStatusValidacaoTest`, `RegeneracaoAutomaticaTest`, `CriativoKitGeracaoTest`, `OrcamentoDeRegeneracaoTest` passam sem mudança — eles não dependem do novo default, só do que a fixture grava.

## Resultado real das suítes (não só relato)

- `tests/Feature/Phase161`, `Phase162`, `Phase165`, `Quick261003L8o`, `Quick261007Rmv` (Feature + Unit): 245 passed (Feature) + 77 passed (Unit) — nenhuma falha.
- `tests/Feature/Phase165` isolado: 151 passed, 1 incomplete (o incomplete é pré-existente — "guarda das rotas antigas se liga sozinha quando chegar", documentado como tal nas restrições do briefing).
- `tests/Feature/Publicador` + `tests/Unit/Publicador` (baseline do briefing): 816 passed — bate exatamente com o número do baseline, nenhuma regressão.
- `tests/Feature/Phase75/PublicarEmpresaNaoAtribuidaTest` (filtro geral `Publicador`): 3 failed com `[MLB Coleta] Falha ao obter app token: HTTP 400` — são os 3 pré-existentes citados nas restrições, não desta tarefa.
- `npm run test:js`: 1149 passed, 2 failed — os 2 falhos são `estrutura-grade-glide.test.js` e `polosEntrantes.test.js`, exatamente os 2 pré-existentes citados nas restrições. `tests/js/publicador-painel-criativos-render.test.js` (compila `PainelCriativos.jsx` de verdade com esbuild) passou sem ajuste — a remoção do `podeUsarKit` não quebrou o render real do componente.
- `npm run build`: sucesso (39s) — rodado por ter tocado `PainelCriativos.jsx`.

## Deviations from Plan

### Auto-fixed Issues

1. [Rule 1 - Bug] Teste de planejamento real com default antigo (7) não citado no plano
- Encontrado em: `CreativeContextBuilderPublicadorTest::test_kit_do_publicador_planeja_pelo_ramo_novo`, descoberto só ao rodar a suíte completa do Phase165 (não estava na lista de arquivos do briefing).
- Problema: usa o planejamento real via `PlanejarKitCriativosJob::dispatchSync()` sem fixar `$quantidade`, então herda o default de config — quebrou com `assertSame(7, ...)`.
- Fix: ajustado para `assertSame(2, ...)`, mesmo padrão dos outros 3 testes de planejamento real.
- Commit: `b4537838`

2. [Rule 1 - Bug] Gate de PUB-03 ainda recusava kit aprovado abaixo do mínimo congelado
- Encontrado em: `CriativoKitPublicacaoGateTest::test_kit_aprovado_abaixo_do_minimo_conferir_lanca_com_mensagem_do_minimo` — testava exatamente o comportamento que a Tarefa 2 pede para remover.
- Fix: reescrito para provar o oposto (não lança, aplica as 2 fotos) — ver "Confirmações por teste" (c).
- Commit: `5b7af9b1`

Nenhum outro desvio das instruções do plano.

## Pendências conhecidas, fora de escopo (registradas para decisão do usuário)

Estas strings/comentários ficaram com o número "7" tecnicamente desatualizado, mas não foram tocadas porque vivem em arquivos do assistente antigo (coordenação com o Creative Engine, aviso no topo do CLAUDE.md) ou são docblocks históricos não citados no plano:

- `resources/js/Pages/Mlb/components/PainelCriativosIa.jsx` — textos "Gerar as 7 imagens" / "gere o kit de 7 imagens" (já estava marcado como pendência no PLAN.md original).
- `app/Http/Controllers/MlbAnuncioController.php:1797` — mensagem de erro "Regenerar é um recurso do kit de 7 — suba uma foto nova para gerar outro criativo." (endpoint `criativo*`, coordenação exigida pelo aviso do CLAUDE.md).
- `app/Http/Controllers/MlbAnuncioController.php::criativoKitAprovar()` (linhas ~2184-2262) — continua exigindo `disponiveis >= minimo_aprovadas` para aprovar o kit do assistente antigo, sem mudança. Isso significa: se o assistente antigo planejar um kit NOVO depois deste deploy (2 slots, `minimo_aprovadas` novo = 1 via config), ele funciona normalmente (1 <= 2). Mas isso só vale porque o DEFAULT de config caiu para 1 — o gate do controller em si não foi tocado, por estar fora do escopo combinado (`CreativeKitPublicacao`/`PublicadorCriativoAprovacaoService` apenas). `CriativoKitAprovacaoTest::test_aprovar_kit_abaixo_do_minimo_recusa_e_nao_sobe_nada` (que testa esse endpoint) não foi alterado e continua passando — prova que o comportamento antigo ali permanece intocado.
- `REQUIREMENTS-v24.md` (PLAN-01: "O sistema planeja no mínimo 7 criativos antes de gerar qualquer imagem") — texto do requisito ficou desatualizado pela decisão de 2026-10-07; não atualizado neste quick task (fora do escopo pedido).
- Docblocks "kit de 7" em `PlanejarKitCriativosJob.php`, `GerarCriativoIaJob.php`, `CreativeContextBuilder.php`, `CreativePromptBuilder.php`, `MlAnuncioCriativo.php`, `CreativeKitPublicacao.php` (docblock de classe) — menções históricas ao nome da Fase 161 ("kit de 7"), não comentários que orientam um número de config atualmente errado. Deixados como estão para não ampliar o raio de mudança além do que o plano pediu.

## Ação necessária na VPS (não executada — fora do que um subagente pode fazer em produção)

Se o .env de produção tiver qualquer uma destas chaves definida explicitamente (em vez de ausente, caindo no default do código), o deploy deste quick task não muda o comportamento até alguém editar manualmente:

CREATIVE_KIT_SLOTS
CREATIVE_KIT_MAX_IMAGENS
CREATIVE_KIT_MAX_REGEN_KIT
CREATIVE_KIT_MINIMO_APROVADAS

Comando para conferir na VPS, dentro do diretório do projeto:

grep -E '^CREATIVE_KIT_(SLOTS|MAX_IMAGENS|MAX_REGEN_KIT|MINIMO_APROVADAS)=' .env

Se alguma linha aparecer, trocar para os novos valores (2, 4, 2, 1 respectivamente) ou remover a linha (cai no default do código) e rodar `php artisan config:clear`. Isto é deploy/produção — fica para quando o usuário autorizar o deploy deste quick task.

## Self-Check

- app/Models/MlAnuncioCriativoKit.php — FOUND
- config/services.php — FOUND
- .env.example — FOUND
- app/Services/Creative/CreativeSlotCatalog.php — FOUND
- app/Services/Creative/CreativeKitPublicacao.php — FOUND
- app/Services/Publicador/Criativos/PublicadorCriativoAprovacaoService.php — FOUND
- resources/js/Components/Publicador/Mesa/PainelCriativos.jsx — FOUND
- Commit b4537838 (feat, Tarefa 1) — FOUND no git log
- Commit 5b7af9b1 (fix, Tarefa 2) — FOUND no git log

## Self-Check: PASSED
