import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { cn } from '@/lib/utils';
import { criarRota, mensagemDe } from '@/Components/Publicador/apoio.js';
import { textoSeguro } from './BarraDaConta';
import PainelCriarFase from './PainelCriarFase';
import SeloStatusProduto from './SeloStatusProduto';
import { haQuanto } from './tempo';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';
import ModalDetalheAnuncio from '@/Pages/Mlb/components/ModalDetalheAnuncio';

// D23: mesmo texto literal de AbasDaConta.jsx/ModoAnuncioTabs.jsx — há teste de
// fonte que procura essa string em outras telas; não variar.
const TITLE_SEM_COMPANY = 'Disponível só para empresas cadastradas no sistema';

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const BOTAO_TRAVADO = 'inline-flex h-10 cursor-not-allowed items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/40 opacity-60';
// O botão miúdo que cabe AO LADO do "estoque próprio · calculado do base: N"
// (quick 261009-uec, §6). Mesmas cores do secundário, na escala do texto de 11px.
const BOTAO_MINI = 'inline-flex h-7 items-center gap-1 whitespace-nowrap rounded-md border border-white/[0.10] bg-white/[0.03] px-2 text-[11px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow disabled:cursor-not-allowed disabled:text-white/40 disabled:opacity-60';
const CARTAO = 'rounded-xl bg-ecf-card p-4';
const TITULO_BLOCO = 'text-[11px] font-bold uppercase tracking-[0.05em] text-white/40';
const AMBAR = 'rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-[13px] font-normal text-amber-300';

// O mapa da §3 ("Não iniciada / Em preparação / Publicada / Com problema"). O
// SELO do estado continua sendo `SeloStatusProduto` (nenhum selo novo); isto é
// só o rótulo da FASE, por cima da chave que o servidor derivou.
const ROTULO_ESTADO_FASE = {
    nao_iniciada: 'Não iniciada',
    em_preparacao: 'Em preparação',
    publicada: 'Publicada',
    com_problema: 'Com problema',
};

const ROTULO_TIPO_HISTORICO = {
    publicacao: 'Publicação',
    criativos: 'Criativos aprovados',
    ia: 'IA preencheu',
    fase_criada: 'Fase criada',
    combo_vinculado: 'Combo vinculado',
};

const ROTULO_ORIGEM = {
    portal: 'Do Portal',
    publicador: 'Cadastrado aqui',
};

const rotaDoPublicador = criarRota('mlb.anuncios.publicador', 'conta');

// §6 (quick 261009-uec). Mesma frase do botão "Criar Fase N" para o mesmo
// motivo — a tela não recebeu a conta, então não há a quem pedir a ação.
const SEM_CONTA_PARA_AGIR = 'Esta tela não recebeu a conta do produto. Recarregue a página.';
const AJUDA_ESTOQUE_CALCULADO = 'O estoque deste kit passa a ser o do produto base dividido pelas unidades do kit, depósito por depósito.';

/** Só aceita number finito do servidor; qualquer outra forma cai em null (nunca derruba a tela). */
function numeroSeguro(valor) {
    return typeof valor === 'number' && Number.isFinite(valor) ? valor : null;
}

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
function objetoSeguro(valor) {
    return valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {};
}

/** Lista do servidor em forma segura; qualquer outra coisa vira `[]`. */
function listaSegura(valor) {
    return Array.isArray(valor) ? valor : [];
}

/** R$ com vírgula sem depender de Intl (o teste de render roda em Node puro). */
function moeda(valor) {
    const n = numeroSeguro(valor);

    return n === null ? '—' : 'R$ ' + n.toFixed(2).replace('.', ',');
}

/** Número decimal curto em pt-BR, sem Intl: 12.5 → "12,5". */
function decimal(valor) {
    const n = numeroSeguro(valor);

    return n === null ? null : String(n).replace('.', ',');
}

/**
 * Os 6 blocos da tela do Produto (§3 da ETAPA-3, Fase 175 plano 04):
 * cabeçalho, fases, ofertas no ar, histórico e as duas laterais (Criativos e
 * Mapeamento).
 *
 * Fica FORA de `Pages/Mlb/Publicador/Produto.jsx` de propósito, pela MESMA
 * razão documentada na SUMMARY da 173-06: aquela página só soma `AppLayout` +
 * `BarraDaConta` + `AbasDaConta` em volta deste painel, e `AppLayout` arrasta
 * sino de notificações, aviso de chamados, tema e Modo TV — árvore pesada
 * demais para o teste de render isolar um bloco desta tela.
 *
 * ⚠️ TODO campo do servidor passa por `textoSeguro()`/`numeroSeguro()` antes do
 * JSX (T-175-14). Esta tela expõe dezenas de campos novos de uma vez e a lição
 * de 07/10 é literal: um campo que chegou como OBJETO e foi renderizado como
 * texto derrubou a árvore React inteira em produção ("Objects are not valid as
 * a React child").
 *
 * ⚠️ Armadilha do Rollup deste projeto (feedback_rollup_map_scope_bug.md):
 * variável de escopo do componente lida DENTRO de `.map()` já foi eliminada no
 * bundle de produção. Toda flag usada dentro de um `.map()` é calculada no
 * próprio callback.
 *
 * O contrato da página chama o cabeçalho de `produto` (é o produto BASE da
 * família); o serviço devolve o mesmo objeto em `base` — os dois nomes são
 * aceitos aqui para que o payload cru do serviço também renderize.
 */
export default function PainelDoProduto({
    produto = null,
    base = null,
    fase_destacada: faseDestacada = null,
    fases = [],
    proxima_fase: proximaFase = null,
    ofertas = [],
    historico = [],
    criativos = [],
    mapeamento = null,
    abas = null,
    empresa = null,
    // Capacidade do servidor (chave do Creative Engine + `CreativePermissao`),
    // não escolha de ninguém: falso faz a caixa da capa do kit NÃO existir no
    // painel "Criar Fase N" — não uma caixa desabilitada.
    criativos_ia: criativosIa = false,
}) {
    const [mlbAberto, setMlbAberto] = useState(null);
    const [criarFaseAberto, setCriarFaseAberto] = useState(false);
    // §6 (quick 261009-uec), por kit: qual está em voo, qual já adotou nesta
    // sessão e o erro de cada um. Por `produto_id` porque a ação é POR KIT — um
    // combo adotar não diz nada sobre o irmão.
    const [adotandoEstoque, setAdotandoEstoque] = useState(null);
    const [estoqueAdotado, setEstoqueAdotado] = useState({});
    const [erroDoEstoque, setErroDoEstoque] = useState({});

    const p = objetoSeguro(produto ?? base);
    const nome = textoSeguro(p.nome, 'Produto');
    const sku = textoSeguro(p.sku, '—');
    const categoria = textoSeguro(p.categoria, 'Categoria não escolhida');
    const estoqueTotal = numeroSeguro(p.estoque_total);
    const fotoUrl = typeof p.foto_url === 'string' && p.foto_url !== '' ? p.foto_url : null;
    const editorDoBase = typeof p.editor_url === 'string' ? p.editor_url : null;
    const origemRotulo = ROTULO_ORIGEM[textoSeguro(p.origem, '')] ?? null;

    const listaFases = listaSegura(fases);
    const listaOfertas = listaSegura(ofertas);
    const listaHistorico = listaSegura(historico);
    const listaCriativos = listaSegura(criativos);

    const proxima = objetoSeguro(proximaFase);
    const proximoNumero = numeroSeguro(proxima.numero) ?? (listaFases.length + 1);
    const proximaQuantidade = numeroSeguro(proxima.quantidade_sugerida);
    const proximaHabilitada = proxima.habilitado === true;
    const proximoMotivo = textoSeguro(proxima.motivo, '');

    // A conta e o id do produto que o painel "Criar Fase N" precisa para pedir a
    // prévia. Sem um dos dois não há a quem perguntar: o botão fica travado com
    // explicação, nunca escondido (D23).
    const contaDaTela = textoSeguro(objetoSeguro(empresa).chave, '') || null;
    const produtoId = numeroSeguro(p.id);
    const podeCriarFase = proximaHabilitada && contaDaTela !== null && produtoId !== null;
    const motivoDeNaoCriar = proximaHabilitada
        ? 'Esta tela não recebeu a conta do produto. Recarregue a página.'
        : (proximoMotivo || 'Publique a Fase 1 primeiro.');

    const mapa = objetoSeguro(mapeamento);
    const medidas = objetoSeguro(mapa.medidas);
    const mapaVazio = mapa.vazio !== false;

    const companyId = objetoSeguro(abas).company_id ?? null;
    const destaque = numeroSeguro(faseDestacada);

    /**
     * §6 (quick 261009-uec): "Usar estoque calculado" deste kit.
     *
     * Recebe a conta e o id POR ARGUMENTO, nunca por closure sobre uma flag do
     * escopo do componente — a armadilha do Rollup documentada no topo deste
     * arquivo. O cartão vira na hora com o `produto` que o servidor devolveu, e
     * o `reload` traz os números novos das variantes (o estoque de verdade é
     * recalculado no servidor, não um palpite da tela).
     */
    const adotarEstoqueCalculado = async (conta, produtoDaFase) => {
        if (conta === null || produtoDaFase === null || adotandoEstoque !== null) return;
        setAdotandoEstoque(produtoDaFase);
        setErroDoEstoque((atual) => { const resto = { ...atual }; delete resto[produtoDaFase]; return resto; });
        try {
            const { data } = await axios.post(rotaDoPublicador('vinculo.estoque-calculado', conta, { produto: produtoDaFase }));
            if (objetoSeguro(objetoSeguro(data).produto).estoque_calculado === true) {
                setEstoqueAdotado((atual) => ({ ...atual, [produtoDaFase]: true }));
            }
            router.reload({ only: ['produto', 'fases', 'ofertas', 'historico'] });
        } catch (e) {
            setErroDoEstoque((atual) => ({ ...atual, [produtoDaFase]: mensagemDe(e) }));
        } finally {
            setAdotandoEstoque(null);
        }
    };

    return (
        <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
            <div className="flex flex-col gap-6">

                {/* 1 — Cabeçalho do produto */}
                <section className={CARTAO}>
                    {p.base_excluido === true && (
                        <p className={cn(AMBAR, 'mb-3')}>
                            O produto base deste kit foi excluído. O histórico dele continua aqui, mas ele não pertence mais a nenhuma família.
                        </p>
                    )}
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-4">
                            {fotoUrl !== null && (
                                <img
                                    src={fotoUrl}
                                    alt=""
                                    loading="lazy"
                                    className="h-20 w-20 shrink-0 rounded-lg bg-white object-contain"
                                />
                            )}
                            <div className="min-w-0">
                                <h1 className="font-display text-[20px] font-bold leading-tight text-white">{nome}</h1>
                                <p className="mt-1 flex flex-wrap items-center gap-2 text-[13px] font-normal text-white/55">
                                    <span className="font-mono text-[11px] text-white/70">{sku}</span>
                                    {origemRotulo !== null && (
                                        <span className="rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-0.5 text-[11px] font-bold text-white/55">
                                            {origemRotulo}
                                        </span>
                                    )}
                                </p>
                                <p className="mt-1 text-[13px] font-normal text-white/55">{categoria}</p>
                                <p className="mt-1 text-[13px] font-normal text-white/55">
                                    Estoque: <span className="font-mono tabular-nums text-white/80">{estoqueTotal ?? '—'}</span>
                                </p>
                            </div>
                        </div>

                        {editorDoBase !== null ? (
                            <Link href={editorDoBase} className={BOTAO_SECUNDARIO}>Editar Fase 1</Link>
                        ) : (
                            <button type="button" disabled className={BOTAO_TRAVADO}>Editar Fase 1</button>
                        )}
                    </div>
                </section>

                {/* 2 — Fases */}
                <section className={CARTAO}>
                    <h2 className={TITULO_BLOCO}>Fases</h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        {listaFases.length === 0 ? (
                            // Nunca uma lista vazia: a Fase 1 é o próprio produto, mesmo sem rascunho.
                            <div className="rounded-lg border border-white/[0.08] bg-white/[0.03] p-3">
                                <p className="text-[13px] font-bold text-white">Fase 1 · 1 unidade</p>
                                <p className="mt-1 text-[13px] font-normal text-white/55">Não iniciada</p>
                                {editorDoBase !== null ? (
                                    <Link href={editorDoBase} className={cn(BOTAO_SECUNDARIO, 'mt-3')}>Começar rascunho</Link>
                                ) : (
                                    <button type="button" disabled className={cn(BOTAO_TRAVADO, 'mt-3')}>Começar rascunho</button>
                                )}
                            </div>
                        ) : listaFases.map((item, indice) => {
                            // Flags calculadas DENTRO do callback — variável de escopo do
                            // componente lida só aqui já foi eliminada pelo Rollup no bundle
                            // de produção neste projeto (feedback_rollup_map_scope_bug.md).
                            const linha = objetoSeguro(item);
                            const numeroFase = numeroSeguro(linha.fase);
                            const rotuloFase = textoSeguro(linha.rotulo, '1 unidade');
                            const skuFase = textoSeguro(linha.sku, '—');
                            const estado = linha.estado && typeof linha.estado === 'object' ? linha.estado : null;
                            const rotuloEstado = ROTULO_ESTADO_FASE[textoSeguro(linha.estado_fase, '')] ?? 'Não iniciada';
                            const noAr = numeroSeguro(linha.ofertas_no_ar) ?? 0;
                            const quantidade = numeroSeguro(linha.quantidade_kit) ?? 1;
                            const calculado = numeroSeguro(linha.estoque_calculado_valor);
                            const editorDaFase = typeof linha.editor_url === 'string' ? linha.editor_url : null;
                            const destacada = numeroFase !== null && numeroFase === destaque;
                            // §6 (quick 261009-uec) — todas as flags da ação computadas
                            // AQUI DENTRO (armadilha do Rollup), inclusive a conta.
                            const produtoDaFase = numeroSeguro(linha.produto_id);
                            const jaAdotou = produtoDaFase !== null && estoqueAdotado[produtoDaFase] === true;
                            // `jaAdotou` faz o cartão virar na hora, antes do reload chegar.
                            const estoqueProprio = linha.estoque_proprio === true && !jaAdotou;
                            // Sem número calculado não há o que adotar: aí a ação nem existe
                            // (não é "desabilitado com motivo", é ação sem objeto).
                            const temOqueAdotar = estoqueProprio && calculado !== null && quantidade >= 2;
                            const contaDaFase = textoSeguro(objetoSeguro(empresa).chave, '') || null;
                            const podeAdotar = contaDaFase !== null && produtoDaFase !== null;
                            const adotandoEsta = produtoDaFase !== null && adotandoEstoque === produtoDaFase;
                            const erroAoAdotar = produtoDaFase !== null ? textoSeguro(erroDoEstoque[produtoDaFase], '') : '';

                            return (
                                <div
                                    key={indice}
                                    className={cn(
                                        'rounded-lg border border-white/[0.08] bg-white/[0.03] p-3',
                                        destacada && 'ring-2 ring-ecf-yellow',
                                    )}
                                >
                                    <p className="text-[13px] font-bold text-white">
                                        Fase {numeroFase ?? '—'} · {rotuloFase}
                                    </p>
                                    <p className="mt-0.5 font-mono text-[11px] text-white/55">{skuFase}</p>
                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                        <SeloStatusProduto status={estado} />
                                        <span className="text-[11px] font-normal text-white/55">{rotuloEstado}</span>
                                    </div>
                                    <p className="mt-2 text-[13px] font-normal text-white/55">
                                        {noAr === 0 ? 'nenhum anúncio no ar' : noAr === 1 ? '1 anúncio no ar' : `${noAr} anúncios no ar`}
                                    </p>
                                    {quantidade >= 2 && (
                                        <div className="mt-1 flex flex-wrap items-center gap-2">
                                            {/* O texto da §6 que já existia — ele CONTINUA, e o
                                                botão entra ao lado dele. */}
                                            <p className="text-[11px] font-normal text-white/40">
                                                {estoqueProprio
                                                    ? `estoque próprio${calculado !== null ? ` · calculado do base: ${calculado}` : ''}`
                                                    : 'estoque calculado do produto base'}
                                            </p>
                                            {temOqueAdotar && (
                                                <>
                                                    <button
                                                        type="button"
                                                        disabled={!podeAdotar || adotandoEsta}
                                                        aria-disabled={!podeAdotar || adotandoEsta ? 'true' : undefined}
                                                        title={podeAdotar ? AJUDA_ESTOQUE_CALCULADO : SEM_CONTA_PARA_AGIR}
                                                        onClick={() => adotarEstoqueCalculado(contaDaFase, produtoDaFase)}
                                                        className={BOTAO_MINI}
                                                    >
                                                        {adotandoEsta ? 'Adotando o estoque do base…' : 'Usar estoque calculado'}
                                                    </button>
                                                    {/* D23: desabilitado COM explicação, nunca escondido. */}
                                                    {!podeAdotar && (
                                                        <span className="text-[11px] font-normal text-white/40">{SEM_CONTA_PARA_AGIR}</span>
                                                    )}
                                                </>
                                            )}
                                            {erroAoAdotar !== '' && (
                                                <span className="text-[11px] font-normal text-red-300">{erroAoAdotar}</span>
                                            )}
                                        </div>
                                    )}
                                    {editorDaFase !== null && (
                                        <Link href={editorDaFase} className="mt-2 inline-block text-[13px] font-normal text-white/55 hover:text-ecf-yellow">
                                            Abrir no editor
                                        </Link>
                                    )}
                                </div>
                            );
                        })}

                        {/* Cartão de ação "Criar Fase N".
                            A regra de habilitação chega pronta do servidor
                            (`proxima_fase.habilitado`) e o motivo aparece visível quando ela
                            é falsa — desabilitado COM explicação, nunca escondido (D23).
                            Desde o 175-07 o botão ABRE o painel "Criar Fase N" (§4); antes
                            dele ficava travado com "Em breve nesta tela", porque um botão
                            que abre nada é pior que um botão que explica. Sem a conta ou sem
                            o id do produto o painel não teria a quem perguntar a prévia, e
                            aí o botão continua travado — também com explicação. */}
                        <div className="rounded-lg border border-dashed border-white/[0.10] bg-white/[0.02] p-3">
                            <p className="text-[13px] font-bold text-white/70">
                                Criar Fase {proximoNumero}
                                {proximaQuantidade !== null && (
                                    <span className="ml-2 font-normal text-white/40">Kit {proximaQuantidade}</span>
                                )}
                            </p>
                            {podeCriarFase ? (
                                <button
                                    type="button"
                                    onClick={() => setCriarFaseAberto(true)}
                                    className={cn(BOTAO_SECUNDARIO, 'mt-2')}
                                >
                                    Criar Fase {proximoNumero}
                                </button>
                            ) : (
                                <>
                                    <button
                                        type="button"
                                        disabled
                                        aria-disabled="true"
                                        title={motivoDeNaoCriar}
                                        className={cn(BOTAO_TRAVADO, 'mt-2')}
                                    >
                                        Criar Fase {proximoNumero}
                                    </button>
                                    <p className="mt-2 text-[11px] font-normal text-white/40">{motivoDeNaoCriar}</p>
                                </>
                            )}
                            <PainelCriarFase
                                aberto={criarFaseAberto}
                                onFechar={() => setCriarFaseAberto(false)}
                                conta={contaDaTela}
                                produtoBase={{ id: produtoId, nome }}
                                proximaFase={proxima}
                                criativosIa={criativosIa === true}
                                onCriado={(url) => {
                                    setCriarFaseAberto(false);
                                    if (typeof url === 'string' && url !== '') router.get(url);
                                }}
                            />
                        </div>
                    </div>
                </section>

                {/* 3 — Ofertas no ar */}
                <section className={CARTAO}>
                    <h2 className={TITULO_BLOCO}>Anúncios no ar</h2>
                    {listaOfertas.length === 0 ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">Nenhum anúncio no ar ainda.</p>
                    ) : (
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full text-left text-[13px] font-normal text-white/70">
                                <thead>
                                    <tr className="border-b border-white/[0.08] text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                                        <th className="py-2 pr-3">Fase</th>
                                        <th className="py-2 pr-3">Tipo</th>
                                        <th className="py-2 pr-3">Título</th>
                                        <th className="py-2 pr-3">MLB</th>
                                        <th className="py-2 pr-3">Preço</th>
                                        <th className="py-2 pr-3">Vendas</th>
                                        <th className="py-2 pr-3">Visitas</th>
                                        <th className="py-2 pr-3">Situação</th>
                                        <th className="py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {listaOfertas.map((item, indice) => {
                                        // Flags dentro do callback (armadilha do Rollup).
                                        const linha = objetoSeguro(item);
                                        const faseOferta = numeroSeguro(linha.fase);
                                        const tipo = textoSeguro(linha.tipo_rotulo, '—');
                                        const titulo = textoSeguro(linha.titulo, '—');
                                        const mlb = typeof linha.ml_item_id === 'string' || typeof linha.ml_item_id === 'number'
                                            ? String(linha.ml_item_id)
                                            : null;
                                        const vendas = numeroSeguro(linha.vendas);
                                        const visitas = numeroSeguro(linha.visitas);
                                        const situacao = textoSeguro(linha.situacao, '—');
                                        const naoAvaliadas = linha.visitas_nao_avaliadas === true;
                                        const detalheOk = linha.detalhe_disponivel === true && mlb !== null && companyId !== null;
                                        const motivoDetalhe = textoSeguro(linha.detalhe_motivo, '');

                                        return (
                                            <tr key={indice} className="border-b border-white/[0.06] last:border-b-0">
                                                <td className="py-2 pr-3 font-mono tabular-nums">{faseOferta ?? '—'}</td>
                                                <td className="py-2 pr-3 text-[11px] text-white/55">{tipo}</td>
                                                <td className="max-w-[260px] truncate py-2 pr-3 text-white" title={titulo}>{titulo}</td>
                                                <td className="py-2 pr-3">{mlb !== null ? <LinkMl mlb={mlb} className="text-[11px]" /> : '—'}</td>
                                                <td className="py-2 pr-3 font-mono tabular-nums">{moeda(linha.preco)}</td>
                                                <td className="py-2 pr-3 font-mono tabular-nums">{vendas ?? '—'}</td>
                                                <td className="py-2 pr-3 font-mono tabular-nums">
                                                    {visitas ?? '—'}
                                                    {naoAvaliadas && (
                                                        <span className="ml-1 text-[11px] font-normal text-white/40">ainda não coletado</span>
                                                    )}
                                                </td>
                                                <td className="py-2 pr-3 text-[11px] text-white/55">{situacao}</td>
                                                <td className="py-2">
                                                    {detalheOk ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => setMlbAberto(mlb)}
                                                            className="text-[13px] font-normal text-white/55 hover:text-ecf-yellow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                                        >
                                                            Detalhe
                                                        </button>
                                                    ) : (
                                                        <span className="inline-flex flex-col gap-0.5">
                                                            <button
                                                                type="button"
                                                                disabled
                                                                aria-disabled="true"
                                                                title={motivoDetalhe || TITLE_SEM_COMPANY}
                                                                className="cursor-not-allowed text-[13px] font-normal text-white/30"
                                                            >
                                                                Detalhe
                                                            </button>
                                                            <span className="text-[11px] font-normal text-white/40">
                                                                {motivoDetalhe || TITLE_SEM_COMPANY}
                                                            </span>
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {/* 4 — Histórico */}
                <section className={CARTAO}>
                    <h2 className={TITULO_BLOCO}>Histórico</h2>
                    {listaHistorico.length === 0 ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">Nada registrado ainda.</p>
                    ) : (
                        <ol className="mt-3 flex flex-col gap-2">
                            {listaHistorico.map((item, indice) => {
                                // Flags dentro do callback (armadilha do Rollup).
                                const linha = objetoSeguro(item);
                                const rotulo = ROTULO_TIPO_HISTORICO[textoSeguro(linha.tipo, '')] ?? 'Registro';
                                const detalhe = textoSeguro(linha.detalhe, '');
                                const quando = typeof linha.quando === 'string' ? (haQuanto(linha.quando) ?? '—') : '—';
                                // Ator sem `id` (linha migrada, achado da Fase 173) chega nulo:
                                // a tela diz "origem antiga", nunca "undefined".
                                const quem = linha.quem === null || linha.quem === undefined
                                    ? 'origem antiga'
                                    : textoSeguro(linha.quem, 'origem antiga');
                                const faseLinha = numeroSeguro(linha.fase);

                                return (
                                    <li key={indice} className="flex flex-wrap items-center justify-between gap-2 border-b border-white/[0.06] pb-2 text-[13px] font-normal text-white/70 last:border-b-0">
                                        <span className="text-white">{rotulo}</span>
                                        {detalhe !== '' && <span className="text-white/55">{detalhe}</span>}
                                        {faseLinha !== null && (
                                            <span className="font-mono text-[11px] text-white/40">Fase {faseLinha}</span>
                                        )}
                                        <span>
                                            {quem} <span className="font-mono text-[11px] text-white/40">· {quando}</span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </section>
            </div>

            <div className="flex flex-col gap-6">

                {/* 5 — Lateral: Criativos */}
                <section className={CARTAO}>
                    <h2 className={TITULO_BLOCO}>Criativos</h2>
                    {listaCriativos.length === 0 ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">Nenhum criativo aprovado ainda.</p>
                    ) : (
                        <div className="mt-3 flex flex-col gap-3">
                            {listaCriativos.map((item, indice) => {
                                // Flags dentro do callback (armadilha do Rollup).
                                const grupo = objetoSeguro(item);
                                const faseGrupo = numeroSeguro(grupo.fase);
                                const rotuloGrupo = textoSeguro(grupo.rotulo, '1 unidade');
                                const miniaturas = listaSegura(grupo.miniaturas)
                                    .map((m) => objetoSeguro(m))
                                    .filter((m) => typeof m.url === 'string' && m.url !== '');

                                return (
                                    <div key={indice}>
                                        <p className="text-[11px] font-normal text-white/40">
                                            Fase {faseGrupo ?? '—'} · {rotuloGrupo}
                                        </p>
                                        <div className="mt-1 flex flex-wrap gap-2">
                                            {miniaturas.map((m, i) => (
                                                <img
                                                    key={i}
                                                    src={m.url}
                                                    alt=""
                                                    loading="lazy"
                                                    className="h-16 w-16 rounded-lg bg-white object-contain"
                                                />
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>

                {/* 6 — Lateral: Mapeamento */}
                <section className={CARTAO}>
                    <h2 className={TITULO_BLOCO}>Mapeamento</h2>
                    {mapaVazio ? (
                        <div className="mt-3">
                            <p className={AMBAR}>
                                Medidas, peso, material e EAN: não informado no Mapeamento Estrutural.
                            </p>
                            <dl className="mt-3 flex flex-col gap-2 text-[13px] font-normal text-white/55">
                                <div className="flex items-center justify-between"><dt>Medidas</dt><dd>—</dd></div>
                                <div className="flex items-center justify-between"><dt>Peso</dt><dd>—</dd></div>
                                <div className="flex items-center justify-between"><dt>Material</dt><dd>—</dd></div>
                                <div className="flex items-center justify-between"><dt>EAN</dt><dd>—</dd></div>
                            </dl>
                        </div>
                    ) : (
                        <dl className="mt-3 flex flex-col gap-2 text-[13px] font-normal text-white/70">
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-white/55">Medidas</dt>
                                <dd className="font-mono tabular-nums">
                                    {[medidas.comprimento, medidas.largura, medidas.altura]
                                        .map((v) => decimal(v) ?? '—')
                                        .join(' × ')}{' '}
                                    {textoSeguro(medidas.unidade, 'cm')}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-white/55">Peso</dt>
                                <dd className="font-mono tabular-nums">
                                    {decimal(mapa.peso) !== null ? `${decimal(mapa.peso)} kg` : '—'}
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-white/55">Material</dt>
                                <dd className="truncate">{textoSeguro(mapa.material, '—')}</dd>
                            </div>
                            <div className="flex items-center justify-between gap-3">
                                <dt className="text-white/55">EAN</dt>
                                <dd className="font-mono">{textoSeguro(mapa.ean, '—')}</dd>
                            </div>
                        </dl>
                    )}
                </section>
            </div>

            {/* O modal de 90 dias já existe (Meus Anúncios, Fase 134) — só é montado
                quando alguém clica em "Detalhe", e exige `company_id` (D23). */}
            {mlbAberto !== null && companyId !== null && (
                <ModalDetalheAnuncio
                    empresaId={companyId}
                    mlItemId={mlbAberto}
                    saudeMlDisponivel={false}
                    onClose={() => setMlbAberto(null)}
                />
            )}
        </div>
    );
}
