import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import {
    categoriaParaConsulta, deveGravar, errosDaResposta, idDoElemento, montarAtributos, valoresIniciais,
} from '@/lib/fichaTecnica';

// ─── Estado da ficha técnica do produto ─────────────────────────────────────
//
// Busca os campos da categoria escolhida (com um respiro, para quem troca de
// categoria várias vezes seguidas), guarda o que a pessoa preenche e grava no
// "Salvar produto" — o PUT só acontece depois que o produto existe. Quem muda
// um campo avisa a ficha (`aoAlterar`), que então protege a saída sem salvar.
// As contas e a montagem do corpo do PUT ficam em `@/lib/fichaTecnica`.

const ESPERA_MS = 350;

export default function useFichaTecnica({ salvos = [], categoria = null, aoAlterar = () => {} }) {
    const [valores, setValores] = useState(() => valoresIniciais(salvos));
    const [definicao, setDefinicao] = useState(null);    // { grupos, categoria } | null
    const [carregando, setCarregando] = useState(false);
    const [indisponivel, setIndisponivel] = useState(false);
    const [tentativa, setTentativa] = useState(0);
    const [erros, setErros] = useState({});              // { [id]: mensagem }
    const [erroGeral, setErroGeral] = useState(null);
    const salvosRef = useRef(Array.isArray(salvos) ? salvos : []);
    const valoresRef = useRef(valores);
    valoresRef.current = valores;
    const definicaoRef = useRef(definicao);
    definicaoRef.current = definicao;
    const memoria = useRef(new Map());                   // categoria → grupos (sem repetir a consulta)

    const idCategoria = categoriaParaConsulta(categoria);

    useEffect(() => {
        if (! idCategoria) {
            setDefinicao(null);
            setCarregando(false);
            setIndisponivel(false);

            return undefined;
        }
        if (memoria.current.has(idCategoria)) {
            setDefinicao({ grupos: memoria.current.get(idCategoria), categoria: idCategoria });
            setIndisponivel(false);
            setCarregando(false);

            return undefined;
        }
        // Enquanto a nova categoria não chega, os campos da anterior saem da tela.
        setDefinicao(null);
        setIndisponivel(false);
        setCarregando(true);
        let vale = true;
        const espera = setTimeout(async () => {
            try {
                const { data } = await axios.get(route('portal.auth.estrutura.produtos.campos_categoria'), { params: { categoria: idCategoria } });
                if (! vale) return;
                const grupos = Array.isArray(data?.grupos) ? data.grupos : [];
                if (data?.indisponivel || grupos.length === 0) {
                    setIndisponivel(true);
                } else {
                    memoria.current.set(idCategoria, grupos);
                    setDefinicao({ grupos, categoria: idCategoria });
                }
            } catch (e) {
                if (vale) setIndisponivel(true);
            } finally {
                if (vale) setCarregando(false);
            }
        }, ESPERA_MS);

        return () => { vale = false; clearTimeout(espera); };
    }, [idCategoria, tentativa]);

    const tentarDeNovo = () => setTentativa((n) => n + 1);

    const mudar = (id, parte) => {
        aoAlterar();
        setValores((atual) => ({ ...atual, [id]: { valor: '', unidade: '', ...atual[id], ...parte } }));
        setErros((atual) => {
            if (! atual[id]) return atual;
            const { [id]: _tirado, ...resto } = atual;

            return resto;
        });
    };

    /**
     * Grava a ficha no produto já existente. Devolve { ok, pulou, salvos }.
     * `pulou`: nada a fazer (sem categoria, definição não carregada ou nada preenchido nem salvo antes).
     */
    const gravar = async (produtoId) => {
        const atual = definicaoRef.current;
        const atributos = montarAtributos(atual?.grupos, valoresRef.current);
        const jaTinhaSalvos = salvosRef.current.length > 0;
        if (! produtoId || ! deveGravar({ definicaoPronta: !! atual && atual.categoria === idCategoria, atributos, jaTinhaSalvos })) {
            return { ok: true, pulou: true, salvos: salvosRef.current };
        }
        setErros({});
        setErroGeral(null);
        try {
            const { data } = await axios.put(route('portal.auth.estrutura.produtos.ficha_tecnica', produtoId), { atributos });
            salvosRef.current = Array.isArray(data?.salvos) ? data.salvos : [];

            return { ok: true, pulou: false, salvos: salvosRef.current };
        } catch (e) {
            const { campos, geral } = errosDaResposta(e);
            setErros(campos);
            setErroGeral(geral);
            const primeiro = Object.keys(campos)[0];
            if (primeiro) {
                requestAnimationFrame(() => {
                    const el = document.getElementById(idDoElemento(primeiro));
                    el?.scrollIntoView?.({ block: 'center' });
                    el?.focus?.();
                });
            }

            return { ok: false, pulou: false, salvos: salvosRef.current };
        }
    };

    return {
        valores, definicao, carregando, indisponivel, erros, erroGeral,
        temCategoria: !! idCategoria,
        mudar, tentarDeNovo, gravar,
    };
}
