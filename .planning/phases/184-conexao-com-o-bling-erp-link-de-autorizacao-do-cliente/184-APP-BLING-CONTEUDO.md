# Cadastro do app no Bling — conteúdo pronto para colar

**Escrito em:** 2026-10-14
**Para:** o formulário de **Central de Extensões > Área do Integrador > Criar aplicativo**.
**Quem lê o que está aqui:** os revisores da homologação do Bling e, se o app for para a vitrine, os
clientes que o encontrarem lá. Por isso os textos abaixo estão escritos para **eles**, não para a
equipe — sem jargão interno, sem nome de fase, sem referência a arquivo do nosso código.

⚠️ **Três campos eu não posso preencher** — eles exigem uma URL pública que exista de verdade, e a
revisão humana do Bling abre cada uma. Estão marcados com **[VOCÊ PRECISA CRIAR]**.

---

## 1. Nome do aplicativo

```
ECF Consultoria — Catálogo e Estoque
```

Alternativa, se o Bling recusar o travessão: `ECF Consultoria - Catalogo e Estoque`

---

## 2. Descrição curta

Até onde dá para ver no seu print, o campo é de uma linha. Abaixo vai uma versão de **138 caracteres**
e uma mais curta, caso o limite seja 100:

**Versão principal (138 caracteres):**
```
Traz os produtos e o estoque do seu Bling para o painel da ECF Consultoria, para a equipe trabalhar sempre com o cadastro atualizado.
```

**Versão curta (89 caracteres):**
```
Traz produtos e estoque do seu Bling para o painel da ECF Consultoria, sempre atualizados.
```

---

## 3. Descrição longa (aceita Markdown)

**1.964 caracteres** — cabe no limite de 3.000 do seu print.

```markdown
## O que este aplicativo faz

A **ECF Consultoria & Assessoria** acompanha a operação de e-commerce dos seus clientes. Quando o
cliente usa o Bling, este aplicativo liga o cadastro de produtos dele ao painel da ECF — para que a
equipe trabalhe sempre com os dados que já existem no ERP, em vez de pedir planilha a cada mudança.

A conexão é **autorizada pelo próprio cliente**, pela conta Bling dele, e pode ser desfeita a qualquer
momento.

## O que o aplicativo lê

- **Produtos**: nome, código, variações, descrição, dimensões, peso e custo.
- **Estoque**: o saldo dos depósitos escolhidos, atualizado automaticamente ao longo do dia.
- **Depósitos e categorias**: para saber quais saldos contam e como o catálogo está organizado.
- **Dados básicos da empresa**: apenas para confirmar que a conta autorizada é mesmo a do cliente
  certo. Se o CNPJ não conferir, a conexão é recusada e nenhum dado é guardado.

## O que o aplicativo NÃO faz

- **Não altera nada no seu Bling.** Esta versão é somente leitura: não cria, não edita e não exclui
  produto, estoque, pedido nem nota.
- **Não mexe no seu preço.** O preço cadastrado no Bling é ignorado.
- **Não apaga nada.** Produto removido ou inativado no Bling apenas deixa de ser acompanhado.

## Como funciona, na prática

1. A equipe da ECF gera um link de autorização e envia ao cliente.
2. O cliente abre o link e autoriza o acesso na conta Bling dele.
3. O catálogo é importado, com uma prévia para conferência **antes** de qualquer gravação.
4. A partir daí o estoque é conferido automaticamente, e o cliente pode revogar o acesso quando
   quiser.

## Privacidade

Os dados lidos são usados somente para o trabalho de consultoria contratado, e não são
compartilhados com terceiros. A autorização é revogável pelo cliente a qualquer momento, pelo próprio
Bling ou pedindo à ECF.

## Suporte

Pelo canal de atendimento que o cliente já usa com a ECF Consultoria.
```

⚠️ **Atenção à frase "esta versão é somente leitura".** Ela é **verdadeira hoje** — está provada por
teste: o cliente HTTP do catálogo **não tem nenhum método de escrita**. Mas a **Fase 185** vai criar
kits no seu Bling (`POST /produtos`). **Quando a 185 entrar em produção, esta descrição tem de ser
atualizada no mesmo dia** — descrição que promete algo que o app não cumpre mais é exatamente o que a
revisão do Bling procura.

---

## 4. Link de redirecionamento ⚠️ DECISÃO SUA

⚠️ **Achei uma divergência que o plano da fase não tinha visto, e ela derruba a autorização se
escolhermos errado.**

O plano assumiu `admin.ecfconsultoria.com.br`. Mas as duas integrações OAuth que **já funcionam em
produção** usam outro domínio — está em `config/services.php:59` e `:104`:

```
https://desafio.ecfconsultoria.com.br/oauth/mercadolivre/callback
https://desafio.ecfconsultoria.com.br/oauth/shopee/callback
```

Isso importa mais no Bling do que nos outros: **o Bling ignora o `redirect_uri` que a gente manda na
URL** — vale **só** o que está cadastrado no app. Se o cadastrado não for um endereço que a aplicação
atende de verdade, o cliente autoriza e cai numa página que não existe.

**A opção que eu recomendo** — mesmo domínio já provado em produção para OAuth:
```
https://desafio.ecfconsultoria.com.br/oauth/bling/callback
```

**A outra opção**, se `admin.` também atende a aplicação (é a URL canônica no `CLAUDE.md`):
```
https://admin.ecfconsultoria.com.br/oauth/bling/callback
```

➡️ **Me diga qual dos dois** e eu ajusto o `.env.example` e o handoff. Não dá para decidir pelo
código: os dois domínios podem apontar para a mesma aplicação, e eu não consigo verificar DNS daqui.

**No app de DESENVOLVIMENTO** (o segundo cadastro), o endereço é o do seu ambiente local — algo como:
```
http://localhost:8000/oauth/bling/callback
```
Confira a porta que você usa no `php artisan serve` ou no XAMPP.

---

## 5. Link da homepage

```
https://ecfconsultoria.com.br
```

Já existe e é pública. ✅

---

## 6. Link do manual **[VOCÊ PRECISA CRIAR]**

Não existe hoje. A revisão humana do Bling **abre esse link** — apontar para a home não passa.

Precisa ser uma página pública explicando, para o **cliente**, como conectar. O conteúdo mínimo, que
eu já escrevi para você aproveitar:

> **Como conectar seu Bling à ECF**
> 1. A equipe da ECF envia um link de autorização (WhatsApp ou e-mail). O link vale **7 dias**.
> 2. Abra o link e confira o nome da sua empresa na tela.
> 3. Clique em **Autorizar acesso**. Você será levado ao Bling para entrar na sua conta.
> 4. O Bling mostra quais informações o aplicativo vai acessar. Confirme.
> 5. Pronto: a tela avisa que a conexão foi concluída.
>
> **Para desconectar:** peça à ECF, ou revogue o aplicativo no próprio Bling, em
> Central de Extensões > Meus aplicativos.
>
> **Se o link expirar:** peça um novo à ECF. Gerar um link novo cancela o anterior.

Onde publicar: uma página no site da ECF (ex.: `https://ecfconsultoria.com.br/manual-bling`) ou um
documento público. **Me diga a URL quando existir** e eu a registro no handoff da fase.

---

## 7. Link do vídeo demonstrativo

Campo **opcional** (sem o asterisco vermelho no seu print). Pode deixar vazio por enquanto.

⚠️ Mas a lista de itens da **homologação** inclui vídeo. Se a intenção é homologar (e é, porque sem
homologação o app para em **10 contas**), um vídeo de 1-2 minutos mostrando os passos 1 a 5 do manual
resolve. **Não é bloqueio para criar o app agora.**

---

## 8. Logo

Criei três arquivos, a partir da identidade que já está travada no sistema (`Phase 32, D-01 LOCKED`):

| Arquivo | Quando usar |
|---|---|
| `public/images/icone-ecf.svg` | **O que eu recomendo mandar ao Bling.** Quadrado 512×512, fundo escuro, funciona em qualquer vitrine |
| `public/images/logo-ecf-claro.svg` | Lockup horizontal para **fundo claro** |
| `public/images/logo-ecf-escuro.svg` | Lockup horizontal para fundo escuro (é a spec LOCKED, sem desvio) |

**Duas coisas que você precisa saber antes de usar:**

1. ⚠️ **A logo travada do sistema é BRANCA**, feita para fundo escuro — e o formulário do Bling é
   branco. Por isso criei a variante de fundo claro, onde **só a cor do texto muda** (`#ffffff` →
   `#0f1116`). Proporções, gradiente e pesos seguem iguais. O próprio comentário do arquivo original
   já previa isto: *"quando surgir uso real em fundo claro, ajustar aqui"*.
2. ⚠️ **A fonte Montserrat não está embutida no SVG** — o repositório não tem arquivo de fonte, ela vem
   do Google Fonts em runtime. Onde Montserrat não estiver instalada, o texto cai para
   Helvetica/Arial. No ícone isso **não quebra a composição** (usei `text-anchor="middle"`), mas a
   letra fica diferente. **Para fidelidade garantida, exportar do arquivo original com o texto
   convertido em contornos** — se você tiver o `.ai`/`.fig` da marca, esse é o caminho certo.

⚠️ No ícone quadrado fiz **duas adaptações declaradas**: o subtítulo "Consultoria & Assessoria" saiu
(a 32 px vira borrão — é prática padrão de app icon) e a barra do gradiente ficou horizontal, com a
mesma ordem de cores. Se você preferir o lockup completo mesmo no ícone, me diga que eu refaço.

---

## 9. Lembretes que valem mais que o formulário

1. **Marque os 9 escopos de uma vez**: Produtos, Estoques, Depósitos, Categorias de produtos, Campos
   customizados, Dados básicos da empresa, Produtos-Lojas, Canais de venda e Anúncios.
   ⚠️ **Mudar escopo depois revoga TODOS os clientes conectados.** A lista fecha agora.
2. **App PÚBLICO**, não privado. Privado atende só a própria conta, e migrar depois é cadastro novo
   de app e nova autorização de todos.
3. **Dois apps**: o de produção e o de desenvolvimento — há **um callback por app**.
4. **Não cole Client ID nem Client Secret** na conversa, em arquivo do repositório ou em commit. Eles
   vivem só no `.env`.
5. ⚠️ **O cliente vai ver "Anúncios" e "Lojas"** na tela de consentimento do Bling. É a tensão que
   você aceitou por escrito (D-03). Quando autorizar na conta de teste, **anote quais escopos a tela
   mostrou de fato** — é o único jeito de medir o tamanho real da exposição.
