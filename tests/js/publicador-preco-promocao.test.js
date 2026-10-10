import test, { after } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { lerSemComentarios } from './_fonte.js';
import { MOTIVOS, arredondar, promocaoDoPreco, textoDaPromocao } from '../../resources/js/Components/Publicador/promocaoAutomatica.js';

// ═══════════════════════════════════════════════════════════════════════════
// Preço de promoção do Portal no editor do Publicador (10/10/2026): a conta da
// tela é a MESMA do servidor (`PrecoDaPromocao`, os mesmos números do
// `PrecoDaPromocaoTest`) e a frase embaixo do preço (13px, sem amarelo).
// Render REAL (esbuild + react-dom/server): prop do servidor em formato
// inesperado nunca derruba a tela (lição da tela preta de 07/10).
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');
const PORTAL = { anunciado: 207.19, minimo: 172.66, sem_frete: false };

// ─── A conta, igual à do PHP ────────────────────────────────────────────────

test('mesmos números do PrecoDaPromocaoTest: anunciado → mínimo, digitado → mesmo percentual, nunca abaixo do mínimo', () => {
    assert.deepEqual(promocaoDoPreco(207.19, PORTAL), { calculavel: true, motivo: null, preco: 172.66, percentual: 16.67, minimo: 172.66, ajustadaAoMinimo: false });
    assert.equal(promocaoDoPreco(250, PORTAL).preco, 208.34);
    assert.equal(promocaoDoPreco(250, PORTAL).percentual, 16.66);
    assert.deepEqual([promocaoDoPreco(195, PORTAL).preco, promocaoDoPreco(195, PORTAL).percentual, promocaoDoPreco(195, PORTAL).ajustadaAoMinimo], [172.66, 11.46, true]);
    assert.equal(promocaoDoPreco(100, { anunciado: 100, minimo: 20.01 }).percentual, 79.99);
    assert.equal(arredondar(1.005), 1.01, 'o round do PHP, sem o erro do ponto flutuante');
});

test('sem promoção: os mesmos motivos do PHP', () => {
    const casos = [
        [null, PORTAL, 'sem_preco'],
        [150, null, 'sem_portal'],
        [150, { anunciado: 150, minimo: null }, 'sem_portal'],
        [207.19, { ...PORTAL, sem_frete: true }, 'sem_frete'],
        [172.66, PORTAL, 'no_minimo'],
        [160, PORTAL, 'no_minimo'],
        [180, PORTAL, 'desconto_pequeno'],
        [100, { anunciado: 100, minimo: 20 }, 'desconto_grande'],
    ];
    for (const [preco, portal, motivo] of casos) {
        const r = promocaoDoPreco(preco, portal);
        assert.equal(r.calculavel, false, motivo);
        assert.equal(r.motivo, motivo);
        assert.ok(MOTIVOS[motivo]);
    }

    const php = fs.readFileSync(path.resolve(RAIZ, 'app/Support/Publicador/PrecoDaPromocao.php'), 'utf8');
    for (const [chave, frase] of Object.entries(MOTIVOS)) {
        assert.ok(php.includes(`'${chave}'`), `motivo ${chave} existe no PHP`);
        assert.ok(php.includes(frase), `mesma frase do motivo ${chave}`);
    }
});

test('a frase embaixo do preço: automática, da Central (conta sem promoção automática), digitado e no mínimo', () => {
    assert.equal(textoDaPromocao(207.19, PORTAL, true), 'Promoção automática: R$ 172,66 (−16,67%) por 14 dias depois de publicar.');
    assert.equal(textoDaPromocao(250, PORTAL, true), 'Promoção automática: R$ 208,34 (−16,66%) por 14 dias depois de publicar. Mesmo desconto do Portal sobre este preço, nunca abaixo de R$ 172,66.');
    assert.equal(textoDaPromocao(195, PORTAL, true), 'Promoção automática: R$ 172,66 (−11,46%) por 14 dias depois de publicar. Fica no mínimo do Portal (R$ 172,66).');
    assert.match(textoDaPromocao(207.19, PORTAL, false), /^Promoção da Central: R\$ 172,66 \(−16,67%\)\. Nesta conta ela não é criada sozinha/);
    assert.equal(textoDaPromocao(180, PORTAL, true), 'Sem promoção automática: o desconto ficaria abaixo de 5%.');
    // Preço do Portal sem frete: quem fala é o V-SAL-08 embaixo do campo; digitado, a frase explica.
    assert.equal(textoDaPromocao(207.19, { ...PORTAL, sem_frete: true }, true), null);
    assert.equal(textoDaPromocao(210, { ...PORTAL, sem_frete: true }, true), 'Sem promoção automática: o preço do Portal foi calculado sem frete.');
    // Sem Portal, sem preço ou sem saber se a conta é automática: nada.
    assert.equal(textoDaPromocao(150, null, true), null);
    assert.equal(textoDaPromocao(null, PORTAL, true), null);
    assert.equal(textoDaPromocao(207.19, PORTAL, null), null);
});

// ─── Gates de fonte ─────────────────────────────────────────────────────────

test('CampoPreco e EtapaCondicoes — a promoção vem do portal da variante, 13px, sem amarelo', () => {
    const campo = lerSemComentarios('resources/js/Components/Publicador/Mesa/CampoPreco.jsx');
    assert.match(campo, /import \{ textoDaPromocao \} from '\.\.\/promocaoAutomatica\.js'/);
    assert.match(campo, /textoDaPromocao\(precoAgora, portal, promocaoAutomatica\)/);
    assert.match(campo, /<p className="mt-1\.5 text-\[13px\] text-white\/50" data-promocao-automatica/);
    assert.doesNotMatch(campo, /ecf-yellow/);

    const etapa = lerSemComentarios('resources/js/Components/Publicador/Mesa/EtapaCondicoes.jsx');
    assert.match(etapa, /const portal = v\.portal\?\.\[lt\] \?\? null/);
    assert.match(etapa, /m\.estado\?\.promocao_automatica\?\.automatica/);
    assert.match(etapa, /portal=\{portal\} promocaoAutomatica=/);
});

// ─── Render real ────────────────────────────────────────────────────────────

const STUB_INERTIA = path.join(__dirname, `.preco-promocao-inertia-stub-${process.pid}.mjs`);
fs.writeFileSync(STUB_INERTIA, `
import React from 'react';
export function Link({ href, children, className, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, reload: () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
after(() => fs.rmSync(STUB_INERTIA, { force: true }));

async function montar(relativo) {
    const resultado = await esbuild.build({
        entryPoints: [path.resolve(RAIZ, relativo)],
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        write: false,
        logLevel: 'silent',
        alias: { '@': path.resolve(RAIZ, 'resources/js'), '@inertiajs/react': STUB_INERTIA },
        external: ['react', 'react-dom', 'react/jsx-runtime', 'lucide-react', 'axios', '@radix-ui/react-popover'],
    });
    const outfile = path.join(__dirname, `.preco-promocao-render-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2)}.mjs`);
    fs.writeFileSync(outfile, resultado.outputFiles[0].text, 'utf8');
    try {
        return await import(pathToFileURL(outfile).href);
    } finally {
        fs.rmSync(outfile, { force: true });
    }
}

test('CampoPreco — render real da promoção embaixo do preço', async (contexto) => {
    const { default: CampoPreco } = await montar('resources/js/Components/Publicador/Mesa/CampoPreco.jsx');
    const html = (props) => renderToStaticMarkup(React.createElement(CampoPreco, {
        valor: null, efetivo: 207.19, disabled: false, onMudar: () => {}, chave: '__single__', tipo: 'gold_special', comPortal: true, rotulo: 'Preço', id: 'p', ...props,
    }));

    await contexto.test('preço do Portal: a promoção é o mínimo, por 14 dias', () => {
        const h = html({ portal: PORTAL, promocaoAutomatica: true });
        assert.match(h, /data-promocao-automatica="__single__\|gold_special"[^>]*>Promoção automática: R\$ 172,66 \(−16,67%\) por 14 dias depois de publicar\.</);
        assert.match(h, /do Portal/);
    });

    await contexto.test('digitado diferente: mesmo percentual e o aviso do mínimo', () => {
        assert.match(html({ valor: 250, portal: PORTAL, promocaoAutomatica: true }), /R\$ 208,34 \(−16,66%\)[^<]*Mesmo desconto do Portal sobre este preço, nunca abaixo de R\$ 172,66\./);
    });

    await contexto.test('conta sem promoção automática, variação publicada e prop estranha', () => {
        assert.match(html({ portal: PORTAL, promocaoAutomatica: false }), /Promoção da Central: R\$ 172,66/);
        assert.doesNotMatch(html({ portal: PORTAL, promocaoAutomatica: true, disabled: true }), /data-promocao-automatica/);
        assert.doesNotMatch(html({ portal: null, promocaoAutomatica: true }), /data-promocao-automatica/);
        const estranha = html({ portal: { anunciado: { x: 1 }, minimo: 'abc' }, promocaoAutomatica: true });
        assert.doesNotMatch(estranha, /\[object Object\]|data-promocao-automatica/);
    });
});
