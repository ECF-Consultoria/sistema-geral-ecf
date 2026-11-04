import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import * as esbuild from 'esbuild';
import { CADASTRO, CADASTRO_COM_CATEGORIA, CADASTRO_CORES_E_TAMANHOS, CAMPOS_DA_CATEGORIA, PROPS_TRABALHO, SALVO_INCOMPLETO, paraCliente } from './_trilha-exemplo.js';
import { lerSemComentarios } from './_fonte.js';
import {
    SELETORES_DO_ALVO,
    acharAlvo,
    alvoDaColuna,
    alvoDaFalta,
    destacar,
    irParaOAlvo,
    irParaOCadastro,
    nomeComparavel,
    pilulasDasFaltas,
    seguirAteOAlvo,
} from '../../resources/js/lib/trilhaFaltas.js';
import { estadoDoRetrato, gruposDaFicha, mexido } from '../../resources/js/lib/trilhaCadastro.js';
import { camposDeMedidas, gruposSemMedidas } from '../../resources/js/lib/medidasDoProduto.js';

// ═══════════════════════════════════════════════════════════════════════════
// Trilha de produtos — o retorno do 1º teste do usuário (04/11/2026), R2 e R11.
//
// R2: a coluna "Fotos" da Lista mostrava só a contagem — era o marcador do
// protótipo e nenhum endereço de foto chegava à tela. Agora a capa vem do
// servidor (já na rota da porta de quem olha) e a coluna a desenha.
//
// R11: clicar no que falta e ser guiado. Cada falta do SERVIDOR leva à gaveta
// de cadastro já no campo dela. O risco que mais custa é a falta apontar para
// um campo que a gaveta não tem (o clique "não faz nada"): por isso a gaveta de
// VERDADE é desenhada no servidor e o caminho de cada falta é procurado nela
// (um DOM pequeno, montado do HTML — a suíte não tem jsdom). E a régua do
// servidor é lida das fontes PHP: falta nova sem alvo derruba o teste.
// ═══════════════════════════════════════════════════════════════════════════

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RAIZ = path.resolve(__dirname, '../..');

global.route = (nome) => `/${nome}`;
global.window = {
    location: { href: 'http://localhost/trilha/459', pathname: '/trilha/459', search: '', hash: '' },
    history: { state: null, replaceState: () => {}, go: () => {}, back: () => {} },
    addEventListener: () => {},
    removeEventListener: () => {},
};

async function montarTodos(arquivos) {
    const stub = path.join(__dirname, `.trilha-faltas-inertia-stub-${process.pid}.mjs`);
    fs.writeFileSync(stub, `
import React from 'react';
export function Head() { return null; }
export function Link({ href, children, className, replace, preserveState, preserveScroll, only, method, as, data, ...props }) {
    return React.createElement('a', { href, className, ...props }, children);
}
export const router = { get: () => {}, post: () => {}, put: () => {}, delete: () => {}, reload: () => {}, visit: () => {}, on: () => () => {} };
export function usePage() { return { props: {} }; }
`, 'utf8');
    const modulos = {};
    try {
        for (const [nome, relativo] of Object.entries(arquivos)) {
            const resultado = await esbuild.build({
                entryPoints: [path.resolve(RAIZ, relativo)],
                bundle: true,
                format: 'esm',
                platform: 'node',
                jsx: 'automatic',
                write: false,
                logLevel: 'silent',
                loader: { '.css': 'empty' },
                alias: { '@inertiajs/react': stub, '@': path.resolve(RAIZ, 'resources/js') },
                external: ['react', 'react-dom', 'react-dom/server', 'react/jsx-runtime', 'lucide-react', 'clsx', 'tailwind-merge', 'axios'],
            });
            const saida = path.join(__dirname, `.trilha-faltas-${nome}-${process.pid}-${Date.now()}.mjs`);
            fs.writeFileSync(saida, resultado.outputFiles[0].text, 'utf8');
            try {
                modulos[nome] = await import(pathToFileURL(saida).href);
            } finally {
                fs.rmSync(saida, { force: true });
            }
        }
    } finally {
        fs.rmSync(stub, { force: true });
    }

    return modulos;
}

const M = await montarTodos({
    pagina: 'resources/js/Pages/Trilha/Empresa.jsx',
    cadastro: 'resources/js/Components/Trilha/Cadastro/PainelDoCadastro.jsx',
    pilula: 'resources/js/Components/Trilha/PilulaDaFalta.jsx',
});

const { createElement: h } = await import('react');
const { renderToStaticMarkup } = await import('react-dom/server');

const texto = (s) => s.replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#x27;/g, "'").replace(/\s+/g, ' ');
const nada = () => {};

// ─── Um DOM pequeno, montado do HTML do React ───────────────────────────────
// Só o que a lib usa: querySelector(All) com tag, [attr], [attr="v"] e :not([…]) (e listas com vírgula), closest,
// textContent, atributos, style, value; scrollIntoView e focus anotam a chamada.

const VAZIOS = new Set(['input', 'img', 'br', 'hr', 'meta', 'link', 'source', 'area', 'col', 'wbr']);
const ENTIDADES = { '&amp;': '&', '&quot;': '"', '&#x27;': "'", '&lt;': '<', '&gt;': '>', '&nbsp;': ' ' };
const decodificar = (s) => s.replace(/&(?:amp|quot|#x27|lt|gt|nbsp);/g, (e) => ENTIDADES[e]);

class No {
    constructor(tagName, atributos = {}) {
        this.tagName = tagName.toUpperCase();
        this.atributos = { ...atributos };
        this.filhos = [];
        this.parentElement = null;
        this.style = {};
        this.rolagens = [];
        this.focos = [];
        this.open = 'open' in this.atributos;
    }

    get textContent() {
        return this.filhos.map((f) => (typeof f === 'string' ? f : f.textContent)).join('');
    }

    get value() {
        return this.atributos.value ?? '';
    }

    getAttribute(nome) {
        return this.atributos[nome] ?? null;
    }

    hasAttribute(nome) {
        return nome in this.atributos;
    }

    setAttribute(nome, valor) {
        this.atributos[nome] = String(valor);
    }

    removeAttribute(nome) {
        delete this.atributos[nome];
    }

    scrollIntoView(opcoes) {
        this.rolagens.push(opcoes);
    }

    focus(opcoes) {
        this.focos.push(opcoes);
    }

    *descendentes() {
        for (const f of this.filhos) {
            if (typeof f === 'string') continue;
            yield f;
            yield* f.descendentes();
        }
    }

    querySelectorAll(seletor) {
        const casa = compilar(seletor);

        return [...this.descendentes()].filter(casa);
    }

    querySelector(seletor) {
        return this.querySelectorAll(seletor)[0] ?? null;
    }

    closest(seletor) {
        const casa = compilar(seletor);
        for (let el = this; el; el = el.parentElement) if (casa(el)) return el;

        return null;
    }
}

function compilar(seletor) {
    const compostos = seletor.split(',').map((s) => s.trim()).map((s) => {
        const m = /^([a-zA-Z][\w-]*)?((?:\[[^\]]+\]|:not\(\[[^\]]+\]\))*)$/.exec(s);
        assert.ok(m, `seletor que o DOM de mentira não sabe ler: ${s}`);
        const tag = m[1]?.toUpperCase() ?? null;
        const testes = [...m[2].matchAll(/(:not\()?\[([\w-]+)(?:="([^"]*)")?\]\)?/g)].map(([, nao, nome, valor]) => {
            const tem = (el) => (valor === undefined ? el.hasAttribute(nome) : el.getAttribute(nome) === valor);

            return nao ? (el) => !tem(el) : tem;
        });

        return (el) => (!tag || el.tagName === tag) && testes.every((t) => t(el));
    });

    return (el) => compostos.some((c) => c(el));
}

function domDe(html) {
    const raiz = new No('div', { role: 'dialog' });
    let atual = raiz;
    for (const [, fecha, nome, attrs, , solta] of html.matchAll(/<(\/?)([a-zA-Z][\w-]*)((?:\s+[^\s=>/]+(?:="[^"]*")?)*)\s*(\/?)>|([^<]+)/g)) {
        if (solta !== undefined) {
            atual.filhos.push(decodificar(solta));
            continue;
        }
        if (fecha) {
            atual = atual.parentElement ?? raiz;
            continue;
        }
        const atributos = Object.fromEntries([...(attrs ?? '').matchAll(/([^\s=]+)(?:="([^"]*)")?/g)].map(([, n, v]) => [n, decodificar(v ?? '')]));
        const el = new No(nome, atributos);
        el.parentElement = atual;
        atual.filhos.push(el);
        if (!VAZIOS.has(nome.toLowerCase())) atual = el;
    }

    return raiz;
}

// ─── A gaveta de verdade (o mesmo molde do trilha-cadastro-render) ──────────

function estadoDoHook(retrato, extra = {}) {
    const estado = retrato ? estadoDoRetrato(retrato) : null;

    return {
        retrato, estado, erroAoAbrir: null, tentarDeNovo: nada, carregando: !retrato, leitura: false, motivo: null,
        temMudanca: !!retrato && mexido(estado, retrato), salvando: false, salvar: nada, erros: { porChave: {}, soltas: [] },
        erroGeral: null, nota: null, resultado: null, continuarDoResultado: nada, mudarProduto: nada, mudarVariacao: nada,
        guardiao: {}, pedido: null, continuarEditando: nada, descartar: nada, salvarESeguir: nada,
        ...extra,
    };
}

function fichaDoHook(retrato) {
    const doProduto = gruposDaFicha(CAMPOS_DA_CATEGORIA.grupos, retrato);

    return {
        definicao: { categoria: 'MLB1902', grupos: CAMPOS_DA_CATEGORIA.grupos }, doProduto, grupos: gruposSemMedidas(doProduto),
        medidas: camposDeMedidas(doProduto), carregando: false, indisponivel: false, tentarDeNovo: nada, sugeridos: new Set(),
        esquecerSugestao: nada, ia: { rodando: false, resultado: null }, podeIa: false, preencherComIa: nada,
    };
}

function desenharGaveta(retrato, extra = {}, { onIrParaFalta = null } = {}) {
    return renderToStaticMarkup(h(M.cadastro.VistaDoCadastro, {
        produto: PROPS_TRABALHO.produtos.find((p) => p.id === (retrato?.produto?.id ?? 9)) ?? { id: retrato.produto.id, nome: retrato.produto.nome },
        dados: PROPS_TRABALHO,
        links: PROPS_TRABALHO.links,
        trabalho: true,
        c: estadoDoHook(retrato, extra),
        onAbrir: nada,
        onIrParaFalta,
    }));
}

/** Um relógio de mentira: `agendar` anota, `andar()` roda o próximo. */
function relogio() {
    const fila = [];

    return {
        agendar: (f, ms) => (fila.push({ f, ms }), fila.length),
        desagendar: (id) => {
            if (fila[id - 1]) fila[id - 1].f = null;
        },
        andar() {
            const proximo = fila.find((x) => x.f);
            if (!proximo) return false;
            const { f } = proximo;
            proximo.f = null;
            f();

            return true;
        },
        pendentes: () => fila.filter((x) => x.f).map((x) => x.ms),
    };
}

/** As faltas que o servidor escreve, lidas das fontes PHP (a mesma leitura do teste do "Pronto quando"). */
function faltasDoServidor() {
    const pendencias = fs.readFileSync(path.resolve(RAIZ, 'app/Services/Portal/Estrutura/Produtos/PendenciasDoProduto.php'), 'utf8');
    const bloco = pendencias.match(/const ROTULOS = \[([\s\S]*?)\];/)[1];
    const rotulos = [...bloco.matchAll(/=>\s*'([^']+)'/g)].map((m) => m[1]);
    const completude = fs.readFileSync(path.resolve(RAIZ, 'app/Services/Trilha/CompletudeDoProduto.php'), 'utf8');
    const diretas = [...completude.matchAll(/\$faltas\[\] = '([^']*)'/g)].map((m) => m[1]);
    assert.ok(rotulos.length >= 8 && diretas.length >= 3, 'a leitura das fontes achou as faltas');

    // As que levam número ou nome: um exemplo de cada (o texto começa como o servidor o monta).
    return [...rotulos, ...diretas.map((d) => (d === 'fotos (' ? 'fotos (1 de 3)' : d === 'ficha: ' ? 'ficha: material principal' : d))];
}

// ═══ A régua: falta → alvo ══════════════════════════════════════════════════

test('toda falta que o servidor escreve tem um alvo na gaveta (lido das fontes PHP: falta nova sem alvo derruba o teste)', () => {
    for (const falta of faltasDoServidor()) {
        const alvo = alvoDaFalta(falta);
        assert.ok(alvo && SELETORES_DO_ALVO[alvo.campo], `sem alvo: ${falta}`);
    }
    assert.deepEqual(alvoDaFalta('custo'), { campo: 'custo' });
    assert.deepEqual(alvoDaFalta('título'), { campo: 'titulo' });
    assert.deepEqual(alvoDaFalta('ambiente'), { campo: 'ambientes' });
    // "medidas" e "peso" são os da EMBALAGEM (learnings §13), não as do produto montado.
    assert.deepEqual([alvoDaFalta('medidas'), alvoDaFalta('peso')], [{ campo: 'volumes' }, { campo: 'volumes' }]);
    assert.deepEqual(alvoDaFalta('fotos (2 de 3)'), { campo: 'fotos' });
    assert.deepEqual(alvoDaFalta('ficha: Material do Estofado '), { campo: 'ficha', nome: 'material do estofado' });
    assert.equal(alvoDaFalta('algo que ninguém conhece'), null);
    assert.equal(alvoDaFalta(''), null);
    assert.equal(nomeComparavel('Material principal *'), 'material principal');
});

test('as colunas da planilha e a coluna "Situação": cada "falta" com o seu alvo; as da ficha numa pílula só', () => {
    const sofa = PROPS_TRABALHO.produtos.find((p) => p.id === 7);
    assert.deepEqual(alvoDaColuna('custo', sofa), { campo: 'custo' });
    assert.deepEqual(alvoDaColuna('embalagem', sofa), { campo: 'volumes' });
    assert.deepEqual(alvoDaColuna('fotos', sofa), { campo: 'fotos' });
    assert.deepEqual(alvoDaColuna('ficha', sofa), { campo: 'ficha', nome: 'material do estofado' }, 'o 1º campo da ficha que falta');
    assert.deepEqual(alvoDaColuna('ficha', { faltas: [] }), { campo: 'ficha' });

    const pilulas = pilulasDasFaltas(['custo', 'ficha: marca', 'descrição', 'ficha: material', 'ficha: cor']);
    assert.deepEqual(pilulas.map((p) => p.texto), ['custo', 'ficha (3 campos)', 'descrição']);
    assert.deepEqual(pilulas[1].alvo, { campo: 'ficha', nome: 'marca' });
    assert.deepEqual(pilulasDasFaltas(['ficha: marca']).map((p) => p.texto), ['ficha: marca']);
    assert.deepEqual(pilulasDasFaltas(null), []);
});

test('quem vai ao cadastro pela falta: só quem escreve e com o produto que não andou; abre `e:{id}` com o alvo', () => {
    const chamadas = [];
    const onAbrir = (...args) => chamadas.push(args);
    const origem = { tag: 'botão' };

    irParaOCadastro(onAbrir, { id: 9 }, true)({ campo: 'custo' }, origem);
    assert.deepEqual(chamadas, [['e:9', origem, { alvo: { campo: 'custo' } }]]);
    assert.equal(irParaOCadastro(onAbrir, { id: 9 }, false), null, 'quem não escreve (cliente, "Ver como")');
    assert.equal(irParaOCadastro(onAbrir, { id: 9, cadastro_travado: true }, true), null, 'o produto que andou só se lê');
});

// ═══ Na gaveta de verdade ═══════════════════════════════════════════════════

test('cada falta do criado-mudo acha o campo dela na gaveta desenhada — o controle certo recebe o foco', () => {
    const dom = domDe(desenharGaveta(CADASTRO));
    const esperado = {
        medidas: ['volumes', 'INPUT'],
        custo: ['custo', 'INPUT'],
        categoria: ['categoria', null],
        'título': ['titulo', 'INPUT'],
        'família': ['familia', 'INPUT'],
        ambiente: ['ambientes', 'INPUT'],
        'descrição': ['descricao', 'TEXTAREA'],
        'fotos (0 de 3)': ['fotos', 'BUTTON'],
    };
    assert.deepEqual(Object.keys(esperado), CADASTRO.situacao.faltas, 'as faltas do retrato do exemplo');
    for (const [falta, [campo, foco]] of Object.entries(esperado)) {
        const achado = acharAlvo(dom, alvoDaFalta(falta));
        assert.ok(achado?.exato, `não achou: ${falta}`);
        if (campo === 'fotos') assert.ok(achado.elemento.hasAttribute('data-galeria'), falta);
        else assert.equal(achado.elemento.getAttribute('data-campo'), campo, falta);
        if (foco) assert.equal(achado.foco?.tagName, foco, `${falta}: o foco vai para ${foco}`);
    }
    // As fotos: o foco no "enviar" da galeria.
    assert.equal(acharAlvo(dom, { campo: 'fotos' }).foco.getAttribute('data-acao'), 'enviar-fotos');
});

test('o campo da ficha pelo NOME (o rótulo, sem o "*"); sem a ficha carregada, só a caixa (aproximado)', () => {
    const comFicha = domDe(desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: fichaDoHook(CADASTRO_COM_CATEGORIA) }));
    const alvo = alvoDaFalta(CADASTRO_COM_CATEGORIA.situacao.faltas[0]);
    const achado = acharAlvo(comFicha, alvo);
    assert.ok(achado?.exato);
    assert.equal(achado.elemento.getAttribute('data-obrigatorio'), 'sim');
    assert.equal(nomeComparavel(achado.elemento.querySelector('label').textContent), 'material principal');
    assert.equal(achado.foco?.tagName, 'SELECT', 'a lista do Material');

    const carregando = domDe(desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: { ...fichaDoHook(CADASTRO_COM_CATEGORIA), carregando: true } }));
    const aproximado = acharAlvo(carregando, alvo);
    assert.equal(aproximado?.exato, false);
    assert.equal(aproximado.elemento.getAttribute('data-caixa'), 'ficha');
});

test('cor e tamanho: "fotos (0 de 3)" leva à galeria da cor que ainda não tem o mínimo, não à primeira', () => {
    const dom = domDe(desenharGaveta(CADASTRO_CORES_E_TAMANHOS));
    const galerias = dom.querySelectorAll('[data-galeria]');
    assert.equal(galerias.length, 2, 'uma galeria por cor');
    const achado = acharAlvo(dom, alvoDaFalta('fotos (0 de 3)'));
    assert.equal(achado.elemento, galerias[1], 'a Preta (0 de 3), não a Branca (3 fotos)');
});

test('ir até a falta: rola até o meio, foca sem rolar de novo, destaca por um instante e desfaz o destaque', () => {
    const dom = domDe(desenharGaveta(CADASTRO));
    const r = relogio();
    assert.equal(irParaOAlvo(dom, { campo: 'custo' }, { agendar: r.agendar }), 'exato');
    const custo = dom.querySelector('[data-campo="custo"]');
    assert.equal(custo.rolagens.length, 1);
    assert.equal(custo.rolagens[0].block, 'center');
    assert.deepEqual(custo.querySelector('input').focos, [{ preventScroll: true }]);
    assert.ok(custo.hasAttribute('data-destaque-da-falta'));
    assert.equal(custo.style.outline, '2px solid rgb(var(--t-warn))');
    assert.deepEqual(r.pendentes(), [2400]);
    r.andar();
    assert.ok(!custo.hasAttribute('data-destaque-da-falta'), 'o destaque sai sozinho');
    assert.equal(custo.style.outline, undefined);

    assert.equal(irParaOAlvo(dom, { campo: 'nada' }), null);
    assert.equal(irParaOAlvo(null, { campo: 'custo' }), null);
    // O <details> fechado que esconde o campo abre.
    const comDetalhes = domDe('<details><summary>Mais</summary><div data-campo="titulo"><input value=""/></div></details>');
    irParaOAlvo(comDetalhes, { campo: 'titulo' }, { agendar: nada });
    assert.equal(comDetalhes.querySelector('details').open, true);
    // `destacar` sem elemento não quebra.
    assert.equal(typeof destacar(null), 'function');
});

test('a gaveta abre carregando e a ficha chega depois: espera o campo exato; esgotado o tempo, vale a caixa; fechar cancela', () => {
    const alvo = alvoDaFalta('ficha: material principal');
    const pronta = domDe(desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: fichaDoHook(CADASTRO_COM_CATEGORIA) }));
    const carregando = domDe(desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: { ...fichaDoHook(CADASTRO_COM_CATEGORIA), carregando: true } }));

    // 1ª tentativa: sem gaveta; 2ª: só a caixa; 3ª: a ficha chegou.
    const r = relogio();
    let raiz = null;
    seguirAteOAlvo(() => raiz, alvo, { tentativas: 5, intervalo: 150, agendar: r.agendar, desagendar: r.desagendar });
    assert.deepEqual(r.pendentes(), [150]);
    raiz = carregando;
    r.andar();
    assert.equal(carregando.querySelector('[data-caixa="ficha"]').rolagens.length, 0, 'a caixa sozinha ainda não basta');
    raiz = pronta;
    r.andar();
    const campo = acharAlvo(pronta, alvo).elemento;
    assert.equal(campo.rolagens.length, 1, 'chegou ao campo');

    // Sem o campo até o fim: vale a caixa.
    const r2 = relogio();
    const caixa = domDe(desenharGaveta(CADASTRO_COM_CATEGORIA, { ficha: { ...fichaDoHook(CADASTRO_COM_CATEGORIA), carregando: true } }));
    seguirAteOAlvo(() => caixa, alvo, { tentativas: 2, agendar: r2.agendar, desagendar: r2.desagendar });
    while (r2.andar());
    assert.equal(caixa.querySelector('[data-caixa="ficha"]').rolagens.length, 1);

    // A gaveta fechou antes: nada rola.
    const r3 = relogio();
    const cancelar = seguirAteOAlvo(() => null, alvo, { agendar: r3.agendar, desagendar: r3.desagendar });
    cancelar();
    assert.equal(r3.andar(), false);
});

test('na gaveta: com `onIrParaFalta` as faltas viram botões (no formulário e no resultado do "Salvar"); sem, o texto de sempre', () => {
    const comBotoes = desenharGaveta(CADASTRO, {}, { onIrParaFalta: nada });
    const faltas = comBotoes.match(/<ul[^>]*data-faltas=""[\s\S]*?<\/ul>/)[0];
    assert.equal((faltas.match(/<button type="button"/g) ?? []).length, CADASTRO.situacao.faltas.length);
    assert.match(faltas, /data-alvo-da-falta="custo"[^>]*aria-label="Completar no cadastro: custo"/);
    // Dentro do formulário: `type="button"`, nunca o envio do "Salvar".
    assert.ok(!/<button(?![^>]*type="button")[^>]*data-falta=/.test(comBotoes));

    const semBotoes = desenharGaveta(CADASTRO);
    assert.ok(!/<button[^>]*data-falta=/.test(semBotoes), 'quem não passa o `onIr` vê as pílulas de sempre');

    const resultado = desenharGaveta(SALVO_INCOMPLETO, {
        resultado: { tipo: 'incompleto', texto: 'Salvo. Ainda faltam: categoria, fotos (0 de 3).', faltas: ['categoria', 'fotos (0 de 3)'], extra: null },
    }, { onIrParaFalta: nada });
    assert.match(resultado, /data-caixa="faltas"/);
    assert.equal((resultado.match(/<button type="button"[^>]*data-falta=/g) ?? []).length, 2);
});

test('a pílula-botão: o clique não sobe para a linha e leva o alvo e o próprio botão; sem alvo conhecido, texto', () => {
    const chamadas = [];
    const botao = M.pilula.default({ falta: 'fotos (1 de 3)', onIr: (alvo, origem) => chamadas.push([alvo, origem]) });
    let parou = false;
    const origem = { botao: true };
    botao.props.onClick({ stopPropagation: () => { parou = true; }, currentTarget: origem });
    assert.ok(parou, 'stopPropagation: a linha da planilha não abre por cima');
    assert.deepEqual(chamadas, [[{ campo: 'fotos' }, origem]]);

    const desconhecida = renderToStaticMarkup(h(M.pilula.default, { falta: 'algo novo', onIr: nada }));
    assert.ok(!desconhecida.includes('<button'), 'sem alvo, não promete o que não faz');
    const doCliente = renderToStaticMarkup(h(M.pilula.default, { falta: 'custo' }));
    assert.ok(!doCliente.includes('<button') && doCliente.includes('>custo<'));
});

// ═══ A Lista e o painel do produto ══════════════════════════════════════════

/** A página com as props e a busca da URL (o estado da tela vem dela). */
function desenhar(props, busca = '', verComo = 'equipe') {
    window.location.search = busca;
    try {
        return renderToStaticMarkup(h(M.pagina.TelaDaTrilha, { ...props, verComo, onVerComo: nada }));
    } finally {
        window.location.search = '';
    }
}

const linhaDaLista = (html, id) => html.match(new RegExp(`<tr data-produto="${id}"[\\s\\S]*?</tr>`))?.[0] ?? '';

/** As props com a capa (R2) que o servidor manda: o sofá com foto; o criado-mudo sem nenhuma. */
const COM_CAPA = {
    ...PROPS_TRABALHO,
    produtos: PROPS_TRABALHO.produtos.map((p) => (p.id === 7 ? { ...p, capa: '/trilha/459/fotos/71/7001' } : { ...p, capa: null })),
};

test('R2 — a coluna "Fotos" desenha a miniatura da capa (com a contagem no canto); sem capa, o quadrado com a contagem', () => {
    const saida = desenhar(COM_CAPA, '?etapa=lista');
    const sofa = linhaDaLista(saida, 7);
    assert.match(sofa, /<img src="\/trilha\/459\/fotos\/71\/7001" alt="" loading="lazy" decoding="async"/);
    assert.match(sofa, /data-capa-da-lista=""[\s\S]*aria-label="2 fotos"[^>]*>2</);
    const criado = linhaDaLista(saida, 9);
    assert.ok(!criado.includes('<img'), 'sem capa, sem imagem');
    assert.match(criado, /aria-label="0 fotos"[^>]*data-sem-capa=""[^>]*>0</);

    // O cliente: a capa que o servidor mandou (a rota da porta dele) aparece igual.
    const doCliente = desenhar({ ...paraCliente(), produtos: paraCliente().produtos.map((p) => (p.id === 7 ? { ...p, capa: '/portal/459/trilha/fotos/71/7001' } : p)) }, '?etapa=lista');
    assert.match(linhaDaLista(doCliente, 7), /<img src="\/portal\/459\/trilha\/fotos\/71\/7001"/);
});

test('R11 — na Lista, quem escreve: cada "falta" das colunas e cada falta da "Situação" é um botão com o alvo dela', () => {
    const saida = desenhar(COM_CAPA, '?etapa=lista');
    const criado = linhaDaLista(saida, 9);
    const alvos = [...criado.matchAll(/data-alvo-da-falta="([^"]+)"/g)].map((m) => m[1]);
    // As colunas (custo, embalagem, descrição, fotos) e a "Situação" (as 8 faltas do servidor).
    assert.deepEqual(alvos, ['custo', 'volumes', 'descricao', 'fotos', 'volumes', 'custo', 'categoria', 'titulo', 'familia', 'ambientes', 'descricao', 'fotos']);
    assert.match(criado, /aria-label="Completar no cadastro: custo"/);
    // O sofá: a ficha numa pílula, levando ao campo que falta.
    const sofa = linhaDaLista(saida, 7);
    assert.match(sofa, /data-falta="ficha: material do estofado"[^>]*data-alvo-da-falta="ficha"/);
    assert.match(sofa, /aria-label="Completar no cadastro: ficha técnica"/, 'o "falta" da coluna da ficha diz para onde vai');
    // O "8 faltam" e a barrinha continuam.
    assert.ok(texto(criado).includes('8 faltam'));
});

test('R11 — o cliente, o "Ver como: Cliente" e o produto que andou: a falta é TEXTO, nenhum botão', () => {
    const doCliente = desenhar(paraCliente(), '?etapa=lista');
    const simulado = desenhar(COM_CAPA, '?etapa=lista', 'cliente');
    for (const [nome, html] of [['cliente', doCliente], ['Ver como: Cliente', simulado]]) {
        assert.ok(!html.includes('data-alvo-da-falta'), nome);
        assert.match(linhaDaLista(html, 9), /data-falta="custo"/, `${nome}: a falta continua à vista, como texto`);
    }
    const comTravado = {
        ...COM_CAPA,
        produtos: COM_CAPA.produtos.map((p) => (p.id === 9 ? { ...p, cadastro_travado: true } : p)),
    };
    assert.ok(!linhaDaLista(desenhar(comTravado, '?etapa=lista'), 9).includes('data-alvo-da-falta'), 'o travado só se lê');
});

test('R11 — o painel do produto (`p:`): as faltas viram botões para quem escreve; o cliente lê', () => {
    const equipe = desenhar(COM_CAPA, '?abrir=p%3A9&etapa=lista');
    const caixa = equipe.match(/data-caixa="lista"[\s\S]*?<\/ul>/)?.[0] ?? '';
    assert.equal((caixa.match(/data-alvo-da-falta=/g) ?? []).length, 8);
    const cliente = desenhar(paraCliente(), '?abrir=p%3A9&etapa=lista');
    assert.ok(!cliente.includes('data-alvo-da-falta'));
});

test('R11 — a página leva o alvo à gaveta fora da URL; a gaveta vai ao campo uma vez por clique e não rouba o foco do nome sem alvo', () => {
    const pagina = lerSemComentarios('resources/js/Pages/Trilha/Empresa.jsx');
    // O `onAbrir` ganhou o 3º argumento, e o alvo vale só para a chave aberta.
    assert.match(pagina, /const abrirItem = useCallback\(\(chave, elemento = null, opcoes = null\) =>/);
    assert.match(pagina, /setAlvoDoCadastro\(opcoes\?\.alvo \? \{ \.\.\.opcoes\.alvo, chave, vez: Date\.now\(\) \} : null\)/);
    assert.match(pagina, /alvo=\{alvoDoCadastro\?\.chave === aberto \? alvoDoCadastro : null\}/);
    // A URL não muda: a busca continua sendo só etapa, vista e abrir.
    assert.ok(!/alvo/.test(lerSemComentarios('resources/js/Components/Trilha/estadoDaTela.js')));

    const gaveta = lerSemComentarios('resources/js/Components/Trilha/Cadastro/PainelDoCadastro.jsx');
    assert.match(gaveta, /if \(alvo\) return;/, 'aberta pela falta, o foco não vai para o nome');
    assert.match(gaveta, /irFeito\.current === irPara\.vez/, 'uma vez por clique: o retrato novo do "Salvar" não rola de novo');
    assert.match(gaveta, /seguirAteOAlvo\(\(\) => campoDoNome\.current\?\.closest\?\.\('\[role="dialog"\]'\)/);
});
