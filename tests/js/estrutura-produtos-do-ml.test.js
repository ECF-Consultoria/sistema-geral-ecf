import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { PRIORIDADES, alternar, marcarPagina, podeMarcar, qualidadeNoMl, resumoDoCadastro, rotuloDaJanela, textoDoItem, textoNoPortal } from '../../resources/js/lib/produtosDoMl.js';

// ═══════════════════════════════════════════════════════════════════════
// Produtos → Trazer do Mercado Livre (28/10/2026): as regras da tela.
//
// POR QUE EXISTE: o diagnóstico e a prioridade vêm do servidor; a tela só decide o que dá para
// marcar (nada já trazido, nada andando, teto por envio) e o texto. E o selo de origem diz SÓ
// que o produto foi importado — decisão do usuário: nada sobre o que se faz com a ficha.
// ═══════════════════════════════════════════════════════════════════════

const anuncio = (mlb, extra = {}) => ({ mlb, no_portal: null, ...extra });

test('não marca o que já foi trazido nem o que está andando', () => {
    assert.equal(podeMarcar(anuncio('MLB1')), true);
    assert.equal(podeMarcar(anuncio('MLB1', { no_portal: { produto_id: 3, origem: 'importado' } })), false);
    assert.equal(podeMarcar(anuncio('MLB1', { no_portal: { produto_id: 3, origem: 'publicador' } })), false, 'nascido no Portal não se importa');
    assert.equal(podeMarcar(anuncio('MLB1'), { MLB1: { situacao: 'importando' } }), false);
    assert.equal(podeMarcar(anuncio('MLB1'), { MLB1: { situacao: 'fila' } }), false);
    assert.equal(podeMarcar(anuncio('MLB1'), { MLB1: { situacao: 'erro' } }), true, 'deu erro: pode tentar de novo');
});

test('a seleção respeita o teto por envio', () => {
    let s = new Set();
    s = alternar(s, 'A', 2);
    s = alternar(s, 'B', 2);
    s = alternar(s, 'C', 2);
    assert.deepEqual([...s], ['A', 'B']);
    s = alternar(s, 'A', 2);
    assert.deepEqual([...s], ['B']);

    const pagina = [anuncio('X'), anuncio('Y', { no_portal: { produto_id: 1, origem: 'mapeamento' } }), anuncio('Z'), anuncio('W')];
    assert.deepEqual([...marcarPagina(new Set(), pagina, {}, 2)], ['X', 'Z']);
});

test('o resumo do cadastro diz título, Modelo, campos e fotos', () => {
    const d = { titulo: { chars: 38, max: 60 }, modelo: { vazio: true, fora_do_padrao: false }, campos: { preenchidos: 12, total: 40 }, fotos: 1 };
    assert.deepEqual(resumoDoCadastro(d), ['Título 38/60', 'Modelo vazio', 'Campos 12 de 40', '1 foto']);
    assert.deepEqual(resumoDoCadastro({ erro: 'x' }), []);
    assert.deepEqual(resumoDoCadastro(undefined), []);
    assert.equal(PRIORIDADES.alta.rotulo, 'Recomendado importar');
});

test('o painel diz o que aconteceu, sem contar como a ficha foi completada', () => {
    assert.equal(textoDoItem({ situacao: 'pronto', campos_ia: 3 }), 'Produto criado. Completamos 3 campos da ficha técnica — confira.');
    assert.equal(textoDoItem({ situacao: 'pronto', campos_ia: null }), 'Produto criado. Completando a ficha técnica…');
    assert.equal(textoDoItem({ situacao: 'erro', mensagem: 'Este anúncio não é da conta conectada.' }), 'Este anúncio não é da conta conectada.');
    for (const item of [{ situacao: 'pronto', campos_ia: 1 }, { situacao: 'pronto', campos_ia: null }, { situacao: 'pronto', campos_ia: null, ia_erro: true }]) {
        assert.ok(! /\bIA\b|intelig/i.test(textoDoItem(item)), textoDoItem(item));
    }
});

test('o selo só diz que foi importado', () => {
    const selo = readFileSync(new URL('../../resources/js/Components/Portal/Estrutura/Produtos/SeloImportadoDoMl.jsx', import.meta.url), 'utf8');
    assert.match(selo, /Importado do Mercado Livre/);
    const texto = selo.replace(/\/\/.*$/gm, '');
    assert.ok(! /an[uú]ncio|publica|\bIA\b/i.test(texto.replace(/Importado do Mercado Livre/g, '')), 'o selo não fala de anúncio, publicação nem IA');
});

test('textos profissionais: importar, nunca trazer; nenhum rótulo depreciativo', () => {
    const fontes = [
        '../../resources/js/Pages/Portal/EstruturaProdutosDoMl.jsx',
        '../../resources/js/lib/produtosDoMl.js',
        '../../resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx',
    ].map((f) => readFileSync(new URL(f, import.meta.url), 'utf8').replace(/^\s*\/\/.*$/gm, '').replace(/\{\/\*[\s\S]*?\*\/\}/g, '').replace(/\/\*\*[\s\S]*?\*\//g, ''));
    for (const texto of fontes) {
        assert.ok(! /\b[Tt]raz(er|ido|endo|idos)\b|trouxe|\b[Pp]iores?\b/.test(texto), 'texto informal ou depreciativo na tela');
    }
    assert.match(fontes[0], /Importar do Mercado Livre/);
    assert.match(fontes[1], /Maior oportunidade de melhoria/);
});

test('janela dos números e qualidade do ML em texto claro', () => {
    assert.equal(rotuloDaJanela('Visitas', { metricas: { dias: 5 } }), 'Visitas (5 dias)');
    assert.equal(rotuloDaJanela('Visitas', { metricas: { dias: 1 } }), 'Visitas (1 dia)');
    assert.equal(rotuloDaJanela('Vendas', { metricas: { dias: 30 } }), 'Vendas 30 dias');
    assert.equal(rotuloDaJanela('Vendas', undefined), 'Vendas 30 dias');
    assert.equal(qualidadeNoMl({ saude_nivel: 'Boa', saude_ml: 88 }), 'Boa · 88');
    assert.equal(qualidadeNoMl({ saude_nivel: null, saude_ml: null }), '—');
    assert.equal(textoNoPortal({ origem: 'publicador' }), 'Já está no Portal · ver produto');
    assert.equal(textoNoPortal({ origem: 'importado' }), 'Já importado · ver produto');
});
