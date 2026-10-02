import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { SECOES, criarRota, estadoDasSecoes, mensagemDe, secaoDoProblema } from './apoio.js';
import {
    contarProntas, estadoDaConferencia, mesclarAlvos, mesclarVariantes, podeConferir as calcularPodeConferir,
    podePublicar as calcularPodePublicar, resumoDoLancamento, textoDaConferencia, totalDeAnuncios,
} from './derivados.js';

// ─── Lógica do editor do Publicador (D24) ───────────────────────────────────
//
// Portada do piloto do Portal (`EditorPublicador.jsx`): quem decide tudo é o
// servidor — toda resposta traz o estado inteiro do rascunho.
// - Digitação (atributos, títulos, preços, estoque) fica numa cópia local e vai
//   ao servidor com espera; a resposta atualiza o resto sem pisar no digitado.
// - Ações de estrutura (categoria, variações, fotos) trocam o estado inteiro.
// - Conferir e publicar vão para a fila: o hook acompanha até terminar.
// A casca (160-13) só compõe; o contrato `m` abaixo é o que os cards da mesa leem.

const ESPERA_SALVAR = 900;
const INTERVALO_ANDAMENTO = 2500;
const LIMITE_ANDAMENTO = 4 * 60 * 1000;

const rota = criarRota('mlb.anuncios.publicador', 'produto');

const doEstado = (e) => ({
    atributos: e.atributos ?? {},
    alvos: (e.alvos ?? []).map(({ listing_type_id, titulo, ativo }) => ({ listing_type_id, titulo, ativo })),
    condicao: e.rascunho.condicao,
    descricao: e.rascunho.descricao,
    envio: e.rascunho.envio ?? { modo: 'me2', frete_gratis: false, retirada: false },
    garantia: e.rascunho.garantia,
});

const variantesDoEstado = (e) => Object.fromEntries((e.variantes ?? []).map((v) => [v.chave, {
    ativa: v.ativa, estoque: v.estoque, estoque_depositos: v.estoque_depositos, precos: v.precos ?? {}, atributos: v.atributos ?? {},
}]));

/**
 * @param {{ produtoId: number, onPublicou?: Function }} opcoes
 */
export default function usePublicador({ produtoId, onPublicou }) {
    const [estado, setEstado] = useState(null);
    const [rasc, setRasc] = useState(null);
    const [vars, setVars] = useState({});
    const [carregando, setCarregando] = useState(true);
    const [erroCarga, setErroCarga] = useState(null);
    const [erro, setErro] = useState(null);
    const [aviso, setAviso] = useState(null);
    const [salvando, setSalvando] = useState(0);
    const [salvoEm, setSalvoEm] = useState(null);
    const [enviandoFoto, setEnviandoFoto] = useState(null);
    const [aguardando, setAguardando] = useState(null);
    const [ciente, setCiente] = useState(false);
    const [simulacao, setSimulacao] = useState(null);
    const [simulando, setSimulando] = useState(false);
    const [recarga, setRecarga] = useState(0);
    const rascRef = useRef(null);
    const varsRef = useRef({});
    const sujo = useRef({ rasc: false, vars: false });
    const relogio = useRef({ rasc: null, vars: null });
    const ordem = useRef({ enviada: 0, aplicada: 0 });

    rascRef.current = rasc;
    varsRef.current = vars;

    // ── Servidor ──
    const aplicarTudo = (data) => { setEstado(data); setRasc(doEstado(data)); setVars(variantesDoEstado(data)); };

    /** Chamada que devolve o estado. `tudo` = ação de estrutura (troca também as cópias locais). */
    const chamar = async (promessa, { tudo = false } = {}) => {
        const n = ++ordem.current.enviada;
        setSalvando((s) => s + 1);
        setErro(null);
        try {
            const { data } = await promessa();
            // Uma resposta mais velha que a última aplicada não volta o estado no tempo.
            if (n >= ordem.current.aplicada) {
                ordem.current.aplicada = n;
                if (tudo) aplicarTudo(data); else setEstado(data);
            }

            return data;
        } catch (e) {
            setErro(mensagemDe(e));

            return null;
        } finally {
            setSalvando((s) => s - 1);
        }
    };

    const salvarRasc = async () => {
        clearTimeout(relogio.current.rasc);
        if (! sujo.current.rasc) return;
        sujo.current.rasc = false;
        const r = await chamar(() => axios.put(rota('salvar', produtoId), rascRef.current));
        if (r) setSalvoEm(new Date()); else sujo.current.rasc = true;
    };
    const salvarVars = async () => {
        clearTimeout(relogio.current.vars);
        if (! sujo.current.vars) return;
        sujo.current.vars = false;
        const r = await chamar(() => axios.put(rota('variantes', produtoId), { variantes: varsRef.current }));
        if (r) setSalvoEm(new Date()); else sujo.current.vars = true;
    };
    const descarregar = async () => { await salvarRasc(); await salvarVars(); };

    const mudarRasc = (mudanca) => {
        setRasc((r) => ({ ...r, ...(typeof mudanca === 'function' ? mudanca(r) : mudanca) }));
        sujo.current.rasc = true;
        clearTimeout(relogio.current.rasc);
        relogio.current.rasc = setTimeout(salvarRasc, ESPERA_SALVAR);
    };
    const mudarVar = (chave, patch) => {
        setVars((v) => ({ ...v, [chave]: { ...v[chave], ...patch } }));
        sujo.current.vars = true;
        clearTimeout(relogio.current.vars);
        relogio.current.vars = setTimeout(salvarVars, ESPERA_SALVAR);
    };
    const mudarAtributo = (id, v) => mudarRasc((r) => ({
        atributos: v === null ? Object.fromEntries(Object.entries(r.atributos).filter(([k]) => k !== id)) : { ...r.atributos, [id]: v },
    }));

    // ── Abertura (e troca de produto na faixa) ──
    useEffect(() => {
        let vivo = true;
        setCarregando(true);
        setErroCarga(null);
        setAguardando(null);
        setSimulacao(null);
        axios.get(rota('abrir', produtoId))
            .then(({ data }) => {
                if (! vivo) return;
                aplicarTudo(data);
                if (data.publicacao?.status === 'RUNNING') setAguardando({ tipo: 'publicacao', desde: Date.now() });
            })
            .catch((e) => vivo && setErroCarga(mensagemDe(e)))
            .finally(() => vivo && setCarregando(false));

        return () => {
            vivo = false;
            clearTimeout(relogio.current.rasc);
            clearTimeout(relogio.current.vars);
            // Descarrega o que ficou por salvar no produto que está saindo (o id é o desta volta).
            if (sujo.current.rasc && rascRef.current) axios.put(rota('salvar', produtoId), rascRef.current).catch(() => {});
            if (sujo.current.vars) axios.put(rota('variantes', produtoId), { variantes: varsRef.current }).catch(() => {});
            sujo.current = { rasc: false, vars: false };
        };
    }, [produtoId, recarga]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Andamento da fila (conferência e publicação) ──
    useEffect(() => {
        if (! aguardando) return undefined;
        const t = setInterval(async () => {
            if (Date.now() - aguardando.desde > LIMITE_ANDAMENTO) {
                setAguardando(null);
                setAviso('Ainda processando no servidor. Recarregue a página em alguns minutos para ver o resultado.');

                return;
            }
            try {
                const { data } = await axios.get(rota('abrir', produtoId));
                setEstado(data);
                if (aguardando.tipo === 'conferencia' && data.conferencia?.id !== aguardando.conferencia) setAguardando(null);
                if (aguardando.tipo === 'publicacao' && data.publicacao?.status !== 'RUNNING') {
                    setAguardando(null);
                    onPublicou?.();
                }
            } catch {
                // Uma leitura que falha não para o acompanhamento: tenta na próxima volta.
            }
        }, INTERVALO_ANDAMENTO);

        return () => clearInterval(t);
    }, [aguardando, produtoId]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Ações ──
    const escolherCategoria = async (id) => {
        await descarregar();
        const data = await chamar(() => axios.put(rota('categoria', produtoId), { categoria_id: id }), { tudo: true });
        const fora = data?.migracao?.descartados ?? [];
        if (fora.length) setAviso(`Ficaram de fora na categoria nova: ${fora.map((d) => d.nome).join(', ')}.`);
    };
    const buscarCategorias = async (texto) => {
        const { data } = await axios.get(route('mlb.anuncios.publicador.categorias'), { params: { q: texto } });

        return data;
    };
    const salvarEixos = async (eixos) => {
        await descarregar();
        const data = await chamar(() => axios.put(rota('eixos', produtoId), { eixos }), { tudo: true });
        if (Object.keys(data?.regeneracao?.conflitos ?? {}).length) {
            setAviso('Algumas variações juntaram dados diferentes (estoque, SKU): confira o card Variações.');
        }
    };
    const enviarFotos = async (arquivos, grupo) => {
        await descarregar();
        for (const arquivo of arquivos) {
            const fd = new FormData();
            fd.append('imagem', arquivo);
            fd.append('grupo', grupo);
            setEnviandoFoto(grupo);
            const data = await chamar(() => axios.post(rota('fotos', produtoId), fd), { tudo: true });
            setEnviandoFoto(null);
            const bloqueio = data?.foto?.problemas?.find((p) => p.severidade === 'BLOCKER');
            if (bloqueio) setErro(`${arquivo.name}: ${bloqueio.mensagem}`);
        }
    };
    const atribuirFotos = async (atribuicoes) => {
        setEstado((e) => ({ ...e, atribuicoes }));
        await chamar(() => axios.put(rota('fotos.atribuir', produtoId), { atribuicoes: atribuicoes.map((a) => ({ ...a, imagem: Number(a.imagem) })) }), { tudo: true });
    };
    const removerFoto = (imagemId) => chamar(() => axios.delete(rota('fotos.remover', produtoId, { imagem: imagemId })), { tudo: true });
    const reenviarFoto = (imagemId) => chamar(() => axios.post(rota('fotos.reenviar', produtoId, { imagem: imagemId })), { tudo: true });

    const conferir = async () => {
        await descarregar();
        const data = await chamar(() => axios.post(rota('conferir', produtoId)));
        if (data) {
            setCiente(false);
            setAguardando({ tipo: 'conferencia', conferencia: estado?.conferencia?.id ?? null, desde: Date.now() });
        }
    };
    const publicar = async () => {
        const data = await chamar(() => axios.post(rota('publicar', produtoId), { ciente }));
        if (data) setAguardando({ tipo: 'publicacao', desde: Date.now() });
    };
    const simular = async () => {
        setSimulando(true);
        await descarregar();
        try {
            const { data } = await axios.get(rota('simular', produtoId));
            setSimulacao(data.simulacao);
        } catch (e) {
            setErro(mensagemDe(e));
        } finally {
            setSimulando(false);
        }
    };
    const recarregar = useCallback(() => setRecarga((n) => n + 1), []);

    // ── Derivados ──
    const schema = estado?.schema ?? null;
    const publicando = estado?.publicacao?.status === 'RUNNING';
    const publicado = estado?.rascunho?.status === 'PUBLISHED';
    const disabled = publicando || publicado || !! aguardando;
    const variantes = estado ? mesclarVariantes(estado.variantes, vars) : [];
    const alvos = estado && rasc ? mesclarAlvos(estado.alvos, rasc.alvos) : [];
    const conf = estado?.conferencia ?? null;
    const liberada = estado?.publicacao_liberada === true;
    const editando = sujo.current.rasc || sujo.current.vars;

    const copiarTituloDo = (de, para) => mudarRasc((r) => ({
        alvos: r.alvos.map((x) => {
            if (x.listing_type_id !== para) return x;
            const origem = alvos.find((a) => a.listing_type_id === de);

            return { ...x, titulo: origem?.titulo || origem?.titulo_efetivo || '' };
        }),
    }));

    // Problemas desta versão: os locais e os do ML (só quando a conferência do ML vale ou a publicação recusou algo).
    const locais = estado?.problemas ?? [];
    const doMl = [
        ...(conf?.vale && ! conf.local ? (conf.issues ?? []).filter((p) => p.camada === 'L3') : []),
        ...(estado?.publicacao && estado.publicacao.status !== 'RUNNING' ? (estado.publicacao.problemas ?? []) : []),
    ];
    const todos = [...locais, ...doMl];
    const problemasDaSecao = (chave) => todos.filter((p) => secaoDoProblema(p) === chave);
    const problemasDoAtributo = (id, variante = null) => todos.filter((p) => p.alvo?.atributo === id && (variante === null || ! p.alvo?.variante || p.alvo.variante === variante));
    const bloqueiosLocais = locais.filter((p) => p.severidade === 'BLOCKER').length;

    const estadoConf = estadoDaConferencia({ conf, aguardando, sujo: editando });
    const nPendencias = (conf?.local ? locais : doMl).filter((p) => p.severidade === 'BLOCKER').length;
    const podeConferir = calcularPodeConferir({ disabled, bloqueiosLocais, salvando, schema });
    const podePublicar = calcularPodePublicar({ disabled, conf, sujo: editando, ciente, salvando, liberada });

    const m = {
        estado, rasc, variantes, alvos, schema, disabled,
        problemasDaSecao, problemasDoAtributo,
        mudarRasc, mudarVar, mudarAtributo,
        escolherCategoria, buscarCategorias, salvarEixos,
        enviarFotos, atribuirFotos, removerFoto, reenviarFoto, enviandoFoto,
        aviso, simular, simulacao, simulando, copiarTituloDo,
    };

    return {
        m,
        carregando,
        erroCarga,
        erro,
        setErro,
        aviso,
        setAviso,
        salvando,
        salvoEm,
        aguardando,
        ciente,
        setCiente,
        conferir,
        publicar,
        podeConferir,
        podePublicar,
        conferencia: {
            estado: estadoConf,
            texto: textoDaConferencia(estadoConf, nPendencias, liberada),
            pendencias: nPendencias,
            avisos: (conf?.issues ?? []).filter((p) => p.severidade !== 'BLOCKER').length,
            local: conf?.local === true,
        },
        secoes: estado ? estadoDasSecoes(todos, schema) : {},
        prontas: estado ? contarProntas(todos, schema) : 0,
        totalSecoes: SECOES.length,
        totalAnuncios: totalDeAnuncios(alvos, variantes),
        resumo: estado && rasc ? resumoDoLancamento(estado, rasc, variantes, alvos) : null,
        publicacao: estado?.publicacao ?? null,
        liberada,
        recarregar,
        descarregar,
    };
}
