import test from 'node:test';
import assert from 'node:assert/strict';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Gate da ficha do produto em PÁGINA INTEIRA (Fase 167-19: D-27, D-28, D-29, D-30).
//
// POR QUE EXISTE: a ficha é o único lugar de editar produto e não pode inventar
// regra (logística, cubagem e frete são do servidor), nem ganhar upload de foto
// que ninguém pediu, nem voltar a ser painel lateral (o usuário reprovou). A
// regra que era do SheetProduto mora no hook useFichaProduto.
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

const contar = (fonte, re) => (fonte.match(re) ?? []).length;

test('Hook: a regra do painel antigo, agora em lotes e sem fechar nada', () => {
    assert.match(hook, /export default function useFichaProduto/);
    assert.match(hook, /export const MEDIDAS/);
    assert.ok(hook.includes('linhaDoServidor('), 'as linhas cruas do servidor precisam do retrato _base');
    assert.equal(contar(hook, /axios\.post\(/g), 1);
    assert.match(hook, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.linhas'\), \{ linhas: lote\.map\(linhaParaServidor\) \}\)/);
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
    for (const fonte of [hook, pecas, dados, variacao, volume, calculados]) {
        assert.ok(! fonte.includes('dangerouslySetInnerHTML'));
        assert.ok(! /6000|cubag|soma_lados|me2\.peso/.test(fonte), 'regra de logística apareceu no JS');
        assert.ok(! /\b79\b/.test(fonte), 'limite de frete apareceu no JS');
        assert.ok(! /\bMath\.(ceil|floor|round)\b/.test(fonte));
        assert.ok(! fonte.includes('precificacaoProdutos'));
    }
});
