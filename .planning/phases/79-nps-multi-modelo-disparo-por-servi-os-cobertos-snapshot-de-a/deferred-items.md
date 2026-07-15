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
