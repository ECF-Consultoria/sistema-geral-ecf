---
task: 261012-mbw
titulo: z-index do PublicadorHeader sobrepõe o menu lateral do sistema e o painel lateral do produto
tipo: quick
data: 2026-10-12
autonomous: true
arquivos_producao:
  - resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
arquivos_teste:
  - tests/js/publicador-header-render.test.js
  - tests/js/publicador-header-nas-paginas.test.js
  - tests/js/publicador-lote.test.js
  - tests/js/publicador-paginas-anuncios-render.test.js
  - tests/js/publicador-produtos-fases.test.js
  - tests/js/publicador-produtos-layout.test.js
  - tests/js/publicador-header-zindex-ordem.test.js (novo)
---

# 261012-mbw — O header do Publicador passa a ficar ABAIXO do menu lateral e do painel do produto

## Objetivo

Trocar **uma linha de produção**: o `z-index` do `PublicadorHeader` de `z-50` para `z-30`.
Reapontar os **6 arquivos de teste** que travam o valor antigo (sem relaxar nenhum, corrigindo
também os comentários que passam a mentir) e **acrescentar um gate novo que prova a ORDEM**,
não só o valor.

## O relato do usuário (12/10/2026, conferido em produção)

> *"Ao passar o mouse sobre menu lateral do sistema ele se expande mas o header do publicador
> continua em cima sobrepondo o menu lateral do sistema"*
>
> *"Ao clicar em algum produto na tabela aparece um sidebar do lado direito, o header está por
> cima desse sidebar, isso está errado, o sidebar deveria ficar por cima"*

## Diagnóstico (já medido antes deste plano — não refazer a investigação)

Os dois sintomas são **um bug só**:

| Elemento | Onde | z-index |
|---|---|---|
| `PublicadorHeader` | `header/PublicadorHeader.jsx:153` | **`z-50`** |
| Menu lateral do sistema (expande no hover) | `AppLayout.jsx:978` | `z-40` |
| Painel lateral do produto | `PainelDoProdutoLateral.jsx:155` | `z-40` |

E os três disputam no **MESMO contexto de empilhamento**: o `<main>` do `AppLayout` (`:1048`) é
`flex-1 overflow-y-auto p-6` — **sem `z-index`, sem `transform`** — e o pai dele é
`flex-1 flex flex-col min-w-0 overflow-hidden`; **`overflow-hidden` NÃO cria contexto de
empilhamento**. Logo o `z-50` do header compara direto, na raiz, com os dois `z-40` e ganha dos
dois. É exatamente o relato.

### Por que `z-30` (medido, não escolhido por gosto)

- O conteúdo das páginas do Publicador tem no máximo **`z-20`** (`Produtos.jsx:404`, dropdown;
  `Editor.jsx:364`, rodapé sticky) e **`z-10`** (`Produtos.jsx:888` e `PublicacaoEmLote.jsx:247`,
  o bloco de filtros `sticky top-[108px]`). `z-30` fica **acima** de todos — o header continua
  cobrindo o corpo ao rolar, que é a função dele.
- `z-30` fica **abaixo** dos dois `z-40` — o menu expandido e o painel do produto passam a cobrir
  o header, que é o pedido.

### O que NÃO é afetado (já conferido)

Os dois `Popover.Content` do header são `z-[61]` (`SeletorDeEmpresaDoHeader.jsx:403`,
`ConexaoDoHeader.jsx:417`) e o modal "Nova publicação" é `z-[80]` (`ModalNovaPublicacao.jsx:88`
e `:101`). Os três **portam para o `document.body`**, vivem na raiz e **não dependem do z do
header**. Não tocar neles — mexer ali é regressão.

### Efeito colateral ESPERADO E DESEJADO

O painel do produto é `fixed inset-0 z-40` **com overlay `bg-black/50`**
(`PainelDoProdutoLateral.jsx:162`). Com o header em `z-30`, esse overlay passa a **escurecer o
header também**. É o comportamento correto que o usuário pediu ("o sidebar deveria ficar por
cima"). **Não tentar preservar o header claro por cima do overlay** — isso seria desfazer o
conserto.

## O custo real está nos gates

`z-50` está travado em **seis** arquivos de teste, em 9 linhas:

```
tests/js/publicador-header-render.test.js:188        ← lista de classes por IGUALDADE DE CONJUNTO
tests/js/publicador-header-nas-paginas.test.js:261   ← regex ancorada em ^<div><header class="...
tests/js/publicador-lote.test.js:513 (comentário), :516 (asserção)
tests/js/publicador-paginas-anuncios-render.test.js:244
tests/js/publicador-produtos-fases.test.js:401       ← só COMENTÁRIO
tests/js/publicador-produtos-layout.test.js:1299 e :1301 (comentários), :1306 (asserção)
```

Os comentários das linhas 513, 401, 1299 e 1301 **passam a mentir** depois da troca — corrigir o
texto deles, senão a próxima sessão lê `z-50` e acredita. A tabela FID da Fase 177 divergiu do
protótipo em 21 medidas justamente por documento que envelheceu.

O gate do `publicador-header-render.test.js:188` compara a lista de classes por **igualdade de
conjunto** (herança das Fases 177/178 — classe a mais reprova). Trocar o valor exige trocar o
conjunto **no mesmo commit**. Atualizar não é afrouxar: igualdade de conjunto continua igualdade
de conjunto.

## Tarefas

### Tarefa 1 — `z-50` → `z-30` no header + os 6 arquivos de teste reapontados

- `resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx:153` — **uma linha de
  produção**, só o token `z-50` → `z-30`.
- Os 6 arquivos de teste: trocar o valor nas asserções e **corrigir o texto dos comentários**.
- Nenhuma asserção relaxada: a igualdade de conjunto do `header-render` continua igualdade de
  conjunto; as regexes ancoradas continuam ancoradas.
- Travar a **contagem de ocorrências** por arquivo **antes** de aplicar e conferir **qual linha
  mudou** (na Fase 178, três mutações foram recusadas porque o alvo aparecia também em comentário
  e o `sed` trocou só a 1ª ocorrência, registrando furo inexistente).
- Verificação: `npm run test:js` nos 6 arquivos, todos verdes.
- Commit: `fix(publicador): header vai para z-30 e deixa de cobrir o menu lateral e o painel`

### Tarefa 2 — Gate novo que prova a ORDEM, lendo os três z do código

Arquivo novo `tests/js/publicador-header-zindex-ordem.test.js`. Harness que **só lê a fonte**
(via `lerSemComentarios` de `tests/js/_fonte.js`) — **não bundla**, logo não precisa de
`@radix-ui/react-dialog` no `external`.

Prova, lendo os valores **do código** e não de literais escritos à mão no teste:

1. `z(header) < z(menu lateral do AppLayout)`
2. `z(header) < z(PainelDoProdutoLateral)`
3. `z(header) > z(bloco de filtros sticky de Produtos.jsx)` — o header continua cobrindo o corpo

Por que ler do código e não conferir `z-30`: um gate que só confere `z-30` volta a passar se
alguém mudar o menu lateral para `z-20` amanhã, e o bug volta sem ninguém ver.

**Cada extração tem de FALHAR se a âncora deixar de casar** — extração que devolve `null` em
silêncio faz o gate passar pelo motivo errado.

⚠️ **Substring casa com prefixo:** `z-30` casa dentro de `z-300`; `z-3` casaria dentro de `z-30`.
Extrair com borda explícita (`(?:^|\s)z-(\d+)(?=\s|"|')`), nunca por `includes`. Este projeto já
foi mordido **quatro vezes** por essa classe de erro.

- Verificação: prova por mutação em cada uma das três pernas (quebrar o valor no arquivo **real**,
  confirmar reprovação, restaurar, conferir a restauração por **sha256**).
- Commit: `test(publicador): gate de ordem de empilhamento do header lendo os tres z do codigo`

### Tarefa 3 — Build, manifest, CSS construído e baselines

- `npm run build` obrigatório (toca `resources/`).
- Manifest **pelo JSON** (`node --input-type=commonjs`, porque o `package.json` é
  `"type":"module"`), **nunca por grep** — o hash sai com hífen. Página React fora do manifest
  **some da aplicação**.
- **Provar que `z-30` existe no CSS construído, com o valor certo** — classe que não gera CSS não
  existe para a tela. **Isca na sonda:** uma classe que eu sei que NÃO existe tem de dar ausente,
  senão a sonda está mentindo "presente" para tudo (no fechamento da Fase 178 a sonda de CSS
  reprovou 14 de 30 na 1ª rodada por erro da própria sonda).
- Baselines medidos **antes** de editar, nesta árvore, hoje:
  - PHP `tests/{Unit,Feature}/Publicador` = **1848 testes / 11.421 asserções / 0 falhando**
    (+ 47 `PHPUnit Deprecations` pré-existentes, fora de escopo).
  - JS = **3012 testes / 3011 pass / 1 fail**. A falha é a **pré-existente**
    `estrutura-grade-glide:123` ("Características secundárias nasce recolhido") — **não corrigir**.
- Gate de escopo: `git diff --stat <inicial>..HEAD -- app/ database/ resources/js/Layouts/ resources/js/Components/Mlb/Publicador/PainelDoProdutoLateral.jsx resources/js/Pages/` → **vazio**,
  **com range** (sem range sai vazio por construção e não prova nada). E provar que o gate não é
  vazio por construção: rodar o mesmo range onde eu espero arquivos e conferir que eles voltam,
  e conferir que os caminhos proibidos **existem no disco**.
- Commit: `docs(publicador): summary do 261012-mbw + posicao no STATE`

## O que NÃO fazer

- **Não** tocar no `AppLayout.jsx` nem no `PainelDoProdutoLateral.jsx` — o conserto é **no
  header**. Mexer no `z-40` deles afetaria o sistema inteiro, muito além do Publicador.
- **Não** tocar no editor (`Pages/Mlb/Publicador/Editor.jsx`, `Components/Publicador/Mesa/`) —
  território de **outro dev, ativo agora**. Necessidade ali vira item em `.planning/coordenacao/`.
- **Não** mexer nos popovers nem no modal (`z-[61]`/`z-[80]`), nem no corpo das 9 páginas.
- **Não** alterar `App\Support\Publicador`, `MlPublicacaoService` nem `PainelVisaoGeralService`.
- Nenhuma migration. Nenhum pacote instalado. `new_layout_publicador/` é somente leitura.
- **Não** tocar em `ROADMAP.md` nem `REQUIREMENTS-v26.md`. **Não** rodar
  `gsd-sdk query state.advance-plan`. No `STATE.md`, **bloco aditivo à mão, 0 linhas removidas,
  conferido por `git diff --numstat`**.
- **Não** deployar, **não** dar push, **não** tocar no VPS.
- `php` não está no PATH: `C:/xampp/php/php.exe`.

## HEAD-01 fica superado (registrar na SUMMARY, não no REQUIREMENTS)

`.planning/REQUIREMENTS-v26.md:307` diz literalmente `position: sticky; top: 0; z-index: 50`.
O **`50` foi superado por conflito medido com o layout do sistema**. O `REQUIREMENTS-v26.md`
**não** é editado aqui — a superação é registrada na **SUMMARY**, com o porquê, para a próxima
sessão não "consertar de volta" para 50.

## Critério de aceite

- [ ] `z-30` no `PublicadorHeader.jsx`, uma linha de produção
- [ ] 6 arquivos de teste reapontados, nenhum relaxado, comentários corrigidos
- [ ] Gate de ordem lendo os três z do código, com prova por mutação nas três pernas
- [ ] `npm run build` verde + manifest pelo JSON + `z-30` provado no CSS construído com isca
- [ ] PHP ≥ 1848/0 · JS ≥ 3012 com exatamente 1 fail (o pré-existente)
- [ ] Gate de escopo vazio **com range**, e provado não-vazio por construção
- [ ] `SUMMARY.md` + `STATE.md` por bloco aditivo
