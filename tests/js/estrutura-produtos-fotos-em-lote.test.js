import test from 'node:test';
import assert from 'node:assert/strict';
import {
    enviarEmRemessas, esperaDo429, filaDeEnvio, MAX_ARQUIVOS_POR_REMESSA, MAX_BYTES_POR_REMESSA, remessas, resumoDoEnvio,
    semRepetidos, textoDoResumo,
} from '../../resources/js/lib/fotosEmLote.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Fotos em lote pelo nome do arquivo (09/10/2026): `Ref_número.jpg`.
//
// POR QUE EXISTE: com centenas de fotos, o envio só é seguro se respeitar o
// tamanho que o servidor aceita por pedido (remessas pequenas), esperar quando
// ele pede calma (429) em vez de perder a remessa, e manter a ORDEM do número
// dentro de cada variação. Quem decide a variação de cada foto é o servidor.
// ═══════════════════════════════════════════════════════════════════════

const arq = (name, size = 1000) => ({ name, size });

test('semRepetidos: nome repetido (sem caixa) fica de fora; vale o primeiro', () => {
    const { unicos, repetidos } = semRepetidos([arq('MESA_1.jpg'), arq('mesa_1.JPG'), arq('MESA_2.jpg'), arq('')]);
    assert.deepEqual(unicos.map((a) => a.name), ['MESA_1.jpg', 'MESA_2.jpg']);
    assert.deepEqual(repetidos.map((a) => a.name), ['mesa_1.JPG']);
});

test('filaDeEnvio: a ordem do plano do servidor, só o que entra', () => {
    const previa = { variacoes: [
        { ref: 'A', arquivos: [{ nome: 'A_1.jpg', entra: true }, { nome: 'A_2.jpg', entra: true }] },
        { ref: 'B', arquivos: [{ nome: 'B_1.jpg', entra: true }, { nome: 'B_2.jpg', entra: false }] },
    ] };
    const escolhidos = [arq('B_2.jpg'), arq('a_2.jpg'), arq('B_1.jpg'), arq('A_1.jpg')];
    assert.deepEqual(filaDeEnvio(previa, escolhidos).map((a) => a.name), ['A_1.jpg', 'a_2.jpg', 'B_1.jpg']);
});

test('remessas: até 8 arquivos e até 8 MB; o arquivo maior que o teto vai sozinho', () => {
    const vinte = Array.from({ length: 20 }, (_, i) => arq(`X_${i}.jpg`, 100));
    assert.deepEqual(remessas(vinte).map((r) => r.length), [MAX_ARQUIVOS_POR_REMESSA, MAX_ARQUIVOS_POR_REMESSA, 4]);

    const mb = 1024 * 1024;
    const pesados = [arq('a', 3 * mb), arq('b', 3 * mb), arq('c', 3 * mb), arq('enorme', 12 * mb), arq('d', mb)];
    assert.deepEqual(remessas(pesados).map((r) => r.map((a) => a.name)), [['a', 'b'], ['c'], ['enorme'], ['d']]);
    assert.ok(remessas(pesados).every((r) => r.length === 1 || r.reduce((s, a) => s + a.size, 0) <= MAX_BYTES_POR_REMESSA));
    assert.deepEqual(remessas([]), []);
});

test('esperaDo429: usa o Retry-After do servidor (com teto) ou o padrão', () => {
    assert.equal(esperaDo429({ response: { headers: { 'retry-after': '7' } } }), 7000);
    assert.equal(esperaDo429({ response: { headers: { 'retry-after': '999' } } }), 120000);
    assert.equal(esperaDo429({ response: { headers: {} } }, 5), 5);
});

test('enviarEmRemessas: 429 espera e tenta a MESMA remessa; outro erro marca a remessa como fora e segue', async () => {
    const lista = [[arq('A_1.jpg')], [arq('B_1.jpg'), arq('B_2.jpg')], [arq('C_1.jpg')]];
    const chamadas = [];
    const esperas = [];
    const progresso = [];
    let primeiraDoB = true;
    const fim = await enviarEmRemessas(lista, {
        enviar: async (remessa) => {
            chamadas.push(remessa.map((a) => a.name).join(','));
            if (remessa[0].name === 'B_1.jpg' && primeiraDoB) {
                primeiraDoB = false;
                throw { response: { status: 429, headers: { 'retry-after': '2' } } };
            }
            if (remessa[0].name === 'C_1.jpg') throw { response: { status: 500 } };

            return { enviadas: remessa.length, resultados: remessa.map((a) => ({ nome: a.name, situacao: 'enviada', motivo: null, ref: a.name[0] })) };
        },
        avisoDoErro: () => 'Não foi possível concluir agora. Tente de novo.',
        esperar: async (ms) => { esperas.push(ms); },
        aoProgresso: (p) => progresso.push(p),
    });

    assert.deepEqual(chamadas, ['A_1.jpg', 'B_1.jpg,B_2.jpg', 'B_1.jpg,B_2.jpg', 'C_1.jpg']);
    assert.deepEqual(esperas, [2000]);
    assert.equal(fim.enviadas, 3);
    assert.equal(fim.parou, false);
    assert.deepEqual(fim.resultados.filter((r) => r.situacao === 'fora').map((r) => r.nome), ['C_1.jpg']);
    assert.ok(progresso.some((p) => p.esperando), 'a tela sabe que está esperando');
    assert.deepEqual(progresso.at(-1), { feitas: 4, total: 4, esperando: false });
});

test('enviarEmRemessas: 429 sem fim desiste depois das tentativas; parar encerra antes da próxima remessa', async () => {
    const sempre429 = await enviarEmRemessas([[arq('A_1.jpg')]], {
        enviar: async () => { throw { response: { status: 429, headers: {} } }; },
        avisoDoErro: () => 'Muitas tentativas seguidas.',
        esperar: async () => {},
        tentativas: 2,
    });
    assert.deepEqual(sempre429.resultados, [{ nome: 'A_1.jpg', situacao: 'fora', motivo: 'Muitas tentativas seguidas.', ref: null }]);

    let vivo = true;
    const enviadas = [];
    const parou = await enviarEmRemessas([[arq('A_1.jpg')], [arq('B_1.jpg')]], {
        enviar: async (r) => { enviadas.push(r[0].name); vivo = false; return { enviadas: 1, resultados: [] }; },
        vivo: () => vivo,
    });
    assert.deepEqual(enviadas, ['A_1.jpg']);
    assert.equal(parou.parou, true);
});

test('resumo do fim: fotos, variações e o que não entrou', () => {
    const resumo = resumoDoEnvio([
        { nome: 'A_1.jpg', situacao: 'enviada', ref: 'A' }, { nome: 'A_2.jpg', situacao: 'enviada', ref: 'a' },
        { nome: 'B_1.jpg', situacao: 'enviada', ref: 'B' }, { nome: 'X_1.jpg', situacao: 'fora', motivo: 'Não achamos a Ref X' },
    ]);
    assert.deepEqual({ enviadas: resumo.enviadas, variacoes: resumo.variacoes, fora: resumo.fora.length }, { enviadas: 3, variacoes: 2, fora: 1 });
    assert.equal(textoDoResumo(resumo), '3 fotos enviadas para 2 variações. 1 não entrou.');
    assert.equal(textoDoResumo({ enviadas: 1, variacoes: 1, fora: [] }), '1 foto enviada para 1 variação.');
    assert.equal(textoDoResumo({ enviadas: 0, variacoes: 0, fora: [{}, {}] }), 'Nenhuma foto foi enviada. 2 não entraram.');
});

// ─── Fonte da janela ───────────────────────────────────────────────────

const janela = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/JanelaFotosEmLote.jsx');
const barra = lerSemComentarios('resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx');
const pagina = lerSemComentarios('resources/js/Pages/Portal/EstruturaProdutos.jsx');
const lib = lerSemComentarios('resources/js/lib/fotosEmLote.js');

test('JanelaFotosEmLote: prévia só com os NOMES, depois remessas pela rota do lote, e a lista recarrega', () => {
    assert.match(janela, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.fotos\.previa'\), \{ nomes: unicos\.map\(\(a\) => a\.name\) \}\)/);
    assert.match(janela, /axios\.post\(route\('portal\.auth\.estrutura\.produtos\.fotos\.enviar'\), montarEnvio\(remessa\)/);
    assert.match(janela, /enviarEmRemessas\(remessas\(fila\), \{/);
    assert.match(janela, /const fila = filaDeEnvio\(previa, arquivos\);/);
    assert.match(janela, /if \(resumo\.enviadas > 0\) onConcluir\?\.\(\);/);
    assert.match(pagina, /<JanelaFotosEmLote aberta=\{enviandoFotos\} onFechar=\{\(\) => setEnviandoFotos\(false\)\} onConcluir=\{recarregarProdutos\} \/>/);
});

test('JanelaFotosEmLote: durante o envio a janela não fecha por fora; "Parar" encerra depois da remessa em curso', () => {
    assert.match(janela, /const fechar = \(\) => \{ if \(etapa !== 'enviando'\) onFechar\(\); \};/);
    assert.match(janela, /vivo: \(\) => rodada\.current === minha && ! parado\.current/);
    assert.ok(janela.includes('Aguardando um instante para continuar…'));
});

test('Barra: "Enviar fotos em lote" só com produtos', () => {
    assert.match(barra, /\{temProdutos && onFotosEmLote && \(\s*<button type="button" onClick=\{onFotosEmLote\} data-acao="fotos-em-lote"/);
    assert.ok(barra.includes('Enviar fotos em lote'));
    assert.match(pagina, /onFotosEmLote=\{\(\) => setEnviandoFotos\(true\)\}/);
});

test('sigilo: a janela das fotos e o módulo de apoio não dizem para onde vão as fotos', () => {
    const PROIBIDO = /Mercado Livre|mercado livre|anúncio|Anúncio|Publicador|\bpublicar\b|\bPublicar\b|\bML\b|\bMLB\b/;
    for (const [nome, fonte] of Object.entries({ janela, lib })) {
        const achado = fonte.match(PROIBIDO);
        assert.equal(achado, null, `${nome} cita "${achado?.[0]}"`);
    }
    for (const t of ['Enviar fotos em lote', 'Arraste as fotos ou clique para escolher', 'MESA-01_1.jpg', 'Ficam de fora']) {
        assert.ok(janela.includes(t), `faltou: ${t}`);
    }
});
