import { useEffect, useState } from 'react';
import axios from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import { CAMPO } from '@/Components/Publicador/Mesa/comum';
import { BotaoAcao } from '@/Components/Publicador/Mesa/botoes';
import { mensagemDe } from '@/Components/Publicador/apoio';
import { rota, useLeitura } from '../useAlavancas';
import { fmtBRL } from '../formato';
import ModalConfirmacao from '../ModalConfirmacao';

const MAXIMO = 5;

/** Aceita "12,5" e "12.5"; vazio ou inválido vira null. */
const numero = (t) => {
    const bruto = String(t ?? '').trim();
    if (bruto === '') return null;
    const n = Number(bruto.replace(',', '.'));

    return Number.isFinite(n) ? n : null;
};

const texto = (n) => (n === null || n === undefined ? '' : String(n).replace('.', ','));
const linhaDe = (f) => ({ id: f.id ?? null, percentual: texto(f.percentual), quantidade: texto(f.quantidade_minima), original: f });
const SEM_REC = { carregando: false, erro: null, semRecomendacao: false, incoerentes: [] };

/** Faixas de atacado (% B2B) de um anúncio: lista inteira, id mantido, linha alterada sai e entra. */
export default function FaixasDoAnuncio({ conta, item, liberada, motivo }) {
    const { dados, erro, carregando, recarregar } = useLeitura('atacado.item', conta, { item });
    const [linhas, setLinhas] = useState([]);
    const [removerAbsoluto, setRemoverAbsoluto] = useState(false);
    const [rec, setRec] = useState(SEM_REC);
    const [alvo, setAlvo] = useState(null);

    // Cada leitura nova (a versão mudou) reinicia a edição.
    useEffect(() => {
        setLinhas((dados?.faixas ?? []).map(linhaDe));
        setRemoverAbsoluto(false);
        setRec(SEM_REC);
    }, [dados]);

    const preco = dados?.preco_padrao ?? null;
    const valorEmpresa = (l) => {
        const p = numero(l.percentual);

        return preco && p !== null ? fmtBRL(preco * (1 - p / 100)) : '—';
    };

    function mudar(i, campo, valor) {
        setLinhas((ls) => ls.map((l, k) => {
            if (k !== i) return l;
            const nova = { ...l, [campo]: valor };
            // Alterar uma faixa existente é sair e entrar: perde o id (como no Mercado Livre).
            if (l.id !== null && l.original) {
                const igual = numero(nova.percentual) === Number(l.original.percentual) && numero(nova.quantidade) === Number(l.original.quantidade_minima);
                if (! igual) nova.id = null;
            }

            return nova;
        }));
    }

    async function pedirRecomendacao() {
        const quantidades = [...new Set(linhas.map((l) => numero(l.quantidade)).filter((q) => Number.isInteger(q) && q >= 1 && q <= 100))].slice(0, MAXIMO);
        if (quantidades.length === 0) {
            setRec({ ...SEM_REC, erro: 'Preencha ao menos uma quantidade para pedir a recomendação.' });

            return;
        }
        setRec({ ...SEM_REC, carregando: true });
        try {
            const r = await axios.post(rota('atacado.recomendacoes', conta, { item }), { quantidades, preco });
            const lista = r.data?.recomendacoes ?? [];
            setLinhas((ls) => ls.map((l) => {
                const q = numero(l.quantidade);
                const achada = lista.find((x) => Number(x.quantidade) === q && ! x.incoerente && x.percentual !== null && x.percentual !== undefined);

                return achada ? { ...l, id: null, percentual: texto(achada.percentual) } : l;
            }));
            setRec({
                ...SEM_REC,
                semRecomendacao: Boolean(r.data?.sem_recomendacao),
                incoerentes: lista.filter((x) => x.incoerente).map((x) => Number(x.quantidade)),
            });
        } catch (e) {
            setRec({ ...SEM_REC, erro: mensagemDe(e) });
        }
    }

    function revisar() {
        // A lista INTEIRA vai na janela: faixa mantida só com o id, alterada ou nova sem id, omitida sai.
        const faixas = linhas.map((l) => (l.id !== null
            ? { id: l.id, percentual: numero(l.percentual), quantidade_minima: numero(l.quantidade) }
            : { percentual: numero(l.percentual), quantidade_minima: numero(l.quantidade) }));
        setAlvo({ itens: [{ item_id: item, faixas, remover_absoluto: removerAbsoluto }] });
    }

    // Só relê: a janela fecha no "Fechar" da pessoa, depois de ler o resultado.
    function aoConcluir() {
        recarregar();
    }

    if (carregando && ! dados) return <p className="text-[13px] font-normal text-white/55">Carregando faixas…</p>;
    if (erro) {
        return (
            <p className="text-[13px] font-normal text-white/55">
                {erro} <button type="button" onClick={() => recarregar()} className="font-bold text-white/70 hover:text-ecf-yellow">Tentar de novo</button>
            </p>
        );
    }

    const bloqueado = dados?.faixas === null && dados?.tem_faixas;

    return (
        <div className="space-y-3 rounded-xl border border-white/[0.08] bg-white/[0.03] p-3">
            <p className="text-[13px] font-normal text-white/70">Preço padrão: <span className="font-bold text-white/90">{fmtBRL(preco)}</span></p>
            <p className="text-[13px] font-normal text-white/55">
                O desconto vale só para compradores empresa e incide sobre o preço vigente, inclusive em promoção. Faixa apagada aqui sai do anúncio.
            </p>

            {bloqueado && <p className="text-[13px] font-normal text-white/70">{dados.aviso}</p>}

            {! bloqueado && (
                <>
                    {linhas.length === 0 && <p className="text-[13px] font-normal text-white/55">Este anúncio ainda não tem faixas.</p>}
                    {linhas.map((l, i) => (
                        <div key={i} className="flex flex-wrap items-center gap-2 text-[13px] font-normal text-white/70">
                            <span>A partir de</span>
                            <input
                                aria-label={`Quantidade mínima da faixa ${i + 1}`}
                                inputMode="numeric"
                                value={l.quantidade}
                                onChange={(e) => mudar(i, 'quantidade', e.target.value)}
                                className={cn(CAMPO, '!w-24')}
                            />
                            <span>unidades —</span>
                            <input
                                aria-label={`Percentual da faixa ${i + 1}`}
                                inputMode="decimal"
                                value={l.percentual}
                                onChange={(e) => mudar(i, 'percentual', e.target.value)}
                                className={cn(CAMPO, '!w-24')}
                            />
                            <span>% — {valorEmpresa(l)} para empresas</span>
                            {rec.incoerentes.includes(numero(l.quantidade)) && (
                                <span className="text-white/55">O Mercado Livre não aceita essa quantidade.</span>
                            )}
                            <button
                                type="button"
                                aria-label={`Remover a faixa ${i + 1}`}
                                onClick={() => setLinhas((ls) => ls.filter((_, k) => k !== i))}
                                className="rounded p-1 text-white/55 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow"
                            >
                                <Trash2 className="h-4 w-4" aria-hidden="true" />
                            </button>
                        </div>
                    ))}

                    {dados?.tem_absoluto && (
                        <label className="flex items-start gap-2 text-[13px] font-normal text-white/70">
                            <input type="checkbox" checked={removerAbsoluto} onChange={(e) => setRemoverAbsoluto(e.target.checked)} className="mt-1" />
                            <span>Substituir as faixas em valor fixo atuais. Elas saem do anúncio quando você gravar as faixas em percentual.</span>
                        </label>
                    )}

                    {rec.semRecomendacao && (
                        <p className="text-[13px] font-normal text-white/55">O Mercado Livre não tem recomendação para este anúncio; defina o que fizer sentido.</p>
                    )}
                    {rec.erro && <p className="text-[13px] font-normal text-white/55">{rec.erro}</p>}

                    <div className="flex flex-wrap gap-2">
                        <BotaoAcao disabled={linhas.length >= MAXIMO} onClick={() => setLinhas((ls) => [...ls, { id: null, percentual: '', quantidade: '', original: null }])}>
                            <Plus className="h-4 w-4" aria-hidden="true" /> Adicionar faixa
                        </BotaoAcao>
                        <BotaoAcao disabled={! liberada || rec.carregando} title={liberada ? undefined : motivo} onClick={pedirRecomendacao}>
                            {rec.carregando ? 'Consultando…' : 'Ver recomendação do Mercado Livre'}
                        </BotaoAcao>
                        <BotaoAcao onClick={() => recarregar()}>Recarregar faixas</BotaoAcao>
                        <BotaoAcao primario disabled={! liberada} title={liberada ? undefined : motivo} onClick={revisar}>
                            Revisar e gravar faixas
                        </BotaoAcao>
                    </div>
                </>
            )}

            {alvo && (
                <ModalConfirmacao
                    aberto
                    conta={conta}
                    acao="atacado.gravar"
                    itens={alvo.itens}
                    titulo="Gravar faixas de atacado"
                    onFechar={() => setAlvo(null)}
                    onConcluido={aoConcluir}
                />
            )}
        </div>
    );
}
