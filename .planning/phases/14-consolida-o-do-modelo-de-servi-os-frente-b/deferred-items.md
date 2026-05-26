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

## Plan 14-05 (descobertos em 2026-05-26)

### Checkpoint humano (Task 5) — UAT visual deferido como débito

**Status:** `pending-human-uat`
**Plan fechado em:** 2026-05-26 com base nos testes automatizados (28/28 verdes, 202 assertions na suíte combinada Phase 14)
**Decisão do usuário:** Pular checkpoint visual e registrar UAT como débito para preservar tokens — fechar Plan 14-05 com base na cobertura automatizada (Phase14BladeRefactorTest + Phase14FechamentoUiTest + suíte Phase 14 combinada).

**Justificativa técnica:** O Plan 14-05 entrega refator de UI cuja correção funcional está coberta por:

- `Phase14BladeRefactorTest` (3 testes / 12 assertions) — confirma que as 3 Blade views renderizam os nomes de serviços corretos via `$company->service_type_label` (não vazam `labelFromTypes` legacy).
- `Phase14FechamentoUiTest` (4 testes / 58 assertions) — confirma `component('Admin/Financeiro')` + shape exato de `servicos_contratados` (8 chaves) + presença da chave em TODA empresa + cálculo `cobranca_mensal = faixa + SUM(contratos mensais ativos)` + payload do catálogo (`servicos_disponiveis`).
- `npm run build` 0 erros — bundle Admin/Financeiro registrado (36.77 kB).

UAT humano valida principalmente **percepção visual** (badges, layout do modal, comportamento de UI Dialog), não comportamento de domínio. Critérios funcionais (Task 5 itens 6, 7, 8, 9) já cobertos pela suíte Phase 14.

**Itens de verificação visual deferidos** (do `<how-to-verify>` da Task 5):

1. Rodar `npm run build` no host XAMPP e confirmar 0 erros — **JÁ EXECUTADO** durante a Task 2 (built in 9.02s).
2. Acessar `https://admin.ecfconsultoria.com.br/administrativo/financeiro` (login admin) — verificação visual.
3. Confirmar que cada empresa exibe badges com nomes vindos do catálogo (ex: "Polos", "Gestão") — não enums (ex: "POLO" maiúsculo do legacy).
4. Expandir uma empresa qualquer (clicar para abrir o editor inline ou accordion existente).
5. Confirmar visualmente: tabela "Serviços contratados" com colunas Serviço/Valor/Tipo/Ações + botão "Adicionar contrato" + modal funcional (select catálogo, valor, datas, switch ativo, Cancelar/Salvar).
6. Adicionar 1 contrato novo via modal — confirmar redirect e novo contrato na lista.
7. Editar contrato — confirmar atualização.
8. Desativar contrato — confirmar que some da lista (ou aparece marcado).
9. Total consolidado soma corretamente: faixa + SUM(contratos mensais ativos).
10. Gerar relatório individual (`gerarRelatorio`) — confirmar que nomes de serviço aparecem na Blade.
11. Gerar relatório geral — idem.
12. Console do navegador (F12) sem erros JS.

**Quando resolver:** Próxima sessão de uso real do Fechamento pelo Admin (usuário relatará discrepâncias se houver). Itens 6/7/8 (CRUD de contrato) já têm cobertura de comportamento via testes da Frente A — o que falta é validação de UX. Item 12 (console JS) só dá para validar manualmente no browser.

**Como remover este débito:** Adicionar entrada em `STATE.md > Quick Tasks Completed` ou em uma quick task `260527-uat-financeiro` quando o admin completar a sequência de 12 passos sem erros. Se algum item falhar, abrir bug específico (Rule 1) e tratar como quick task antes do Plan 14-06.

**Por que NÃO bloquear o Plan 14-06:** Plan 14-06 drop irreversível (6 colunas legacy) depende do `phase14:verificar-cobranca --abort-on-divergence` retornar exit 0 — esse comando NÃO depende da UI do Fechamento, depende apenas dos cálculos de `cobranca_mensal` do helper `CobrancaCalculator` (já cobertos por `Phase14AdminControllerCobrancaTest`). UAT visual do Fechamento é ortogonal ao gate de drop.

### Comando phase14:verificar-cobranca não pôde ser rodado pelo executor

**Status:** `pending-host-run`
**Motivo:** Comando depende de banco populado com empresas reais; ambiente do executor não tem dados. Já documentado no Plan 14-04 mas vale reforçar — o comando precisa ser executado no host (XAMPP local com dump de produção OU produção em janela de manutenção) ANTES do Plan 14-06 disparar o drop. Critério de gate: exit 0 + 0 divergências.

**Cobertura automatizada que substitui:** `Phase14AdminControllerCobrancaTest` (suíte golden no Plan 14-03) verifica o cálculo isolado em ambiente controlado — garante que o REFATOR está correto. O comando garante que os DADOS REAIS DE PRODUÇÃO não divergem entre o caminho legacy e o novo.
## Plan 14-06 (descobertos em 2026-05-26)

### Suites antigas ainda assumem colunas legacy apos o drop

**Status:** `pending-regression-cleanup`

A bateria focada pos-drop passou:

```bash
php artisan test --filter='Phase14FechamentoUiTest|Phase14BladeRefactorTest|Phase14MlbControllerFiltroTest'
# 9 passed (101 assertions)
```

Mas a bateria combinada `Phase14|CobrancaCalculator|ComercialControllerHelper` ainda contem testes de coexistencia que criam ou esperam os campos dropados:

- `Phase14MigrationTest` reexecuta a migration de dados criando companies com campos legacy depois que o schema ja foi dropado.
- `Phase14VerificarCobrancaTest::test_aborta_com_divergencia` depende de divergencia no campo antigo; o comando agora vira smoke check pos-drop quando a coluna nao existe.
- `Phase14AdminControllerCobrancaTest` ainda tem cenarios golden escritos para coexistencia.
- `Phase14ComercialTest::test_update_ignora_campos_legacy` perdeu sentido apos remover a compat do schema.

Tambem houve falhas ambientais no sandbox local: `storage/logs/laravel.log` sem permissao de append e chamadas Adman sem rede durante testes que acionam cache.

**Decisao:** nao reescrever essas suites dentro do commit de drop. A limpeza deve entrar no gate de regression pos-execucao ou quick task propria, convertendo testes de coexistencia em testes de schema pos-drop.

