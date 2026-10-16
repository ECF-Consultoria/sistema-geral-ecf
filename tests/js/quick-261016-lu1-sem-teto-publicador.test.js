import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// Quick 261016-lu1 — o kit do Publicador não tem teto: o servidor manda `regeneracoes_restantes:
// null` e o cartão da imagem tem de continuar oferecendo "Gerar de novo" (antes ele sumia quando o
// número chegava a 0, e `null > 0` é falso). Render real, mesmo harness de
// `publicador-painel-criativos-render.test.js`.

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

async function montarPainel() {
    const r = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, 'resources/js/Components/Publicador/Mesa/PainelCriativos.jsx')],
        bundle: true, format: 'esm', platform: 'node', jsx: 'automatic', write: false, logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js') },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react'],
    });
    const outfile = path.join(__dirname, `.painel-lu1-${process.pid}-${Date.now()}.mjs`);
    fs.writeFileSync(outfile, r.outputFiles[0].text, 'utf8');
    try {
        return (await import(pathToFileURL(outfile).href)).default;
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

const nada = () => {};
const c = (kit) => ({
    fase: 'kit', kit, erro: null, processando: null, confirmacaoRecusada: false, motivos: {}, alvo: { grupo: 'GERAL' },
    fechar: nada, limparErro: nada, recusarConfirmacao: nada, mostrarConfirmacao: nada, gerar: nada, planejar: nada,
    aprovar: nada, regenerar: nada, aprovarKit: nada, novoKit: nada, mudarMotivo: nada,
});
const kitCom = (restantes) => ({
    kit_id: 17, grupo: 'GERAL', status: 'pronto', etapa: null, em_andamento: false, erro: null, estrategia: null,
    pode_ter_texto: true, faltam: [], minimo_aprovadas: 1, prontas: 1, aprovadas: 0, prontas_sem_risco: 0, reprovadas: 1,
    referencias: [{ indice: 0, url: '/ref/0', nome: 'ref.jpg' }],
    slots: [{
        indice: 2, slot: 'dimensions', rotulo: 'Medidas', objetivo: 'medidas', status: 'pronto', imagem_url: '/img/2',
        regeneracoes: 1, regeneracoes_restantes: restantes, validacao_status: 'reprovada', validacao_mensagem: null,
        validacao_problemas: [], exige_confirmacao_risco: true, no_anuncio: false,
    }],
});

test('cartão da imagem — sem teto (null) oferece "Gerar de novo" e mostra o custo; com teto 0 não oferece', async (t) => {
    const Painel = await montarPainel();
    const html = (restantes) => renderToStaticMarkup(React.createElement(Painel, { c: c(kitCom(restantes)), titulo: 'Fotos' }));

    await t.test('null (Publicador) — botão presente, custo no lugar do contador', () => {
        const h = html(null);
        assert.match(h, /Gerar de novo/);
        assert.match(h, /cada nova imagem custa cerca de US\$ 0,10/);
        assert.doesNotMatch(h, /pode gerar de novo mais/);
    });

    await t.test('3 restantes — mantém o contador', () => {
        assert.match(html(3), /pode gerar de novo mais 3 vez\(es\)/);
    });

    await t.test('0 restantes (teto do assistente antigo) — sem botão', () => {
        assert.doesNotMatch(html(0), /Gerar de novo/);
    });
});
