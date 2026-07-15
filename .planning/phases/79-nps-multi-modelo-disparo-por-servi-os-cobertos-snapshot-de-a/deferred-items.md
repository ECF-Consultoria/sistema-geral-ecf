# Itens deferidos — Phase 79

Descobertas fora do escopo do plano atual, registradas para tratamento posterior.
NÃO corrigidas aqui (SCOPE BOUNDARY: só se corrige o que é diretamente causado pela task).

## Falhas de teste pré-existentes (NÃO causadas pelo 79-03)

Ao rodar a suite completa (`php artisan test`), várias falhas aparecem em domínios
NÃO tocados por este plano. Confirmado que são pré-existentes (efeito do trabalho
paralelo em setores/Shopee/polos — Phase 75+ do dev paralelo), não do disparo NPS:

- `Phase37ServicoSetorTest::constants setores expostas` — espera `Servico::SETORES`
  com 3 entradas; hoje há 5 (shopee + outro setor novo). Teste desatualizado para
  a nova taxonomia de setores.
- `Phase13MigrationTest`, `Phase14*` (Comercial/Migration/Cobranca), `Phase18`,
  `Phase33OnboardingFichaTest`, `Phase38*` (Polos/MeuPainel), `Phase42` — falhas
  em migrations/props de serviços/polos, sem relação com `NpsDispararMensal`.
- Fatal `Maximum execution time exceeded` em
  `app/Services/Sugadores/MercadoLivreAdsService.php:215` — chamada HTTP real
  travando no ambiente de teste local (sem rede/mock). Ambiental.

Ação: encaminhar ao dono da milestone de setores/Shopee para atualizar as
assertions de taxonomia e mockar o serviço ML nos testes de Sugadores.

Verificação do 79-03 (escopo do plano): `--filter=Nps` = 165 verdes;
`tests/Feature/V16/DisparoEstritoTest.php` = 5 verdes.

## Falha pré-existente descoberta no 79-04 (NÃO causada pelo snapshot)

- `PublicacaoDesempenhoRouteTest::user com mlb dashboard acessa rota e recebe 200`
  — GET `/publicacao/desempenho` retorna 403 em vez de 200. É problema de
  permissão/middleware do módulo de publicação (RBAC), sem qualquer relação com o
  snapshot NPS. O 79-04 só toca `NpsController::submitResponseV15` (submit público
  do NPS), o novo `NpsSnapshotService` e testes V16. Arquivo de teste tocado por
  último na Phase 49-02 — anterior a este trabalho. Provável efeito do dev paralelo
  (anunciar-ml) sobre permissões de publicação.

Ação: encaminhar ao dono do módulo de publicação/desempenho para revalidar o RBAC
da rota `/publicacao/desempenho`.

Verificação do 79-04 (escopo do plano): `tests/Feature/V16` = 54 verdes;
`--filter=Nps` = 168 verdes; `--filter=Desempenho` = 55 verdes + 1 falha
pré-existente fora de escopo (acima).
