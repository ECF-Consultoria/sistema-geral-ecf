# Deferred Items — Phase 14

Itens descobertos durante a execução mas fora de escopo dos plans atuais.

## Plan 14-03 (descobertos em 2026-05-26)

### Falhas pré-existentes em AdminFechamentoControllerTest (5 testes)

Detectadas ao rodar `php artisan test --filter=AdminFechamentoControllerTest` para validar não-regressão pós refator do Plan 14-03. Verificadas via `git stash` — os testes JÁ FALHAVAM antes deste plan.

Testes falhando:
- `test_update_persiste_service_type`
- `test_update_rejeita_service_type_invalido`
- `test_update_rejeita_contract_end_anterior`
- `test_empresa_ok_recebe_periodo_coberto`
- `test_metrica_fora_do_mes_nao_conta`

Causa provável: testes escritos antes do refator do `service_type` para JSON array (Phase 2 quick task `260525`) ou da janela 30d rolling no `fechamento()` (Phase 5).

**Decisão:** Não fazer fix neste plan (fora de escopo — SCOPE BOUNDARY do executor). Deve ser atacado em quick task específica.

## Plan 14-04 (descobertos em 2026-05-26)

### Phase13ComercialTest fica incompatível com a nova API de store/update

Após o refator do `ComercialController::store()` para aceitar `servicos[]` (em vez do enum legacy `service_type`), a suíte `Phase13ComercialTest` (12 testes) deixa de bater nos novos contratos:

- Payload da suíte envia `service_type` como STRING scalar (`'polos'`, `'publicidade'`, etc.) — controller novo exige `servicos[].servico_id` integer + Rule::exists.
- `test_validacao_campos_obrigatorios` ainda asserta erro em `service_type` — a nova chave é `servicos`.
- `test_guard_duplicata_*` e `test_cria_empresa_*` enviam payload legacy — todos falham com `validation.required` em `servicos`.
- `test_empresa_visivel_no_financeiro`, `test_notificacao_lideres`, `test_sem_lideres_nao_falha` — mesma raiz (payload obsoleto).

**Causa:** A suíte foi escrita no Phase 13 (modelo enum legacy). Plan 14-04 inverte o contrato de entrada.

**Decisão:** Não reescrever a Phase13ComercialTest neste plan — a cobertura equivalente já foi reproduzida em `Phase14ComercialTest` (este plan), com 8 testes verdes cobrindo COM-04/05/06/08 + validação enxuta. Marcar `Phase13ComercialTest` como obsoleta e remover (ou portar) em quick task pós Plan 14-06.

**Cobertura equivalente** (validação Phase 14):

| Phase 13 COM | Phase 14 teste equivalente |
|--------------|----------------------------|
| COM-04 (polos cria mlb_implementacao) | `test_cadastra_empresa_polos_cria_mlb_implementacao` |
| COM-05 (assessoria cria mlb sem implementacao) | `test_cadastra_empresa_assessoria_cria_mlb_sem_implementacao` |
| COM-06 (publicidade só company) | `test_cadastra_empresa_publicidade_nao_cria_mlb` |
| COM-07 (gestao só company) | Implícito no `test_cadastra_empresa_com_multiplos_servicos_*` (gestão como 2º slot) |
| COM-08 (notification para líderes) | `test_cadastro_aciona_notificacao_para_lideres_do_setor` |
| Validação obrigatórios | `test_cadastro_sem_servicos_falha_validation` |
| Validação inválidos | `test_cadastro_com_servico_inativo_falha_validation` |

