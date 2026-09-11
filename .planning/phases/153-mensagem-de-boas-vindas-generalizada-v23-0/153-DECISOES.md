---
phase: 153-mensagem-de-boas-vindas-generalizada-v23-0
tipo: decisoes-de-desenho
data: 2026-09-10
requirements: [COMUNIC-01, COMUNIC-02, COMUNIC-03]
---

# Fase 153 — decisões de desenho, escritas ANTES do código

Fase conduzida como **trabalho direto** (`CLAUDE.md` → "GSD por RISCO"): é feature nova em módulo
existente, não toca `DesempenhoScoreService`/`CompanyScoreService`/`PlanoMetasPublicacaoService`,
nem `*_snapshots`/`bonus_*`, e a migration **cria tabela nova** em vez de alterar tabela com dado em
produção. As três disciplinas que substituem a cerimônia:

1. **Perguntar antes** — feito, duas decisões travadas pelo usuário (D-A e D-B abaixo).
2. **Decisão de schema por escrito** — é este documento.
3. **Teste no mesmo commit.**

## O que já existe (medido, não suposto)

| Peça | Onde | Situação |
|---|---|---|
| Mensagem de boas-vindas do Polos | `MlbConfiguracao::MENSAGEM_BOAS_VINDAS_PADRAO`, salva em `mlb_configuracoes.implementacao_defaults` (JSON) | **viva em produção**, específica do Polos |
| Substituição dos `{placeholders}` | `resources/js/Pages/Mlb/components/ImplModal.jsx` linhas 352-372 | no **frontend**, sem teste |
| Grant regional do Polos | `grants_por_polo`, chaveado por `mlb_empresas.polo` | só Polos; não existe para outro serviço |
| Link público de OAuth do cliente | `portal-cliente/{token}/onboarding/conectar/ml` | ✅ existe e é seguro |
| Link de conexão com o sistema | `portal-cliente/{token}` — o `OnboardingLink` do item 8 da Fase 152 | ✅ existe |
| Link do Adman | `config('services.adman.register_url')` — item 6 da Fase 152 | ✅ existe, fixo |
| E-mail colaborador | `companies.email_colaborador` | ✅ existe |

**Os 6 blocos do §4 são todos montáveis com dado que já existe.** Nenhuma integração nova.

## D-A — Um template POR SERVIÇO; o do Polos fica intacto

Decisão do usuário. A mensagem do Polos é específica no conteúdo e no tom ("Projeto Polos! 🚀",
Grant regional, Guia do Projeto Polos) e **está em produção**. Generalizá-la mudaria o que clientes
Polos recebem hoje.

- Cada serviço pode ter o seu texto.
- Existe **um** texto genérico (`servico_id IS NULL`) usado por serviço que não tenha o próprio.
- O texto do Polos **não é migrado nem tocado** por esta fase: segue em
  `mlb_configuracoes.implementacao_defaults.mensagem_boas_vindas`, consumido pelo `ImplModal.jsx`
  como sempre. Esta fase **não altera** aquele caminho.

## D-B — A mensagem é montada no BACKEND

Decisão do usuário. Um service devolve o texto pronto; a ficha só exibe e copia.

Razão registrada: é a mesma disciplina da D-04 da Fase 152 (o link do Adman vem do servidor, nunca
hard-coded no JSX), fica coberto por PHPUnit — que hoje **não cobre** a substituição do Polos, feita
no JSX — e evita uma segunda implementação dos placeholders no front.

## D-C — Tabela DEDICADA, nunca o JSON de `implementacao_defaults`

**Medido:** `MlbImplementacaoController::salvarPadroes()` (linha ~585) faz
`MlbConfiguracao::get()->update(['implementacao_defaults' => $validated])` — **substitui o JSON
inteiro** pelas chaves que passaram na validação. Qualquer chave nova ali é **apagada em silêncio**
no próximo save da tela de Padrões, por quem não fez nada errado.

É a mesma família do `updateEmpresa()` que zera campos omitidos, já registrada em
`project_polos_link_whatsapp_coluna_260810`. Não repetir.

### Schema

```
Tabela: boas_vindas_templates

id              bigint unsigned  PK
servico_id      bigint unsigned  NULL  FK servicos.id  ON DELETE CASCADE
texto           text             NOT NULL
atualizado_por  bigint unsigned  NULL  FK users.id     ON DELETE SET NULL
created_at / updated_at

índice único NOMEADO À MÃO: bvt_servico_unique (servico_id)
```

Decisões dentro do schema, e o porquê de cada uma:

- **`servico_id` nullable é o genérico.** Sem coluna-sentinela e sem string mágica: "template do
  serviço X" e "template padrão" são a mesma coisa com o FK preenchido ou não.
- ⚠️ **MariaDB permite N linhas com `servico_id` NULL num índice único.** O índice **não** garante
  linha genérica única — quem garante é a aplicação, por `updateOrCreate(['servico_id' => null])`
  num ponto único. Está escrito aqui porque é exatamente o tipo de coisa que o SQLite dos testes
  não pega (learnings §6).
- **Índice nomeado à mão.** `boas_vindas_templates_servico_id_unique` tem 43 caracteres e passaria,
  mas a Fase 152 já levou um erro 1059 por nome gerado longo demais; nomear é barato e remove a
  classe inteira.
- **`ON DELETE CASCADE` no `servico_id`.** Serviço apagado leva o template junto — template órfão de
  serviço inexistente não tem leitor. Diferente do histórico da Fase 150, onde cascade seria perda
  de insumo de SLA: aqui não há histórico a preservar, é configuração corrente.
- **`ON DELETE SET NULL` no `atualizado_por`.** Remover o usuário não pode apagar o template.
- **Sem `soft deletes`** — não há caso de uso para template "apagado mas recuperável".

## D-D — Empresa com mais de um serviço: desempate DETERMINÍSTICO

Uma empresa pode ter vários `ContratoServico` ativos. Regra:

> Entre os serviços **ativos** da empresa que possuem template próprio, vence o de **menor
> `servicos.id`**. Se nenhum tiver, usa o genérico.

Mesma disciplina da D-12 da Fase 152 (`is_primary` duplicado desempata por `orderBy('id')`).
Deliberadamente **não** reusa o `setorDominante` de `ComercialEntradaController` linha 138: aquele é
`->first()` sobre coleção sem ordenação — o resultado depende da ordem de carga e pode mudar entre
duas requisições idênticas. Não propagar.

## D-E — O que cada bloco do §4 recebe

| Bloco §4 | Placeholder | Fonte | Vazio quando |
|---|---|---|---|
| Boas-vindas | `{empresa}` | `companies.name` | nunca |
| E-mail colaborador | `{email_colaborador}` | `companies.email_colaborador` | não preenchido no cadastro |
| Link da ADMA | `{link_adman}` | `config('services.adman.register_url')` | `.env` sem a chave |
| Link/Grant da consultoria | `{link_oauth}` | `portal-cliente/{token}/onboarding/conectar/ml` | sem `OnboardingLink` (item 8 não gerado) |
| Link de conexão com o sistema | `{link_sistema}` | `portal-cliente/{token}` | idem |
| Orientações | texto fixo do template | — | nunca |

⚠️ **`{link_oauth}` é a rota PÚBLICA por token, jamais a interna.** `ml.oauth.initiate` é
autenticada e, clicada por um usuário ECF logado, autoriza a conta do Mercado Livre **dele** — é o
incidente da D-05 da Fase 152 e de `project_polos_oauth_link_boas_vindas_260827`. A mensagem vai
para o cliente; o link precisa ser o que o cliente pode abrir.

**Bloco sem dado não vira texto quebrado.** Quando o placeholder não tem valor, o service devolve a
lista de pendências junto com a mensagem, e a tela mostra o aviso — em vez de entregar para copiar
um texto com "Link: " vazio. É o mesmo princípio do `requisito_faltante` da Fase 152: dizer o que
falta, não entregar um resultado mudo.

## D-F — Onde se edita (COMUNIC-03)

Tela nova sob a permissão **`admin.contratos` OU `comercial.entrada`** — as mesmas duas da ficha
(D-17 da Fase 152), pelo mesmo motivo: quem opera a Entrada precisa ajustar o texto que envia.
**Nenhuma chave de permissão nova** (D-09 da Fase 152).

Deliberadamente **não** entra na tela de Padrões do MLB (`Mlb/Implementacao.jsx`): aquela é gated
por `publication_role`, é do módulo de Publicação, e é justamente a que tem o bug de sobrescrita do
JSON descrito na D-C.

## Fora de escopo, declarado

- **Enviar** a mensagem. O §4 diz "pronta para o Administrativo copiar e enviar no grupo" — copiar,
  não disparar. Nenhuma integração de WhatsApp nesta fase.
- **Migrar o texto do Polos** para a tabela nova. D-A o mantém onde está.
- Histórico de versões do template.
