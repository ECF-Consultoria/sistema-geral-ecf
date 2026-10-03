# Fase 164 — Baseline de testes (antes de mexer)

- Data/hora: 2026-10-02 (medida antes de qualquer mudança de código da fase)
- `git rev-parse HEAD`: `b3c5cc537225342ac9df77001b5d88678608b830`
- Branch: `feat/publicador-ml-261001`
- Autoloader conferido: `C:\tmp\ecf-publicador-spec-261001\app\Models\PubRascunho.php` (carrega ESTE worktree)
- Saídas brutas: `C:/tmp/ecf-160-baseline/g1.txt` … `g8.txt`

| # | Grupo (comando) | Testes | Asserções | Falhas | Erros | Pulados | Exit | Tempo |
|---|---|---|---|---|---|---|---|---|
| 1 | Publicador (`tests/Unit/Publicador tests/Feature/Publicador`) | 230 | 952 | 0 | 0 | 0 | 0 | 16 s |
| 2 | `tests/Feature/PortalCliente` | 240 | 2123 | 0 | 0 | 0 | 0 | 70 s |
| 3 | `tests/Feature/Phase75` | 43 | 151 | 0 | 0 | 0 | 0 | 18 s |
| 4 | `tests/Feature/Phase76` | 23 | 103 | 0 | 0 | 0 | 0 | 6 s |
| 5 | `tests/Feature/Phase77` | 33 | 92 | 0 | 0 | 0 | 0 | 5 s |
| 6 | `tests/Feature/Phase134` | 24 | 107 | 0 | 0 | 0 | 0 | 5 s |
| 7 | Soltos (AnuncioIaAnalise, AnuncioIaRascunho, AnunciosPolosNaListagem, MlTokenAncoraPolos) | 52 | 189 | 0 | 0 | 0 | 0 | 10 s |
| 8 | `npm run test:js` (node --test) | 476 | n/d | 2 | 0 | 0 | 1 | 2 s |

Os grupos 3 a 6 saem "OK, but there were issues" apenas por PHPUnit Deprecations (4, 2, 36 e 27), não por falha.

## Falhas PRÉ-EXISTENTES (não corrigidas aqui)

Grupo 8 (`test:js`), 474 passam e 2 falham:

1. `Características secundárias nasce recolhido (é o grupo que mais infla)` — `AssertionError`: o fonte do componente de planilha não casa com `/RECOLHIDOS_INICIAIS = \[G_SECUND\]/`.
2. `FASES_TERMINAIS cobre as três fases de saída, com as strings exatas da planilha` — `deepStrictEqual`: o código tem `['Encerrado','Protocolo Churn','Desistência','Churn']` e o teste espera `['Encerrado','Protocolo Churn','Churn']`.

Nenhuma das duas tem relação com o Publicador (planilha / fases de Polos).

## Falha INTERMITENTE conhecida (depende da rede — não é regressão)

Grupo 3 (`tests/Feature/Phase75`): `PublicarEmpresaNaoAtribuidaTest::test_admin_nao_recebe_403_no_update`
falhou uma vez no gate da wave 3 (2026-10-02) com 500 — `RuntimeException: [MLB Coleta] Falha ao obter
app token: HTTP 400`. O teste não tem `Http::fake`: o `PUT /mlb/anuncios/rascunho/{id}` do assistente
antigo valida o título por `MlCatalogoMetaService::categoria('')`, que pede um app token REAL ao ML
(`MlColetaService::getAppToken`, client_credentials do `.env`). Na baseline a API respondeu 200; no
gate respondeu 400; rodado de novo, sozinho, passou. A fase não tocou nesse caminho (só apagou
`index`/`empresas` do `MlbAnuncioController`). Se reaparecer no gate final, rodar o teste isolado
antes de chamar de regressão.

## Regra de comparação

Gate da fase = cada grupo com contagem de testes maior ou igual à desta tabela e nenhuma falha nova; os testes do Portal Anunciar removidos em 164-15 saem da conta com nome listado. No grupo 8 as 2 falhas acima são o piso conhecido.

## Depois da fase (164-15)

- Data: 2026-10-02, depois da remoção do Anunciar do Portal (commits `eaa03321` rotas/allowlist/menu e `2cda5bc3` código morto).
- Branch `feat/publicador-ml-261001`, mesmo worktree. Saídas brutas: `C:/tmp/ecf-160-final/` (`g1.txt`, `g2.txt`, `f3.txt`…`f7.txt`, `g8.txt`).
- Rodado um grupo por vez, redirecionado para arquivo (sem pipe).

| # | Grupo | Antes: testes / asserções / falhas / exit | Depois: testes / asserções / falhas / exit | Diferença |
|---|---|---|---|---|
| 1 | Publicador (`tests/Unit/Publicador tests/Feature/Publicador`) | 230 / 952 / 0 / 0 | 350 / 1637 / 0 / 0 | +120 testes (os da fase, descontados 11 removidos) |
| 2 | `tests/Feature/PortalCliente` | 240 / 2123 / 0 / 0 | 231 / 1872 / 0 / 0 | −14 (`AnunciarEstruturaTest`) +5 (`PortalSemAnunciarTest`) |
| 3 | `tests/Feature/Phase75` | 43 / 151 / 0 / 0 | 43 / 151 / 0 / 0 | igual |
| 4 | `tests/Feature/Phase76` | 23 / 103 / 0 / 0 | 23 / 103 / 0 / 0 | igual |
| 5 | `tests/Feature/Phase77` | 33 / 92 / 0 / 0 | 33 / 92 / 0 / 0 | igual |
| 6 | `tests/Feature/Phase134` | 24 / 107 / 0 / 0 | 24 / 107 / 0 / 0 | igual |
| 7 | Soltos (4 arquivos) | 52 / 189 / 0 / 0 | 52 / 190 / 0 / 0 | +1 asserção |
| 8 | `npm run test:js` | 476 / n/d / 2 / 1 | 624 / n/d / 2 / 1 | +148 testes; as MESMAS 2 falhas |

Deprecations idênticas à baseline nos grupos 3 a 6 (4, 2, 36 e 27). Nenhuma falha nova; a falha intermitente do Phase75 (`test_admin_nao_recebe_403_no_update`) NÃO reapareceu.

Regra do gate (contagem ≥ baseline − removidos): grupo 2 = 240 − 14 = 226 ≤ 231; grupo 1 = 230 ≤ 350. Nos demais, igual ou maior.

### Testes removidos com o Anunciar do Portal

`tests/Feature/PortalCliente/Estrutura/AnunciarEstruturaTest.php` (14 testes, arquivo apagado em `eaa03321`):

| Teste removido | Onde vive o caso que continua valendo |
|---|---|
| `test_a_pagina_abre_marca_o_submodulo_e_separa_a_anunciar_de_publicados` | Não continua: era a página do Portal. O menu sem o Anunciar: `PortalSemAnunciarTest::test_o_mapeamento_estrutural_tem_cinco_submodulos` e `AcessoAoModuloEstruturaTest::test_a_entrada_abre_a_lista_...` |
| `test_abrir_pre_preenche_titulo_da_aba_anuncios_e_preco_da_precificacao_e_o_rascunho_salva` | `EstadoDoProdutoTest` (efetivos de título/preço vindos da oferta) e `MlbPublicadorTest` (salvar por parte) |
| `test_foto_sobe_para_o_ml_e_entra_no_rascunho` | `Publicador/ImagensTest` e `MlbPublicadorTest` (foto) |
| `test_a_ordem_das_fotos_e_a_do_anuncio_e_reordenar_exige_conferir_de_novo` | `Publicador/ImagensTest` + `tests/js/fotos-do-par.test.js` (ordem) e `ConferenciaTest` (revisão invalida a conferência) |
| `test_sugestoes_de_categoria_trazem_o_caminho_completo_e_uma_falha_nao_derruba_as_outras` | `CategoriaBuscaServiceTest` (164-08) |
| `test_conferir_lista_pendencias_locais_sem_chamar_o_ml_e_traduz_os_erros_do_ml` | `ConferenciaTest` / `MlbPublicadorTest` (conferir) |
| `test_publicar_o_par_grava_os_dois_mlb_completa_os_planejados_e_a_oferta_vira_ok` | `Publicador/PublicacaoTest` |
| `test_publicar_duas_vezes_nao_chama_o_ml_de_novo` | `PublicacaoTest` (idempotência, item `SENT` antes do POST) |
| `test_falha_no_premium_deixa_parcial_e_o_retry_publica_so_o_premium` | `PublicacaoTest` (`PARTIALLY_PUBLISHED`, reconciliação) |
| `test_o_que_mudou_depois_da_conferencia_precisa_ser_conferido_de_novo` | `ConferenciaTest` (hash/revisão) |
| `test_oferta_com_classico_importado_publica_so_o_premium` | `PublicacaoTest` (`ja_publicados`, RN-94) |
| `test_oferta_completa_nao_publica_de_novo` | `PublicacaoTest` / `EstadoDoProdutoTest` (`ja_publicados`) |
| `test_oferta_de_outra_empresa_responde_404` | Sem equivalente (o Portal não publica mais); o isolamento por empresa do restante do módulo segue em `AcessoAoModuloEstruturaTest::test_id_de_outra_empresa_responde_404_em_toda_escrita` |
| `test_sem_conta_do_ml_nada_vai_para_o_ml` | `ConferenciaContaNaoLiberadaTest` (D26) e `PublicaMlbEmpresaSemCompanyTest` |

`tests/Feature/Publicador/PortalPublicadorTest.php` (10 testes, apagado em `eaa03321`):

| Teste removido | Onde vive o caso |
|---|---|
| `test_fora_do_piloto_e_404_e_a_pagina_diz_qual_formulario_usar` | Não continua (não há mais piloto); `PortalSemAnunciarTest` (404 em tudo) |
| `test_oferta_de_outra_empresa_e_404` | Não continua (rota do Portal) |
| `test_abrir_cria_o_rascunho_com_os_tipos_que_faltam_e_le_a_conta` | `EstadoDoProdutoTest` / `MlbPublicadorTest` |
| `test_categoria_formulario_e_salvar_por_parte` | `MlbPublicadorTest` |
| `test_eixos_geram_as_variantes_e_os_dados_por_variante_sao_gravados` | `MlbPublicadorTest` / `Unit/Publicador` (eixos e variantes) |
| `test_foto_sobe_entra_na_geral_e_foto_pequena_volta_com_o_problema` | `ImagensTest` |
| `test_conferir_vai_para_a_fila_e_publicar_sem_conferencia_e_recusado` | `MlbPublicadorTest` |
| `test_publicar_com_conferencia_valida_dispara_o_job` | `MlbPublicadorTest` |
| `test_cards_do_piloto_mostram_o_rascunho_novo_e_o_estado_marca_o_que_ja_esta_no_ar` | Não convertido (específico do Portal); o selo de prontidão vive na Tela B (`MlbPublicadorProdutosTest`) |
| `test_simulador_usa_tarifa_e_frete_do_ml` | `MlbPublicadorTest` (simular) |

Outros testes alterados em `2cda5bc3`: `CategoriaBuscaServiceTest::test_o_servico_do_portal_delega_e_devolve_exatamente_o_mesmo` removido (o service do Portal saiu; a busca em si segue testada); `EstadoDoProdutoTest` (2 asserções) e `OfertaExcluidaNoPortalTest` (1) deixaram de olhar a chave `oferta` do estado, que saiu; `tests/js/publicador-mesa.test.js` perdeu o teste que lia `EditorPublicador.jsx` (625 → 624 em `test:js`).
