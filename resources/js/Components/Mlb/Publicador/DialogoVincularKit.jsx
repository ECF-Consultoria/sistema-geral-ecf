import { useEffect, useState } from 'react';
import axios from 'axios';
import { Loader2, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { textoSeguro } from './BarraDaConta';
import { criarRota, mensagemDe } from '@/Components/Publicador/apoio.js';
import { BASE_BOTAO, PRIMARIO, SECUNDARIO } from '@/Components/Publicador/Mesa/botoes';

// ─── §6 da ETAPA-3 (Fase 175): o combo que JÁ existe vira fase de outro ─────
//
// A §6 é "o sistema sugere, o usuário confirma": o base vem da sugestão do
// servidor (`SugestaoDeKitService`) e NÃO há seletor livre de base aqui. Com
// isso o navegador manda um `base_id` que ele não escolheu — e o servidor
// (175-08) ainda o resolve dentro do escopo da conta, com 404 fora dele
// (T-175-44).
//
// ⚠️ O que esta confirmação precisa deixar claro, porque é a dúvida real de
// quem clica: vincular NÃO altera o rascunho, o SKU, o estoque nem os anúncios
// já no ar do combo. O `VinculoDeKitService` grava três colunas e nada mais, e
// isso é provado byte a byte do lado do servidor — aqui a tela só conta.
//
// ⚠️ "Não é kit" é irreversível (carimba `kit_sugestao_recusada_em`), então ele
// vem com confirmação. A confirmação é um SEGUNDO MODO deste próprio diálogo,
// não um `window.confirm`: a caixa nativa do navegador aparece em branco, com
// a fonte do sistema e o domínio em cima, numa tela dark — e não dá para
// explicar nela o que "não volta a aparecer" significa.
//
// ⚠️ Lição da tela preta de 05-07/10/2026 ("Objects are not valid as a React
// child"): todo campo da sugestão passa por `textoSeguro()`/`numeroSeguro()`
// antes do JSX.

const CAMPO = 'h-10 w-full rounded-lg border border-white/[0.08] bg-white/[0.04] px-3 text-[13px] font-normal text-white placeholder:text-white/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow';
const ROTULO = 'mb-1 block text-[13px] font-normal text-white/70';
const AJUDA = 'mt-1 block text-[11px] font-normal text-white/40';
const ERRO = 'mt-1 block text-[13px] font-normal text-red-300';
const BLOCO = 'rounded-lg border border-white/[0.08] bg-white/[0.03] p-3';
const AMBAR = 'rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-[13px] font-normal text-amber-300';

const rotaDoPublicador = criarRota('mlb.anuncios.publicador', 'conta');

/** Objeto do servidor em forma segura; qualquer outra coisa vira `{}`. */
const objetoSeguro = (valor) => (valor && typeof valor === 'object' && !Array.isArray(valor) ? valor : {});

/** Só number finito do servidor; qualquer outra forma cai em null. */
const numeroSeguro = (valor) => (typeof valor === 'number' && Number.isFinite(valor) ? valor : null);

/**
 * O erro de campo que o diálogo sabe sozinho, para não gastar uma chamada ao
 * servidor com "1" ou "abc". Mesmas mensagens do painel "Criar Fase N" (§4) —
 * a pessoa vê a mesma frase nas duas portas de entrada da mesma regra.
 */
export function erroLocalDaQuantidade(texto) {
    const t = String(texto ?? '').trim();
    if (t === '') return 'Informe quantas unidades o kit tem.';
    if (!/^\d+$/.test(t)) return 'Use um número inteiro de unidades, sem vírgula.';
    if (Number(t) < 2) return 'Um kit tem 2 unidades ou mais.';

    return null;
}

/**
 * A recusa do servidor (422) repartida entre campo e mensagem geral.
 *
 * ⚠️ O `campo` sai do contexto da recusa e **não é sempre `quantidade`**:
 * VINC-03 e VINC-04 marcam o campo das unidades, mas VINC-01/02/05/06 são
 * recusas do produto inteiro e não têm campo nenhum para marcar — tratá-las
 * como erro de campo marcaria o input errado em "este produto já é um kit".
 *
 * @returns {{porCampo: Object, geral: ?string}}
 */
export function erroDeRecusa(dados) {
    const d = objetoSeguro(dados);
    const mensagem = typeof d.message === 'string' && d.message.trim() !== ''
        ? d.message
        : 'Não foi possível vincular o combo. Tente de novo.';
    const campo = typeof d.campo === 'string' && d.campo.trim() !== '' ? d.campo : null;
    const porCampo = {};

    for (const [chave, lista] of Object.entries(objetoSeguro(d.errors))) {
        const primeira = Array.isArray(lista)
            ? lista.find((item) => typeof item === 'string' && item.trim() !== '')
            : (typeof lista === 'string' && lista.trim() !== '' ? lista : null);
        if (primeira) porCampo[chave] = primeira;
    }

    if (campo !== null) {
        porCampo[campo] = mensagem;
    }

    return { porCampo, geral: Object.keys(porCampo).length === 0 ? mensagem : null };
}

/**
 * A fase que vai nascer do vínculo, calculada com a família que a própria
 * lista já tem em mão — espelho fiel de `PubProduto::proximaFase()`
 * (max + 1, nunca menos que 2). É só RÓTULO: quem decide a fase é o servidor.
 *
 * O kit herda as âncoras do base, então a família inteira sempre vem na mesma
 * lista de produtos da tela (decisão do 175-08).
 *
 * @param {Array} produtos  a lista crua da tela
 * @param {?number} baseId  o base que a sugestão apontou
 */
export function proximaFaseDaFamilia(produtos, baseId) {
    const base = numeroSeguro(baseId);
    if (base === null) return 2;

    const fases = (Array.isArray(produtos) ? produtos : [])
        .filter((item) => {
            const p = objetoSeguro(item);

            return numeroSeguro(p.id) === base || numeroSeguro(p.produto_base_id) === base;
        })
        .map((item) => numeroSeguro(objetoSeguro(item).fase) ?? 1);

    return Math.max(1, ...fases, 1) + 1;
}

/**
 * O valor inicial do campo de unidades. `sugestao.quantidade` pode vir `null`
 * (casamento por SKU não traz o N): nesse caso o campo abre VAZIO, que é
 * exatamente o que a §6 pede — é a pessoa que informa.
 */
export function quantidadeInicial(sugestao) {
    const n = numeroSeguro(objetoSeguro(sugestao).quantidade);

    return n !== null && n >= 2 ? String(n) : '';
}

/**
 * O diálogo de confirmação do vínculo de combo (§6).
 *
 * @param {{aberto?: boolean, onFechar?: Function, conta?: ?string, produto?: Object,
 *   sugestao?: Object, proximaFase?: ?number, modo?: 'vincular'|'recusar',
 *   onConcluido?: Function}} props
 *   `modo` e `proximaFase` são acréscimos ao contrato do PLAN: o modo carrega a
 *   confirmação do "Não é kit" (em vez de um `window.confirm` cru) e a fase é
 *   calculada pela lista, que é quem tem a família em mão.
 */
export default function DialogoVincularKit({
    aberto = false,
    onFechar,
    conta = null,
    produto = null,
    sugestao = null,
    proximaFase = null,
    modo = 'vincular',
    onConcluido,
}) {
    const [quantidade, setQuantidade] = useState(() => quantidadeInicial(sugestao));
    const [enviando, setEnviando] = useState(false);
    const [errosCampo, setErrosCampo] = useState({});
    const [erroGeral, setErroGeral] = useState(null);

    const alvo = objetoSeguro(produto);
    const dica = objetoSeguro(sugestao);
    const produtoId = numeroSeguro(alvo.id);
    const baseId = numeroSeguro(dica.base_id);
    const contaSegura = typeof conta === 'string' && conta !== '' ? conta : null;
    const fase = numeroSeguro(proximaFase) ?? 2;
    const recusando = modo === 'recusar';
    const erroQuantidade = errosCampo.quantidade ?? erroLocalDaQuantidade(quantidade);
    const podeEnviar = !enviando && contaSegura !== null && produtoId !== null
        && (recusando || (baseId !== null && erroLocalDaQuantidade(quantidade) === null));

    // Reabrir com outra sugestão volta o campo ao que o servidor sabe.
    useEffect(() => {
        if (!aberto) return;
        setQuantidade(quantidadeInicial(sugestao));
        setErrosCampo({});
        setErroGeral(null);
        // Depende de `aberto` + os dois IDS que identificam a sugestão: o objeto
        // `sugestao` chega novo a cada render da lista e, na lista de dependências,
        // reiniciaria o campo a cada volta — apagando o que a pessoa digitou.
    }, [aberto, produtoId, baseId]);

    // Escape fecha sem gravar (molde do ModalNovoProduto/PainelCriarFase).
    useEffect(() => {
        if (!aberto) return undefined;
        const aoTeclar = (ev) => {
            if (ev.key === 'Escape' && !enviando) onFechar?.();
        };
        window.addEventListener('keydown', aoTeclar);

        return () => window.removeEventListener('keydown', aoTeclar);
    }, [aberto, enviando, onFechar]);

    const fechar = () => {
        if (enviando) return;
        onFechar?.();
    };

    const enviar = async () => {
        if (!podeEnviar) return;
        setEnviando(true);
        setErrosCampo({});
        setErroGeral(null);
        try {
            const { data } = recusando
                ? await axios.post(rotaDoPublicador('vinculo.recusar', contaSegura, { produto: produtoId }))
                : await axios.put(
                    rotaDoPublicador('vinculo.salvar', contaSegura, { produto: produtoId }),
                    { base_id: baseId, quantidade: Number(String(quantidade).trim()) },
                );

            onConcluido?.({
                produto: objetoSeguro(data).produto ?? null,
                texto: recusando
                    ? `Pronto: ${textoSeguro(alvo.sku, 'o produto')} não é mais sugerido como kit.`
                    : `${textoSeguro(alvo.sku, 'O combo')} agora é a Fase ${numeroSeguro(objetoSeguro(objetoSeguro(data).produto).fase) ?? fase} de ${textoSeguro(dica.base_sku, 'o produto base')}.`,
            });
        } catch (e) {
            // 422 mantém o diálogo ABERTO com a mensagem no campo indicado.
            const repartido = erroDeRecusa(e?.response?.data);
            setErrosCampo(repartido.porCampo);
            setErroGeral(repartido.geral ?? (Object.keys(repartido.porCampo).length === 0 ? mensagemDe(e) : null));
            setEnviando(false);
        }
    };

    if (!aberto) return null;

    const titulo = recusando ? 'Não é kit?' : 'Vincular combo';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div
                className="absolute inset-0 bg-black/60"
                onClick={fechar}
                aria-hidden="true"
            />
            <div
                role="dialog"
                aria-modal="true"
                aria-label={titulo}
                className="relative z-10 flex w-[480px] max-w-full flex-col gap-4 rounded-2xl border border-white/[0.08] bg-ecf-card p-6"
            >
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-[15px] font-bold text-white">{titulo}</h2>
                        <p className="text-[13px] font-normal text-white/55">
                            {textoSeguro(alvo.nome, 'Produto')}
                            {' · '}
                            <span className="font-mono">{textoSeguro(alvo.sku, '—')}</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={fechar}
                        aria-label="Fechar"
                        className="rounded-lg p-1 text-white/55 hover:bg-white/[0.06] hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                    >
                        <X className="h-4 w-4" aria-hidden="true" />
                    </button>
                </div>

                {recusando ? (
                    <div className={BLOCO}>
                        <p className="text-[13px] font-normal text-white/70">
                            A sugestão não volta a aparecer para este produto.
                        </p>
                        <p className={AJUDA}>
                            Nada do produto muda: é só a sugestão que sai da lista. O combo continua com o
                            rascunho, o estoque e os anúncios dele, exatamente como estão.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className={BLOCO}>
                            <p className="text-[11px] font-bold uppercase tracking-[0.05em] text-white/40">Produto base (1 unidade)</p>
                            <p className="mt-1 text-[13px] font-normal text-white">
                                <span className="font-mono">{textoSeguro(dica.base_sku, '—')}</span>
                                {' · '}
                                {textoSeguro(dica.base_nome, '—')}
                            </p>
                            <p className={AJUDA}>Quem sugeriu este base foi o sistema, pelo cadastro da conta.</p>
                        </div>

                        <label className="block">
                            <span className={ROTULO}>Quantas unidades este combo tem</span>
                            <input
                                type="text"
                                inputMode="numeric"
                                value={quantidade}
                                maxLength={6}
                                onChange={(ev) => setQuantidade(ev.target.value)}
                                className={cn(CAMPO, 'font-mono')}
                                autoFocus
                            />
                            {erroQuantidade
                                ? <span className={ERRO}>{textoSeguro(erroQuantidade, '')}</span>
                                : <span className={AJUDA}>2 ou mais. É o que vira o rótulo “Kit N”.</span>}
                        </label>

                        {dica.conflito_heuristica === true && (
                            <p className={AMBAR}>
                                O nome sugere outro produto. Confira a composição cadastrada no Portal antes de
                                vincular — o que vale é ela, não o nome.
                            </p>
                        )}

                        <p className="text-[13px] font-normal text-white/55">
                            Vincular não altera o rascunho, o SKU, o estoque nem os anúncios deste combo
                            que já estão no ar. Só registra que ele é a Fase {fase} deste produto base.
                        </p>
                    </>
                )}

                {textoSeguro(erroGeral, '') !== '' && (
                    <p className="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-[13px] font-normal text-red-300">
                        {textoSeguro(erroGeral, '')}
                    </p>
                )}

                <div className="flex justify-end gap-2 border-t border-white/[0.08] pt-4">
                    <button type="button" onClick={fechar} disabled={enviando} className={cn(BASE_BOTAO, SECUNDARIO)}>
                        Voltar
                    </button>
                    <button
                        type="button"
                        onClick={enviar}
                        disabled={!podeEnviar}
                        className={cn(BASE_BOTAO, PRIMARIO)}
                    >
                        {enviando && <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />}
                        {recusando ? 'Não é kit, descartar' : `Vincular como Fase ${fase}`}
                    </button>
                </div>
            </div>
        </div>
    );
}
