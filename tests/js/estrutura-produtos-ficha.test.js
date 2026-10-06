import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da ficha do produto em PÁGINA INTEIRA (Fase 167-19: D-27, D-28, D-29, D-30).
//
// POR QUE EXISTE: a ficha é o único lugar de editar produto e não pode inventar
// regra (logística, cubagem e frete são do servidor), nem ganhar upload de foto
// que ninguém pediu, nem voltar a ser painel lateral (o usuário reprovou). A
// regra que era do painel lateral antigo mora no hook useFichaProduto.
//
// Lê a fonte SEM COMENTÁRIOS (helper _fonte.js): a prosa pt-BR cita os próprios
// identificadores e um gate cru passaria pelo comentário, não pelo código.
// ═══════════════════════════════════════════════════════════════════════

const DIR = 'resources/js/Components/Portal/Estrutura/Produtos/';
const hook = lerSemComentarios(`${DIR}useFichaProduto.js`);
const pecas = lerSemComentarios(`${DIR}PecasDoProduto.jsx`);
const dados = lerSemComentarios(`${DIR}FichaDadosGerais.jsx`);
const variacao = lerSemComentarios(`${DIR}CartaoVariacao.jsx`);
const volume = lerSemComentarios(`${DIR}CartaoVolume.jsx`);
const calculados = lerSemComentarios(`${DIR}FaixaCalculados.jsx`);
const lib = lerSemComentarios('resources/js/lib/produtosEstrutura.js');
const gravacao = lerSemComentarios('resources/js/lib/produtosGravacao.js');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutoFicha.jsx');
const nav = lerSemComentarios('resources/js/lib/produtosNavegacao.js');
const raiz = resolve(import.meta.dirname, '../..');
const PAINEL = 'Sheet' + 'Produto';   // o painel lateral antigo, apagado no 167-19

const contar = (fonte, re) => (fonte.match(re) ?? []).length;

test('Hook: a regra do painel antigo, agora em lotes e sem fechar nada', () => {
    assert.match(hook, /export default function useFichaProduto/);
    assert.match(hook, /export const MEDIDAS/);
    assert.ok(hook.includes('linhaDoServidor('), 'as linhas cruas do servidor precisam do retrato _base');
    assert.equal(contar(hook, /axios\.post\(/g), 1);
    // A sequência (1ª variação sozinha, lotes com o produto_id) mora em produtosGravacao, com teste
    // comportamental próprio (estrutura-produtos-gravacao.test.js); o hook só injeta o POST.
    assert.match(hook, /gravarVariacoes\(vars, \{/);
    assert.match(hook, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.linhas'\), \{ linhas \}\)/);
    assert.match(gravacao, /lote\.map\(linhaParaServidor\)/);
    assert.ok(! /grupo\s*[:=]/.test(hook.replace(/base\.grupo/g, '')), 'a ficha não monta grupo para produto novo (FE-CR-01)');
    assert.ok(! /\{ \.\.\.v, grupo \}/.test(gravacao), 'nenhum lote leva grupo');
    assert.ok(hook.includes('limites?.colar'));
    assert.ok(hook.includes('Não salvamos esta variação: informe a Ref e o nome do produto.'));
    assert.ok(hook.includes('Não salvamos esta variação: ${'));
    assert.ok(! hook.includes('Não salvamos esta linha') && ! hook.includes('toque'));
    assert.ok(hook.includes('CAMPOS_DO_PRODUTO'));
    assert.ok(hook.includes('ok:'));
    assert.ok(! hook.includes('onFechar') && ! hook.includes('onGravado'), 'o hook não fecha nem avisa a página: devolve { ok, data }');
});

test('Hook: nova variação copia a 1ª e deixa o valor vazio (D-04)', () => {
    assert.match(hook, /const nova = \{\s*\.\.\.base,/);
    assert.match(hook, /valor: '',/);
    assert.match(hook, /codigo: `\$\{base\.grupo \?\? base\.codigo\}-\$\{quantas \+ 1\}`/);
});

test('Peças: foto sem upload (D-29), selo de logística, obrigatório e caminho da categoria', () => {
    for (const e of ['QuadroFotoProduto', 'PilulaLogistica', 'Obrigatorio', 'CaminhoCategoria']) {
        assert.match(pecas, new RegExp(`export function ${e}`));
    }
    assert.ok(pecas.includes('iniciais('));
    assert.ok(! /type="file"|upload/i.test(pecas), 'foto não tem upload');
    assert.ok(pecas.includes('ESTILO_LOGISTICA') && pecas.includes('Pendente: completar cadastro'));
});

test('Dados gerais: nome, família, ambientes e categoria nos pickers do projeto', () => {
    for (const t of ['Nome do produto', 'Família', 'Ambientes', 'Categoria do Mercado Livre']) {
        assert.ok(dados.includes(t), `faltou: ${t}`);
    }
    assert.equal(contar(dados, /<Obrigatorio/g), 1, 'só o nome do produto é obrigatório aqui');
    for (const re of [/<PickerLista tipo="familia"/, /<PickerLista tipo="ambiente" multiplo/, /<PickerCategoria row=\{primeira\}/, /registrarFechar=/]) {
        assert.match(dados, re);
    }
    assert.ok(dados.includes('@radix-ui/react-popover'));
    assert.ok(dados.includes('aria-label="Limpar categoria"'));
    assert.ok(dados.includes('<QuadroFotoProduto') && dados.includes('data-ficha-dados'));
    assert.ok(! dados.includes('@/Components/ui/sheet'));
});

test('Variação: Ref · Eixo · Valor · Custo, eixo nativo, valor livre e só a Ref obrigatória (D-20)', () => {
    for (const t of ['Ref', 'Eixo', 'Valor', 'Custo (R$)', 'Excluir variação', 'Volumes']) {
        assert.ok(variacao.includes(t), `faltou: ${t}`);
    }
    assert.ok(variacao.includes('<select') && variacao.includes('<option value="">'));
    assert.ok(! variacao.includes('@/Components/ui/select'), 'Radix Select com value vazio apaga o dado');
    assert.ok(variacao.includes('inputMode="decimal"'));
    assert.equal(contar(variacao, /<Obrigatorio/g), 1);
    for (const t of ['<CartaoVolume', '<FaixaCalculados', '<PilulaLogistica', 'text-red-300', 'data-variacao-form']) {
        assert.ok(variacao.includes(t), `faltou: ${t}`);
    }
    assert.ok(variacao.includes('Ver na Lista SKUs') && variacao.includes("route('portal.auth.estrutura.lista'"));
});

test('Volume: cartão com as quatro medidas do hook e a lixeira', () => {
    for (const t of ['Volume ', 'Trash2', 'Remover volume', 'inputMode="decimal"', 'data-volume-cartao', 'MEDIDAS']) {
        assert.ok(volume.includes(t), `faltou: ${t}`);
    }
});

test('Calculados: só leitura, vindos do servidor (D-28)', () => {
    for (const t of ['Nº de volumes', 'Peso total', 'Logística provável', 'Peso cubado', 'Frete ME2', 'Adicionar volume', '.n_volumes', '.peso_total',
        'renderPesoCubado(', 'renderFrete(', "'pilha'", 'data-calculado=', 'Os calculados aparecem ao salvar.', 'Recalcula ao salvar.']) {
        assert.ok(calculados.includes(t), `faltou: ${t}`);
    }
    assert.ok(! calculados.includes('reduce(') && ! calculados.includes('+='), 'nenhuma soma no JS');
    assert.ok(! /<input/.test(calculados), 'calculado nunca é campo');
});

test('Lib: iniciais, caminho da categoria, frase de sucesso e frete empilhado', () => {
    for (const e of ['iniciais', 'partesDaCategoria', 'textoProdutoSalvo']) {
        assert.match(lib, new RegExp(`export function ${e}`));
    }
    assert.match(lib, /export function renderFrete\(row, \{ consultando = false \} = \{\}, forma = 'linha'\)/);
});

test('Sem regra de negócio, HTML cru nem herança da precificação nos arquivos da ficha', () => {
    for (const fonte of [hook, pecas, dados, variacao, volume, calculados, gravacao]) {
        assert.ok(! fonte.includes('dangerouslySetInnerHTML'));
        assert.ok(! /6000|cubag|soma_lados|me2\.peso/.test(fonte), 'regra de logística apareceu no JS');
        assert.ok(! /\b79\b/.test(fonte), 'limite de frete apareceu no JS');
        assert.ok(! /\bMath\.(ceil|floor|round)\b/.test(fonte));
        assert.ok(! fonte.includes('precificacaoProdutos'));
    }
});

test('Página: componente real no layout do portal, com a ficha, as variações e o rodapé', () => {
    assert.match(pagina, /export default function EstruturaProdutoFicha/);
    for (const t of ['PortalClienteLayout', 'useFichaProduto(', '<FichaDadosGerais', '<CartaoVariacao', '<JanelaExcluirVariacao', 'ArrowLeft',
        'Produtos', 'Variações', 'Nova variação', 'Cancelar', 'Salvar produto',
        'data-ficha-produto', 'data-acao="nova-variacao"', 'data-acao="cancelar"', 'data-acao="salvar-produto"']) {
        assert.ok(pagina.includes(t), `faltou: ${t}`);
    }
    assert.equal(contar(pagina, /bg-ecf-yellow/g), 1, 'Salvar produto é o único amarelo');
});

test('Guarda: alteração não salva pede confirmação ao sair por link, botão ou aba', () => {
    for (const t of ["router.on('before'", "addEventListener('beforeunload'", "removeEventListener('beforeunload'",
        'window.confirm(', 'Há alterações não salvas neste produto. Sair sem salvar?']) {
        assert.ok(pagina.includes(t), `faltou: ${t}`);
    }
});

test('Guarda: volta a valer se a visita confirmada falhar ou for cancelada (FE-WR-03)', () => {
    assert.ok(pagina.includes("const tirarFim = router.on('finish', () => { liberado.current = false; });"));
    assert.ok(pagina.includes('tirarFim();'), 'o ouvinte sai junto com a ficha');
});

test('Guarda: o voltar do navegador também pergunta, e quem fica volta para a ficha (FE-CR-02)', () => {
    // Em captura no window: roda antes do ouvinte do Inertia, que troca a página sem o evento `before`.
    assert.ok(pagina.includes("window.addEventListener('popstate', aoNavegarNoHistorico, true)"));
    assert.ok(pagina.includes("window.removeEventListener('popstate', aoNavegarNoHistorico, true)"));
    assert.ok(pagina.includes('e.stopImmediatePropagation()'), 'quem fica: o Inertia não vê o popstate');
    assert.ok(pagina.includes('window.history.go(passosAte(entradaDaFicha.current))'));
    assert.ok(pagina.includes('ignorarVolta.current = true'), 'o popstate da volta à ficha é ignorado');
    // Sair confirmado (link, botão ou voltar) apaga o rascunho; excluir o produto também.
    assert.ok(contar(pagina, /esquecerRascunho\(\)/g) >= 3);
    // D-32 continua: a ficha marca o último produto ao desmontar, saia como sair.
    assert.ok(pagina.includes('useEffect(() => () => marcarUltimoProduto(ultimoRef.current), [])'));
});

test('Salvando: campos, Nova variação, Excluir e pickers ficam travados durante o POST (FE-WR-02)', () => {
    assert.equal(contar(pagina, /<fieldset disabled=\{ficha\.salvando\}/g), 2, 'dados gerais e variações');
    // Nova variação e os cartões (com Excluir e volumes) estão dentro do fieldset das variações.
    const fieldsetVariacoes = pagina.slice(pagina.indexOf('data-campos-variacoes'), pagina.indexOf('</fieldset>', pagina.indexOf('data-campos-variacoes')));
    assert.ok(fieldsetVariacoes.includes('<CartaoVariacao') && fieldsetVariacoes.includes('data-acao="nova-variacao"'));
    assert.ok(pagina.includes('min-w-0 border-0 p-0'), 'o fieldset não muda o visual');
    // O gatilho de Ambientes é uma div (o fieldset não a trava): a trava mora no componente.
    assert.ok(dados.includes('if (ficha.salvando) return; fecharPicker.current = null;'));
    assert.equal(contar(dados, /if \(ficha\.salvando\) return;/g), 3, 'abrir picker, tirar ambiente e limpar categoria');
    assert.ok(dados.includes('aria-disabled={ficha.salvando || undefined}'));
});

test('Rascunho: gravado a cada alteração, oferecido ao abrir, apagado ao salvar (FE-CR-02)', () => {
    for (const t of ['gravarRascunho(', 'lerRascunho(', 'apagarRascunho(', 'recuperarRascunho', 'descartarRascunho', 'esquecerRascunho']) {
        assert.ok(hook.includes(t), `faltou no hook: ${t}`);
    }
    assert.match(hook, /if \(ok\) \{\s*setAlterado\(false\);[\s\S]*?apagarRascunho\(r\.produtoId\)/, 'salvo por inteiro apaga o rascunho');
    assert.match(hook, /useEffect\(\(\) => \{\s*if \(! alterado\) return;[\s\S]*?gravarRascunho\(id, vars\)/, 'grava só com alteração');
    for (const t of ['Você tinha alterações não salvas neste produto.', 'Recuperar', 'Descartar', 'data-rascunho',
        'onClick={ficha.recuperarRascunho}', 'onClick={ficha.descartarRascunho}']) {
        assert.ok(pagina.includes(t), `faltou na página: ${t}`);
    }
    assert.ok(nav.includes("'ecf.produtos.rascunho.'") && nav.includes('RASCUNHO_VALE_MS'));
});

test('Volta: sucesso volta para a lista com o aviso, trocando a entrada do histórico (D-27)', () => {
    assert.ok(pagina.includes('voltarParaLista('));
    assert.match(pagina, /replace: true/);
    assert.ok(pagina.includes('textoProdutoSalvo('));
    for (const proibido of ['@/Components/ui/sheet', 'Ver no Mercado Livre', 'Bell', 'Avatar']) {
        assert.ok(! pagina.includes(proibido), `a ficha não pode ter: ${proibido} (D-30)`);
    }
});

test('Navegação: ida e volta guardada em sessionStorage e sem redirecionamento aberto', () => {
    for (const e of ['guardarRetorno', 'urlDeVolta', 'voltarParaLista', 'pegarVolta', 'rolarParaVolta']) {
        assert.match(nav, new RegExp(`export function ${e}`));
    }
    assert.ok(nav.includes('sessionStorage'));
    assert.match(nav, /onStart: \(\) => gravar\(CHAVE_VOLTA/);
    assert.ok(nav.includes('url === lista || url.startsWith(`${lista}?`)'), 'só o caminho da lista é aceito como volta');
    assert.ok(! nav.includes('window.location.href ='));
});

test('O painel lateral deixou de existir (D-27)', () => {
    assert.ok(! existsSync(resolve(raiz, 'resources/js/Components/Portal/Estrutura/Produtos/' + PAINEL + '.jsx')));
    for (const fonte of [pagina, nav, hook, dados, variacao]) {
        assert.ok(! fonte.includes(PAINEL));
    }
});
