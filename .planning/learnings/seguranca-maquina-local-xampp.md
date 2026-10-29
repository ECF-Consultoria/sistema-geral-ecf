# Segurança — o XAMPP da máquina de desenvolvimento expõe o projeto na rede

> Descoberto em 2026-10-29 na máquina do dev.01, em exercício de segurança.
> Contido nessa máquina no mesmo dia. **Confira a sua — o XAMPP vem assim de fábrica.**
> Nenhuma chave aparece neste arquivo.

---

## 1. O problema: duas falhas que se somam

1. **Pasta pública errada.** O projeto roda em `http://localhost/ecf_admin/public`, então o
   Apache serve `C:\xampp\htdocs` **inteira**. Pedir `/ecf_admin/.env`,
   `/ecf_admin/storage/logs/laravel.log` ou um `.zip` qualquer devolve o arquivo cru,
   **sem passar pelo Laravel** — login, `role:admin` e policies não se aplicam a esse
   caminho. A única regra padrão do Apache bloqueia `.ht*` (feita para `.htaccess`);
   `.env` passa. Arquivos `.php` são executados, não exibidos; todo o resto sai como está.
2. **Apache atende a rede.** O XAMPP vem com `Listen 80` (todas as interfaces) e o Windows
   cria regra de firewall liberando o Apache — inclusive em Wi-Fi classificada como "Pública".

**Prova (29/10):** um celular na mesma Wi-Fi baixou `http://<ip-da-máquina>/ecf_admin/composer.json`
sem login — status 200, tamanho idêntico ao arquivo no disco. O `.env` responde do mesmo jeito.

**O que vaza:** todas as chaves do `.env` (ML, Shopee, Google, Adman, SMTP, `APP_KEY`,
`AUTENTICADORES_ENC_KEY` — esta cifra o cofre de 2FA). Na máquina do dev.01 havia ainda 4
compactados do projeto dentro de `htdocs` com `.env.production`, um com export do banco
(`ecf_admin_export.sql`). Foram movidos para fora de `htdocs` e do OneDrive.

**Sinal de uso indevido:** nenhum. O `access.log` de 29/04 a 29/10 não tinha um único acesso
de outra máquina antes do teste.

---

## 2. Como conferir a sua máquina (só leitura, < 1 s cada)

```powershell
# a) Em quais endereços o Apache atende. 0.0.0.0 ou :: = aberto para a rede.
Get-NetTCPConnection -State Listen -LocalPort 80,443 | Select-Object LocalAddress,LocalPort

# b) Compactados e dumps dentro de htdocs (raiz + um nível).
Get-ChildItem -LiteralPath (@('C:\xampp\htdocs') + (Get-ChildItem C:\xampp\htdocs -Directory).FullName) -File |
  Where-Object { $_.Extension -in '.zip','.rar','.7z','.sql' } | Select-Object FullName

# c) Quantos acessos vieram de outra máquina (linhas que não começam com 127.0.0.1 nem ::1).
findstr /V /B /C:"127.0.0.1 " /C:"::1 " C:\xampp\apache\logs\access.log
```

Não use `Get-ChildItem -Recurse -Depth 1 -Include` nem `Select-String` no `access.log`: no
PowerShell 5.1 o primeiro percorre todos os `vendor/` e `node_modules/` e o segundo leva
minutos num log de ~80 MB.

**Limite do item (c):** o log só registra o que **chegou** ao Apache. Com o Apache aberto,
log limpo é evidência boa de que ninguém de fora pediu nada. Depois da correção, tentativas
são recusadas pelo Windows antes do Apache e **não deixam rastro** — log vazio deixa de provar
ausência de tentativa.

---

## 3. Como corrigir (contenção, ~10 min, reversível)

1. Backup: copie `C:\xampp\apache\conf\httpd.conf` e `C:\xampp\apache\conf\extra\httpd-ssl.conf`
   (ex.: sufixo `.antes-seguranca-AAAAMMDD`).
2. Em `httpd.conf`, troque a linha `Listen 80` por:
   ```
   Listen 127.0.0.1:80
   Listen [::1]:80
   ```
   Em `httpd-ssl.conf`, troque `Listen 443` por `Listen 127.0.0.1:443` e `Listen [::1]:443`.
   As duas linhas são necessárias: o Chrome usa `::1` (IPv6) quando se digita `localhost`.
3. `C:\xampp\apache\bin\httpd.exe -t` → tem que dizer `Syntax OK`. Se não disser, **não reinicie**.
4. XAMPP Control Panel → Apache → Stop → Start.
5. Reteste: o comando (a) só pode listar `127.0.0.1` e `::1`; o endereço pelo IP da máquina
   (no próprio PC e no celular) tem que dar `ERR_CONNECTION_REFUSED`; `localhost` segue normal.
6. Mova compactados com `.env` para fora de `htdocs` **e fora do OneDrive** (mover, não apagar).

**Efeito colateral:** nenhum outro aparelho abre o sistema local (testar tela no celular pelo IP
deixa de funcionar).

**Causa raiz ainda aberta:** o certo é um virtual host com `DocumentRoot` em `ecf_admin/public`.
Não foi feito porque muda `APP_URL`/`ASSET_URL` e já houve "tela branca" por `ASSET_URL` errado.

---

## 4. Armadilha: tela branca logo depois de reiniciar o Apache

Parece regressão da mudança acima, **não é**. Causa: `public/hot` órfão — arquivo criado por
`npm run dev` que manda o navegador buscar o JS em `http://[::1]:5173`; se o Vite não está
rodando, a página chega (200) mas fica sem JS. Na máquina do dev.01 o `hot` era de 20/10,
nove dias antes da mudança.

- Diagnóstico: F12 → Console mostra `[::1]:5173` com `ERR_CONNECTION_REFUSED`; o `access.log`
  mostra a página com 200 e nenhum pedido de `.js`.
- Correção: renomear `public/hot` (ex.: `hot.desligado-AAAAMMDD`) + `npm run build` + Ctrl+Shift+R.

Lição geral: "quebrou logo depois que mexi" não é "quebrou porque mexi" — compare tamanho da
resposta antes/depois e veja de onde vem o recurso que falhou.

---

## 5. O ambiente local NÃO é laboratório de segurança

O `.env` local tem chaves reais de integração e o banco local guarda tokens OAuth preenchidos
(contagem em 29/10: 2 em `ml_tokens`, 1 em `shopee_tokens`). Um scanner ativo (ZAP, Burp)
clica em tudo: aqui ele pode publicar anúncio, mandar e-mail por SMTP real ou chamar o Adman.
Teste ativo só em laboratório isolado: worktree próprio, `.env` com chaves de terceiros apagadas,
`MAIL_MAILER=log`, banco com dados fictícios.

---

## 6. Produção

**Não verificado.** Produção precisa servir só `public/`. Conferir — com autorização explícita,
uma única requisição GET — que `/.env` e `/storage/logs/laravel.log` em
`admin.ecfconsultoria.com.br` não respondem 200.
