---
quick_id: 260910-l7k
slug: teto-do-contrato-na-convencao
date: 2026-09-10
type: quick
status: pending
---

# O teto lido do contrato entra na convenção da casa — e passa pela validação

## O que foi medido em produção hoje (2026-09-10), depois do deploy do quick anterior

O usuário confirmou **19 das 85 propostas** lidas do Clicksign. Essas confirmações passam por
`TabelasContratoController::confirmar()`, que grava `$proposta->faixas` direto na porta de escrita
**sem passar pela validação de faixas**. Duas consequências, ambas já em produção:

### F-1 — 75 tetos gravados com `,00` (13 empresas, todas `origem=contrato`)

O parser guarda o teto **literal do contrato** ("Até R$ 500.000,00"). A convenção do projeto,
unificada pelo usuário em 2026-09-03, é `teto = valor redondo − 0,01`.

Empresas afetadas: #6 WEHOUSE, #137 ELLITE, #196 MILANILENONN, #234 MAXIGOLD, #247 USEMILLE,
#251 ALCOMERCIO, #256 EZIOFREDIANI, #257 GARCIA, #319 DINMAP, #348 SARDAGNA, #367 RAVENA,
#368 Utilarshop, #417 GABS — 75 tetos ao todo.

`servico_faixas_faturamento`: 0 fora. `grupo_faixas_faturamento`: 0 fora.

**O que isso quebra hoje:** na ficha de edição o campo de faturamento mostra **R$ 500.000,01** — a
conversão de borda (`tetoRedondo`, quick `260910-j1u`) soma 1 centavo a um teto que não tem `,99`.
A grade de leitura esconde porque formata sem centavos. Na cobrança só muda para quem faturar o
centavo exato; **nenhuma das 13 estava nessa situação em agosto** (conferido por reconsulta ao
`fechamento_snapshots`).

### F-2 — a confirmação não valida a tabela, e duas malformadas entraram

`confirmar()` chama `GravarTabelaEmpresaService::gravar()` sem nada equivalente ao
`SalvarFaixasFaturamentoRequest`. Passaram duas tabelas que a validação teria recusado:

| | #234 MAXIGOLD | #256 EZIOFREDIANI |
|---|---|---|
| penúltima linha | teto R$ 48.000 → R$ 25.000 | teto R$ 36.000 → R$ 12.000 |
| linha sem teto | **R$ 4.000** | **R$ 3.000** |

A faixa aberta — a que pega o MAIOR faturamento — está com o MENOR preço da tabela. Varri as 171
empresas: são **exatamente essas 2**, nenhuma outra fora de ordem, nenhuma sem faixa aberta.

⚠️ **Corrigir o conteúdo dessas duas tabelas está FORA deste quick** — depende de ler o contrato
real. Este quick só impede que outra entre, e conserta os tetos.

---

## Escopo

### T1 — extrair a validação para um serviço puro, sem mudar comportamento

Hoje as regras compostas vivem em `SalvarFaixasFaturamentoRequest::withValidator()`, presas ao
FormRequest — não dá para aplicá-las a um array vindo do parser.

Criar **`app/Services/Fechamento/ValidadorTabelaFaixas.php`** com as MESMAS quatro regras, na
MESMA ordem, com as MESMAS mensagens em pt-BR:

- (a) `ordem` não repete
- (b) no máximo uma faixa sem `limite_superior`, e ela é a de maior `ordem`
- (b2) `valor_e_piso` só na faixa sem teto
- (c) `limite_superior` estritamente crescente na ordem

Assinatura sugerida:

```php
/** @return array<int, array{campo: string, mensagem: string}> — vazio = tabela válida */
public function erros(array $faixas): array
```

Mantendo a disciplina atual: **para no primeiro conflito** (mensagem única, sem spam) e o `campo`
aponta para o **índice ORIGINAL** do payload, não o pós-ordenação.

`SalvarFaixasFaturamentoRequest::withValidator()` passa a delegar para o serviço e mapear cada
`{campo, mensagem}` em `$v->errors()->add()`. As regras primárias (`rules()`) e o `messages()`
ficam onde estão.

⚠️ **Prova de que nada mudou:** os testes que já existem do cadastro manual precisam continuar
verdes **sem edição nenhuma**. Se algum precisar ser alterado, o comportamento mudou — pare e
reporte em vez de ajustar o teste.

### T2 — `FaixaFaturamento::tetoGravado()`, o espelho PHP da conversão de borda

Criar **`app/Support/FaixaFaturamento.php`** com o espelho da função JS homônima
(`resources/js/lib/faixasFaturamento.js`), citando-a no docblock:

```php
public static function tetoGravado(?float $tetoDoContrato): ?float
```

Regra, e ela tem os dois casos:

| entrada | saída | por quê |
|---|---|---|
| `500000.00` | `499999.99` | teto redondo do contrato → convenção da casa |
| `499999.99` | `499999.99` | **já está na convenção — não subtrai de novo** |
| `null` | `null` | faixa sem teto |

⚠️ A idempotência não é enfeite: sem ela, reprocessar uma proposta já normalizada vira
`499.999,98` e move empresa de faixa. Comparar centavos com tolerância (`round($v * 100) % 100`),
nunca `==` em float.

### T3 — `confirmar()` normaliza, valida, e só então grava

Em `TabelasContratoController::confirmar()`, no ramo `TIPO_TABELA`, nesta ordem:

1. normalizar cada `limite_superior` com `FaixaFaturamento::tetoGravado()`
2. rodar `ValidadorTabelaFaixas`
3. se houver erro: **não grava, não marca a proposta como confirmada**, e devolve 422 com a
   mensagem — a transação inteira não acontece
4. só então `gravar()`

⚠️ **Copy sem jargão** (regra do projeto): a pessoa precisa entender que a leitura automática saiu
torta e que alguém tem de cadastrar a tabela à mão. Nada de "não-monotônico", "limite_superior",
"payload", "faixa inválida no índice 6".

⚠️ Os ramos `valor_fixo` / `indefinido` / `ilegivel` continuam **exatamente** como estão: não
gravam faixa, e confirmar segue liberado (é só marcar como conferido). Não mexer neles.

### T4 — comando de correção dos 75 tetos já gravados

**`php artisan fechamento:normalizar-tetos-contrato`** — no molde de
`MaterializarTabelasFechamento`: **dry-run por padrão**, escreve só com `--aplicar`.

- alvo: linhas com `origem = 'contrato'` cujo teto não termina em `,99`
- reescreve **só o `limite_superior`** — `valor`, `valor_e_piso` e `ordem` ficam intactos
- grava pela porta única (`GravarTabelaEmpresaService::gravar()`) para a trilha de `activity_log`
  registrar antes/depois, com `feitoDe = 'normalizacao_teto'`
- ⚠️ **preservar `servico_origem_id`**: `gravar()` recebe esse valor por parâmetro; ler o das
  linhas atuais e devolver o mesmo, senão a correção apaga o vínculo em silêncio
- ⚠️ tabela que **falharia** na validação do T1 (as duas do F-2) é **pulada e nomeada no
  relatório**, nunca corrigida pela metade
- imprimir, por empresa: nome, quantos tetos mudam, e o antes/depois de cada um

---

## Testes

| caso | espera |
|---|---|
| `tetoGravado(500000.00)` | `499999.99` |
| `tetoGravado(499999.99)` | `499999.99` (idempotente) |
| `tetoGravado(null)` | `null` |
| suíte atual do cadastro manual | **verde sem editar nenhum teste** (prova do T1) |
| `confirmar()` com tetos `,00` | grava `,99`, proposta vira `confirmada` |
| `confirmar()` com a forma do MAXIGOLD (teto fora de ordem) | **nada gravado**, proposta segue `pendente`, 422 |
| `confirmar()` de `valor_fixo` | inalterado — nenhuma faixa, confirma normalmente |
| comando sem `--aplicar` | não escreve nada |
| comando com `--aplicar` | tetos viram `,99`, `valor` intacto, `servico_origem_id` preservado, 1 entrada de `activity_log` por empresa |
| comando com `--aplicar` numa tabela malformada | pula e nomeia; não grava |

---

## Travas

⚠️ **Isto mexe em cobrança viva de 171 empresas.** O `FechamentoFaixaResolver` **não se toca** —
nem uma linha. A conversão acontece na borda da confirmação, nunca dentro do motor.

⚠️ **Não corrigir o conteúdo das tabelas de MAXIGOLD (#234) e EZIOFREDIANI (#256).** Fora de
escopo, decidido: depende de ler o contrato real.

⚠️ **Executores não alcançam produção** (`plink`/`pscp`/`deploy.sh` são bloqueados em subagente).
Entregar o comando pronto e parar — quem roda contra o VPS é o orquestrador.

⚠️ Banco local ~31 migrations atrás e vazio de dado real — testes em SQLite com factories.

⚠️ **Árvore compartilhada, outra sessão ativa:** nunca `git add -A` / `git add .` / `git commit -a`
/ `git stash`. Antes de commitar, conferir `git status --porcelain app/ tests/ database/` e olhar
os `??`. `tests/Feature/CompanyPortfolioAccessTest.php`, `public/images/*`, os `.docx`/`.pdf` da
raiz, `design_handoff_fechamento/` e `scratchpad/` **não são seus**.

⚠️ **Não use `gsd-sdk query state.advance-plan`** — a última execução avançou o contador de outra
fase.

⛔ Sem deploy, sem `.env`, sem ligar/desligar `fechamento_tabela_por_empresa_ativa`.

PHP: `C:\xampp\php\php.exe`. Comentários, copy e commits em **pt-BR**. Commits atômicos.
Sem mudança de JSX prevista — se mexer em `.jsx`, rodar `npm run build`.

**Gate:** `--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909|Quick260910"`
partindo de **605 testes / 2833 asserções / 0 falhas**.
