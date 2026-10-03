---
phase: 164
slug: publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-10-02
---

# Fase 164 — Estratégia de validação

> Contrato de validação da fase: o que é medido, quando e com que comando. Fonte: `164-RESEARCH.md` §7.

---

## Infraestrutura de teste

| Propriedade | Valor |
|----------|-------|
| **Framework** | PHPUnit 11.5.55 (PHP 8.2.12, `C:\xampp\php\php.exe`) + `node --test` (`npm run test:js`) |
| **Config** | `phpunit.xml` (SQLite `:memory:`) |
| **Comando rápido** | `C:\xampp\php\php.exe vendor/bin/phpunit tests/Unit/Publicador tests/Feature/Publicador` |
| **Suíte da fase (por pastas — a completa estoura 512 MB)** | o rápido + `tests/Feature/PortalCliente`, `tests/Feature/Phase75`, `Phase76`, `Phase77`, `Phase134`, `tests/Feature/AnuncioIaAnaliseTest.php`, `AnunciosPolosNaListagemTest.php`, `MlTokenAncoraPolosTest.php`, `npm run test:js` |
| **Tempo estimado** | rápido ~16 s; suíte da fase ~3 min |
| **Baseline medida** | `tests/Unit/Publicador` + `tests/Feature/Publicador` = **230 testes / 952 asserções, verde** (02/10). As demais pastas são medidas na Wave 0 e gravadas em `164-BASELINE-TESTES.md` antes de mexer. |

Cuidado: `| tail` engole o exit code do phpunit — usar `${PIPESTATUS[0]}`.

---

## Amostragem

- **Depois de cada commit de tarefa:** comando rápido + o teste novo da tarefa
- **Depois de cada onda:** suíte da fase (pastas acima)
- **Antes de `/gsd-verify-work`:** suíte da fase verde, `npm run build` com o manifest conferido, roteiro manual do MariaDB e conferência visual
- **Latência máxima de retorno:** 30 s por tarefa (o rápido roda em 16 s)
- **Mutação:** teste novo com `Http::fake` registra o fake UMA vez (learnings `publicador-ml.md` §5) e é conferido com uma mutação que o quebra (ex.: âncora de token errada)

---

## Mapa critério → teste

| Critério (ROADMAP 160) | Comportamento | Tipo | Comando / arquivo | Existe? |
|---|---|---|---|---|
| 1 | Entrada lista Polos · Incubadora · Gestão (D23); Incubadora por `tipo`/`projeto`/`fase`; arquivada fora; situação do Portal; não-admin 403 | Feature | `tests/Feature/Publicador/MlbPublicadorEntradaTest.php` | ❌ W0 |
| 2 | "Sincronizar do Portal": cria só ofertas sem produto; 2ª chamada não duplica; nunca apaga; sem Portal ⇒ recusa clara | Feature + Unit | `tests/Feature/Publicador/SincronizaPortalTest.php` | ❌ W0 |
| 3 | Produto com `oferta_id` herda título/preço ao vivo; digitado vence; publicar cadastra o MLB na aba Anúncios; sem `oferta_id` não cadastra | Feature | `DadosEfetivosTest` + `PublicacaoTest` adaptados | parcial |
| 4 | `MlbEmpresa` sem `Company` publica com token em `mlb_empresa_id` (`Http::fake`); sem token ⇒ `V-ACC-01` | Feature | `tests/Feature/Publicador/PublicaMlbEmpresaSemCompanyTest.php` | ❌ W0 |
| 5 | Migration: roda no SQLite; backfill dos rascunhos sem `produto_id`; idempotente; os rascunhos abrem pelo produto | Feature (migration) | `tests/Feature/Publicador/MigracaoProdutoRascunhoTest.php` | ❌ W0 |
| 5 (MariaDB) | `SHOW CREATE TABLE pub_produtos` / `pub_rascunhos`, `SHOW INDEX`, migration com `--path` no MariaDB local | **manual** | roteiro em `164-RESEARCH.md` §2.3 → VERIFICATION | — |
| 6 | Meus/Massa/Histórico seguem; a aba Individual leva ao Publicador; assistente antigo escondido mas abrindo rascunhos e "Anunciar semelhante" (D22); botão IA grava em `pub_*` | Feature + JS | suítes do módulo adaptadas + `tests/Feature/Publicador/IaParaRascunhoTest.php` | parcial |
| 7 | Rotas do Anunciar no Portal ⇒ 404 e fora da allowlist; submódulos = 5; Lista SKUs/Precificação/Anúncios/Planejamento/Mapeamento intactos | Feature | `tests/Feature/PortalCliente/PortalSemAnunciarTest.php` + `tests/Feature/PortalCliente` inteira | ❌ W0 |
| 8 | Layout das telas pelo Stitch/UI-SPEC, sem erro de console | **manual** | Puppeteer no SQLite isolado (learnings `publicador-ml.md` §7) | — |
| Segurança | Produto de outra empresa / arquivada / inexistente ⇒ 404; não-admin ⇒ 403; conta não liberada (D21) ⇒ `RegraViolada` | Feature | `tests/Feature/Publicador/MlbPublicadorAcessoTest.php` | ❌ W0 |

*Status: ⬜ pendente · ✅ verde · ❌ vermelho · ⚠️ instável*

---

## Wave 0

- [ ] `164-BASELINE-TESTES.md` — contagem das pastas da suíte da fase ANTES de mexer (CLAUDE.md: fase com dado em produção exige baseline)
- [ ] `tests/Feature/Publicador/Concerns/CenarioCadeira.php` — migrar para `PubProduto` (base de 31 testes)
- [ ] factory/helper de `PubProduto` e de `MlbEmpresa` com `MlToken` por `mlb_empresa_id`
- [ ] esqueletos dos testes novos marcados ❌ W0 acima

---

## Verificações só manuais

| Comportamento | Critério | Por que manual | Como |
|---|---|---|---|
| Schema real no MariaDB (FK/unique, nomes, nullability) | 5 | O SQLite dos testes não reproduz os erros 1553/1059/1830 (learnings `desempenho-bonificacao.md` §6) | `php artisan migrate --path=<cada arquivo>` no MariaDB local + `SHOW CREATE TABLE` e `SHOW INDEX` |
| Telas no navegador | 8 | Renderização e interação | Puppeteer no SQLite isolado; desktop e celular; 0 erro de console |
| E2E na #459 (fora da fase, pré-requisito D20) | — | Cria anúncio de verdade | Só com a confirmação do usuário antes de cada `POST /items` |

---

## Assinatura da validação

- [ ] Toda tarefa tem verificação `<automated>` ou dependência da Wave 0
- [ ] Continuidade: nunca 3 tarefas seguidas sem verificação automática
- [ ] A Wave 0 cobre todas as referências ❌
- [ ] Nenhum comando em modo watch
- [ ] Retorno < 30 s por tarefa
- [ ] `nyquist_compliant: true` no frontmatter

**Aprovação:** pendente
