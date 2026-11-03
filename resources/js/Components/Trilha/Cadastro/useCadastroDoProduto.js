import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    abrirCadastro,
    conferirFrete as conferirFreteNoServidor,
    enviarFotos as enviarFotosAoServidor,
    excluirFoto as excluirFotoNoServidor,
    gravarFicha,
    gravarLinhas,
    mensagemDoErro,
} from '@/lib/trilhaEscrita';
import { errosDaResposta, idDoElemento } from '@/lib/fichaTecnica';
import {
    ESPERE_A_FICHA,
    FICHA_FICOU_PARA_DEPOIS,
    MENSAGEM_DA_FICHA,
    MENSAGEM_DOS_CAMPOS,
    MENSAGEM_SO_DA_FICHA,
    aplicarResposta,
    categoriaTravada,
    chaveDaVariacao,
    depoisDaFichaRecusada,
    depoisDeSalvar,
    erroDaCategoriaNo422,
    errosPorCampo,
    estadoDoRetrato,
    fichaParaSalvar,
    gravarCadastro,
    mexido as estaMexido,
    nadaMudou,
    resultadoDoSalvar,
    temRetrato,
} from '@/lib/trilhaCadastro';
import useCategoriaDaTrilha from '@/Components/Trilha/Cadastro/useCategoriaDaTrilha';
import useFichaDaTrilha from '@/Components/Trilha/Cadastro/useFichaDaTrilha';

// ─── Trilha de produtos — o estado da gaveta de cadastro (`e:{id}`) ─────────
//
// Sem visual (arquivo `.js`, sem classe do Tailwind): o retrato do servidor
// (rota 1, com "Tentar de novo"), os campos como a pessoa deixou, o "mexido",
// o "Salvar" na ordem do contrato e o que ele devolve — o resultado que toma o
// lugar do formulário, os erros por campo ou a trava (409: o produto andou).
//
// F2: a categoria e a ficha técnica moram no MESMO estado (`estado.produto.categoria`
// e `estado.ficha`): o "mexido" e a guarda de saída as veem. A sugestão, a
// busca, a árvore e os termos do título são de `useCategoriaDaTrilha`; os
// campos da categoria e o "Preencher com IA", de `useFichaDaTrilha`. O
// "Salvar" tem dois passos (§5.9 do desenho): 1) as linhas (com a categoria);
// 2) a ficha inteira, se ela ou a categoria mudou. O 422 do passo 2 marca os
// campos da ficha e o passo 1 fica gravado.
//
// F3: as AÇÕES IMEDIATAS — enviar e excluir foto e "Conferir frete pela
// conta". Elas gravam na hora, sem o "Salvar", e o servidor responde o retrato
// novo: ele vira o retrato da gaveta e o estado o recebe por `aplicarResposta`
// (o que a pessoa digitou e ainda não salvou fica como ela deixou). Uma ação de
// cada vez, e nunca durante o "Salvar" (as duas mudariam o mesmo retrato).
//
// E a guarda de saída (§5.12 do desenho): com algo mexido, fechar a gaveta,
// abrir outro item por dentro, trocar o "Ver como" ou uma visita do Inertia
// (link, `router.visit`) pedem confirmação no rodapé; F5 e fechar a aba, o
// `beforeunload` do navegador (só enquanto há algo mexido). A recarga dos
// dados da trilha (a visita parcial, com `only`) passa direto: é a própria
// tela que pede.
//
// O que a guarda NÃO cobre: o botão VOLTAR do navegador. No Inertia 2.3.21 o
// `popstate` troca a página por `page.setQuietly(…, {preserveState: false})`,
// sem o evento `before`, e o `beforeunload` não dispara (é a mesma página do
// documento): o que estava mexido se perde sem pergunta. Uma entrada-sentinela
// no histórico (pushState + interceptar o `popstate`) foi avaliada e deixada
// de lado por ora: ela disputa a entrada com o `replaceState` que a trilha faz
// a cada painel (`gravarNaUrl`) e com o `replace` da recarga do Inertia, e
// deixa entradas duplicadas no histórico em vários caminhos (F5, visita depois
// de descartar) — ver o relato da revisão da F1.

const SEM_ERROS = { porChave: {}, soltas: [], ficha: {} };

/** O foco no 1º campo da ficha com erro (no navegador; no teste não há `document`). */
function focarNaFicha(campos) {
    const primeiro = Object.keys(campos ?? {})[0];
    if (!primeiro || typeof document === 'undefined' || typeof requestAnimationFrame === 'undefined') return;
    requestAnimationFrame(() => {
        const el = document.getElementById(idDoElemento(primeiro));
        el?.scrollIntoView?.({ block: 'center' });
        el?.focus?.();
    });
}

/**
 * @param {{escrita: {produtos: string, categorias?: string}, produtoId: number, onRecarregar?: () => Promise<void>,
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
    // A ação imediata em curso (F3): `{tipo: 'fotos'|'foto'|'frete', variacaoId?, imagemId?, quantas?}`.
    const [acao, setAcao] = useState(null);
    // O que o "Conferir frete pela conta" respondeu: `{texto, tom: 'ok'|'erro'}`.
    const [notaDoFrete, setNotaDoFrete] = useState(null);

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

    // ─── Os campos ──────────────────────────────────────────────────────────

    const mudarProduto = useCallback((campo, valor) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, produto: { ...e.produto, [campo]: valor } } : e));
    }, []);
    const mudarVariacao = useCallback((id, campo, valor) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, variacoes: e.variacoes.map((v) => (v.id === id ? { ...v, [campo]: valor } : v)) } : e));
    }, []);

    /** A categoria escolhida vai para o ESTADO (grava no "Salvar", pela linha). */
    const usarCategoria = useCallback((categoria) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, produto: { ...e.produto, categoria } } : e));
        setErros((atual) => {
            const chave = Object.keys(atual.porChave).find((k) => atual.porChave[k]?.categoria);
            if (!chave) return atual;
            const { categoria: _tirada, ...resto } = atual.porChave[chave];

            return { ...atual, porChave: { ...atual.porChave, [chave]: resto } };
        });
    }, []);

    /** A ficha inteira trocada de uma vez (as sugestões da IA). */
    const trocarFicha = useCallback((mudar) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, ficha: mudar(e.ficha ?? {}) } : e));
    }, []);

    const categoria = useCategoriaDaTrilha({
        escrita,
        nome: estado?.produto?.nome ?? '',
        categoria: estado?.produto?.categoria ?? null,
        ativo: !!retrato && !leitura && !categoriaTravada(retrato),
        onUsar: usarCategoria,
    });
    const ficha = useFichaDaTrilha({ escrita, produtoId, retrato, estado, trocarFicha, ativo: !!retrato && !leitura });

    /** Um campo da ficha (ou das medidas do produto montado): `parte` junta ao que o campo tinha. */
    const mudarFicha = useCallback((id, parte) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, ficha: { ...(e.ficha ?? {}), [id]: { valor: '', unidade: '', ...(e.ficha?.[id] ?? {}), ...parte } } } : e));
        setErros((atual) => {
            if (!atual.ficha?.[id]) return atual;
            const { [id]: _tirado, ...resto } = atual.ficha;

            return { ...atual, ficha: resto };
        });
        ficha.esquecerSugestao(id);
    }, [ficha.esquecerSugestao]);

    // O "mexido" com a definição da ficha da categoria de agora (compara o corpo que iria ao servidor).
    const temMudanca = !leitura && estaMexido(estado, retrato, { grupos: ficha.doProduto });

    // O de agora, para quem é chamado depois (a guarda, o "Salvar e fechar", a visita do Inertia).
    const agora = useRef({});
    agora.current = {
        estado,
        retrato,
        leitura,
        temMudanca,
        salvando,
        // A ação imediata em curso: quem a liga e desliga é a própria ação (antes do próximo render).
        acao: agora.current.acao ?? null,
        definicao: { categoria: ficha.definicao?.categoria ?? null, grupos: ficha.definicao?.grupos ?? null, carregando: ficha.carregando },
    };

    /** O "Salvar". → true quando gravou tudo (o resultado toma o lugar do formulário). */
    const salvar = useCallback(async () => {
        const { estado: e0, retrato: r0, leitura: soLeitura, salvando: jaSalvando, definicao, acao: emCurso } = agora.current;
        if (jaSalvando || soLeitura || !e0 || !r0 || emCurso) return false;
        const plano = fichaParaSalvar({ estado: e0, retrato: r0, definicao });
        if (plano.esperar) {
            setNota(ESPERE_A_FICHA);

            return false;
        }
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
                // O 2º passo: a ficha que a tela montou ANTES do 1º, com a categoria dela (o servidor confere).
                gravarFicha: plano.gravar ? ({ versao }) => gravarFicha(escrita, produtoId, plano.atributos, { categoria: plano.categoria, versao }) : null,
                fichaMudou: plano.gravar,
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
                    onAvisar(texto, 'erro');

                    return false;
                }

                if (r.passo === 'ficha') {
                    // O passo 1 (as linhas, com a categoria) JÁ gravou, se houve: o retrato e a trilha mudaram. A
                    // ficha fica como a pessoa deixou, com os campos marcados.
                    if (r.linhas) {
                        const novo = temRetrato(r.resposta) ? r.resposta : await abrirCadastro(escrita, produtoId);
                        if (!vivo.current) return false;
                        setRetrato(novo);
                        setEstado(depoisDaFichaRecusada(e0, novo));
                        onRecarregar();
                    }
                    const { campos, geral } = resposta?.status === 422 ? errosDaResposta(r.falha) : { campos: {}, geral: null };
                    const comCampos = Object.keys(campos).length > 0;
                    const doRodape = comCampos ? (r.linhas ? MENSAGEM_DA_FICHA : MENSAGEM_SO_DA_FICHA) : (geral ?? texto);
                    setErros({ ...SEM_ERROS, ficha: campos });
                    setErroGeral(doRodape);
                    onAvisar(doRodape, 'erro');
                    focarNaFicha(campos);

                    return false;
                }

                // O 422 da categoria (não anunciável, ou o produto já no Publicador) vai no campo dela.
                const daCategoria = erroDaCategoriaNo422(r.falha);
                if (daCategoria && e0.variacoes?.[0]) {
                    setErros({ ...SEM_ERROS, porChave: { [chaveDaVariacao(e0.variacoes[0])]: { categoria: daCategoria } } });
                }
                setErroGeral(texto);
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
                setErros({ ...SEM_ERROS, ...porCampo });
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
            // O SKU que tinha anúncio na espera liga o produto a ele no MESMO save: o resultado diz isso (e o retrato
            // novo costuma voltar travado — a gaveta fica só leitura, pelo `edicao.livre` dele).
            const fim = resultadoDoSalvar(r0, novo, r.criadas_nas_listas, { absorvidos: r.totais?.absorvidos_da_espera ?? 0 });
            setRetrato(novo);
            setEstado(estadoDoRetrato(novo));
            setErros(SEM_ERROS);
            setResultado(fim);
            const extras = [fim.extra, plano.pulou ? FICHA_FICOU_PARA_DEPOIS : null, ...r.avisos].filter(Boolean);
            onAvisar(fim.texto, 'sucesso', extras);

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

    // ─── As ações imediatas (F3) ────────────────────────────────────────────

    /**
     * Uma ação imediata: o pedido, e a resposta (o retrato novo) juntada ao que está na tela. → `{ok, resposta?,
     * falha?, travado?}`. O 409 da trava vira o modo leitura, como no "Salvar". A trilha recarrega (as faltas e a
     * etapa mudam com as fotos); a foto que completa o produto diz que ele seguiu para o mapeamento.
     */
    const imediata = useCallback(async (descricao, pedir) => {
        const { retrato: r0, leitura: soLeitura, salvando: jaSalvando, acao: emCurso } = agora.current;
        if (!r0 || soLeitura || jaSalvando || emCurso) return { ok: false, ignorada: true };
        agora.current.acao = descricao;
        setAcao(descricao);
        try {
            const resposta = await pedir();
            const novo = temRetrato(resposta) ? resposta : await abrirCadastro(escrita, produtoId);
            if (!vivo.current) return { ok: true, resposta };
            // O estado se mede contra o retrato de AGORA (um "Salvar" não corre junto: a ação o barra).
            const antigo = agora.current.retrato ?? r0;
            setRetrato(novo);
            setEstado((e) => aplicarResposta(e, antigo, novo));
            setResultado(null);
            onRecarregar();
            const fim = resultadoDoSalvar(antigo, novo);
            if (fim.tipo === 'completo') onAvisar(fim.texto, 'sucesso');

            return { ok: true, resposta };
        } catch (e) {
            const resposta = e?.response;
            if (resposta?.status === 409 && resposta.data?.travado) {
                const texto = mensagemDoErro(e);
                if (vivo.current) {
                    setTrava(texto);
                    setErros(SEM_ERROS);
                    setTentativa((n) => n + 1);
                }
                onAvisar(texto, 'erro');

                return { ok: false, falha: e, travado: true };
            }

            return { ok: false, falha: e };
        } finally {
            agora.current.acao = null;
            if (vivo.current) setAcao(null);
        }
    }, [escrita, produtoId, onRecarregar, onAvisar]);

    /** Envia as fotos para a variação que guarda as da cor (a remessa já foi conferida pela galeria). */
    const enviarFotos = useCallback(
        (variacaoId, arquivos) => imediata(
            { tipo: 'fotos', variacaoId, quantas: Array.from(arquivos ?? []).length },
            () => enviarFotosAoServidor(escrita, produtoId, variacaoId, arquivos),
        ),
        [imediata, escrita, produtoId],
    );

    /** Exclui uma foto (a galeria já pediu a confirmação). */
    const excluirFoto = useCallback(
        (variacaoId, imagemId) => imediata({ tipo: 'foto', variacaoId, imagemId }, () => excluirFotoNoServidor(escrita, produtoId, variacaoId, imagemId)),
        [imediata, escrita, produtoId],
    );

    /** "Conferir frete pela conta": a logística de cada variação volta no retrato; a nota diz o que o servidor disse. */
    const conferirFrete = useCallback(async () => {
        setNotaDoFrete(null);
        const r = await imediata({ tipo: 'frete' }, () => conferirFreteNoServidor(escrita, produtoId));
        if (!vivo.current || r.ignorada || r.travado) return r;
        if (r.ok) {
            const texto = typeof r.resposta?.mensagem === 'string' && r.resposta.mensagem.trim() ? r.resposta.mensagem.trim() : 'Frete conferido pela conta da loja.';
            setNotaDoFrete({ texto, tom: 'ok' });
        } else {
            setNotaDoFrete({ texto: mensagemDoErro(r.falha), tom: 'erro' });
        }

        return r;
    }, [imediata, escrita, produtoId]);

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

    // As visitas do Inertia (o "Empresas" do topo, qualquer link do Inertia): com algo mexido, perguntam antes. A
    // recarga dos dados da trilha (`only`) é da própria tela e passa. O VOLTAR do navegador não passa por aqui
    // (o `popstate` do Inertia não dispara o `before`) — ver o cabeçalho.
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
        mudarFicha,
        categoria,
        ficha,
        guardiao,
        pedido,
        continuarEditando,
        descartar,
        salvarESeguir,
        acao,
        enviarFotos,
        excluirFoto,
        conferirFrete,
        notaDoFrete,
    };
}
