import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import {
    AVISO_FALHA_GERAL, AVISO_GRANDE_DEMAIS, AVISO_SEM_SALVAR, LIMITE_IMAGENS,
    aplicarImagens, avisosDoErro, decidirEnvio, imagensDaResposta, manterImagensAtuais, montarEnvio,
    moverImagem, mudouAOrdem, ordemDeIds, prepararRemessa, quantasCabem, separarArquivos, soltarSobre,
} from '../../resources/js/lib/imagensVariacao.js';
import { lerSemComentarios } from './_fonte.js';

// ═══════════════════════════════════════════════════════════════════════
// Galeria de imagens por variação na ficha do produto.
//
// POR QUE EXISTE: a galeria grava na hora (envio, ordem, exclusão) e o servidor
// é "tudo ou nada" no teto de 12. O que decide a tela — se pode enviar, o que
// responder a cada erro, a nova ordem — roda aqui DE VERDADE (a função real,
// não o texto do arquivo). Os gates estruturais travam o resto: sem upload
// novo na ficha fora da galeria, sem marcar a ficha como alterada, e o sigilo
// da tela (nada que cite marketplace ou publicação).
// ═══════════════════════════════════════════════════════════════════════

// Fonte crua (com comentários) para o gate de sigilo.
const lerCru = (caminho) => readFileSync(resolve(import.meta.dirname, '../..', caminho), 'utf8');

const DIR = 'resources/js/Components/Portal/Estrutura/Produtos/';
const img = (id, ordem) => ({ id, url: `/x/${id}`, nome_original: `f${id}.jpg`, ordem, capa: ordem === 0, mime: 'image/jpeg', tamanho: 10, largura: 1, altura: 1 });
const galeria = (...ids) => ids.map((id, i) => img(id, i));
const arq = (name) => ({ name });
const falha = (status, data = {}) => Object.assign(new Error('x'), { response: { status, data } });

// ─── Pode enviar? ───────────────────────────────────────────────────────

test('Envio: variação sem id (produto novo ou "Nova variação") não envia e mostra a dica', () => {
    for (const variacaoId of [undefined, null, 0, '']) {
        const r = decidirEnvio({ variacaoId, total: 0 });
        assert.equal(r.pode, false);
        assert.equal(r.motivo, AVISO_SEM_SALVAR);
    }
    assert.equal(AVISO_SEM_SALVAR, 'Salve o produto para enviar as imagens desta variação.');
});

test('Envio: gravada com espaço pode; enviando ou ocupada, não; no teto de 12, não e explica', () => {
    assert.deepEqual(decidirEnvio({ variacaoId: 9, total: 0 }), { pode: true, motivo: null });
    assert.deepEqual(decidirEnvio({ variacaoId: 9, total: 11 }), { pode: true, motivo: null });
    assert.equal(decidirEnvio({ variacaoId: 9, total: 3, enviando: true }).pode, false);
    assert.equal(decidirEnvio({ variacaoId: 9, total: 3, ocupado: true }).pode, false);
    const cheia = decidirEnvio({ variacaoId: 9, total: LIMITE_IMAGENS });
    assert.equal(cheia.pode, false);
    assert.match(cheia.motivo, /12 imagens possíveis/);
});

test('Quanto cabe: nunca negativo', () => {
    assert.equal(quantasCabem(0), 12);
    assert.equal(quantasCabem(5), 7);
    assert.equal(quantasCabem(12), 0);
    assert.equal(quantasCabem(20), 0);
    assert.equal(quantasCabem(undefined), 12);
});

// ─── Escolha de arquivos ────────────────────────────────────────────────

test('Arquivos: só jpg, jpeg, png e webp (qualquer caixa) passam', () => {
    const { aceitos, recusados } = separarArquivos([arq('a.JPG'), arq('b.png'), arq('c.WebP'), arq('d.jpeg'), arq('e.gif'), arq('f.pdf'), arq('semextensao')]);
    assert.deepEqual(aceitos.map((a) => a.name), ['a.JPG', 'b.png', 'c.WebP', 'd.jpeg']);
    assert.deepEqual(recusados.map((a) => a.name), ['e.gif', 'f.pdf', 'semextensao']);
    assert.deepEqual(separarArquivos(null), { aceitos: [], recusados: [] });
});

test('Remessa: formato recusado vira aviso e o resto segue', () => {
    const r = prepararRemessa([arq('a.jpg'), arq('b.gif')], 0);
    assert.deepEqual(r.arquivos.map((a) => a.name), ['a.jpg']);
    assert.equal(r.avisos.length, 1);
    assert.match(r.avisos[0], /"b\.gif" não está num formato aceito/);
});

test('Remessa: tudo recusado não envia nada', () => {
    const r = prepararRemessa([arq('a.gif'), arq('b.pdf')], 0);
    assert.deepEqual(r.arquivos, []);
    assert.match(r.avisos[0], /2 arquivos não estão num formato aceito/);
});

test('Remessa: tudo ou nada no teto, e o aviso diz quantas ainda cabem', () => {
    const tres = [arq('a.jpg'), arq('b.jpg'), arq('c.jpg')];
    const r = prepararRemessa(tres, 10);
    assert.deepEqual(r.arquivos, []);
    assert.match(r.avisos.join(' '), /Cabem mais 2 imagens/);
    assert.match(prepararRemessa(tres, 11).avisos.join(' '), /Cabem mais 1 imagem nesta/);
    assert.match(prepararRemessa(tres, 12).avisos.join(' '), /já tem as 12 imagens/);
    assert.equal(prepararRemessa(tres, 9).arquivos.length, 3, 'cabe exatamente: envia');
});

// ─── Corpo do envio ─────────────────────────────────────────────────────

test('Multipart: um campo `imagens[]` por arquivo, na ordem escolhida', () => {
    const a = new File(['aa'], 'a.jpg', { type: 'image/jpeg' });
    const b = new File(['bb'], 'b.png', { type: 'image/png' });
    const corpo = montarEnvio([a, b]);
    assert.ok(corpo instanceof FormData);
    const todos = corpo.getAll('imagens[]');
    assert.equal(todos.length, 2);
    assert.deepEqual(todos.map((f) => f.name), ['a.jpg', 'b.png']);
    assert.equal([...corpo.keys()].every((k) => k === 'imagens[]'), true);
    assert.equal(montarEnvio([]).getAll('imagens[]').length, 0);
});

// ─── Respostas do servidor ──────────────────────────────────────────────

test('Resposta: devolve a galeria quando é lista; senão null', () => {
    const lista = galeria(1, 2);
    assert.equal(imagensDaResposta({ imagens: lista, mensagem: 'ok' }), lista);
    assert.deepEqual(imagensDaResposta({ imagens: [] }), []);
    assert.equal(imagensDaResposta({}), null);
    assert.equal(imagensDaResposta(null), null);
    assert.equal(imagensDaResposta({ imagens: 'x' }), null);
});

test('Erro 422: lista em errors.imagens, por arquivo em imagens.N, sem repetir', () => {
    const lista = avisosDoErro(falha(422, { errors: { imagens: ['Cada variação aceita até 12 imagens. Envie menos de uma vez.'] } }));
    assert.deepEqual(lista, ['Cada variação aceita até 12 imagens. Envie menos de uma vez.']);

    const porArquivo = avisosDoErro(falha(422, { errors: {
        'imagens.10': ['A imagem 11 não está num formato aceito. Envie JPG, PNG ou WebP.'],
        'imagens.2': ['A imagem 3 passa de 10 MB; tente uma menor.'],
        'imagens.3': ['A imagem 3 passa de 10 MB; tente uma menor.'],
    } }));
    assert.deepEqual(porArquivo, ['A imagem 3 passa de 10 MB; tente uma menor.', 'A imagem 11 não está num formato aceito. Envie JPG, PNG ou WebP.']);

    const mistura = avisosDoErro(falha(422, { errors: { 'imagens.0': 'Mensagem solta', imagens: ['Geral'] } }));
    assert.deepEqual(mistura, ['Geral', 'Mensagem solta'], 'o aviso geral vem primeiro e texto solto vira lista');
});

test('Erro: imagem grande demais (422 do servidor ou 413 do servidor web) tem a mesma frase clara', () => {
    assert.equal(AVISO_GRANDE_DEMAIS, 'A imagem é grande demais para enviar; tente uma menor.');
    assert.deepEqual(avisosDoErro(falha(422, { errors: { imagens: [AVISO_GRANDE_DEMAIS] } })), [AVISO_GRANDE_DEMAIS]);
    assert.deepEqual(avisosDoErro(falha(413, '<html>413</html>')), [AVISO_GRANDE_DEMAIS]);
});

test('Erro: queda de rede, sessão, limite de tentativas e o resto têm texto próprio', () => {
    assert.match(avisosDoErro(new Error('Network Error'))[0], /Sem conexão/);
    assert.match(avisosDoErro(falha(429))[0], /Muitas tentativas/);
    assert.match(avisosDoErro(falha(419))[0], /sessão expirou/);
    assert.match(avisosDoErro(falha(404))[0], /não existe mais/);
    assert.deepEqual(avisosDoErro(falha(500, { message: 'Server Error' })), [AVISO_FALHA_GERAL]);
    assert.deepEqual(avisosDoErro(falha(422, {})), [AVISO_FALHA_GERAL]);
    assert.deepEqual(avisosDoErro(falha(422, { message: 'Dado inválido.' })), ['Dado inválido.']);
});

// ─── Ordem ──────────────────────────────────────────────────────────────

test('Ordem: mover reenumera ordem e capa; a 1ª é sempre a capa', () => {
    const r = moverImagem(galeria(1, 2, 3, 4), 3, 0);
    assert.deepEqual(ordemDeIds(r), [3, 1, 2, 4]);
    assert.deepEqual(r.map((i) => i.ordem), [0, 1, 2, 3]);
    assert.deepEqual(r.map((i) => i.capa), [true, false, false, false]);
    assert.equal(r[0].url, '/x/3', 'o resto do objeto segue intacto');
});

test('Ordem: para trás, no mesmo lugar, nos extremos e com id ou posição inválidos', () => {
    const base = galeria(1, 2, 3);
    assert.deepEqual(ordemDeIds(moverImagem(base, 1, 2)), [2, 3, 1]);
    assert.equal(moverImagem(base, 2, 1), base, 'mesmo lugar: a mesma lista');
    assert.deepEqual(ordemDeIds(moverImagem(base, 1, 99)), [2, 3, 1], 'passou do fim: vai para o fim');
    assert.deepEqual(ordemDeIds(moverImagem(base, 3, -5)), [3, 1, 2], 'antes do começo: vira capa');
    assert.equal(moverImagem(base, 42, 0), base);
    assert.equal(moverImagem(base, 1, NaN), base);
    assert.deepEqual(base.map((i) => i.id), [1, 2, 3], 'a lista original não é alterada');
    assert.deepEqual(moverImagem(undefined, 1, 0), []);
});

test('Arrastar: soltar sobre outra miniatura ocupa a posição dela', () => {
    const base = galeria(1, 2, 3, 4);
    assert.deepEqual(ordemDeIds(soltarSobre(base, 4, 1)), [4, 1, 2, 3]);
    assert.deepEqual(ordemDeIds(soltarSobre(base, 1, 3)), [2, 3, 1, 4]);
    assert.equal(soltarSobre(base, 1, 99), base, 'alvo desconhecido: nada muda');
});

test('Ordem: só vai PUT quando a ordem mudou', () => {
    const base = galeria(1, 2, 3);
    assert.equal(mudouAOrdem(base, moverImagem(base, 2, 1)), false);
    assert.equal(mudouAOrdem(base, moverImagem(base, 2, 0)), true);
    assert.deepEqual(ordemDeIds(moverImagem(base, 2, 0)), [2, 1, 3], 'é o corpo do PUT');
});

// ─── Estado da ficha ────────────────────────────────────────────────────

test('Estado: troca só as imagens da variação pela chave; as outras e os campos ficam', () => {
    const vars = [{ _k: 'v1', id: 1, codigo: 'A', imagens: [] }, { _k: 'v2', id: 2, codigo: 'B', imagens: galeria(7) }];
    const nova = galeria(5, 6);
    const r = aplicarImagens(vars, 'v1', nova);
    assert.equal(r[0].imagens, nova);
    assert.equal(r[0].codigo, 'A');
    assert.equal(r[1], vars[1], 'a outra variação é o mesmo objeto');
    assert.equal(vars[0].imagens.length, 0, 'não altera o estado anterior');
    assert.deepEqual(aplicarImagens(vars, 'inexistente', nova), vars);
});

test('Rascunho recuperado: as imagens são as de agora, e variação não gravada fica sem', () => {
    const doRascunho = [{ _k: 'v1', id: 1, codigo: 'A', imagens: galeria(1, 2, 3) }, { _k: 'm1', codigo: 'X', imagens: galeria(9) }, { _k: 'v3', id: 3, codigo: 'C' }];
    const atuais = [{ id: 1, imagens: galeria(2) }];
    const r = manterImagensAtuais(doRascunho, atuais);
    assert.deepEqual(ordemDeIds(r[0].imagens), [2], 'apagou 1 e 3 depois do rascunho: não voltam');
    assert.deepEqual(r[1].imagens, []);
    assert.deepEqual(r[2].imagens, []);
    assert.equal(r[0].codigo, 'A');
});

// ─── Gates estruturais ──────────────────────────────────────────────────

const galeriaFonte = lerSemComentarios(`${DIR}GaleriaVariacao.jsx`);
const lib = lerSemComentarios('resources/js/lib/imagensVariacao.js');
const cartao = lerSemComentarios(`${DIR}CartaoVariacao.jsx`);
const hook = lerSemComentarios(`${DIR}useFichaProduto.js`);

test('Gate: o cartão da variação monta a galeria e liga as imagens ao hook', () => {
    assert.ok(cartao.includes("import GaleriaVariacao from '@/Components/Portal/Estrutura/Produtos/GaleriaVariacao'"));
    assert.match(cartao, /<GaleriaVariacao variacao=\{variacao\} aoMudar=\{\(imagens\) => ficha\.definirImagens\(k, imagens\)\} \/>/);
});

test('Gate: as três rotas do servidor, com o id da variação, e nada de upload fora da galeria', () => {
    assert.ok(galeriaFonte.includes("route('portal.auth.estrutura.produtos.imagens.enviar', variacao.id)"));
    assert.ok(galeriaFonte.includes("route('portal.auth.estrutura.produtos.imagens.ordem', variacao.id)"));
    assert.ok(galeriaFonte.includes("route('portal.auth.estrutura.produtos.imagens.excluir', [variacao.id, imagem.id])"));
    assert.match(galeriaFonte, /axios\.post\(/);
    assert.match(galeriaFonte, /axios\.put\(/);
    assert.match(galeriaFonte, /axios\.delete\(/);
    assert.ok(galeriaFonte.includes('type="file"') && galeriaFonte.includes('multiple') && galeriaFonte.includes('accept={ACEITA_NO_INPUT}'));
    assert.ok(! cartao.includes('type="file"'), 'o input de arquivo mora só na galeria');
    assert.ok(! hook.includes('type="file"') && ! hook.includes('FormData'));
});

test('Gate: envio bloqueado sem id, texto "Enviando…" e contador N/12', () => {
    assert.ok(galeriaFonte.includes('decidirEnvio({ variacaoId: variacao.id'));
    assert.ok(galeriaFonte.includes("'Enviando…'") && galeriaFonte.includes("'Adicionar imagens'"));
    assert.ok(galeriaFonte.includes('{imagens.length}/{LIMITE_IMAGENS}'));
    assert.ok(galeriaFonte.includes('disabled={! envio.pode}'));
    assert.ok(galeriaFonte.includes('data-dica-salvar'));
});

test('Gate: capa com selo, arrastar e setas (teclado), exclusão com confirmação inline', () => {
    assert.ok(galeriaFonte.includes('data-selo-capa') && galeriaFonte.includes('>Capa<'));
    assert.ok(galeriaFonte.includes('draggable={') && galeriaFonte.includes('onDrop='));
    assert.ok(galeriaFonte.includes('data-acao="mover-para-frente"') && galeriaFonte.includes('data-acao="mover-para-tras"'));
    assert.ok(galeriaFonte.includes('data-acao="excluir-imagem"') && galeriaFonte.includes('data-acao="confirmar-excluir-imagem"'));
    assert.ok(galeriaFonte.includes('data-confirmar-exclusao'));
    // A exclusão só dispara no "Sim": o X apenas abre a confirmação.
    assert.ok(galeriaFonte.includes('onClick={() => setConfirmando(img.id)}'));
    assert.ok(galeriaFonte.includes('onClick={() => excluir(img)}'));
});

test('Gate: ordem errada volta como estava (otimista com reversão)', () => {
    const ordenar = galeriaFonte.slice(galeriaFonte.indexOf('const ordenar = async'), galeriaFonte.indexOf('const excluir = async'));
    assert.ok(ordenar.indexOf('aoMudar(nova)') < ordenar.indexOf('axios.put('), 'mostra a ordem nova antes da resposta');
    assert.ok(ordenar.includes('aoMudar(antes)'), 'se o servidor recusar, volta a anterior');
});

test('Gate: imagens são gravadas na hora e NÃO marcam a ficha como alterada', () => {
    const def = hook.slice(hook.indexOf('const definirImagens'), hook.indexOf('const alterarNome'));
    assert.match(def, /setVars\(\(atual\) => aplicarImagens\(atual, chave, imagens\)\)/);
    assert.ok(! def.includes('setAlterado'), 'imagem não é "alteração não salva"');
    assert.ok(hook.includes('definirImagens,'), 'exposto na ficha');
    assert.ok(! galeriaFonte.includes('alterar(') && ! galeriaFonte.includes('setAlterado'));
});

test('Gate: "Nova variação" não herda as imagens da 1ª, e o salvar parcial não as apaga', () => {
    const nova = hook.slice(hook.indexOf('const novaVariacao = () =>'), hook.indexOf('const removerVariacao'));
    assert.ok(nova.includes('imagens: []'));
    assert.ok(hook.includes('imagens: v.imagens ?? []'), 'o POST `linhas` não devolve imagens: as da tela seguem');
    assert.ok(hook.includes('manterImagensAtuais(rascunho.vars, varsRef.current)'));
});

test('Gate: o cartão continua tendo Volumes, calculados, excluir variação e o erro da variação', () => {
    for (const trecho of ['<CartaoVolume', '<FaixaCalculados', 'data-acao="excluir-variacao"', 'data-erro-variacao', 'Volumes']) {
        assert.ok(cartao.includes(trecho), trecho);
    }
});

test('Gate de sigilo: nada de marketplace, anúncio, publicação ou MLB nos arquivos novos da galeria', () => {
    // Lê a fonte CRUA (com comentários): o sigilo vale também para o comentário.
    for (const caminho of [`${DIR}GaleriaVariacao.jsx`, 'resources/js/lib/imagensVariacao.js']) {
        const cru = lerCru(caminho);
        assert.ok(! /mercado|an[uú]ncio|public(ar|a[cç][aã]o|ador)|\bMLB\b|\bML\b|marketplace/i.test(cru), `${caminho} cita algo que a tela não pode citar`);
    }
    assert.ok(! /mercado|an[uú]ncio|publicar|\bMLB\b/i.test(galeriaFonte + lib));
});
