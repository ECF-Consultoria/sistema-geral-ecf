import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { cn } from '@/lib/utils';
import { criarRota, mensagemDe } from '@/Components/Publicador/apoio.js';
import { textoSeguro } from './BarraDaConta';
import CartaoDaFase from './CartaoDaFase';
import CartaoKpi from './CartaoKpi';
import PainelCriarFase from './PainelCriarFase';
import SeloStatusProduto from './SeloStatusProduto';
import { iniciaisDoNome } from './layoutDaListaDeProdutos.js';
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
const PILULA = 'rounded-full border border-white/[0.08] bg-white/[0.04] px-2 py-0.5 text-[11px] font-normal text-white/55';

// ─── Os quadros financeiros do mockup que o sistema NÃO tem ────────────────
//
// Decisão 2 do plano (quick 261009-t04), mesmo tratamento das telas 01 e 02:
// o quadro é DESENHADO e fica VAZIO, dizendo o motivo. "Não sabemos" não é
// "é zero" — e número inventado com cara de certo é pior que número nenhum.
// Nenhum destes existe em `pub_produtos`, `pub_rascunhos` nem no acervo do ML.
const SEM_CUSTO = 'Não temos custo de compra no Publicador';
const SEM_PRECO_SUGERIDO = 'Não calculamos preço nem margem sem o custo';
const SEM_SAUDE = 'Não avaliamos a saúde do cadastro nesta tela';
const SEM_ESTOQUE = 'O rascunho ainda não informou estoque';
const NAO_COLETADO = 'ainda não coletado';
const FORA_DESTA_TELA = 'não calculamos aqui';

// Os títulos da metodologia de fases. São DESCRIÇÃO do método, não dado do
// servidor: nenhum número, nenhum ticket, nenhum percentual.
const TITULO_DA_FASE = {
    1: 'Publicação individual',
    2: 'Kits múltiplos',
    3: 'Cross-selling e combos',
};
const DESCRICAO_DA_FASE = {
    1: 'O produto sozinho, uma unidade por venda — a fase que valida o cadastro no catálogo.',
    2: 'O mesmo produto em kit de mais de uma unidade: o frete se dilui no ticket maior.',
    3: 'Anúncios que juntam este produto a outros SKUs já cadastrados na conta.',
};

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
 * Um número da faixa "Performance últimos 30 dias".
 *
 * ⚠️ Sem valor ele escreve "— {motivo}", NUNCA 0. Um 0 aqui diria "não vendeu"
 * quando a verdade é "ainda não coletamos" — a mesma distinção que, nos
 * learnings deste projeto, já custou caro no "dia sem linha ≠ venda zero".
 */
function Fato({ rotulo, valor = null, motivo = '' }) {
    const numero = numeroSeguro(valor);
    const texto = numero !== null ? String(numero) : textoSeguro(valor, '');

    return (
        <span className="text-[13px] font-normal text-white/55">
            {textoSeguro(rotulo, '')}:{' '}
            {texto !== '' ? (
                <span className="font-mono tabular-nums text-white/80">{texto}</span>
            ) : (
                <span className="text-white/40">— {textoSeguro(motivo, FORA_DESTA_TELA)}</span>
            )}
        </span>
    );
}

/**
 * A tela do Produto (§3 da ETAPA-3, Fase 175 plano 04), no layout da tela 04 do
 * pacote do Stitch (quick 261009-t04): cabeçalho com a faixa de números, matriz
 * de fases, anúncios no ar, biblioteca de criativos, o rodapé "Pronto para a
 * Fase N", histórico e a lateral de Mapeamento.
 *
 * Os 6 blocos que a Etapa 3 entregou continuam todos aqui — mudaram de lugar e
 * de forma, nunca de efeito. A lateral de Criativos virou a grade da coluna
 * principal; o resto ficou onde estava.
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

    // ─── Cabeçalho (tela 04, task 2) ───
    const nomeDaEmpresa = textoSeguro(objetoSeguro(empresa).nome, '');
    const iniciais = iniciaisDoNome(nome);
    const eanDoMapa = mapaVazio ? '' : textoSeguro(mapa.ean, '');
    // A ficha curta do mockup ("Sensor PixArt • Peso 78g • Categoria"), montada
    // só com o que o Mapeamento Estrutural de fato trouxe.
    const fichaCurta = mapaVazio ? '' : [
        [medidas.comprimento, medidas.largura, medidas.altura].every((v) => numeroSeguro(v) !== null)
            ? `${decimal(medidas.comprimento)} × ${decimal(medidas.largura)} × ${decimal(medidas.altura)} ${textoSeguro(medidas.unidade, 'cm')}`
            : '',
        decimal(mapa.peso) !== null ? `${decimal(mapa.peso)} kg` : '',
        textoSeguro(mapa.material, ''),
    ].filter((parte) => parte !== '').join(' • ');

    /**
     * Soma de uma métrica das ofertas, ou `null` quando NENHUMA oferta trouxe o
     * número. ⚠️ Zero só aparece se o acervo tiver mesmo devolvido zero —
     * ausência de coleta nunca vira 0.
     */
    const somaDasOfertas = (chave) => {
        let soma = null;
        for (const bruto of listaOfertas) {
            const n = numeroSeguro(objetoSeguro(bruto)[chave]);
            if (n !== null) soma = (soma ?? 0) + n;
        }

        return soma;
    };
    const vendasSomadas = somaDasOfertas('vendas');
    const visitasSomadas = somaDasOfertas('visitas');

    // Quantas imagens o kit REALMENTE tem. ⚠️ O mockup fala em "8 imagens
    // prontas" e um custo total de IA fixo: é o kit de 7 imagens, que deixou de
    // existir (quick 261007-kit2). A grade conta o que veio do servidor.
    let totalDeImagens = 0;
    for (const bruto of listaCriativos) {
        for (const mini of listaSegura(objetoSeguro(bruto).miniaturas)) {
            const url = objetoSeguro(mini).url;
            if (typeof url === 'string' && url !== '') totalDeImagens += 1;
        }
    }

    // Decisão 6: a metodologia tem três fases, então o cartão de roadmap só
    // existe enquanto houver uma fase seguinte à próxima para prometer.
    const numeroDoRoadmap = proximoNumero + 1 <= 3 ? proximoNumero + 1 : null;

    /**
     * ⚠️ O botão "Criar Fase N" é definido UMA vez e renderizado em dois
     * lugares (o cartão da próxima fase e o rodapé "Pronto para a Fase N" do
     * mockup). Mesmo elemento, mesmo gatilho, mesmo painel — o rodapé REUSA a
     * ação, não cria um segundo caminho para ela.
     */
    const acaoCriarFase = podeCriarFase ? (
        <button type="button" onClick={() => setCriarFaseAberto(true)} className={BOTAO_SECUNDARIO}>
            Criar Fase {proximoNumero}
        </button>
    ) : (
        <button type="button" disabled aria-disabled="true" title={motivoDeNaoCriar} className={BOTAO_TRAVADO}>
            Criar Fase {proximoNumero}
        </button>
    );

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
                    {/* A trilha do mockup: empresa › Produtos › este produto. Só
                        com a conta na tela — sem ela não há para onde voltar. */}
                    {contaDaTela !== null && (
                        <p className="mb-3 flex flex-wrap items-center gap-1 text-[11px] font-normal text-white/40">
                            {nomeDaEmpresa !== '' && (
                                <>
                                    <span>{nomeDaEmpresa}</span>
                                    <span aria-hidden="true">›</span>
                                </>
                            )}
                            <Link href={rotaDoPublicador('produtos', contaDaTela)} className="hover:text-ecf-yellow">Produtos</Link>
                            <span aria-hidden="true">›</span>
                            <span className="text-white/55">{nome}</span>
                        </p>
                    )}

                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 items-start gap-4">
                            {/* Sem foto, as INICIAIS — mesma solução da lista de
                                Produtos (quick 261009-prd), nunca uma imagem quebrada. */}
                            {fotoUrl !== null ? (
                                <img
                                    src={fotoUrl}
                                    alt=""
                                    loading="lazy"
                                    className="h-20 w-20 shrink-0 rounded-lg bg-white object-contain"
                                />
                            ) : (
                                <span
                                    data-miniatura={iniciais}
                                    aria-hidden="true"
                                    className="flex h-20 w-20 shrink-0 items-center justify-center rounded-lg border border-white/[0.08] bg-white/[0.04] font-display text-[24px] font-bold text-white/40"
                                >
                                    {iniciais}
                                </span>
                            )}
                            <div className="min-w-0">
                                <p className="flex flex-wrap items-center gap-2">
                                    <span className={PILULA}>{categoria}</span>
                                    {eanDoMapa !== '' && <span className={PILULA}>EAN: {eanDoMapa}</span>}
                                    {origemRotulo !== null && <span className={PILULA}>{origemRotulo}</span>}
                                </p>
                                <h1 className="mt-2 font-display text-[24px] font-bold leading-tight text-white">{nome}</h1>
                                <p className="mt-1 font-mono text-[11px] text-white/70">SKU: {sku}</p>
                                {fichaCurta !== '' && (
                                    <p className="mt-1 text-[13px] font-normal text-white/55">{fichaCurta}</p>
                                )}
                            </div>
                        </div>

                        {editorDoBase !== null ? (
                            <Link href={editorDoBase} className={BOTAO_SECUNDARIO}>Editar Fase 1</Link>
                        ) : (
                            <button type="button" disabled className={BOTAO_TRAVADO}>Editar Fase 1</button>
                        )}
                    </div>

                    {/* A faixa de números do mockup. ⚠️ Só o estoque existe — e
                        ele é o do RASCUNHO, não o de um ERP (decisão 3). Os três
                        quadros financeiros ficam desenhados e VAZIOS, dizendo o
                        motivo (decisão 2). */}
                    <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <CartaoKpi
                            rotulo="Estoque do rascunho"
                            numero={estoqueTotal}
                            nota="unidades somadas das variantes do rascunho"
                            motivoVazio={SEM_ESTOQUE}
                        />
                        <CartaoKpi rotulo="Custo médio" numero={null} motivoVazio={SEM_CUSTO} />
                        <CartaoKpi rotulo="Preço sugerido" numero={null} motivoVazio={SEM_PRECO_SUGERIDO} />
                        <CartaoKpi rotulo="Saúde cadastral" numero={null} motivoVazio={SEM_SAUDE} />
                    </div>

                    {/* Performance de 30 dias: vendas e visitas são reais (vêm do
                        acervo, somadas das ofertas). GMV, conversão e ranking não
                        chegam nesta tela — ficam em branco, com o motivo, em vez
                        de virar estimativa. O sparkline do mockup fica de fora:
                        não há série temporal nenhuma para desenhar. */}
                    <div className="mt-4 border-t border-white/[0.06] pt-3">
                        <p className={TITULO_BLOCO}>Performance últimos 30 dias</p>
                        <div className="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <Fato rotulo="Vendas" valor={vendasSomadas !== null ? `${vendasSomadas} un` : null} motivo={NAO_COLETADO} />
                            <Fato rotulo="Visitas" valor={visitasSomadas} motivo={NAO_COLETADO} />
                            <Fato rotulo="GMV faturado" motivo={FORA_DESTA_TELA} />
                            <Fato rotulo="Conversão estimada" motivo={FORA_DESTA_TELA} />
                            <Fato rotulo="Ranking de categoria" motivo="não consultamos o Mercado Livre aqui" />
                        </div>
                        <p className="mt-2 text-[11px] font-normal text-white/40">
                            Vendas e visitas vêm do acervo do Mercado Livre. Faturamento, conversão e posição na categoria não chegam a esta tela — por isso ficam em branco em vez de virar palpite.
                        </p>
                    </div>
                </section>

                {/* 2 — Fases da publicação (a "Metodologia de evolução" do mockup) */}
                <section className={CARTAO}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className={TITULO_BLOCO}>Fases da publicação</h2>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                Cada fase é um anúncio próprio do mesmo produto, com mais unidades por venda. A fase seguinte nasce com o título, a ficha técnica e as fotos da anterior.
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-3 text-[11px] font-normal text-white/40">
                            <span className="inline-flex items-center gap-1">
                                <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-emerald-400" />No ar
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-ecf-yellow/70" />Disponível
                            </span>
                            <span className="inline-flex items-center gap-1">
                                <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-white/30" />Roadmap
                            </span>
                        </div>
                    </div>

                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                        {listaFases.length === 0 ? (
                            // Nunca uma lista vazia: a Fase 1 é o próprio produto, mesmo sem rascunho.
                            <CartaoDaFase
                                numero={1}
                                titulo={TITULO_DA_FASE[1]}
                                subtitulo="1 unidade"
                                descricao={DESCRICAO_DA_FASE[1]}
                                variante="concluida"
                                selo="Não iniciada"
                                acao={editorDoBase !== null
                                    ? <Link href={editorDoBase} className={BOTAO_SECUNDARIO}>Começar rascunho</Link>
                                    : <button type="button" disabled className={BOTAO_TRAVADO}>Começar rascunho</button>}
                            />
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
                            // ⚠️ Os FATOS da fase também saem daqui de dentro: preço,
                            // vendas e criativos são lidos das listas no próprio
                            // callback (armadilha do Rollup). Nenhum deles vira 0 por
                            // falta de dado — sem número, a linha diz o motivo.
                            const ofertasDaFase = listaOfertas
                                .map((o) => objetoSeguro(o))
                                .filter((o) => numeroFase !== null && numeroSeguro(o.fase) === numeroFase);
                            const precosDaFase = ofertasDaFase.map((o) => numeroSeguro(o.preco)).filter((v) => v !== null);
                            const precoTexto = precosDaFase.length === 0
                                ? null
                                : (Math.min(...precosDaFase) === Math.max(...precosDaFase)
                                    ? moeda(Math.min(...precosDaFase))
                                    : `${moeda(Math.min(...precosDaFase))} a ${moeda(Math.max(...precosDaFase))}`);
                            let vendasDaFase = null;
                            for (const oferta of ofertasDaFase) {
                                const v = numeroSeguro(oferta.vendas);
                                if (v !== null) vendasDaFase = (vendasDaFase ?? 0) + v;
                            }
                            let imagensDaFase = 0;
                            for (const bruto of listaCriativos) {
                                const grupo = objetoSeguro(bruto);
                                if (numeroFase === null || numeroSeguro(grupo.fase) !== numeroFase) continue;
                                for (const mini of listaSegura(grupo.miniaturas)) {
                                    const url = objetoSeguro(mini).url;
                                    if (typeof url === 'string' && url !== '') imagensDaFase += 1;
                                }
                            }
                            const mlbDaFase = ofertasDaFase
                                .map((o) => o.ml_item_id)
                                .find((v) => typeof v === 'string' && v !== '') ?? null;
                            const tituloDaFase = TITULO_DA_FASE[numeroFase]
                                ?? (quantidade >= 2 ? `Kit de ${quantidade} unidades` : 'Publicação individual');

                            return (
                                <CartaoDaFase
                                    key={indice}
                                    numero={numeroFase}
                                    titulo={tituloDaFase}
                                    subtitulo={rotuloFase}
                                    descricao={DESCRICAO_DA_FASE[numeroFase] ?? ''}
                                    variante="concluida"
                                    selo={rotuloEstado}
                                    destaque={destacada}
                                    linhas={[
                                        {
                                            rotulo: 'Anúncios no ar',
                                            valor: noAr > 0 ? (noAr === 1 ? '1 oferta' : `${noAr} ofertas`) : null,
                                            motivo: 'nenhum anúncio no ar',
                                        },
                                        { rotulo: 'Preço unitário', valor: precoTexto, motivo: NAO_COLETADO },
                                        {
                                            rotulo: 'Criativos aplicados',
                                            valor: imagensDaFase > 0 ? (imagensDaFase === 1 ? '1 imagem' : `${imagensDaFase} imagens`) : null,
                                            motivo: 'nenhum kit aprovado',
                                        },
                                        { rotulo: 'Vendas acumuladas', valor: vendasDaFase, motivo: NAO_COLETADO },
                                    ]}
                                >
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        <SeloStatusProduto status={estado} />
                                        <span className="font-mono text-[11px] text-white/40">{skuFase}</span>
                                    </div>
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
                                    <div className="mt-2 flex flex-wrap items-center gap-3">
                                        {editorDaFase !== null && (
                                            <Link href={editorDaFase} className="text-[13px] font-normal text-white/55 hover:text-ecf-yellow">
                                                Abrir no editor
                                            </Link>
                                        )}
                                        {mlbDaFase !== null && <LinkMl mlb={mlbDaFase} className="text-[11px]" />}
                                    </div>
                                </CartaoDaFase>
                            );
                        })}

                        {/* O cartão da PRÓXIMA fase — a "Oportunidade" do mockup.
                            A regra de habilitação chega pronta do servidor
                            (`proxima_fase.habilitado`) e o motivo aparece visível quando ela
                            é falsa — desabilitado COM explicação, nunca escondido (D23).
                            Desde o 175-07 o botão ABRE o painel "Criar Fase N" (§4).
                            ⚠️ O botão é o `acaoCriarFase`, definido UMA vez: o rodapé
                            "Pronto para a Fase N" renderiza esse mesmo elemento. Não há
                            segundo caminho para a mesma ação. */}
                        <CartaoDaFase
                            numero={proximoNumero}
                            titulo={TITULO_DA_FASE[proximoNumero]
                                ?? (proximaQuantidade !== null ? `Kit de ${proximaQuantidade} unidades` : 'Próxima fase')}
                            subtitulo={proximaQuantidade !== null ? `Kit ${proximaQuantidade}` : ''}
                            descricao={DESCRICAO_DA_FASE[proximoNumero] ?? ''}
                            variante={podeCriarFase ? 'oportunidade' : 'roadmap'}
                            selo={podeCriarFase ? 'Disponível para criação' : 'Em planejamento'}
                            linhas={[
                                {
                                    rotulo: 'Kit sugerido',
                                    valor: proximaQuantidade !== null ? `${proximaQuantidade} unidades` : null,
                                    motivo: 'o servidor ainda não sugeriu o tamanho',
                                },
                                // Decisão 2: o preço do kit e a economia do cliente são
                                // desenhados e VAZIOS — quem define o preço é o editor,
                                // depois que a fase existe.
                                { rotulo: 'Preço do kit', valor: null, motivo: 'definido no editor, depois de criar a fase' },
                                { rotulo: 'Economia para o cliente', valor: null, motivo: 'depende do preço do kit' },
                                { rotulo: 'Herança técnica', valor: 'título, ficha e fotos do base' },
                                {
                                    rotulo: 'Estoque do rascunho',
                                    valor: estoqueTotal !== null ? `${estoqueTotal} un` : null,
                                    motivo: SEM_ESTOQUE,
                                },
                            ]}
                            acao={acaoCriarFase}
                            notaDaAcao={podeCriarFase
                                ? 'Herda as fotos e a ficha já aprovadas da fase anterior.'
                                : motivoDeNaoCriar}
                        />

                        {/* O cartão de ROADMAP da fase seguinte (decisão 6): sem ticket
                            estimado e sem requisito inventado. O bloqueio que ele mostra é
                            real — a próxima fase só aparece quando a anterior existe. */}
                        {numeroDoRoadmap !== null && (
                            <CartaoDaFase
                                numero={numeroDoRoadmap}
                                titulo={TITULO_DA_FASE[numeroDoRoadmap] ?? `Fase ${numeroDoRoadmap}`}
                                descricao={DESCRICAO_DA_FASE[numeroDoRoadmap] ?? ''}
                                variante="roadmap"
                                selo="Em planejamento"
                                linhas={[
                                    { rotulo: 'Ticket estimado', valor: null, motivo: 'não estimamos preço aqui' },
                                    { rotulo: 'Requisito', valor: `a Fase ${proximoNumero} precisa existir` },
                                ]}
                                notaDaAcao={`Liberado depois que a Fase ${proximoNumero} existir.`}
                            />
                        )}
                    </div>
                </section>

                {/* 3 — Publicações & ofertas no Mercado Livre */}
                <section className={CARTAO}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className={TITULO_BLOCO}>Anúncios no ar</h2>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                As publicações deste SKU no Mercado Livre, com o link de cada uma.
                            </p>
                        </div>
                        {listaOfertas.length > 0 && (
                            <span className={PILULA}>
                                {listaOfertas.length === 1 ? '1 anúncio ativo' : `${listaOfertas.length} anúncios ativos`}
                            </span>
                        )}
                    </div>
                    {listaOfertas.length === 0 ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">Nenhum anúncio no ar ainda.</p>
                    ) : (
                        <div className="mt-3 overflow-x-auto">
                            <table className="w-full text-left text-[13px] font-normal text-white/70">
                                <thead>
                                    {/* ⚠️ "Tipo de exposição" vai SEM o percentual de comissão
                                        que o mockup mostra ao lado de "Premium": a tarifa do ML
                                        varia por categoria e faixa de preço, e sem
                                        `logistic_type` + `shipping_mode` ela sai errada
                                        (learnings do projeto). Número errado com cara de certo
                                        é pior que número nenhum. */}
                                    <tr className="border-b border-white/[0.08] text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">
                                        <th className="py-2 pr-3">Oferta / fase</th>
                                        <th className="py-2 pr-3">Tipo de exposição</th>
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
                                            <tr key={indice} className="border-b border-white/[0.06] last:border-b-0 align-top">
                                                {/* Oferta e fase na mesma célula, como no mockup:
                                                    o selo da fase, o título e o link do anúncio. */}
                                                <td className="max-w-[320px] py-2 pr-3">
                                                    <span className="mr-2 inline-flex items-center rounded-md border border-white/[0.10] bg-white/[0.04] px-2 py-0.5 align-middle text-[11px] font-bold text-white/55">
                                                        {faseOferta !== null ? `Fase ${faseOferta}` : 'Fase —'}
                                                    </span>
                                                    <span className="text-white" title={titulo}>{titulo}</span>
                                                    <span className="mt-0.5 block">
                                                        {mlb !== null ? <LinkMl mlb={mlb} className="text-[11px]" /> : <span className="text-[11px] text-white/40">—</span>}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-3 text-[11px] text-white/55">{tipo}</td>
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

                {/* 4 — Biblioteca de criativos.
                    Saiu da lateral para a coluna principal, no formato de grade do
                    mockup. ⚠️ A contagem é a REAL (`totalDeImagens`): o "8 imagens
                    prontas", com custo fixo de IA, do desenho é o kit de 7
                    imagens, extinto pelo quick 261007-kit2. Nenhum custo aparece
                    aqui porque ele não chega nesta tela. */}
                <section className={CARTAO}>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h2 className={TITULO_BLOCO}>Criativos</h2>
                            <p className="mt-1 text-[13px] font-normal text-white/55">
                                As imagens já aprovadas de cada fase, nas resoluções que o catálogo do Mercado Livre aceita.
                            </p>
                        </div>
                        {totalDeImagens > 0 && (
                            <div className="flex flex-wrap items-center gap-2">
                                <span className={PILULA}>
                                    {totalDeImagens === 1 ? '1 imagem pronta' : `${totalDeImagens} imagens prontas`}
                                </span>
                                {/* Decisão 5: esta promessa do mockup é VERDADE — o
                                    `CriarFaseService` copia as fotos do base para o kit,
                                    com bytes próprios e sem gerar nada de novo. */}
                                <span className="rounded-full border border-ecf-yellow/40 bg-ecf-yellow/10 px-2 py-0.5 text-[11px] font-normal text-ecf-yellow">
                                    Reutilizáveis na próxima fase, a custo zero
                                </span>
                            </div>
                        )}
                    </div>

                    {totalDeImagens === 0 ? (
                        <p className="mt-3 text-[13px] font-normal text-white/55">Nenhum criativo aprovado ainda.</p>
                    ) : (
                        <>
                            {listaCriativos.map((item, indice) => {
                                // Flags dentro do callback (armadilha do Rollup).
                                const grupo = objetoSeguro(item);
                                const faseGrupo = numeroSeguro(grupo.fase);
                                const rotuloGrupo = textoSeguro(grupo.rotulo, '1 unidade');
                                const miniaturas = listaSegura(grupo.miniaturas)
                                    .map((m) => objetoSeguro(m))
                                    .filter((m) => typeof m.url === 'string' && m.url !== '');
                                if (miniaturas.length === 0) return null;

                                return (
                                    <div key={indice} className="mt-3">
                                        <p className="text-[11px] font-normal text-white/40">
                                            Fase {faseGrupo ?? '—'} · {rotuloGrupo} · {miniaturas.length === 1 ? '1 imagem' : `${miniaturas.length} imagens`}
                                        </p>
                                        <div className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-8">
                                            {miniaturas.map((mini, i) => {
                                                // Nada de escopo de fora aqui dentro também.
                                                const indiceDoSlot = numeroSeguro(mini.indice);

                                                return (
                                                    <figure key={i} className="min-w-0">
                                                        <img
                                                            src={mini.url}
                                                            alt=""
                                                            loading="lazy"
                                                            className="aspect-square w-full rounded-lg bg-white object-contain"
                                                        />
                                                        <figcaption className="mt-1 truncate text-[11px] font-normal text-white/40">
                                                            {indiceDoSlot !== null ? `Imagem ${indiceDoSlot}` : 'Imagem'}
                                                        </figcaption>
                                                    </figure>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                            <p className="mt-3 text-[11px] font-normal text-white/40">
                                O que cada geração custou não chega a esta tela — esse número fica no painel de criativos do rascunho.
                            </p>
                        </>
                    )}
                </section>

                {/* 5 — O rodapé "Pronto para a Fase N" do mockup.
                    ⚠️ Ele RENDERIZA O MESMO `acaoCriarFase` do cartão da próxima
                    fase: um elemento só, um gatilho só, um painel só. */}
                <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ecf-yellow/25 bg-ecf-yellow/[0.04] p-4">
                    <div className="min-w-0">
                        <p className="font-display text-[15px] font-bold text-white">
                            Pronto para a Fase {proximoNumero}{proximaQuantidade !== null ? `: Kit ${proximaQuantidade}` : ''}
                        </p>
                        <p className="mt-1 text-[13px] font-normal text-white/55">
                            {totalDeImagens > 0
                                ? 'As imagens aprovadas são copiadas para a fase nova a custo zero, junto com o título e a ficha técnica do base.'
                                : 'A fase nova nasce com o título, a ficha técnica e as fotos do produto base.'}
                        </p>
                        {!podeCriarFase && (
                            <p className="mt-1 text-[11px] font-normal text-white/40">{motivoDeNaoCriar}</p>
                        )}
                    </div>
                    {acaoCriarFase}
                </section>

                {/* 6 — Histórico */}
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

                {/* 7 — Lateral: Mapeamento */}
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

            {/* O painel "Criar Fase N" (§4) é montado UMA vez, fora dos cartões:
                os dois lugares que mostram o botão abrem este mesmo painel. */}
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
