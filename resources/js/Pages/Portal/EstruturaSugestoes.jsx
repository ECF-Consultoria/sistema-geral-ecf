import { useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, ChevronUp, Loader2, Plus, Tag, Truck } from 'lucide-react';
import PortalClienteLayout from '@/Layouts/PortalClienteLayout';
import { AvisoFlash, Botao, Paginacao } from '@/Components/Portal/Estrutura/comum';
import ComoFunciona from '@/Components/Portal/Estrutura/ComoFunciona';
import Janela from '@/Components/Portal/Estrutura/Janela';
import LinhaSugestao from '@/Components/Portal/Estrutura/Sugestoes/LinhaSugestao';
import ResumoSugestoes from '@/Components/Portal/Estrutura/Sugestoes/ResumoSugestoes';
import BarraDeFiltros from '@/Components/Portal/Estrutura/Sugestoes/BarraDeFiltros';
import GrupoFamilia from '@/Components/Portal/Estrutura/Sugestoes/GrupoFamilia';
import AbasDasSugestoes from '@/Components/Portal/Estrutura/Sugestoes/AbasDasSugestoes';
import BarraDeMarcadas from '@/Components/Portal/Estrutura/Sugestoes/BarraDeMarcadas';
import PainelSemTipo from '@/Components/Portal/Estrutura/Sugestoes/PainelSemTipo';
import JanelaTipo from '@/Components/Portal/Estrutura/Sugestoes/JanelaTipo';
import ListaDescartadas from '@/Components/Portal/Estrutura/Sugestoes/ListaDescartadas';
import AvisoSugestoes from '@/Components/Portal/Estrutura/Sugestoes/AvisoSugestoes';
import MontarKitAMao from '@/Components/Portal/Estrutura/Sugestoes/MontarKitAMao';
import { destinoDaOfertaCriada } from '@/lib/portalSubmodulos';
import {
    aceitarMarcadas, alternarMarca, desfazerEdicao, descartarChaves, desmarcarVarias, editarCampo,
    deveSegurarVisita, estadoInicial, haEdicaoPendente, limparMarcacao, marcarVarias, podeAceitar,
} from '@/lib/sugestoesSelecao';
import {
    MSG_FALHA_REDE, MSG_GUARDA, msgLimiteDoLote, msgMarcamosPrimeiras, qualEstadoVazio, textoDescarte, textoRestauracao, textoResultadoAceite,
    corpoDaGeracao, textoTipoDefinido, textoAtualizado, filtroAtivo, filtrosDaAba,
    montarGrupos, alternarCombos, textoVerCombos, textoCombosCortados,
} from '@/lib/sugestoesEstrutura';
import { chegouPeloHistorico, definirGuardaDoVoltar } from '@/lib/guardaDoVoltar';
import { avisoDosFretes } from '@/lib/produtosFretes';
import { entradaAtual, passosAte } from '@/lib/produtosNavegacao';
import { cn } from '@/lib/utils';

// ─── Mapeamento Estrutural — Planejamento (sugestões de ofertas, Fase 168-14) ─
//
// D-01: a pessoa aceita uma ou várias e descarta; nada é criado sozinho.
// D-08: cada cartão mostra composição, o porquê, logística e frete estimado.
// D-19: nome e código editáveis só no navegador até Aceitar (limites 60/120 do servidor).
// D-20 (revisto em 09/10/2026): a tela virou o submódulo "Planejamento" do menu, logo depois de
// Produtos; continua entrando também por Produtos e pela Lista SKUs.
//
// SEM PLANILHA NA TELA (D-23 da 167): cartões, listas e janelas. Filtros, abas e páginas
// vão ao servidor (learnings §25/§27). A marcação e as edições ficam em `estado`, que
// sobrevive a trocar de filtro, aba e página (preserveState). Toda a lógica (marcar, editar,
// montar o pedido de aceite, aplicar o resultado) está em `sugestoesSelecao.js`: aqui só se
// liga a lib à tela. As abas "Sem tipo" e "Descartadas" têm o corpo no 168-15.
//
// Guarda de saída (learnings §32): nome ou código editado e ainda não aceito segura a saída
// da tela pelo fechar da aba (beforeunload), por link (router 'before') e pelo voltar do
// navegador (guardaDoVoltar, registrado no app.jsx ANTES do Inertia; nunca um popstate
// próprio aqui). Trocar filtro, aba ou página (mesmo caminho) não pergunta. Só marcar não
// conta como edição.
//
// Redesenho pela referência (168-19, D-24..D-31): trilha, título e "Atualizado agora"; quatro cartões
// de resumo; barra de filtros num container; abas Pendentes · Sem tipo · Descartadas com a seleção e
// "Aceitar selecionadas" à direita (a barra fixa só existe no celular); famílias em container, com
// cada sugestão em UMA linha. Só a apresentação mudou: a lógica e a guarda de saída são as mesmas.
//
// Fase 168-15: a aba "Sem tipo" (PainelSemTipo, D-12) grava o tipo por botão; a pílula de
// tipo da linha e o "Ajustar quantidades" abrem a mesma JanelaTipo (D-07). A aba
// "Descartadas" tem marcação PRÓPRIA (outra instância de estadoInicial) e a barra sem
// amarelo: restaurar não cria nada, só devolve à lista (D-01).
//
// 09/10/2026: "Montar kit" (MontarKitAMao) — a pessoa junta os produtos à mão, com a prévia do
// servidor; `?montar=<produto>` abre a janela com o produto já escolhido (vindo de Produtos).
// O "ver a oferta criada" leva à Lista SKUs só para quem a vê; o cliente vai à Precificação.

/** Cartão tracejado dos estados vazios (mesmo desenho do estado vazio de Produtos). */
function EstadoVazio({ titulo, corpo, children }) {
    return (
        <section className="mt-6 rounded-2xl border border-dashed border-white/[0.12] p-6 text-center" data-estado-vazio>
            <h2 className="text-[17px] font-semibold text-white">{titulo}</h2>
            {corpo && <p className="mx-auto mt-2 max-w-lg text-[14px] text-white/60">{corpo}</p>}
            {children && <div className="mt-4 flex flex-wrap items-center justify-center gap-2">{children}</div>}
        </section>
    );
}

const LINK_SECUNDARIO = 'inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-medium text-white/80 transition-colors hover:bg-white/[0.07] hover:text-white';
const LINK_PRIMARIO = 'inline-flex h-11 items-center justify-center gap-1.5 rounded-xl bg-ecf-yellow px-4 text-[13px] font-semibold text-black transition-colors hover:bg-ecf-yellow/90';

/**
 * Combos de uma família, recolhidos (08/10): uma linha "Ver N combos" no fim do grupo. Abrir
 * pede a mesma página ao servidor com a família em `combos`; os Combos chegam como as outras
 * sugestões (marcar, editar, aceitar e descartar iguais).
 */
function BlocoDeCombos({ chave, bloco, ocupado, onAlternar, children }) {
    const Icone = bloco.expandido ? ChevronUp : ChevronDown;

    return (
        <div data-bloco-combos={chave} className="rounded-[10px] border border-dashed border-white/[0.10] bg-white/[0.02]">
            <button type="button" data-acao="alternar-combos" onClick={onAlternar} disabled={ocupado} aria-expanded={bloco.expandido}
                className="flex min-h-[44px] w-full items-center justify-between gap-2 rounded-[10px] px-3 text-left text-[13px] text-white/75 transition-colors hover:bg-white/[0.04] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40 disabled:opacity-60 xl:min-h-10">
                <span>
                    {bloco.expandido ? 'Ocultar combos' : textoVerCombos(bloco.total)}
                    <span className="text-white/45"> · mesmo produto em mais unidades</span>
                </span>
                <Icone size={16} aria-hidden="true" />
            </button>
            {bloco.expandido && (
                <div className="space-y-1.5 p-1.5 pt-0">
                    {children}
                    {bloco.mostrando < bloco.total && (
                        <p data-combos-cortados className="px-2 py-1 text-[12px] text-white/55">{textoCombosCortados(bloco.mostrando, bloco.total)}</p>
                    )}
                </div>
            )}
        </div>
    );
}

export default function EstruturaSugestoes({ empresa, modulos = [], sugestoes, filtros, ml_conectado = false, vocabulario, montagem = null, montar_disponivel = false }) {
    const limites = sugestoes.limites;
    const [montarAberto, setMontarAberto] = useState(false);
    const [produtoMontar, setProdutoMontar] = useState(null);     // `?montar=<produto>`: entra já escolhido
    const [estado, setEstado] = useState(estadoInicial);
    const [errosPorChave, setErrosPorChave] = useState({});
    const [aceitando, setAceitando] = useState(() => new Set());   // chaves em aceite individual
    const [emLote, setEmLote] = useState(false);                   // aceite/descarte em lote em andamento
    const [aviso, setAviso] = useState(null);                      // { texto, erro?, acao? }
    const [busca, setBusca] = useState(filtros.q ?? '');
    const [visitando, setVisitando] = useState(false);
    const [aula, setAula] = useState(false);
    const [confirmaDescarte, setConfirmaDescarte] = useState(false);
    const [fretesCotados, setFretesCotados] = useState({});         // chave -> frete cotado no ML (D-18)
    const [consultando, setConsultando] = useState(false);
    const [aceitouNaSessao, setAceitouNaSessao] = useState(false);
    const [saida, setSaida] = useState(null);                       // pergunta de saída aberta: { visita } ou { voltar: true }
    const [estadoDesc, setEstadoDesc] = useState(estadoInicial);    // marcação própria da aba Descartadas
    const [tipoAberto, setTipoAberto] = useState(null);             // produto da JanelaTipo (null = fechada)
    const [gravandoTipo, setGravandoTipo] = useState(() => new Set());
    const [restaurando, setRestaurando] = useState(() => new Set());
    const [restaurandoLote, setRestaurandoLote] = useState(false);
    const [agora, setAgora] = useState(() => Date.now());             // relógio do "Atualizado há N min"
    const [atualizando, setAtualizando] = useState(false);
    const [recolhidos, setRecolhidos] = useState(() => new Set());       // famílias recolhidas (só em memória)
    const estadoRef = useRef(estado);
    estadoRef.current = estado;
    const liberado = useRef(false);                                 // "Sair sem aceitar" já confirmado

    const ehAbaSugestoes = sugestoes.aba === 'sugestoes';
    const ehAbaSemTipo = sugestoes.aba === 'sem_tipo';
    const ehAbaDescartadas = sugestoes.aba === 'descartadas';
    const itens = sugestoes.itens ?? [];
    const grupos = useMemo(() => montarGrupos(itens, sugestoes.grupos), [itens, sugestoes.grupos]);
    const marcadas = estado.marcadas;
    const marcadasDesc = estadoDesc.marcadas;
    const barraVisivel = ehAbaDescartadas ? marcadasDesc.length > 0 : (ehAbaSugestoes && marcadas.length > 0);
    // `produtos` chega indexado por id (objeto) ou como lista, conforme o JSON do servidor.
    const produtoDaPagina = (id) => Object.values(sugestoes.produtos ?? {}).find((p) => p.id === id) ?? null;

    // ─── Navegação dentro da tela (servidor) ────────────────────────────────

    // Os filtros "de agora": atualizados na hora do clique, para duas mudanças seguidas
    // (limpar filtros e a busca esvaziando) não pisarem uma na outra com as props antigas.
    // `combos`: famílias com os Combos abertos ("12,sem"), que seguem em toda visita da tela.
    const combosDosFiltros = (filtros.combos ?? []).join(',') || undefined;
    const filtrosRef = useRef({ fase: filtros.fase ?? undefined, familia: filtros.familia ?? undefined, tipo: filtros.tipo ?? undefined, status: filtros.status ?? undefined, q: filtros.q || undefined, combos: combosDosFiltros });
    useEffect(() => {
        filtrosRef.current = { fase: filtros.fase ?? undefined, familia: filtros.familia ?? undefined, tipo: filtros.tipo ?? undefined, status: filtros.status ?? undefined, q: filtros.q || undefined, combos: combosDosFiltros };
    }, [filtros.fase, filtros.familia, filtros.tipo, filtros.status, filtros.q, combosDosFiltros]);

    // "Atualizado há N min" anda sozinho, sem recarregar (D-31); recomeça quando a carga muda.
    useEffect(() => {
        setAgora(Date.now());
        const t = setInterval(() => setAgora(Date.now()), 30000);

        return () => clearInterval(t);
    }, [sugestoes.gerado_em]);

    const visitar = (mudancas = {}) => {
        const proximo = { ...filtrosRef.current, ...mudancas };
        filtrosRef.current = { fase: proximo.fase, familia: proximo.familia, tipo: proximo.tipo, status: proximo.status, q: proximo.q, combos: proximo.combos };
        const params = { ...(sugestoes.aba !== 'sugestoes' ? { aba: sugestoes.aba } : {}) };
        for (const [k, v] of Object.entries({ ...proximo, pagina: mudancas.pagina })) {
            if (v !== undefined && v !== null && v !== '') params[k] = v;
        }
        router.get(route('portal.auth.estrutura.sugestoes'), params, {
            preserveState: true, preserveScroll: true, replace: true, only: ['sugestoes', 'filtros'],
            onStart: () => setVisitando(true), onFinish: () => setVisitando(false),
        });
    };

    const primeiraBusca = useRef(true);
    useEffect(() => {
        if (primeiraBusca.current) { primeiraBusca.current = false; return undefined; }
        if ((busca || '') === (filtrosRef.current.q ?? '')) return undefined;
        const t = setTimeout(() => visitar({ q: busca || undefined }), 350);

        return () => clearTimeout(t);
    }, [busca]); // eslint-disable-line react-hooks/exhaustive-deps

    /** "Ver N combos" / "Ocultar combos": a mesma página, com a família aberta ou fechada. */
    const alternarBlocoDeCombos = (chave) => {
        const abertos = alternarCombos(String(filtrosRef.current.combos ?? '').split(',').filter(Boolean), chave);
        visitar({ combos: abertos.join(',') || undefined, pagina: sugestoes.paginacao.pagina });
    };

    const limparFiltros = () => {
        setBusca('');
        visitar({ fase: undefined, familia: undefined, tipo: undefined, status: undefined, q: undefined });
    };

    // Os filtros seguem junto na troca de aba; a página volta para 1 (mesmo caminho: a guarda não pergunta).
    const hrefAba = (aba) => route('portal.auth.estrutura.sugestoes', filtrosDaAba(filtros, aba));

    // ─── Marcação e edição (libs do 168-05) ─────────────────────────────────

    const marcar = (chave) => {
        const r = alternarMarca(estado, chave, limites.lote);
        setEstado(r.estado);
        if (r.recusou) setAviso({ texto: msgLimiteDoLote(limites.lote) });
    };

    /** Marca (ou desmarca, se já estão todas) as que podem ser aceitas dentre `chaves`. */
    const alternarVarias = (chaves) => {
        const aceitaveis = itens.filter((i) => chaves.includes(i.chave) && podeAceitar(i, estado, limites)).map((i) => i.chave);
        if (aceitaveis.length === 0) return;
        if (aceitaveis.every((c) => marcadas.includes(c))) {
            setEstado(desmarcarVarias(estado, aceitaveis));

            return;
        }
        const r = marcarVarias(estado, aceitaveis, limites.lote);
        setEstado(r.estado);
        if (r.recusadas.length > 0) setAviso({ texto: msgMarcamosPrimeiras(limites.lote) });
    };

    const marcarDoFiltro = () => {
        const r = marcarVarias(estado, sugestoes.chaves_filtradas ?? [], limites.lote);
        setEstado(r.estado);
        if (r.recusadas.length > 0 || (sugestoes.chaves_filtradas ?? []).length >= limites.lote) setAviso({ texto: msgMarcamosPrimeiras(limites.lote) });
    };

    const editar = (chave, campo, valor, sugerido) => setEstado((e) => editarCampo(e, chave, campo, valor, sugerido));
    const desfazer = (chave) => setEstado((e) => desfazerEdicao(e, chave));

    // ─── Escritas: aceitar e descartar ──────────────────────────────────────

    const recarregar = () => router.reload({ only: ['sugestoes'], preserveScroll: true });

    /** "Atualizar sugestões": recarrega só as sugestões; a seleção e as edições ficam (mesma instância). */
    const atualizar = () => router.reload({
        only: ['sugestoes'], preserveScroll: true,
        onStart: () => setAtualizando(true), onFinish: () => setAtualizando(false),
    });

    const alternarRecolhido = (chave) => setRecolhidos((s) => {
        const n = new Set(s);
        if (n.has(chave)) n.delete(chave); else n.add(chave);

        return n;
    });

    const enviarAceite = async (pedidos) => (await axios.post(route('portal.auth.estrutura.sugestoes.aceitar'), { sugestoes: pedidos })).data;
    const enviarDescarte = async (chaves) => (await axios.post(route('portal.auth.estrutura.sugestoes.descartar'), { chaves })).data;

    /** Aceita as chaves pela lib e mostra o resultado único; as com erro ficam e aparecem no cartão. */
    const aceitar = async (chaves) => {
        setAviso(null);
        const r = await aceitarMarcadas(estado, chaves, { enviar: enviarAceite, limite: limites.lote });
        if (r.falhaDeRede || (! r.resultado && Object.keys(r.errosPorChave).length === 0)) {
            setAviso({ erro: true, texto: MSG_FALHA_REDE });

            return;
        }
        setEstado(r.estado);
        setErrosPorChave((antes) => {
            const proximo = { ...antes };
            for (const c of chaves) delete proximo[c];

            return { ...proximo, ...r.errosPorChave };
        });
        if (r.resultado) {
            const criadas = r.resultado.criadas.length;
            if (criadas > 0) setAceitouNaSessao(true);
            // A Lista SKUs só para quem a vê; o cliente vai à Precificação (com o SKU, se for uma só).
            const destino = destinoDaOfertaCriada(modulos, criadas === 1 ? r.resultado.criadas[0].sku : null);
            setAviso({
                texto: textoResultadoAceite(r.resultado, destino.ondeFica),
                acao: criadas > 0 ? { rotulo: destino.rotulo, href: destino.href } : null,
            });
            recarregar();
        }
    };

    const aceitarUma = async (chave) => {
        setAceitando((s) => new Set(s).add(chave));
        try {
            await aceitar([chave]);
        } finally {
            setAceitando((s) => { const n = new Set(s); n.delete(chave); return n; });
        }
    };

    const aceitarMarcadasEmLote = async () => {
        setEmLote(true);
        try {
            await aceitar(marcadas);
        } finally {
            setEmLote(false);
        }
    };

    /** "Desfazer" do descarte de uma: devolve a sugestão à lista. */
    const desfazerDescarte = async (chave) => {
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.restaurar'), { chaves: [chave] });
            setAviso({ texto: textoRestauracao(data?.restauradas ?? 0, data?.ja_existem ?? 0) });
            recarregar();
        } catch {
            setAviso({ erro: true, texto: MSG_FALHA_REDE });
        }
    };

    const descartar = async (chaves) => {
        setAviso(null);
        const r = await descartarChaves(estado, chaves, { enviar: enviarDescarte });
        if (! r.resultado) {
            setAviso({ erro: true, texto: MSG_FALHA_REDE });

            return;
        }
        setEstado(r.estado);
        setAviso({
            texto: textoDescarte(chaves.length),
            acao: chaves.length === 1 ? { rotulo: 'Desfazer', onClick: () => desfazerDescarte(chaves[0]) } : null,
        });
        recarregar();
    };

    const descartarUma = async (chave) => {
        setAceitando((s) => new Set(s).add(chave));
        try {
            await descartar([chave]);
        } finally {
            setAceitando((s) => { const n = new Set(s); n.delete(chave); return n; });
        }
    };

    const descartarMarcadas = async () => {
        if (marcadas.length > 1) { setConfirmaDescarte(true); return; }
        setEmLote(true);
        try {
            await descartar(marcadas);
        } finally {
            setEmLote(false);
        }
    };

    const confirmarDescarte = async () => {
        setConfirmaDescarte(false);
        setEmLote(true);
        try {
            await descartar(marcadas);
        } finally {
            setEmLote(false);
        }
    };

    // ─── Sem tipo: definir o tipo e ajustar quantidades (D-07, D-12) ────────

    /** "Definir tipo" do cartão: grava só o tipo, mantendo as quantidades que o produto já tinha. */
    const definirTipo = async (produto, slug) => {
        if (! slug || gravandoTipo.has(produto.id)) return;
        setGravandoTipo((s) => new Set(s).add(produto.id));
        setAviso(null);
        try {
            await axios.put(route('portal.auth.estrutura.sugestoes.geracao', produto.id),
                corpoDaGeracao({ tipo: slug, qtdCombo: produto.qtd_combo ?? '', qtdCombit: produto.qtd_combit ?? '' }, sugestoes.tipos ?? []));
            const nome = (sugestoes.tipos ?? []).find((t) => t.slug === slug)?.nome ?? '';
            setAviso({ texto: textoTipoDefinido(nome) });
            recarregar();
        } catch (e) {
            const erros = e?.response?.data?.errors;
            const primeiro = erros ? Object.values(erros).flat()[0] : null;
            setAviso({ erro: true, texto: primeiro ?? MSG_FALHA_REDE });
        } finally {
            setGravandoTipo((s) => { const n = new Set(s); n.delete(produto.id); return n; });
        }
    };

    const tipoSalvo = (texto) => {
        setTipoAberto(null);
        setAviso({ texto });
        recarregar();
    };

    // ─── Descartadas: marcar e restaurar (D-01) ─────────────────────────────

    const marcarDescartada = (chave) => {
        const r = alternarMarca(estadoDesc, chave, limites.lote);
        setEstadoDesc(r.estado);
        if (r.recusou) setAviso({ texto: msgLimiteDoLote(limites.lote) });
    };

    /** Restaura uma ou várias; as que já viraram oferta não voltam e a pessoa é avisada. */
    const restaurar = async (chaves) => {
        setAviso(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.restaurar'), { chaves });
            setAviso({ texto: textoRestauracao(data?.restauradas ?? 0, data?.ja_existem ?? 0) });
            setEstadoDesc((e) => desmarcarVarias(e, chaves));
            recarregar();
        } catch {
            setAviso({ erro: true, texto: MSG_FALHA_REDE });
        }
    };

    const restaurarUma = async (chave) => {
        setRestaurando((s) => new Set(s).add(chave));
        try {
            await restaurar([chave]);
        } finally {
            setRestaurando((s) => { const n = new Set(s); n.delete(chave); return n; });
        }
    };

    /** Marca (ou desmarca, se já estão todas) as descartadas desta página, com o mesmo teto. */
    const alternarPaginaDescartadas = () => {
        const chaves = itens.map((i) => i.chave);
        if (chaves.length === 0) return;
        if (chaves.every((c) => marcadasDesc.includes(c))) {
            setEstadoDesc(desmarcarVarias(estadoDesc, chaves));

            return;
        }
        const r = marcarVarias(estadoDesc, chaves, limites.lote);
        setEstadoDesc(r.estado);
        if (r.recusadas.length > 0) setAviso({ texto: msgMarcamosPrimeiras(limites.lote) });
    };

    const restaurarMarcadas = async () => {
        setRestaurandoLote(true);
        try {
            await restaurar(marcadasDesc);
        } finally {
            setRestaurandoLote(false);
        }
    };

    // ─── Guarda de saída (learnings §32) ────────────────────────────────────

    useEffect(() => {
        const aoFecharAba = (e) => {
            if (liberado.current || ! haEdicaoPendente(estadoRef.current)) return undefined;
            e.preventDefault();
            e.returnValue = '';

            return '';
        };
        window.addEventListener('beforeunload', aoFecharAba);
        const tirarGuarda = router.on('before', (event) => {
            const visita = event.detail.visit;
            const parcial = Boolean(visita.prefetch) || (visita.only?.length ?? 0) > 0 || (visita.except?.length ?? 0) > 0;
            const segurar = deveSegurarVisita({
                haEdicao: haEdicaoPendente(estadoRef.current), liberado: liberado.current,
                destino: String(visita.url), telaAtual: window.location.href, parcial,
            });
            if (! segurar) return true;
            setSaida({ visita });

            return false;
        });
        // A visita liberada terminou e a tela continua aqui (rede caiu, sessão expirou): a guarda volta a valer.
        const tirarFim = router.on('finish', () => { liberado.current = false; });

        return () => {
            window.removeEventListener('beforeunload', aoFecharAba);
            tirarGuarda();
            tirarFim();
        };
    }, []);

    // Voltar do navegador: o Inertia troca a página no popstate sem o evento `before`. Com edição
    // pendente, o popstate não chega ao Inertia, o histórico volta à entrada desta tela e a pergunta abre.
    const entradaDaTela = useRef(entradaAtual());
    const ignorarVolta = useRef(false);
    useEffect(() => definirGuardaDoVoltar((e) => {
        if (ignorarVolta.current) {
            ignorarVolta.current = false;
            e.stopImmediatePropagation();

            return;
        }
        if (liberado.current || ! haEdicaoPendente(estadoRef.current)) return;
        e.stopImmediatePropagation();
        ignorarVolta.current = true;
        setTimeout(() => { ignorarVolta.current = false; }, 1000);
        window.history.go(passosAte(entradaDaTela.current));
        setSaida({ voltar: true });
    }), []);

    // Voltar do navegador até aqui: o Inertia devolve as props da data da visita (sugestão já aceita ou
    // descartada continuaria na tela). Quem chegou pelo histórico pede as sugestões de agora.
    useEffect(() => {
        if (chegouPeloHistorico()) router.reload({ only: ['sugestoes'], preserveScroll: true });
    }, []);

    // Página devolvida pelo cache do navegador: o React não monta de novo, então as sugestões de agora vêm por recarga parcial.
    useEffect(() => {
        const aoMostrar = (e) => {
            if (e.persisted) router.reload({ only: ['sugestoes'], preserveScroll: true });
        };
        window.addEventListener('pageshow', aoMostrar);

        return () => window.removeEventListener('pageshow', aoMostrar);
    }, []);

    // ─── Montar kit à mão (09/10/2026) ──────────────────────────────────────

    // `?montar=<produto>` (o atalho de Produtos): abre a janela com o produto já escolhido. O
    // parâmetro sai da URL depois de o Inertia gravar o estado inicial, para um F5 não reabrir.
    useEffect(() => {
        const url = new URL(window.location.href);
        const id = Number(url.searchParams.get('montar'));
        if (! id) return;
        setProdutoMontar(id);
        setMontarAberto(true);
        url.searchParams.delete('montar');
        setTimeout(() => window.history.replaceState(window.history.state, '', url), 100);
    }, []);

    const abrirMontagem = () => {
        setProdutoMontar(null);
        setMontarAberto(true);
    };

    // O catálogo da janela só vem quando ela pede (pode ter milhares de linhas).
    const carregarCatalogo = () => router.reload({ only: ['montagem'], preserveScroll: true });

    const montagemCriada = () => {
        setAceitouNaSessao(true);
        // A combinação pode ter sido uma sugestão pendente: ela sai da lista. O catálogo segue igual.
        recarregar();
    };

    const sairSemAceitar = () => {
        const pedido = saida;
        liberado.current = true;
        setSaida(null);
        if (pedido?.voltar) {
            window.history.back();

            return;
        }
        if (pedido?.visita) router.visit(String(pedido.visita.url), { method: pedido.visita.method, data: pedido.visita.data, replace: pedido.visita.replace });
    };

    // ─── Frete real pela conta do cliente (D-18): só quando a pessoa pede ────

    const chavesMe2 = itens.filter((i) => i.logistica?.chave === 'me2' || i.logistica?.chave === 'me2_full').map((i) => i.chave);

    const consultarFretes = async () => {
        if (consultando || chavesMe2.length === 0) return;
        setConsultando(true);
        setAviso(null);
        try {
            const { data } = await axios.post(route('portal.auth.estrutura.sugestoes.frete'), { chaves: chavesMe2 });
            setFretesCotados((antes) => ({ ...antes, ...(data?.fretes ?? {}) }));
            const resultado = data?.falhou ? 'falhou' : (Number(data?.pendentes) > 0 ? 'parcial' : 'ok');
            setAviso({ texto: avisoDosFretes({ resultado, status: null }), erro: resultado === 'falhou' });
        } catch (e) {
            setAviso({ texto: avisoDosFretes({ resultado: 'erro', status: e?.response?.status ?? null }), erro: true });
        } finally {
            setConsultando(false);
        }
    };

    // ─── Tela ───────────────────────────────────────────────────────────────

    // Há filtro aplicado (fase, família, tipo, busca ou status)? Decide o estado vazio e o "Marcar todas do filtro".
    const comFiltro = filtroAtivo(filtros);
    const naPagina = itens.filter((i) => podeAceitar(i, estado, limites));
    const todasDaPaginaMarcadas = naPagina.length > 0 && naPagina.every((i) => marcadas.includes(i.chave));
    const todasDescPaginaMarcadas = itens.length > 0 && itens.every((i) => marcadasDesc.includes(i.chave));
    const vazio = ehAbaSugestoes
        // Um bloco de Combos recolhido também é conteúdo da página.
        ? qualEstadoVazio({ temProdutos: sugestoes.tem_produtos, contagens: sugestoes.contagens, filtroAtivo: comFiltro, aceitouNaSessao, qtdItens: itens.length + grupos.filter((g) => g.bloco).length })
        : null;
    const textoDeAtualizacao = textoAtualizado(sugestoes.gerado_em, agora);
    const horaExata = sugestoes.gerado_em ? new Date(sugestoes.gerado_em).toLocaleString('pt-BR') : undefined;

    return (
        <PortalClienteLayout empresa={empresa} modulos={modulos} titulo="Planejamento">
            <div className={cn('mx-auto w-full max-w-[1600px] px-4 pt-6 sm:px-6 lg:pl-10 lg:pr-8 lg:pt-11', barraVisivel ? 'pb-28 lg:pb-10' : 'pb-10')} data-sugestoes-pagina>
                <header data-cabecalho-sugestoes>
                    <nav aria-label="Caminho" data-trilha className="flex items-center gap-2 text-[13px] text-white/65">
                        <Link href={route('portal.auth.estrutura.produtos')} data-acao="voltar-produtos" className="hover:text-white">Produtos</Link>
                        <ChevronRight size={13} aria-hidden="true" className="text-white/40" />
                        <span className="truncate text-white">Planejamento</span>
                    </nav>
                    <div className="mt-3 flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="grid h-[42px] w-[42px] shrink-0 place-items-center rounded-[10px] bg-white/[0.04] text-ecf-yellow">
                                <Tag size={20} aria-hidden="true" />
                            </span>
                            <div className="min-w-0">
                                <h1 className="font-display text-[26px] font-bold leading-tight text-white">Planejamento</h1>
                                <p className="mt-0.5 max-w-[900px] text-[14px] leading-relaxed text-white/65">
                                    O sistema encontrou combinações possíveis de produtos para aumentar suas vendas.
                                </p>
                            </div>
                        </div>
                        <div className="flex shrink-0 flex-wrap items-center gap-4">
                            {(sugestoes.tem_produtos || montar_disponivel) && (
                                <Botao variante="secundario" onClick={abrirMontagem} data-acao="montar-kit" className="h-11 lg:h-9">
                                    <Plus size={15} aria-hidden="true" /> Montar kit
                                </Botao>
                            )}
                            <Botao variante="fantasma" onClick={() => setAula(true)} data-acao="como-funciona" className="h-9">Como funciona</Botao>
                            {textoDeAtualizacao && (
                                <span data-atualizado title={horaExata} className="inline-flex items-center gap-2 text-[13px] text-white/70">
                                    <span aria-hidden="true" className="h-2 w-2 rounded-full bg-emerald-400" />
                                    {textoDeAtualizacao}
                                </span>
                            )}
                        </div>
                    </div>
                </header>

                {vazio === 'sem_produtos' && (
                    <EstadoVazio titulo="Cadastre seus produtos primeiro"
                        corpo="As sugestões nascem dos produtos que você cadastrou. Cadastre ao menos um produto com família, ambiente e medidas.">
                        <Link href={route('portal.auth.estrutura.produtos')} className={LINK_PRIMARIO} data-acao="ir-para-produtos">Ir para Produtos</Link>
                        {/* Ofertas sem produto cadastrado (importadas) também se juntam à mão. */}
                        {montar_disponivel && (
                            <button type="button" onClick={abrirMontagem} className={LINK_SECUNDARIO} data-acao="montar-kit-vazio">Montar kit</button>
                        )}
                    </EstadoVazio>
                )}

                {sugestoes.tem_produtos && (
                    <>
                        <div className="mt-4">
                            <ResumoSugestoes resumo={sugestoes.resumo} />
                        </div>
                        <div className="mt-4">
                            <BarraDeFiltros sugestoes={sugestoes} filtros={filtros} aba={sugestoes.aba} busca={busca} onBusca={setBusca}
                                onFiltro={(m) => visitar(m)} onLimpar={limparFiltros} atualizando={atualizando} onAtualizar={atualizar} />
                        </div>
                    </>
                )}

                <div className="mt-4">
                    {ehAbaDescartadas ? (
                        <AbasDasSugestoes aba={sugestoes.aba} contagens={sugestoes.contagens} hrefAba={hrefAba}
                            selecionadas={marcadasDesc.length} naPagina={itens.length} todasDaPagina={todasDescPaginaMarcadas}
                            onMarcarPagina={alternarPaginaDescartadas} onLimpar={() => setEstadoDesc(limparMarcacao(estadoDesc))}
                            ocupada={restaurandoLote} onRestaurar={restaurarMarcadas} />
                    ) : (
                        <AbasDasSugestoes aba={sugestoes.aba} contagens={sugestoes.contagens} hrefAba={hrefAba}
                            selecionadas={marcadas.length} naPagina={naPagina.length} todasDaPagina={todasDaPaginaMarcadas}
                            onMarcarPagina={() => alternarVarias(itens.map((i) => i.chave))} onLimpar={() => setEstado(limparMarcacao(estado))}
                            ocupada={emLote} onAceitar={aceitarMarcadasEmLote} onDescartar={descartarMarcadas}
                            filtroAtivo={comFiltro} totalDoFiltro={(sugestoes.chaves_filtradas ?? []).length}
                            limiteDoLote={limites.lote} onMarcarFiltro={marcarDoFiltro} />
                    )}
                </div>

                {ehAbaSugestoes && sugestoes.tem_produtos && (
                    <>
                        {sugestoes.excedeu_teto && (
                            <p className="mt-4 rounded-xl border border-amber-400/20 bg-amber-500/[0.06] px-3 py-2 text-[12px] text-amber-200/90" data-faixa-teto>
                                Há muitas sugestões. Mostramos as primeiras {sugestoes.teto}. Escolha uma família para ver o restante.
                            </p>
                        )}

                        <div className={cn('mt-4 space-y-[13px]', visitando && 'opacity-60')} aria-busy={visitando} data-lista-sugestoes>
                            {grupos.map((g, indice) => {
                                // Visíveis = o que está na tela: Kits/Combits e os Combos do bloco aberto.
                                const visiveis = [...g.itens, ...(g.combos ?? [])];
                                const aceitaveis = visiveis.filter((i) => podeAceitar(i, estado, limites));
                                const linha = (item) => (
                                    <LinhaSugestao key={item.chave} sugestao={item} estado={estado} limites={limites} vocabulario={vocabulario}
                                        marcada={marcadas.includes(item.chave)} aceitando={aceitando.has(item.chave)} bloqueado={emLote}
                                        erro={errosPorChave[item.chave] ?? null} freteCotado={fretesCotados[item.chave] ?? null}
                                        onMarcar={marcar} onEditar={editar} onDesfazer={desfazer}
                                        onAceitar={aceitarUma} onDescartar={descartarUma}
                                        onTipo={(id) => setTipoAberto(produtoDaPagina(id))} />
                                );

                                return (
                                    <GrupoFamilia key={`${g.chave}-${indice}`} chave={g.chave} nome={g.nome} semFamilia={g.chave === 'sem'}
                                        ambientes={sugestoes.familia_ambientes?.[g.chave] ?? []}
                                        total={sugestoes.familia_totais?.[g.chave] ?? visiveis.length} naPagina={visiveis.length}
                                        continua={indice === 0 && sugestoes.familia_continua !== null && sugestoes.familia_continua !== undefined && String(sugestoes.familia_continua) === g.chave}
                                        todasMarcadas={aceitaveis.length > 0 && aceitaveis.every((i) => marcadas.includes(i.chave))}
                                        podeMarcar={aceitaveis.length > 0}
                                        recolhido={recolhidos.has(g.chave)} onAlternar={() => alternarRecolhido(g.chave)}
                                        onMarcarTodas={() => alternarVarias(visiveis.map((i) => i.chave))}>
                                        {g.itens.map(linha)}
                                        {g.bloco && (
                                            <BlocoDeCombos chave={g.chave} bloco={g.bloco} ocupado={visitando} onAlternar={() => alternarBlocoDeCombos(g.chave)}>
                                                {(g.combos ?? []).map(linha)}
                                            </BlocoDeCombos>
                                        )}
                                    </GrupoFamilia>
                                );
                            })}
                        </div>

                        {itens.length > 0 && (
                            <div data-rodape-frete className="mt-3 flex items-center text-[12px]">
                                {ml_conectado && chavesMe2.length > 0 && (
                                    <button type="button" onClick={consultarFretes} disabled={consultando} data-acao="consultar-fretes"
                                        className="inline-flex min-h-[44px] items-center justify-center gap-1.5 rounded-xl px-3 text-[12px] font-medium text-white/60 transition-colors hover:bg-white/[0.05] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40 disabled:pointer-events-none disabled:opacity-40 lg:min-h-9">
                                        {consultando ? <Loader2 size={14} className="animate-spin" aria-hidden="true" /> : <Truck size={14} aria-hidden="true" />}
                                        {consultando ? 'Consultando…' : 'Consultar fretes desta página no Mercado Livre'}
                                    </button>
                                )}
                                {! ml_conectado && <p className="text-white/55" data-nota-frete>Conecte sua conta do Mercado Livre para ver o frete real.</p>}
                            </div>
                        )}

                        {vazio === 'filtro_vazio' && (
                            <EstadoVazio titulo="Nada com esses filtros.">
                                <Botao variante="secundario" onClick={limparFiltros} className="h-11" data-acao="limpar-filtros-vazio">Limpar filtros</Botao>
                            </EstadoVazio>
                        )}
                        {vazio === 'sem_sugestoes' && (
                            <EstadoVazio titulo="Ainda não há sugestões novas"
                                corpo="Para sugerir Kit e Combit, os produtos precisam de família, ambiente e tipo. Veja a aba Sem tipo, cadastre mais produtos ou monte o kit você mesmo.">
                                {(sugestoes.contagens?.sem_tipo ?? 0) > 0 && (
                                    <Link href={hrefAba('sem_tipo')} className={LINK_SECUNDARIO} data-acao="ver-sem-tipo">Ver Sem tipo</Link>
                                )}
                                <button type="button" onClick={abrirMontagem} className={LINK_SECUNDARIO} data-acao="montar-kit-vazio">Montar kit</button>
                            </EstadoVazio>
                        )}
                        {vazio === 'tudo_revisado' && (() => {
                            // Só leva à Lista SKUs quem a vê; o cliente segue para a Precificação.
                            const destino = destinoDaOfertaCriada(modulos);

                            return (
                                <EstadoVazio titulo="Você revisou todas as sugestões" corpo={`As ofertas aceitas já estão ${destino.ondeFica}.`}>
                                    <Link href={destino.href} className={LINK_SECUNDARIO} data-acao="ver-ofertas-aceitas">{destino.rotulo}</Link>
                                    <button type="button" onClick={abrirMontagem} className={LINK_SECUNDARIO} data-acao="montar-kit-vazio">Montar kit</button>
                                </EstadoVazio>
                            );
                        })()}

                        {sugestoes.paginacao.paginas > 1 && (
                            <div className="mt-6">
                                <Paginacao rotulo="sugestões" paginacao={sugestoes.paginacao} onIr={(pagina) => visitar({ pagina })} />
                            </div>
                        )}
                    </>
                )}

                {ehAbaSemTipo && sugestoes.tem_produtos && (
                    <PainelSemTipo produtos={sugestoes.produtos_sem_tipo ?? []} tipos={sugestoes.tipos ?? []} paginacao={sugestoes.paginacao}
                        gravando={gravandoTipo} visitando={visitando} onDefinir={definirTipo}
                        onAjustar={(produto) => setTipoAberto(produto)} onIr={(pagina) => visitar({ pagina })} />
                )}

                {ehAbaDescartadas && sugestoes.tem_produtos && (
                    <>
                        <ListaDescartadas itens={itens} marcadas={marcadasDesc} restaurando={restaurando} bloqueado={restaurandoLote}
                            visitando={visitando} onMarcar={marcarDescartada} onRestaurar={restaurarUma} />
                        {sugestoes.paginacao.paginas > 1 && (
                            <div className="mt-6">
                                <Paginacao rotulo="sugestões" paginacao={sugestoes.paginacao} onIr={(pagina) => visitar({ pagina })} />
                            </div>
                        )}
                    </>
                )}
            </div>

            {ehAbaSugestoes && (
                <BarraDeMarcadas total={marcadas.length} ocupada={emLote} onLimpar={() => setEstado(limparMarcacao(estado))}
                    onAceitar={aceitarMarcadasEmLote} onDescartar={descartarMarcadas} />
            )}
            {ehAbaDescartadas && (
                <BarraDeMarcadas variante="descartadas" total={marcadasDesc.length} ocupada={restaurandoLote}
                    onLimpar={() => setEstadoDesc(limparMarcacao(estadoDesc))} onRestaurar={restaurarMarcadas} />
            )}

            <JanelaTipo produto={tipoAberto} tipos={sugestoes.tipos ?? []} onFechar={() => setTipoAberto(null)} onSalvo={tipoSalvo} />

            <MontarKitAMao aberta={montarAberto} onFechar={() => setMontarAberto(false)} catalogo={montagem}
                onCarregar={carregarCatalogo} produtoInicial={produtoMontar} vocabulario={vocabulario} onCriada={montagemCriada} />

            <Janela aberta={confirmaDescarte} onFechar={() => setConfirmaDescarte(false)} titulo={`Descartar ${marcadas.length} sugestões?`}
                descricao="Elas saem da lista e não voltam sozinhas. Você pode restaurá-las na aba Descartadas.">
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Botao variante="secundario" onClick={() => setConfirmaDescarte(false)} className="h-11">Cancelar</Botao>
                    <Botao variante="primario" onClick={confirmarDescarte} className="h-11" data-acao="confirmar-descarte">Descartar {marcadas.length}</Botao>
                </div>
            </Janela>

            <ComoFunciona aberta={aula} onFechar={() => setAula(false)} passos={[
                'Combo: o mesmo produto em mais unidades — Kit 4 cadeiras.',
                'Kit: produtos diferentes juntos — mesa + banco.',
                'Combit: um kit com mais unidades de um item — mesa + 4 cadeiras.',
                'Confira o nome e o código de cada sugestão, aceite o que fizer sentido e descarte o resto. Nada é criado sozinho.',
            ]} />
            <Janela aberta={saida !== null} onFechar={() => setSaida(null)} titulo={MSG_GUARDA}>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Botao variante="secundario" onClick={sairSemAceitar} className="h-11" data-acao="sair-sem-aceitar">Sair sem aceitar</Botao>
                    <Botao variante="primario" onClick={() => setSaida(null)} className="h-11" data-acao="continuar-editando">Continuar editando</Botao>
                </div>
            </Janela>
            <AvisoSugestoes aviso={aviso} onFechar={() => setAviso(null)} />
            <AvisoFlash />
        </PortalClienteLayout>
    );
}
