# Fase 184 — handoff do checkpoint humano (plano 13)

**Escrito em:** 2026-10-14
**Estado:** **12 de 13 planos concluídos.** O código está pronto e verde. O que falta é **só** o que
depende de acesso que a sessão não tem: a conta do Bling.

---

## 1. O que está pronto e medido

Números conferidos pelo orquestrador, não só reportados pelos executores:

| Gate | Resultado |
|---|---|
| `artisan test --filter=Bling` | **210 passed** (657 asserções), exit 0 |
| `npm run test:js` | **4426 pass / 1 fail** — a falha é a pré-existente `estrutura-grade-glide.test.js` (espera `RECOLHIDOS_INICIAIS = [G_SECUND]`, a fonte tem `[]`); baseline antes da fase era 4398/1, logo **+28 testes, zero regressão** |
| `tests/Feature/PortalCliente` | **757 passed**, igual ao baseline |
| `npm run build` | exit 0 |
| Migrations | provadas no **MariaDB local** (`migrate → rollback → migrate`), com `SHOW CREATE TABLE` conferindo os 7 índices nomeados e o unique disparando 1062 de verdade |
| Credencial em commit | `git log -p 092f43a2..HEAD \| grep -cE "BLING_CLIENT_(ID\|SECRET)=.+"` → **0** |
| `.env` rastreado/modificado | não |

**Os três gates do ERP da v26.0 — a promessa feita à sessão da Fase 180 — estão cumpridos:**
- `tests/Feature/Publicador/CabecalhoDoPublicadorTest.php` → **16 passed**, diff **vazio**
- `tests/Feature/Publicador/CockpitDaContaTest.php` → o teste do ERP (*"a linha do erp nao tem nenhuma
  chave de conexao"*) **passa**; diff **vazio**
- `tests/js/publicador-visao-geral-acoes.test.js` → **214 pass / 0 fail**, diff **vazio**

**Asserções removidas nesta fase: ZERO.** O estado da conexão nasce na chave **irmã** `bling`; a chave
`erp` continua exatamente `['valor' => ..., 'rotulo' => ...]`.

⚠️ **Duas falhas do `CockpitDaContaTest` não são desta fase:** são sensíveis à virada do dia (a data
virou 13→14/10 no meio da execução) e a asserção que quebra é literalmente `'a última posição é HOJE'`.
Verificado pela mensagem da própria asserção.

---

## 2. O que só você pode fazer — o plano 13

### Passo A — criar o app no Bling

1. Com um usuário que tenha a permissão **"Cadastro de aplicativos"** (Preferências > Sistema >
   Usuários), ir em **Central de Extensões > Área do Integrador > Criar aplicativo**.
2. Criar como **PÚBLICO**. ⚠️ Privado serve só à própria conta, e migrar depois é **cadastro novo de
   app e nova autorização de todos**.
3. Marcar os **9 escopos da D-03, todos de uma vez**: Produtos, Estoques, Depósitos, Categorias de
   produtos, Campos customizados, Dados básicos da empresa, Produtos-Lojas, Canais de venda e Anúncios.
   ⚠️ **Mudar escopo depois revoga TODOS os clientes conectados.** A lista fecha agora.
4. Link de redirecionamento: `https://admin.ecfconsultoria.com.br/oauth/bling/callback`.
5. Copiar **Client ID** e **Client Secret** da aba "Informações do app".
6. Criar um **segundo app, de desenvolvimento**, com o redirect local — **há um callback por app**.
7. Preencher no `.env` local: `BLING_CLIENT_ID`, `BLING_CLIENT_SECRET`, `BLING_REDIRECT_URI` (o do app
   de desenvolvimento).

⚠️ **Não cole Client ID nem Secret na conversa, em arquivo do repositório ou em commit.** Eles vivem
só no `.env`. O `.env` de **produção** fica para o deploy, que precisa de autorização explícita.

**Como saber que funcionou, sem mostrar nenhum valor:**
```
C:\xampp\php\php.exe artisan tinker --execute="echo App\Models\BlingConexao::estaConfigurado() ? 'ok' : 'falta';"
```
Hoje imprime `falta` (é o esperado com o `.env` vazio — e a tela degrada com "não configurado", nunca
com 500, o que está provado por teste). Depois do passo 7 tem de imprimir `ok`.

### Passo B — montar a massa mínima de teste

Na conta Bling de teste: 1 produto simples, 1 com variação de cor, 1 com cor × tamanho, 1 kit virtual,
2 depósitos, 1 campo customizado.

### Passo C — autorizar de verdade

Abrir `/mlb/anuncios/publicador/empresas/company-459/configuracoes`, clicar em **"Gerar link para o
cliente"**, abrir o link, autorizar na conta de teste, e conferir que o estado virou **conectado** e
que o CNPJ casou.

⚠️ **Se for ligar a conta de teste ao Mercado Livre, só à #459 (MGSTOREL, loja REAL)** — e com decisão
sua antes, porque o Bling passa a mandar **estoque E preço** aos anúncios vinculados.

### Passo D — medir as 8 suposições

Nenhuma delas é fato hoje; todas estão no código como fallback declarado, com `TODO` citando o número.
A lista está em `184-RESEARCH.md` §5. As que mais mexem no comportamento:

1. A API funciona em conta **trial**, e um app em desenvolvimento autoriza outra conta.
2. O formato real de `variacao.nome` (hoje suposto `"Cor:Azul;Tamanho:M"`).
3. Físico × virtual; **quantos `idsProdutos[]` cabem por chamada** (hoje `IDS_POR_CHAMADA_PADRAO = 50`,
   com fallback de 400/414 **sem teste dedicado**); o `limite` máximo de `/produtos`.
5. Se o **refresh gira** a cada renovação e se o `enable-jwt` é necessário nas chamadas comuns.
7. Se as `dimensoes` são do produto **embalado**.

### Passo E — abrir o item da homologação

O app público atende **até 10 contas** sem homologação, e a carteira passa disso. A homologação exige
logo, homepage pública, manual, vídeo, as 5 chamadas encadeadas de `/homologacao/produtos` e **revisão
humana sem prazo publicado**. Ficou **fora** desta fase de propósito.

---

## 3. A tensão que você aceitou, registrada

Com a D-01 (o **cliente** autoriza, por link) somada à D-03 (**todos** os escopos de uma vez), é o
cliente que vê a tela de consentimento do Bling — e ela lista **"Anúncios"** e **"Lojas"**. Isso
**expõe a plataforma de venda**, contra a regra de sigilo que o resto do Portal segue
(`assertSemOrigem`). É o risco 10 do seed e o T-184-94 do threat model: **aceito por você, por
escrito, nas duas opções**. A tela é do Bling e está fora do nosso controle; **os nossos** textos
seguem sob a regra, e isso está provado por teste.

No passo C, **registre quais escopos a tela de consentimento mostrou de fato** — é o único jeito de
saber o tamanho real da exposição.

---

## 4. Próximo passo depois do checkpoint

Fase **185**: o SKU do combo indo ao Bling como **kit com composição** (`POST /produtos`, `formato E`,
com `estrutura.componentes`). Ela depende desta: sem o `bling_produto_id` do produto-base em
`bling_vinculos` não há `componentes[].produto.id` para mandar, e o kit nasceria sem composição — que é
exatamente o que **não** resolve o estoque.

⚠️ Lembrete medido em 13/10: **o SKU do combo já é gerado hoje** (`PreviaDaFaseService::skuSugerido()`,
`-KIT{N}`/`-CB{N}`) e **já é o mesmo no Clássico e no Premium** (um rascunho, duas `listing_type_id`).
A Fase 185 é **só a escrita no Bling**, não a geração.
