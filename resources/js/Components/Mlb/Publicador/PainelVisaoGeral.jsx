import { useState } from 'react';
import { router } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { textoSeguro } from './BarraDaConta';
import LinkReconexao from './LinkReconexao';
import BotaoSincronizarPortal from './BotaoSincronizarPortal';
import SeloConta from './SeloConta';
import SeloPortal from './SeloPortal';
import AvisoContaTravada from './AvisoContaTravada';
import { haQuanto } from './tempo';
import { LinkMl } from '@/Components/Portal/Estrutura/comum';

// D23: mesmo texto literal usado em AbasDaConta.jsx/ModoAnuncioTabs.jsx — há
// teste de fonte que procura essa string em outras telas; não variar.
const TITLE_SEM_COMPANY = 'Disponível só para empresas cadastradas no sistema';

const BOTAO_SECUNDARIO = 'inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';

/** Só aceita number finito do servidor; qualquer outra forma cai em null (nunca derruba a tela). */
function numeroSeguro(valor) {
    return typeof valor === 'number' && Number.isFinite(valor) ? valor : null;
}

/**
 * Cartão de indicador do topo — mesmo padrão visual do `Cartao` interno de
 * `IndicadoresDoPrograma.jsx` (rótulo 11px/bold/uppercase, número 24px
 * font-display, nota 13px). Não importamos aquele componente porque os
 * rótulos são de outra tela (painel do PROGRAMA inteiro: "Empresas", "Com
 * dados do Portal"...) e esta precisa do estado "nunca coletado" (botão
 * "Atualizar agora") que o componente genérico não tem — decisão
 * documentada na SUMMARY da plan 06.
 */
function CartaoIndicador({ rotulo, numero, nota, barraPct = null, onClick, botaoTexto, onBotao }) {
    const corpo = (
        <>
            <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">{rotulo}</p>
            <p className="mt-1 font-display text-[24px] font-bold tabular-nums text-white">{numero}</p>
            {nota && <p className="text-[13px] font-normal text-white/55">{nota}</p>}
            {typeof barraPct === 'number' && (
                <div className="mt-2 h-1 w-full rounded-full bg-white/10">
                    <div
                        className="h-1 rounded-full bg-ecf-yellow/50"
                        style={{ width: `${Math.max(0, Math.min(100, barraPct))}%` }}
                    />
                </div>
            )}
        </>
    );

    // Card com botão de ação (ex.: "Atualizar agora") nunca é ele mesmo um
    // <button> — evita <button> dentro de <button> quando o acervo nunca
    // foi coletado.
    if (botaoTexto) {
        return (
            <div className="rounded-xl bg-ecf-card p-4 text-left">
                {corpo}
                <button
                    type="button"
                    onClick={onBotao}
                    className="mt-2 inline-flex h-8 items-center rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[11px] font-bold text-white/80 hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                >
                    {botaoTexto}
                </button>
            </div>
        );
    }

    if (onClick) {
        return (
            <button
                type="button"
                onClick={onClick}
                className="rounded-xl bg-ecf-card p-4 text-left hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
            >
                {corpo}
            </button>
        );
    }

    return <div className="rounded-xl bg-ecf-card p-4 text-left">{corpo}</div>;
}

/**
 * Painel de conteúdo da Visão geral do Publicador (Fase 173, plano 06) — os
 * 7 blocos do contrato fechado pela plan 04 (`PainelVisaoGeralService`).
 *
 * Fica FORA de `Pages/Mlb/Publicador/VisaoGeral.jsx` de propósito: aquela
 * página só soma `AppLayout` + `BarraDaConta` + `AbasDaConta` em volta deste
 * painel. `AppLayout` arrasta sino de notificações, aviso de chamados, tema
 * e Modo TV — uma árvore pesada demais pro teste de render isolar só porque
 * um bloco da Visão geral mudou. Decisão documentada na SUMMARY da plan 06.
 *
 * TODO campo do servidor passa por `textoSeguro()`/`numeroSeguro()` antes do
 * JSX — esta página expõe dezenas de campos de uma vez (T-173-13, lição da
 * tela preta de 07/10: um campo que chegou como objeto e foi renderizado
 * como texto derrubou a árvore React inteira).
 */
export default function PainelVisaoGeral({
    empresa = {},
    indicadores = {},
    oQueFazerAgora = [],
    situacaoProdutos = {},
    ultimasPublicacoes = { disponivel: false, itens: [] },
    integracoes = {},
    identidadeResumo = { tem_identidade: false, texto_resumo: null },
    quemPublicou = { equipe: [], cliente: { quantidade: 0 }, origem_antiga: { quantidade: 0 } },
    abas = { company_id: null },
}) {
    const [erroSincronizar, setErroSincronizar] = useState(null);

    const empresaSegura = empresa && typeof empresa === 'object' ? empresa : {};
    const contaChave = textoSeguro(empresaSegura.chave, null);
    const companyIdAbas = abas && typeof abas === 'object' ? (abas.company_id ?? null) : null;

    const indicadoresSeguros = indicadores && typeof indicadores === 'object' ? indicadores : {};
    const acervoIndisponivel = indicadoresSeguros.acervo_disponivel === false;
    const nuncaColetado = indicadoresSeguros.nunca_coletado === true;
    const semAcervoOuNuncaColetado = acervoIndisponivel || nuncaColetado;
    const noAr = numeroSeguro(indicadoresSeguros.no_ar);
    const comVenda = numeroSeguro(indicadoresSeguros.com_venda);
    const semOferta = numeroSeguro(indicadoresSeguros.sem_oferta) ?? 0;
    const publicados30d = numeroSeguro(indicadoresSeguros.publicados_30d) ?? 0;
    const publicados30dPessoas = numeroSeguro(indicadoresSeguros.publicados_30d_pessoas) ?? 0;

    const linhasOQueFazer = Array.isArray(oQueFazerAgora) ? oQueFazerAgora : [];

    const situacaoProdutosSegura = situacaoProdutos && typeof situacaoProdutos === 'object' && !Array.isArray(situacaoProdutos)
        ? situacaoProdutos
        : {};

    const ultimasSeguras = ultimasPublicacoes && typeof ultimasPublicacoes === 'object' ? ultimasPublicacoes : {};
    const ultimasDisponiveis = ultimasSeguras.disponivel === true;
    const itensUltimas = Array.isArray(ultimasSeguras.itens) ? ultimasSeguras.itens : [];

    const integracoesSeguras = integracoes && typeof integracoes === 'object' ? integracoes : {};

    const identidadeSegura = identidadeResumo && typeof identidadeResumo === 'object' ? identidadeResumo : {};
    const identidadeTemTexto = identidadeSegura.tem_identidade === true
        && Array.isArray(identidadeSegura.texto_resumo)
        && identidadeSegura.texto_resumo.length > 0;
    const linhasIdentidade = identidadeTemTexto ? identidadeSegura.texto_resumo.slice(0, 3) : [];

    const quemSeguro = quemPublicou && typeof quemPublicou === 'object' ? quemPublicou : {};
    const equipeSegura = Array.isArray(quemSeguro.equipe) ? quemSeguro.equipe : [];
    const clienteQtd = numeroSeguro(quemSeguro.cliente?.quantidade) ?? 0;
    const origemQtd = numeroSeguro(quemSeguro.origem_antiga?.quantidade) ?? 0;

    function abrirProdutos(filtro) {
        if (!contaChave) return;
        router.get(route('mlb.anuncios.publicador.produtos', { conta: contaChave, filtro }));
    }

    function atualizarAgora() {
        if (!companyIdAbas) return;
        router.post(route('mlb.anuncios.meus.atualizar', { company: companyIdAbas }));
    }

    return (
        <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
            <div className="flex flex-col gap-6">

                {/* 1 — Indicadores (4) */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <CartaoIndicador
                        rotulo="No ar"
                        numero={semAcervoOuNuncaColetado ? '—' : (noAr ?? '—')}
                        nota={acervoIndisponivel ? TITLE_SEM_COMPANY : 'ativos e pausados'}
                        botaoTexto={!acervoIndisponivel && nuncaColetado ? 'Atualizar agora' : null}
                        onBotao={atualizarAgora}
                        onClick={!semAcervoOuNuncaColetado && companyIdAbas
                            ? () => router.get(route('mlb.anuncios.meus', { company: companyIdAbas, status: 'acionaveis' }))
                            : null}
                    />
                    <CartaoIndicador
                        rotulo="Com venda"
                        numero={semAcervoOuNuncaColetado ? '—' : (comVenda ?? '—')}
                        nota={acervoIndisponivel ? TITLE_SEM_COMPANY : `de ${noAr ?? '—'} no ar`}
                        barraPct={!semAcervoOuNuncaColetado && noAr && noAr > 0 && comVenda !== null ? (comVenda / noAr) * 100 : null}
                        botaoTexto={!acervoIndisponivel && nuncaColetado ? 'Atualizar agora' : null}
                        onBotao={atualizarAgora}
                        onClick={!semAcervoOuNuncaColetado && companyIdAbas
                            ? () => router.get(route('mlb.anuncios.meus', { company: companyIdAbas, comVenda: 1 }))
                            : null}
                    />
                    <CartaoIndicador
                        rotulo="Publicados nos últimos 30 dias"
                        numero={publicados30d}
                        nota={`${publicados30dPessoas} ${publicados30dPessoas === 1 ? 'pessoa' : 'pessoas'}`}
                        onClick={companyIdAbas ? () => router.get(route('mlb.anuncios.historico', { company: companyIdAbas })) : null}
                    />
                    <CartaoIndicador
                        rotulo="Sem oferta"
                        numero={semOferta}
                        nota="produtos sem oferta do Portal vinculada"
                    />
                </div>

                {/* 2 — O que fazer agora */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">O que fazer agora</h2>
                    <div className="mt-3 flex flex-col gap-2">
                        {linhasOQueFazer.length === 0 ? (
                            <p className="text-[13px] font-normal text-white/55">Nada pendente nesta conta.</p>
                        ) : linhasOQueFazer.map((item, indice) => {
                            // Flags calculadas DENTRO do callback — variável de escopo do
                            // componente lida só dentro do .map() já foi eliminada pelo
                            // Rollup no bundle de produção neste projeto (feedback_rollup_map_scope_bug.md).
                            const linha = item && typeof item === 'object' ? item : {};
                            const texto = textoSeguro(linha.texto, 'Pendência');
                            const numeroLinha = numeroSeguro(linha.numero);
                            const destino = linha.destino && typeof linha.destino === 'object' ? linha.destino : {};
                            const acao = typeof destino.acao === 'string' ? destino.acao : null;
                            const rota = typeof destino.rota === 'string' ? destino.rota : null;
                            const params = destino.params && typeof destino.params === 'object' ? destino.params : {};
                            const legado = numeroSeguro(linha.legado);
                            const exemplo = linha.exemplo && typeof linha.exemplo === 'object' ? linha.exemplo : null;

                            return (
                                <div key={indice} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-white/[0.06] p-3">
                                    <div>
                                        <p className="text-[13px] font-normal text-white/85">
                                            {texto}
                                            {numeroLinha !== null && (
                                                <span className="ml-2 font-mono text-[11px] tabular-nums text-white/55">{numeroLinha}</span>
                                            )}
                                        </p>
                                        {legado !== null && legado > 0 && (
                                            <p className="mt-0.5 text-[11px] font-normal text-white/40">{legado} legado</p>
                                        )}
                                        {exemplo && (
                                            <p className="mt-0.5 text-[11px] font-normal text-white/40">
                                                Ex.: {textoSeguro(exemplo.nome, '—')} (faltam {numeroSeguro(exemplo.faltam) ?? '—'})
                                            </p>
                                        )}
                                    </div>
                                    {acao === 'reconectar' ? (
                                        <LinkReconexao link={typeof destino.url === 'string' ? destino.url : null} />
                                    ) : acao === 'sincronizar' ? (
                                        <BotaoSincronizarPortal
                                            conta={contaChave}
                                            onConcluido={() => router.reload()}
                                            onErro={(mensagem) => setErroSincronizar(mensagem)}
                                        />
                                    ) : rota ? (
                                        <button type="button" onClick={() => router.get(route(rota, params))} className={BOTAO_SECUNDARIO}>
                                            Ver
                                        </button>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                    {erroSincronizar && (
                        <p className="mt-2 text-[13px] font-normal text-red-300">{textoSeguro(erroSincronizar, 'Não foi possível sincronizar.')}</p>
                    )}
                </section>

                {/* 3 — Situação dos produtos */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Situação dos produtos</h2>
                    <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {Object.entries(situacaoProdutosSegura).map(([chave, valor]) => {
                            const item = valor && typeof valor === 'object' ? valor : {};
                            const numero = numeroSeguro(item.numero) ?? 0;
                            const rotulo = textoSeguro(item.rotulo, chave);

                            return (
                                <button
                                    key={chave}
                                    type="button"
                                    onClick={() => abrirProdutos(chave)}
                                    className="rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-left hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                >
                                    <p className="font-mono text-[18px] font-bold tabular-nums text-white">{numero}</p>
                                    <p className="text-[11px] font-normal text-white/55">{rotulo}</p>
                                </button>
                            );
                        })}
                    </div>
                </section>

                {/* 4 — Últimas publicações */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Últimas publicações</h2>
                        {ultimasDisponiveis && (
                            <button
                                type="button"
                                onClick={() => companyIdAbas && router.get(route('mlb.anuncios.historico', { company: companyIdAbas }))}
                                className="text-[13px] font-normal text-white/55 hover:text-ecf-yellow"
                            >
                                Ver todas
                            </button>
                        )}
                    </div>

                    {!ultimasDisponiveis ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">{TITLE_SEM_COMPANY}</p>
                    ) : itensUltimas.length === 0 ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">Nenhuma publicação ainda.</p>
                    ) : (
                        <div className="mt-3 flex flex-col gap-2">
                            {itensUltimas.slice(0, 5).map((item, indice) => {
                                const linha = item && typeof item === 'object' ? item : {};
                                const titulo = textoSeguro(linha.titulo, '—');
                                const mlbId = typeof linha.ml_item_id === 'string' || typeof linha.ml_item_id === 'number'
                                    ? String(linha.ml_item_id)
                                    : null;
                                const tipo = textoSeguro(linha.tipo, '—');
                                const quem = linha.quem && typeof linha.quem === 'object' ? linha.quem : {};
                                const quemTexto = quem.tipo === 'cliente'
                                    ? 'Cliente'
                                    : quem.tipo === 'origem_antiga'
                                        ? 'Origem antiga'
                                        : textoSeguro(quem.nome, '—');
                                const quandoTexto = haQuanto(typeof linha.quando === 'string' ? linha.quando : null) ?? '—';
                                const vendas = numeroSeguro(linha.vendas);
                                const situacao = textoSeguro(linha.situacao, '—');

                                return (
                                    <div
                                        key={indice}
                                        className="flex flex-wrap items-center justify-between gap-2 border-b border-white/[0.06] pb-2 text-[13px] font-normal text-white/70 last:border-b-0"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-white" title={titulo}>{titulo}</p>
                                            {mlbId && <LinkMl mlb={mlbId} className="text-[11px]" />}
                                        </div>
                                        <span className="text-[11px] text-white/55">{tipo}</span>
                                        <span>
                                            {quemTexto} <span className="font-mono text-[11px] text-white/40">· {quandoTexto}</span>
                                        </span>
                                        <span className="font-mono tabular-nums">{vendas ?? '—'}</span>
                                        <span className="text-[11px] text-white/55">{situacao}</span>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>
            </div>

            <div className="flex flex-col gap-6">

                {/* 5 — Lateral: Integrações */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Integrações</h2>
                    <div className="mt-3 flex flex-col gap-3 text-[13px] font-normal text-white/70">
                        <div className="flex items-center justify-between">
                            <span>Mercado Livre</span>
                            <SeloConta token={textoSeguro(integracoesSeguras.mercado_livre?.token, textoSeguro(empresaSegura.token, 'sem_token'))} />
                        </div>
                        {textoSeguro(empresaSegura.token, 'ativo') !== 'ativo' && (
                            <LinkReconexao link={typeof empresaSegura.link_reconexao === 'string' ? empresaSegura.link_reconexao : null} />
                        )}
                        <div className="flex items-center justify-between">
                            <span>Publicação</span>
                            {integracoesSeguras.publicacao_liberada === true ? (
                                <span className="text-[11px] font-bold text-emerald-400">Liberada</span>
                            ) : (
                                <AvisoContaTravada variante="selo" />
                            )}
                        </div>
                        <div className="flex items-center justify-between">
                            <span>Alavancas</span>
                            <span className={cn('text-[11px] font-bold', integracoesSeguras.alavancas_liberada === true ? 'text-emerald-400' : 'text-white/40')}>
                                {integracoesSeguras.alavancas_liberada === true ? 'Liberada' : 'Não liberada'}
                            </span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span>Portal</span>
                            <SeloPortal portal={integracoesSeguras.portal} />
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <span>ERP</span>
                            <span className="truncate text-white/55">
                                {textoSeguro(integracoesSeguras.erp?.valor, textoSeguro(integracoesSeguras.erp?.rotulo, 'Não informado'))}
                            </span>
                        </div>
                    </div>
                </section>

                {/* 6 — Lateral: Identidade visual */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Identidade visual</h2>
                        <button
                            type="button"
                            onClick={() => contaChave && router.get(route('mlb.anuncios.publicador.configuracoes', { conta: contaChave }))}
                            className="text-[13px] font-normal text-white/55 hover:text-ecf-yellow"
                        >
                            Editar
                        </button>
                    </div>
                    <div className="mt-3 text-[13px] font-normal text-white/70">
                        {identidadeTemTexto ? (
                            linhasIdentidade.map((linha, indice) => (
                                <p key={indice} className="truncate">{textoSeguro(linha, '')}</p>
                            ))
                        ) : (
                            <p className="text-white/55">Não cadastrada. Os criativos são gerados sem identidade.</p>
                        )}
                    </div>
                </section>

                {/* 7 — Lateral: Quem publicou */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Quem publicou</h2>
                    <div className="mt-3 flex flex-col gap-2 text-[13px] font-normal text-white/70">
                        {equipeSegura.length === 0 && clienteQtd === 0 && origemQtd === 0 ? (
                            <p className="text-white/55">Nenhuma publicação nos últimos 30 dias.</p>
                        ) : (
                            <>
                                {equipeSegura.map((pessoa, indice) => {
                                    const p = pessoa && typeof pessoa === 'object' ? pessoa : {};
                                    const nome = textoSeguro(p.nome, '—');
                                    const quantidade = numeroSeguro(p.quantidade) ?? 0;
                                    const responsavel = p.responsavel === true;

                                    return (
                                        <div key={indice} className="flex items-center justify-between">
                                            <span className={cn(responsavel && 'font-bold text-white')}>
                                                {nome}
                                                {responsavel && <span className="ml-1 text-[11px] font-normal text-ecf-yellow">responsável</span>}
                                            </span>
                                            <span className="font-mono tabular-nums text-white/55">{quantidade}</span>
                                        </div>
                                    );
                                })}
                                {clienteQtd > 0 && (
                                    <div className="flex items-center justify-between">
                                        <span>Cliente</span>
                                        <span className="font-mono tabular-nums text-white/55">{clienteQtd}</span>
                                    </div>
                                )}
                                {origemQtd > 0 && (
                                    <div className="flex items-center justify-between">
                                        <span>Origem antiga</span>
                                        <span className="font-mono tabular-nums text-white/55">{origemQtd}</span>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </section>
            </div>
        </div>
    );
}
