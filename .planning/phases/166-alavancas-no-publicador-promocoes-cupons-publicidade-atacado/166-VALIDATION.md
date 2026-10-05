---
phase: 166
slug: alavancas-no-publicador-promocoes-cupons-publicidade-atacado
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-04
---

# Fase 166 — Estratégia de validação

> Contrato de validação da fase: como cada requisito é amostrado durante a execução.
> Fonte: `166-RESEARCH.md`, seção "Arquitetura de Validação".

---

## Infraestrutura de teste

| Propriedade | Valor |
|-------------|-------|
| **Framework** | PHPUnit 11.5 (suítes `Unit`/`Feature`, SQLite `:memory:`, `QUEUE_CONNECTION=sync`) + `node --test` |
| **Arquivo de config** | `phpunit.xml` / `package.json` (`test:js`) |
| **Comando rápido** | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Publicador/Alavancas` e `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Publicador/Alavancas` |
| **Suíte completa** | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Feature/Publicador > <arquivo>` + `tests/Unit/Publicador > <arquivo>` (ler o arquivo; `\| tail` engole o exit code; a suíte inteira estoura 512 MB) e `npm run test:js` |
| **Build** | `npm run build` + conferir `public/build/manifest.json` (worktree: `node_modules` ligado; build que sai 0 sem buildar é armadilha conhecida) |
| **Tempo estimado** | rápido ~60 s; completo ~6 min |

---

## Taxa de amostragem

- **Depois de cada commit de tarefa:** comando rápido + `npm run test:js` quando a tarefa toca front.
- **Depois de cada onda:** suíte completa do Publicador (Feature + Unit) e `npm run test:js`, comparadas ao baseline.
- **Antes do `/gsd:verify-work`:** suíte do Publicador verde contra o baseline, `npm run build` com manifest conferido, capturas visuais (learnings §7).
- **Latência máxima de retorno:** 120 s.

---

## Mapa de verificação por requisito

| Req | Comportamento | Tipo | Comando | Arquivo existe | Status |
|-----|---------------|------|---------|----------------|--------|
| AL166-01 | barra Publicar \| Alavancas; Publicar idêntico; sem `bg-ecf-yellow` | unit JS | `node --test tests/js/publicador-alavancas.test.js` | ❌ W0 | ⬜ |
| AL166-02 | 403 não admin; 404 chave inválida; `company-N`→canônica; sem token não chama o ML | feature | `phpunit tests/Feature/Publicador/Alavancas/AcessoAlavancasTest.php` | ❌ W0 | ⬜ |
| AL166-03 | panorama com 4 fontes; uma falha não derruba as outras | feature | `phpunit tests/Feature/Publicador/Alavancas/PanoramaTest.php` | ❌ W0 | ⬜ |
| AL166-04 | trava própria × trava de publicação (dois sentidos), por âncora, fail-closed | unit | `phpunit tests/Unit/Publicador/Alavancas/AlavancasLiberadasTest.php` | ❌ W0 | ⬜ |
| AL166-05 | escrita fora da lista = `RECUSADA` sem HTTP; token de outro vendedor recusa; guarda de fonte do caminho único | feature + unit | `phpunit tests/Feature/Publicador/Alavancas/TravaEscritaTest.php` e `tests/Unit/Publicador/Alavancas/UnicoCaminhoDeEscritaTest.php` | ❌ W0 | ⬜ |
| AL166-06 | `PENDENTE` antes do HTTP; resposta crua; `INCERTO` em timeout; sem token na linha; migration MariaDB-safe | feature | `phpunit tests/Feature/Publicador/Alavancas/HistoricoEscritaTest.php` e `MigracaoHistoricoTest.php` | ❌ W0 | ⬜ |
| AL166-07 | histórico só da empresa, paginado, filtros | feature | `phpunit tests/Feature/Publicador/Alavancas/HistoricoTelaTest.php` | ❌ W0 | ⬜ |
| AL166-08 | prévia sem escrita; confirmar exige assinatura válida; lote por job com `release` | feature | `phpunit tests/Feature/Publicador/Alavancas/PreviaEConfirmarTest.php` e `LoteJobTest.php` | ❌ W0 | ⬜ |
| AL166-09 | matriz por tipo de convite (corpo, método, `offer_id`, `stock`, sem preço onde "aceita") | feature | `phpunit tests/Feature/Publicador/Alavancas/ConvitesMatrizTest.php` | ❌ W0 | ⬜ |
| AL166-10 | regras do desconto individual + erros traduzidos | unit + feature | `phpunit tests/Unit/Publicador/Alavancas/PriceDiscountRegrasTest.php` e `tests/Feature/Publicador/Alavancas/PriceDiscountTest.php` | ❌ W0 | ⬜ |
| AL166-11 | campanha do vendedor e VOLUME do vendedor | feature | `phpunit tests/Feature/Publicador/Alavancas/CampanhaVendedorTest.php` | ❌ W0 | ⬜ |
| AL166-12 | lista de exclusão da conta e por produto | feature | `phpunit tests/Feature/Publicador/Alavancas/ExclusaoTest.php` | ❌ W0 | ⬜ |
| AL166-13 | cupons: criar/alterar/excluir e pôr/tirar produtos; saldo | feature | `phpunit tests/Feature/Publicador/Alavancas/CuponsTest.php` | ❌ W0 | ⬜ |
| AL166-14 | publicidade só GET, caminhos atuais, nenhum legado na fonte | feature + unit | `phpunit tests/Feature/Publicador/Alavancas/PublicidadeLeituraTest.php` e `tests/Unit/Publicador/Alavancas/SemEndpointLegadoTest.php` | ❌ W0 | ⬜ |
| AL166-15 | atacado % B2B com recomendação, `X-Version`, 409; nunca o absoluto | feature + unit | `phpunit tests/Feature/Publicador/Alavancas/AtacadoTest.php` | ❌ W0 | ⬜ |
| AL166-16 | quanto recebe (normal × promoção), frete desconhecido sinalizado, margem só com custo | feature | `phpunit tests/Feature/Publicador/Alavancas/AnaliseRecebeTest.php` | ❌ W0 | ⬜ |
| AL166-17 | alertas por config, nenhum bloqueia, sem ranking | unit | `phpunit tests/Unit/Publicador/Alavancas/AlertasTest.php` | ❌ W0 | ⬜ |
| AL166-18 | gates visuais nos arquivos novos; build compila a página | unit JS + build | `npm run test:js` e `npm run build` | ❌ W0 | ⬜ |
| AL166-19 | produtos da conta ao vivo (duas âncoras), multiget de 20, teto de paginação | feature | `phpunit tests/Feature/Publicador/Alavancas/ProdutosDaContaTest.php` | ❌ W0 | ⬜ |
| AL166-20 | mapeador de erros; 423 até 3×; escrita nunca repetida em 5xx/timeout | unit + feature | `phpunit tests/Unit/Publicador/Alavancas/MapeadorErroAlavancaTest.php` e `tests/Feature/Publicador/Alavancas/RobustezTest.php` | ❌ W0 | ⬜ |

*Status: ⬜ pendente · ✅ verde · ❌ vermelho · ⚠️ intermitente*

---

## Onda 0

- [ ] Baseline: `tests/Feature/Publicador`, `tests/Unit/Publicador` e `npm run test:js` rodados ANTES de mexer, falhas pré-existentes anotadas (commit próprio).
- [ ] `tests/Feature/Publicador/Alavancas/Concerns/CenarioAlavancas.php` — trait com `Http::fake` de closure única (estado em propriedades, learnings §5), conta #459 com `MlToken` e variante `MlbEmpresa` sem `Company`, usuário admin.
- [ ] `tests/fixtures-ml/alavancas/*.json` — derivadas dos exemplos da doc oficial (`"origem": "doc-oficial"`), conferindo a grafia gravada com `git ls-files --stage | grep -i alavancas` (learnings §2).
- [ ] `tests/js/publicador-alavancas.test.js` — gates de fonte.

---

## Verificações só manuais (conta real #459, com confirmação do usuário antes de CADA escrita)

| Comportamento | Req | Por que manual | Como |
|---------------|-----|----------------|------|
| Permissão "Promoções" do app ECF ativa | AL166-09..13 | depende do DevCenter, fora do código | `GET /seller-promotions/users/{id}` na #459 ≠ 403 |
| Forma real de `price_per_quantity` no GET `prices` | AL166-15 | suposição A1 | ler um item da #459 |
| `offer_id` de candidato SMART/PRICE_MATCHING | AL166-09 | questão aberta 1 | sondagem só leitura |
| "Quanto recebe" em cofinanciada × Seller Center | AL166-16 | suposição A3 | comparar uma oferta real |
| Uma escrita pequena e reversível | AL166-05/06/10 | prova ponta a ponta | criar e remover um `PRICE_DISCOUNT` em anúncio de teste da #459 |
| Atacado: gravar 1 faixa e reler | AL166-15 | a #459 tem `business` | idem, com confirmação |
| Conferência visual | AL166-18 | layout | capturas no SQLite isolado (learnings §7) |

---

## Assinatura da validação

- [ ] Toda tarefa tem verificação automatizada ou dependência na Onda 0
- [ ] Continuidade: nenhuma sequência de 3 tarefas sem verificação automatizada
- [ ] A Onda 0 cobre todas as referências ❌
- [ ] Nenhum modo watch
- [ ] Latência de retorno < 120 s
- [ ] `nyquist_compliant: true` no frontmatter

**Aprovação:** pendente
