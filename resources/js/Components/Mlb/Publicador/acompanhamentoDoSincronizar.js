// Acompanhamento do preenchimento dos rascunhos depois do "Sincronizar do Portal" (Fase 172-12,
// review 172 CR-01/WR-03). Sem React: quem usa é a PÁGINA, que não desmonta quando a lista recarrega
// (no botão, o polling morria junto com o botão do estado vazio).
//
// Um acompanhamento por vez: acompanhar um pedido novo cancela o anterior (o resumo velho nunca
// alterna com o novo). `cancelar` para tudo (fechar o painel, sair da página).

export const INTERVALO_MS = 2500;
export const LIMITE_MS = 5 * 60 * 1000;

/**
 * @param {object} o
 * @param {(pedido: string) => Promise<object>} o.ler       lê o resumo do pedido
 * @param {(resumo: object) => void} o.aoLer                 cada leitura que voltou do pedido ATUAL
 * @param {() => void} [o.aoExpirar]                         bateu o limite sem ficar pronto
 * @param {(ativo: boolean) => void} [o.aoMudar]             começou (true) ou parou (false) de acompanhar
 */
export function criarAcompanhamento({
    ler,
    aoLer,
    aoExpirar,
    aoMudar,
    intervalo = INTERVALO_MS,
    limite = LIMITE_MS,
    agora = () => Date.now(),
    agendar = (fn, ms) => setTimeout(fn, ms),
    desagendar = (t) => clearTimeout(t),
}) {
    let atual = null;

    function parar() {
        if (atual === null) return;
        desagendar(atual.t);
        atual = null;
        aoMudar?.(false);
    }

    function acompanhar(pedido) {
        if (atual !== null) desagendar(atual.t);
        const ctl = { pedido, inicio: agora(), t: null };
        atual = ctl;
        aoMudar?.(true);

        const volta = async () => {
            if (atual !== ctl) return;
            try {
                const data = await ler(pedido);
                if (atual !== ctl) return; // cancelado ou trocado enquanto a leitura voava
                aoLer(data);
                if (data?.status === 'pronto') {
                    parar();
                    return;
                }
            } catch {
                // 404 ou queda de rede: não desiste na 1ª falha, tenta na próxima volta.
            }
            if (atual !== ctl) return;
            if (agora() - ctl.inicio >= limite) {
                parar();
                aoExpirar?.();
                return;
            }
            ctl.t = agendar(volta, intervalo);
        };
        ctl.t = agendar(volta, 0);
    }

    return { acompanhar, cancelar: parar, ativo: () => atual !== null };
}
