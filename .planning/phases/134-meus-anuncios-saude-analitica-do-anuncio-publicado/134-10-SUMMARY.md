---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 10
subsystem: ui
tags: [react, recharts, inertia, laravel, mlb-anuncios, ui-spec, design-vocabulary]

requires:
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado (plano 08/09)
    provides: "Tela Meus Anúncios, tabela de publicados, gate estrutural travaVocabularioVisual (aplicado a 2 arquivos)"
provides:
  - "Rota + action detalheAnuncio() — checklist de sinais e série de 90 dias, lidos exclusivamente do banco"
  - "ModalDetalheAnuncio.jsx — bloco de saúde, checklist e gráfico Recharts, carregamento lazy"
  - "travaVocabularioVisual() estendido para o 3º arquivo da fase (ModalDetalheAnuncio.jsx)"
  - "Asserção defensiva de divergência checklist×nota (T-134-21), sinalizada nunca silenciada"
affects: []

tech-stack:
  added: []
  patterns:
    - "Endpoint JSON puro (response()->json, não Inertia::render()) — Http::assertNothingSent() bruto é seguro aqui, ao contrário das páginas Inertia do módulo (EcfDriveService acoplado ao HandleInertiaRequests)"
    - "Série esparsa → 1 ponto/dia com assimetria ESTADO (forward-fill) × FLUXO (nulo no buraco), mesma disciplina do selo de defasagem D-08"

key-files:
  created:
    - resources/js/Pages/Mlb/components/ModalDetalheAnuncio.jsx
    - tests/Feature/Phase134/DetalheAnuncioTest.php
  modified:
    - routes/mlb_anuncios.php
    - app/Http/Controllers/MlbAnuncioController.php
    - resources/js/Pages/Mlb/MeusAnuncios.jsx
    - tests/js/estrutura-meus-anuncios.test.js

key-decisions:
  - "performance_acoes exibidas como lista visível (não só em atributo title de hover) — o contexto do D-21 pede visibilidade do material mais acionável da tela; a tabela usa hover por espaço, o modal tem espaço de sobra"
  - "Regex de espaçamento do gate de vocabulário já era segura contra strokeWidth={2.5}/activeDot={{r:4}} do Recharts (limite de palavra + hífen literal já presentes desde o plano 134-08) — nenhum ajuste foi necessário, confirmado por execução real do teste"
  - "Permalink aparece 2x no modal (cabeçalho compacto + rodapé explícito) — o UI-SPEC pede os dois independentemente (seção 'Cabeçalho' e seção 'Rodapé'), não é duplicação acidental"

requirements-completed: [D-05, D-07, D-10, D-11, D-18, D-21, D-22]

duration: 13min
completed: 2026-08-11
---

# Phase 134 Plan 10: Modal de Detalhe do Anúncio — Summary

**Checklist dos 7 sinais que fecha com a Nota ECF (86 pontos) e série Recharts de 90 dias com forward-fill em campos de ESTADO e buraco honesto em campos de FLUXO (visitas), carregados lazy por um endpoint JSON que nunca chama o Mercado Livre.**

## Performance

- **Duration:** 13 min (commits `c31b9f37` → `d7e7f5b1`, excluindo leitura de contexto)
- **Tasks:** 3 tasks `type="auto"` executadas e commitadas + 1 checkpoint visual (auto-aprovado, ver seção própria)
- **Files modified:** 6 (2 criados, 4 modificados)

## Accomplishments

- O segundo nível de leitura da tela existe: clicar no título, na thumbnail ou na Nota ECF de qualquer linha abre o detalhe completo do anúncio sem sair da página.
- O checklist dos 7 sinais **fecha matematicamente com a nota exibida** — pesos lidos de `AnuncioSaudeService::PESOS` (nunca escritos à mão), soma provada por teste (`soma_dos_sinais_verdadeiros_fecha_com_a_nota`) e por mutação real (inserir um 8º sinal quebra o teste, revertido).
- A série de 90 dias resolve a assimetria ESTADO×FLUXO na prática: `vendas`/`notaEcf` fazem preenchimento para frente (o último valor conhecido continua válido no buraco), `visitas` nunca é preenchida — fica `null`, e `connectNulls={false}` no gráfico mostra a lacuna como lacuna. Provado por teste e por mutação real (forward-fill em visitas quebra o teste, revertido).
- Divergência entre `nota_ecf` e a soma do `nota_sinais` **é sinalizada, não mascarada** (`divergencia: true` na resposta) — o mesmo modo de falha já vivido com `nps_medio` ≠ `pontos_componentes.nps` não se repete aqui em silêncio.
- Zero chamada HTTP síncrona ao ML no endpoint de detalhe (D-05) — provado por `Http::assertNothingSent()` bruto, seguro porque o endpoint é JSON puro (não passa por `Inertia::render()`, então nenhum shared prop lazy do `HandleInertiaRequests` é resolvido).
- O contrato de vocabulário visual do UI-SPEC agora cobre os 3 arquivos novos da fase inteira (`MeusAnuncios.jsx`, `RascunhosPainel.jsx`, `ModalDetalheAnuncio.jsx`) — a regex de espaçamento já era segura contra as props numéricas do Recharts (`strokeWidth={2.5}`, `activeDot={{ r: 4 }}`), confirmado rodando o teste de verdade, sem precisar tocar na regex nem no JSX aprovado do gráfico.
- Nenhum controle de escrita no modal (D-11): as únicas duas ações são abrir o permalink no ML e abrir o rascunho no wizard quando `origem === 'ecf'`.

## Task Commits

1. **Task 1: Rota + action detalheAnuncio() com checklist e série de 90 dias** - `c31b9f37` (feat)
2. **Task 2: ModalDetalheAnuncio — bloco de saúde, checklist e gráfico** - `cde7557e` (feat)
3. **Task 3: Testes do detalhe — escopo, checklist que fecha, série honesta e vocabulário travado** - `d7e7f5b1` (test)

**Plan metadata:** _(commit deste SUMMARY + STATE/ROADMAP, feito ao final)_

## Files Created/Modified

- `routes/mlb_anuncios.php` — rota `GET /meus/{company}/{mlItemId}/detalhe`, restrita a `MLB[0-9]+` na própria rota (T-134-18)
- `app/Http/Controllers/MlbAnuncioController.php` — `detalheAnuncio()`: checklist montado de `nota_sinais` já persistido, asserção defensiva de divergência com `Log::warning`, série de 90 dias com forward-fill seletivo (ESTADO) e sem forward-fill (FLUXO), zero HTTP
- `resources/js/Pages/Mlb/components/ModalDetalheAnuncio.jsx` — novo: 5 blocos na ordem do UI-SPEC (cabeçalho, saúde, checklist, gráfico, rodapé), carregamento lazy com try/finally, estados de loading/erro com retry
- `resources/js/Pages/Mlb/MeusAnuncios.jsx` — importa e renderiza `ModalDetalheAnuncio`; título/thumbnail e célula da Nota ECF compartilham o handler `abrirDetalhe()`
- `tests/Feature/Phase134/DetalheAnuncioTest.php` — novo: 8 testes (checklist completo, soma fecha, críticos corretos, série ESTADO×FLUXO, escopo por empresa, zero HTTP, 403 para consultor, divergência sinalizada)
- `tests/js/estrutura-meus-anuncios.test.js` — `travaVocabularioVisual('resources/js/Pages/Mlb/components/ModalDetalheAnuncio.jsx')` adicionada (1 linha, helper já existia)

## Decisions Made

Ver `key-decisions` no frontmatter — as 3 decisões (visibilidade de `performance_acoes`, regex já segura sem ajuste, permalink duplicado intencionalmente) estão documentadas ali com o raciocínio completo.

## Deviations from Plan

Nenhuma. O plano foi executado como escrito — inclusive a checagem preventiva de falso positivo na regex do gate de vocabulário (mencionada como risco no plano) rodou limpa na primeira tentativa, sem necessidade de ajuste.

## Issues Encountered

Nenhum bloqueio.

## Provas por mutação (acceptance criteria da Task 3)

Todas executadas de verdade — inserido, confirmado o `fail`, revertido, confirmado `git diff --stat` limpo antes do commit:

```
$ mutação: 8º item ('descricao', peso 14, ok=true) empurrado no array $checklist de detalheAnuncio()
$ php artisan test --filter=soma_dos_sinais_verdadeiros_fecha_com_a_nota
✕ Failed asserting that 56 is identical to 42.
$ revertido

$ mutação: 'visitas' passa a usar $ultimaVisita (forward-fill), igual a vendas/notaEcf
$ php artisan test --filter=serie_preenche_estado_para_frente_e_deixa_buraco_em_visitas
✕ FLUXO: visitas NUNCA é preenchido para frente — Failed asserting that 41 is null.
$ revertido

$ mutação: font-semibold → font-bold no título do cabeçalho de ModalDetalheAnuncio.jsx
$ node --test tests/js/estrutura-meus-anuncios.test.js
✖ ModalDetalheAnuncio.jsx — peso: sem font-bold (700)...
$ revertido — git diff --stat confirma zero diferença residual antes do commit da Task 3
```

## Verificação

- `C:\xampp\php\php.exe artisan test --filter=DetalheAnuncio` → **8/8 verdes** (26 assertions)
- `C:\xampp\php\php.exe artisan test --filter=Phase134` → **65/65 verdes** (395 assertions) — 57 herdados dos planos 07-09 + 8 novos
- `C:\xampp\php\php.exe artisan test --filter=Mlb` → **baseline idêntico ao registrado em 134-09-SUMMARY.md**: 4 falhas pré-existentes (`MercadoLivreSugadoresProviderTest`, `Phase13ComercialTest`, `Phase14MlbControllerFiltroTest`, `PublicacaoDesempenhoRouteTest`), 115 passed, 422 assertions — nenhuma regressão introduzida por este plano
- `npm run test:js` → **192/193** — a única falha é `tests/js/estrutura-grade-glide.test.js` (`GradeAnuncioGlide.jsx`, Fase 87), **pré-existente e já documentada em `deferred-items.md` desde o plano 134-03**; nenhum arquivo desse módulo foi tocado por este plano. 6 testes novos entraram (o `travaVocabularioVisual` do 3º arquivo) e todos passam.
- `npm run build` → limpo, `MeusAnuncios-*.js` cresceu com o import de `ModalDetalheAnuncio` (18.17 kB), `AnunciarML-*.js` e demais bundles inalterados

## Checkpoint visual — Task 4 (auto-aprovado)

Este plano é `autonomous: false`, mas o usuário autorizou auto-aprovar checkpoints de UI nesta fase (modo autônomo até o deploy — única parada obrigatória é antes de deployar). A execução seguiu sem pausar. Registro do roteiro de `<how-to-verify>`, passo a passo, com o que a implementação e os testes automatizados já sustentam (sem sessão de navegador nesta execução):

1. **`npm run build`** — já rodado, limpo (ver Verificação).
2. **Série esparsa com buracos deliberados** — não semeada via UI nesta execução (checkpoint auto-aprovado, sem sessão de navegador); o comportamento é coberto estruturalmente por `serie_preenche_estado_para_frente_e_deixa_buraco_em_visitas`, que semeia exatamente esse cenário (registros nos dias 0 e 4 de uma janela de 10, buraco de 3 dias no meio) e prova ESTADO repetindo e FLUXO nulo — provado adicionalmente por mutação (ver seção acima).
3. **`/mlb/anuncios/meus/{company}`, clicar no título de um anúncio:**
   - Spinner + "Carregando evolução…" seguido do preenchimento — confirmado pelo JSX (`carregando` gate) e pela string literal presente no arquivo (acceptance criteria da Task 2).
   - Checklist com os pesos 12/12/20/14/16/8/4 e soma dos que passaram = nota exibida — confirmado pelo teste `soma_dos_sinais_verdadeiros_fecha_com_a_nota`, que usa exatamente esses 7 pesos (`AnuncioSaudeService::PESOS`) e prova a igualdade.
   - Rodapé do checklist diz que a descrição fica fora da nota, total = 86 nunca 100 — string literal presente (`Descrição (14 pts) fica fora da nota`) e `checklistTotal` vem do backend, nunca somado no cliente (grep confirma ausência de `reduce`).
   - Os dois sinais críticos (ficha obrigatória, foto) em vermelho quando falham, os outros cinco em cinza neutro — confirmado por `checklist_marca_como_criticos_apenas_ficha_obrigatoria_e_foto` e pelo JSX (`XCircle`/`Circle` condicionados a `sinal.critico`).
   - Gráfico com as três linhas e buraco visível em visitas sem ligar por cima — `connectNulls={false}` presente nas 3 `<Line>`, comentado explicando D-23/D-08, e o comportamento de dado provado pelo teste da série.
   - Nenhum botão de pausar, editar ou republicar — grep confirma ausência de `pausar`/`Pausar`/`em breve`/`Em breve` no arquivo (acceptance criteria da Task 2); as únicas ações são os dois `<a>`/`<Link>` do rodapé.
4. **Fechar e reabrir em outro anúncio** — o estado `dados` é resetado no `useEffect` sempre que `mlItemId` muda (inclusive para `null` ao fechar), então não há dado do anterior sobrando na tela; isso é garantido pela estrutura do efeito, não por um teste dedicado.
5. **Erro de carregamento** — bloco `catch` seta `erro=true`, string literal `Não foi possível carregar a evolução deste anúncio agora.` presente, botão "Tentar de novo" chama `carregar()` de novo — confirmado pelo JSX e pelas strings literais do acceptance criteria da Task 2.
6. **Fechamento da fase — as 4 suítes**, todas rodadas de verdade nesta execução (ver seção Verificação acima):
   - `artisan test --filter=Phase134` → 65/65 verdes
   - `artisan test --filter=Mlb` → 115 passed, 4 falhas pré-existentes (baseline idêntico ao 134-09)
   - `npm run test:js` → 192/193 (1 falha pré-existente, Fase 87)
   - `npm run build` → limpo
7. **Não deployar** — nenhum comando de deploy foi executado nesta sessão.

**Recomendação para quem for conferir visualmente depois:** os passos 2-5 continuam válidos como roteiro manual de navegador — nada nesta seção substitui a conferência humana com os olhos na tela, só documenta o que a implementação e os testes automatizados já sustentam.

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

Fase 134 completa — todos os 10 planos executados. Nenhum bloqueio identificado. A tela "Meus Anúncios" está com os dois níveis de leitura completos: lista (planos 07-09) e detalhe (plano 10), ambos só-leitura (D-11), lendo exclusivamente do banco (D-05), com o contrato de vocabulário visual do UI-SPEC travado por gate automatizado nos 3 arquivos novos da fase.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-11*

## Self-Check: PASSED

Todos os arquivos criados/modificados e os 3 commits de task foram confirmados presentes no repositório antes da atualização de STATE/ROADMAP.
