---
phase: 134
slug: meus-anuncios-saude-analitica-do-anuncio-publicado
status: approved
nyquist_compliant: true
wave_0_complete: false
created: 2026-08-10
---

# Phase 134 — Validation Strategy

> Contrato de validação por fase, para amostragem de feedback durante a execução.
> Derivado da seção `## Validation Architecture` do `134-RESEARCH.md`.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework (backend)** | PHPUnit 11.x com `RefreshDatabase` (SQLite in-memory) + `Http::fake()` — molde: `tests/Feature/Phase86/HistoricoAnunciosTest.php` |
| **Framework (lógica pura JS)** | `node --test` — molde: `tests/js/mlAnuncioRegras.test.js` |
| **Config file** | `phpunit.xml` (existente) — nenhum config novo necessário |
| **Quick run command** | `C:\xampp\php\php.exe artisan test --filter=Phase134` |
| **Full suite command** | `C:\xampp\php\php.exe artisan test` |
| **Estimated runtime** | ~20-40s no filtro; suíte completa é longa (ver ressalva abaixo) |

> **Ressalva registrada no aprendizado do projeto:** `artisan test` sem filtro pode **não concluir** (timeout de 300s em `MercadoLivreAdsService`). Durante o desenvolvimento, usar sempre `--filter`; reservar a suíte completa para o gate final da fase e tratar um timeout ali como conhecido, não como regressão desta fase.

---

## Sampling Rate

- **Após cada commit de task:** `C:\xampp\php\php.exe artisan test --filter=Phase134` + `node --test tests/js/*anuncio*`
- **Após cada wave:** suíte do módulo MLB (`--filter=Mlb`)
- **Antes de `/gsd:verify-work`:** suíte completa verde, respeitando a ressalva de timeout acima
- **Max feedback latency:** 60s

---

## Per-Task Verification Map

Mapa por decisão (fase avulsa — sem REQ-IDs de milestone; os IDs são as decisões travadas no `134-CONTEXT.md`). O planner preenche `Task ID` / `Plan` / `Wave` ao gerar os PLAN.md.

| Decisão | Comportamento a provar | Plano / Wave | Arquivo de teste | Automated Command | Status |
|---------|------------------------|--------------|------------------|-------------------|--------|
| D-01 | A tela lista itens que **não** vieram deste módulo (acervo inteiro da conta) | 134-04 (w3), 134-07 (w4) | `ColetaAcervoTest`, `MeusAnunciosTest` | `--filter=Phase134` | ⬜ pending |
| D-02 / V4 | Escopo por empresa: usuário não vê item de outra empresa na listagem nem na triagem | 134-07 (w4) | `MeusAnunciosTest::nao_vaza_anuncio_de_outra_empresa` | `--filter=MeusAnuncios` | ⬜ pending |
| D-03 | Só ativos por padrão; pausados e encerrados atrás de filtro | 134-07 (w4), 134-08 (w5) | `MeusAnunciosTest` | `--filter=MeusAnuncios` | ⬜ pending |
| D-04 | Selo de origem cobre os 3 casos: ECF · time · legado do cliente | 134-04 (w3), 134-07 (w4) | `ColetaAcervoTest::selo_de_origem_cobre_os_tres_casos` | `--filter=ColetaAcervo` | ⬜ pending |
| D-05 | **Zero chamada HTTP síncrona ao ML** no request de leitura | 134-07 (w4), 134-10 (w7) | `MeusAnunciosTest::nenhuma_chamada_sincrona_ao_ml_no_request`, `DetalheAnuncioTest` | `--filter=Phase134` | ⬜ pending |
| D-06 | Coleta agendada diária, sem sobreposição | 134-06 (w4) | `ComandosAcervoTest` mais `artisan schedule:list` | `--filter=ComandosAcervo` | ⬜ pending |
| D-07 | Dois níveis de persistência mais retenção de 90 dias | 134-02 (w1), 134-04 (w3), 134-06 (w4) | `SchemaAcervoTest`, `ColetaAcervoTest`, `ComandosAcervoTest` | `--filter=Phase134` | ⬜ pending |
| D-08 | Snapshot velho/ausente → selo de defasagem, **nunca** tela em branco | 134-07 (w4), 134-08 (w5) | `MeusAnunciosTest::degradacao_graciosa_mostra_ultimo_snapshot_com_selo` | `--filter=MeusAnuncios` | ⬜ pending |
| D-09 | Triagem agrupa por motivo, o total não é a soma dos chips, e o clique filtra | 134-07 (w4), 134-08 (w5) | `MeusAnunciosTest::triagem_agrupa_por_motivo_e_o_clique_filtra` | `--filter=MeusAnuncios` | ⬜ pending |
| D-10 | Checklist dos sinais fecha com a nota exibida | 134-03 (w2), 134-10 (w7) | `NotaEcfFecharComContaTest`, `DetalheAnuncioTest::soma_dos_sinais_verdadeiros_fecha_com_a_nota` | `--filter=Phase134` | ⬜ pending |
| D-11 | Nenhum write na API do ML em todo o caminho da fase | 134-01, 134-04, 134-05, 134-07, 134-08, 134-10 | `SondagemSaudeMlTest`, `ColetaAcervoTest`, `RotacaoDetalheTest`, `estrutura-meus-anuncios.test.js` | `--filter=Phase134` mais `npm run test:js` | ⬜ pending |
| D-12 | Ordenação por gravidade determinística **sem** dados da camada cara | 134-07 (w4) | `OrdenacaoGravidadeTest` | `--filter=OrdenacaoGravidade` | ⬜ pending |
| D-13 | 4 abas, Meus Anúncios como inicial | 134-08 (w5) | `estrutura-meus-anuncios.test.js` | `npm run test:js` | ⬜ pending |
| D-14 | Sub-abas Publicados/Rascunhos; `publicarLote` segue funcionando após migrar | 134-08 (w5), 134-09 (w6) | `RascunhosMeusAnunciosTest::publicar_lote_continua_funcionando_a_partir_da_tela_nova` | `--filter=RascunhosMeusAnuncios` | ⬜ pending |
| D-15 | Módulo continua `role:admin`, sem item de menu | 134-07 (w4) | `MeusAnunciosTest::consultor_nao_acessa_meus_anuncios` | `--filter=MeusAnuncios` | ⬜ pending |
| D-16 | "Saúde do anúncio" **permanece** no wizard; bloco de Rascunhos **saiu** | 134-09 (w6) | `tests/js/estrutura-anunciar-ml.test.js` | `node --test tests/js/estrutura-anunciar-ml.test.js` | ⬜ pending |
| D-17 | Item com variações grava agregado do pai mais `has_variations` mais JSON | 134-02 (w1), 134-04 (w3) | `ColetaAcervoTest::item_com_variacoes_grava_uma_linha_com_o_agregado` | `--filter=ColetaAcervo` | ⬜ pending |
| D-18 | Item de catálogo não coberto aparece como **"não avaliado"** | 134-03 (w2), 134-05 (w3), 134-08 (w5) | `NotaEcfFecharComContaTest::buybox_nao_avaliado_nunca_vira_motivo`, `RotacaoDetalheTest` | `--filter=Phase134` | ⬜ pending |
| D-19 | Coleta separada em camada barata e camada cara | 134-04 (w3), 134-05 (w3), 134-06 (w4) | `ColetaAcervoTest`, `RotacaoDetalheTest` | `--filter=Phase134` | ⬜ pending |
| D-20 | Varredura usa `scroll_id` e sobrevive a conta com mais de mil itens | 134-04 (w3) | `ColetaAcervoTest::varredura_usa_scroll_e_nao_offset`, `conta_com_mais_de_mil_itens_e_varrida_por_completo` | `--filter=ColetaAcervo` | ⬜ pending |
| D-21 | Sondagem executada; sem saúde do ML, a tela exibe ausência explícita | 134-01 (w1), 134-08 (w5) | `SondagemSaudeMlTest` mais o VEREDICTO registrado no SUMMARY | `--filter=SondagemSaudeMl` | ⬜ pending |
| D-22 | Mesmo fixture pontua igual no scorer JS e no PHP; base 86, nunca renormalizada | 134-03 (w2), 134-08 (w5), 134-10 (w7) | `NotaEcfFecharComContaTest` mais `tests/js/notaEcfConcordancia.test.js` | `--filter=NotaEcfFecharComConta` mais `npm run test:js` | ⬜ pending |
| D-23 | Rotação por fatia cobre 100% do acervo em N execuções, sem item órfão; N é opção do comando | 134-05 (w3), 134-06 (w4) | `RotacaoDetalheTest::rotacao_cobre_todo_o_acervo_em_n_execucoes`, `ComandosAcervoTest` | `--filter=Phase134` | ⬜ pending |
| V5 | "Atualizar agora" rejeita empresa não autorizada / sem token ML | 134-07 (w4) | `MeusAnunciosTest::atualizar_agora_enfileira_e_rejeita_empresa_sem_token` | `--filter=MeusAnuncios` | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Nesta fase, as lacunas de Wave 0 não viraram um plano isolado de scaffolds: cada arquivo de
teste nasce **no mesmo plano que constrói o comportamento que ele prova**, com ownership
exclusivo por arquivo (nenhum plano da mesma wave escreve no mesmo arquivo de teste). O que
o plano 134-01 entrega, e que é pré-requisito de todos os demais, são as **fixtures reais da
API** e o arquivo de configuração da fase — sem eles os testes seguintes validariam um shape
inventado.

- [x] Fixtures de resposta real da API do ML (scroll, multiget, item com variações, `price_to_win`, `visits`, sondagem de `performance`) → **plano 134-01, wave 1**. **Não inventar o shape do payload**: a pesquisa já provou que a documentação do ML mente em pelo menos um ponto (`/visits/items?ids=` promete lote de 20 e aceita 1)
- [x] `tests/Unit/Phase134/SondagemSaudeMlTest.php` → plano 134-01, wave 1 (D-21, D-11, parâmetros da fase)
- [x] `tests/Unit/Phase134/SchemaAcervoTest.php` → plano 134-02, wave 1 (D-07, D-17, gate de nome de índice do MariaDB)
- [x] `tests/Unit/Phase134/NotaEcfFecharComContaTest.php` mais `tests/js/notaEcfConcordancia.test.js` → plano 134-03, wave 2 (D-22, D-18)
- [x] `tests/Unit/Phase134/ColetaAcervoTest.php` → plano 134-04, wave 3 (D-17, D-20, D-04, D-11)
- [x] `tests/Unit/Phase134/RotacaoDetalheTest.php` → plano 134-05, wave 3 (D-23, D-18). Divisão deliberada do `ColetaAcervoTest` originalmente previsto, para que camada barata e camada cara tenham arquivos de teste distintos, como os serviços
- [x] `tests/Unit/Phase134/ComandosAcervoTest.php` → plano 134-06, wave 4 (D-06, D-07, D-23)
- [x] `tests/Feature/Phase134/MeusAnunciosTest.php` mais `tests/Unit/Phase134/OrdenacaoGravidadeTest.php` → plano 134-07, wave 4 (D-01, D-02, D-05, D-08, D-09, D-11, D-12, D-15, V5)
- [x] `tests/js/estrutura-meus-anuncios.test.js` → plano 134-08, wave 5 (contrato de design: 4 tamanhos, 2 pesos, espaçamento múltiplo de 4, D-11, D-22)
- [x] `tests/js/estrutura-anunciar-ml.test.js` mais `tests/Feature/Phase134/RascunhosMeusAnunciosTest.php` → plano 134-09, wave 6 (D-16, D-14)
- [x] `tests/Feature/Phase134/DetalheAnuncioTest.php` → plano 134-10, wave 7 (D-10, D-07, D-22)

---

## Manual-Only Verifications

| Behavior | Decisão | Why Manual | Test Instructions |
|----------|---------|------------|-------------------|
| Sondagem de `/item/{id}/performance` em item **clássico** real | D-21 | Depende de conta e item reais de produção; nenhum fixture pode provar se o endpoint responde ao app | Rodar leitura contra um item sem `user_product_id` numa conta com token ativo. Registrar o resultado no PLAN/SUMMARY. **Somente leitura** — D-11 |
| "Abro, escolho a empresa e em 5 segundos sei o que fazer" | Critério de aceite do usuário | Julgamento de produto, não asserção | UAT em `/gsd:verify-work`: abrir a tela numa empresa real com acervo grande e cronometrar até a primeira ação óbvia |
| Legibilidade da triagem e do gráfico da série | D-09 / D-07b | Visual | UAT com a tela renderizada; `/gsd:ui-phase` define o contrato antes |
| Coleta contra conta grande real (66k itens) sem estourar tempo/rate limit | D-19 / D-23 | Só se prova contra volume real; `Http::fake()` não mede tempo de rede | Rodar o comando com `--company=` na maior conta e medir duração e ocorrência de 429 |

---

## Validation Sign-Off

- [x] Toda task tem verify `<automated>` ou dependência declarada de Wave 0
- [x] Continuidade de amostragem: não existem 3 tasks consecutivas sem verify automatizado
- [x] Wave 0 cobre todas as referências MISSING
- [x] Nenhuma flag de watch mode
- [x] Feedback latency < 60s
- [x] `nyquist_compliant: true` no frontmatter

**Approval:** aprovado no planejamento da fase (2026-08-10) — 10 planos, 7 waves, todas as decisões D-01 a D-23 com teste ou checkpoint mapeado
