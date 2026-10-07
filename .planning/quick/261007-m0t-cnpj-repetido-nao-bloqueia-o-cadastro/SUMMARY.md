---
quick_id: 261007-m0t
slug: cnpj-repetido-nao-bloqueia-o-cadastro
date: 2026-10-07
type: quick
status: done
---

# CNPJ repetido deixou de dar 500 e virou aviso

O botão **Salvar cadastro** da ficha `/administrativo/contratos/empresa/{id}` devolvia **erro 500**
porque `companies.cnpj` tinha índice único. CNPJ repetido é legítimo neste negócio (uma empresa
jurídica com várias lojas de marketplace, cada loja um registro), então o unique saiu do banco, um
índice comum entrou no lugar, a ficha passou a avisar quando outra empresa já tem o mesmo CNPJ, e o
salvar ganhou rede de segurança contra qualquer erro de banco futuro.

## O que mudou

| Task | Arquivo | O que faz |
|---|---|---|
| T1 | `database/migrations/2026_10_07_120000_remove_unique_do_cnpj_em_companies.php` (novo) | Dropa `companies_cnpj_unique`, cria `companies_cnpj_idx` (não-único) |
| T2 | `app/Http/Controllers/ContratoAdminController.php` | Prop `empresas_mesmo_cnpj` em `show()` + helper `empresasComMesmoCnpj()` |
| T2 | `resources/js/Pages/Admin/ContratoDetalhe.jsx` | Componente `CnpjRepetidoAviso`, abaixo da linha do CNPJ |
| T3 | `app/Http/Controllers/ContratoAdminController.php` | `atualizarCadastro()` envolve a gravação em `try/catch (QueryException)` |
| T4 | `tests/Feature/Quick261007/CnpjRepetidoNaoBloqueiaCadastroTest.php` (novo) | 9 testes, 48 asserções |

## Decisões de implementação

**A ordem dos passos da migration é deliberada.** O índice comum nasce **antes** de o unique sair,
para `cnpj` nunca ficar um instante sem índice — a coluna é usada em busca, inclusive pela consulta
nova do aviso.

**O `down()` recusa reverter em vez de "consertar" dado.** Recriar o unique com os 12 pares de CNPJ
repetido no banco é impossível (volta o mesmo 1062 do incidente). A contagem dos duplicados no
`down()` é feita **por dígitos**, não por string crua — contar por string crua daria zero e deixaria
o unique estourar depois, no meio do `ALTER`. A mensagem diz o que falta decidir; a migration não
apaga nem funde empresa nenhuma.

**Branch de SQLite no drop do índice.** A suíte roda em SQLite (`phpunit.xml`), então a migration
roda a cada `RefreshDatabase`. No SQLite o drop usa `DROP INDEX IF EXISTS` cru — literal, idempotente
e sem depender de como a grammar resolve `dropUnique()` por nome. Todos os passos são guardados por
`Schema::hasIndex`: migration que morre no meio fica `Pending` com metade aplicada e precisa poder
rodar de novo.

**A comparação do aviso é por dígitos, e é isso que muda o resultado.** Nos 12 pares de produção um
registro guarda só dígitos (`38196897000143`) e o outro guarda pontuado (`38.196.897/0001-43`).
Comparar strings cruas — que era o que o unique fazia — não enxerga **nenhum** desses pares. O SQL
usa um `REPLACE` encadeado (`.`, `/`, `-`, espaço) como filtro barato, e por cima dele
`Cnpj::digitos()` dá a palavra final sobre o que conta como mesmo CNPJ. Documentado no helper que
essa comparação aplica função sobre a coluna e por isso **não** usa o índice novo: a varredura é
aceitável (`companies` tem algumas centenas de linhas, o payload é id/nome/ativo), e o índice existe
para as buscas por CNPJ em formato conhecido.

**Empresa sem CNPJ devolve lista vazia**, nunca "todas as outras empresas sem CNPJ" — CNPJ em branco
não é coincidência que mereça aviso. Tem teste próprio, incluindo o caso de `cnpj` string vazia.

**A prop não é recortada por `pode_ver_contrato`.** Ela acompanha `company.cnpj`, que já chega aos
dois perfis (`admin.contratos` e `comercial.entrada`) desde a Fase 152 — e nome de empresa já é
visível na listagem Comercial › Entrada. Não há classe de exposição nova.

**O `try/catch` do T3 pega só `QueryException`, de propósito.** Nada de `\Throwable`: engolir
Throwable transformaria bug de código em "tente de novo", que é justamente o que esconde o próximo
incidente. O texto do erro do banco vai para o **log** (carrega SQL e valores gravados, dado de
cliente); a tela recebe copy sem jargão — e o teste prova que a mensagem da sessão não contém
`SQLSTATE`, `Duplicate entry`, `unique` nem `update \`companies\``.

**Sem transação em volta da gravação**, também de propósito: salvar a empresa dispara o observer de
gatilho da Fase 128 de forma **síncrona**, e abrir transação aqui mudaria quando esse efeito
acontece — fora do escopo deste quick. Está escrito no comentário, junto da consequência (uma falha
no meio pode deixar a empresa gravada e um serviço não; a repetição é inofensiva).

**Os dois `abort(422)` de pertencimento (IDOR) ficaram intactos**, rodando antes do bloco
`try` — com teste dedicado provando que seguem devolvendo 422 sem gravar nada.

## Como o caso 6 do T4 prova a rede de segurança

A restrição do incidente foi removida na raiz, então o teste **recria um índice único em
`companies.cnpj` em tempo de execução** e faz o PATCH colidir — reproduzindo literalmente a violação
23000 que derrubava o formulário — e confere que agora ela vira aviso + log, sem 500. O ponto do teste
é o caminho (`QueryException` → log + flash), não essa restrição em especial.

## Gate

`--filter="Phase131|Phase132|Phase133|Phase152|Quick260819|Quick260821|Quick260824|Quick261007|Contrato"`,
exit capturado antes de qualquer pipe nas duas rodadas.

| | Antes de editar | Ao final |
|---|---|---|
| Passaram | 764 | **773** (+9) |
| Falharam | 4 | **4** (as mesmas) |
| Asserções | 3275 | 3323 |
| Duração | 325,38s | 313,05s |

### ⚠️ As 4 falhas são PRÉ-EXISTENTES — não são regressão deste quick

Medidas **antes** de qualquer edição e idênticas depois, nome por nome:

1. `Tests\Feature\AdminFechamentoControllerTest > update persiste datas contrato`
2. `Tests\Feature\Phase14MigrationTest > migration cria contratos para empresa com service type`
   (`InvalidFormatException`)
3. `Tests\Feature\Phase14MigrationTest > migration cria contrato adicional com normalizacao title case`
   (`InvalidFormatException`)
4. `Tests\Feature\Phase42\AnalyzeCompanyMlWindowQuarantineTest > fetch adgroups metrics retorna contrato completo 22 chaves`

Nenhuma delas toca `companies.cnpj`, `ContratoAdminController` ou a ficha — as duas do `Phase14MigrationTest`
são de parse de data e a do `Phase42` é de contrato de retorno da API do ML.

`npm run build` rodou com sucesso (37,39s); `public/build/` é gitignored, nada a commitar dele.

## Commits

| Hash | Mensagem |
|---|---|
| `5449d63f` | `fix(261007-m0t): CNPJ repetido deixa de ser proibido no banco` |
| `4420f1b0` | `feat(261007-m0t): a ficha avisa quando o CNPJ ja esta em outra empresa` |
| `93a5dbbd` | `fix(261007-m0t): erro de banco no salvar virou aviso, nunca mais 500` |
| `6dc731ac` | `test(261007-m0t): trava os sete casos de CNPJ repetido` |

Commits por caminho (`git add -- <caminho>`), nunca `git add -A`. A árvore tem WIP de outra sessão em
`resources/js/Components/Publicador/` e `tests/Feature/CompanyPortfolioAccessTest.php` — nada disso foi
tocado nem commitado.

## O que ficou de fora (e por quê)

- ⛔ **Os CNPJs já gravados não foram normalizados.** Mexer nos 12 pares de dado de produção é passo
  humano separado e mudaria o que a tela mostra hoje.
- ⛔ **Nada sobre o grupo das empresas #184/#249** — há questão de cobrança em aberto com o usuário.
- ⛔ **Nada de produção**: sem deploy, VPS, `plink`, `pscp`, `.env` ou `cache:clear`.

## Pendente para o orquestrador

**A migration ainda não rodou em produção.** Em produção ela é passo do orquestrador
(`php artisan migrate --force`). Enquanto não rodar, o 500 continua acontecendo lá — o `try/catch` do
T3 já estará no ar e transformará o 500 em aviso, mas o salvar **ainda não gravará** o CNPJ repetido
até o unique sair do banco.

## Self-Check: PASSED

Os 2 arquivos novos existem em disco, os 5 commits existem no histórico, e `git status --porcelain`
nos caminhos deste quick (`app/`, `tests/Feature/Quick261007/`, `database/`, `resources/js/Pages/Admin/`,
`.planning/quick/261007-m0t-*/`) volta vazio. O WIP das outras sessões segue intocado.

## ⚠️ Incidente de árvore compartilhada — o commit T4 foi absorvido por outra sessão

Entre o commit do T4 e o commit deste SUMMARY, **outra sessão reescreveu a ponta da `main`** e o
commit `6dc731ac` (`test(261007-m0t): trava os sete casos de CNPJ repetido`) deixou de ser ancestral
do HEAD. O arquivo de teste não se perdeu: ele foi absorvido pelo commit `964c050b`, cuja mensagem é
`test(261007-etp): gates de fonte seguem a divisão fotos/dados da variação` — mensagem da OUTRA
sessão, conteúdo 100% deste quick (os 366 linhas de
`tests/Feature/Quick261007/CnpjRepetidoNaoBloqueiaCadastroTest.php`, e só isso).

**Nada foi perdido.** Conferido por diff contra os commits originais: a migration, o
`ContratoAdminController.php`, o `ContratoDetalhe.jsx` e o arquivo de teste estão **byte-idênticos**
no HEAD ao que foi commitado aqui. Os 9 testes foram rodados de novo depois da reescrita e seguem
passando (48 asserções).

**Não corrigi a história, de propósito.** Reescrever a ponta da `main` para recuperar a atribuição do
commit arrancaria os três commits que a outra sessão fez em cima — exatamente o risco descrito em
`project_sessoes_paralelas_working_tree.md`. O estado do código está certo; só a etiqueta de um
commit está errada, e isso não vale uma reescrita de história compartilhada.

Consequência prática: no histórico, o T4 deste quick aparece sob mensagem de outro quick. Quem for
procurar o teste depois acha pelo caminho (`tests/Feature/Quick261007/`), não pela mensagem do commit.
