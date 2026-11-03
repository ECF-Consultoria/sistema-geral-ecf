import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { abrirCadastro, gravarLinhas, mensagemDoErro } from '@/lib/trilhaEscrita';
import {
    MENSAGEM_DOS_CAMPOS,
    depoisDeSalvar,
    errosPorCampo,
    estadoDoRetrato,
    gravarCadastro,
    mexido as estaMexido,
    nadaMudou,
    resultadoDoSalvar,
    temRetrato,
} from '@/lib/trilhaCadastro';

// ─── Trilha de produtos — o estado da gaveta de cadastro (`e:{id}`) ─────────
//
// Sem visual (arquivo `.js`, sem classe do Tailwind): o retrato do servidor
// (rota 1, com "Tentar de novo"), os campos como a pessoa deixou, o "mexido",
// o "Salvar" na ordem do contrato e o que ele devolve — o resultado que toma o
// lugar do formulário, os erros por campo ou a trava (409: o produto andou).
//
// E a guarda de saída (§5.12 do desenho): com algo mexido, fechar a gaveta,
// abrir outro item por dentro, trocar o "Ver como" ou uma visita do Inertia
// pedem confirmação no rodapé; F5 e fechar a aba, o `beforeunload` do
// navegador (só enquanto há algo mexido). A recarga dos dados da trilha (a
// visita parcial, com `only`) passa direto: é a própria tela que pede.

const SEM_ERROS = { porChave: {}, soltas: [] };

/**
 * @param {{escrita: {produtos: string}, produtoId: number, onRecarregar?: () => Promise<void>,
 *   onAvisar?: (texto: string, tipo?: string, extras?: string[]) => void}} opcoes
 */
export default function useCadastroDoProduto({ escrita, produtoId, onRecarregar = async () => {}, onAvisar = () => {} }) {
    const [retrato, setRetrato] = useState(null);
    const [estado, setEstado] = useState(null);
    const [erroAoAbrir, setErroAoAbrir] = useState(null);
    const [tentativa, setTentativa] = useState(0);
    const [salvando, setSalvando] = useState(false);
    const [erros, setErros] = useState(SEM_ERROS);
    const [erroGeral, setErroGeral] = useState(null);
    const [nota, setNota] = useState(null);
    const [resultado, setResultado] = useState(null);
    const [trava, setTrava] = useState(null);
    // A confirmação de saída pendente: `{seguir}` — o que fazer se a pessoa descartar (ou salvar).
    const [pedido, setPedido] = useState(null);

    const vivo = useRef(true);
    useEffect(() => {
        vivo.current = true;

        return () => {
            vivo.current = false;
        };
    }, []);

    // 1. O retrato (rota 1). Só a resposta do pedido de agora vale.
    useEffect(() => {
        let atual = true;
        setErroAoAbrir(null);
        abrirCadastro(escrita, produtoId)
            .then((r) => {
                if (!atual) return;
                setRetrato(r);
                setEstado(estadoDoRetrato(r));
            })
            .catch((e) => {
                if (atual) setErroAoAbrir(mensagemDoErro(e));
            });

        return () => {
            atual = false;
        };
    }, [produtoId, tentativa]);

    const leitura = !!trava || retrato?.edicao?.livre === false;
    const motivo = trava ?? retrato?.edicao?.motivo ?? null;
    const temMudanca = !leitura && estaMexido(estado, retrato);

    // O de agora, para quem é chamado depois (a guarda, o "Salvar e fechar", a visita do Inertia).
    const agora = useRef({});
    agora.current = { estado, retrato, leitura, temMudanca, salvando };

    const mudarProduto = useCallback((campo, valor) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, produto: { ...e.produto, [campo]: valor } } : e));
    }, []);
    const mudarVariacao = useCallback((id, campo, valor) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, variacoes: e.variacoes.map((v) => (v.id === id ? { ...v, [campo]: valor } : v)) } : e));
    }, []);

    /** O "Salvar". → true quando gravou tudo (o resultado toma o lugar do formulário). */
    const salvar = useCallback(async () => {
        const { estado: e0, retrato: r0, leitura: soLeitura, salvando: jaSalvando } = agora.current;
        if (jaSalvando || soLeitura || !e0 || !r0) return false;
        agora.current.salvando = true;
        setSalvando(true);
        setErroGeral(null);
        setNota(null);
        try {
            const r = await gravarCadastro({
                estado: e0,
                retrato: r0,
                versao: r0.versao ?? null,
                enviarLinhas: (linhas, { versao }) => gravarLinhas(escrita, produtoId, linhas, { versao }),
            });
            if (!vivo.current) return r.ok;

            if (r.nada) {
                setEstado(estadoDoRetrato(r0));
                setErros(SEM_ERROS);
                setNota('Nada mudou: o que está aqui já estava salvo.');

                return true;
            }

            if (r.falha) {
                const resposta = r.falha?.response;
                const texto = mensagemDoErro(r.falha);
                if (resposta?.status === 409 && resposta.data?.travado) {
                    // O produto andou enquanto a gaveta estava aberta: vira só leitura, com o motivo do servidor, e o
                    // retrato é lido de novo (o que está na tela não foi salvo).
                    setTrava(texto);
                    setEstado(estadoDoRetrato(r0));
                    setErros(SEM_ERROS);
                    setTentativa((n) => n + 1);
                } else {
                    setErroGeral(texto);
                }
                onAvisar(texto, 'erro');

                return false;
            }

            const novo = temRetrato(r.resposta) ? r.resposta : await abrirCadastro(escrita, produtoId);
            if (!vivo.current) return r.ok;

            if (r.erros.length) {
                // As linhas sem erro gravaram: o retrato muda, e a trilha também. As com erro ficam como estavam.
                setRetrato(novo);
                setEstado(depoisDeSalvar(e0, novo, r.erros));
                const porCampo = errosPorCampo(r.erros);
                setErros(porCampo);
                setErroGeral(porCampo.soltas[0] ?? MENSAGEM_DOS_CAMPOS);
                onAvisar(MENSAGEM_DOS_CAMPOS, 'erro');
                onRecarregar();

                return false;
            }

            // O servidor não achou diferença ("890" contra os "890,00" de lá): nada a anunciar, os campos ficam os dele.
            if (nadaMudou(r)) {
                setRetrato(novo);
                setEstado(estadoDoRetrato(novo));
                setErros(SEM_ERROS);
                setNota('Nada mudou: o que está aqui já estava salvo.');

                return true;
            }

            // Tudo gravado: a trilha recarrega ANTES do resultado (o "Mapear agora" precisa do produto fora da Lista).
            await onRecarregar();
            if (!vivo.current) return true;
            const fim = resultadoDoSalvar(r0, novo, r.criadas_nas_listas);
            setRetrato(novo);
            setEstado(estadoDoRetrato(novo));
            setErros(SEM_ERROS);
            setResultado(fim);
            onAvisar(fim.texto, 'sucesso', [fim.extra, ...r.avisos].filter(Boolean));

            return true;
        } catch (e) {
            // O GET de depois falhou (o "Salvar" já foi): o texto do erro, e o retrato de antes continua.
            if (vivo.current) setErroGeral(mensagemDoErro(e));

            return false;
        } finally {
            agora.current.salvando = false;
            if (vivo.current) setSalvando(false);
        }
    }, [escrita, produtoId, onRecarregar, onAvisar]);

    // ─── A guarda de saída ──────────────────────────────────────────────────

    /** A gaveta pergunta a esta antes de fechar, abrir outro item ou trocar o "Ver como" (`useGuardaDaGaveta`). */
    const guardiao = useMemo(() => ({
        mexido: () => !!agora.current.temMudanca,
        confirmar: (seguir) => setPedido({ seguir }),
    }), []);

    const continuarEditando = useCallback(() => setPedido(null), []);
    const descartar = useCallback(() => {
        const seguir = pedido?.seguir;
        setPedido(null);
        // Descartado: a guarda não pergunta de novo no caminho (a visita do Inertia passa pelo `before` outra vez).
        agora.current.temMudanca = false;
        seguir?.();
    }, [pedido]);
    const salvarESeguir = useCallback(async () => {
        const seguir = pedido?.seguir;
        const gravou = await salvar();
        if (!vivo.current) return;
        if (gravou) {
            setPedido(null);
            agora.current.temMudanca = false;
            seguir?.();
        } else {
            setPedido(null);
        }
    }, [pedido, salvar]);

    // F5 e fechar a aba: o aviso do navegador, só enquanto há algo mexido.
    useEffect(() => {
        if (!temMudanca || typeof window === 'undefined') return undefined;
        const aoSair = (evento) => {
            evento.preventDefault();
            evento.returnValue = '';
        };
        window.addEventListener('beforeunload', aoSair);

        return () => window.removeEventListener('beforeunload', aoSair);
    }, [temMudanca]);

    // As visitas do Inertia (o "Empresas" do topo, o voltar que vira visita): com algo mexido, perguntam antes. A
    // recarga dos dados da trilha (`only`) é da própria tela e passa.
    useEffect(() => {
        const liberada = { agora: false };
        const desligar = router.on?.('before', (evento) => {
            const visita = evento?.detail?.visit;
            if (!visita || (visita.only ?? []).length > 0 || liberada.agora || !agora.current.temMudanca) return undefined;
            evento.preventDefault?.();
            setPedido({
                seguir: () => {
                    liberada.agora = true;
                    router.visit(visita.url, {
                        method: visita.method,
                        data: visita.data,
                        replace: visita.replace,
                        preserveScroll: visita.preserveScroll,
                        preserveState: visita.preserveState,
                    });
                },
            });

            return false;
        });

        return () => desligar?.();
    }, []);

    return {
        retrato,
        estado,
        erroAoAbrir,
        tentarDeNovo: () => setTentativa((n) => n + 1),
        carregando: !retrato && !erroAoAbrir,
        leitura,
        motivo,
        temMudanca,
        salvando,
        salvar,
        erros,
        erroGeral,
        nota,
        resultado,
        continuarDoResultado: () => setResultado(null),
        mudarProduto,
        mudarVariacao,
        guardiao,
        pedido,
        continuarEditando,
        descartar,
        salvarESeguir,
    };
}
