import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// Quick 261016-mgv — (1) a tela parava de consultar o servidor quando o kit virava `pronto`, antes de
// o juiz validar: "Validando automaticamente…" só mudava com F5; (2) a imagem gerada amplia em tela
// cheia para ver os detalhes.

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const semComentarios = (s) => s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

test('hook — continua consultando enquanto alguma imagem está em validação, não só com o kit em andamento', () => {
    const f = semComentarios(fs.readFileSync(path.resolve(RAIZ, 'resources/js/Components/Publicador/useCriativosDoPublicador.js'), 'utf8'));
    assert.match(f, /const acompanhando = !! kit\?\.em_andamento \|\| \(Array\.isArray\(kit\?\.slots\) && kit\.slots\.some\(\(s\) => s\?\.validacao_status === 'pendente'\)\);/);
    assert.match(f, /if \(! acompanhando\) \{/);
    assert.match(f, /\}, \[kit\?\.kit_id, acompanhando, produtoId\]\);/);
    assert.doesNotMatch(f, /if \(! kit\?\.em_andamento\) \{/);
});

async function montar(entrada) {
    const r = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, entrada)],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
    });
    const outfile = path.join(__dirname, `.mgv-${path.basename(entrada)}-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, r.outputFiles[0].text, 'utf8');
    try {
        return (await import(pathToFileURL(outfile).href)).default;
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const nada = () => {};
const kit = {
    kit_id: 17, grupo: 'GERAL', status: 'pronto', etapa: null, em_andamento: false, erro: null, estrategia: null,
    pode_ter_texto: true, faltam: [], minimo_aprovadas: 1, prontas: 1, aprovadas: 0, prontas_sem_risco: 0, reprovadas: 0,
    referencias: [],
    slots: [
        { indice: 1, slot: 'lifestyle', rotulo: 'Ambientada', objetivo: 'x', status: 'pronto', imagem_url: 'https://admin.exemplo/img/1', regeneracoes: 0, regeneracoes_restantes: null, validacao_status: 'pendente', validacao_problemas: [] },
        { indice: 2, slot: 'dimensions', rotulo: 'Medidas', objetivo: 'y', status: 'pendente', imagem_url: null, regeneracoes: 0, regeneracoes_restantes: null, validacao_status: null, validacao_problemas: [] },
    ],
};

test('imagem gerada — botão de ampliar só onde há imagem, e o painel ampliado mostra a imagem e "Abrir em nova aba"', async (t) => {
    const Painel = await montar('resources/js/Components/Publicador/Mesa/PainelCriativos.jsx');
    const Ampliada = await montar('resources/js/Components/Publicador/Mesa/ImagemAmpliada.jsx');

    await t.test('cartão — imagem 1 vira botão de ampliar; a 2 (sem imagem) não', () => {
        const c = {
            fase: 'kit', kit, erro: null, processando: null, confirmacaoRecusada: false, motivos: {}, alvo: { grupo: 'GERAL' },
            fechar: nada, limparErro: nada, recusarConfirmacao: nada, mostrarConfirmacao: nada, gerar: nada, planejar: nada,
            aprovar: nada, regenerar: nada, aprovarKit: nada, novoKit: nada, mudarMotivo: nada,
        };
        const h = renderToStaticMarkup(React.createElement(Painel, { c, titulo: 'Fotos' }));
        assert.match(h, /data-ampliar-slot="1"/);
        assert.doesNotMatch(h, /data-ampliar-slot="2"/);
        assert.match(h, /aria-label="Ampliar a imagem 1 — Ambientada"/);
        assert.match(h, /Validando automaticamente…/);
    });

    await t.test('painel ampliado — imagem grande, rótulo, nova aba e Fechar', () => {
        const h = renderToStaticMarkup(React.createElement(Ampliada, { url: 'https://admin.exemplo/img/1', rotulo: '1. Ambientada', onFechar: nada }));
        assert.match(h, /role="dialog"/);
        assert.match(h, /<img src="https:\/\/admin\.exemplo\/img\/1"/);
        assert.match(h, /max-h-\[80vh\]/);
        assert.match(h, /Abrir em nova aba/);
        assert.match(h, /target="_blank" rel="noopener noreferrer"/);
        assert.match(h, /Fechar/);
    });

    await t.test('painel ampliado — url em formato inesperado não renderiza imagem nem link, e não lança', () => {
        for (const url of [null, undefined, '', 42, {}, []]) {
            const h = renderToStaticMarkup(React.createElement(Ampliada, { url, onFechar: nada }));
            assert.doesNotMatch(h, /<img/);
            assert.doesNotMatch(h, /Abrir em nova aba/);
        }
    });
});
