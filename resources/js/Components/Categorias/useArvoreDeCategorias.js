import { useEffect, useRef, useState } from 'react';
import { NIVEL_VAZIO, nivelDeAbertura } from '@/lib/arvoreDeCategorias';

// ─── O estado da navegação pelas categorias ─────────────────────────────────
//
// Um nível por vez, lido por `buscarNivel(pai)` (cada tela passa a sua rota). O que já
// foi lido fica guardado enquanto a navegação está aberta: voltar pela trilha não lê de
// novo. `inicio` é a categoria já escolhida — a navegação abre no nível dela (ou no de
// cima, quando ela é a última do ramo), em vez de recomeçar do zero.

export default function useArvoreDeCategorias(buscarNivel, { inicio = null } = {}) {
    const [pai, setPai] = useState(inicio ? String(inicio) : null);
    const [nivel, setNivel] = useState(NIVEL_VAZIO);
    const [estado, setEstado] = useState('carregando');     // carregando | pronto | indisponivel
    const [tentativa, setTentativa] = useState(0);
    const lidos = useRef(new Map());
    const buscar = useRef(buscarNivel);
    buscar.current = buscarNivel;
    const abrindo = useRef(!! inicio);

    useEffect(() => {
        let vivo = true;
        const chave = pai ?? '';

        const mostrar = (dados) => {
            // Só na abertura: a categoria já escolhida é a última do ramo → sobe um nível.
            if (abrindo.current) {
                abrindo.current = false;
                const destino = nivelDeAbertura(dados);
                if (destino !== undefined) { setPai(destino); return; }
            }
            setNivel(dados);
            setEstado('pronto');
        };

        const guardado = lidos.current.get(chave);
        if (guardado) { mostrar(guardado); return undefined; }

        setEstado('carregando');
        Promise.resolve()
            .then(() => buscar.current(pai))
            .then((dados) => {
                if (! vivo) return;
                if (! dados || dados.indisponivel) {
                    // A categoria de abertura não respondeu: recomeça do 1º nível em vez de travar.
                    if (abrindo.current && pai !== null) { abrindo.current = false; setPai(null); return; }
                    setEstado('indisponivel');

                    return;
                }
                lidos.current.set(chave, dados);
                mostrar(dados);
            })
            .catch(() => { if (vivo) setEstado('indisponivel'); });

        return () => { vivo = false; };
    }, [pai, tentativa]);

    return { nivel, estado, pai, ir: (id) => setPai(id ? String(id) : null), tentarDeNovo: () => setTentativa((n) => n + 1) };
}
