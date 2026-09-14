---
quick_id: 260914-gmp
slug: setor-do-teste-reusa-o-que-existe
date: 2026-09-14
type: quick
---

# O setor criado pelo teste passa a reusar o que a migration já semeou

## O problema

`setores.nome` é **UNIQUE**. A migration `2026_09_10_140000_seed_setor_performance.php` (da outra
sessão, Fase 157) passou a criar o setor **"Performance"**. Nos testes a sequência é:

1. rodam todas as migrations → já existe um "Performance"
2. o `setUp()` do teste tenta criar o dele → o banco recusa
3. o teste **morre antes de testar qualquer coisa**

```
SQLSTATE[23000]: UNIQUE constraint failed: setores.nome
```

**28 testes vermelhos** no gate do fechamento, e o estrago maior fora dele: **39 arquivos** criam
"Performance". Todos com o mesmo padrão — `nome` igual, `slug` próprio:

```php
$this->setorId = DB::table('setores')->insertGetId([
    'nome'       => 'Performance',
    'slug'       => 'performance-122-verif',   // slug varia por arquivo
    ...
]);
```

⚠️ **Isto é SÓ nos testes.** A migration da outra sessão está bem-feita: confere por slug **e** por
nome antes de inserir, e por isso não quebrou nada no deploy. Não mexa nela.

⚠️ **Há uma segunda colisão latente, ainda não estourada:** **2 testes criam o setor "Shopee"**, que
a migration `2026_07_14_120000_seed_setor_shopee_e_usuarios.php` também semeia. Estão fora do filtro
do gate atual — conserte junto, senão vira o próximo susto.

Setores semeados por migration (a lista completa a respeitar): **Performance, Shopee, Comercial,
Desenvolvimento, Dev**.

---

## O que fazer

Nos arquivos de teste afetados, trocar "crie um setor" por "**use o que existir; só crie se não
existir**". A forma mínima, preservando o que cada teste já faz com o id:

```php
$this->setorId = DB::table('setores')->where('nome', 'Performance')->value('id')
    ?? DB::table('setores')->insertGetId([... como está hoje ...]);
```

⚠️ **Conferir, arquivo por arquivo, se o `slug` próprio é usado depois.** Reusando o setor da
migration, o slug passa a ser `performance` — se algum teste consultar pelo slug antigo
(`performance-122-verif` e parentes), ele quebra de um jeito novo. Onde isso acontecer, o teste tem
de passar a ler o slug do registro reusado, nunca fixar a string.

⚠️ **Conferir também quem conta setores.** Teste que afirme "existe 1 setor" passa a ver mais de um.
Se aparecer, ajuste a asserção para o que o teste realmente quer provar.

⚠️ **Não invente helper global nem trait nova** só para isso. São 41 arquivos com o mesmo padrão de
4 linhas; a mudança local é conferível e não cria acoplamento entre suítes que hoje são
independentes. Se você julgar que um helper é inevitável, **pare e reporte antes de criar**.

---

## O que NÃO fazer

⛔ **Não mexer em nenhuma migration** — nem na de Performance, nem na de Shopee.
⛔ **Não mexer em código de produção.** Esta tarefa é só `tests/`.
⛔ **Não afrouxar asserção para o teste passar.** Se algum teste falhar por motivo diferente da
colisão, **pare e reporte** — pode ser defeito de verdade escondido atrás do vermelho de sempre.

---

## Como saber que terminou

Antes (medido em 2026-09-14, filtro do fechamento):

```
Tests: 736 · 708 passando · 28 errors, todos "UNIQUE constraint failed: setores.nome"
```

Depois: **os 28 viram verdes** e o filtro fecha em **736 passando, 0 errors, 0 failures**.

⚠️ **Rode também a suíte inteira** (sem `--filter`) antes e depois, e reporte os dois números. É lá
que vivem os outros 13 arquivos e os 2 do Shopee. A suíte inteira tem falhas antigas conhecidas que
**não são suas** (Polos, `Phase13Comercial`, e 4 do NPS ligadas a data) — separe-as, como sempre.

---

## Travas

⚠️ **Árvore compartilhada, e a outra sessão está ativa** (milestone v23.0). Nunca `git add -A` /
`git add .` / `git commit -a` / `git stash`. `git status --porcelain tests/` (sem
`--untracked-files=no`) antes de cada commit. **`tests/Feature/CompanyPortfolioAccessTest.php` não é
seu** — está como não-rastreado e pertence à outra sessão.

⚠️ **Não use `gsd-sdk query state.advance-plan`.**

⛔ Sem deploy, sem `.env`.

Nenhum `.jsx`. PHP: `C:\xampp\php\php.exe`. Comentários e commits em **pt-BR**. Commits atômicos —
agrupe por suíte, não um commit por arquivo.
