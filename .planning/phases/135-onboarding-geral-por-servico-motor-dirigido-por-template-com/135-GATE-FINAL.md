---
phase: 135
executado_em: "2026-08-12T18:44:54Z"
sha: 08ee1d79087403acad08e415dd5e2c709e6fb105
sha_baseline: 735b8f7da6c3dd9d0b164b4d8ebcb53f7f9318f3
---

# Fase 135 — Gate Final

> Fecha a fase provando duas coisas que nenhum plano isolado prova sozinho: que o Polos não
> regrediu (SC-02/D-02) e que os 11 critérios de sucesso têm evidência reexecutável. Ver
> `135-13-PLAN.md` para o contrato completo.

---

## Gate de Polos (SC-02/D-02)

**Veredito: APROVADO**

### Frente 1 — diff de arquivos (byte-a-byte intocado)

Comando:
```
git diff --name-only 735b8f7da6c3dd9d0b164b4d8ebcb53f7f9318f3..HEAD
```

Resultado: 144 arquivos modificados entre a baseline e o HEAD atual (`08ee1d79`). **Nenhum**
arquivo/diretório da lista de escopo intocável do bloco `<interfaces>` do `135-13-PLAN.md`
aparece nesse diff:

| Arquivo/diretório vigiado | Aparece no diff? |
|---|---|
| `app/Models/MlbImplementacao.php` | Não |
| `app/Http/Controllers/MlbImplementacaoController.php` | Não |
| `app/Models/MlbEmpresa.php` | Não |
| `app/Observers/MlbEmpresaObserver.php` | Não |
| `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` | Não |
| `resources/js/Pages/MlbImplementacao/` (diretório) | Não |
| `resources/js/Pages/Mlb/OnboardingFicha.jsx` | Não |
| Migrations de `mlb_implementacoes` | Não |

Comando de checagem (busca literal, sem confiar em leitura visual da lista de 144 arquivos):
```
git diff --name-only 735b8f7d..HEAD | grep -iE "mlbimplementacao|mlbempresa|MlbEmpresaObserver|OnboardingFicha|ImplementacaoPublica|mlb_implementacoes"
→ NENHUM MATCH
```

`routes/web.php` e `bootstrap/app.php` **aparecem** no diff (a fase adiciona rotas/entradas
próprias de Onboarding), mas nenhuma linha de Polos foi removida ou alterada:

```
git diff 735b8f7d..HEAD -- routes/web.php | grep -c '^-.*implementacao'
→ 0
git diff 735b8f7d..HEAD -- bootstrap/app.php | grep -c "^-.*'implementacao/\*'"
→ 0
```

### Frente 2 — contagem de falhas contra a baseline

Todos os comandos rodados filtrados, um por vez, com `C:/xampp/php/php.exe`:

| Suíte | Passed | Failed | Baseline (735b8f7d) | Regressão? |
|---|---|---|---|---|
| `PolosControllerTest` | 6 | 6 | 6 passed / 6 failed | Não — mesmas 6 falhas |
| `PolosFaturamentoSnapshotTest` | 0 | 4 | 0 passed / 4 failed | Não — mesmas 4 falhas |
| `Phase112HubspotHandoffWebhookTest` | 6 | 0 | 6 passed / 0 failed | Não |
| `Phase113HubspotDedupTest` | 14 | 0 | 14 passed / 0 failed | Não |
| `Phase37ComercialListagemTest` | 17 | 0 | 17 passed / 0 failed | Não |
| `Phase37CompaniesPerformanceFilterTest` | 15 | 0 | 15 passed / 0 failed | Não |
| `Phase135` (suíte inteira da fase) | 162 | 0 | não existia | 0 failures |

As 6 falhas de `PolosControllerTest` batem nome por nome com a baseline: `meta por estagio`,
`status sim`, `status em progresso`, `status problema precedencia`, `status dist`, `filtro por
mes`. As 4 falhas de `PolosFaturamentoSnapshotTest` batem tipo por tipo: 2 `ArgumentCountError`
(`job persiste snapshot no sucesso`, `job nao sobrescreve snapshot no erro`) + 2 falhas de
asserção (`fallback snapshot evita r0 no mes corrente`, `cache fresco prevalece sobre
snapshot`). Causa raiz de ambas já documentada em `.planning/learnings/painel-polos-status-e-meta.md`
§2 (faturamento migrou de CSV para Adman; `SyncPolosFaturamentoJob` mudou de assinatura) —
nenhuma relação com o motor de Onboarding desta fase.

Detalhe completo, incluindo a nota sobre o worktree ter trabalho não commitado de outra sessão
durante a medição, em `135-BASELINE-TESTES.md` §"Medição depois da fase".

### Composer/npm — nenhum pacote novo (verificação de fecho, T-135-13-SC)

```
git diff 735b8f7d..HEAD -- composer.json package.json
→ (vazio)
```

**Conclusão da Frente 1 + Frente 2: o onboarding de Polos está byte-a-byte intocado e nenhuma
suíte vigiada regrediu. Gate D-02/SC-02 = APROVADO.**

---

## Suítes de risco do Observer

O `ContratoServicoObserver` (nasce no Plano 05, commit `d7f86c25`) passa a disparar em qualquer
criação de `ContratoServico`, inclusive nos 4 call-sites que essas suítes exercitam
indiretamente. Comparação: baseline "antes" (735b8f7d, pré-Observer) → medição "depois do
Observer" (Plano 05, HEAD `d8e0bcaa`) → medição deste gate final (HEAD `08ee1d79`, ~7 planos
depois do Observer entrar em cena).

| Suíte | Antes (735b8f7d) | Depois do Observer (d8e0bcaa) | Gate final (08ee1d79) | Regressão? |
|---|---|---|---|---|
| `Phase112HubspotHandoffWebhookTest` | 6/0 | 6/0 | 6/0 | Não |
| `Phase113HubspotDedupTest` | 14/0 | 14/0 | 14/0 | Não |
| `Phase37ComercialListagemTest` | 17/0 | 17/0 | 17/0 | Não |
| `Phase37CompaniesPerformanceFilterTest` | 15/0 | 15/0 | 15/0 | Não |

(formato: `passed/failed`)

**Zero falha nova em nenhum dos 3 pontos de medição.** O único ajuste necessário por causa do
Observer foi em `OnboardingSchemaTest` — suíte da própria Fase 135, fora do escopo destas 4
suítes de risco — e já está registrado em `135-BASELINE-TESTES.md` (commit `47be2771`).

---

## Verificações estruturais de fecho

Comandos rodados nesta sessão, com o HEAD em `08ee1d79`:

```
C:/xampp/php/php.exe artisan test --filter=Phase135
→ Tests: 162 passed (745 assertions)

C:/xampp/php/php.exe artisan route:list --path=onboarding
→ 17 rotas, incluindo as 8 exigidas: onboarding.painel.index, onboarding.painel.show,
  onboarding.responsavel.confirmar, onboarding.passos.concluir, onboarding.link.gerar,
  onboarding.templates.index, onboarding.templates.store, onboarding.templates.migrar

C:/xampp/php/php.exe artisan route:list --path=onboarding-cliente
→ 3 rotas: onboarding.publico.workspace (GET), onboarding.publico.ficha (POST),
  onboarding.publico.passo (PATCH)

C:/xampp/php/php.exe artisan schedule:list | grep -i onboarding
→ */10 * * * *  php artisan onboarding:reavaliar-passos

npm run build
→ built in 33.71s (sem erro)

grep -o '"resources/js/Pages/Onboarding/[^"]*\.jsx"' public/build/manifest.json | sort -u
→ Onboarding/Detalhe.jsx, Onboarding/Painel.jsx, Onboarding/Publico.jsx,
  Onboarding/Templates/Index.jsx  (as 3 exigidas pelo plano + Detalhe)

artisan tinker: app(OnboardingResolverFactory::class)->catalogo()
→ 5 chaves: adman_account_id_preenchido, ml_token_ativo, adman_grant_ativo,
  metricas_conta, acervo_coletado
```

---

## Mapa de evidência — Critérios de Sucesso (SC-01..SC-11)

| SC | Comportamento | Evidência | Status |
|---|---|---|---|
| SC-01 | `Onboarding` ancorado em `Company × Servico`, um por contrato | `unique(contrato_servico_id)` em `onboardings` (migration `2026_08_11_120100_create_onboardings_tables.php`) provado por `QueryException` real; teste `dois_onboardings_do_mesmo_contrato_lancam_query_exception_sc01` em `tests/Feature/Phase135/OnboardingSchemaTest.php:175` — `--filter=OnboardingSchemaTest` | OK |
| SC-02 | Onboarding de Polos byte-a-byte intocado | Seção "Gate de Polos" acima, deste mesmo documento — diff `735b8f7d..HEAD` sem nenhum arquivo de Polos + 6 suítes com contagem idêntica à baseline | OK |
| SC-03 | Observer cria rascunho nos 4 call-sites | `tests/Feature/Phase135/OnboardingObserverCallSitesTest.php` — `test_webhook_hubspot_dispara_observer_e_gera_onboarding_em_rascunho:113`, `test_comercial_store_dispara_observer_e_gera_onboarding_em_rascunho:194`, `test_company_store_contrato_dispara_observer_e_gera_onboarding_em_rascunho:218`, `test_atribuir_servico_por_grupo_com_3_empresas_gera_3_onboardings_sem_rede:238` — `--filter=OnboardingObserverCallSitesTest` (commits `d7f86c25`/`ec542775`) | OK |
| SC-04 | Rascunho não corre SLA nem expõe link até confirmar responsável | `rascunho_nao_carimba_disponivel_em_em_nenhum_passo_d05_sc04` em `tests/Feature/Phase135/OnboardingTransicaoStatusTest.php:109` (commit `d8e0bcaa`) + `OnboardingLinkService::passosDoCliente()` só considera onboardings `emAndamento()` (`app/Services/Onboarding/OnboardingLinkService.php`) | OK |
| SC-05 | Passo dependente nasce bloqueado e destrava sozinho | `tests/Feature/Phase135/OnboardingEngineDependenciasTest.php` — 18 testes das 11 regras do motor (commit `aab3a66a`) — `--filter=OnboardingEngineDependenciasTest` | OK |
| SC-06 | Os 5 passos automáticos resolvem sem digitação humana | `OnboardingResolverFactory::catalogo()` confirmado nesta sessão via `artisan tinker` com exatamente 5 chaves (`adman_account_id_preenchido`, `ml_token_ativo`, `adman_grant_ativo`, `metricas_conta`, `acervo_coletado`); 5 suítes correspondentes: `OnboardingResolversLocaisTest`, `OnboardingResolverAdmanGrantTest`, `OnboardingResolverMetricasTest`, `OnboardingResolverAcervoTest` | OK |
| SC-07 | Resolver distingue "não coletado" de "zero real" | `OnboardingResolverAcervoTest::tabela_vazia_com_token_ativo_dispara_coleta_e_sinaliza_em_andamento:123` (nunca conclui) **vs.** `tabela_populada_toda_pausada_resolve_concluido_com_zero_ativos_real:161` (conclui com `ativos=0` real) — `tests/Feature/Phase135/OnboardingResolverAcervoTest.php` (commit `02cece69`) | OK |
| SC-08 | Guarda contra ciclo em `depende_de` | `ciclo_direto_a_b_a_e_rejeitado_com_erro_de_campo_e_mensagem_por_extenso:48` e `ciclo_indireto_de_3_saltos_tambem_e_rejeitado:67` em `tests/Feature/Phase135/OnboardingTemplateCicloTest.php` (commit `f6e5e396`) | OK |
| SC-09 | Versão N+1 não afeta onboardings em andamento | `onboarding_criado_na_v1_mantem_template_id_apos_publicar_v2:111` em `tests/Feature/Phase135/OnboardingTemplateVersionamentoTest.php` (commit `d21eaa5f`) | OK |
| SC-10 | Link único por empresa agrega passos `dono=cliente` | `OnboardingLinkService::paraEmpresa()` (`firstOrCreate` por `company_id`) + `tests/Feature/Phase135/OnboardingPortalPublicoTest.php` (23 testes, commits `4f76ec40`/`c25f966f`/`7f9d1511`) — `--filter=OnboardingPortalPublicoTest` | OK |
| SC-11 | Painel responde "o que trava/há quantos dias/de quem" — nunca porcentagem | `grep -icE "Progress\|percentual\|porcentagem" resources/js/Pages/Onboarding/Painel.jsx resources/js/Components/Onboarding/Painel/*.jsx` → só 2 ocorrências, ambas em **comentário que documenta a ausência** (`EmpresaCard.jsx:66` — "Sem barra de progresso, sem porcentagem"; `SituacaoChip.jsx:5` — "nunca porcentagem"); payload testado em `tests/Feature/Phase135/OnboardingPainelPropsTest.php` (`situacaoDe()`/`passoQueTrava()`/`diasParado()`) | OK |

## Mapa de evidência — Decisões (D-01..D-19)

| D | Decisão | Onde vive no código | Status |
|---|---|---|---|
| D-01 | Um onboarding por empresa × serviço (via contrato) | `unique(contrato_servico_id)` em `onboardings` — mesma evidência de SC-01 | OK |
| D-02 | Onboarding de Polos coexiste intocado | Seção "Gate de Polos" deste documento — ausência provada por diff de arquivos + contagem de falhas | OK |
| D-03 | Passos automáticos são o núcleo da v1 | 5 resolvers registrados em `app/Providers/AppServiceProvider.php`, catálogo fechado confirmado com 5 chaves nesta sessão | OK |
| D-04 | Admin monta o template pela UI | `OnboardingTemplateController` atrás de `role:admin` (`routes/web.php`) + `StoreOnboardingTemplateRequest::authorize()`; teste `usuario_nao_admin_recebe_403_mesmo_com_payload_valido` em `OnboardingTemplateCicloTest.php` | OK |
| D-05 | Rascunho + confirmação (SLA só corre após confirmar responsável) | `OnboardingEngineService::confirmarResponsavel()`; teste `confirmar_responsavel_leva_a_andamento_grava_iniciado_em_e_destrava_os_5_passos_sem_dependencia` em `tests/Feature/Phase135/OnboardingTransicaoStatusTest.php` | OK |
| D-06 | Link do cliente é um por EMPRESA | `unique(company_id)` em `onboarding_links` (migration `2026_08_11_120100_...`) + `OnboardingLinkService::paraEmpresa()` (`firstOrCreate`) | OK |
| D-07 | Editar template publica nova versão; onboardings vivos ficam na antiga | `OnboardingTemplateVersionService::publicarNovaVersao()` (INSERT, nunca UPDATE na versão viva); teste `publicar_v2_nao_altera_updated_at_dos_passos_da_v1` em `OnboardingTemplateVersionamentoTest.php` | OK |
| D-08 | Só Gestão (Performance) na v1 | Ausência provada: nenhum outro `Servico` tem `OnboardingTemplate` publicado — teste `sem_servico_de_gestao_ativo_o_seeder_nao_cria_nada` (`OnboardingTemplateGestaoSeederTest.php`) + `test_contrato_de_servico_sem_template_publicado_nao_cria_onboarding` (`OnboardingObserverCallSitesTest.php:271`) | OK |
| D-09 | `auto_fonte` vem de catálogo fechado, nunca texto livre | `OnboardingResolverFactory::for()` lança `\RuntimeException` para chave fora do catálogo; teste `auto_fonte_fora_do_catalogo_fechado_e_rejeitado` em `OnboardingTemplateCicloTest.php:85` | OK |
| D-10 | `template_passos.chave` nasce agora, mesmo sem uso pleno na v1 | Coluna `chave` em `template_passos` (migration Plano 02) consumida por `groupBy('chave')` em `OnboardingLinkService::passosDoCliente()` — testado com dois templates sintéticos colidindo na mesma chave (`OnboardingPortalPublicoTest.php`) | OK |
| D-11 | Resolver distingue "não coletado" de "zero" | Mesma evidência de SC-07, mais os 6 estados de `OnboardingPasso::STATUSES` (incluindo `aguardando_coleta`/`indeterminado`) em `app/Models/OnboardingPasso.php` | OK |
| D-12 | Passo condicional nasce só se aplicável | `OnboardingEngineService::avaliarCondicao()`; teste `so_excluir_anuncios_inativos_tem_condicao_d12` em `tests/Feature/Phase135/OnboardingTemplateGestaoSeederTest.php:110` | OK |
| D-13 | Observer em `ContratoServico`, não lógica duplicada por controller | `#[ObservedBy(ContratoServicoObserver::class)]` em `app/Models/ContratoServico.php`; os 4 testes de call-site de SC-03 provam via rota real, nunca chamando o Observer diretamente | OK |
| D-14 | Três donos (`cliente`/`interno`/`sistema`) | `TemplatePasso::DONOS` (3 valores fechados); teste `dono_administrativo_e_rejeitado_d14` em `OnboardingTemplateCicloTest.php:103` | OK |
| D-15 | Pagamento não trava o mapeamento, só a conclusão do onboarding | `nenhum_passo_de_mapeamento_depende_de_confirmacao_pagamento_d15` (`OnboardingTemplateGestaoSeederTest.php:99`) + `concluir_passos_de_mapeamento_sem_confirmacao_pagamento_nao_fecha_o_onboarding_d15` (`OnboardingEngineDependenciasTest.php:476`) | OK |
| D-16 | Ficha do cliente é anexo, não formulário | `OnboardingPublicoController::anexarFicha()` (`app/Http/Controllers/OnboardingPublicoController.php:101-141`) grava `valor` mas nunca muda `status` para `concluido` — docblock explícito ("capacidade de anexar ≠ autoridade de confirmar", linhas 97-100) | OK |
| D-17 | Responsável sugerido, não escolhido do zero | `Onboarding::ROLES_RESPONSAVEL_SUGERIDO` (`consultor`→`estrategista`); teste `roles_responsavel_sugerido_e_consultor_e_estrategista_nesta_ordem` em `OnboardingTransicaoStatusTest.php:167` | OK |
| D-18 | Grant com a Consultoria = sonda Adman (`fetchPerformance`), 429 nunca vira "sem grant" | `AdmanGrantResolver` (`app/Services/Onboarding/Resolvers/AdmanGrantResolver.php`); teste `resposta_429_resolve_indeterminado_nunca_sem_grant` em `tests/Feature/Phase135/OnboardingResolverAdmanGrantTest.php:143` | OK — cobertura automatizada. A confirmação contra a API real da Adman (não `Http::fake()`) é o item 1 da Task 3 (verificação manual, ainda pendente) |
| D-19 | `dono` e `auto_fonte` são eixos independentes | `MlTokenAtivoResolver` (passo 5: `dono=cliente` + `auto_fonte` preenchido); teste `exatamente_4_passos_tem_dono_sistema_e_o_grant_ecf_e_cliente_com_auto_fonte_d19` em `OnboardingTemplateGestaoSeederTest.php:74`; docblock D-19 em `app/Models/TemplatePasso.php` | OK |

**Nenhuma célula das duas tabelas ficou `PENDENTE`** — todo SC e todo D tem teste nomeado com
`--filter` reexecutável, `arquivo:linha`, ou comando de CLI reproduzido acima. A única ressalva
é D-18: a parte automatizada (classificação dos 3 estados, incluindo 429) está coberta por
teste; a confirmação contra `api.adman.com.br` de verdade é o item 1 da Task 3 — verificação
manual, não uma lacuna de teste.

---

## Verificações manuais (Task 3 — AGUARDANDO O USUÁRIO)

> **Este gate não está fechado.** As Tasks 1 e 2 provaram tudo que teste automatizado alcança:
> zero regressão em Polos e evidência reexecutável para os 11 SC e as 19 D. Os cinco itens
> abaixo são exatamente os que `135-VALIDATION.md` §"Verificações só-manuais" e o
> `135-13-PLAN.md` (Task 3) marcam como fora do alcance de `Http::fake()` — precisam de um
> humano com acesso ao ambiente real (API da Adman, conta ML real, navegador, worker de fila).
>
> Ambiente preparado para a conferência abaixo:
> - **Worker de fila:** para os itens 1, 2 e 5 é preciso um worker rodando (`php artisan
>   queue:work`) para `ResolveOnboardingPassoJob`/`SyncMlAcervoCompanyJob` processarem.
> - **Comando de reavaliação:** `C:/xampp/php/php.exe artisan onboarding:reavaliar-passos
>   --onboarding=<id>` — sempre com `--onboarding=<id>` (uma empresa por vez), nunca em lote,
>   para respeitar `ADMAN_RATE_LIMIT_RPM = 10` (T-135-13-01).
> - **Link público de teste:** para gerar um link para uma empresa com onboarding em
>   `andamento`, use o botão "Gerar link" na Tela 1 (`/onboarding`) ou
>   `POST /onboarding/empresas/{company}/link` — o token não fica registrado neste documento
>   (T-135-13-04, aceito por desenho: o link é capacidade por posse).
> - Todos os comandos usam `C:/xampp/php/php.exe` (PHP não está no PATH).

### Item 1 — Sonda de grant Adman contra a API real (SC-06/D-18/D-11)

**Como fazer:**
1. Escolher **uma** empresa com grant Adman comprovadamente ativo e **uma** sem grant.
2. Com o worker rodando, `C:/xampp/php/php.exe artisan onboarding:reavaliar-passos
   --onboarding=<id>` para cada uma.
3. Conferir `onboarding_passos.status` e `valor` do passo `grant_consultoria_adman` **por
   reconsulta ao banco** (nunca por stdout).

**Esperado:** grant ativo → `concluido`; sem grant → `aberto`/`nao_coletado` com motivo. O ponto
crítico: provocar (ou aguardar) um `429` e confirmar que o passo vai para `indeterminado`,
**nunca** para "sem grant". Não disparar em lote.

**Resultado:** _(em branco — preencher após a resposta do usuário)_

**Data:** _(em branco)_

---

### Item 2 — Coleta assíncrona do acervo (SC-07)

**Como fazer:**
1. Escolher uma empresa com `ml_tokens.status = active` que **nunca** sincronizou acervo
   (`ml_acervo_itens` sem linhas para ela).
2. Rodar a reavaliação (`onboarding:reavaliar-passos --onboarding=<id>`).
3. Depois que o worker terminar o `SyncMlAcervoCompanyJob`, rodar a reavaliação de novo.

**Esperado:** o passo 8 fica em `aguardando_coleta` e a Tela 1 mostra "Coletando dados
automaticamente…" — **nunca** "0 anúncios". Após o job terminar, o passo conclui com os números
certos.

**Resultado:** _(em branco)_

**Data:** _(em branco)_

---

### Item 3 — Render das 3 telas (SC-11)

**Como fazer:**
1. `npm run build` (já rodado nesta sessão — ver "Verificações estruturais de fecho" acima).
2. Hard reload e abrir: `/onboarding`, `/onboarding/templates` e
   `/onboarding-cliente/{token}` (link gerado por uma empresa com onboarding em `andamento`).
3. Na Tela 2, abrir o Select de `auto_fonte` e conferir 5 opções com rótulo legível e linha de
   ajuda, sem campo de texto livre ao lado.

**Esperado:** nenhuma das três dá "Unable to locate file in Vite manifest"; o painel não mostra
barra de porcentagem como resposta principal.

**Resultado:** _(em branco)_

**Data:** _(em branco)_

---

### Item 4 — Conferência de escopo (D-02)

**Como fazer:** abrir `/mlb/implementacao` e um `/implementacao/{token}` existente do Polos.

**Esperado:** continuam funcionando exatamente como antes.

**Resultado:** _(em branco)_

**Data:** _(em branco)_

---

### Item 5 — Extração de medalha e Full do passo 7 contra conta ML real (SC-06 · OQ3 do `135-RESEARCH.md`)

**Como fazer:**
1. Escolher uma empresa com `ml_tokens.status = active` cuja conta o time conheça — idealmente
   uma com medalha e uma sem, e uma que use Full.
2. Com o worker rodando, `C:/xampp/php/php.exe artisan onboarding:reavaliar-passos
   --onboarding=<id>`.
3. Conferir `onboarding_passos.valor` do passo `metricas_da_conta` **por reconsulta ao banco**,
   nunca por stdout.

**Esperado:** `nickname` preenchido; reputação/medalha e o indicador de Full batendo com o que o
painel do Mercado Livre daquela conta mostra; campo que a API não devolveu aparece em
`valor['nao_obtidos']` e vale `null` — **nunca** `false`, **nunca** `0`.

**Se divergir:** ajustar o parsing em
`app/Services/Onboarding/Resolvers/MetricasContaResolver.php` e as expectativas de
`tests/Feature/Phase135/OnboardingResolverMetricasTest.php`, mantendo o parsing defensivo.
Registrar o payload real observado aqui, para que a marca `[ASSUMIDO]` do Plano 06 deixe de
existir.

**Resultado:** _(em branco)_

**Payload real observado:** _(em branco)_

**Data:** _(em branco)_

---

### Veredito final da fase

_(em branco — só o usuário fecha; "aprovado" ou divergência item a item, conforme
`<resume-signal>` do `135-13-PLAN.md`)_
