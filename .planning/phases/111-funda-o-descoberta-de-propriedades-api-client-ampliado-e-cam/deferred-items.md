# Deferred Items — Phase 111

## Plan 111-02

- **`Tests\Feature\Phase37ServicoSetorTest::test_constants_setores_expostas`** falha
  pré-existente (confirmado via `git stash` antes das mudanças deste plano —
  falha idêntica sem as alterações do 111-02). `Servico::SETORES` tem 5
  entradas, o teste espera 3 (`performance`/`publicacao`/`outros`). Não
  relacionado a `HubspotApiClient` nem a este plano — fora de escopo (Rule:
  scope boundary). Não corrigido aqui.
