---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
verified: 2026-10-02T00:00:00Z
status: human_needed
score: 8/8 critérios de sucesso verificados no código (SC5 e SC3-publicação com parte só provável em produção)
overrides_applied: 0
human_verification:
  - test: "Em produção, depois do deploy: abrir os 2 rascunhos de teste da #459 pelo produto"
    expected: "Os 2 rascunhos abrem (backfill pub_rascunhos.produto_id) sem perda; migration roda sem erro no MariaDB de produção"
    why_human: "Backfill provado só em SQLite; no MariaDB local havia 0 linhas. Produção não recebeu deploy (SC5)."
  - test: "WR-B02 no MariaDB real: salvar no editor enquanto a IA gera"
    expected: "Espera de milissegundos pela trava lockForUpdate única do rascunho, sem deadlock nem 500"
    why_human: "lockForUpdate não é exercido no SQLite dos testes"
  - test: "Editor no navegador: CR-F01, CR-F02, WR-F02 (digitar e acionar estrutura; autosave durante/depois da IA; falha de rede com nova tentativa; sair da página com alteração pendente)"
    expected: "Nada digitado é descartado; a IA não é sobrescrita; falha tenta de novo; saída pede confirmação"
    why_human: "Novo modelo de salvamento só é provado em interação real"
  - test: "Passo D20 em produção: php artisan publicador:empresa-teste, depois --confirmar"
    expected: "Dev 02 (#459) entra como empresa da Incubadora, conta liberada"
    why_human: "Ação do usuário em produção"
  - test: "E2E real do Publicador só na #459, com confirmação antes de cada POST /items; verificar cadastro do MLB na aba Anúncios da oferta (SC3) e publicação de empresa sem Portal com token por mlb_empresa_id (SC4)"
    expected: "Item criado no ML; MLB aparece na oferta; empresa sem Company publica"
    why_human: "Exige chamada real ao ML e deploy; vetado nesta verificação"
  - test: "Pós-deploy: Portal do cliente sem Anunciar para clientes reais (menu e URL antiga)"
    expected: "Menu sem Anunciar; Lista SKUs, Precificação, Anúncios, Planejamento e Mapeamento intactos"
    why_human: "Confirmação em ambiente real (cobertura automatizada existe)"
---

# Fase 160: Verificação do Publicador no sistema interno

**Objetivo:** equipe ECF publica no ML por `/mlb/anuncios` (Polos | Incubadora | Gestão), com herança do Portal e cadastro próprio para empresa sem Portal; motor reaproveitado; Anunciar sai do Portal.
**Status:** human_needed (nenhuma lacuna bloqueante; restam itens que só ambiente real prova).
**Re-verificação:** Não, verificação inicial.

## Critérios de sucesso

| SC | Status | Evidência (código lido e testes rodados por mim) |
|---|---|---|
| SC1 entrada por programa, só admin | VERIFICADO | `routes/mlb_anuncios.php`: `/` -> `MlbPublicadorEntradaController@index`, grupo `auth, verified, role:admin`; `MlbPublicadorEntradaTest` 11 testes OK, `MlbPublicadorAcessoTest` 9 OK |
| SC2 Sincronizar do Portal idempotente, não apaga | VERIFICADO | `PublicadorSincronizaPortalService::sincronizar`; rota `publicador.sincronizar`; `SincronizaPortalTest` 8 OK |
| SC3 herança título/preço, digitado vence, publicar cadastra MLB na oferta | VERIFICADO (publicação real pendente) | `PublicacaoService` (~l.596-602) chama `anuncios->cadastrar($oferta, ...)` com checagem de duplicidade; `DadosEfetivosTest`, `OfertaExcluidaNoPortalTest` 9 OK; Publicador 376/1874 verde (relato do gate) |
| SC4 empresa sem Portal, token por `mlb_empresa_id` | VERIFICADO (E2E pendente) | `PublicaMlbEmpresaSemCompanyTest` 6 OK; rota criar produto manual |
| SC5 rascunhos abrem pelo produto; migration sem perda | VERIFICADO em SQLite/MariaDB local; produção pendente | migrations `2026_10_02_100000/100100/200000`; `MigracaoProdutoRascunhoTest` 7 OK; `MigracoesDaFaseDetectamMariaDbTest` existe (WR-B06) |
| SC6 Meus Anúncios, Em massa, Histórico mantidos; IA vira botão | VERIFICADO | páginas `MeusAnuncios/AnunciarMassa/AnunciosHistorico` presentes; `Mesa/BotaoAnunciarPorIa.jsx`; `IaParaRascunhoTest`, soltos 52/190 verde (relato) |
| SC7 Anunciar sai do Portal | VERIFICADO | `routes/web.php` l.210 só comentário; sem "anunciar" na allowlist `RestringeDominioDoPortal`; `PortalSemAnunciarTest` 5 OK (104 asserções); PortalCliente 231/1872 verde (relato) |
| SC8 layout Stitch sem erro de console | VERIFICADO (por registro) | 160-14: Puppeteer 0 erro, 39 capturas, aprovado pelo usuário em 02/10 |

## Decisões D12–D27

Contabilizadas pelos frontmatters `requirements:` dos 15 planos (01..15) e cobertas por commits/testes: D12-D14 (entrada, IA botão) planos 03/09/10/12; D15/D16/D27 planos 01/02/06/07/11; D17 rota admin; D18 plano 15 (`eaa03321`); D19 baseline + gate final (`74ded401`); D20 comando `publicador:empresa-teste` (`PublicadorEmpresaTesteCommandTest`); D21/D26 plano 07 (`ConferenciaContaNaoLiberadaTest`) e correções CR-B01/WR-B04; D22 plano 11/15; D23 plano 03/10; D24/D25 planos 04/05/13/14. Nenhuma lacuna.

## Gate de testes (D19)

Conforme relato do orquestrador: Publicador 376/1874, PortalCliente 231/1872, soltos 52/190, Phase75 43/151, `test:js` 645 com 2 falhas pré-existentes da baseline. `MeuPainelControllerTest` x2 falha desde antes da fase (Fase 159, `a2250d35`). Reexecutei 8 arquivos pontuais: todos verdes.

## Code review

13 achados corrigidos (CR-B01, CR-B02, CR-F01, CR-F02, WR-B01..B06, WR-F02, WR-F04, WR-F07) com commits individuais. Fora do escopo por escolha do usuário, **dívida conhecida e não lacuna**: 14 warnings e 16 infos de `160-REVIEW.md`. Nenhum deles foi identificado como quebrando um SC. 6 pendências de layout não pedidas em `160-14-SUMMARY.md` também são dívida.

## Anti-padrões

Não houve varredura exaustiva de TBD/FIXME; sem evidência de bloqueador nos arquivos lidos.

## Resumo

Nenhuma lacuna encontrada contra SC1-SC8. Não houve deploy nem push; o que depende de produção, MariaDB real, navegador e ML real está em `human_verification`.
