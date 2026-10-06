import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { campoEditaveis, linhaDoServidor, refSugerida } from '@/lib/produtosEstrutura';
import { gravarVariacoes, mensagemDeFalha } from '@/lib/produtosGravacao';
import { apagarRascunho, gravarRascunho, lerRascunho } from '@/lib/produtosNavegacao';

// ─── Regra da ficha do produto (167-16/18, agora da ficha em PÁGINA — D-27) ──
//
// Era do painel lateral antigo (aposentado no 167-19). Aqui mora só o que
// coleta e envia: rascunho das variações, escolha de família/ambiente/categoria
// (vale para o produto inteiro), volumes digitados, "Nova variação" que copia a
// 1ª (D-04) e o "Salvar produto", que grava pelo POST `linhas` que já existe.
// Nada de conta de logística, cubagem ou frete aqui: o servidor calcula e a
// ficha só exibe (D-15/D-19/D-28).

export const MEDIDAS = [
    { chave: 'c', rotulo: 'Comprimento (cm)' },
    { chave: 'l', rotulo: 'Largura (cm)' },
    { chave: 'a', rotulo: 'Altura (cm)' },
    { chave: 'kg', rotulo: 'Peso (kg)' },
];

/** Campos que valem para o produto inteiro: a escolha vai para todas as variações do formulário. */
export const CAMPOS_DO_PRODUTO = ['familia', 'ambientes_texto', 'categoria', 'categoria_ml_id', 'categoria_ml_nome', 'categoria_ml_caminho', '_categoriaEscolhida'];

let contador = 0;
const novaChave = () => `m${++contador}`;

const linhaEmBranco = (produto) => ({
    _k: novaChave(), codigo: '', nome: produto?.nome ?? '', eixo_rotulo: '', valor: '', familia: '', ambientes_texto: '',
    categoria: '', volumes_texto: '', custo: '', volumes: [],
    ...(produto?.id ? { produto_id: produto.id } : {}),
});

const texto = (n) => (n == null ? '' : String(n).replace('.', ','));

/** Caixas editáveis (textos) a partir do que a variação tem hoje. */
const caixasDe = (row) => {
    const origem = Array.isArray(row.volumes_digitados) ? row.volumes_digitados : (Array.isArray(row.volumes) ? row.volumes : []);

    return origem.map((v) => ({ c: texto(v.c), l: texto(v.l), a: texto(v.a), kg: texto(v.kg) }));
};

const caixaVazia = (c) => MEDIDAS.every((m) => String(c[m.chave] ?? '').trim() === '');

const aparar = (c) => ({ c: c.c.trim(), l: c.l.trim(), a: c.a.trim(), kg: c.kg.trim() });

/** Para comparar escolhas: vazio, null, undefined e false contam igual; ambientes, sem ordem. */
const comoTexto = (x, campo = null) => {
    const t = x === null || x === undefined || x === false ? '' : String(x);
    if (campo !== 'ambientes_texto') return t;

    return t.split(',').map((s) => s.trim()).filter(Boolean).sort().join(', ');
};

/** O produto das variações (o id que o servidor deu), ou null enquanto ele é novo. */
const idDoProduto = (lista, produto) => lista.find((v) => v.produto_id)?.produto_id ?? produto?.id ?? null;

/** O que a pessoa vê e pode mudar, para comparar o rascunho com o que a ficha abriu. */
const conteudo = (lista) => JSON.stringify((lista ?? []).map((v) => ({ id: v.id ?? null, ...campoEditaveis(v), volumes: v.volumes_digitados ?? null })));

export default function useFichaProduto({ linhas = [], produto = null, vocabulario, limites }) {
    // Rascunho guardado no navegador (FE-CR-02): oferecido só quando difere do que a ficha abriu.
    const [inicio] = useState(() => {
        const iniciais = linhas.length
            ? linhas.map((l) => linhaDoServidor(l, vocabulario?.pendencias))
            : [linhaEmBranco(produto)];
        const guardado = lerRascunho(produto?.id ?? null);
        const oferecer = guardado && conteudo(guardado.vars) !== conteudo(iniciais) ? guardado : null;
        if (guardado && ! oferecer) apagarRascunho(produto?.id ?? null);

        return { iniciais, oferecer };
    });
    const [vars, setVars] = useState(inicio.iniciais);
    const [erros, setErros] = useState({});          // { _k: mensagem }
    const [aviso, setAviso] = useState(null);
    const [salvando, setSalvando] = useState(false);
    const [alterado, setAlterado] = useState(false);   // há algo digitado ainda não salvo
    const [rascunho, setRascunho] = useState(inicio.oferecer);   // { em, vars } à espera de Recuperar/Descartar
    const varsRef = useRef(vars);
    varsRef.current = vars;

    // A cada alteração o rascunho é regravado. Produto novo que ganhou id (gravação parcial) muda de
    // chave: o rascunho "novo" sai e passa a valer o do produto.
    const chaveGravada = useRef(undefined);
    useEffect(() => {
        if (! alterado) return;
        const id = idDoProduto(vars, produto);
        if (chaveGravada.current !== undefined && chaveGravada.current !== id) apagarRascunho(chaveGravada.current);
        gravarRascunho(id, vars);
        chaveGravada.current = id;
    }, [vars, alterado]); // eslint-disable-line react-hooks/exhaustive-deps

    /** Volta o que estava no rascunho. Variação nunca gravada ganha chave nova (o contador recomeça ao recarregar). */
    const recuperarRascunho = () => {
        if (! rascunho) return;
        setVars(rascunho.vars.map((v) => ({ ...v, _k: v.id ? `v${v.id}` : novaChave() })));
        setErros({});
        setAlterado(true);
        setRascunho(null);
    };

    const descartarRascunho = () => {
        apagarRascunho(produto?.id ?? null);
        setRascunho(null);
        // O que já foi digitado nesta visita continua protegido.
        if (alterado) gravarRascunho(idDoProduto(varsRef.current, produto), varsRef.current);
    };

    /** Sair confirmado, produto excluído ou salvo: o rascunho deixa de valer. */
    const esquecerRascunho = () => apagarRascunho(idDoProduto(varsRef.current, produto));

    const primeira = vars[0];
    const novoProduto = ! primeira.produto_id;
    const eixos = Object.values(vocabulario?.eixos ?? {});

    const alterar = (chave, campo, valor) => { setAlterado(true); setVars((atual) => atual.map((v) => (v._k === chave ? { ...v, [campo]: valor } : v))); };

    /** Nome vale para todas as variações; os demais campos do produto vêm do picker. */
    const alterarNome = (valor) => { setAlterado(true); setVars((atual) => atual.map((v) => ({ ...v, nome: valor }))); };

    /**
     * Escolha num picker (vale para o produto inteiro). Fechar o picker sem mudar nada, ou escolher a
     * mesma família, não marca a ficha como alterada (revisão FE-IN-02).
     */
    const aplicarEscolha = (patch) => {
        const parte = Object.fromEntries(Object.entries(patch).filter(([c]) => CAMPOS_DO_PRODUTO.includes(c)));
        const muda = varsRef.current.some((v) => Object.entries(parte).some(([c, valor]) => comoTexto(v[c], c) !== comoTexto(valor, c)));
        if (! muda) return;
        setAlterado(true);
        setVars((atual) => atual.map((v) => ({ ...v, ...parte })));
    };

    // ─── Volumes (cartões) ──────────────────────────────────────────────────

    /** Caixas em edição (inclui as em branco); sempre ao menos uma. */
    const caixasEdit = (v) => {
        const atuais = v._caixas ?? caixasDe(v);

        return atuais.length ? atuais : [{ c: '', l: '', a: '', kg: '' }];
    };

    const gravarCaixas = (chave, caixas) => { setAlterado(true); setVars((atual) => atual.map((v) => {
        if (v._k !== chave) return v;
        const preenchidas = caixas.filter((c) => ! caixaVazia(c)).map(aparar);

        return {
            ...v,
            _caixas: caixas,
            volumes_digitados: preenchidas,
            volumes_texto: preenchidas.map((c) => `${c.c}×${c.l}×${c.a} · ${c.kg}`).join(' | '),
        };
    })); };

    const mudarCaixa = (v, indice, campo, valor) => {
        gravarCaixas(v._k, caixasEdit(v).map((c, i) => (i === indice ? { ...c, [campo]: valor } : c)));
    };
    const adicionarVolume = (v) => gravarCaixas(v._k, [...caixasEdit(v), { c: '', l: '', a: '', kg: '' }]);
    const removerCaixa = (v, indice) => gravarCaixas(v._k, caixasEdit(v).filter((_, i) => i !== indice));

    // ─── Nova variação (D-04): nasce com tudo da 1ª, só o Valor fica vazio ──

    /** Devolve a chave da variação criada (a página foca o Valor dela). */
    const novaVariacao = () => {
        const base = vars[0];
        const nova = {
            ...base,
            _k: novaChave(),
            id: undefined, oferta: null, frete: null, pendencias: [], falta: '', peso_cubado: null, peso_faturado: null,
            cubado_cobrado: false, logistica: null, oferta_id: undefined,
            codigo: refSugerida(vars),
            valor: '',
            volumes_digitados: caixasEdit(base).filter((c) => ! caixaVazia(c)).map(aparar),
            _caixas: undefined,
        };
        // Com produto já gravado, família, ambientes e categoria são do produto e não voltam no POST
        // (o retrato os marca como "não mexidos"); eixo, custo e volumes vão sempre explícitos, com o
        // que a tela mostra — `linhaParaServidor` cuida disso (FE-CR-04).
        if (nova.produto_id) nova._base = { ...campoEditaveis(nova), codigo: '', valor: '' };
        else delete nova._base;
        setAlterado(true);
        setVars((atual) => [...atual, nova]);

        return nova._k;
    };

    /** Tira a variação do rascunho (depois da exclusão D-22 ou quando ela nem foi gravada). */
    const removerVariacao = (chave) => {
        setVars((atual) => {
            const resto = atual.filter((v) => v._k !== chave);

            return resto.length ? resto : [linhaEmBranco(produto)];
        });
        setErros((atual) => { const { [chave]: _descartada, ...outras } = atual; return outras; });
    };

    // ─── Salvar: o POST linhas, em lotes do limite do servidor ──────────────

    /** Devolve { ok, data }; quem chama decide o que fazer (a página volta para a lista). */
    const salvar = async () => {
        if (salvando) return { ok: false, data: null };
        setAviso(null);
        const faltando = {};
        vars.forEach((v) => {
            if (String(v.codigo).trim() === '' || String(v.nome).trim() === '') {
                faltando[v._k] = 'Não salvamos esta variação: informe a Ref e o nome do produto.';
            }
        });
        if (Object.keys(faltando).length) { setErros(faltando); return { ok: false, data: null }; }

        // Produto novo: a 1ª variação grava sozinha e SEM grupo; as demais vão com o produto_id que
        // voltou para ela (FE-CR-01 — a sequência mora em produtosGravacao, testada de verdade).
        setSalvando(true);
        setErros({});
        const r = await gravarVariacoes(vars, {
            enviar: async (linhas) => (await axios.post(route('portal.auth.estrutura.produtos.linhas'), { linhas })).data,
            tamanho: limites?.colar ?? 200,
        });
        const juntas = r.juntas;

        // O que o servidor já gravou volta com os ids (e o produto_id): a próxima tentativa vai por eles,
        // em vez de recriar e esbarrar em "o código já existe em outro produto" (FE-WR-01).
        const aplicarGravadas = (comErro) => {
            const porChave = new Map(juntas.linhas.filter((l) => l.chave).map((l) => [l.chave, l]));
            if (porChave.size === 0) return;
            setVars((atual) => atual.map((v) => {
                const servidor = porChave.get(v._k);

                return servidor && ! comErro[v._k] ? { ...linhaDoServidor(servidor, vocabulario?.pendencias), _k: v._k } : v;
            }));
        };

        if (r.falha) {
            aplicarGravadas({});
            setAviso(mensagemDeFalha(r.falha));
            setSalvando(false);

            return { ok: false, data: juntas };
        }

        const comErro = {};
        juntas.erros.forEach((e) => {
            if (e.chave) comErro[e.chave] = `Não salvamos esta variação: ${String(e.mensagem).replace(/\.$/, '')}.`;
        });
        // A 1ª variação de um produto novo não gravou e o servidor não disse por quê: a sequência parou nela.
        if (r.parou && ! comErro[vars[0]._k]) comErro[vars[0]._k] = 'Não salvamos esta variação. Tente de novo.';
        const ok = ! r.parou && juntas.erros.length === 0;

        if (ok) {
            setAlterado(false);
            // Salvo por inteiro: o rascunho (do produto e, se ele nasceu agora, o "novo") deixa de valer.
            apagarRascunho(r.produtoId);
            if (! produto?.id) apagarRascunho(null);
            setRascunho(null);
        } else {
            // Parcial: o que gravou volta com os ids do servidor; o que falhou fica como está, com o motivo.
            aplicarGravadas(comErro);
            setErros(comErro);
        }
        setSalvando(false);

        return { ok, data: juntas };
    };

    return {
        vars, primeira, novoProduto, eixos, erros, aviso, salvando, alterado,
        alterarNome, alterar, aplicarEscolha, caixasEdit, mudarCaixa, adicionarVolume, removerCaixa,
        novaVariacao, removerVariacao, salvar,
        rascunho, recuperarRascunho, descartarRascunho, esquecerRascunho,
    };
}
