# Verificação visual no localhost — como ver a tela de verdade

Leitura obrigatória antes de dizer "conferi na tela" em qualquer alteração de
frontend, e **especialmente** nas páginas públicas (`/implementacao/{token}`,
`/nps/...`, PPA) — onde a armadilha abaixo faz a verificação medir nada.

## 1. `npm run build` verde não prova nada sobre a mudança

O projeto **não tem ESLint**. Identificador indefinido, prop não passada e
handler trocado compilam e vão para produção calados. O `✓ built in` só diz que
o Vite conseguiu empacotar.

Grep no bundle também não serve: prova o **deploy**, nunca a **função**.
Identificador livre sobrevive à minificação, então ver o nome literal no bundle
é sinal do bug, não da correção.

## 2. A página pública pode carregar o bundle de PRODUÇÃO — e o CORS a esvazia

Observado em 2026-08-17 no `/implementacao/{token}`: a página local pediu

```
https://admin.ecfconsultoria.com.br/build/assets/ImplementacaoPublica-<hash>.js
```

com o **hash do build local recém-feito** — nome certo, host errado. O Chrome
bloqueou por CORS (`No 'Access-Control-Allow-Origin' header`), o React nunca
montou, e a página renderizou como casca: o `innerText` ainda tem o texto do
Blade, então um teste ingênuo passa achando que viu a tela.

O host sai do `ASSET_URL` **efetivo**. `.env` correto não basta —
`bootstrap/cache/config.php` sobrepõe. Confirme antes de acusar o seu código:

```bash
C:/xampp/php/php.exe artisan tinker --execute="echo asset('build/x.js');"
curl -s "http://localhost/ecf_admin/public/implementacao/<TOKEN>" \
  | grep -oE 'src="[^"]*app-[^"]*\.js"'
```

Se vier host de produção: `php artisan config:clear`. **Não** edite o `.env` para
contornar — a árvore é compartilhada por mais de uma sessão e mais de um dev.

## 3. Receita que funciona: servir o build local sob a URL esperada

Em vez de mexer em config compartilhada, intercepte a requisição. Puppeteer já
está em `node_modules` (o Chrome dele, **não** — aponte para o Chrome do sistema
em `C:/Program Files/Google/Chrome/Application/chrome.exe`).

```js
await page.setRequestInterception(true);
page.on('request', req => {
    const u = req.url();
    if (!u.startsWith('https://admin.ecfconsultoria.com.br/')) return req.continue();
    const rel = u.slice('https://admin.ecfconsultoria.com.br/'.length).split('?')[0];
    const arq = `C:/xampp/htdocs/ecf_admin/public/${rel}`;
    if (!existsSync(arq)) return req.abort();
    req.respond({
        status: 200,
        headers: { 'Access-Control-Allow-Origin': '*' },   // é isto que destrava
        contentType: rel.endsWith('.js') ? 'text/javascript' : 'text/css',
        body: readFileSync(arq),
    });
});
```

Detalhes que custaram tentativa:

- Script fora da árvore do projeto **não resolve** `import puppeteer` (ESM ignora
  `NODE_PATH`). Rode com o cwd do projeto:
  `node --input-type=module -e "$(cat /caminho/script.mjs)"`.
- Sempre colete `page.on('pageerror')` e `console` de tipo `error`, e **afirme
  lista vazia**. Sem isso, um crash do React passa por "tela conferida".
- Afirme também `faltando: []` dos assets interceptados: arquivo ausente vira
  `req.abort()` silencioso.

## 4. `innerText` aplica `text-transform`

Rótulo com `uppercase` no Tailwind volta em MAIÚSCULAS no `innerText`. Regex sem
flag `i` não casa, e o teste acusa ausência de um elemento que está na tela —
foi o que fez um probe reportar "bloco de edição em massa não renderizou" com o
bloco visível no screenshot ao lado.

## 5. Semear dado de teste no banco local: limpe depois

O banco local é compartilhado entre as sessões da máquina. Semear 48 produtos
numa implementação para conferir uma tela é legítimo; **deixar** lá não é —
outra sessão encontra dado fantasma e persegue bug que não existe. Restaure o
estado anterior no fim (e confira por reconsulta, não por stdout do script).

Cuidado extra em tela com autosave: a própria conferência **grava**. O que você
digitou no probe fica persistido — o valor semeado não é o valor final.

## 6. Deploy isolado com o `main` local divergido

Em 2026-08-17 o `main` local estava **~130 commits à frente e ~180 atrás** de
`origin/main` ao mesmo tempo (histórias paralelas com as mesmas mensagens: a Fase
135 só existia no local). `deploy.sh` exige árvore limpa **e** `HEAD ==
origin/main`, então não há como deployar dali — e a árvore ainda tinha trabalho
vivo de outra sessão.

Receita que funcionou, na ordem:

1. **Provar que a base é igual antes de transplantar.** É este passo que separa
   deploy isolado de arrastar trabalho alheio:
   ```bash
   git diff origin/main HEAD -- <cada arquivo seu>   # tem de sair VAZIO
   ```
   Vazio significa que o seu diff de working-tree assenta em `origin/main` sem
   carregar commit de ninguém.
2. `git diff HEAD -- <seus arquivos> > /c/tmp/x.patch`
3. `git worktree add /c/tmp/wt -b deploy/<assunto> origin/main`
4. `git apply --check` (recusa cedo se não assentar) → `git apply` → copiar os
   arquivos novos. **`plink.exe`/`pscp.exe` são untracked**: a worktree nasce sem
   eles e o `deploy.sh` os procura ao lado do script — copiar à mão.
5. Rodar os testes **na worktree**. Ela não tem `node_modules`, então qualquer
   teste que importe pacote npm (`lib/utils.js` → `clsx`) falha por ambiente, não
   por regressão. Meça o baseline do arquivo suspeito com `git stash -u` antes de
   culpar a sua mudança.
6. Auditar as deleções do commit **antes do push** — `git show HEAD --unified=0 |
   grep '^-'` — e confirmar `HEAD~1 == origin/main` (fast-forward puro).
7. `git push origin deploy/<assunto>:main` e rodar `deploy.sh` **de dentro da
   worktree**, onde `HEAD == origin/main` e nada está sujo.

### Duas armadilhas do próprio deploy

- **Workers presos em `STOPPING`** seguram o `supervisorctl restart` e o deploy
  fica pendurado indefinidamente. Destrave: `supervisorctl signal KILL ecf-worker:*`
  — eles voltam a `RUNNING` e o script segue.
- **`pgrep -f 'reset --hard origin/main'` casa com o próprio comando** e reporta
  "deploy rodando" para sempre. Use classe de caractere: `pgrep -fa '[r]eset --hard origin'`.

### Mudança só de frontend não precisa do deploy.sh inteiro

Commit e push primeiro (arquivo solto na VPS morre no `reset --hard` seguinte —
regressão silenciosa semanas depois). Depois, na VPS:

```bash
cd /var/www/ecf_admin && git fetch origin main --quiet && git reset --hard origin/main --quiet
npx vite build && php artisan config:cache && php artisan view:cache
chown -R www-data:www-data /var/www/ecf_admin
```

Sem `composer install`, sem `migrate`, sem restart de worker — ~20s de build em
vez de 10+ minutos. Confirme pelo hash novo em `public/build/manifest.json`.
