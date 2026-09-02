---
phase: 138
slug: rea-comercial-conectada-etapa-v23-0
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-09-02
---

# Phase 138 — Estratégia de Validação

> Contrato de validação por fase, para amostragem de feedback durante a execução.
> Derivado de `138-RESEARCH.md` § "Validation Architecture".

---

## Infraestrutura de Testes

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) — já instalado, nada a adicionar |
| **Arquivo de config** | `phpunit.xml` (banco de teste = SQLite `:memory:`) |
| **Comando rápido** | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase138 tests/Feature/Phase138 --colors=never` |
| **Comando de suíte (por wave)** | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Phase138 tests/Feature/Phase138 tests/Feature/Phase131/ContratoAdminPermissaoTest.php tests/Unit/Phase137 tests/Feature/Phase137 --colors=never` |
| **Runtime estimado** | ~40-90 s (suíte por wave) |

> ⚠️ **`php artisan test` e `--testsuite=Feature` NÃO terminam neste ambiente.** Travam numa cascata
> de timeout de rede (~300 s) e nunca imprimem o resumo. Herdado e reconfirmado do
> `137-BASELINE-TESTES.md`. **Rodar sempre por diretório/arquivo**, nunca a suíte Feature inteira.
>
> ⚠️ **~10 falhas pré-existentes** em `tests/Feature/Phase38/PolosControllerTest.php` e
> `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` estão documentadas em
> `.planning/learnings/painel-polos-status-e-meta.md` §2 — **não são regressão desta fase** e não
> entram na baseline.
>
> ✅ **Ambiente Vite já destravado neste worktree** (medido 2026-09-02, HEAD `2a9562af`):
> `node_modules/` e `public/build/` existem. Não é preciso `npm install` nem recriar `public/hot`
> antes de testes Feature que renderizam Inertia.

---

## Baseline exigida pelo `CLAUDE.md`

A migration da D-09 é **aditiva** (3 colunas nullable em `companies`, sem backfill destrutivo, sem
alterar coluna existente) — mais branda que a da Fase 137. Ainda assim `companies` tem ~500 registros
em produção, e a regra do `CLAUDE.md` ("migration que altere tabela existente com dado em produção")
se aplica.

**Capturar ANTES da migration**, em `138-BASELINE-TESTES.md`:

```
C:\xampp\php\php.exe vendor/bin/phpunit \
  tests/Feature/Phase37CompaniesPerformanceFilterTest.php \
  tests/Feature/V16/CompanyControllerResponsavelPerformanceTest.php \
  tests/Feature/Phase131/ContratoAdminPermissaoTest.php \
  tests/Unit/Phase137 tests/Feature/Phase137 --colors=never
```

São as suítes que tocam as três coisas que esta fase encosta: `companies`, o módulo Contrato e a
máquina de estados. Não é preciso recapturar a suíte inteira da 137.

---

## Amostragem

- **A cada commit de task:** comando rápido (`tests/Unit/Phase138 tests/Feature/Phase138`)
- **A cada fechamento de wave:** comando de suíte (inclui a regressão da 131 e da 137)
- **Antes de `/gsd:verify-work`:** baseline específica verde + suíte por wave verde
- **Latência máxima de feedback:** ~90 s

---

## Mapa Requisito → Verificação

| Requisito | Comportamento | Tipo | Comando automatizado | Arquivo existe |
|--------|------------|------|-------------------|-------------|
| COMERC-01 | Webhook cria empresa → nasce em `aguardando_administrativo` | feature | `phpunit tests/Feature/Phase138/ComercEtapaNascimentoWebhookTest.php` | ❌ Wave 0 |
| COMERC-01 | Cadastro manual (`ComercialController::store`) nasce na mesma etapa, pelo mesmo serviço | feature | `phpunit tests/Feature/Phase138/ComercEtapaNascimentoCadastroManualTest.php` | ❌ Wave 0 |
| COMERC-01 | Evento reentregue: o **call site** trata `recusado` sem lançar (idempotência do serviço já coberta pela 137) | feature | incluído no teste de webhook acima | ❌ Wave 0 |
| COMERC-02 | Listagem **Contrato** mostra os 8 campos do §2, incluindo owner e `data_venda` | feature | `phpunit tests/Feature/Phase138/ComercListagemContratoCamposTest.php` | ❌ Wave 0 |
| COMERC-02 | Listagem **Entrada** mostra os 8 campos; a mesma empresa pode aparecer nas duas listas | feature | `phpunit tests/Feature/Phase138/ComercListagemEntradaTest.php` | ❌ Wave 0 |
| COMERC-02 | Pendência do fluxo e pendências do cadastro em colunas **separadas**, nunca somadas (D-11) | feature | assert de shape do payload, incluído nos testes de listagem | ❌ Wave 0 |
| COMERC-03 | Empresa em `administrativo_concluido` (4) continua visível; em `aguardando_distribuicao` (5) some (D-07) | feature | `phpunit tests/Feature/Phase138/ComercVisibilidadeAteEtapa5Test.php` | ❌ Wave 0 |
| D-15 (regressão) | `admin.contratos.*` continua sob `permission:admin.contratos`, **nunca** `role:admin`, depois da mudança de menu | feature | `phpunit tests/Feature/Phase131/ContratoAdminPermissaoTest.php` — **reusar sem modificar** | ✅ já existe |
| D-16 (regressão) | `admin.empresas` continua acessível por URL direta mesmo fora do menu | feature | teste leve novo em `tests/Feature/Phase138/` | ⚠️ conferir cobertura atual |
| D-17 | Ator de sistema não configurado/não encontrado → log e **não** transiciona (`etapa` NULL), nunca `TypeError`/500 | feature | `phpunit tests/Feature/Phase138/ComercAtorSistemaAusenteTest.php` | ❌ Wave 0 |
| D-17 | A conta "Sistema HubSpot" **não é logável** (senha inutilizável, sem cargo/permissão) | feature + checkpoint humano | teste de asserção + `checkpoint:human-verify` antes do deploy | ❌ Wave 0 |

---

## Wave 0 — o que precisa existir antes

- [ ] `tests/Feature/Phase138/ComercEtapaNascimentoWebhookTest.php` — COMERC-01 (webhook)
- [ ] `tests/Feature/Phase138/ComercEtapaNascimentoCadastroManualTest.php` — COMERC-01 (manual)
- [ ] `tests/Feature/Phase138/ComercListagemContratoCamposTest.php` — COMERC-02 (Contrato)
- [ ] `tests/Feature/Phase138/ComercListagemEntradaTest.php` — COMERC-02 (Entrada)
- [ ] `tests/Feature/Phase138/ComercVisibilidadeAteEtapa5Test.php` — COMERC-03
- [ ] `tests/Feature/Phase138/ComercAtorSistemaAusenteTest.php` — D-17 (fallback seguro)
- [ ] Fixture/mock de `HubspotApiClient::fetchOwner()` — **nenhum teste pode chamar a API real**;
      seguir o padrão já usado pelos testes de `HubspotWebhookController` (`Http::fake()` ou bind no
      container)
- [ ] Framework: **nada a instalar** — PHPUnit configurado, ambiente Vite já destravado

---

## Verificações só manuais

| Comportamento | Requisito | Por que manual | Instruções |
|----------|-------------|------------|-------------------|
| Escopo OAuth `crm.objects.owners.read` concedido ao Private App do HubSpot | COMERC-02 (D-08) | Depende de estado externo da conta HubSpot da ECF, não verificável em teste | Primeira chamada real a `fetchOwner()` com credencial real. Se vier **403**, é escopo faltando — reconfigurar o Private App no painel do HubSpot (ação humana, fora do código). Mesmo padrão do gate não-medido da Fase 126 (`max_upload_bytes` da Clicksign) |
| Nome interno real da property `hubspot_owner_id` na conta da ECF | COMERC-02 (D-08) | Adivinhar nome de property já quebrou em silêncio antes (quick `260805-eqk`) | `php artisan hubspot:inspect-properties --objects=deals` contra a conta real, **antes** de confiar no nome |
| Conta "Sistema HubSpot" criada e não-logável em local **e** na VPS | D-17 | Criação de registro em produção; precedente do usuário de review da Shopee (`users.id=30`, ativo desde 2026-07-16 porque ninguém anotou) | `checkpoint:human-verify` antes do deploy: conta existe nos dois ambientes, senha inutilizável, sem cargo/permissão, e registrada onde não vire login esquecido |
| Render das telas Contrato e Entrada dentro do Comercial | COMERC-02 | Inertia + Vite; grep no bundle prova deploy, nunca que a mudança funciona | Abrir as duas listagens no navegador após `npm run build`; conferir os 8 campos e as **duas** colunas de pendência separadas |

---

## Sign-Off da Validação

- [ ] Toda task tem verificação automatizada ou dependência declarada de Wave 0
- [ ] Continuidade de amostragem: não há 3 tasks consecutivas sem verify automatizado
- [ ] Wave 0 cobre todos os arquivos marcados ❌
- [ ] Nenhuma flag de watch mode
- [ ] Latência de feedback < 90 s
- [ ] `nyquist_compliant: true` no frontmatter

**Aprovação:** aprovada 2026-09-02 — conferida à mão pelo `gsd-plan-checker` contra os 9 planos:
toda task tem verify automatizado, nenhuma flag de watch mode, nenhum uso de `php artisan test` nem
de `--testsuite=Feature`, e nenhuma referência pendente a arquivo de teste inexistente.
`wave_0_complete` segue `false` — a wave 0 ainda não foi executada.
