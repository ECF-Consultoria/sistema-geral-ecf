import { useCallback, useEffect, useRef, useState } from 'react';
import { buscarCategorias, nivelDaArvore, termosDoTitulo } from '@/lib/trilhaEscrita';
import { categoriaDaGaveta, sugestaoDaCategoria, textoDaBusca } from '@/lib/trilhaCadastro';

// ─── Trilha de produtos — a categoria do produto na gaveta (F2) ─────────────
//
// Sem visual (arquivo `.js`, sem classe do Tailwind). O desenho é o §5.4:
//
// 1. SUGERIDA + 1 CLIQUE. Com o produto sem categoria, ao abrir e a cada
//    mudança do nome (600 ms sem digitar, de 2 a 120 letras), sai a busca pelo
//    nome. Só a resposta do último pedido vale. A 1ª candidata que é a última
//    do caminho E aceita produto novo é a sugerida — e NADA é aplicado sozinho
//    (D-06): "Usar esta" é que põe a categoria no ESTADO da gaveta (ela grava
//    no "Salvar", pela linha). Com ela no estado, os campos da ficha e os termos
//    do título carregam (os campos, em `useFichaDaTrilha`; os termos, aqui).
// 2. BUSCAR ("Ver outras" é a mesma lista, com o nome já digitado) e NAVEGAR
//    na árvore nível a nível (`useArvoreDeCategorias`, com a rota da trilha).
// 3. TROCAR uma categoria que já estava na gaveta pede confirmação, dentro da
//    gaveta: os campos da ficha que a nova não tem saem no "Salvar".
//
// O catálogo fora nunca é erro aqui: a busca volta vazia e a tela diz "Não
// achei pelo nome" com as outras saídas; o 422 (o texto da busca) é outro caso.

const ESPERA_DA_SUGESTAO_MS = 600;
const ESPERA_DA_BUSCA_MS = 350;
const ESPERA_DOS_TERMOS_MS = 600;
const ERRO_DOS_TERMOS = 'Não deu para carregar os termos agora. O título pode ser digitado normalmente.';

const BUSCA_PARADA = { situacao: 'parada', candidatas: [], consulta: null, mudouEm: 0 };
const TERMOS_PARADOS = { categoria: null, dados: null, limite: null, carregando: false, erro: null };

/** O que a gaveta mostra antes de o hook existir (o teste de render desenha sem ele). */
export const CATEGORIA_PARADA = Object.freeze({
    sugestao: BUSCA_PARADA,
    sugerida: null,
    tentarSugestaoDeNovo: () => {},
    aberto: null,
    consulta: '',
    mudarConsulta: () => {},
    lista: BUSCA_PARADA,
    tentarListaDeNovo: () => {},
    inicioDaArvore: null,
    aberturaDaArvore: 0,
    buscarNivel: async () => null,
    abrirBusca: () => {},
    abrirNavegacao: () => {},
    fechar: () => {},
    escolher: () => {},
    troca: null,
    confirmarTroca: () => {},
    manterAtual: () => {},
    termos: TERMOS_PARADOS,
    tentarTermosDeNovo: () => {},
});

/**
 * A busca de categorias por um texto, com um respiro: só a resposta do último pedido vale (a de um nome antigo,
 * chegando depois, não toma o lugar). `situacao`: parada | curta | buscando | pronta | nada | recusada.
 */
function useBusca(escrita, texto, { ligada, espera }) {
    const [estado, setEstado] = useState(BUSCA_PARADA);
    const [tentativa, setTentativa] = useState(0);
    const pedido = useRef(0);
    const consulta = textoDaBusca(texto);

    useEffect(() => {
        const meu = ++pedido.current;
        if (!ligada) return undefined;
        if (!consulta) {
            setEstado({ ...BUSCA_PARADA, situacao: String(texto ?? '').trim() ? 'curta' : 'parada', mudouEm: Date.now() });

            return undefined;
        }
        setEstado((atual) => ({ ...atual, situacao: 'buscando' }));
        const t = setTimeout(async () => {
            try {
                const dados = await buscarCategorias(escrita, consulta);
                if (meu !== pedido.current) return;
                const candidatas = Array.isArray(dados?.categorias) ? dados.categorias : [];
                // Lista vazia vem com `indisponivel` nos dois casos (nada achado ou catálogo fora): a tela não afirma qual.
                setEstado({ situacao: candidatas.length ? 'pronta' : 'nada', candidatas, consulta, mudouEm: Date.now() });
            } catch (e) {
                if (meu !== pedido.current) return;
                // 422 é o texto da busca, não o catálogo: "Tentar de novo" igual não adiantaria.
                setEstado({ situacao: e?.response?.status === 422 ? 'recusada' : 'nada', candidatas: [], consulta, mudouEm: Date.now() });
            }
        }, espera);

        return () => clearTimeout(t);
    }, [ligada, consulta, tentativa]);

    return { ...estado, tentarDeNovo: () => setTentativa((n) => n + 1) };
}

/** Os termos mais buscados e o limite do título da categoria da tela (o nome vai junto para marcar os que o citam). */
function useTermos(escrita, categoriaId, nome) {
    const [estado, setEstado] = useState(TERMOS_PARADOS);
    const [tentativa, setTentativa] = useState(0);
    const pedido = useRef(0);
    const texto = String(nome ?? '').trim().slice(0, 120);

    useEffect(() => {
        const meu = ++pedido.current;
        if (!categoriaId) {
            setEstado(TERMOS_PARADOS);

            return undefined;
        }
        // O nome mudou (a mesma categoria): o limite e os termos de antes ficam até a resposta nova.
        setEstado((atual) => (atual.categoria === categoriaId
            ? { ...atual, carregando: true, erro: null }
            : { ...TERMOS_PARADOS, categoria: categoriaId, carregando: true }));
        const t = setTimeout(async () => {
            try {
                const dados = await termosDoTitulo(escrita, categoriaId, texto);
                if (meu !== pedido.current) return;
                setEstado({
                    categoria: categoriaId,
                    dados: dados?.indisponivel ? null : dados,
                    // O limite vale mesmo sem os termos (o servidor manda o da categoria, ou o padrão).
                    limite: Number(dados?.limite) > 0 ? Number(dados.limite) : null,
                    carregando: false,
                    erro: dados?.indisponivel ? ERRO_DOS_TERMOS : null,
                });
            } catch {
                if (meu !== pedido.current) return;
                setEstado((atual) => ({ ...atual, dados: null, carregando: false, erro: ERRO_DOS_TERMOS }));
            }
        }, ESPERA_DOS_TERMOS_MS);

        return () => clearTimeout(t);
    }, [categoriaId, texto, tentativa]);

    return { termos: estado, tentarDeNovo: () => setTentativa((n) => n + 1) };
}

/**
 * @param {{escrita: object, nome: string, categoria: ?{id: string}, ativo: boolean,
 *   onUsar: (categoria: {id: string, nome: string, caminho_texto: string}) => void}} opcoes
 *   `categoria`: a do estado da gaveta; `ativo`: falso no produto só para leitura ou com a categoria travada.
 */
export default function useCategoriaDaTrilha({ escrita, nome, categoria, ativo, onUsar }) {
    const temCategoria = !!categoria?.id;
    const [aberto, setAberto] = useState(null);             // null | 'buscar' | 'navegar'
    const [consulta, setConsulta] = useState('');
    const [inicioDaArvore, setInicioDaArvore] = useState(null);
    const [aberturaDaArvore, setAberturaDaArvore] = useState(0);
    const [troca, setTroca] = useState(null);               // a categoria nova esperando a confirmação

    // 1. A sugestão pelo nome: só sem categoria na gaveta.
    const sugestao = useBusca(escrita, nome, { ligada: ativo && !temCategoria, espera: ESPERA_DA_SUGESTAO_MS });
    const sugerida = !temCategoria && sugestao.situacao === 'pronta' ? sugestaoDaCategoria(sugestao.candidatas) : null;

    // 2. A lista de "Buscar"/"Ver outras": com o mesmo texto da sugestão, a resposta dela serve (nada sai de novo).
    const mesmaDaSugestao = !!sugestao.consulta && textoDaBusca(consulta) === sugestao.consulta
        && ['pronta', 'nada'].includes(sugestao.situacao);
    const propria = useBusca(escrita, consulta, { ligada: ativo && aberto === 'buscar' && !mesmaDaSugestao, espera: ESPERA_DA_BUSCA_MS });
    const lista = mesmaDaSugestao ? sugestao : propria;

    const { termos, tentarDeNovo: tentarTermosDeNovo } = useTermos(escrita, temCategoria ? categoria.id : null, nome);

    const buscarNivel = useCallback((pai) => nivelDaArvore(escrita, pai), [escrita]);

    const usar = (nova) => {
        onUsar(nova);
        setAberto(null);
        setTroca(null);
    };

    /** Uma categoria escolhida (sugerida, da lista ou da árvore). Trocar a que já está na gaveta pede confirmação. */
    const escolher = (bruta) => {
        const nova = categoriaDaGaveta(bruta);
        if (!nova) return;
        if (temCategoria && categoria.id === nova.id) {
            setAberto(null);

            return;
        }
        if (temCategoria) {
            setTroca(nova);

            return;
        }
        usar(nova);
    };

    return {
        sugestao,
        sugerida,
        tentarSugestaoDeNovo: sugestao.tentarDeNovo,
        aberto,
        consulta,
        mudarConsulta: setConsulta,
        lista,
        tentarListaDeNovo: propria.tentarDeNovo,
        inicioDaArvore,
        aberturaDaArvore,
        buscarNivel,
        /** "Buscar" e "Ver outras": a lista, começando pelo nome do produto. */
        abrirBusca: (texto = nome) => {
            setConsulta(String(texto ?? '').slice(0, 120));
            setAberto('buscar');
        },
        /** "Navegar": a árvore, no nível de `inicio` (a candidata que não é a última do caminho) ou do começo. */
        abrirNavegacao: (inicio = null) => {
            setInicioDaArvore(inicio ? String(inicio) : null);
            setAberturaDaArvore((n) => n + 1);
            setAberto('navegar');
        },
        fechar: () => setAberto(null),
        escolher,
        troca,
        confirmarTroca: () => troca && usar(troca),
        manterAtual: () => setTroca(null),
        termos,
        tentarTermosDeNovo,
    };
}
