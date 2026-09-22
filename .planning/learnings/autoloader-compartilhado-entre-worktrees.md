# O autoloader do Composer é compartilhado entre os worktrees — e seus testes podem estar rodando na árvore errada

> Descoberto na marra em 2026-06-23 e de novo em 2026-09-22. Não é dedutível do
> código: nada no repositório diz de onde as classes `App\` estão sendo
> carregadas. Leia antes de gastar meia hora achando que sua edição em PHP "não
> faz efeito".

## O sintoma

Você edita um Controller/Service, o `grep` mostra o código novo no arquivo, os
caches do Laravel estão limpos — e a aplicação (ou o teste) continua com o
comportamento ANTIGO. O frontend pega a mudança; o PHP não.

Em **teste** o sintoma é pior porque é mudo: `php artisan test` no checkout
principal roda contra o `app/` de outra árvore. Testes novos passam ou falham por
motivo que não tem relação com a sua edição, sem nenhuma mensagem de erro.

## A causa

Este projeto é trabalhado em muitos `git worktree` simultâneos (dezenas, veja
`git worktree list`), e os worktrees compartilham o `vendor/` do checkout
principal. Quem rodar `composer install` ou `composer dump-autoload` **de dentro
de um worktree** reescreve `vendor/composer/autoload_*.php` com o `baseDir`
daquele worktree. A partir daí, `App\`, `Tests\`, `Database\Factories\` e
`Database\Seeders\` resolvem para a árvore dele — para todo mundo.

Já aconteceu apontando para `.claude/worktrees/agent-…` (2026-06) e para
`C:/tmp/ecf-polos-moveis-260921` (2026-09).

## Como confirmar em 10 segundos

Não confie em `grep` no fonte — pergunte de onde a CLASSE carrega:

```bash
grep -m3 "=> __DIR__" vendor/composer/autoload_static.php
```

Se aparecer um caminho de worktree (`/tmp/ecf-…`, `.claude/worktrees/…`) em vez
do checkout onde você está editando, é isso. O teste definitivo:

```php
(new ReflectionClass(App\Http\Controllers\SeuController::class))->getFileName()
```

## O conserto que NÃO serve quando há outra sessão viva

`composer dump-autoload` no seu checkout resolve — **e quebra a sessão do outro
dev na mesma hora**, porque agora o autoloader aponta para a sua árvore e os
testes dele passam a rodar contra o seu código. É o mesmo estrago, invertido.
Use isso só quando tiver certeza de que ninguém mais está com worktree ativo.

## O contorno que não toca em nada compartilhado

Um bootstrap de teste **fora do repositório** que registra um PSR-4 com
prioridade (`prepend = true`, roda ANTES do classmap do Composer):

```php
<?php
$base = 'C:/xampp/htdocs/ecf_admin';           // o SEU checkout
require $base . '/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($base) {
    static $prefixos = [
        'App\\'                 => '/app/',
        'Tests\\'               => '/tests/',
        'Database\\Factories\\' => '/database/factories/',
        'Database\\Seeders\\'   => '/database/seeders/',
    ];
    foreach ($prefixos as $prefixo => $dir) {
        if (strncmp($class, $prefixo, strlen($prefixo)) !== 0) {
            continue;
        }
        $arquivo = $base . $dir . str_replace('\\', '/', substr($class, strlen($prefixo))) . '.php';
        if (is_file($arquivo)) {
            require $arquivo;
            return;
        }
    }
}, true, true);
```

```bash
php vendor/bin/phpunit -c phpunit.xml --bootstrap /caminho/fora/do/repo/bootstrap_local.php tests/Feature/...
```

Dois detalhes que fazem isso funcionar:

- o `--bootstrap` da linha de comando **vence** o `bootstrap="vendor/autoload.php"`
  do `phpunit.xml`, e o resto do XML (os `<env>` do sqlite `:memory:`) continua valendo;
- `php artisan test` **não** aceita `--bootstrap` — chame o `vendor/bin/phpunit`
  direto.

## Como não cair de novo

- Não rode `composer install` / `dump-autoload` de dentro de um worktree.
- Worktree que precisa de `vendor/` próprio: copie o diretório em vez de
  depender da junction.
- Antes de concluir que "a edição não fez efeito", cheque o `autoload_static.php`.
  Em 2026-09-22 o que finalmente denunciou foi um `file_put_contents` no início
  do método que **nunca escreveu o arquivo**, enquanto a resposta HTTP voltava
  200/302 normalmente.
