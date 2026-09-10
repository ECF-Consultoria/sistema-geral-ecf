---
phase: 152
slug: checklist-administrativo-trava-de-finaliza-o-v23-0
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-09-09
---

# Phase 152 — Estratégia de Validação

> Contrato de validação por fase, para amostragem de feedback durante a execução.
> Derivado de `152-RESEARCH.md` §Validation Architecture, corrigido pelas decisões D-15..D-19.

---

## Infraestrutura de teste

| Propriedade | Valor |
|-------------|-------|
| **Framework** | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) — já instalado, nada a instalar |
| **Config** | `phpunit.xml` (banco de teste = SQLite `:memory:`) |
| **Comando rápido** | `/c/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase152 tests/Feature/Phase152 --colors=never` |
| **Comando de suíte (por wave)** | `/c/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase152 tests/Feature/Phase152 tests/Unit/Phase150 tests/Feature/Phase150 tests/Feature/Phase151 --colors=never` |
| **Runtime estimado** | ~40–90s (por diretório; a suíte inteira NÃO termina — ver abaixo) |

### ⚠️ Restrições duras deste ambiente (não são opcionais)

1. **`php artisan test` e `php artisan test --testsuite=Feature` NÃO terminam.** Travam numa
   cascata de timeout de rede (~300s) e nunca imprimem resumo. Herdado de
   `150-BASELINE-TESTES.md` e reconfirmado em `151-VALIDATION.md`.
   **Rodar sempre por diretório ou arquivo**, nunca a suíte inteira.
2. **`php` não está no PATH do Bash** — usar `/c/xampp/php/php.exe` sempre.
   ⚠️ **A forma com barras invertidas NÃO funciona no Bash** — o shell come as barras e o comando
   vira `C:xamppphpphp.exe`. Use a forma POSIX no Bash; a forma com barras invertidas só vale no
   PowerShell.
3. **~10 falhas pré-existentes** em `tests/Feature/Phase38/PolosControllerTest.php` e
   `tests/Feature/Polos/PolosFaturamentoSnapshotTest.php` — documentadas em
   `.planning/learnings/painel-polos-status-e-meta.md` §2. **NÃO são regressão desta fase.**
4. **MariaDB em produção, SQLite nos testes** — as três armadilhas do
   `.planning/learnings/desempenho-bonificacao.md` §6 não aparecem no teste. Ver
   "Verificações manuais" abaixo.

---

## Baseline obrigatória ANTES de qualquer código novo

Esta fase adiciona **novos chamadores** de `EtapaTransicaoService::transicionar()` (etapas 2/3/4/5,
D-15) sobre uma máquina de estados que já tem baseline própria. Capturar em
`152-BASELINE-TESTES.md` antes da primeira linha de código:

```
/c/xampp/php/php.exe vendor/bin/phpunit tests/Unit/Phase150 tests/Feature/Phase150 tests/Feature/Phase151 --colors=never
```

Sem esta baseline não há como distinguir falha nova de falha herdada.

---

## Taxa de amostragem

- **A cada commit de task:** comando rápido
- **A cada fechamento de wave:** comando de suíte (inclui regressão 137/138)
- **Antes de `/gsd:verify-work`:** suíte por wave verde + baseline reconferida
- **Latência máxima de feedback:** ~90s

---

## Mapa Requisito → Teste

| Req / Decisão | Comportamento verificado | Tipo | Arquivo de teste | Existe? |
|---|---|---|---|---|
| ADMIN-01 | Checklist agrupado em Contrato/Entrada; **9** itens com contrato, **6** para serviço isento (D-07); denominador do progresso acompanha | feature | `tests/Feature/Phase152/ChecklistContagemItensTest.php` | ❌ Wave 0 |
| ADMIN-02 | Itens 2/3 mudam sozinhos conforme `ContratoAssinatura` avança; item 1 ("revisado") é **manual** — exceção documentada (D-06, D-19) | feature | `tests/Feature/Phase152/ChecklistGrupoContratoAutoTest.php` | ❌ Wave 0 |
| ADMIN-03 | Itens 7 (OAuth ML) e 8 (conexão ECF) auto-marcam; item 6 (link fixo Adman) é **manual** (D-04) | feature | `tests/Feature/Phase152/ChecklistGrupoEntradaAutoTest.php` | ❌ Wave 0 |
| ADMIN-04 | Marcar manualmente grava `feito_por`/`feito_em`; **desmarcar limpa os dois juntos** (D-11); autoria sobrevive a usuário soft-deleted (`withTrashed()`) | feature | `tests/Feature/Phase152/ChecklistMarcacaoManualAutoriaTest.php` | ❌ Wave 0 |
| ADMIN-05 | FINALIZAR desabilitado com qualquer item pendente **ou** contrato não assinado; habilita no instante exato em que o último requisito cumpre — nunca antes | feature | `tests/Feature/Phase152/FinalizarTravaTest.php` | ❌ Wave 0 |
| ADMIN-06 | FINALIZAR move `companies.etapa` para `aguardando_distribuicao`, **mesmo `company_id`** (nenhum cadastro novo); `primaryMarketplace()` resolve o módulo de destino | feature | `tests/Feature/Phase152/FinalizarTransicaoEtapaTest.php` | ❌ Wave 0 |
| **D-15** | Progresso do checklist dispara as etapas: 1º item concluído → 2; envelope enviado → 3; tudo pronto → 4; FINALIZAR → 5. **Salto 2→4 para empresa isenta** (sem envelope não há etapa 3). Toda transição via `EtapaTransicaoService` | feature | `tests/Feature/Phase152/ChecklistDirigeEtapaTest.php` | ❌ Wave 0 |
| **D-16** | Item 3 fecha por `assinado_em`/`status === assinado` **OU** por `ContratoLiberacao` existente. Empresa liberada pela via `manual` (que não escreve `contrato_assinaturas`) **consegue** finalizar | feature | `tests/Feature/Phase152/ContratoAssinadoPorLiberacaoTest.php` | ❌ Wave 0 |
| **D-17** | Usuário só com `comercial.entrada` abre a ficha **sem 403** e **não vê** a seção Contrato; usuário com `admin.contratos` abre e vê | feature | `tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php` | ❌ Wave 0 |
| **D-18** | Com 2 envelopes ativos em estados diferentes, itens 2/3 ficam **pendentes** (manda o mais atrasado); só fecham quando TODOS atingem o estado | feature | `tests/Feature/Phase152/MultiplosEnvelopesTest.php` | ❌ Wave 0 |
| **D-12 (defesa)** | 2 linhas `is_primary = true` na pivot não quebram o FINALIZAR — comportamento **determinístico**, ainda que arbitrário | unit | incluído em `FinalizarTransicaoEtapaTest.php` | ❌ Wave 0 |
| **D-10 (defesa)** | Chave de item desconhecida/órfã **não** entra no denominador do progresso — a ficha ainda fecha 100% | unit | `tests/Unit/Phase152/ChecklistProgressoTest.php` | ❌ Wave 0 |
| **D5 milestone** | Nada desta fase **escreve** em `contrato_assinaturas` nem cria cliente HTTP de assinatura | feature | asserção dentro de `ChecklistGrupoContratoAutoTest.php` | ❌ Wave 0 |

---

## Wave 0 — o que precisa nascer antes

- [ ] `tests/Feature/Phase152/ChecklistContagemItensTest.php` — ADMIN-01
- [ ] `tests/Feature/Phase152/ChecklistGrupoContratoAutoTest.php` — ADMIN-02 + D5
- [ ] `tests/Feature/Phase152/ChecklistGrupoEntradaAutoTest.php` — ADMIN-03
- [ ] `tests/Feature/Phase152/ChecklistMarcacaoManualAutoriaTest.php` — ADMIN-04
- [ ] `tests/Feature/Phase152/FinalizarTravaTest.php` — ADMIN-05
- [ ] `tests/Feature/Phase152/FinalizarTransicaoEtapaTest.php` — ADMIN-06 + D-12
- [ ] `tests/Feature/Phase152/ChecklistDirigeEtapaTest.php` — D-15
- [ ] `tests/Feature/Phase152/ContratoAssinadoPorLiberacaoTest.php` — D-16
- [ ] `tests/Feature/Phase152/ChecklistAcessoPorEntradaTest.php` — D-17
- [ ] `tests/Feature/Phase152/MultiplosEnvelopesTest.php` — D-18
- [ ] `tests/Unit/Phase152/ChecklistProgressoTest.php` — D-10
- [ ] `152-BASELINE-TESTES.md` — baseline 137/138 capturada
- [ ] Framework: **nada a instalar**

---

## Verificações manuais (o teste não pega)

| Comportamento | Requisito | Por que manual | Instruções |
|---|---|---|---|
| Nome de índice ≤ 64 chars no MariaDB | D-10 (schema) | SQLite dos testes aceita nome longo; MariaDB dá erro 1059 | Rodar a migration contra o MariaDB local (`ecf_admin` via XAMPP) e conferir que ela sobe. Nomear o unique explicitamente (ex.: `cai_company_chave_unique`) |
| `nullOnDelete()` em coluna `nullable()` | D-11 (`feito_por`) | erro 1830 só no MariaDB | mesma migration contra MariaDB local |
| Autorização OAuth falsa por clique interno | D-05 / Pitfall 1 | depende de sessão real do navegador na conta ML | Conferir que a UI do item 7 oferece **copiar link**, nunca `<a href>` que abre direto. Ver `project_polos_oauth_link_boas_vindas_260827` |
| Ficha renderiza no navegador | D-08 | React/Inertia; teste PHP não renderiza | `npm run build` e abrir a ficha pelas **duas** listagens (Contrato e Entrada) |
| Página React não some do manifest do Vite | D-08 | armadilha conhecida com re-export puro | Após `npm run build`, conferir a entrada no manifest — ver `.planning/learnings/painel-polos-status-e-meta.md` §2 |
| `ADMAN_REGISTER_URL` trocável sem deploy | D-04 | é config de `.env` na VPS | Conferir que a chave existe em `config/services.php` **e** em `.env.example` |

---

## Sign-off da validação

- [x] Toda task tem verificação automatizada ou dependência declarada de Wave 0
- [x] Continuidade da amostragem: nunca 3 tasks seguidas sem verificação automatizada
- [ ] Wave 0 cobre todos os arquivos marcados ❌
- [x] Nenhum comando em watch-mode
- [x] Nenhum comando que não termine neste ambiente (`artisan test`, `--testsuite=Feature`)
- [ ] Baseline 137/138 capturada em `152-BASELINE-TESTES.md` antes do primeiro commit de código
- [x] `nyquist_compliant: true` no frontmatter

**Aprovação:** aprovada 2026-09-09 — planos 152-01..152-10 verificados pelo `gsd-plan-checker`.
`wave_0_complete` segue `false` de propósito: os 11 arquivos de teste nascem DURANTE a execução,
junto da task que implementa cada comportamento (disciplina "Teste no mesmo commit" do CLAUDE.md).
