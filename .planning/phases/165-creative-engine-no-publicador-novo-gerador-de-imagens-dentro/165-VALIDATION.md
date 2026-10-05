---
phase: 165
slug: creative-engine-no-publicador-novo-gerador-de-imagens-dentro
status: planned
nyquist_compliant: true
wave_0_complete: false
created: 2026-10-04
updated: 2026-10-04
---

# Fase 165 — Estratégia de validação

> Contrato de validação da fase: o que se roda depois de cada tarefa, de cada onda e antes do `/gsd:verify-work`.
> Fonte: `165-RESEARCH.md` §8 (baseline medido) e "Arquitetura de Validação"; o mapa abaixo segue os arquivos de teste
> que os 8 planos criam (revisão dos planos, rodada 1).

---

## Infraestrutura de teste

| Propriedade | Valor |
|-------------|-------|
| **Framework** | PHPUnit 11.x (PHP) + `node --test` (gates de fonte JS) |
| **Config** | `phpunit.xml` (SQLite em memória, `QUEUE_CONNECTION=sync`, FKs ligadas); JS em `tests/js/*.test.js` |
| **Comando rápido** | `C:/xampp/php/php.exe -d memory_limit=1024M vendor/bin/phpunit tests/Feature/Phase165 tests/Unit/Phase165` + `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js` |
| **Suíte completa da fase** | Creative Engine antigo (`tests/Feature/Phase160 tests/Feature/Phase161 tests/Feature/Quick261003L8o tests/Unit/Phase160 tests/Unit/Phase161 tests/Unit/Quick261003L8o`) + Publicador (`tests/Feature/Publicador tests/Unit/Publicador`) + `npm run test:js`. Rodar por pasta, com a saída em arquivo (o `| tail` engole o exit code) |
| **Tempo estimado** | ~3–4 min a suíte completa da fase; o comando rápido, < 60 s |

**Baseline:** a pesquisa mediu em 04/10, antes do editor em 3 etapas (`cab40d48`): Creative antigo 164 testes / 738
asserções OK · Publicador 394 / 1995 OK · JS (mesa + editor + estrutura-anunciar-ml) 169 OK · `npm run test:js` inteiro
714/716 (as 2 falhas são antigas e de outras telas). O baseline OFICIAL é o que o plano 165-01 (Task 2) mede e commita
sozinho, antes de qualquer código, em `165-BASELINE-TESTES.md`.

---

## Taxa de amostragem

- **Depois de cada commit de tarefa:** o `<automated>` da tarefa + a suíte do arquivo de produção tocado.
- **Depois de cada onda:** a suíte completa da fase. Os números do baseline só podem subir; nenhuma falha nova.
- **Antes do `/gsd:verify-work`:** suíte completa verde + `npm run build` ("✓ built in") + conferência visual (165-08).
- **Latência máxima de feedback:** 60 s (comando rápido).

---

## Mapa de verificação por requisito

| Req | Plano(s) | Comportamento seguro | Tipo | Arquivo(s) de teste | Status |
|-----|----------|----------------------|------|---------------------|--------|
| CE165-01 | 165-01 | Migration aditiva e idempotente (roda 2×), sem enum, índices ≤ 64, `nullOnDelete` só em coluna `nullable`, `dropForeign` antes de `dropIndex`, `pub_grupo` sem índice; linha antiga intacta; helpers de retomada | unit (guarda por conteúdo) + feature | `tests/Unit/Phase165/PubColunasMigrationGuardaTest.php`, `tests/Feature/Phase165/PubColunasMigrationTest.php` | ⬜ |
| CE165-02 | 165-02 | `paraCriativo` com `pub_rascunho_id` devolve o contexto do Publicador; SEM ele, a saída é idêntica à de hoje; o rascunho apagado (FK ligada) cai na mensagem antiga; a mensagem nova só na corrida | feature | `tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php` + `tests/Feature/Phase160/CriativoGeracaoTest.php` (existente) | ⬜ |
| CE165-03 | 165-02 | Título efetivo, atributos com `value_name`, variações ativas, loja de `MlbEmpresa` sem `Company`; o kit do Publicador planeja pelo job sem mudança | feature | `tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php` | ⬜ |
| CE165-04 | 165-02 | O grupo da variação injeta o valor (ex.: `COLOR` = Azul) como fato; GENERAL não injeta; eixo customizado nunca entra | unit + feature | `tests/Unit/Phase165/ContextoDaVariacaoTest.php`, `tests/Feature/Phase165/CreativeContextBuilderPublicadorTest.php` | ⬜ |
| CE165-05 | 165-03, 165-04 | Referência: copia `pub_imagens` com arquivo para `creative-referencias/{token}/` (cópia efêmera; origem intacta); ignora `caminho` nulo; recusa foto de outro rascunho; teto de 14; validação no planejar | feature | `tests/Feature/Phase165/ReferenciaDoPublicadorTest.php`, `tests/Feature/Phase165/KitIdempotenciaTest.php` | ⬜ |
| CE165-06 | 165-04, 165-05 | Chave desligada → 404; produto fora do escopo → 404; kit de outro produto (pelo id) → 404; sem permissão → 403 depois do escopo; planejar → gerar → status → aprovar ponta a ponta com o provedor dublê; regenerar e aprovar o kit | feature | `tests/Feature/Phase165/CriativosEndpointsTest.php`, `tests/Feature/Phase165/FatiaFinaPontaAPontaTest.php`, `tests/Feature/Phase165/RegenerarEAprovarKitTest.php` | ⬜ |
| CE165-07 | 165-04 | Duplo clique de planejar devolve o mesmo kit; grupos diferentes coexistem; o kit de outro produto nunca volta; `atual` retoma; lock ocupado → 409 ou retomada | feature | `tests/Feature/Phase165/KitIdempotenciaTest.php` | ⬜ |
| CE165-08 | 165-03, 165-04 | Aprovar cria `PubImagem` + atribuição no FIM do grupo; dedupe por sha; conta não liberada = `pending` sem HTTP; PUBLISHING → 422; não grava `payload` nem chama `MlImagemService`; trava antes do snapshot; não trunca; re-adicionar (D-12) | feature | `tests/Feature/Phase165/ColocarFotoNoGrupoSobTravaTest.php`, `tests/Feature/Phase165/AprovacaoParaPubImagensTest.php`, `tests/Feature/Phase165/FatiaFinaPontaAPontaTest.php` | ⬜ |
| CE165-09 | 165-03, 165-05 | Fechar o kit apaga a referência e marca `referencias_apagadas_em`; `creative:limpar-referencias` recolhe o criativo do Publicador de 49 h; regenerar recusa kit fechado/sem referência sem subir o contador | feature | `tests/Feature/Phase165/RetencaoDoPublicadorTest.php`, `tests/Feature/Phase165/RegenerarEAprovarKitTest.php` | ⬜ |
| CE165-10 | 165-06, 165-07, 165-08 | Gates de fonte: `Mesa/PainelCriativos.jsx` em `CARDS` (sem `route(`, 24/15/13/11, 400/700, sem amarelo sólido, sem contador, sem caixa-alta); o hook usa `criarRota('mlb.anuncios.publicador','produto')` com `kit_id`, `setInterval` com limpeza e nenhum token; o `BlocoDeFotos` mostra "Gerar com IA" (LINK) pelo contexto, com o painel montado durante a releitura; prop `criativos_ia` | js + feature + visual | `tests/js/publicador-mesa.test.js`, `tests/js/publicador-editor.test.js` (ampliados), `tests/Feature/Phase165/EditorCriativosIaPropTest.php`, conferência visual (165-08) | ⬜ |
| CE165-11 | 165-04, 165-05, 165-06 | Limitadores nomeados reaproveitados (mesmo balde do fluxo antigo); 429 em pt-BR; confirmação de custo antes de `kit/gerar` | feature + js | `tests/Feature/Phase165/LimitesDoPublicadorTest.php`, `tests/js/publicador-mesa.test.js` (gate do painel) | ⬜ |
| CE165-12 | 165-01, 165-02, 165-05, 165-08 | Não regressão: assistente antigo e `mlb.anuncios.criativo.*` inalterados; NENHUM token de 32 caracteres (kit, slot, portador) em resposta nova; o fluxo antigo não recebe kit do Publicador; teste da guarda das rotas antigas ("incomplete" até a guarda existir; 404 depois) | regressão | `tests/Feature/Phase165/NenhumTokenNoNavegadorTest.php`, `tests/Feature/Phase165/RotasAntigasComKitDoPublicadorTest.php`, suítes Phase160/161/Quick261003L8o, `tests/js/estrutura-anunciar-ml.test.js` | ⬜ |

*Status: ⬜ pendente · ✅ verde · ❌ vermelho · ⚠️ instável*

---

## Wave 0

Nesta fase os testes nascem NO MESMO plano que introduz cada comportamento (TDD por tarefa); não há plano separado de
Wave 0. Cada arquivo da coluna "Arquivo(s) de teste" é criado pela tarefa que o lista em `files_modified`:

- [ ] 165-01: `PubColunasMigrationGuardaTest`, `PubColunasMigrationTest`
- [ ] 165-02: `Concerns/CenarioCriativoDoPublicador` (trait base), `ContextoDaVariacaoTest`, `CreativeContextBuilderPublicadorTest`
- [ ] 165-03: `ColocarFotoNoGrupoSobTravaTest`, `AprovacaoParaPubImagensTest`, `RetencaoDoPublicadorTest`, `ReferenciaDoPublicadorTest`
- [ ] 165-04: `CriativosEndpointsTest`, `KitIdempotenciaTest`, `FatiaFinaPontaAPontaTest`
- [ ] 165-05: `RegenerarEAprovarKitTest`, `LimitesDoPublicadorTest`, `NenhumTokenNoNavegadorTest`, `RotasAntigasComKitDoPublicadorTest`
- [ ] 165-06: gates do hook e do painel em `tests/js/publicador-editor.test.js` e `tests/js/publicador-mesa.test.js`
- [ ] 165-07: `EditorCriativosIaPropTest` + gates do `BlocoDeFotos` e do `Editor.jsx`

---

## Verificações só manuais

| Comportamento | Req | Por que manual | Instruções |
|---------------|-----|----------------|------------|
| Portão de coordenação com o outro dev (ordem com a 162, chave/permissão, guarda nas rotas antigas, `created_at`) | todos | Decisão humana | Checkpoint do 165-01 (Task 1) |
| O editor com o painel de criativos: "Gerar com IA" no bloco geral e no de cada variação, painel no lugar certo, confirmação de custo em dólar, estados planejado/pronto/erro, foto aprovada no bloco com "N de até M", nenhum amarelo além do "Continuar" | CE165-10 | Visual | Checkpoint do 165-08 (Task 3): receita do learnings `publicador-ml.md` §7 (SQLite isolado, `php -S` de dentro de `public/`), `creative_engine_ativo` ligado, Gemini inalcançável (o planejar cai no plano determinístico → `planejado`), kits semeados em pronto/planejado/erro; NÃO clicar "Gerar agora" com `QUEUE_CONNECTION=sync` |
| Geração real com o Gemini | CE165-06/08 | Custo real (~US$ 0,71 por kit) | Só na conta #459 "Dev 02 Testes API", depois de um deploy autorizado, com confirmação do usuário antes de gerar |

---

## Aprovação da validação

- [x] Toda tarefa `auto` tem `<automated>` (as duas tarefas de checkpoint são humanas por natureza e estão listadas acima)
- [x] Nenhuma sequência de 3 tarefas sem verificação automática
- [x] Todo arquivo de teste do mapa é criado por uma tarefa que o lista em `files_modified`
- [x] Sem flags de watch
- [x] Latência de feedback < 60 s no comando rápido
- [x] `nyquist_compliant: true` no frontmatter

**Aprovação:** pronta para execução (depois do "seguir" no checkpoint do 165-01)
