import { useState } from 'react';
import { router } from '@inertiajs/react';
import { AlertTriangle, BadgeCheck, Boxes, ChevronRight, ClipboardList, Clock, Images, Palette, Send, ShieldCheck, Sparkles, TrendingUp, Zap } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro } from './BarraDaConta';
import CartaoKpi from './CartaoKpi';
import SeloExemplo from './SeloExemplo';
import {
    ALERTAS_ML_EXEMPLO,
    ATIVIDADE_EXEMPLO,
    CATALOGO_EXEMPLO,
    CONTA_EXEMPLO,
    CONVERSAO_EXEMPLO,
    ERP_EXEMPLO,
    PERIODOS_EXEMPLO,
    TRACAO_EXEMPLO,
} from './dadosDeExemplo';
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

// ─── A régua do dado de exemplo (quick 261010-t02b) ─────────────────────────
//
// O main/content desta tela é o do mockup do Stitch INTEIRO: onde o dado da
// conta existe ele é usado; onde ainda não existe, entra um valor de exemplo —
// nunca um quadro vazio (decisão do usuário em 10/10).
//
// Todo valor fictício vem de `dadosDeExemplo.js` e todo bloco que o usa carrega
// `SeloExemplo`. Nada de número inventado escrito aqui no JSX.

/** O `title` de um botão que só existe para completar o desenho do mockup. */
const TITULO_BOTAO_EXEMPLO = 'Bloco de exemplo: este botão não leva a lugar nenhum porque a integração ainda não existe.';

/** O `title` do seletor de período — ele marca o escolhido e não refiltra nada. */
const TITULO_PERIODO = 'O recorte por período ainda não está ligado: o seletor marca a opção e a tela continua mostrando o acervo inteiro.';

/**
 * O caminho do sparkline de conversão diária, em coordenadas da viewBox 320×48.
 *
 * Fica no escopo do MÓDULO de propósito: a série é constante, e calcular isso
 * dentro do componente colocaria uma variável de escopo do componente dentro de
 * um `.map()` — exatamente o que o Rollup já eliminou no bundle de produção
 * deste projeto (feedback_rollup_map_scope_bug.md).
 *
 * ⚠️ O CAMINHO REAL já existe no banco: a série diária por conta mora em
 * `ml_acervo_metricas_diarias`. Quando o servidor mandar os últimos 14 dias nos
 * `indicadores`, basta trocar `CONVERSAO_EXEMPLO.pontos` pela série do servidor.
 */
const PONTOS_CONVERSAO = CONVERSAO_EXEMPLO.pontos
    .map((valor, indice, lista) => {
        const maximo = Math.max(...lista);
        const minimo = Math.min(...lista);
        const faixa = (maximo - minimo) || 1;
        const x = lista.length > 1 ? (indice / (lista.length - 1)) * 320 : 0;
        const y = 44 - (((valor - minimo) / faixa) * 40);

        return `${Math.round(x)},${Math.round(y)}`;
    })
    .join(' ');

/** O mesmo caminho fechado contra a base, para o preenchimento esmaecido. */
const AREA_CONVERSAO = `${PONTOS_CONVERSAO} 320,48 0,48`;

/** Soma a contagem de produtos de "Situação dos produtos" — o catálogo real da conta. */
function totalDoCatalogo(situacao) {
    if (!situacao || typeof situacao !== 'object' || Array.isArray(situacao)) return null;

    let total = null;
    for (const valor of Object.values(situacao)) {
        const item = valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {};
        const numero = numeroSeguro(item.numero);
        if (numero !== null) total = (total ?? 0) + numero;
    }

    return total;
}

/** Só aceita number finito do servidor; qualquer outra forma cai em null (nunca derruba a tela). */
function numeroSeguro(valor) {
    return typeof valor === 'number' && Number.isFinite(valor) ? valor : null;
}

// ─── "Produtos por fase" (Fase 175, §7 da ETAPA-3) ──────────────────────────
//
// ⚠️ Este bloco nasce ABAIXO de "Situação dos produtos", não no lugar dele: a
// §7 manda substituir, mas a regra inviolável "nada que existe pode sumir"
// vence (divergência já registrada pelo 175-08, que entrega as duas props).

/** A ordem dos 5 buckets, do contrato do 175-08 — nunca a ordem das chaves do JSON. */
const ORDEM_POR_FASE = ['sem_oferta', 'fase1_publicada', 'fase2_preparacao', 'fase2_publicada', 'fase3_mais'];

/**
 * O par (filtro de situação, filtro de fase) com que a lista de Produtos
 * expressa cada bucket. A lista tem dois filtros: os chips de situação (de
 * hoje) e o de fase (`todas | so_base | so_kits`, Fase 175).
 *
 * ⚠️ "Sem oferta" aqui é "sem nenhum anúncio no ar" — e isso NÃO é um dos
 * chips de situação da lista. Em vez de inventar um filtro que não existe (ou
 * de deixar o número sem destino), ele abre a lista inteira: é o mesmo número,
 * sem mentir sobre o recorte.
 */
export function destinoDaFase(chave) {
    if (chave === 'fase1_publicada') return { filtro: 'publicados', fase: 'so_base' };
    if (chave === 'fase2_preparacao') return { filtro: 'rascunho', fase: 'so_kits' };
    if (chave === 'fase2_publicada') return { filtro: 'publicados', fase: 'so_kits' };
    if (chave === 'fase3_mais') return { filtro: 'todos', fase: 'so_kits' };

    return { filtro: 'todos', fase: 'todas' };
}

/**
 * Normaliza a prop `produtosPorFase` em `[{chave, numero, rotulo}]`.
 *
 * Aceita as DUAS formas: o MAPA por bucket que `PainelVisaoGeralService`
 * manda hoje (`{sem_oferta: {numero, rotulo}, …}`) e a LISTA de
 * `{chave, rotulo, numero}` que o PLAN do 175-10 descrevia — o contrato
 * divergiu entre o plano e o que o 175-08 implementou, e a tela não pode
 * ficar vazia por causa disso. Qualquer outra forma vira lista vazia, e o
 * bloco não aparece (servidor antigo).
 */
export function itensPorFase(valor) {
    const item = (chave, dados) => {
        const d = dados && typeof dados === 'object' && !Array.isArray(dados) ? dados : {};

        return { chave, numero: numeroSeguro(d.numero) ?? 0, rotulo: textoSeguro(d.rotulo, chave) };
    };

    if (Array.isArray(valor)) {
        return valor
            .filter((linha) => linha && typeof linha === 'object' && typeof linha.chave === 'string')
            .map((linha) => item(linha.chave, linha));
    }

    if (!valor || typeof valor !== 'object') return [];

    const conhecidos = ORDEM_POR_FASE.filter((chave) => Object.prototype.hasOwnProperty.call(valor, chave));
    // Bucket novo que o servidor passe a mandar entra no fim, nunca desaparece.
    const extras = Object.keys(valor).filter((chave) => !ORDEM_POR_FASE.includes(chave));

    return [...conhecidos, ...extras].map((chave) => item(chave, valor[chave]));
}

// ─── Helpers da faixa de KPIs (quick 261009-t02) ────────────────────────────
//
// O cartão em si mora em `CartaoKpi.jsx`; aqui ficam só os recortes que
// dependem do contrato do servidor desta tela.

/**
 * O número de UMA linha de "O que fazer agora", pelo texto que o servidor
 * escreveu.
 *
 * É assim que o KPI "Aguardando ação" alcança o "Prontos para a Fase 2": esse
 * número já existe, e existe EXATAMENTE em um lugar — a linha montada por
 * `PainelVisaoGeralService::oQueFazerAgora()`. Recalcular aqui criaria uma
 * segunda implementação do mesmo número, que é como duas telas passam a
 * discordar. Linha ausente devolve `null` (não zero): a linha some quando o
 * número é zero, mas também quando o servidor é antigo — e os dois casos
 * aparecem como "sem sub-número", nunca como "0".
 */
export function numeroDaLinha(linhas, texto) {
    if (!Array.isArray(linhas)) return null;

    for (const bruto of linhas) {
        const linha = bruto && typeof bruto === 'object' ? bruto : {};
        if (textoSeguro(linha.texto, '') === texto) {
            return numeroSeguro(linha.numero);
        }
    }

    return null;
}

/**
 * Normaliza a prop `alertas` (quick 261009-t02) — a triagem do acervo que o
 * servidor já carregava, no formato que a coluna lateral consome.
 *
 * `presente` distingue "servidor antigo, sem a prop" (o bloco nem aparece) de
 * "servidor novo": só então `disponivel` separa "sem Company, não há acervo
 * para triar" de "triamos e não há alerta". Nenhum dos dois pode virar zero.
 */
export function alertasSeguros(valor) {
    const bruto = valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : null;
    if (bruto === null || typeof bruto.disponivel !== 'boolean') {
        return { presente: false, disponivel: false, total: 0, itens: [] };
    }

    const itens = (Array.isArray(bruto.itens) ? bruto.itens : [])
        .map((linha) => (linha && typeof linha === 'object' && !Array.isArray(linha) ? linha : {}))
        .filter((linha) => typeof linha.chave === 'string');

    return {
        presente: true,
        disponivel: bruto.disponivel === true,
        total: numeroSeguro(bruto.total) ?? 0,
        itens,
    };
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
    produtosPorFase = null,
    ultimasPublicacoes = { disponivel: false, itens: [] },
    integracoes = {},
    identidadeResumo = { tem_identidade: false, texto_resumo: null },
    quemPublicou = { equipe: [], cliente: { quantidade: 0 }, origem_antiga: { quantidade: 0 } },
    alertas = null,
    abas = { company_id: null },
}) {
    const [erroSincronizar, setErroSincronizar] = useState(null);
    // Seletor de período do mockup: VISUAL. Marca o escolhido e não refiltra
    // nada — a tela não tem janela de período, e fingir que filtra seria mentir.
    const [periodoEscolhido, setPeriodoEscolhido] = useState(PERIODOS_EXEMPLO.escolhido);

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

    // ─── Chaves novas da tela 02 (quick 261009-t02) ──────────────────────
    //
    // Todas opcionais: servidor antigo não manda nenhuma delas, e aí o cartão
    // correspondente mostra "—" com o motivo, nunca um zero inventado.
    const porFase = indicadoresSeguros.no_ar_por_fase && typeof indicadoresSeguros.no_ar_por_fase === 'object'
        ? indicadoresSeguros.no_ar_por_fase
        : {};
    const noArBase = numeroSeguro(porFase.fase1);
    // ⚠️ "kits", NUNCA "Fase 2": `quantidade_kit >= 2` inclui o kit de 3, que é
    // Fase 3 (foi a correção que a tela 01 precisou fazer).
    const noArKits = numeroSeguro(porFase.kits);
    const criativosPacks = numeroSeguro(indicadoresSeguros.criativos_packs);
    // ⚠️ `tracao_pct` é NULO quando não há o que dividir. Nunca 0%: "não
    // medimos" e "medimos e deu zero" são coisas diferentes nesta tela.
    const tracaoPct = numeroSeguro(indicadoresSeguros.tracao_pct);

    const linhasOQueFazer = Array.isArray(oQueFazerAgora) ? oQueFazerAgora : [];
    // O "Prontos para a Fase 2" sai da linha que o servidor já monta — fonte
    // única; aqui nunca se recalcula o número de ninguém.
    const prontosFase2 = numeroDaLinha(linhasOQueFazer, 'Prontos para a Fase 2');
    const aguardandoAcao = semOferta + (prontosFase2 ?? 0);

    const alerta = alertasSeguros(alertas);
    // Sem venda registrada: só existe quando os DOIS números existem.
    const semVenda = (noAr !== null && comVenda !== null && noAr >= comVenda) ? noAr - comVenda : null;
    const motivoSemAcervo = acervoIndisponivel ? TITLE_SEM_COMPANY : 'Acervo ainda não coletado';

    const situacaoProdutosSegura = situacaoProdutos && typeof situacaoProdutos === 'object' && !Array.isArray(situacaoProdutos)
        ? situacaoProdutos
        : {};

    const linhasPorFase = itensPorFase(produtosPorFase);

    const ultimasSeguras = ultimasPublicacoes && typeof ultimasPublicacoes === 'object' ? ultimasPublicacoes : {};
    const ultimasDisponiveis = ultimasSeguras.disponivel === true;
    const itensUltimas = Array.isArray(ultimasSeguras.itens) ? ultimasSeguras.itens : [];

    const integracoesSeguras = integracoes && typeof integracoes === 'object' ? integracoes : {};

    // ─── Cabeçalho da conta (261010-t02b) ────────────────────────────────
    //
    // REAL: nome, identificador, estado do token ML, nome do ERP declarado e a
    // contagem do catálogo. EXEMPLO: a reputação e o frescor do ERP.
    const nomeDaConta = textoSeguro(empresaSegura.nome, 'Conta');
    const identificadorDaConta = textoSeguro(empresaSegura.identificador, null);
    const tokenDaConta = textoSeguro(integracoesSeguras.mercado_livre?.token, textoSeguro(empresaSegura.token, 'sem_token'));
    const mlConectado = tokenDaConta === 'ativo';
    const mlSituacao = mlConectado
        ? 'Conectado'
        : (tokenDaConta === 'expirado' ? 'Token expirado' : 'Falta reconectar');
    const erpDeclarado = textoSeguro(integracoesSeguras.erp?.valor, null);
    // "Catálogo SKU ativo" é a soma de "Situação dos produtos" — número real da
    // conta. Só quando não há NENHUM produto cadastrado entra o valor de exemplo.
    const catalogoReal = totalDoCatalogo(situacaoProdutos);
    const catalogoTexto = catalogoReal !== null ? String(catalogoReal) : CATALOGO_EXEMPLO.sku_ativo;

    /**
     * O período marcado, como FUNÇÃO — o `.map()` do seletor chama isto em vez
     * de ler `periodoEscolhido` lá dentro (feedback_rollup_map_scope_bug.md).
     */
    function periodoEstaAtivo(opcao) {
        return opcao === periodoEscolhido;
    }

    function escolherPeriodo(opcao) {
        setPeriodoEscolhido(opcao);
    }

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

    /** Bucket por fase → lista de Produtos com os DOIS filtros (§7). */
    function abrirProdutosPorFase(chave) {
        if (!contaChave) return;
        const destino = destinoDaFase(chave);
        router.get(route('mlb.anuncios.publicador.produtos', { conta: contaChave, ...destino }));
    }

    /**
     * Um motivo da triagem → a lista de Publicações filtrada por ele. MESMO
     * destino da linha 8 de "O que fazer agora", nunca um segundo caminho.
     *
     * Existe como FUNÇÃO de propósito: o `.map()` dos alertas chama isto em vez
     * de ler `companyIdAbas` lá dentro — variável de escopo do componente lida
     * dentro de um `.map()` já foi eliminada pelo Rollup no bundle de produção
     * deste projeto (feedback_rollup_map_scope_bug.md).
     */
    function abrirAlertaNoAcervo(motivo) {
        if (!companyIdAbas) return;
        router.get(route('mlb.anuncios.meus', { company: companyIdAbas, motivo }));
    }

    function atualizarAgora() {
        if (!companyIdAbas) return;
        router.post(route('mlb.anuncios.meus.atualizar', { company: companyIdAbas }));
    }

    return (
        <div className="flex flex-col gap-6">

            {/* 0 — Cabeçalho da conta (tela 02 do Stitch, quick 261010-t02b).
                Nome e identificador são da conta; a reputação ("Conta Líder
                Platinum", "Platinum 100%") e o frescor do ERP são exemplo. O
                "Nova Publicação Direta" do mockup segue DESABILITADO com "Em
                breve": não existe fluxo de publicação direta a partir do painel,
                e prometer botão que não leva a lugar nenhum é pior que não ter
                o botão. */}
            <section className="rounded-xl bg-ecf-card p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-2">
                        <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Visão geral da conta</p>

                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-display text-[15px] font-bold text-white">{nomeDaConta}</h2>
                            {identificadorDaConta !== null && (
                                <span className="rounded-md border border-white/[0.08] bg-white/[0.03] px-2 py-0.5 font-mono text-[11px] text-white/55">
                                    {identificadorDaConta}
                                </span>
                            )}
                            <span className="inline-flex items-center gap-1 rounded-full border border-ecf-yellow/30 bg-ecf-yellow/10 px-2 py-0.5 text-[11px] font-bold text-ecf-yellow">
                                <BadgeCheck className="h-[14px] w-[14px]" aria-hidden="true" />
                                {CONTA_EXEMPLO.selo}
                            </span>
                        </div>

                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] font-normal text-white/55">
                            <span className="inline-flex items-center gap-1.5">
                                <span aria-hidden="true" className={cn('h-2 w-2 rounded-full', mlConectado ? 'bg-emerald-400' : 'bg-amber-300')} />
                                <span className="font-bold text-white">Mercado Livre:</span>
                                {mlConectado ? `${mlSituacao} (${CONTA_EXEMPLO.reputacao})` : mlSituacao}
                            </span>
                            <span aria-hidden="true" className="text-white/20">•</span>
                            <span className="inline-flex items-center gap-1.5">
                                <span aria-hidden="true" className="h-2 w-2 rounded-full bg-ecf-yellow/70" />
                                <span className="font-bold text-white">{erpDeclarado !== null ? `ERP ${erpDeclarado}:` : 'ERP:'}</span>
                                {erpDeclarado !== null ? ERP_EXEMPLO.frescor : 'não informado'}
                            </span>
                            <span aria-hidden="true" className="text-white/20">•</span>
                            <span>
                                Catálogo SKU ativo: <span className="font-bold text-white">{catalogoTexto}</span>
                            </span>
                            <SeloExemplo title="A reputação da conta e o frescor do ERP são de exemplo. O nome, o identificador, o estado da conexão com o Mercado Livre e a contagem do catálogo são desta conta." />
                        </div>

                        <p className="text-[13px] font-normal text-white/55">
                            Tudo que já está gravado sobre esta conta — nenhuma consulta ao Mercado Livre nesta tela.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <div className="inline-flex items-center gap-1 rounded-lg border border-white/[0.08] bg-white/[0.03] p-1">
                            {PERIODOS_EXEMPLO.opcoes.map((opcao) => {
                                // Flags calculadas DENTRO do callback — variável de escopo do
                                // componente lida só dentro do .map() já foi eliminada pelo
                                // Rollup no bundle de produção (feedback_rollup_map_scope_bug.md).
                                const rotuloDoPeriodo = textoSeguro(opcao, '');
                                const ativo = periodoEstaAtivo(rotuloDoPeriodo);

                                return (
                                    <button
                                        key={rotuloDoPeriodo}
                                        type="button"
                                        title={TITULO_PERIODO}
                                        onClick={() => escolherPeriodo(rotuloDoPeriodo)}
                                        className={cn(
                                            'rounded-md px-3 py-1 text-[13px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                            ativo
                                                ? 'border border-ecf-yellow/30 bg-ecf-yellow/10 font-bold text-ecf-yellow'
                                                : 'border border-transparent font-normal text-white/55 hover:text-white',
                                        )}
                                    >
                                        {rotuloDoPeriodo}
                                    </button>
                                );
                            })}
                        </div>
                        <SeloExemplo title={TITULO_PERIODO} />
                        <button
                            type="button"
                            disabled
                            title="Ainda não existe publicação direta a partir do painel."
                            className="inline-flex h-10 cursor-not-allowed items-center gap-2 whitespace-nowrap rounded-lg border border-white/[0.10] bg-white/[0.03] px-4 text-[13px] font-normal text-white/80 opacity-40"
                        >
                            <Send className="h-4 w-4" aria-hidden="true" />
                            Nova publicação direta
                            <span className="rounded-md border border-white/[0.10] px-2 py-0.5 text-[11px] font-normal text-white/55">Em breve</span>
                        </button>
                    </div>
                </div>
            </section>

            {/* 1 — A faixa de KPIs. O mockup tem 5; aqui são 6, porque
                "Publicados nos últimos 30 dias" já existia e nada que a tela
                fazia desde 08/10 pode sumir. */}
            <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                <CartaoKpi
                    rotulo="No ar"
                    numero={semAcervoOuNuncaColetado ? null : noAr}
                    motivoVazio={motivoSemAcervo}
                    nota="anúncios ativos e pausados"
                    icone={<Boxes className="h-4 w-4" aria-hidden="true" />}
                    subs={[
                        ...(noArBase !== null ? [{ rotulo: 'produtos base', valor: noArBase }] : []),
                        ...(noArKits !== null ? [{ rotulo: 'produtos em kit', valor: noArKits }] : []),
                    ]}
                    botaoTexto={!acervoIndisponivel && nuncaColetado ? 'Atualizar agora' : null}
                    onBotao={atualizarAgora}
                    onClick={!semAcervoOuNuncaColetado && companyIdAbas
                        ? () => router.get(route('mlb.anuncios.meus', { company: companyIdAbas, status: 'acionaveis' }))
                        : null}
                />
                <CartaoKpi
                    rotulo="Aguardando ação"
                    numero={aguardandoAcao}
                    nota="produtos esperando um passo seu"
                    icone={<ClipboardList className="h-4 w-4" aria-hidden="true" />}
                    destaque="atencao"
                    destaqueTexto={aguardandoAcao > 0 ? 'Prioritário' : null}
                    subs={[
                        { rotulo: 'Sem oferta', valor: semOferta },
                        ...(prontosFase2 !== null ? [{ rotulo: 'Prontos para a Fase 2', valor: prontosFase2 }] : []),
                    ]}
                    onClick={contaChave ? () => abrirProdutos('todos') : null}
                />
                <CartaoKpi
                    rotulo="Publicados nos últimos 30 dias"
                    numero={publicados30d}
                    nota={`${publicados30dPessoas} ${publicados30dPessoas === 1 ? 'pessoa' : 'pessoas'}`}
                    icone={<Send className="h-4 w-4" aria-hidden="true" />}
                    onClick={companyIdAbas ? () => router.get(route('mlb.anuncios.historico', { company: companyIdAbas })) : null}
                />
                <CartaoKpi
                    rotulo="Com venda"
                    numero={semAcervoOuNuncaColetado ? null : comVenda}
                    motivoVazio={motivoSemAcervo}
                    // ⚠️ O mockup chama este cartão de "Tração (30D)". Aqui NÃO:
                    // `sold_quantity` é a venda ACUMULADA do anúncio, não uma
                    // janela de 30 dias — afirmar a janela seria mentir.
                    nota={tracaoPct !== null ? `${tracaoPct}% do que está no ar já vendeu` : 'venda acumulada do anúncio'}
                    icone={<TrendingUp className="h-4 w-4" aria-hidden="true" />}
                    barraPct={tracaoPct}
                    botaoTexto={!acervoIndisponivel && nuncaColetado ? 'Atualizar agora' : null}
                    onBotao={atualizarAgora}
                    onClick={!semAcervoOuNuncaColetado && companyIdAbas
                        ? () => router.get(route('mlb.anuncios.meus', { company: companyIdAbas, comVenda: 1 }))
                        : null}
                />
                <CartaoKpi
                    rotulo="Criativos por IA"
                    numero={criativosPacks}
                    motivoVazio="Ainda não medimos os criativos desta conta"
                    // O mockup escreve "32 packs reaproveitados". O acervo NÃO
                    // registra reuso — o número não existe e não entra aqui.
                    nota="packs de imagens gerados nesta conta"
                    icone={<Sparkles className="h-4 w-4" aria-hidden="true" />}
                    onClick={contaChave ? () => abrirProdutos('todos') : null}
                />
                <CartaoKpi
                    rotulo="Revisão humana"
                    numero={null}
                    motivoVazio="Não existe no sistema — nada passa por revisão manual hoje"
                    icone={<ShieldCheck className="h-4 w-4" aria-hidden="true" />}
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_340px]">
                <div className="flex flex-col gap-6">

                {/* 2 — O que fazer agora */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0">
                            <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">O que fazer agora</h2>
                            {/* O mockup promete "estoque sincronizado do ERP com alta
                                demanda orgânica". Nada disso é lido aqui — dizer o que a
                                fila realmente é vale mais que repetir a legenda do mockup. */}
                            <p className="text-[13px] font-normal text-white/55">
                                As pendências que o sistema sabe medir. Não lemos estoque do ERP nem demanda orgânica.
                            </p>
                        </div>
                        {linhasOQueFazer.length > 0 && (
                            <span className="rounded-md border border-amber-400/40 bg-amber-400/10 px-2 py-0.5 text-[11px] font-bold text-amber-300">
                                {linhasOQueFazer.length} {linhasOQueFazer.length === 1 ? 'pendência' : 'pendências'}
                            </span>
                        )}
                    </div>
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

                {/* 2b — Desempenho rápido das publicações (tela 02): a divisão
                    com venda × sem venda do acervo no ar.

                    ⚠️ O mockup desenha também um sparkline de conversão diária e
                    um seletor Hoje/7 dias/Este mês. Ficaram FORA de propósito: a
                    série diária existe (`ml_acervo_metricas_diarias`), então isso
                    é factível com dado real e merece tarefa própria — um gráfico
                    de mentira agora seria pior que nenhum gráfico. */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="min-w-0">
                            <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Desempenho rápido das publicações</h2>
                            {/* O mockup chama isto de "tração nos últimos 30 dias".
                                `sold_quantity` é a venda ACUMULADA do anúncio — a
                                janela não existe no dado e não pode ser afirmada. */}
                            <p className="text-[13px] font-normal text-white/55">
                                Venda acumulada do anúncio, não uma janela de 30 dias.
                            </p>
                        </div>
                        {tracaoPct !== null && (
                            <span className="rounded-md border border-ecf-yellow/40 bg-ecf-yellow/10 px-2 py-0.5 text-[11px] font-bold text-ecf-yellow">
                                {tracaoPct}% já vendeu
                            </span>
                        )}
                    </div>

                    {noAr === null ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">{motivoSemAcervo}</p>
                    ) : noAr === 0 ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">Nenhum anúncio no ar para medir.</p>
                    ) : (
                        <div className="mt-3 flex flex-col gap-2">
                            <div className="h-2 w-full overflow-hidden rounded-full bg-white/10">
                                <div className="h-2 rounded-full bg-ecf-yellow/60" style={{ width: `${Math.max(0, Math.min(100, tracaoPct ?? 0))}%` }} />
                            </div>
                            <div className="flex items-center justify-between text-[13px] font-normal text-white/70">
                                <span>Com venda registrada ({comVenda ?? 0} {(comVenda ?? 0) === 1 ? 'anúncio' : 'anúncios'})</span>
                                <span className="font-mono tabular-nums">{tracaoPct !== null ? `${tracaoPct}%` : '—'}</span>
                            </div>
                            <div className="flex items-center justify-between text-[13px] font-normal text-white/55">
                                <span>Sem venda registrada ({semVenda ?? 0} {(semVenda ?? 0) === 1 ? 'anúncio' : 'anúncios'})</span>
                                <span className="font-mono tabular-nums">{tracaoPct !== null ? `${100 - tracaoPct}%` : '—'}</span>
                            </div>
                            {semVenda !== null && semVenda > 0 && contaChave && (
                                <button
                                    type="button"
                                    onClick={() => router.get(route('mlb.anuncios.publicador.alavancas.index', { conta: contaChave }))}
                                    className={cn(BOTAO_SECUNDARIO, 'mt-1 self-start')}
                                >
                                    Ver alavancas desta conta
                                </button>
                            )}
                        </div>
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
                                    <p className="font-mono text-[24px] font-bold tabular-nums text-white">{numero}</p>
                                    <p className="text-[11px] font-normal text-white/55">{rotulo}</p>
                                </button>
                            );
                        })}
                    </div>
                </section>

                {/* 3b — Produtos por fase (Fase 175, §7) — ABAIXO do bloco de cima,
                    não no lugar dele. Sem a prop (servidor antigo) o bloco nem
                    aparece, e a tela fica exatamente como era. */}
                {linhasPorFase.length > 0 && (
                    <section className="rounded-xl bg-ecf-card p-4">
                        <h2 className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Produtos por fase</h2>
                        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-5">
                            {linhasPorFase.map((linha) => {
                                // Flags calculadas DENTRO do callback — variável de escopo do
                                // componente lida só dentro do .map() já foi eliminada pelo
                                // Rollup no bundle de produção (feedback_rollup_map_scope_bug.md).
                                const chaveDaFase = linha.chave;
                                const numeroDaFase = numeroSeguro(linha.numero) ?? 0;
                                const rotuloDaFase = textoSeguro(linha.rotulo, chaveDaFase);

                                return (
                                    <button
                                        key={chaveDaFase}
                                        type="button"
                                        onClick={() => abrirProdutosPorFase(chaveDaFase)}
                                        className="rounded-lg border border-white/[0.08] bg-white/[0.03] p-3 text-left hover:bg-white/[0.06] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                                    >
                                        <p className="font-mono text-[24px] font-bold tabular-nums text-white">{numeroDaFase}</p>
                                        <p className="text-[11px] font-normal text-white/55">{rotuloDaFase}</p>
                                    </button>
                                );
                            })}
                        </div>
                    </section>
                )}

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
                                // Coluna Fase (Fase 175, §7): o rótulo vem pronto do servidor
                                // (`rotuloFase()`); publicação antiga, sem fase, mostra "—".
                                const rotuloDaFase = textoSeguro(linha.rotulo_fase, '—');

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
                                        <span className="text-[11px] text-white/55">{rotuloDaFase}</span>
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

                {/* 4b — Lateral: Alertas (tela 02).

                    São a TRIAGEM do acervo que esta tela já carregava, com
                    outro nome. `motivosDef()` segue sendo a fonte única dos
                    motivos — nada é reimplementado aqui, nem rótulo nem cor.

                    ⚠️ O mockup chama o bloco de "Alertas Meli & ERP". Aqui não:
                    nada vem do ERP, e o título não pode prometer o que não há. */}
                {alerta.presente && (
                    <section className="rounded-xl bg-ecf-card p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                                <AlertTriangle className="h-4 w-4" aria-hidden="true" />
                                Alertas do acervo
                            </h2>
                            {alerta.disponivel && alerta.total > 0 && (
                                <span className="rounded-md border border-red-400/40 bg-red-400/10 px-2 py-0.5 text-[11px] font-bold text-red-300">
                                    {alerta.total} {alerta.total === 1 ? 'anúncio' : 'anúncios'}
                                </span>
                            )}
                        </div>

                        {!alerta.disponivel ? (
                            <p className="mt-3 text-[13px] font-normal text-white/55">{TITLE_SEM_COMPANY}</p>
                        ) : (
                            <div className="mt-3 flex flex-col gap-2">
                                {alerta.itens.filter((linha) => (numeroSeguro(linha.total) ?? 0) > 0).length === 0 ? (
                                    <p className="text-[13px] font-normal text-white/55">Nenhum alerta no acervo desta conta.</p>
                                ) : alerta.itens.map((bruto) => {
                                    // Flags calculadas DENTRO do callback — variável de escopo do
                                    // componente lida só dentro do .map() já foi eliminada pelo
                                    // Rollup no bundle de produção (feedback_rollup_map_scope_bug.md).
                                    const chaveDoAlerta = bruto.chave;
                                    const totalDoAlerta = numeroSeguro(bruto.total) ?? 0;
                                    const rotuloDoAlerta = textoSeguro(bruto.label, chaveDoAlerta);
                                    const critico = textoSeguro(bruto.cor, '') === 'red';

                                    if (totalDoAlerta === 0) return null;

                                    return (
                                        <button
                                            key={chaveDoAlerta}
                                            type="button"
                                            onClick={() => abrirAlertaNoAcervo(chaveDoAlerta)}
                                            className={cn(
                                                'flex items-center justify-between gap-2 rounded-lg border p-3 text-left text-[13px] font-normal hover:bg-white/[0.04] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow',
                                                critico ? 'border-red-400/30 text-red-200' : 'border-amber-400/30 text-amber-100',
                                            )}
                                        >
                                            <span className="min-w-0 truncate">{rotuloDoAlerta}</span>
                                            <span className="font-mono tabular-nums">{totalDoAlerta}</span>
                                        </button>
                                    );
                                })}
                                <p className="text-[11px] font-normal text-white/40">
                                    Do acervo do Mercado Livre. Nada aqui vem do ERP.
                                </p>
                            </div>
                        )}
                    </section>
                )}

                {/* 4c — Lateral: Quem publicou.

                    Ocupa o lugar do "Atividade da Equipe" do mockup, por decisão
                    do usuário: é o que o sistema de fato sabe — quem publicou o
                    quê e quando. O mockup inventa "gerou 5 imagens IA" e "revisão
                    aprovada"; nenhum dos dois existe como registro. */}
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
                    {companyIdAbas && (
                        <button
                            type="button"
                            onClick={() => router.get(route('mlb.anuncios.historico', { company: companyIdAbas }))}
                            className="mt-3 inline-flex items-center gap-1 text-[13px] font-normal text-white/55 hover:text-ecf-yellow"
                        >
                            Ver histórico completo
                            <ChevronRight className="h-4 w-4" aria-hidden="true" />
                        </button>
                    )}
                </section>

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

                {/* 6a — Lateral: atalho de Criativos (o par de cards do rodapé
                    do mockup; aqui empilhados, porque a coluna tem 340px e dois
                    cards lado a lado ficariam ilegíveis).

                    Não há biblioteca de criativos no Publicador: a geração mora
                    DENTRO do produto, no card de Fotos (Fase 165). O atalho leva
                    para lá em vez de prometer uma tela que não existe. */}
                <section className="rounded-xl bg-ecf-card p-4">
                    <div className="flex items-start justify-between gap-2">
                        <h2 className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                            <Sparkles className="h-4 w-4" aria-hidden="true" />
                            Criativos por IA
                        </h2>
                        <span className="font-mono text-[13px] font-bold tabular-nums text-white">
                            {criativosPacks !== null ? criativosPacks : '—'}
                        </span>
                    </div>
                    <p className="mt-2 text-[13px] font-normal text-white/55">
                        {criativosPacks !== null
                            ? `${criativosPacks === 1 ? 'pack gerado' : 'packs gerados'} nesta conta. A geração fica dentro do produto, no card de Fotos.`
                            : 'Ainda não medimos os criativos desta conta.'}
                    </p>
                    {contaChave && (
                        <button
                            type="button"
                            onClick={() => abrirProdutos('todos')}
                            className="mt-3 inline-flex items-center gap-1 text-[13px] font-normal text-white/55 hover:text-ecf-yellow"
                        >
                            Abrir Produtos
                            <ChevronRight className="h-4 w-4" aria-hidden="true" />
                        </button>
                    )}
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

                </div>
            </div>
        </div>
    );
}
