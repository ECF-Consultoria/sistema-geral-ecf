---
quick_id: 260821-cq0
slug: endereco-em-partes-separadas
created: 2026-08-21
completed: 2026-08-21
status: complete
---

# Endereço volta a ser guardado em partes separadas — SUMMARY

**O quê:** o contrato de Gestão elaborado pelo jurídico
(`novo-modelo-contrato-gestao-COM-VARIAVEIS.docx`) precisa do endereço em **cinco pedaços**
(`{{endereco}}`, `{{bairro}}`, `{{cidade}}`, `{{estado}}`, `{{cep}}`) — a decisão de 2026-08-20
(quick 260820-jc8) de concatenar tudo num `companies.endereco` único foi **revertida**. HubSpot,
gravação, variáveis do modelo, tela administrativa e dados mínimos voltam a tratar as 5 partes
como campos independentes.

## Commits

| Área | Commit | O que entrou |
|---|---|---|
| Migration + Model | `c26316ee` | `companies` ganha `bairro`/`cidade`/`estado` (string nullable) e `cep` (string(20) nullable), idempotente; `endereco` muda de significado; `Company::$fillable` |
| Handoff HubSpot | `0d67f85c` | `HubspotDealHandoffService::comporEndereco()` removido; `deal_contract_data` devolve as 5 partes cruas; `HubspotWebhookController` grava as 4 colunas novas (regra "só preenche se vazio") |
| Variáveis do contrato | `d8511581` | `ContratoVariaveisModeloService::mapa()` + `ContratoPdfService::montarDados()` ganham as 4 variáveis; `GerarContratoAssinaturaJob` passa a ler as 4 colunas AO VIVO da empresa |
| Tela + dados mínimos | `eec4f7d0` | `ContratoAdminController` (props/validação/save); `Admin/ContratoDetalhe.jsx` (5 campos, copy sem jargão); `ContratoDadosMinimosService::faltantes()` exige as 4 novas; fixtures de 8 arquivos de teste fora do gate atualizadas |

## ⚠️ Mudanças de comportamento registradas explicitamente (pedido do plano)

### 1. `companies.endereco` mudou de significado

Antes desta sessão (desde 2026-08-20): `endereco` era o **endereço completo concatenado**
(`"Rua X, Bairro Y, Cidade - Estado, CEP 00000-000"`). A partir desta sessão: `endereco` é **só o
logradouro** (rua e número) — o que `{{endereco}}` representa no `.docx` novo do jurídico.

**A única empresa em produção com `endereco` preenchido hoje é a id 429 (Maderatto Móveis)**, com a
string concatenada antiga. Ela **não foi tocada por esta sessão** (sem backfill automático, por
instrução explícita do plano) — continuará com a string velha (`"Rua ..., Bairro ..., Cidade - UF,
CEP ..."`) em `endereco` até alguém do Administrativo corrigir manualmente na tela (separando em
rua/bairro/cidade/estado/CEP nos 5 campos). Enquanto isso não acontecer, o contrato dela vai emitir
`{{endereco}}` com a string toda concatenada (feio, mas não quebra nada) e `{{bairro}}`/
`{{cidade}}`/`{{estado}}`/`{{cep}}` vazios até ela também passar a reprovar em
`ContratoDadosMinimosService::faltantes()` (ver item 2) e a tela pedir os campos que faltam.

### 2. As 4 novas colunas voltam a travar a geração de contrato

`ContratoDadosMinimosService::faltantes()` passou a exigir `bairro`/`cidade`/`estado`/`cep`
preenchidos, mesma disciplina de `endereco` (decisão original da quick 260819-guy, agora estendida).
**Toda empresa que hoje só tinha `endereco` preenchido (ou nem isso) passa a aparecer com pendência
na tela do Administrativo** e não gera contrato novo até completar os 5 campos — é exatamente a
continuação fiel da decisão de 2026-08-19 ("campo em branco num contrato assinado é pior"), não um
efeito colateral.

## Decisões da implementação

- **`GerarContratoAssinaturaJob` foi ajustado além do que o plano listava explicitamente** (Rule 2 —
  funcionalidade crítica ausente): o plano só mencionava `ContratoPdfService`/
  `ContratoVariaveisModeloService`, mas o job de geração REAL do contrato é quem monta
  `$complementos` a partir da `Company` ao vivo (`$company->endereco` já vinha assim desde
  260819-guy). Sem incluir `bairro`/`cidade`/`estado`/`cep` nessa mesma leitura, as 4 variáveis
  novas ficariam **pendentes para sempre** em todo contrato gerado de verdade, mesmo com a tela do
  Administrativo 100% preenchida — o gate ficaria satisfeito, mas o `.docx` continuaria saindo
  errado. Corrigido no mesmo padrão de `endereco`.
- **Rótulo de `endereco` em `faltantes()` mudou de "Endereço" para "Rua e número"** — evita jargão e
  ambiguidade agora que existem 5 campos de endereço na mesma tela (nenhum teste depende do texto
  literal antigo, conferido por grep antes da mudança).
- **Endereço parcial continua sendo caso normal, sem concatenação nenhuma para quebrar**: como não
  existe mais `comporEndereco()`, "endereço parcial" hoje é simplesmente cada campo com seu próprio
  valor (ou `null`) — não há mais lógica de hífen/vírgula solta para testar.

## Testes

- `tests/Feature/Phase112HandoffServiceTest.php` — 3 testes reescritos: endereço completo devolve
  as 5 partes separadas (sem string composta); parcial (só cidade/estado) deixa as outras `null`;
  vazio deixa as 5 `null`.
- `tests/Feature/Quick260820Jc8HubspotContratoTest.php` — teste principal atualizado para checar as
  5 colunas separadas; teste de "não sobrescreve dado manual" ganhou asserções para
  cidade/estado (preenchidos porque a empresa não tinha valor prévio); teste novo dedicado
  provando "só preenche se vazio" nas 4 colunas novas; teste de props ausentes cobre os 4 campos
  novos como `null`.
- `tests/Feature/Phase126/ContratoVariaveisModeloTest.php` — teste de valores reais das variáveis
  passa a cobrir bairro/cidade/estado/cep também.
- `tests/Feature/Phase126/ContratoPdfDadosTest.php` — os dois testes de `campos_pendentes` (sem
  complementos / com complementos completos) atualizados para 8 campos em vez de 4.
- `tests/Feature/Phase127/ContratoDadosMinimosTest.php` — 4 testes novos (`sem_bairro_reprova...`,
  `sem_cidade_reprova...`, `sem_estado_reprova...`, `sem_cep_reprova...`) + 1 teste do caso
  "endereço parcial só cidade e estado" pedido explicitamente pelo plano.
- Fixtures `companyCompleta()`/`empresaCompleta()` de 8 arquivos **fora do filtro do gate**
  (`Phase127\ContratoClicksignServiceTest`, `Phase127\ConfiguracaoEcfBloqueiaTest`,
  `Phase127\IdempotenciaContratoTest`, `Phase128\GatilhoContratoPendenciaTest`,
  `Phase128\ReavaliacaoAutomaticaTest`, `Phase131\ContratoAdminDetalheTest`,
  `Phase132\EmissaoCongeladaTest`) foram atualizadas com os 4 campos novos — sem isso,
  `estaPronta()`/o gate de elegibilidade quebrariam nessas suítes fora do escopo do filtro pedido
  pelo plano (Rule 1 — bug causado diretamente por esta mudança).

**Gate do plano** `--filter=Phase111|Phase112|Phase113|Phase126|Phase127|Phase131|Hubspot|Quick260820`:
**Tests: 483, Assertions: 1809, Failures: 0** (só deprecations pré-existentes do PHPUnit).

**Confirmação extra** (fora do gate, arquivos tocados pelas fixtures): `--filter=Phase128|Phase132`:
**Tests: 78, Assertions: 212, Failures: 0**.

## Verificação antes de cada commit

`git status --porcelain app/ config/ tests/ resources/ database/` (sem `--untracked-files=no`)
rodado antes dos 4 commits. `tests/Feature/CompanyPortfolioAccessTest.php` apareceu como `??` nas 4
vezes e foi deixado de fora — não é deste trabalho.

## Build

`npm run build` rodado ao final, sem erros (`ContratoDetalhe-B2Gj9OtJ.js` gerado).

## Fora de escopo

- **Nenhum backfill da empresa 429** — instrução explícita do plano.
- **`.docx` da Clicksign não foi tocado/reenviado** — as 4 variáveis novas (`bairro`/`cidade`/
  `estado`/`cep`) só produzem efeito real quando o modelo cadastrado na Clicksign já usa essas
  chaves; isso é passo separado do time jurídico/administrativo, fora deste quick.
- **Nenhuma migração de MySQL local rodada** — MySQL não estava ativo na máquina durante a
  execução (XAMPP parado); a migration roda normalmente via `php artisan migrate` quando o
  ambiente subir ou no deploy (não autorizado nesta sessão).

## Não deployado

Nada foi para produção nesta sessão. Deploy requer autorização explícita separada. `.env`, VPS e
`servicos.clicksign_template_id` não foram tocados.

## Self-Check: PASSED

Todos os arquivos citados (migration, Model, Services, Controller, JSX, este SUMMARY) e os 4
commits (`c26316ee`, `0d67f85c`, `d8511581`, `eec4f7d0`) foram confirmados no disco/git log.
