---
phase: 173-publicador-etapa-2-visao-geral
plan: 04
subsystem: publicador-visao-geral
tags: [publicador, visao-geral, configuracoes, pub-publicacoes, fonte-unica]
dependency-graph:
  requires:
    - "173-01: ProgramasPublicadorService::contagemProdutos()"
    - "173-02: AcervoTriagemService (triagem/defasagem/comMotivos/legadoEntre)"
  provides:
    - "PainelVisaoGeralService::publicadosRecentes()/indicadores()/oQueFazerAgora()/situacaoProdutos()/integracoes()/identidadeResumo()/ultimasPublicacoes()"
    - "MlbPublicadorEntradaController::visaoGeral()/configuracoes()"
    - "rota mlb.anuncios.publicador.visao-geral"
    - "rota mlb.anuncios.publicador.configuracoes"
  affects:
    - "app/Services/Publicador/PainelVisaoGeralService.php (novo)"
    - "app/Http/Controllers/MlbPublicadorEntradaController.php"
    - "routes/mlb_anuncios.php"
tech-stack:
  added: []
  patterns:
    - "fonte única de publicação (pub_publicacoes) — MlAnuncioRascunho não aparece em nenhum número desta classe, nem como fallback (decisão do usuário que reescreveu este plano)"
    - "baseQuery() compartilhada entre publicadosRecentes()/ultimasPublicacoes() — UMA junção pub_publicacao_itens→pub_publicacoes→pub_rascunhos→pub_produtos, escopada pela dupla-âncora dentro de where(function...)"
    - "resolverAtor()/atorDecodificado() privados únicos — a classificação equipe/cliente/origem_antiga nunca é duplicada entre os dois métodos que a usam"
    - "D23 (sem Company): indicadores/ultimasPublicacoes devolvem null/disponivel=false explícito, nunca erro nem zero disfarçado"
key-files:
  created:
    - app/Services/Publicador/PainelVisaoGeralService.php
    - tests/Unit/Publicador/PainelVisaoGeralServiceTest.php
    - tests/Feature/Publicador/VisaoGeralTest.php
    - tests/Feature/Publicador/ConfiguracoesContaTest.php
  modified:
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - routes/mlb_anuncios.php
decisions:
  - "Linha 6 de 'O que fazer agora' ('Rascunhos com pendências') NÃO foi implementada — a assinatura de oQueFazerAgora(), definida pelo próprio plano, recebe só agregados (contagemProdutos/triagemAcionaveis/situacaoPortal), nunca a lista de produtos/rascunhos. Sem a lista não há como citar QUAL rascunho tem a menor 'faltam' (EditorRascunhoService::prontidao() tem o campo, mas não chega até este método). Lacuna documentada no código e aqui; plano futuro precisa passar $produtos para fechar essa linha."
  - "ultimasPublicacoes(): 'vendas' escopado por company_id + ml_item_id (Rule 2), não só ml_item_id como o texto literal do plano sugeria — o docblock de MlAcervoItem avisa que ml_item_id sozinho não é único globalmente; sem o escopo duas empresas com o mesmo MLB (corrida de dados legados) vazariam venda uma da outra."
  - "destino da linha 8 ('Ficha, catálogo ou foto') segue o texto do PLAN.md (Produtos ?motivo=<chave>), que diverge da tabela do handoff ETAPA-2-visao-geral.md (Publicações › No ar filtrado) — o PLAN.md é a versão revisada/autoritativa desta rodada."
metrics:
  duration: "~70min"
  completed: "2026-10-08"
---

# Fase 173 Plano 04: Serviço, controller e rotas da Visão geral do Publicador Summary

`PainelVisaoGeralService` completo (7 métodos) + 2 rotas novas + 2 métodos de controller (`visaoGeral()`/`configuracoes()`), tudo sobre **fonte única** `pub_publicacoes` — o assistente antigo (`MlAnuncioRascunho`) não entra em nenhum número, decisão do usuário que eliminou o dedup que a versão original do plano previa. Zero chamada ao Mercado Livre em qualquer dos dois endpoints; zero migration.

## O que foi entregue

### Task 1 — `publicadosRecentes()` + `indicadores()`

Uma única query (`PubPublicacaoItem` → join `pub_publicacoes` → `pub_rascunhos` → `pub_produtos`), escopada pela dupla-âncora (`mlb_empresa_id`/`company_id`) dentro de `where(function...)`. `publicadosRecentes()` classifica o `ator` de cada item CREATED em `equipe` (nome por `User::find`, responsável da conta sempre primeiro), `cliente` (soma única, nunca expõe nome/id — T-173-09) e `origem_antiga` (ator sem `id`, migração de `estrutura_publicacoes`). `total` é a contagem bruta — sem dedup, porque não há mais duas fontes a conciliar.

`indicadores()` reusa `publicadosRecentes()` e o `AcervoTriagemService::defasagem()` da Fase 173-02: sem Company (`D23`) devolve `no_ar`/`com_venda` = `null` e `acervo_disponivel=false`; com Company mas nunca coletado, `no_ar`/`com_venda` = `null` com `nunca_coletado=true` (nunca zero disfarçado de medição).

### Task 2 — `oQueFazerAgora()`, `situacaoProdutos()`, `integracoes()`, `identidadeResumo()`, `ultimasPublicacoes()`

`oQueFazerAgora()` segue a ordem fixa da seção 3 do handoff. Token diferente de `'ativo'` interrompe a lista com UMA linha só (reconexão). As linhas de Produtos (com_problema/publicados/conferidos) e os 3 motivos da linha 8 (ficha_incompleta/perdendo_catalogo/foto_insuficiente, cada um como linha distinta) usam a MESMA fonte que a aba Produtos (`contagemProdutos()`, Fase 173-01) e a MESMA triagem de Meus Anúncios (`AcervoTriagemService`, Fase 173-02) — nenhum número reimplementado.

`integracoes()` cobre Mercado Livre (token), `publicacao_liberada`/`alavancas_liberada` (via `ContasLiberadas`/`AlavancasLiberadas` — confirmei no `MlbAlavancasController` que são classes SEPARADAS, D-03 da Fase 166), Portal e ERP declarado (lido de `MlbImplementacao->dados['itens']['erp']`, com os sentinelas `'---'`/`'Outro'` do checklist).

`identidadeResumo()` devolve as 3 primeiras linhas não vazias de `CreativeIdentidade::paraAncora()`, ou `tem_identidade=false` sem registro/texto vazio.

`ultimasPublicacoes()` reusa a mesma `baseQuery()` de `publicadosRecentes()` sem o filtro de 30 dias, `orderByDesc('concluida_em')`, limit 5. Sem Company devolve `{disponivel:false, itens:[]}` (D23) sem nenhuma query.

### Task 3 — Rotas + `visaoGeral()`/`configuracoes()`

Duas rotas novas em `routes/mlb_anuncios.php`, dentro do grupo `role:admin` já existente, logo após o bloco de identidade por conta da Fase 173-01:

```
GET mlb/anuncios/publicador/empresas/{conta}/visao-geral    → mlb.anuncios.publicador.visao-geral
GET mlb/anuncios/publicador/empresas/{conta}/configuracoes  → mlb.anuncios.publicador.configuracoes
```

Confirmadas via `route:list --name=mlb.anuncios.publicador` (ambas presentes, sem colisão de nome com as rotas da Fase 173-01).

`visaoGeral()` resolve `$alvo` (padrão de `produtos()`), monta `$empresaParaTela`/`$produtos`/`$contagemProdutos`, chama `AcervoTriagemService::triagem()`/`defasagem()` só se Company existir (senão agregados vazios explícitos), e compõe os 7 métodos do service em `Inertia::render('Mlb/Publicador/VisaoGeral', [...])`.

`configuracoes()` devolve identidade CRUA (sem resumo de 3 linhas — essa é só para a Visão geral), `conexoes` (= `integracoes()`, reusado — mesmo número nos dois lugares) e `programa`/`responsavel`.

## Shape completo do JSON de `GET .../visao-geral`

```json
{
  "empresa": { "chave": "...", "tipo": "mlb_empresa|company", "id": 1, "nome": "...", "identificador": "...",
    "programa": "polos|incubadora|gestao", "programa_rotulo": "...", "company_id": null,
    "token": "ativo|expirado|sem_token", "link_reconexao": null,
    "portal": { "situacao": "sem_portal|nunca|novas|sincronizado", "novas": 0, "sincronizado_em": null },
    "conta_nome": null, "conta_ml_id": null },
  "liberada": false,
  "indicadores": { "no_ar": null, "com_venda": null, "sem_oferta": 0, "publicados_30d": 0,
    "publicados_30d_pessoas": 0, "acervo_disponivel": false, "nunca_coletado": false },
  "oQueFazerAgora": [
    { "texto": "...", "numero": 3, "destino": { "rota": "mlb.anuncios.publicador.produtos", "params": { "conta": "...", "filtro": "com_problema" } } }
  ],
  "situacaoProdutos": {
    "rascunho": { "numero": 0, "rotulo": "Rascunho" },
    "conferidos": { "numero": 0, "rotulo": "Conferidos" },
    "publicados": { "numero": 0, "rotulo": "Publicados" },
    "com_problema": { "numero": 0, "rotulo": "Com problema" }
  },
  "ultimasPublicacoes": { "disponivel": false, "itens": [] },
  "integracoes": {
    "mercado_livre": { "token": "ativo" },
    "publicacao_liberada": false,
    "alavancas_liberada": false,
    "portal": { "situacao": "sem_portal", "novas": 0, "sincronizado_em": null },
    "erp": { "valor": null, "rotulo": "Não informado" }
  },
  "identidadeResumo": { "tem_identidade": false, "texto_resumo": null },
  "quemPublicou": {
    "equipe": [ { "nome": "...", "quantidade": 1, "responsavel": true } ],
    "cliente": { "quantidade": 0 },
    "origem_antiga": { "quantidade": 0 }
  },
  "abas": { "company_id": null }
}
```

Quando um item de `ultimasPublicacoes.itens` existe, o shape da linha é:

```json
{ "titulo": "Produto X", "ml_item_id": "MLB123", "tipo": "classico|premium|null",
  "quem": { "tipo": "equipe|cliente|origem_antiga", "nome": "..." },
  "quando": "2026-10-08T12:00:00+00:00", "vendas": 7, "situacao": "PUBLISHED" }
```

E o shape de `GET .../configuracoes`:

```json
{ "empresa": { /* mesmo shape de empresaParaTela() */ },
  "identidade": "Texto cru da identidade (ou null)",
  "conexoes": { /* mesmo shape de integracoes() acima */ },
  "programa": "polos",
  "responsavel": "Nome do responsável (ou null)" }
```

## Prova de que os números batem com a fonte

- `test_visao_geral_com_company_mostra_publicacoes_e_quem_publicou` (VisaoGeralTest) prova por asserção que `quemPublicou.equipe[].quantidade + quemPublicou.cliente.quantidade + quemPublicou.origem_antiga.quantidade === indicadores.publicados_30d` — o critério de aceite "ninguém pode sumir da conta" do aviso de coordenação.
- `test_o_que_fazer_agora_ordem_fixa_e_motivos_distintos` (Unit) prova que `oQueFazerAgora` usa os MESMOS `contagemProdutos`/chips de triagem recebidos como parâmetro — nenhuma query própria reimplementando a conta de Produtos/triagem.
- `test_indicadores_nunca_coletado_nunca_e_zero` e o equivalente em `VisaoGeralTest` provam `no_ar=null`/`com_venda=null` (nunca `0`) quando o acervo nunca foi coletado.
- `test_visao_geral_token_expirado_mostra_so_a_linha_de_reconexao` prova a regra "token expirado → só a linha de reconexão" ponta a ponta via HTTP, com um produto em erro no banco (que deveria gerar outra linha e não gera).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 — correção de escopo] `ultimasPublicacoes()` escopa `vendas` por `company_id` além de `ml_item_id`**
- **Found during:** Task 2
- **Issue:** o texto do plano pedia `MlAcervoItem::where('ml_item_id', ...)->value('sold_quantity')`, mas o docblock do próprio model `MlAcervoItem` avisa que `ml_item_id` sozinho NÃO é único globalmente — só `(company_id, ml_item_id)` é.
- **Fix:** acrescentado `->where('company_id', $companyId)` (a Company já está garantida não-nula neste ramo). Comentário no código explica o porquê.
- **Files modified:** `app/Services/Publicador/PainelVisaoGeralService.php`
- **Commit:** `4cca8a92`

Nenhum outro desvio automático. A linha 6 de "O que fazer agora" foi deliberadamente deixada de fora (ver Decisions acima) por lacuna na assinatura definida pelo próprio plano — documentado, não corrigido por conta própria (exigiria mudar a assinatura, que é uma decisão do plano, não um bug).

## Known Stubs

Nenhum. Todos os 7 métodos do service e os 2 métodos de controller estão completos e testados; a única lacuna (linha 6 de "O que fazer agora") está documentada em código e aqui, não escondida.

## Deploy

⚠️ **Deploy precisa de `php artisan route:cache`** — 2 rotas novas registradas nesta plan, nenhum deploy executado por este plano.

## Verificação executada (resultado real)

```
php artisan test --filter=PainelVisaoGeralServiceTest         → 15 passed (63 assertions)
php artisan test --filter='VisaoGeralTest|ConfiguracoesContaTest' → 10 passed (51 assertions)
php artisan route:list --name=mlb.anuncios.publicador          → visao-geral e configuracoes presentes, sem colisão de nome
php artisan test tests/Feature/Publicador tests/Unit/Publicador → 869 passed (4311 assertions), 0 failed
```

Baseline (173-02-SUMMARY.md) era 844 — subiu para 869 com os 25 testes novos deste plano (15 Unit + 10 Feature), sem nenhuma regressão. As 2 falhas pré-existentes conhecidas (`Phase38Publicador`/`MeuPainelControllerTest` e "[MLB Coleta] Falha ao obter app token") vivem fora de `tests/Feature/Publicador`/`tests/Unit/Publicador`, logo não aparecem neste filtro — consistente com o que 173-02-SUMMARY.md já registrou.

## Self-Check: PASSED

- `app/Services/Publicador/PainelVisaoGeralService.php` — FOUND
- `app/Http/Controllers/MlbPublicadorEntradaController.php` — FOUND (métodos `visaoGeral`/`configuracoes` presentes)
- `routes/mlb_anuncios.php` — FOUND (2 rotas novas confirmadas via `route:list`)
- `tests/Unit/Publicador/PainelVisaoGeralServiceTest.php` — FOUND
- `tests/Feature/Publicador/VisaoGeralTest.php` — FOUND
- `tests/Feature/Publicador/ConfiguracoesContaTest.php` — FOUND
- Commits `608a57d6`, `4cca8a92`, `64cf9e09` confirmados em `git log --oneline`
