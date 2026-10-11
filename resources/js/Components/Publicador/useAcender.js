import { useEffect, useRef } from 'react';
import { acender, apagar, chaveDoProblema, levarAte, piscar } from './destaque.js';

// ─── "Corrigir em…": mantém acesos os campos da etapa aberta ────────────────
//
// `problemas` = o que acender agora (lista vazia apaga tudo). `vez` muda a cada clique em
// "Corrigir em…": aí tudo pisca de novo e a tela vai ao campo de `foco` (a linha clicada) ou
// ao primeiro aceso; sem campo nenhum na tela, vai à lista do topo.
//
// O campo pode nascer depois (a etapa monta partes em seguida, uma variação é ligada): um
// observador reacende sempre que o conteúdo da etapa ganha ou perde elementos.

const RAIZ = 'conteudo-etapa';
const ESPERA = 50;

export default function useAcender({ problemas, vez = 0, foco = null, listaId = null }) {
    const atuais = useRef(problemas);
    atuais.current = problemas;
    const ativo = problemas.length > 0;

    // A cada desenho: a lista de problemas muda quando o servidor responde a um salvamento.
    useEffect(() => {
        const raiz = document.getElementById(RAIZ);
        if (! raiz) return;
        if (ativo) acender(raiz, atuais.current);
        else apagar(raiz);
    });

    useEffect(() => {
        const raiz = document.getElementById(RAIZ);
        if (! raiz || ! ativo || typeof MutationObserver === 'undefined') return undefined;
        let quadro = 0;
        const observador = new MutationObserver(() => {
            cancelAnimationFrame(quadro);
            quadro = requestAnimationFrame(() => acender(raiz, atuais.current));
        });
        observador.observe(raiz, { childList: true, subtree: true });

        return () => {
            observador.disconnect();
            cancelAnimationFrame(quadro);
            apagar(raiz);
        };
    }, [ativo]);

    useEffect(() => {
        if (! vez) return undefined;
        const t = setTimeout(() => {
            const raiz = document.getElementById(RAIZ);
            if (! raiz) return;
            const pares = acender(raiz, atuais.current);
            pares.forEach((par) => par.elementos.forEach(piscar));
            const doFoco = foco ? pares.find((par) => chaveDoProblema(par.problema) === foco)?.elementos[0] : null;
            const alvo = doFoco ?? pares.find((par) => par.elementos.length > 0)?.elementos[0] ?? null;
            if (alvo) levarAte(alvo);
            else if (listaId) document.getElementById(listaId)?.scrollIntoView({ block: 'center' });
        }, ESPERA);

        return () => clearTimeout(t);
    }, [vez]); // eslint-disable-line react-hooks/exhaustive-deps
}
