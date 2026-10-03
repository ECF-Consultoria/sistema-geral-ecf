import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { SECOES, criarRota, estadoDasSecoes, mensagemDe, secaoDoProblema, valorVazio } from './apoio.js';
import {
    contarProntas, envioDasVariantes, envioDoRascunho, esperaDaNovaTentativa, estadoDaConferencia, estadoDoSalvamento, mesclarAlvos,
    mesclarComPendentes, mesclarVariantes, pendenciasDaConferencia, podeConferir as calcularPodeConferir, podePublicar as calcularPodePublicar,
    resumoDoLancamento, semRepetir, textoDaConferencia, totalDeAnuncios,
} from './derivados.js';

// ─── Lógica do editor do Publicador (D24) ───────────────────────────────────
//
// Nasceu no piloto do Portal (já removido, D18): quem decide tudo é o
// servidor — toda resposta traz o estado inteiro do rascunho.
// - Digitação (atributos, títulos, preços, estoque) fica numa cópia local e vai
//   ao servidor com espera; a resposta atualiza o resto sem pisar no digitado.
// - A BASE é o que o servidor já tem da cópia local (última leitura ou último
//   salvamento que deu certo). Campo local ≠ base = digitado e ainda não salvo.
// - Toda escrita passa por uma FILA: um pedido de cada vez, na ordem. Assim
//   "descarregar" espera também o salvamento que já está em voo (CR-F01).
// - Ações de estrutura (categoria, variações, fotos) descarregam antes e, na
//   volta, trocam o estado inteiro MENOS o que foi digitado durante a ação
//   (`mesclarComPendentes`), que segue por salvar.
// - O salvamento automático manda só os campos editados. Enquanto a IA grava o
//   rascunho no servidor (`pausado`), a mesa é só leitura e nada é salvo; ao fim
//   da IA o hook relê o servidor ANTES de liberar a edição (CR-F02).
// - Salvamento que falha tenta de novo sozinho (espera crescente, poucas vezes); o
//   indicador deixa de dizer "Salvo" e, com algo por salvar, sair da página pede
//   confirmação (WR-F02).
// - Conferir e publicar vão para a fila do servidor: o hook acompanha até terminar.
// - Termos mais buscados e a IA do Modelo/título (melhoria de 03/10/2026): a IA roda
//   na fila do servidor; o hook acompanha o pedido e APLICA o resultado pelo caminho
//   normal de edição (vira digitação salva como qualquer outra). Escolher categoria
//   com o Modelo vazio já pede o Modelo à IA.
// A casca (164-13) só compõe; o contrato `m` abaixo é o que os cards da mesa leem.

const ESPERA_SALVAR = 900;
const CONFIRMA_SAIR = 'Há alterações que não foram salvas. Sair mesmo assim e perdê-las?';
const INTERVALO_ANDAMENTO = 2500;
const LIMITE_ANDAMENTO = 4 * 60 * 1000;
const LIMITE_PALAVRAS_IA = 5 * 60 * 1000;

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
 * @param {{ produtoId: number, onPublicou?: Function, pausado?: boolean }} opcoes
 *   `pausado` = a IA está gravando este rascunho no servidor: mesa só leitura e nenhum
 *   salvamento sai até `recarregarDepoisDaIa` reler o que ela gravou (CR-F02).
 */
export default function usePublicador({ produtoId, onPublicou, pausado = false }) {
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
    const [relendo, setRelendo] = useState(false);
    // WR-F02: `{ mensagem, desistiu }` do salvamento automático que falhou; nulo = em dia.
    const [falhaSalvar, setFalhaSalvar] = useState(null);
    // Termos mais buscados da categoria: `{ categoria, dados, carregando, erro }`.
    const [termos, setTermos] = useState({ categoria: null, dados: null, carregando: false, erro: null });
    // IA do Modelo/título por alvo: `{ status, erro, pedido, automatico, desde }`.
    const [palavrasIa, setPalavrasIa] = useState({});
    const palavrasRef = useRef({});
    // Frete grátis obrigatório pela faixa de preço (resposta do servidor), nulo = não consultado.
    const [frete, setFrete] = useState(null);
    const pausadoRef = useRef(pausado);
    pausadoRef.current = pausado;
    const emVoo = useRef(0);
    const tentativas = useRef({ rasc: 0, vars: 0 });
    const erroDoSalvamento = useRef(false);
    // As cópias locais vivem também em refs, atualizadas JUNTO com o estado (nunca no render):
    // quem salva ou mescla lê sempre a última versão, mesmo antes de o React renderizar.
    const rascRef = useRef(null);
    const varsRef = useRef({});
    // O que o servidor já tem da cópia local (última leitura ou último salvamento que deu certo).
    const baseRef = useRef({ rasc: null, vars: {} });
    const relogio = useRef({ rasc: null, vars: null });
    const ordem = useRef({ enviada: 0, aplicada: 0 });
    const fila = useRef(Promise.resolve());

    const porRasc = (r) => { rascRef.current = r; setRasc(r); };
    const porVars = (v) => { varsRef.current = v; setVars(v); };

    /** Põe uma escrita na fila: roda depois de todas as anteriores (as que falharam também). */
    const enfileirar = (tarefa) => {
        const vez = fila.current.then(() => tarefa());
        fila.current = vez.catch(() => {});

        return vez;
    };

    /** O que foi digitado e o servidor ainda não tem. */
    const pendencias = () => ({
        rasc: envioDoRascunho(rascRef.current, baseRef.current.rasc) !== null,
        vars: envioDasVariantes(varsRef.current, baseRef.current.vars) !== null,
    });

    // ── Servidor ──
    /** Resposta do servidor → tela. `mesclar` = não pisar no que foi digitado e ainda não foi salvo (CR-F01). */
    const aplicarServidor = (data, { mesclar = false } = {}) => {
        const servidor = { rasc: doEstado(data), vars: variantesDoEstado(data) };
        const r = mesclar
            ? mesclarComPendentes({ servidor, local: { rasc: rascRef.current, vars: varsRef.current }, base: baseRef.current })
            : { ...servidor, pendente: { rasc: false, vars: false } };
        baseRef.current = servidor;
        setEstado(data);
        porRasc(r.rasc);
        porVars(r.vars);
        // O que ficou da cópia local continua por salvar.
        if (r.pendente.rasc) agendar('rasc');
        if (r.pendente.vars) agendar('vars');
    };

    /**
     * Chamada que devolve o estado (nulo se falhou). `tudo` = ação de estrutura (troca também as
     * cópias locais, mesclando). `fundo` = salvamento automático: não mexe na faixa de erro (quem
     * avisa é o indicador da barra) e entrega a mensagem a `aoFalhar`.
     */
    const chamar = async (promessa, { tudo = false, fundo = false, aoFalhar } = {}) => {
        const n = ++ordem.current.enviada;
        emVoo.current += 1;
        setSalvando((s) => s + 1);
        if (! fundo) setErro(null);
        try {
            const { data } = await promessa();
            // Uma resposta mais velha que a última aplicada não volta o estado no tempo.
            if (n >= ordem.current.aplicada) {
                ordem.current.aplicada = n;
                if (tudo) aplicarServidor(data, { mesclar: true }); else setEstado(data);
            }

            return data;
        } catch (e) {
            const mensagem = mensagemDe(e);
            if (fundo) aoFalhar?.(mensagem); else setErro(mensagem);

            return null;
        } finally {
            emVoo.current -= 1;
            setSalvando((s) => s - 1);
        }
    };

    /** WR-F02: o salvamento que falhou tenta de novo sozinho, com espera crescente; esgotadas as tentativas, avisa. */
    const salvamentoFalhou = (tipo, mensagem) => {
        const espera = esperaDaNovaTentativa(++tentativas.current[tipo]);
        if (espera !== null) {
            clearTimeout(relogio.current[tipo]);
            relogio.current[tipo] = setTimeout(() => enfileirar(tipo === 'rasc' ? salvarRascAgora : salvarVarsAgora), espera);
            setFalhaSalvar({ mensagem, desistiu: false });

            return;
        }
        setFalhaSalvar({ mensagem, desistiu: true });
        erroDoSalvamento.current = true;
        setErro(`As últimas alterações não foram salvas: ${mensagem} Elas continuam na tela; edite de novo para tentar outra vez.`);
    };
    const salvamentoEmDia = (tipo) => {
        tentativas.current[tipo] = 0;
        if (tentativas.current.rasc > 0 || tentativas.current.vars > 0) return;
        setFalhaSalvar(null);
        if (erroDoSalvamento.current) {
            erroDoSalvamento.current = false;
            setErro(null);
        }
    };

    // Salvamentos que rodam DENTRO da fila. Nunca chamam `enfileirar` (a fila esperaria por si mesma).
    // Devolvem true quando o servidor ficou com tudo o que havia para salvar.
    // CR-F02: vai SÓ o que foi editado (o servidor não mexe no que não veio) — nunca o documento
    // inteiro, que apagaria o que a IA ou outra aba gravou nos outros campos. E, com a IA gravando
    // no servidor (`pausado`), nada sai daqui: o pendente espera a releitura do fim da IA.
    const salvarRascAgora = async () => {
        clearTimeout(relogio.current.rasc);
        const envio = envioDoRascunho(rascRef.current, baseRef.current.rasc);
        if (envio === null) {
            salvamentoEmDia('rasc');

            return true;
        }
        if (pausadoRef.current) return false;
        let falha = null;
        const r = await chamar(() => axios.put(rota('salvar', produtoId), envio), { fundo: true, aoFalhar: (m) => { falha = m; } });
        if (! r) {
            salvamentoFalhou('rasc', falha);

            return false;
        }
        baseRef.current = { ...baseRef.current, rasc: { ...baseRef.current.rasc, ...envio } };
        setSalvoEm(new Date());
        salvamentoEmDia('rasc');

        return true;
    };
    const salvarVarsAgora = async () => {
        clearTimeout(relogio.current.vars);
        const envio = envioDasVariantes(varsRef.current, baseRef.current.vars);
        if (envio === null) {
            salvamentoEmDia('vars');

            return true;
        }
        if (pausadoRef.current) return false;
        let falha = null;
        const r = await chamar(() => axios.put(rota('variantes', produtoId), { variantes: envio }), { fundo: true, aoFalhar: (m) => { falha = m; } });
        if (! r) {
            salvamentoFalhou('vars', falha);

            return false;
        }
        const vars = { ...baseRef.current.vars };
        for (const [chave, campos] of Object.entries(envio)) vars[chave] = { ...vars[chave], ...campos };
        baseRef.current = { ...baseRef.current, vars };
        setSalvoEm(new Date());
        salvamentoEmDia('vars');

        return true;
    };
    const salvarTudoAgora = async () => {
        const rascOk = await salvarRascAgora();
        const varsOk = await salvarVarsAgora();

        return rascOk && varsOk;
    };

    /** Salva o pendente — depois do que já está na fila (inclusive um salvamento em voo). */
    const descarregar = () => enfileirar(salvarTudoAgora);

    const agendar = (tipo) => {
        clearTimeout(relogio.current[tipo]);
        relogio.current[tipo] = setTimeout(() => enfileirar(tipo === 'rasc' ? salvarRascAgora : salvarVarsAgora), ESPERA_SALVAR);
    };

    // Edição nova depois de uma falha ganha de novo todas as tentativas.
    const mudarRasc = (mudanca) => {
        const atual = rascRef.current;
        porRasc({ ...atual, ...(typeof mudanca === 'function' ? mudanca(atual) : mudanca) });
        tentativas.current.rasc = 0;
        agendar('rasc');
    };
    const mudarVar = (chave, patch) => {
        const atual = varsRef.current;
        porVars({ ...atual, [chave]: { ...atual[chave], ...patch } });
        tentativas.current.vars = 0;
        agendar('vars');
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
        setTermos({ categoria: null, dados: null, carregando: false, erro: null });
        palavrasRef.current = {};
        setPalavrasIa({});
        setFrete(null);
        enfileirar(async () => {
            try {
                const { data } = await axios.get(rota('abrir', produtoId));
                if (! vivo) return;
                aplicarServidor(data);
                if (data.publicacao?.status === 'RUNNING') setAguardando({ tipo: 'publicacao', desde: Date.now() });
            } catch (e) {
                if (vivo) setErroCarga(mensagemDe(e));
            } finally {
                if (vivo) setCarregando(false);
            }
        });

        return () => {
            vivo = false;
            clearTimeout(relogio.current.rasc);
            clearTimeout(relogio.current.vars);
            // Descarrega, na fila, o que ficou por salvar no produto que está saindo (o id é o desta
            // volta): só os campos pendentes, e sem mexer na tela (ela já é de outro produto).
            const envioRasc = envioDoRascunho(rascRef.current, baseRef.current.rasc);
            const envioVars = envioDasVariantes(varsRef.current, baseRef.current.vars);
            if (envioRasc || envioVars) {
                enfileirar(async () => {
                    if (envioRasc) await axios.put(rota('salvar', produtoId), envioRasc).catch(() => {});
                    if (envioVars) await axios.put(rota('variantes', produtoId), { variantes: envioVars }).catch(() => {});
                });
            }
        };
    }, [produtoId]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── IA gravando no servidor (CR-F02) ──
    // Saindo da pausa sem releitura (análise sumiu, prazo estourado): o pendente volta a ser salvo.
    useEffect(() => {
        if (pausado) return;
        const p = pendencias();
        if (p.rasc) agendar('rasc');
        if (p.vars) agendar('vars');
    }, [pausado]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Saída da página com algo por salvar (WR-F02) ──
    // Fechar a aba ou F5: o navegador pergunta. Navegação do Inertia (links, faixa de produtos):
    // a visita espera o salvamento; se ele não passar, pergunta antes de sair. Pré-carregamento e
    // recarga parcial da própria página (`only`) não são saída.
    const naoSalvo = () => emVoo.current > 0 || pendencias().rasc || pendencias().vars;
    const naoSalvoRef = useRef(naoSalvo);
    naoSalvoRef.current = naoSalvo;
    const descarregarRef = useRef(descarregar);
    descarregarRef.current = descarregar;
    useEffect(() => {
        let liberado = false;
        const aoFecharAba = (e) => {
            if (! naoSalvoRef.current()) return undefined;
            e.preventDefault();
            e.returnValue = '';

            return '';
        };
        window.addEventListener('beforeunload', aoFecharAba);
        const tirarGuarda = router.on('before', (event) => {
            const visita = event.detail.visit;
            if (liberado || visita.prefetch || visita.only?.length || visita.except?.length || visita.reset?.length) return true;
            if (! naoSalvoRef.current()) return true;
            descarregarRef.current().then((salvou) => {
                if (! salvou && ! window.confirm(CONFIRMA_SAIR)) return;
                liberado = true;
                router.visit(visita.url, {
                    method: visita.method,
                    data: visita.data,
                    replace: visita.replace,
                    preserveScroll: visita.preserveScroll,
                    preserveState: visita.preserveState,
                    headers: visita.headers,
                    onFinish: () => { liberado = false; },
                });
            });

            return false;
        });

        return () => {
            window.removeEventListener('beforeunload', aoFecharAba);
            tirarGuarda();
        };
    }, []);

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
    /**
     * Ação de estrutura (CR-F01): na fila, salva antes o que estava pendente e, na volta,
     * troca o estado inteiro mesclando — o que for digitado enquanto ela corre fica na tela
     * e segue por salvar. `antes` roda logo antes do pedido (depois do salvamento).
     */
    const estruturar = (fazer, { antes } = {}) => enfileirar(async () => {
        await salvarTudoAgora();
        antes?.();

        return chamar(fazer, { tudo: true });
    });

    const escolherCategoria = async (id) => {
        const data = await estruturar(() => axios.put(rota('categoria', produtoId), { categoria_id: id }));
        const fora = data?.migracao?.descartados ?? [];
        if (fora.length) setAviso(`Ficaram de fora na categoria nova: ${fora.map((d) => d.nome).join(', ')}.`);
        // Docx §2: com a categoria escolhida, a IA monta o Modelo com os termos mais buscados — só se ele estiver vazio.
        if (data?.schema?.atributos?.MODEL && valorVazio(rascRef.current?.atributos?.MODEL)) {
            pedirPalavrasIa('modelo', { automatico: true });
        }
    };
    const buscarCategorias = async (texto) => {
        const { data } = await axios.get(route('mlb.anuncios.publicador.categorias'), { params: { q: texto } });

        return data;
    };
    const salvarEixos = async (eixos) => {
        const data = await estruturar(() => axios.put(rota('eixos', produtoId), { eixos }));
        if (Object.keys(data?.regeneracao?.conflitos ?? {}).length) {
            setAviso('Algumas variações juntaram dados diferentes (estoque, SKU): confira o card Variações.');
        }
    };
    const enviarFotos = async (arquivos, grupo) => {
        // Uma volta da fila por arquivo: o que se digita enquanto as fotos sobem é salvo entre elas.
        for (const arquivo of arquivos) {
            const fd = new FormData();
            fd.append('imagem', arquivo);
            fd.append('grupo', grupo);
            setEnviandoFoto(grupo);
            const data = await estruturar(() => axios.post(rota('fotos', produtoId), fd));
            setEnviandoFoto(null);
            const bloqueio = data?.foto?.problemas?.find((p) => p.severidade === 'BLOCKER');
            if (bloqueio) setErro(`${arquivo.name}: ${bloqueio.mensagem}`);
        }
    };
    const atribuirFotos = async (atribuicoes) => {
        // A nova ordem aparece na hora e de novo depois do salvamento (que devolve a ordem anterior).
        const otimista = (e) => ({ ...e, atribuicoes });
        setEstado(otimista);
        await estruturar(
            () => axios.put(rota('fotos.atribuir', produtoId), { atribuicoes: atribuicoes.map((a) => ({ ...a, imagem: Number(a.imagem) })) }),
            { antes: () => setEstado(otimista) },
        );
    };
    const removerFoto = (imagemId) => estruturar(() => axios.delete(rota('fotos.remover', produtoId, { imagem: imagemId })));
    const reenviarFoto = (imagemId) => estruturar(() => axios.post(rota('fotos.reenviar', produtoId, { imagem: imagemId })));

    const conferir = async () => {
        const data = await enfileirar(async () => {
            await salvarTudoAgora();

            return chamar(() => axios.post(rota('conferir', produtoId)));
        });
        if (data) {
            setCiente(false);
            setAguardando({ tipo: 'conferencia', conferencia: estado?.conferencia?.id ?? null, desde: Date.now() });
        }
    };
    const publicar = async () => {
        const data = await enfileirar(() => chamar(() => axios.post(rota('publicar', produtoId), { ciente })));
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
    // ── Termos mais buscados e IA do Modelo/título (docx §2 e §3) ──
    const carregarTermos = async () => {
        const categoria = estado?.rascunho?.categoria_id ?? null;
        if (! categoria) return;
        setTermos({ categoria, dados: null, carregando: true, erro: null });
        try {
            const { data } = await axios.get(rota('termos', produtoId));
            setTermos({ categoria, dados: data, carregando: false, erro: null });
        } catch (e) {
            setTermos({ categoria, dados: null, carregando: false, erro: mensagemDe(e) });
        }
    };

    const mudarIa = (alvo, patch) => {
        palavrasRef.current = { ...palavrasRef.current, [alvo]: { ...(palavrasRef.current[alvo] ?? {}), ...patch } };
        setPalavrasIa(palavrasRef.current);
    };

    /** `automatico` = pedido pela escolha de categoria: o resultado não pisa no que a pessoa escreveu enquanto isso. */
    const pedirPalavrasIa = async (alvo, { escolhidos = [], automatico = false } = {}) => {
        mudarIa(alvo, { status: 'rodando', erro: null, pedido: null, automatico, desde: Date.now() });
        try {
            const { data } = await axios.post(rota('palavras-ia', produtoId), { alvo, escolhidos });
            mudarIa(alvo, { pedido: data.pedido });
        } catch (e) {
            mudarIa(alvo, { status: 'erro', erro: mensagemDe(e) });
        }
    };

    const aplicarPalavras = (alvo, valor, automatico) => {
        if (alvo === 'modelo') {
            if (automatico && ! valorVazio(rascRef.current?.atributos?.MODEL)) {
                mudarIa(alvo, { status: 'pronto', erro: null });

                return;
            }
            mudarAtributo('MODEL', { value_id: null, value_name: valor, origem: 'ia', revisar: false });
        } else {
            const lt = alvo.replace('titulo_', '');
            mudarRasc((r) => ({ alvos: r.alvos.map((x) => (x.listing_type_id === lt ? { ...x, titulo: valor } : x)) }));
        }
        mudarIa(alvo, { status: 'pronto', erro: null });
    };

    // Acompanha os pedidos em andamento; só aceita a resposta do PRÓPRIO pedido.
    const rodandoIa = Object.entries(palavrasIa).filter(([, s]) => s.status === 'rodando').map(([alvo]) => alvo).join(',');
    useEffect(() => {
        if (! rodandoIa) return undefined;
        const t = setInterval(async () => {
            for (const alvo of rodandoIa.split(',')) {
                const s = palavrasRef.current[alvo];
                if (! s || s.status !== 'rodando' || ! s.pedido) continue;
                if (Date.now() - s.desde > LIMITE_PALAVRAS_IA) {
                    mudarIa(alvo, { status: 'erro', erro: 'A IA demorou demais. Tente de novo.' });
                    continue;
                }
                try {
                    const { data } = await axios.get(rota('palavras-ia.status', produtoId, { alvo }));
                    if (data.pedido !== s.pedido) continue;
                    if (data.status === 'pronto') aplicarPalavras(alvo, data.valor, s.automatico);
                    else if (data.status === 'erro') mudarIa(alvo, { status: 'erro', erro: data.erro ?? 'A IA não conseguiu agora. Tente de novo.' });
                } catch {
                    // Uma leitura que falha não para o acompanhamento: tenta na próxima volta.
                }
            }
        }, INTERVALO_ANDAMENTO);

        return () => clearInterval(t);
    }, [rodandoIa, produtoId]); // eslint-disable-line react-hooks/exhaustive-deps

    /** Frete grátis obrigatório pela faixa de preço: salva o pendente antes (o servidor lê preço e pacote gravados). */
    const consultarFrete = async () => {
        await descarregar();
        try {
            const { data } = await axios.get(rota('frete', produtoId));
            setFrete(data.frete_gratis ?? null);
        } catch {
            // Sem a resposta do ML a tela segue como antes: a escolha do frete grátis fica com a pessoa.
            setFrete(null);
        }
    };

    /**
     * Relê o rascunho DENTRO da fila — nenhum PUT corre junto com este GET (CR-F02) — e
     * aplica mesclando: o que ainda estiver por salvar fica na tela. `descarregarAntes` =
     * salva o pendente antes de ler. Devolve se a leitura deu certo.
     */
    const reler = async ({ descarregarAntes }) => {
        if (descarregarAntes) await salvarTudoAgora();
        setCarregando(true);
        setErroCarga(null);
        try {
            const { data } = await axios.get(rota('abrir', produtoId));
            aplicarServidor(data, { mesclar: true });
            setSimulacao(null);
            setAguardando(data.publicacao?.status === 'RUNNING' ? { tipo: 'publicacao', desde: Date.now() } : null);

            return true;
        } catch (e) {
            setErroCarga(mensagemDe(e));

            return false;
        } finally {
            setCarregando(false);
        }
    };

    /** Relê o servidor (reenviar descrição, "Tentar de novo"): salva o pendente, ESPERA, e só então lê. */
    const recarregar = async () => {
        setRelendo(true);
        await enfileirar(() => reler({ descarregarAntes: true }));
        setRelendo(false);
    };

    /**
     * Fim da IA (concluída ou com erro — ela pode ter gravado parte). A mesa só é liberada
     * depois de reler o que ela gravou. Sem descarregar antes: o pendente (salvamento que
     * falhou) iria inteiro por cima do que a IA gravou; lido primeiro, ele é mesclado campo
     * a campo e só então salvo.
     */
    const recarregarDepoisDaIa = async () => {
        setRelendo(true);
        const leu = await enfileirar(() => reler({ descarregarAntes: false }));
        if (leu) setRelendo(false);
        else setErro('Não foi possível ler o que a IA preencheu. Recarregue a página antes de continuar editando.');
    };

    // ── Derivados ──
    const schema = estado?.schema ?? null;
    const publicando = estado?.publicacao?.status === 'RUNNING';
    const publicado = estado?.rascunho?.status === 'PUBLISHED';
    // CR-F02: com a IA gravando (`pausado`) ou relendo o servidor, a mesa é só leitura.
    const disabled = publicando || publicado || !! aguardando || pausado || relendo;
    const variantes = estado ? mesclarVariantes(estado.variantes, vars) : [];
    const alvos = estado && rasc ? mesclarAlvos(estado.alvos, rasc.alvos) : [];
    const conf = estado?.conferencia ?? null;
    const liberada = estado?.publicacao_liberada === true;
    const pendente = pendencias();
    const editando = pendente.rasc || pendente.vars;

    const copiarTituloDo = (de, para) => mudarRasc((r) => ({
        alvos: r.alvos.map((x) => {
            if (x.listing_type_id !== para) return x;
            const origem = alvos.find((a) => a.listing_type_id === de);

            return { ...x, titulo: origem?.titulo || origem?.titulo_efetivo || '' };
        }),
    }));

    // Problemas desta versão: os locais, os da conferência que ainda vale e os da publicação que recusou algo.
    // WR-F07: da conferência do ML entram TODOS (o bloqueio da ficha achado nela é camada L2, não L3),
    // sem repetir o que os locais já mostram — senão a seção diz "pronta" ao lado de um BLOQUEADO.
    const locais = estado?.problemas ?? [];
    const daConferencia = pendenciasDaConferencia(conf);
    const doServidor = [
        ...semRepetir([...daConferencia.bloqueios, ...daConferencia.avisos], locais),
        ...(estado?.publicacao && estado.publicacao.status !== 'RUNNING' ? (estado.publicacao.problemas ?? []) : []),
    ];
    const todos = [...locais, ...doServidor];
    const problemasDaSecao = (chave) => todos.filter((p) => secaoDoProblema(p) === chave);
    const problemasDoAtributo = (id, variante = null) => todos.filter((p) => p.alvo?.atributo === id && (variante === null || ! p.alvo?.variante || p.alvo.variante === variante));
    const bloqueiosLocais = locais.filter((p) => p.severidade === 'BLOCKER').length;

    const estadoConf = estadoDaConferencia({ conf, aguardando, sujo: editando });
    const nPendencias = conf?.local ? locais.filter((p) => p.severidade === 'BLOCKER').length : daConferencia.bloqueios.length;
    // O texto só diz "o Mercado Livre apontou" quando tudo veio dele (camada L3).
    const conferenciaDoMl = estadoConf === 'avisos' ? daConferencia.avisosDoMl : daConferencia.bloqueiosDoMl;
    const podeConferir = calcularPodeConferir({ disabled, bloqueiosLocais, salvando, schema });
    const podePublicar = calcularPodePublicar({ disabled, conf, sujo: editando, ciente, salvando, liberada });

    const m = {
        estado, rasc, variantes, alvos, schema, disabled,
        problemasDaSecao, problemasDoAtributo,
        mudarRasc, mudarVar, mudarAtributo,
        escolherCategoria, buscarCategorias, salvarEixos,
        enviarFotos, atribuirFotos, removerFoto, reenviarFoto, enviandoFoto,
        aviso, simular, simulacao, simulando, copiarTituloDo,
        termos, carregarTermos, palavrasIa, pedirPalavrasIa, frete, consultarFrete,
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
        // WR-F02: o indicador da barra ("Salvo" só quando não sobra nada por salvar).
        salvamento: {
            estado: estadoDoSalvamento({ salvando, pendente: editando, falha: falhaSalvar, pausado, salvoEm }),
            mensagem: falhaSalvar?.mensagem ?? null,
        },
        aguardando,
        ciente,
        setCiente,
        conferir,
        publicar,
        podeConferir,
        podePublicar,
        conferencia: {
            estado: estadoConf,
            texto: textoDaConferencia(estadoConf, nPendencias, liberada, conferenciaDoMl),
            pendencias: nPendencias,
            avisos: (conf?.issues ?? []).filter((p) => p.severidade !== 'BLOCKER').length,
            local: conf?.local === true,
            // WR-F07: o que a conferência do ML apontou (L2 e L3), para a lateral listar.
            bloqueios: daConferencia.bloqueios,
            listaDeAvisos: daConferencia.avisos,
        },
        secoes: estado ? estadoDasSecoes(todos, schema) : {},
        prontas: estado ? contarProntas(todos, schema) : 0,
        totalSecoes: SECOES.length,
        totalAnuncios: totalDeAnuncios(alvos, variantes),
        resumo: estado && rasc ? resumoDoLancamento(estado, rasc, variantes, alvos) : null,
        publicacao: estado?.publicacao ?? null,
        liberada,
        relendo,
        recarregar,
        recarregarDepoisDaIa,
        descarregar,
    };
}
