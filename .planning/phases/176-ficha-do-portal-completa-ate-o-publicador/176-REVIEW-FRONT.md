---
phase: 176-ficha-do-portal-completa-ate-o-publicador
part: 2-frontend
reviewed: 2026-10-08T19:12:26Z
depth: standard
diff_base: 4b37f1da
files_reviewed: 29
files_reviewed_list:
  - resources/js/Components/Explicacao.jsx
  - resources/js/Components/Mlb/Publicador/BotaoSincronizarPortal.jsx
  - resources/js/Components/Mlb/Publicador/ResumoDoSincronizar.jsx
  - resources/js/Components/Mlb/Publicador/resumoDoSincronizar.js
  - resources/js/Components/Portal/Estrutura/Produtos/CampoFichaTecnica.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/CartaoVolume.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/FichaDadosGerais.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/FichaDescricao.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/FichaTecnica.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/PecasDoProduto.jsx
  - resources/js/Components/Portal/Estrutura/Produtos/useDescricaoProduto.js
  - resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js
  - resources/js/Components/Portal/Estrutura/Produtos/useFichaTecnica.js
  - resources/js/Components/Publicador/CampoAtributo.jsx
  - resources/js/Components/Publicador/EditorDeEixos.jsx
  - resources/js/Components/Publicador/Mesa/CartaoVariante.jsx
  - resources/js/Components/Publicador/Mesa/CorPrincipal.jsx
  - resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx
  - resources/js/Components/Publicador/Mesa/MedidasDoPacote.jsx
  - resources/js/Components/Publicador/Mesa/NovaVariacao.jsx
  - resources/js/Components/Publicador/Mesa/comum.jsx
  - resources/js/Components/Publicador/descricaoIa.js
  - resources/js/Components/Publicador/useDescricaoIa.js
  - resources/js/Pages/Mlb/Publicador/Editor.jsx
  - resources/js/Pages/Mlb/Publicador/Produtos.jsx
  - resources/js/Pages/Portal/EstruturaProdutoFicha.jsx
  - resources/js/lib/fichaTecnica.js
  - resources/js/lib/produtosEstrutura.js
findings:
  critical: 1
  warning: 6
  info: 7
  total: 14
status: issues_found
---

# Fase 176: Relatório de Code Review — Parte 2 (frontend)

**Revisado:** 2026-10-08T19:12:26Z
**Profundidade:** standard (com leitura dos chamados: `usePublicador.mudarRasc`, `MlbPublicadorDescricaoController`, `DescricaoIaService::pedir/concluir`, `PortalClienteLayout`)
**Arquivos revisados:** 29
**Status:** issues_found

## Resumo

Revisão adversarial do frontend da Fase 176: estoque e descrição na ficha do Portal, "Não se aplica", eixo
escondido por produto, `Explicacao` (o "o que é isto?"), descrição MAG T8 automática no editor e o resumo do
Sincronizar.

Parte do que pediram para checar está correta e foi conferida linha a linha:

- **Estoque 0 × vazio:** `estoqueParaTexto` (`produtosEstrutura.js:153`) mantém `0` como `"0"`, e
  `linhaParaServidor` (`:246-249`) só manda `null` em `limpou` (tinha valor no servidor e foi esvaziado).
  `vazio('estoque')` com `"0"` dá falso, então o 0 vai. A nova variação nasce com `estoque: ''` e manda o campo
  explícito (`novaDeProdutoGravado`). Rascunho do navegador antigo, sem a chave `estoque`, não limpa nada
  porque o `_base` vem do mesmo rascunho.
- **N/A ida e volta:** `valoresIniciais` reconhece `valor_id '-1'` (string ou número), `montarAtributos` manda
  `{id, nao_se_aplica: true}` só quando `aceitaNaoSeAplica`, e o valor digitado volta da memória ao desmarcar.
  Um campo que deixou de aceitar N/A cai no caminho normal (vazio) sem 422.
- **Eixo escondido por produto:** `eixosEmUso` casa pelo rótulo e pela chave, `gruposDoProduto` filtra a tela e
  o PUT com a mesma regra, e `gravar` lê `eixosRef` (os eixos do momento). Bate com `doProduto` no servidor
  porque as linhas são gravadas antes da ficha.
- **XSS:** nenhum `dangerouslySetInnerHTML` ou `innerHTML` nos 29 arquivos. A descrição do cliente
  (`EtapaDetalhes.jsx:183`), os textos da `Explicacao` e os avisos do resumo aparecem como texto do React.
- **Sigilo dos textos novos do Portal:** "Estoque (un.)", "Descrição do produto", a orientação, o contador, os
  `aria-label` e o comentário do `Explicacao.jsx` não citam mercado, anúncio, publicar ou MLB. O `nome="Categoria"`
  evita "Mercado Livre" no aria. O rótulo "Categoria do Mercado Livre" é texto aprovado da 167 (§34).
- **Colisão de caixa no Windows:** os imports de hoje têm extensão explícita
  (`Produtos.jsx:12`, `ResumoDoSincronizar.jsx:3`, `tests/js/publicador-sincronizar-resumo.test.js:4`), então o
  build passa. A fragilidade continua (WR-05).

O problema grave está no acompanhamento do Sincronizar. O botão do estado vazio é desmontado logo depois do
POST, e com ele o polling morre. É exatamente o caso do 1º Sincronizar de uma conta (CR-01). No editor, o
`useDescricaoIa` usa um `atual` de antes do `await`. Com isso uma resposta atrasada aplica texto depois do
tempo esgotado, de um pedido novo ou da saída da página (WR-01). O `ja_pedido` também joga fora o pedido
automático que já estava rodando (WR-02).

## Critical Issues

### CR-01: "Sincronizar do Portal" no estado vazio perde o acompanhamento: nenhum resumo e nenhum recarregar no "pronto"

**Arquivo:** `resources/js/Components/Mlb/Publicador/BotaoSincronizarPortal.jsx:23-48` + `resources/js/Pages/Mlb/Publicador/Produtos.jsx:142-152, 263-285`
**Problema:** O polling do resumo mora DENTRO do componente do botão (`vivo`, `espera`). Em `Produtos.jsx`, o
estado vazio (`vazio ? … <BotaoSincronizarPortal …/>`) é a chamada principal da tela ("Traga os produtos que o
cliente listou no Portal"). Ao clicar ali, `onConcluido` → `aoConcluirSync` roda `setRecarregando(true)` e
`router.reload`. No render seguinte o ramo vira `<Esqueleto />` e o botão do estado vazio é DESMONTADO. O
cleanup roda `vivo.current = false` e `clearTimeout(espera.current)`, e isso:
- ou cancela o `setTimeout(volta, 0)` que `acompanhar` acabou de agendar;
- ou faz a 1ª leitura voltar e parar em `if (!vivo.current) return` (linha 38).

Resultado: `setResumo(null)` (linha 143) nunca é substituído. O painel `ResumoDoSincronizar` não aparece, e o
`router.reload` do `aoLerResumo` no "pronto" nunca roda. A lista fica com o que havia logo depois do POST
(antes de os Jobs preencherem variantes e status). É justamente o caso do 1º Sincronizar de uma conta, todos os
produtos novos, em que o feedback "N produtos, M variações, K fotos" (decisão de discricionariedade do CONTEXT)
mais importa. Os testes de fonte (`tests/js/publicador-sincronizar-resumo.test.js`) não pegam isso porque só
leem o texto do arquivo.
**Correção:** tirar o polling do botão e deixá-lo na página, que não desmonta:
```jsx
// Produtos.jsx — a página acompanha; o botão só faz o POST e devolve o pedido
const acompanhando = useRef(null);
useEffect(() => () => clearTimeout(acompanhando.current?.t), []);
function acompanhar(pedido) {
    clearTimeout(acompanhando.current?.t);            // cancela o loop anterior (ver WR-03)
    const ctl = { pedido, inicio: Date.now(), t: null };
    acompanhando.current = ctl;
    const volta = async () => {
        if (acompanhando.current !== ctl) return;
        try {
            const { data } = await axios.get(route('mlb.anuncios.publicador.sincronizar.resumo', { conta: empresa.chave, pedido }));
            if (acompanhando.current !== ctl) return;
            aoLerResumo(data);
            if (data?.status === 'pronto') return;
        } catch {}
        if (Date.now() - ctl.inicio >= LIMITE_MS) { setResumo((r) => r && { ...r, status: 'expirou' }); return; }
        ctl.t = setTimeout(volta, INTERVALO_MS);
    };
    ctl.t = setTimeout(volta, 0);
}
// aoConcluirSync(json): … if (json?.pedido) acompanhar(json.pedido);
```
Alternativa mínima: manter o botão do estado vazio montado (sem trocar o ramo inteiro pelo `<Esqueleto />`). É
mais frágil.

## Warnings

### WR-01: `useDescricaoIa` decide com um `atual` capturado ANTES do `await`: uma resposta atrasada aplica texto depois que o estado mudou

**Arquivo:** `resources/js/Components/Publicador/useDescricaoIa.js:63-88`
**Problema:** `const atual = ref.current` é lido antes do `await axios.get(...)` e nunca relido. O callback do
`setInterval` é async e não há trava de "leitura em voo", então com rede lenta (> 2,5 s) as leituras se
sobrepõem. Em todos estes casos a resposta que chega tarde aplica mesmo assim:
1. **Tempo esgotado:** o tick do `LIMITE` põe `status: 'erro'`, mas um GET que já estava em voo volta com
   `pronto` e chama `mRef.current.mudarRasc(...)`. A tela mostra erro e o texto entra.
2. **Duas leituras com `pronto`:** a 1ª aplica e põe `parado`. A 2ª, com o `atual` velho (`rodando`), recalcula
   `podeAplicarDescricao` contra o texto já aplicado (≠ vazio) e RESSUSCITA `status: 'pronto', valor`. O botão
   "Usar a descrição gerada" aparece sem motivo.
3. **Saiu da página:** o cleanup só faz `clearInterval`. O GET em voo volta depois de o Editor desmontar,
   `mRef.current.mudarRasc` → `agendar('rasc')` grava a descrição no servidor 900 ms depois, sem ninguém na tela.
   Isso contraria o princípio "a TELA aplica" (learnings §10/§14).
4. **Mesa travada:** não há checagem de `mRef.current.disabled` (publicando, publicado, IA gravando ou
   `relendo`). Aplicar no meio de um `reler` concorre com a releitura.
**Correção:** reler o ref depois do `await`, impedir sobreposição e marcar a desmontagem:
```js
const vivo = useRef(true);
useEffect(() => () => { vivo.current = false; }, []);
// dentro do intervalo:
if (emVoo.current) return;
emVoo.current = true;
try {
    const { data } = await axios.get(rota('descricao-ia.status', produtoId));
    const agora = ref.current;
    if (! vivo.current || agora.status !== 'rodando' || agora.pedido !== data.pedido) return;
    if (data.status === 'pronto') {
        const pode = ! mRef.current.disabled && podeAplicarDescricao({ automatico: agora.automatico, textoNoPedido: agora.textoNoPedido, textoAgora: mRef.current.rasc?.descricao ?? '' });
        …
    }
} finally { emVoo.current = false; }
```

### WR-02: `ja_pedido` descarta o pedido automático que já está rodando ou pronto: a única chance por rascunho se perde

**Arquivo:** `resources/js/Components/Publicador/useDescricaoIa.js:33-37` (com `app/Http/Controllers/MlbPublicadorDescricaoController.php:32-36`)
**Problema:** O pedido automático vale uma vez por rascunho (`Cache::add(chaveAuto)`). Se a pessoa recarregar a
página ou abrir o mesmo produto de novo enquanto o Job roda, ou depois que ele termina sem ter sido aplicado,
o POST volta `ja_pedido` e o hook vai para `parado`. Ele não lê o `GET descricao-ia`, que ainda tem
`{pedido, status: 'rodando'|'pronto', valor}` no cache (TTL_PEDIDO). A descrição gerada (IA paga) fica órfã
no cache, a tela não mostra nem "Usar a descrição gerada", e o automático não volta (D-11). A pessoa só
recupera regerando à mão.
**Correção:** no `ja_pedido`, adotar o pedido existente:
```js
if (data.status === 'ja_pedido') {
    const { data: e } = await axios.get(rota('descricao-ia.status', produtoId));
    if (e?.pedido && (e.status === 'rodando' || e.status === 'pronto')) {
        mudar({ pedido: e.pedido, status: 'rodando', automatico: true });   // o intervalo aplica ou guarda
        return;
    }
    mudar({ status: 'parado' });
    return;
}
```
Uma opção é o servidor já devolver o `pedido` atual junto do `ja_pedido`.

### WR-03: Polling do Sincronizar: loops paralelos, painel que não fecha e spinner eterno no limite

**Arquivo:** `resources/js/Components/Mlb/Publicador/BotaoSincronizarPortal.jsx:31-48`, `resources/js/Pages/Mlb/Publicador/Produtos.jsx:131-137, 205`
**Problema:**
1. O botão só fica `disabled` durante o POST, não durante o acompanhamento. Um 2º clique chama `acompanhar` de
   novo sem cancelar o loop anterior: `espera.current` é sobrescrito e os dois loops seguem. O loop velho
   chama `onResumo` com o resumo do pedido ANTERIOR, alternando com o novo. Se o velho chegar a `pronto`
   primeiro, `resumoPronto.current = true` faz o `router.reload` cedo demais, e o `pronto` do pedido novo não
   recarrega a lista.
2. "Fechar o resumo" (`onFechar={() => setResumo(null)}`) não para o polling: 2,5 s depois `setResumo(r)` reabre
   o painel.
3. Ao bater `LIMITE_MS` o loop só retorna. O painel fica em "Preenchendo… (x/y)" com o spinner para sempre,
   sem dizer que parou de acompanhar.
**Correção:** um controlador de acompanhamento por página com cancelamento (ver o código do CR-01). `onFechar`
cancela o loop. No limite, mostrar "Ainda preenchendo; recarregue a página em alguns minutos" e tirar o spinner.

### WR-04: `Explicacao`: o balão invisível continua no layout (rolagem horizontal no celular) e não atende WCAG 1.4.13

**Arquivo:** `resources/js/Components/Explicacao.jsx:27-35`
**Problema:**
1. O balão é escondido com `invisible opacity-0` (`visibility: hidden`). O elemento continua no layout e entra
   na área rolável. Ele é `absolute left-0 top-full w-64` (até `max-w-[80vw]`) e começa no ícone. Em telas
   estreitas, todo ícone de coluna da direita estoura a borda: no Portal, Custo e Estoque em `grid-cols-2`,
   Largura/Peso do volume e campos da direita da ficha técnica. O `PortalClienteLayout` não corta
   `overflow-x`, então a página do cliente ganha rolagem horizontal mesmo sem ninguém passar o mouse. No
   editor, o `<main overflow-y-auto>` do AppLayout também vira `overflow-x: auto`.
2. WCAG 1.4.13 (Content on Hover or Focus): não fecha com Esc. Também não dá para pôr o mouse em cima do balão:
   `pointer-events-none` mais o vão `mt-1.5` fazem ele sumir no caminho.
**Correção:** tirar do layout quando fechado (o `aria-describedby` continua valendo com `display:none`) e
limitar a largura à viewport:
```jsx
<span role="tooltip" id={id}
  className="pointer-events-auto absolute left-0 top-full z-30 hidden w-64 max-w-[min(16rem,calc(100vw-2rem))] … group-hover/explicacao:block group-focus-within/explicacao:block">
```
Somar `onKeyDown={(e) => e.key === 'Escape' && e.currentTarget.blur()}` no botão. De preferência, escolher o
lado (`left-0`/`right-0`) pela posição do ícone, ou usar o `@radix-ui/react-tooltip`/`popover` que o projeto já
tem (Radix trata colisão, Esc e hover).

### WR-05: Dois arquivos que só diferem na caixa e na extensão: `ResumoDoSincronizar.jsx` × `resumoDoSincronizar.js`

**Arquivo:** `resources/js/Components/Mlb/Publicador/ResumoDoSincronizar.jsx`, `resources/js/Components/Mlb/Publicador/resumoDoSincronizar.js`
**Problema:** O build só passa no Windows porque TODO import tem extensão explícita (o learnings §14 registra
que sem ela o Vite falha com "default is not exported"). O próximo import sem extensão, como
`@/Components/Mlb/Publicador/ResumoDoSincronizar`, que é a convenção do resto do projeto, resolve de um jeito
no Windows (FS sem caixa) e de outro na VPS Linux, que builda no servidor. Ou seja: pode buildar local e
quebrar em produção, ou o contrário. O próprio learnings recomenda "nunca nomear dois arquivos só pela caixa".
**Correção:** renomear o módulo puro, por exemplo para `textosDoResumoSincronizar.js`, e atualizar os 2 imports
(`ResumoDoSincronizar.jsx:3` e `tests/js/publicador-sincronizar-resumo.test.js:4`). Os imports voltam a ser sem
extensão, como no resto do projeto.

### WR-06: A descrição do produto (até 5.000 caracteres) fica fora do rascunho do navegador: fechou a aba, perdeu

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/useFichaProduto.js:65, 90-96, 125`; `resources/js/Components/Portal/Estrutura/Produtos/useDescricaoProduto.js:15-26`
**Problema:** O rascunho local (FE-CR-02) grava só `vars` (`gravarRascunho(id, vars)`), e a oferta de
recuperar compara só `conteudo(vars)`. A descrição é o campo de texto livre mais longo da ficha e vive só no
`useState` do `useDescricaoProduto`. Se a aba fechar, o navegador cair ou o "Sair sem salvar" for aceito por
engano, ela some. E quando só a descrição mudou, o rascunho nem é oferecido, porque `conteudo(guardado.vars)`
é igual às iniciais e o rascunho é apagado na abertura (linha 74). A ficha técnica tem a mesma lacuna, que já
existia. A descrição é nova nesta fase.
**Correção:** pôr `descricao` (e, se quiser, os `valores` da ficha técnica) no payload de
`gravarRascunho`/`lerRascunho` e na comparação de `conteudo`. No `recuperarRascunho`, chamar
`descricao.alterar(guardado.descricao)`.

## Info

### IN-01: O disparo automático não fica restrito à abertura do rascunho

**Arquivo:** `resources/js/Components/Publicador/useDescricaoIa.js:52-58`
**Problema:** `pede` é recalculado a cada render. Se o rascunho abriu COM descrição e a pessoa apaga o texto, `pede`
vira `true` e o automático dispara (o `disparou` só segura a repetição). Hoje isso quase sempre dá
`nao_se_aplica`, porque o POST sai antes do debounce de 900 ms gravar o vazio. Mas depende dessa corrida. Se o
salvamento ganhar, a IA preenche um campo que a pessoa esvaziou de propósito e consome a chance única do D-11
("ao abrir um rascunho com `descricao` vazia").
**Correção:** decidir uma vez só, quando `carregou` vira `true` pela 1ª vez (por exemplo, `decidiu.current`
marcado nesse momento, dispare ou não).

### IN-02: "Não se aplica" sem o nome do campo no nome acessível

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/CampoFichaTecnica.jsx:159-165`
**Problema:** A ficha pode ter dezenas de caixas, todas anunciadas só como "Não se aplica, caixa de seleção", sem
dizer de qual campo.
**Correção:** `aria-describedby={rotuloId}` no `<input type="checkbox">`, ou um `<span className="sr-only">` com `de {campo.nome}`.

### IN-03: "Gerar descrição com IA" troca o texto existente sem confirmar e sem desfazer

**Arquivo:** `resources/js/Components/Publicador/descricaoIa.js:18`, `resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx:187`
**Problema:** No pedido manual, se o texto não mudou desde o clique, a resposta SUBSTITUI a descrição que a equipe
tinha escrito antes de clicar. É coerente com "regerar", mas o texto anterior se perde (não há desfazer).
**Correção:** com texto não vazio no pedido, cair sempre em `pronto` ("Usar a descrição gerada"), ou guardar o
texto anterior para um "Voltar ao texto anterior".

### IN-04: Chaves repetidas e `aria-live` falante no resumo

**Arquivo:** `resources/js/Components/Mlb/Publicador/ResumoDoSincronizar.jsx:17, 55`
**Problema:** `key={a}` em `avisos` repete quando dois produtos geram o mesmo aviso (warning do React e
reconciliação errada). O `aria-live="polite"` cobre a seção inteira, então o leitor de tela anuncia o contador
a cada 2,5 s.
**Correção:** `key={`${i}-${a}`}`. Pôr o `aria-live` só na frase final (estado `pronto`).

### IN-05: "mínimo 500 px" fixo no front

**Arquivo:** `resources/js/Components/Mlb/Publicador/resumoDoSincronizar.js:28`
**Problema:** Repete a regra do lado mínimo do servidor (D-15). Se o servidor mudar, o motivo mostrado mente.
**Correção:** o resumo do servidor manda o mínimo (`fotos_minimo_px`), ou o texto fica sem o número.

### IN-06: Textos antigos do Portal que citam "anúncio"/"Publicador" (fora do D-03, só registro)

**Arquivo:** `resources/js/Components/Portal/Estrutura/Produtos/CartaoVariacao.jsx:41`; `resources/js/Components/Portal/Estrutura/Produtos/JanelaExcluirVariacao.jsx:64` (fora da lista)
**Problema:** O cliente vê "· N anúncios" e "o item do Publicador fica solto". Não é regressão desta fase, e o
§34 diz que o alcance do sigilo é só o dos campos novos. Mas "Publicador" no Portal revela a ferramenta interna,
e mudar isso pede decisão do usuário.
**Correção:** levar ao usuário. Se ele aprovar, trocar por texto neutro ("o item da equipe fica solto").

### IN-07: `Explicacao` no toque e no Tab

**Arquivo:** `resources/js/Components/Explicacao.jsx:28`
**Problema:** No Safari do iPhone, tocar num `<button>` não dá foco, então o `group-focus-within` pode não abrir o
balão no celular, que é onde o cliente do Portal mais usa. O ícone também soma um Tab por campo (a ficha técnica
tem dezenas).
**Correção:** um `onClick` que alterna um estado `aberto` (o mesmo que o Esc fecha, ver WR-04). Conferir num
iPhone real.

---

_Revisado: 2026-10-08T19:12:26Z_
_Revisor: Claude (gsd-code-reviewer)_
_Profundidade: standard_
