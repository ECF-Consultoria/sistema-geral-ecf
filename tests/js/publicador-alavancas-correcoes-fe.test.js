import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Correções da revisão do frontend da Fase 166 (CR-FE-*, WR-FE-*).
// Testes de fonte (o `node --test` não importa .jsx) e de função pura (`formato.js`).
// ═══════════════════════════════════════════════════════════════════════

const RAIZ = resolve(import.meta.dirname, '../..');
const PASTA = 'resources/js/Components/Mlb/Alavancas';

const JSX_DA_PASTA = readdirSync(resolve(RAIZ, PASTA), { recursive: true })
    .map((c) => String(c).replaceAll('\\', '/'))
    .filter((c) => /\.jsx$/.test(c))
    .map((c) => `${PASTA}/${c}`);

/** Corpo (entre as chaves) da `function nome(...) { ... }`, por contagem de chaves. */
function corpoDaFuncao(fonte, nome) {
    const inicio = fonte.search(new RegExp(`function ${nome}\\s*\\(`));
    if (inicio === -1) return null;
    const abre = fonte.indexOf('{', fonte.indexOf(')', inicio));
    let nivel = 0;
    for (let i = abre; i < fonte.length; i++) {
        if (fonte[i] === '{') nivel++;
        if (fonte[i] === '}' && --nivel === 0) return fonte.slice(abre + 1, i);
    }

    return null;
}

// ─── CR-FE-01: o `onConcluido` só relê; quem fecha é o `onFechar` ───
for (const caminho of JSX_DA_PASTA) {
    const fonte = lerSemComentarios(caminho);

    test(`${caminho} — CR-FE-01: função passada ao onConcluido não desmonta a janela nem o formulário`, () => {
        for (const m of fonte.matchAll(/onConcluido=\{(\w+)\}/g)) {
            const corpo = corpoDaFuncao(fonte, m[1]);
            if (corpo === null) continue;
            assert.doesNotMatch(corpo, /set(Alvo|Form)\(\s*null\s*\)/, `${m[1]} fecha janela/formulário dentro do onConcluido`);
        }
        for (const m of fonte.matchAll(/onConcluido=\{\(([^)]*)\)\s*=>\s*([^}]*)\}/g)) {
            assert.doesNotMatch(m[2], /set(Alvo|Form)\(\s*null\s*\)/);
        }
    });
}

test('CR-FE-01: ModalConfirmacao entrega o último resultado ao onFechar', () => {
    const fonte = lerSemComentarios(`${PASTA}/ModalConfirmacao.jsx`);
    assert.match(fonte, /onFechar\?\.\(ultimo\.current\)/);
    assert.doesNotMatch(fonte, /onClick=\{onFechar\}/);
});

test('CR-FE-01: FormCupom e AbaCupons fecham o formulário só no onEncerrado (resultado OK)', () => {
    const form = lerSemComentarios(`${PASTA}/Cupons/FormCupom.jsx`);
    const aba = lerSemComentarios(`${PASTA}/AbaCupons.jsx`);
    assert.match(form, /resultado\?\.resultado === 'OK'\) onEncerrado/);
    assert.match(aba, /onEncerrado=\{\(\) => setForm\(null\)\}/);
});

// ─── CR-FE-02: fmtData não desloca data pura ───
test('CR-FE-02: data pura aaaa-mm-dd sai igual, sem conversão de fuso', async () => {
    const { fmtData } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(fmtData('2026-10-05'), '05/10/2026');
    assert.equal(fmtData('2026-10-18'), '18/10/2026');
    assert.equal(fmtData('2026-01-01', { hora: true }), '01/01/2026');
});

test('CR-FE-02: instante com Z ou offset continua convertido para São Paulo', async () => {
    const { fmtData } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(fmtData('2026-10-05T02:00:00Z'), '04/10/2026');
    assert.equal(fmtData('2026-10-05T15:30:00Z', { hora: true }), '05/10/2026 12:30');
    assert.equal(fmtData('2026-10-05T00:00:00-03:00'), '05/10/2026');
});

test('CR-FE-02: ISO sem fuso vale horário de São Paulo, e vazio/inválido vira traço', async () => {
    const { fmtData } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(fmtData('2026-10-05T23:59:59'), '05/10/2026');
    assert.equal(fmtData('2026-10-05T23:59:59', { hora: true }), '05/10/2026 23:59');
    assert.equal(fmtData(null), '—');
    assert.equal(fmtData('lixo'), '—');
});

// ─── WR-FE-09: leitura única de número pt-BR ───
test('WR-FE-09: lerNumero — com vírgula, ponto é milhar e vírgula é decimal', async () => {
    const { lerNumero } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(lerNumero('1.500,50'), 1500.5);
    assert.equal(lerNumero('12,50'), 12.5);
    assert.equal(lerNumero('1.234.567,8'), 1234567.8);
});

test('WR-FE-09: lerNumero — sem vírgula, ponto + 3 dígitos é milhar; o resto é decimal', async () => {
    const { lerNumero } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(lerNumero('1.500'), 1500);
    assert.equal(lerNumero('1.299'), 1299);
    assert.equal(lerNumero('1.500.000'), 1500000);
    assert.equal(lerNumero('1.5'), 1.5);
    assert.equal(lerNumero('85.90'), 85.9);
    assert.equal(lerNumero('0.500'), 0.5);
    assert.equal(lerNumero('1500'), 1500);
});

test('WR-FE-09: lerNumero — vazio e inválido viram null; `positivo` recusa zero e negativo', async () => {
    const { lerNumero } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(lerNumero(''), null);
    assert.equal(lerNumero('  '), null);
    assert.equal(lerNumero(null), null);
    assert.equal(lerNumero('abc'), null);
    assert.equal(lerNumero('1,2,3'), null);
    assert.equal(lerNumero('0'), 0);
    assert.equal(lerNumero('0', { positivo: true }), null);
    assert.equal(lerNumero('-5', { positivo: true }), null);
});

test('WR-FE-09: nenhuma tela mantém cópia própria do parser (todas usam lerNumero)', () => {
    for (const caminho of JSX_DA_PASTA) {
        const fonte = lerSemComentarios(caminho);
        assert.doesNotMatch(fonte, /includes\(','\)/, `${caminho} tem parser próprio`);
        assert.doesNotMatch(fonte, /\.replace\(',', '\.'\)/, `${caminho} tem parser próprio`);
    }
    for (const arq of ['Promocoes/ItensDoConvite', 'Promocoes/AdicionarProdutos', 'Promocoes/DescontoIndividual', 'Cupons/FormCupom', 'Atacado/FaixasDoAnuncio', 'Promocoes/CampanhasDoVendedor']) {
        assert.match(lerSemComentarios(`${PASTA}/${arq}.jsx`), /lerNumero/, arq);
    }
});

// ─── WR-FE-07: dia em São Paulo, não em UTC ───
test('WR-FE-07: diaSP converte instante com Z/offset para o dia de São Paulo', async () => {
    const { diaSP } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(diaSP('2026-10-05T02:00:00Z'), '2026-10-04');
    assert.equal(diaSP('2026-10-18T02:59:59Z'), '2026-10-17');
    assert.equal(diaSP('2026-10-05T00:00:00-03:00'), '2026-10-05');
    assert.equal(diaSP('2026-10-05T23:30:00-03:00'), '2026-10-05');
});

test('WR-FE-07: diaSP sem fuso devolve os 10 primeiros caracteres; vazio/inválido vira texto vazio', async () => {
    const { diaSP } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(diaSP('2026-10-05'), '2026-10-05');
    assert.equal(diaSP('2026-10-05T23:59:59'), '2026-10-05');
    assert.equal(diaSP(null), '');
    assert.equal(diaSP('lixo'), '');
});

test('WR-FE-07: FormCupom e CampanhasDoVendedor usam diaSP, sem cortar o ISO em UTC', () => {
    for (const arq of ['Cupons/FormCupom', 'Promocoes/CampanhasDoVendedor']) {
        const fonte = lerSemComentarios(`${PASTA}/${arq}.jsx`);
        assert.match(fonte, /diaSP\(/, arq);
        assert.doesNotMatch(fonte, /\.slice\(0, 10\)/, arq);
    }
});

// ─── WR-FE-08: período do cupom em dias inclusivos, como o servidor ───
test('WR-FE-08: diasInclusivos conta as duas pontas', async () => {
    const { diasInclusivos } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(diasInclusivos('2026-10-07', '2026-10-07'), 1);
    assert.equal(diasInclusivos('2026-10-05', '2026-11-04'), 31);
    assert.equal(diasInclusivos('2026-10-05', '2026-11-05'), 32);
    assert.equal(diasInclusivos('', '2026-11-05'), null);
});

test('WR-FE-08: periodoDeCupomOk aceita 1 dia e 31 dias, recusa 32 dias e fim antes do início', async () => {
    const { periodoDeCupomOk } = await import('../../resources/js/Components/Mlb/Alavancas/formato.js');
    assert.equal(periodoDeCupomOk('2026-10-07', '2026-10-07'), true);
    assert.equal(periodoDeCupomOk('2026-10-05', '2026-11-04'), true);
    assert.equal(periodoDeCupomOk('2026-10-05', '2026-11-05'), false);
    assert.equal(periodoDeCupomOk('2026-10-07', '2026-10-06'), false);
    assert.equal(periodoDeCupomOk('', ''), false);
});

test('WR-FE-08: FormCupom valida o período por periodoDeCupomOk, sem diferença de datas própria', () => {
    const fonte = lerSemComentarios(`${PASTA}/Cupons/FormCupom.jsx`);
    assert.match(fonte, /periodoDeCupomOk\(f\.inicio, f\.fim\)/);
    assert.doesNotMatch(fonte, /86400000/);
});

// ─── WR-FE-05: lote fechado/esgotado também relê a tela ───
test('WR-FE-05: ModalConfirmacao chama concluir ao fechar com lote e ao esgotar o polling', () => {
    const fonte = lerSemComentarios(`${PASTA}/ModalConfirmacao.jsx`);
    const fechar = corpoDaFuncao(fonte, 'fechar');
    assert.match(fechar, /if \(loteId\) concluir\(lote\.dados \?\? null\)/);
    assert.match(fonte, /if \(loteId && lote\.esgotou\) concluir\(lote\.dados \?\? null\)/);
});

test('WR-FE-05: concluir continua protegido pelo ref (uma releitura só)', () => {
    const corpo = corpoDaFuncao(lerSemComentarios(`${PASTA}/ModalConfirmacao.jsx`), 'concluir');
    assert.match(corpo, /if \(concluido\.current\) return;/);
    assert.match(corpo, /concluido\.current = true;/);
});

// ─── WR-FE-10: polling do lote sem sobreposição ───
test('WR-FE-10: useLote encadeia as leituras (sem setInterval) e descarta resposta velha', () => {
    const fonte = lerSemComentarios(`${PASTA}/useAlavancas.js`);
    const lote = fonte.slice(fonte.indexOf('export function useLote'));
    assert.doesNotMatch(lote, /setInterval|clearInterval/);
    assert.match(lote, /if \(! vivo \|\| emVoo\) return;/);
    assert.match(lote, /numero > ultimaAplicada/);
    assert.match(lote, /temporizador = setTimeout\(ler, INTERVALO_LOTE\)/);
});

test('WR-FE-10: useLote para no desmonte, ao terminar e em 403/404', () => {
    const fonte = lerSemComentarios(`${PASTA}/useAlavancas.js`);
    const lote = fonte.slice(fonte.indexOf('export function useLote'));
    assert.match(lote, /vivo = false;\s*clearTimeout\(temporizador\);/);
    assert.match(lote, /if \(r\.data\?\.terminado\) fim = true;/);
    assert.match(lote, /\[403, 404\]\.includes\(e\.response\?\.status\)\) fim = true/);
    assert.match(lote, /if \(vivo && ! fim\)/);
});

// ─── WR-FE-06: incluir produtos relê o ItensDoConvite aberto ao lado ───
for (const arq of ['Promocoes/CampanhasDoVendedor', 'AbaCupons']) {
    test(`${arq} — WR-FE-06: AdicionarProdutos sobe a versão e o ItensDoConvite remonta por key`, () => {
        const fonte = lerSemComentarios(`${PASTA}/${arq}.jsx`);
        assert.match(fonte, /const \[versaoItens, setVersaoItens\] = useState\(0\)/);
        assert.match(fonte, /<ItensDoConvite key=\{`\$\{c\.id\}-\$\{versaoItens\}`\}/);
        const adicionar = fonte.slice(fonte.indexOf('<AdicionarProdutos'));
        assert.match(adicionar.slice(0, adicionar.indexOf('/>')), /onConcluido=\{\(\) => setVersaoItens\(\(n\) => n \+ 1\)\}/);
    });
}

// ─── WR-FE-01: Convites usa o mesmo critério do Panorama ───
test('WR-FE-01: CONVITES_DO_ML e STATUS_ENCERRADOS espelham o PHP', async () => {
    const { CONVITES_DO_ML, STATUS_ENCERRADOS } = await import('../../resources/js/Components/Mlb/Alavancas/rotulos.js');
    const lista = (php, nome) => [...php.match(new RegExp(String.raw`${nome} = \[([^\]]*)\]`))[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
    const tipos = readFileSync(resolve(RAIZ, 'app/Services/Publicador/Alavancas/TiposDePromocao.php'), 'utf8');
    const panorama = readFileSync(resolve(RAIZ, 'app/Services/Publicador/Alavancas/PanoramaService.php'), 'utf8');
    assert.deepEqual([...CONVITES_DO_ML].sort(), lista(tipos, 'CONVITES_DO_ML').sort());
    assert.deepEqual([...STATUS_ENCERRADOS].sort(), lista(panorama, 'STATUS_ENCERRADOS').sort());
});

test('WR-FE-01: ehConviteAberto tira campanha do vendedor, cupom, preço individual e encerradas', async () => {
    const { ehConviteAberto } = await import('../../resources/js/Components/Mlb/Alavancas/rotulos.js');
    assert.equal(ehConviteAberto({ tipo: 'DEAL', status: 'candidate', dias_para_vencer: 3 }), true);
    assert.equal(ehConviteAberto({ tipo: 'DEAL', status: 'candidate', dias_para_vencer: 0 }), true);
    assert.equal(ehConviteAberto({ tipo: 'DEAL', status: 'candidate' }), true);
    assert.equal(ehConviteAberto({ tipo: 'DEAL', status: 'finished', dias_para_vencer: null }), false);
    assert.equal(ehConviteAberto({ tipo: 'DEAL', status: 'started', dias_para_vencer: -1 }), false);
    assert.equal(ehConviteAberto({ tipo: 'SELLER_CAMPAIGN', status: 'started', dias_para_vencer: 5 }), false);
    assert.equal(ehConviteAberto({ tipo: 'SELLER_COUPON_CAMPAIGN', status: 'started' }), false);
    assert.equal(ehConviteAberto({ tipo: 'PRICE_DISCOUNT', status: 'started' }), false);
    assert.equal(ehConviteAberto(null), false);
});

test('WR-FE-01: Convites.jsx filtra a lista por ehConviteAberto', () => {
    assert.match(lerSemComentarios(`${PASTA}/Promocoes/Convites.jsx`), /\(dados\?\.itens \?\? \[\]\)\.filter\(ehConviteAberto\)/);
});

// ─── WR-FE-03: troca de período não mostra número do período antigo ───
test('WR-FE-03: AbaPublicidade esconde os números enquanto a nova leitura não chega', () => {
    const fonte = lerSemComentarios(`${PASTA}/AbaPublicidade.jsx`);
    assert.match(fonte, /\{carregando && <p[^>]*>Carregando…<\/p>\}/);
    assert.match(fonte, /\{! carregando && dados && ! dados\.indisponivel && \(/);
    assert.match(fonte, /\{! carregando && meus\.length > 0 && \(/);
    assert.doesNotMatch(fonte, /carregando && ! dados &&/);
});
