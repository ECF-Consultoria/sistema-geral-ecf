// Sigilo da ficha do produto do Portal (decisão do usuário, 07/10/2026): nada do que o cliente vê na
// ficha pode dar a entender que o cadastro é para o Mercado Livre — nem rótulo, nem ajuda, nem erro,
// nem janela de confirmação. Os ids internos (categoria_ml_id, MLB…) ficam no código, nunca no texto.
// Em 09/10 a varredura achou "Categoria do Mercado Livre", a busca de categoria, o erro da busca e a
// janela de excluir variação ("N anúncios… item do Publicador"), todos herdados da Fase 167.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { lerSemComentarios } from './_fonte.js';

const PASTA = 'resources/js/Components/Portal/Estrutura/Produtos';
const ARQUIVOS = [
    'resources/js/Pages/Portal/EstruturaProdutoFicha.jsx',
    'resources/js/lib/fichaTecnica.js',
    'resources/js/lib/estoqueDoProduto.js',
    'resources/js/lib/medidasDoProduto.js',
    ...readdirSync(PASTA).filter((f) => /\.(jsx|js)$/.test(f)).map((f) => `${PASTA}/${f}`),
];

// Texto que o cliente leria. Variáveis como `anuncios` (sem acento) e ids como `categoria_ml_id`
// não entram: o que se proíbe é a palavra escrita para gente ler.
const PROIBIDO = /Mercado Livre|mercado livre|an[uú]ncio publicado|anúncio|Anúncio|Publicador|\bpublicar\b|\bPublicar\b/;

test('nenhum arquivo da ficha do produto escreve para o cliente a origem do cadastro', () => {
    assert.ok(ARQUIVOS.length >= 10, 'a varredura precisa cobrir a pasta inteira da ficha');
    for (const arquivo of ARQUIVOS) {
        const fonte = lerSemComentarios(arquivo);
        const achado = fonte.match(PROIBIDO);
        assert.equal(achado, null, `${arquivo} cita "${achado?.[0]}"`);
    }
});

test('os textos neutros que substituíram os de 09/10 estão lá', () => {
    assert.match(lerSemComentarios(`${PASTA}/FichaDadosGerais.jsx`), /nome="Categoria">Categoria</);
    assert.match(lerSemComentarios(`${PASTA}/PickerCategoria.jsx`), /aria-label="Buscar categoria"/);
    assert.match(lerSemComentarios(`${PASTA}/JanelaSugestoesCategoria.jsx`), /a busca está indisponível agora/);
    assert.match(lerSemComentarios(`${PASTA}/JanelaExcluirVariacao.jsx`), /já está em uso pela equipe da ECF/);
});
