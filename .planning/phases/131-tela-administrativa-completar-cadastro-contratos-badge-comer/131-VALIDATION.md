---
phase: 131
slug: tela-administrativa-completar-cadastro-contratos-badge-comer
status: draft
nyquist_compliant: true
wave_0_complete: true
created: 2026-08-14
updated: 2026-08-14
---

# Phase 131 — Validation Strategy

> Contrato de validação por fase. Derivado de `131-RESEARCH.md` §"Validation Architecture",
> **com duas correções** aplicadas em 2026-08-14 (ver "Itens corrigidos" ao final) — o RESEARCH
> foi escrito antes das medições contra a sandbox e antes da decisão sobre ADM-03.
>
> **Revisão de 2026-08-14 (pós-checker):** acrescentada a armadilha do `--filter` que não casa com
> nada, a regra de "o teste nasce na mesma task do código", o 11º arquivo de Wave 0 e as duas linhas
> novas do mapa (configuração da ECF no `gerarContrato()` e URL do painel no CTA de cancelamento).

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| **Config file** | `phpunit.xml` (`CACHE_STORE=array`, `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=sync`) |
| **Quick run command** | `C:\xampp\php\php.exe artisan test --filter=Phase131` |
| **Regressão cruzada** | `C:\xampp\php\php.exe artisan test --filter="Phase126\|Phase129\|Phase130\|Phase131"` |
| **Full suite** | ⛔ **NÃO rodar sem filtro** — ponto pré-existente no `MercadoLivreAdsService` estoura timeout de 300s |

### ⚠️ Armadilha do ambiente — `--filter` que não casa com nada sai 0 **e varre a suíte**

Medido neste repositório em 2026-08-14: `C:\xampp\php\php.exe artisan test --filter=ClasseInexistente`
carrega **toda** a suíte, imprime `INFO No tests found.` e devolve **EXIT_CODE 0**. Ou seja:

1. Um `<verify>` apontando para classe de teste que ainda não existe **passa em falso** — o gate
   fica verde com o código quebrado.
2. Ele dispara exatamente a varredura sem filtro proibida acima. **Filtro sem match ≠ filtro que
   limita.**

Regras que saem daí, válidas para esta fase e para as próximas:

- **O teste nasce na MESMA task do código que ele prova.** Nenhuma task pode verificar com
  `--filter=` de classe criada por task posterior.
- Ao rodar qualquer `--filter=`, conferir que a saída traz `Tests: N` com **N > 0**.
  `No tests found` é FALHA, mesmo com exit code 0.
- `artisan route:list` tem o mesmo defeito: sai 0 mesmo sem casar rota nenhuma. Usar sempre
  `route:list --name=... -v | grep -c "<middleware esperado>"`, porque o `grep` falha com 0
  ocorrências.

> **Ambiente:** PHP não está no PATH — usar sempre `C:\xampp\php\php.exe`. MariaDB local é
> instável; a suíte roda em SQLite.

---

## Sampling Rate

- **Após cada commit de task:** `--filter=Phase131`
- **Após cada wave:** `--filter="Phase126|Phase129|Phase130|Phase131"` — esta fase toca
  `ComercialController` e absorve a rota da Fase 130, então a regressão cruzada não é opcional
- **Antes de `/gsd:verify-work`:** a suíte filtrada acima verde

---

## Per-Task Verification Map

| Req ID | Behavior | Test Type | Automated Command | Status |
|--------|----------|-----------|-------------------|--------|
| UI-05 | 403 para usuário sem `admin.contratos`; 200 para `role:admin` (short-circuit de `hasPermission()`); 200 para quem recebeu a permission via setor | Feature | `--filter=ContratoAdminPermissaoTest` | ⬜ pending |
| UI-05 | As rotas novas usam `permission:admin.contratos`, **nunca** `role:admin` (asserção de fonte) | Feature | `--filter=ContratoAdminPermissaoTest` | ⬜ pending |
| ADM-01 / ADM-02 | `faltantes()` e o gate de "pode gerar" chegam corretos na prop Inertia do detalhe | Feature | `--filter=ContratoAdminDetalheTest` | ⬜ pending |
| ADM-01 / **D-11** | `email_colaborador` é editável na tela **e NÃO bloqueia** o botão "Gerar contrato" — ausente dele, o botão segue habilitado se o resto estiver completo | Feature | `--filter=ContratoAdminDetalheTest` | ⬜ pending |
| UI-01 / D9 | A lista de contratos **nunca** inclui empresa só-Polos | Feature | `--filter=ContratoAdminListaExcluiPolosTest` | ⬜ pending |
| UI-01 / D-04 | O resumo devolve as **7** contagens, uma por estado — sem agrupar | Feature | `--filter=ContratoAdminListaTest` | ⬜ pending |
| UI-03 / D-08 | Badge do Comercial traz situação + dias parado **sem N+1** (asserção de contagem de queries) | Feature | `--filter=EmpresasListagemBadgeContratoTest` | ⬜ pending |
| CLICK-07 | Reenvio usa o corpo JSON:API medido (§14 do empírico) e trata **429 como resposta esperada**, não erro (`Http::fake` com texto puro) | Feature | `--filter=ContratoAdminReenviarTest` | ⬜ pending |
| CLICK-09 | A tela renderiza o **RAMO B** — a opção "corrigir e-mail" **não** é oferecida | Feature | `--filter=ContratoAdminAjustarSignatarioTest` | ⬜ pending |
| **CLICK-10 (corrigido)** | Registrar cancelamento grava **autor + motivo + data** e marca "cancelamento solicitado"; motivo obrigatório mín. 10 chars; **NÃO chama `cancelarEnvelope()`** | Feature | `--filter=ContratoAdminCancelarTest` | ⬜ pending |
| D-10 | A rota antiga `contratos.liberacao-manual.*` foi **removida** (404, não redireciona) | Feature | `--filter=LiberacaoManualRotaAntigaRemovidaTest` | ⬜ pending |
| D-10 / segurança | A liberação absorvida **preserva** as mitigações da Fase 130: `Rule::in` fechado no `motivo_slug`, `exists:` nos ids, checagem de que o contrato pertence à empresa/serviço, `motivo_detalhe` obrigatório | Feature | `--filter=LiberacaoManualAbsorvidaTest` | ⬜ pending |
| D-13 / migration | A migration do cancelamento respeita as armadilhas do projeto (sem `enum()`, `nullOnDelete` sempre com `nullable`, nenhum índice anônimo, nenhum nome > 64 chars, `up()`/`down()` guardados) e `STATUS_TODOS` continua com 7 valores | Feature | `--filter=MigrationFase131ConvencoesTest` | ⬜ pending |
| **UI-02 / D-05 (novo)** | `gerarContrato()` **não** anuncia "Contrato gerado" quando `dispararSeElegivel()` devolve `status: disparado` com `resultado.ok === false` (caso `faltantesDaConfiguracaoEcf()` não vazio): devolve erro citando a configuração interna da ECF e **nenhum** `ContratoAssinatura` é criado | Feature | `--filter=ContratoAdminDetalheTest` | ⬜ pending |
| **UI-SPEC / CLICK-10 (novo)** | O CTA de confirmação do cancelamento usa o texto literal **"Registrar e ir para a Clicksign"** e leva ao painel via `config('services.clicksign.painel_url')` — nenhuma URL literal no JSX | Manual + fonte | `grep -c "Registrar e ir para a Clicksign" resources/js/Pages/Admin/ContratoDetalhe.jsx` = 1 e `grep -c "clicksign.com"` no mesmo arquivo = 0 | ⬜ pending |

*Legenda: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [x] `tests/Feature/Phase131/ContratoAdminPermissaoTest.php` — UI-05
- [x] `tests/Feature/Phase131/ContratoAdminListaTest.php` — UI-01, resumo de 7 contagens
- [x] `tests/Feature/Phase131/ContratoAdminListaExcluiPolosTest.php` — UI-01 / D9
- [x] `tests/Feature/Phase131/ContratoAdminDetalheTest.php` — ADM-01/02 + D-11 + configuração da ECF
- [x] `tests/Feature/Phase131/ContratoAdminReenviarTest.php` — CLICK-07 (inclui o 429)
- [x] `tests/Feature/Phase131/ContratoAdminAjustarSignatarioTest.php` — CLICK-09 (RAMO B)
- [x] `tests/Feature/Phase131/ContratoAdminCancelarTest.php` — CLICK-10 (registro, não cancelamento)
- [x] `tests/Feature/Phase131/LiberacaoManualAbsorvidaTest.php` — D-10, mitigações preservadas
- [x] `tests/Feature/Phase131/LiberacaoManualRotaAntigaRemovidaTest.php` — D-10, rota removida
- [x] `tests/Feature/Phase131/EmpresasListagemBadgeContratoTest.php` — UI-03 / D-08
- [x] `tests/Feature/Phase131/MigrationFase131ConvencoesTest.php` — D-13, convenções de migration
  (11º arquivo, acrescentado na revisão de 2026-08-14; já estava nos planos e faltava aqui)

**Framework:** nenhum install. Verificar se existe `ContratoAssinaturaSignatarioFactory` antes de
criar uma nova.

**Onde cada arquivo nasce** (regra do teste na mesma task — ver a armadilha do `--filter` acima):

| Arquivo de teste | Nasce em | Completado em |
|------------------|----------|---------------|
| `MigrationFase131ConvencoesTest` | 131-01 Task 2 | — |
| `EmpresasListagemBadgeContratoTest` | 131-02 Task 1 | 131-02 Task 3 |
| `ContratoAdminListaTest` | 131-03 Task 1 | 131-03 Task 3 |
| `ContratoAdminPermissaoTest` | 131-03 Task 3 | — |
| `ContratoAdminListaExcluiPolosTest` | 131-03 Task 3 | — |
| `ContratoAdminDetalheTest` | 131-04 Task 1 | 131-04 Task 3 |
| `ContratoAdminReenviarTest` | 131-05 Task 1 | 131-05 Task 3 |
| `ContratoAdminCancelarTest` | 131-05 Task 1 | 131-05 Task 3 |
| `ContratoAdminAjustarSignatarioTest` | 131-05 Task 3 | — |
| `LiberacaoManualRotaAntigaRemovidaTest` | 131-06 Task 1 | — |
| `LiberacaoManualAbsorvidaTest` | 131-06 Task 3 | — |

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Nenhum termo da tela exige conhecimento de Clicksign | UI-06 | Julgamento humano de linguagem — nenhum teste automatizado decide se um texto é jargão | Percorrer as duas telas e confrontar com a tabela de copy do `131-UI-SPEC.md` |
| O fluxo "registra aqui, cancela lá" faz sentido para quem usa | CLICK-10 / D-13 | O valor está em a pessoa entender que precisa concluir no painel — só observando dá para saber | Registrar um cancelamento e verificar se a instrução é clara e o aviso persistente aparece |
| Ponto focal e hierarquia visual | UI-SPEC dim. 2 | Percepção visual | Abrir as duas telas e conferir contra a tabela de ponto focal do UI-SPEC |

> **Disciplina obrigatória do projeto:** conferir por **RECONSULTA AO BANCO**, nunca por stdout
> nem pela mensagem de sucesso da tela.

---

## Itens corrigidos em relação ao `131-RESEARCH.md`

O RESEARCH foi escrito antes das medições contra a sandbox e antes da decisão do usuário sobre a
ADM-03. Duas linhas do mapa original não valem mais:

1. **REMOVIDO — `ComercialStoreSemGmailColaboradorTest`.** O RESEARCH previa testar que
   `gmail_colaborador` sumiu do payload de `ComercialController::store()`. **A D-12 decidiu que a
   ADM-03 já está cumprida** (o `email_colaborador` saiu do Comercial na quick `260805-eqk`) e que
   o `gmail_colaborador` do Polos **fica onde está**. Testar a remoção de algo que não será
   removido faria a suíte falhar por descrever trabalho que ninguém vai fazer.

2. **CORRIGIDO — `ContratoAdminCancelarTest`.** O RESEARCH previa asserir que o cancelamento
   *"chama `ClicksignClient::cancelarEnvelope()`"*. **Medido em 2026-08-14: não funciona** —
   `DELETE` devolve 403 em envelope `running` (§15.2 do empírico). Pela D-13, a tela **registra**
   autor+motivo+data e instrui a concluir no painel. O teste passa a asserir o registro e a
   **ausência** da chamada ao client.

---

## Validation Sign-Off

- [x] Todas as tasks têm verify `<automated>` ou dependência de Wave 0
- [x] Nenhuma task verifica com `--filter=` de classe criada por task POSTERIOR (armadilha do
      `--filter` sem match — corrigida em 131-02/03/04/05/06 na revisão de 2026-08-14)
- [x] Continuidade de amostragem: nenhuma sequência de 3 tasks sem verify automatizado
- [x] Wave 0 cobre todos os arquivos de teste MISSING (11 arquivos, com o dono de cada um mapeado)
- [x] Nenhuma flag de watch-mode
- [x] `nyquist_compliant: true` no frontmatter

**Approval:** aprovado em 2026-08-14 (revisão pós-checker — BLOCKER do `--filter` fechado,
mapa de Wave 0 completo com 11 arquivos)
