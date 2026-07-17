# Deferred Items — Phase 96

## Plano 96-04

### Falha pré-existente e não-relacionada: `PublicacaoDesempenhoRouteTest`

- **Teste:** `Tests\Feature\PublicacaoDesempenhoRouteTest::user_com_mlb_dashboard_acessa_rota_e_recebe_200`
- **Sintoma:** `Expected response status code [200] but received 403.` na rota `/publicacao/desempenho`.
- **Escopo:** Fora do escopo deste plano — não toca `NpsResponse`, invalidação, dashboards NPS,
  bônus, nem qualquer arquivo modificado pela Fase 96. É um teste de permissão de acesso ao
  módulo de publicação (`mlb.dashboard`).
- **Verificação:** Reproduzido em isolamento (`--filter=PublicacaoDesempenhoRouteTest`) antes de
  qualquer alteração desta sessão — falha idêntica, confirmando que é pré-existente e não uma
  regressão introduzida pelo Plano 96-04.
- **Ação:** Não corrigido (Rule: fora do escopo do plano — regra "Scope Boundary" do executor).
  Registrar aqui para follow-up futuro fora da Fase 96.
