import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { criarRota, mensagemDe } from '@/Components/Publicador/apoio.js';

// ─── "Sugerir com IA" do painel "Criar Fase N" (§4 da ETAPA-3) ──────────────
//
// Põe na fila o pedido de TÍTULO e/ou DESCRIÇÃO do kit e acompanha até o
// resultado. O servidor responde 202 no POST e o GET é o polling; alvo nunca
// pedido responde `{status: 'nenhum'}`, nunca 404 (plano 175-06).
//
// ⚠️ Por que um arquivo NOVO e não o `useIaDoPublicador`/`usePublicador`:
// o acompanhamento de IA que vive no `usePublicador.js` é estado do EDITOR
// (produto aberto, rascunho carregado, `aplicarPalavras` escrevendo no rascunho
// em memória) e o painel não tem nada disso — ele vive na tela do Produto, pede
// ao rascunho do BASE e aplica em campos LOCAIS, que só existem até o Confirmar.
// Extrair aquele `useEffect` para um módulo compartilhado mexeria no editor, que
// está em produção, sem ganho nenhum. A disciplina é a mesma dos hooks irmãos
// (`useIaDoPublicador`, `useCriativosDoPublicador`, `useAcervoDaConta`):
// intervalo próprio, limite de tempo, só aceita a resposta do PRÓPRIO pedido,
// leitura que falha não para o acompanhamento, `clearInterval` no unmount.
//
// ⚠️ Diferença deliberada em relação ao `useIaDoPublicador`: **nada de
// sessionStorage**. Lá o id da análise é guardado para o acompanhamento
// sobreviver a um F5; aqui o painel é efêmero — um F5 fecha o painel e apaga
// todos os campos locais, então não há o que retomar. Guardar o pedido só
// criaria um acompanhamento órfão de um painel que não existe mais.

/** Os dois únicos alvos (`SugestaoKitIaService::ALVOS`). */
export const ALVOS = ['titulo', 'descricao'];

/** Mesmo intervalo dos hooks irmãos deste módulo. */
export const INTERVALO = 2500;

/**
 * Teto de espera. A sugestão do kit é UMA chamada de texto (não uma análise do
 * produto inteiro nem uma geração de imagem), então o teto é bem menor que os
 * 15 min do `useIaDoPublicador` — passar disso é fila travada, não IA pensando.
 */
export const LIMITE = 3 * 60 * 1000;

export const ERRO_PADRAO = 'A IA não conseguiu responder. Tente de novo.';
export const ERRO_DEMORA = 'A IA demorou demais. Tente de novo.';

const rotaDoPublicador = criarRota('mlb.anuncios.publicador', 'conta');

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/** Só string ou número do servidor viram identificador de pedido. */
const pedidoSeguro = (valor) => ((typeof valor === 'string' || typeof valor === 'number') ? String(valor) : null);

/**
 * Já passou do teto de espera?
 *
 * `desde` inválido conta como EXPIRADO de propósito: sem um instante de partida
 * confiável não há como limitar o acompanhamento, e polling infinito é pior que
 * um "tente de novo".
 */
export function expirou(desde, agora = Date.now(), limite = LIMITE) {
    if (typeof desde !== 'number' || !Number.isFinite(desde)) {
        return true;
    }

    return (agora - desde) > limite;
}

/**
 * O que fazer com UMA leitura de status, sem tocar em estado nenhum.
 *
 * @returns {{acao: 'ignorar'|'esperar'|'pronto'|'erro', valor?: string, erro?: string}}
 *   - `ignorar`: a resposta é de outro pedido (pedido antigo NUNCA sobrescreve);
 *   - `esperar`: ainda rodando, ou nunca pedido (`nenhum`);
 *   - `pronto`: `valor` é texto de verdade;
 *   - `erro`: `erro` é a mensagem do servidor, ou o texto padrão.
 *
 * ⚠️ `valor` que não é string (objeto, número, nulo, vazio) vira ERRO, nunca
 * texto: é exatamente o campo que cairia num `<textarea>` e, antes disso, num
 * `{valor}` do JSX — a lição da tela preta de 07/10.
 */
export function interpretarLeitura(dados, pedidoAtual) {
    const d = objetoSeguro(dados);
    const pedido = pedidoSeguro(d.pedido);
    const atual = pedidoSeguro(pedidoAtual);

    if (pedido !== null && atual !== null && pedido !== atual) {
        return { acao: 'ignorar' };
    }

    const status = typeof d.status === 'string' ? d.status : '';

    if (status === 'pronto') {
        const valor = typeof d.valor === 'string' ? d.valor : '';

        return valor.trim() === '' ? { acao: 'erro', erro: ERRO_PADRAO } : { acao: 'pronto', valor };
    }

    if (status === 'erro') {
        const erro = typeof d.erro === 'string' && d.erro.trim() !== '' ? d.erro : ERRO_PADRAO;

        return { acao: 'erro', erro };
    }

    return { acao: 'esperar' };
}

/**
 * UMA volta do acompanhamento: lê o estado de cada alvo pendente e devolve o
 * que fazer com ele. **Não tem React dentro** — é o núcleo testável do hook
 * (não há DOM nos testes deste projeto, então efeito de React não roda lá).
 *
 * @param {{pendentes: Object, ler: Function, agora?: number, limite?: number}} opcoes
 *   `pendentes[alvo] = {pedido, desde}`; `ler(alvo)` devolve o JSON do status.
 * @returns {Promise<Array<{alvo: string, acao: string, valor?: string, erro?: string, encerra: boolean}>>}
 *   `encerra` = pare de acompanhar este alvo.
 */
export async function umaVolta({ pendentes, ler, agora = Date.now(), limite = LIMITE }) {
    const resultados = [];

    for (const [alvo, info] of Object.entries(objetoSeguro(pendentes))) {
        // Flags calculadas DENTRO do laço: variável de escopo lida só aqui já foi
        // eliminada pelo Rollup no bundle deste projeto.
        const dadosDoPedido = objetoSeguro(info);

        if (expirou(dadosDoPedido.desde, agora, limite)) {
            // Alvo expirado não gasta mais uma leitura.
            resultados.push({ alvo, acao: 'erro', erro: ERRO_DEMORA, encerra: true });
            continue;
        }

        try {
            const lido = await ler(alvo);
            const decidido = interpretarLeitura(lido, dadosDoPedido.pedido ?? null);
            resultados.push({
                alvo,
                ...decidido,
                encerra: decidido.acao === 'pronto' || decidido.acao === 'erro',
            });
        } catch {
            // Uma leitura que falha não para o acompanhamento: tenta na próxima volta.
            resultados.push({ alvo, acao: 'esperar', encerra: false });
        }
    }

    return resultados;
}

/**
 * @param {{conta: string, produtoId: number, quantidade: number, onPronto?: Function}} opcoes
 *   `onPronto(alvo, valor)` é chamado UMA vez por pedido concluído; quem aplica
 *   o texto no campo é o painel (o hook não conhece campo nenhum).
 * @returns {{estados: Object, pedir: Function, limpar: Function}}
 *   `estados[alvo] = {status: 'rodando'|'pronto'|'erro', erro: ?string}`.
 */
export default function useSugestaoKitIa({ conta, produtoId, quantidade, onPronto }) {
    const [estados, setEstados] = useState({});
    // Os pedidos vivos ficam num REF: o intervalo é criado uma vez e não pode
    // fechar sobre um valor velho (mesmo truque do `palavrasRef` do usePublicador).
    const pedidos = useRef({});
    // Só para reiniciar o efeito quando um pedido novo entra na fila.
    const [geracao, setGeracao] = useState(0);
    const aoPronto = useRef(onPronto);
    aoPronto.current = onPronto;
    // Conta/produto/quantidade também por ref: a volta do intervalo monta a rota
    // com o valor ATUAL, não com o da renderização em que o intervalo nasceu.
    const alvoDaRota = useRef({ conta, produtoId, quantidade });
    alvoDaRota.current = { conta, produtoId, quantidade };

    const encerrar = useCallback((alvo) => {
        const resto = { ...pedidos.current };
        delete resto[alvo];
        pedidos.current = resto;
    }, []);

    const limpar = useCallback(() => {
        pedidos.current = {};
        setEstados({});
    }, []);

    const pedir = useCallback(async (alvo) => {
        if (!ALVOS.includes(alvo)) return;
        const { conta: c, produtoId: id, quantidade: n } = alvoDaRota.current;
        setEstados((atual) => ({ ...atual, [alvo]: { status: 'rodando', erro: null } }));
        try {
            const { data } = await axios.post(
                rotaDoPublicador('fases.ia', c, { produto: id }),
                { alvo, quantidade: n },
            );
            pedidos.current = {
                ...pedidos.current,
                [alvo]: { pedido: pedidoSeguro(objetoSeguro(data).pedido), desde: Date.now() },
            };
            setGeracao((g) => g + 1);
        } catch (e) {
            encerrar(alvo);
            setEstados((atual) => ({ ...atual, [alvo]: { status: 'erro', erro: mensagemDe(e) } }));
        }
    }, [encerrar]);

    useEffect(() => {
        if (Object.keys(pedidos.current).length === 0) return undefined;
        let vivo = true;

        const intervalo = setInterval(async () => {
            if (Object.keys(pedidos.current).length === 0) {
                clearInterval(intervalo);

                return;
            }

            const voltas = await umaVolta({
                pendentes: pedidos.current,
                ler: async (alvo) => {
                    const { conta: c, produtoId: id, quantidade: n } = alvoDaRota.current;
                    const { data } = await axios.get(
                        rotaDoPublicador('fases.ia.status', c, { produto: id, alvo, quantidade: n }),
                    );

                    return data;
                },
            });

            if (!vivo) return;

            for (const volta of voltas) {
                // Flags dentro do laço (armadilha do Rollup).
                const terminou = volta.encerra === true;
                if (!terminou) continue;
                encerrar(volta.alvo);
                if (volta.acao === 'pronto') {
                    setEstados((atual) => ({ ...atual, [volta.alvo]: { status: 'pronto', erro: null } }));
                    aoPronto.current?.(volta.alvo, volta.valor);
                } else {
                    setEstados((atual) => ({ ...atual, [volta.alvo]: { status: 'erro', erro: volta.erro } }));
                }
            }
        }, INTERVALO);

        return () => { vivo = false; clearInterval(intervalo); };
    }, [geracao, encerrar]);

    return { estados, pedir, limpar };
}
