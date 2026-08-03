---
task: Decola vira select (Sim/Não/Mensagem Enviada) + corrigir "Criar novo valor"
slug: 260803-kv2-decola-select-e-criar-valor
date: 2026-08-03
status: complete
commits:
  - 4bf258ae fix(revisao) backfill de pendencias quebrava o migrate no SQLite
  - 6ca36370 feat(polos) Decola vira select e "Criar novo valor" volta a salvar
---

# Resumo

## 1. Decola como select

`mlb_implementacoes.decola` migrou de `boolean` para `varchar(60)`. A migration
(`2026_08_03_140000`) converte o histórico via coluna temporária + rename — portável
MySQL/SQLite. Conversão local verificada: **121 "Sim" · 144 "Não" · 4 nulos**, nada perdido.

Novo catálogo em `MlbImplementacao::ONB_DECOLA_OPCOES` = `Sim` · `Não` · `Mensagem Enviada`,
exposto em `opcoes.decola` pelos dois controllers (painel e ficha).

Consumidores do booleano acertados:

| Onde | Antes | Depois |
|---|---|---|
| `MetasPanel.temDecola` | `decola === true` | `decola === 'Sim'` |
| `PolosController` payload | `(bool) $impl->decola` | `$impl?->decola` |
| `PolosController::painelBulk` | `decola` em `$BOOL` | fora do `$BOOL` |
| `SyncPolosPlanilha` | `BOOL_IMPL` + `parseBool` | `normDecola()` |
| `OnboardingFicha` modal | `ToggleSimNao` | `Select` |
| `Painel.jsx` grade | `EditToggle` | `EditSelect` |
| Edição em massa | `tipo: 'bool'` | `tipo: 'select'` |

**Decisão de régua:** na aba Metas, só `Sim` conta como Decola ATIVO para o M1 completo.
"Mensagem Enviada" é convite mandado sem resposta — segue pendente. Cor âmbar (em progresso)
em `VAL_PROG`/`corStatus`.

## 2. Fix do "＋ Criar novo valor"

**Causa-raiz:** o front mandava o valor certo; os blocos da ficha é que validavam com
`Rule::in(ONB_*_OPCOES)` e devolviam **422**. O Inertia não recarregava e nenhum erro
aparecia na grade → o valor sumia sem explicação.

A pista estava no próprio código: `status_entrada`, `chance_entrada` e `reuniao_onboarding`
já eram texto livre (comentário: *"texto livre (aceita 'criar novo valor')"*) — e só esses
funcionavam. O desenho pretendido era texto livre; faltou aplicar ao resto.

Campos que passaram a texto livre com limite: `acesso_colaborador`, `planilha_produtos`,
`listagem`, `publicacao`, `decola`, `me1`, `integradora`, `places`, `erp`, `polo`.

**Exceção — `fase` continua fechada** e perdeu o "＋ Criar novo valor…" no front (prop
`criavel={false}` no `EditSelect`). Ela alimenta `MlbEmpresa::FASE_PARA_PROJETO`, que decide
se a empresa é do projeto POLOS: uma fase inventada faria a empresa **sumir do painel** sem
aviso nenhum.

## 3. Efeito colateral: suíte de testes destravada

O `migrate` quebrava no SQLite desde a reforma da Revisão MLB (`64a2173c`): a migration de
backfill usava `UPDATE <tabela> <alias> SET`, sintaxe só do MySQL. Como o `RefreshDatabase`
roda todas as migrations antes de cada teste, **a suíte inteira falhava**. Corrigido em commit
separado. A migration já rodou em produção — a mudança só afeta execuções novas.

# Verificação

- `php artisan migrate` local: OK, coluna `varchar(60)`, dados convertidos
- `Phase33OnboardingFichaTest`: 15/16 passam
- Filtro `Polos|Onboarding|Implementacao|Impl`: **96 passam, 13 falham**
- Baseline isolado (só o fix da migration, sem as mudanças desta task): **as mesmas 13
  falhas, lista idêntica** → zero regressão introduzida
- `npm run build`: OK

# Achados NÃO corrigidos (fora de escopo)

**Grant do polo "Serra Gaúcha" não resolve.** `ONB_POLO_OPCOES` renomeou `'Bento Gonçalves'`
→ `'Serra Gaúcha'` em `5c6808a7`, mas `MlbConfiguracao::GRANTS_POR_POLO_PADRAO` ainda usa a
chave antiga. Efeito: a busca por polo não acha o Grant, e `{link_grant}`/`{projeto_grant}`
da mensagem de boas-vindas saem vazios para esse polo. Falha o teste
`padroes expoem mensagem e grants padrao`. Não corrigido porque trocar a chave pode conflitar
com o que já está gravado no JSON `implementacao_defaults` (se alguém editou os grants em
Padrões Globais, está salvo sob a chave velha) — precisa de decisão do usuário.

**12 outras falhas pré-existentes** no filtro, mascaradas desde `64a2173c`. Destaque:
`SyncPolosFaturamentoJob::handle()` ganhou um 2º parâmetro (`MlCategoriaService`) e
`PolosFaturamentoSnapshotTest` chama com um só → `ArgumentCountError`.
