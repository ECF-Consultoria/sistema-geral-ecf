import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    abrirCadastro,
    conferirFrete as conferirFreteNoServidor,
    enviarFotos as enviarFotosAoServidor,
    excluirFoto as excluirFotoNoServidor,
    excluirProduto as excluirProdutoNoServidor,
    excluirVariacao as excluirVariacaoNoServidor,
    gravarFicha,
    gravarLinhas,
    mensagemDoErro,
    ordenarFotos as ordenarFotosNoServidor,
    previaDaExclusao,
} from '@/lib/trilhaEscrita';
import { errosDaResposta, idDoElemento } from '@/lib/fichaTecnica';
import {
    ESPERE_A_FICHA,
    FICHA_FICOU_PARA_DEPOIS,
    MENSAGEM_DA_FICHA,
    MENSAGEM_DAS_CORES,
    MENSAGEM_DOS_CAMPOS,
    MENSAGEM_SO_DA_FICHA,
    NOTA_DO_RETRATO_DE_FORA,
    aplicarResposta,
    avisoDaCorNova,
    categoriaTravada,
    chaveDaVariacao,
    comCustoECaixasIguais,
    conferirGravacaoDasCores,
    conflitoDeVersao,
    custoECaixasIguais,
    custoIgualEmCadaCor,
    depoisDaFichaRecusada,
    depoisDeSalvar,
    erroDaCategoriaNo422,
    errosDasLinhasNo422,
    errosPorCampo,
    estadoDoRetrato,
    exclusaoMudou,
    fichaParaSalvar,
    gravarCadastro,
    linhasDaEstrutura,
    linhasParaSalvar,
    mexido as estaMexido,
    montadasDaPrevia,
    nadaMudou,
    resultadoDoSalvar,
    temRetrato,
    textoDoConflito,
    travadoNo409,
    trocaDeEixo,
    versaoDepoisDe,
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
// F4: cores e tamanhos. O tipo de variação e o nome de cada cor e tamanho
// moram no estado (grava no "Salvar", com a conferência das cores ANTES); "+
// Cor"/"+ Tamanho" e remover são AÇÕES IMEDIATAS — o pedido leva as variações
// novas e a estrutura da tela (a 1ª cor nomeia a que já existe no mesmo
// pedido), e nada do conteúdo (`linhasDaEstrutura`). Custo e caixas "iguais em
// todas as cores" (o padrão, quando o que está gravado é igual) ou "diferente
// por cor". "Excluir produto" em dois passos: a prévia do que sai junto e a
// confirmação; depois, a trilha recarrega e a gaveta fecha (o produto sumiu).
//
// F6: a aba velha. A gaveta guarda a `versao` do retrato (a que ELA conhece) e a
// manda nas escritas que mudam conteúdo — o "Salvar" (linhas e ficha, a de cada
// resposta valendo para o pedido seguinte), "+ Cor", remover e excluir o produto.
// O 409 SEM `travado` é o da versão: o que a pessoa digitou fica na tela e o
// rodapé diz "Este produto mudou depois que você abriu…" com [Recarregar] — que
// relê o retrato e só descarta o que estava mexido depois de a pessoa confirmar.
// Nunca vira só leitura por isso (o 409 COM `travado` continua o modo leitura).
// As fotos (enviar, excluir, a ordem) e o frete não levam versão (não a mudam):
// se a resposta deles traz outra, alguém mudou o produto por fora. Com algo
// mexido, a gaveta avisa na hora em vez de adotá-la (adotar deixaria o próximo
// "Salvar" passar por cima). Sem nada mexido (R1, 04/11/2026) não há o que
// perder: a gaveta passa a mostrar o retrato de agora e só diz isso no rodapé
// — antes o envio de foto pedia "Recarregue…" com a gaveta toda salva.
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
    // F4: custo e caixas "diferente por cor" (false = iguais em todas). Null até o retrato chegar: aí vale o gravado.
    const [custoPorCor, setCustoPorCor] = useState(null);
    // F4: "Cor nova salva. Este produto já foi mapeado…" (depois de "+ Cor" num produto mapeado).
    const [avisoDaCor, setAvisoDaCor] = useState(null);
    // F4: "Excluir produto" — `{passo: 'previa'|'confirmar'|'excluindo'|'excluido'|'erro', previa, erro}`.
    const [exclusao, setExclusao] = useState(null);
    // F6: o 409 da versão — `{texto, confirmar, recarregando, erro}` (o rodapé vira o aviso com [Recarregar]).
    const [conflito, setConflito] = useState(null);
    // F6: a versão do cadastro que ESTA gaveta conhece (vai nas escritas que mudam conteúdo). Não se desenha: ref.
    const versao = useRef(null);

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
                versao.current = r?.versao ?? null;
                setConflito(null);
                setRetrato(r);
                setEstado(estadoDoRetrato(r));
                // "Iguais em todas as cores" é o padrão quando o gravado é igual; a escolha da pessoa fica numa releitura.
                setCustoPorCor((escolhido) => escolhido ?? !custoECaixasIguais(r?.variacoes));
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
        custoPorCor: !!custoPorCor,
        exclusao,
        conflito,
    };

    /**
     * O 409 da versão (F6): o rodapé vira o aviso com [Recarregar]. O que a pessoa digitou FICA — nada é descartado
     * aqui, e a gaveta não vira só leitura.
     */
    const avisarConflito = useCallback((falha) => {
        const texto = typeof falha === 'string' ? falha : textoDoConflito(falha);
        if (vivo.current) {
            setConflito({ texto, confirmar: false, recarregando: false, erro: null });
            setErros(SEM_ERROS);
            setErroGeral(null);
            setNota(null);
        }
        onAvisar(texto, 'erro');
    }, [onAvisar]);

    /**
     * A versão depois de uma resposta com retrato (`versaoDepoisDe`). A de fora (fotos, ordem, frete) que mudou só vira o
     * aviso com algo MEXIDO a perder; sem nada mexido, a gaveta adota o retrato de agora e diz isso no rodapé (R1).
     */
    const conhecerVersao = useCallback((resposta, { conferida }) => {
        const { versao: nova, mudou, porFora } = versaoDepoisDe(versao.current, resposta, { conferida, mexido: !!agora.current.temMudanca });
        versao.current = nova;
        if (mudou) avisarConflito(textoDoConflito(null));
        else if (porFora && vivo.current) setNota(NOTA_DO_RETRATO_DE_FORA);
    }, [avisarConflito]);

    /** O "Salvar". → true quando gravou tudo (o resultado toma o lugar do formulário). */
    const salvar = useCallback(async () => {
        const { estado: e0, retrato: r0, leitura: soLeitura, salvando: jaSalvando, definicao, acao: emCurso } = agora.current;
        if (jaSalvando || soLeitura || !e0 || !r0 || emCurso) return false;
        const plano = fichaParaSalvar({ estado: e0, retrato: r0, definicao });
        if (plano.esperar) {
            setNota(ESPERE_A_FICHA);

            return false;
        }
        // F4: a conferência das cores (a régua do servidor) ANTES de gravar: a variação que esta gravação deixaria sem
        // nome, repetida ou de outro tipo viraria, em silêncio, um anúncio separado. O que já estava gravado assim não
        // barra (como no servidor) — só aparece marcado.
        const conferencia = conferirGravacaoDasCores(e0, r0, [], { eixos: r0.eixos });
        if (!conferencia.ok) {
            setErros({ ...SEM_ERROS, porChave: conferencia.porChave });
            setErroGeral(MENSAGEM_DAS_CORES);
            onAvisar(MENSAGEM_DAS_CORES, 'erro');

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
                // F6: a versão que esta gaveta conhece; a de cada resposta vale para o pedido seguinte (linhas → ficha).
                versao: versao.current,
                enviarLinhas: (linhas, { versao: v }) => gravarLinhas(escrita, produtoId, linhas, { versao: v }),
                // O 2º passo: a ficha que a tela montou ANTES do 1º, com a categoria dela (o servidor confere).
                gravarFicha: plano.gravar ? ({ versao: v }) => gravarFicha(escrita, produtoId, plano.atributos, { categoria: plano.categoria, versao: v }) : null,
                fichaMudou: plano.gravar,
            });
            // O que gravou (os pedidos que passaram pela conferência) é desta gaveta: a versão dele vale daqui em diante.
            if (r.versao) versao.current = r.versao;
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
                if (conflitoDeVersao(r.falha)) {
                    // A aba velha (F6): nada deste pedido gravou e o que a pessoa digitou FICA na tela. Se um passo
                    // anterior gravou (as linhas, quando foi a ficha que levou o 409), ele vira o retrato — sem apagar
                    // o resto do que está mexido.
                    if (temRetrato(r.resposta)) {
                        const novo = r.resposta;
                        setRetrato(novo);
                        setEstado(r.passo === 'ficha' ? depoisDaFichaRecusada(e0, novo) : aplicarResposta(e0, r0, novo));
                        onRecarregar();
                    }
                    avisarConflito(r.falha);

                    return false;
                }
                if (travadoNo409(r.falha)) {
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

                // O 422 da categoria (não anunciável, ou o produto já no Publicador) vai no campo dela; o da
                // conferência das cores do servidor (F4), no campo de cada variação — pela linha que a tela mandou.
                const daCategoria = erroDaCategoriaNo422(r.falha);
                const dasLinhas = errosDasLinhasNo422(r.falha, linhasParaSalvar(e0, r0));
                const porChave = { ...(dasLinhas?.porChave ?? {}) };
                if (daCategoria && e0.variacoes?.[0]) {
                    const primeira = chaveDaVariacao(e0.variacoes[0]);
                    porChave[primeira] = { ...(porChave[primeira] ?? {}), categoria: daCategoria };
                }
                if (Object.keys(porChave).length) setErros({ ...SEM_ERROS, porChave });
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
    }, [escrita, produtoId, onRecarregar, onAvisar, avisarConflito]);

    // ─── As ações imediatas (F3) ────────────────────────────────────────────

    /** O 409 da trava numa ação imediata: a gaveta vira só leitura, com o motivo do servidor, e relê o retrato. */
    const travar = useCallback((falha) => {
        const texto = mensagemDoErro(falha);
        if (vivo.current) {
            setTrava(texto);
            setErros(SEM_ERROS);
            setTentativa((n) => n + 1);
        }
        onAvisar(texto, 'erro');
    }, [onAvisar]);

    /**
     * Uma ação imediata: o pedido, e a resposta (o retrato novo) juntada ao que está na tela. → `{ok, resposta?,
     * falha?, travado?, conflito?}`. O 409 da trava vira o modo leitura, como no "Salvar"; o da versão (F6), o aviso
     * com [Recarregar]. A trilha recarrega (as faltas e a etapa mudam com as fotos); a foto que completa o produto diz
     * que ele seguiu para o mapeamento.
     *
     * `conferida` (F6): o pedido levou a versão da gaveta e passou — a da resposta passa a valer. Sem ela (fotos,
     * frete), a versão fica a de antes, e uma diferente na resposta é o produto mudado por fora (`versaoDepoisDe`).
     */
    const imediata = useCallback(async (descricao, pedir, { conferida = false } = {}) => {
        const { retrato: r0, leitura: soLeitura, salvando: jaSalvando, acao: emCurso } = agora.current;
        if (!r0 || soLeitura || jaSalvando || emCurso) return { ok: false, ignorada: true };
        agora.current.acao = descricao;
        setAcao(descricao);
        try {
            const resposta = await pedir();
            const novo = temRetrato(resposta) ? resposta : await abrirCadastro(escrita, produtoId);
            conhecerVersao(novo, { conferida });
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
            if (travadoNo409(e)) {
                travar(e);

                return { ok: false, falha: e, travado: true };
            }
            // Uma ação de vários pedidos (remover uma cor de vários tamanhos) que parou no meio: o que já saiu, saiu —
            // o retrato do último pedido que deu certo vale (F4).
            if (vivo.current && temRetrato(e?.retratoParcial)) {
                const antigo = agora.current.retrato ?? r0;
                // A da escrita conferida vale; a de fora (a ordem das fotos que voltou 422 e foi relida) passa pela mesma
                // régua da resposta de sucesso (R1: sem nada mexido, só a nota).
                conhecerVersao(e.retratoParcial, { conferida });
                setRetrato(e.retratoParcial);
                setEstado((atual) => aplicarResposta(atual, antigo, e.retratoParcial));
                onRecarregar();
            }
            if (conflitoDeVersao(e)) {
                avisarConflito(e);

                return { ok: false, falha: e, conflito: true, parcial: temRetrato(e?.retratoParcial) };
            }

            return { ok: false, falha: e, parcial: temRetrato(e?.retratoParcial) };
        } finally {
            agora.current.acao = null;
            if (vivo.current) setAcao(null);
        }
    }, [escrita, produtoId, onRecarregar, onAvisar, travar, conhecerVersao, avisarConflito]);

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

    /**
     * R6: a ordem das fotos da cor (todas as da galeria; a 1ª é a capa). Não leva versão: a ordem fica fora dela. O 422 (a
     * galeria mudou por fora no meio do caminho) relê o retrato — a galeria volta a ser a de agora — e a falha segue
     * para a galeria dizer o porquê.
     */
    const ordenarFotos = useCallback(
        (variacaoId, ordem) => imediata({ tipo: 'ordem', variacaoId }, async () => {
            try {
                return await ordenarFotosNoServidor(escrita, produtoId, variacaoId, ordem);
            } catch (e) {
                if (e?.response?.status === 422) {
                    try {
                        e.retratoParcial = await abrirCadastro(escrita, produtoId);
                    } catch {
                        // Sem o retrato novo, fica só o aviso: o [Recarregar] da gaveta resolve.
                    }
                }
                throw e;
            }
        }),
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

    // ─── Cores e tamanhos (F4) ──────────────────────────────────────────────

    /**
     * "Este produto varia?": troca o tipo de variação NA TELA (grava no "Salvar"). → o resultado de `trocaDeEixo`:
     * `{pode: false, motivo}` não muda nada; `{confirmar}` sem `confirmado` só pergunta (tirar o tamanho com valores).
     */
    const trocarEixo = useCallback((novo, { confirmado = false } = {}) => {
        const { estado: e0, retrato: r0 } = agora.current;
        if (!e0) return { pode: false, mudou: false };
        const r = trocaDeEixo(e0, novo, { eixos: r0?.eixos });
        if (!r.pode || !r.mudou || (r.confirmar && !confirmado)) return r;
        setNota(null);
        setEstado(r.estado);

        return { ...r, aplicado: true };
    }, []);

    /** O nome de uma cor (o cartão inteiro: todas as variações dela) — ou de uma variação, com um tipo só. */
    const mudarCor = useCallback((ids, valor) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, variacoes: e.variacoes.map((v) => (ids.includes(v.id) ? { ...v, valor } : v)) } : e));
    }, []);

    /** Custo ou caixas (`campo`) em várias variações de uma vez: todas ("iguais em todas as cores") ou as da cor. */
    const mudarNasVariacoes = useCallback((ids, campo, valor) => {
        setNota(null);
        setEstado((e) => (e ? { ...e, variacoes: e.variacoes.map((v) => (!ids || ids.includes(v.id) ? { ...v, [campo]: valor } : v)) } : e));
    }, []);

    /** "Iguais em todas as cores": o custo e as caixas da 1ª variação passam a valer para todas (na tela). */
    const usarCustoIgual = useCallback(() => {
        setCustoPorCor(false);
        setEstado((e) => comCustoECaixasIguais(e));
    }, []);
    const usarCustoPorCor = useCallback(() => setCustoPorCor(true), []);

    /**
     * "+ Cor" / "+ Tamanho": a conferência das cores na tela e, passando, o pedido com as variações novas e a
     * estrutura da tela (a 1ª cor nomeia a que já existe no MESMO pedido). → `{ok, porChave, soltas, criadas}`:
     * - a conferência que não passa não envia nada (`porChave` marca a variação, nova ou não);
     * - o servidor grava linha por linha: a nova com erro (o SKU que já existe na empresa) volta em `porChave` e as
     *   outras ficam gravadas (`parcial`);
     * - o 422 (a conferência do servidor) marca pela chave que a tela mandou.
     * Com o custo "iguais em todas as cores", a variação nova passa a ter o da tela (fica mexida até o "Salvar").
     */
    const adicionarVariacoes = useCallback(async (novas, { cor = false } = {}) => {
        const { estado: e0, retrato: r0 } = agora.current;
        if (!e0 || !r0 || !novas?.length) return { ok: false, ignorada: true };
        const conferencia = conferirGravacaoDasCores(e0, r0, novas, { eixos: r0.eixos });
        if (!conferencia.ok) {
            // Só o que ESTA ação cria (o que já estava gravado assim aparece marcado nos cartões, e não barra).
            const porChave = {};
            for (const p of conferencia.novos) porChave[p.chave] = { ...(porChave[p.chave] ?? {}), [p.campo]: p.mensagem };

            return { ok: false, porChave, soltas: [MENSAGEM_DAS_CORES] };
        }

        const linhas = linhasDaEstrutura(e0, r0, novas);
        const r = await imediata(
            { tipo: 'variacoes', quantas: novas.length },
            () => gravarLinhas(escrita, produtoId, linhas, { versao: versao.current }),
            { conferida: true },
        );
        if (!vivo.current || r.ignorada || r.travado || r.conflito) return r;
        if (!r.ok) {
            const do422 = errosDasLinhasNo422(r.falha, linhas);
            const soltas = do422?.soltas?.length ? do422.soltas : Object.keys(do422?.porChave ?? {}).length ? [] : [mensagemDoErro(r.falha)];

            return { ok: false, porChave: do422?.porChave ?? {}, soltas, falha: r.falha };
        }

        // O custo e as caixas da tela valem para a nova ("iguais em todas", ou os da cor dela).
        const novo = r.resposta;
        if (!agora.current.custoPorCor) setEstado((e) => comCustoECaixasIguais(e));
        else setEstado((e) => custoIgualEmCadaCor(e, novo));
        const erros = novo?.erros ?? [];
        const porCampo = errosPorCampo(erros);
        const criadas = Number(novo?.totais?.criadas ?? 0);
        // O produto já mapeado que ganhou variação (`mapear_de_novo` do servidor): o aviso com [Mapear de novo].
        const aviso = avisoDaCorNova(novo, { cor });
        if (aviso) setAvisoDaCor(aviso);

        return { ok: erros.length === 0, parcial: erros.length > 0, porChave: porCampo.porChave, soltas: porCampo.soltas, criadas };
    }, [imediata, escrita, produtoId]);

    /**
     * Remove variações (uma cor inteira são as dela; um tamanho, a dele) — uma por pedido (rota 14). A peça de combo
     * ou kit volta 422 com o texto do servidor: o que já saiu antes dela fica fora (o retrato parcial vale). Cada
     * pedido leva a versão que o anterior devolveu (F6: o 1º, a da gaveta). → `{ok, falha?, parcial?, conflito?}`.
     */
    const removerVariacoes = useCallback(async (ids) => {
        const lista = [...(ids ?? [])];
        if (!lista.length) return { ok: false, ignorada: true };

        return imediata({ tipo: 'remover', ids: lista }, async () => {
            let ultimo = null;
            let v = versao.current;
            for (const id of lista) {
                try {
                    ultimo = await excluirVariacaoNoServidor(escrita, produtoId, id, { versao: v });
                    if (ultimo?.versao) v = ultimo.versao;
                } catch (e) {
                    if (temRetrato(ultimo)) e.retratoParcial = ultimo;
                    throw e;
                }
            }

            return ultimo;
        }, { conferida: true });
    }, [imediata, escrita, produtoId]);

    // ─── Excluir o produto (F4): dois passos ────────────────────────────────

    /** O 1º passo: a prévia do que sai junto (as ofertas montadas). Só com o produto livre e nada em curso. */
    const pedirExclusao = useCallback(async () => {
        const { leitura: soLeitura, salvando: jaSalvando, acao: emCurso, exclusao: emAndamento } = agora.current;
        if (soLeitura || jaSalvando || emCurso || ['previa', 'excluindo', 'excluido'].includes(emAndamento?.passo)) return;
        setExclusao({ passo: 'previa', previa: null, erro: null });
        try {
            // F6: com a versão, a aba velha sabe já aqui — antes de pedir a confirmação.
            const previa = await previaDaExclusao(escrita, produtoId, { versao: versao.current });
            if (vivo.current) setExclusao({ passo: 'confirmar', previa, erro: null });
        } catch (e) {
            if (!vivo.current) return;
            if (travadoNo409(e)) {
                setExclusao(null);
                travar(e);

                return;
            }
            if (conflitoDeVersao(e)) {
                setExclusao(null);
                avisarConflito(e);

                return;
            }
            setExclusao({ passo: 'erro', previa: null, erro: mensagemDoErro(e) });
        }
    }, [escrita, produtoId, travar, avisarConflito]);

    /**
     * O 2º passo: exclui com as ofertas montadas que a pessoa VIU. Deu certo: a trilha recarrega e a gaveta fecha (o
     * produto sumiu dos dados). O que sai junto mudou no meio do caminho (422 em `montadas`): a prévia volta, nova.
     */
    const confirmarExclusao = useCallback(async () => {
        const atual = agora.current.exclusao;
        if (atual?.passo !== 'confirmar' || !atual.previa) return;
        setExclusao({ ...atual, passo: 'excluindo', erro: null });
        try {
            const r = await excluirProdutoNoServidor(escrita, produtoId, montadasDaPrevia(atual.previa), { versao: versao.current });
            if (!vivo.current) return;
            setExclusao({ passo: 'excluido', previa: atual.previa, erro: null });
            const texto = typeof r?.mensagem === 'string' && r.mensagem.trim() ? r.mensagem.trim() : 'Produto excluído.';
            onAvisar(texto, 'sucesso');
            await onRecarregar();
        } catch (e) {
            if (!vivo.current) return;
            if (travadoNo409(e)) {
                setExclusao(null);
                travar(e);

                return;
            }
            // F6: o produto mudou depois que a gaveta abriu — nada saiu; o rodapé pede "Recarregar" antes de excluir.
            if (conflitoDeVersao(e)) {
                setExclusao(null);
                avisarConflito(e);

                return;
            }
            // Já tinha saído (outra aba, outra pessoa): o que se queria aconteceu — a trilha recarrega e a gaveta fecha.
            if (e?.response?.status === 404) {
                setExclusao({ passo: 'excluido', previa: atual.previa, erro: null });
                onAvisar('Este produto já tinha sido excluído.', 'sucesso');
                await onRecarregar();

                return;
            }
            if (exclusaoMudou(e)) {
                try {
                    const previa = await previaDaExclusao(escrita, produtoId, { versao: versao.current });
                    if (vivo.current) setExclusao({ passo: 'confirmar', previa, erro: 'O que sai junto mudou. Confira de novo antes de excluir.' });
                } catch (e2) {
                    if (!vivo.current) return;
                    if (conflitoDeVersao(e2)) {
                        setExclusao(null);
                        avisarConflito(e2);

                        return;
                    }
                    setExclusao({ passo: 'erro', previa: null, erro: mensagemDoErro(e2) });
                }

                return;
            }
            setExclusao({ ...atual, passo: 'confirmar', erro: mensagemDoErro(e) });
        }
    }, [escrita, produtoId, onAvisar, onRecarregar, travar, avisarConflito]);

    const cancelarExclusao = useCallback(() => setExclusao(null), []);

    // ─── A aba velha (F6): [Recarregar] ─────────────────────────────────────

    /**
     * Relê o retrato e volta ao que está salvo AGORA (a versão nova junto). Quem chama já confirmou o descarte, ou não
     * havia nada mexido. O GET que falha deixa o aviso como estava, com o erro, para tentar de novo.
     */
    const recarregarRetrato = useCallback(async () => {
        setConflito((atual) => (atual ? { ...atual, confirmar: false, recarregando: true, erro: null } : atual));
        try {
            const novo = await abrirCadastro(escrita, produtoId);
            if (!vivo.current) return;
            versao.current = novo?.versao ?? null;
            agora.current.temMudanca = false;
            setRetrato(novo);
            setEstado(estadoDoRetrato(novo));
            setCustoPorCor((escolhido) => escolhido ?? !custoECaixasIguais(novo?.variacoes));
            setErros(SEM_ERROS);
            setErroGeral(null);
            setResultado(null);
            setNotaDoFrete(null);
            setConflito(null);
            setNota('Recarregado: aqui está o que está salvo agora.');
            onRecarregar();
        } catch (e) {
            if (vivo.current) {
                setConflito((atual) => ({ ...(atual ?? { texto: textoDoConflito(null) }), confirmar: false, recarregando: false, erro: mensagemDoErro(e) }));
            }
        }
    }, [escrita, produtoId, onRecarregar]);

    /** [Recarregar]: com algo mexido, pergunta antes (o mexido seria descartado); sem, relê na hora. */
    const pedirRecarga = useCallback(() => {
        const { temMudanca: mexido, conflito: atual } = agora.current;
        if (!atual || atual.recarregando) return;
        if (mexido) {
            setConflito({ ...atual, confirmar: true, erro: null });

            return;
        }
        recarregarRetrato();
    }, [recarregarRetrato]);

    /** "Cancelar" na pergunta: o aviso volta, com o mexido intacto. */
    const cancelarRecarga = useCallback(() => setConflito((atual) => (atual ? { ...atual, confirmar: false } : atual)), []);

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
        ordenarFotos,
        conferirFrete,
        notaDoFrete,
        // F4
        trocarEixo,
        mudarCor,
        mudarNasVariacoes,
        custoPorCor: !!custoPorCor,
        usarCustoIgual,
        usarCustoPorCor,
        adicionarVariacoes,
        removerVariacoes,
        avisoDaCor,
        fecharAvisoDaCor: () => setAvisoDaCor(null),
        exclusao,
        pedirExclusao,
        confirmarExclusao,
        cancelarExclusao,
        // F6
        conflito,
        pedirRecarga,
        confirmarRecarga: recarregarRetrato,
        cancelarRecarga,
    };
}
