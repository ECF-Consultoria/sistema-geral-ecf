# Itens deferidos — Fase 80

## Falha PRÉ-EXISTENTE (fora do escopo do 80-01)

**`Tests\Feature\PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200`**

- **Sintoma:** `Expected response status code [200] but received 403` (`tests/Feature/PublicacaoDesempenhoRouteTest.php:149`).
- **Quando aparece:** capturada pelo `--filter=Desempenho` apenas por coincidência de nome de classe — é um teste de rota/permissão do módulo de PUBLICAÇÃO, não da régua de bônus.
- **Pré-existente:** confirmada falhando em `HEAD 47f38d5` com a árvore de trabalho limpa (zero alterações minhas), ANTES de qualquer edição do Plan 80-01.
- **Relação com esta fase:** nenhuma. Não toca `DesempenhoScoreService` nem `nps_score_assignments`.
- **Ação:** NÃO corrigida aqui (SCOPE BOUNDARY — só se auto-corrige o que a task corrente causou). Fica registrada para triagem própria.

**Âncoras de regressão da fase seguem verdes:** `--filter=DesempenhoScoreServiceTest` 14/14 (inclui `test_fixture_carlos_retorna_nota_4_08_basico`) e `--filter=AtribuicaoPorServico` 8/8.
