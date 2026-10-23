import { useEffect, useRef, useState } from 'react';

// ═══════════════════════════════════════════════════════════════════════════
// 23/10/2026 (P-18a) — O CAMPO DE BUSCA DA FILA, COM RASCUNHO LOCAL.
//
// O defeito que ele conserta: o input de `FiltrosDaFila` era controlado pela busca
// que VOLTA do servidor, e cada tecla fazia uma visita (`router.get`) na hora.
//   · o servidor faz `trim` → "mesa " voltava "mesa": não dava para digitar o
//     espaço de "mesa jantar";
//   · digitando rápido, cada visita nova cancela a anterior e o campo voltava ao
//     valor velho ("cadeira" virava "ra");
//   · e cada tecla remontava a fila inteira no servidor.
//
// ➡️ A URL continua sendo a fonte do FILTRO (COL-03): este campo só guarda o que
// está sendo DIGITADO. O termo vai para a URL quando a pessoa para de digitar
// (`ATRASO_DA_BUSCA`) ou aperta Enter, com as pontas aparadas — o espaço do meio
// fica. A resposta à própria digitação não mexe no campo; uma busca que mudou por
// outro caminho (Limpar filtros, voltar no histórico, link aberto) é adotada.
//
// ⚠️ Por que um arquivo próprio: `FiltrosDaFila` é SEM ESTADO por gate (Fase 187)
// e o estado dos filtros não mora na página. O rascunho de digitação não é filtro.
// ⚠️ As classes NÃO moram aqui: o `className` vem de `FiltrosDaFila`, que é onde
// as medidas do protótipo (`:69`) são conferidas.
// ═══════════════════════════════════════════════════════════════════════════

/** Quanto o campo espera a pessoa parar de digitar antes de mexer na URL. */
export const ATRASO_DA_BUSCA = 350;

/** Texto, mesmo vazio, ou `''`. ⚠️ Nunca `String(valor)`, que LANÇA com `{toString:null}`. */
function textoDoCampo(valor) {
    return typeof valor === 'string' ? valor : '';
}

/**
 * O input da busca da fila.
 *
 * @param {object} props
 * @param {unknown} [props.valor] a busca que o SERVIDOR devolveu (já aparada)
 * @param {unknown} [props.onBusca] `(termo) => void` — vira `?busca=`. Sem ela o
 *   campo é só leitura
 * @param {unknown} [props.className] as classes do input (de `FiltrosDaFila`)
 * @param {unknown} [props.placeholder] o placeholder, que também é o `aria-label`
 */
export default function CampoDeBuscaDaFila({ valor = '', onBusca = null, className = undefined, placeholder = undefined }) {
    const doServidor = textoDoCampo(valor);
    const [texto, setTexto] = useState(doServidor);
    // O último termo que este campo mandou para a URL (aparado, como o servidor o devolve).
    const ultimoEnviado = useRef(doServidor);
    const relogio = useRef(null);
    // O handler MAIS RECENTE: o envio atrasado não pode usar o recorte de quando a
    // tecla foi apertada (um filtro trocado no meio seria desfeito).
    const aoBuscar = useRef(onBusca);
    aoBuscar.current = onBusca;

    const pararRelogio = () => {
        if (relogio.current === null) return;

        clearTimeout(relogio.current);
        relogio.current = null;
    };

    // A busca mudou por OUTRO caminho (não é a resposta à digitação deste campo):
    // o campo adota o valor do servidor e esquece o que estava para ser enviado.
    useEffect(() => {
        if (doServidor === ultimoEnviado.current) return;

        ultimoEnviado.current = doServidor;
        pararRelogio();
        setTexto(doServidor);
    }, [doServidor]);

    useEffect(() => () => pararRelogio(), []);

    const podeBuscar = typeof onBusca === 'function';

    const enviar = (atual) => {
        pararRelogio();

        // Só as PONTAS saem (é o que o servidor faria): "mesa jantar" chega inteiro.
        const termo = textoDoCampo(atual).trim();
        if (termo === ultimoEnviado.current) return;

        if (typeof aoBuscar.current !== 'function') return;

        ultimoEnviado.current = termo;
        aoBuscar.current(termo);
    };

    return (
        <input
            type="search"
            value={texto}
            onChange={podeBuscar ? (evento) => {
                const novo = textoDoCampo(evento?.target?.value);

                setTexto(novo);
                pararRelogio();
                relogio.current = setTimeout(() => enviar(novo), ATRASO_DA_BUSCA);
            } : undefined}
            onKeyDown={podeBuscar ? (evento) => {
                if (evento?.key === 'Enter') enviar(texto);
            } : undefined}
            readOnly={!podeBuscar}
            placeholder={placeholder}
            aria-label={placeholder}
            className={className}
        />
    );
}
