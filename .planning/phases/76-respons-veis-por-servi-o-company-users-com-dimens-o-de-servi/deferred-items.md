# Itens diferidos — Phase 76

## Fora de escopo (descobertos durante 76-02)

- **`Tests\Feature\PublicacaoDesempenhoRouteTest > user com mlb dashboard acessa rota e recebe 200`**
  - Sintoma: retorna 403 em vez de 200.
  - Verificado PRÉ-EXISTENTE: falha idêntica no commit HEAD~1 (76-01), antes de qualquer mudança do 76-02.
  - Não relacionado à dedup de carteira/`company_users` (é permissão de rota `/publicacao/desempenho`).
  - Ação: NÃO corrigido nesta fase (regra scope boundary). Investigar separadamente.
