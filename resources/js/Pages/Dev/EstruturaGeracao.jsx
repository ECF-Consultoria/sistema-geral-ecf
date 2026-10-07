import DevCard from '@/Components/Dev/DevCard';
import Janela from '@/Components/Portal/Estrutura/Janela';
import AppLayout from '@/Layouts/AppLayout';
import { chipsDePalavras, linhaDeQuantidades, opcoesDoCombit, resumoDoPar } from '@/lib/estruturaGeracaoAdmin';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Link2, Plus, Tag } from 'lucide-react';
import { useState } from 'react';

const CAMPO = 'h-11 w-full rounded-lg border border-white/[0.1] bg-black/30 px-3 text-[14px] text-white placeholder:text-white/30 focus:border-white/30 focus:outline-none';
const BTN_SEC = 'inline-flex h-9 items-center gap-1.5 rounded-lg border border-white/[0.12] bg-white/[0.04] px-3 text-[13px] text-white/80 transition-colors hover:bg-white/[0.08]';
const BTN_AMARELO = 'inline-flex h-11 items-center justify-center rounded-lg bg-ecf-yellow px-4 text-[14px] font-semibold text-black transition-opacity hover:opacity-90 disabled:opacity-50';
const BTN_CANCELAR = 'inline-flex h-11 items-center justify-center rounded-lg border border-white/[0.12] px-4 text-[14px] text-white/70 hover:bg-white/[0.06]';
const BTN_PERIGO = 'inline-flex h-11 items-center justify-center rounded-lg bg-red-500/90 px-4 text-[14px] font-semibold text-white hover:bg-red-500 disabled:opacity-50';

function Campo({ rotulo, dica, erro, children }) {
    return (
        <label className="block space-y-1">
            <span className="text-[13px] font-medium text-white/80">{rotulo}</span>
            {children}
            {dica && <span className="block text-[12px] text-white/40">{dica}</span>}
            {erro && <span className="block text-[12px] text-red-300">{erro}</span>}
        </label>
    );
}

function Vazio({ texto, botao, onClick }) {
    return (
        <div className="flex flex-col items-center gap-3 py-8 text-center">
            <p className="text-[13px] text-white/40">{texto}</p>
            <button type="button" onClick={onClick} className={BTN_SEC}>
                <Plus size={14} /> {botao}
            </button>
        </div>
    );
}

const TIPO_VAZIO = { nome: '', plural: '', palavras: '', qtd_combo: '', qtd_combit: '' };

function JanelaTipo({ alvo, onFechar }) {
    const editando = !!alvo?.id;
    const [dados, setDados] = useState(() => ({
        ...TIPO_VAZIO,
        ...(editando ? {
            nome: alvo.nome, plural: alvo.plural ?? '',
            palavras: (alvo.palavras ?? []).join(', '),
            qtd_combo: alvo.qtd_combo ?? '', qtd_combit: alvo.qtd_combit ?? '',
        } : {}),
    }));
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);
    const set = (k) => (e) => setDados((d) => ({ ...d, [k]: e.target.value }));

    function salvar(e) {
        e.preventDefault();
        const opcoes = {
            preserveScroll: true,
            onStart: () => setEnviando(true),
            onFinish: () => setEnviando(false),
            onError: setErros,
            onSuccess: onFechar,
        };
        if (editando) router.put(route('dev.estrutura_geracao.tipos.atualizar', alvo.id), dados, opcoes);
        else router.post(route('dev.estrutura_geracao.tipos.criar'), dados, opcoes);
    }

    return (
        <Janela aberta onFechar={onFechar} titulo={editando ? 'Editar tipo' : 'Novo tipo'} largura="max-w-md">
            <form onSubmit={salvar} className="space-y-4">
                <Campo rotulo="Nome" erro={erros.nome}>
                    <input className={CAMPO} value={dados.nome} onChange={set('nome')} placeholder="Cadeira" />
                </Campo>
                <Campo rotulo="Plural" erro={erros.plural}>
                    <input className={CAMPO} value={dados.plural} onChange={set('plural')} placeholder="Cadeiras" />
                </Campo>
                <Campo
                    rotulo="Palavras-chave"
                    erro={erros.palavras}
                    dica="Separe por vírgula. O sistema procura estas palavras na categoria e no nome do produto. Ex.: cadeira, poltrona."
                >
                    <input className={CAMPO} value={dados.palavras} onChange={set('palavras')} />
                </Campo>
                <Campo
                    rotulo="Quantidades de Combo"
                    erro={erros.qtd_combo}
                    dica="Ex.: 2, 4, 6. Digite 0 para este tipo não ter Combo. Vazio = nenhuma."
                >
                    <input className={CAMPO} value={dados.qtd_combo} onChange={set('qtd_combo')} />
                </Campo>
                <Campo
                    rotulo="Quantidades de Combit"
                    erro={erros.qtd_combit}
                    dica="Ex.: 2, 4, 6. Digite 0 para este tipo não ter Combit. Vazio = nenhuma."
                >
                    <input className={CAMPO} value={dados.qtd_combit} onChange={set('qtd_combit')} />
                </Campo>
                {editando && <p className="text-[12px] text-white/40">Identificador: {alvo.slug}</p>}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onFechar} className={BTN_CANCELAR}>Cancelar</button>
                    <button type="submit" disabled={enviando} className={BTN_AMARELO}>Salvar tipo</button>
                </div>
            </form>
        </Janela>
    );
}

function JanelaPar({ alvo, tipos, onFechar }) {
    const editando = !!alvo?.id;
    const [dados, setDados] = useState(() => ({
        primeiro: editando ? String(alvo.primeiro.id) : '',
        segundo: editando ? String(alvo.segundo.id) : '',
        combit: editando ? alvo.combit : 'nao',
    }));
    const [erros, setErros] = useState({});
    const [enviando, setEnviando] = useState(false);

    const nomeDe = (id) => tipos.find((t) => String(t.id) === String(id))?.nome ?? '';
    const opcoes = opcoesDoCombit(nomeDe(dados.primeiro), nomeDe(dados.segundo));

    function salvar(e) {
        e.preventDefault();
        const cfg = {
            preserveScroll: true,
            onStart: () => setEnviando(true),
            onFinish: () => setEnviando(false),
            onError: setErros,
            onSuccess: onFechar,
        };
        if (editando) router.put(route('dev.estrutura_geracao.pares.atualizar', alvo.id), dados, cfg);
        else router.post(route('dev.estrutura_geracao.pares.criar'), dados, cfg);
    }

    const seletor = (campo, rotulo) => (
        <Campo rotulo={rotulo} erro={erros[campo]}>
            <select
                className={CAMPO}
                value={dados[campo]}
                onChange={(e) => setDados((d) => ({ ...d, [campo]: e.target.value }))}
            >
                <option value="">Escolha um tipo</option>
                {tipos.map((t) => <option key={t.id} value={t.id}>{t.nome}</option>)}
            </select>
        </Campo>
    );

    return (
        <Janela aberta onFechar={onFechar} titulo={editando ? 'Editar par' : 'Novo par'} largura="max-w-md">
            <form onSubmit={salvar} className="space-y-4">
                {seletor('primeiro', 'Primeiro tipo')}
                {seletor('segundo', 'Segundo tipo')}
                {erros.tipo_b_id && <p className="text-[12px] text-red-300">{erros.tipo_b_id}</p>}
                <fieldset className="space-y-2">
                    <legend className="mb-1 text-[13px] font-medium text-white/80">No Combit</legend>
                    {opcoes.map((o) => (
                        <label key={o.valor} className="flex min-h-[44px] cursor-pointer items-center gap-2 rounded-lg border border-white/[0.08] px-3 text-[14px] text-white/80">
                            <input
                                type="radio"
                                name="combit"
                                value={o.valor}
                                checked={dados.combit === o.valor}
                                onChange={() => setDados((d) => ({ ...d, combit: o.valor }))}
                            />
                            {o.rotulo}
                        </label>
                    ))}
                    {erros.combit && <p className="text-[12px] text-red-300">{erros.combit}</p>}
                </fieldset>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" onClick={onFechar} className={BTN_CANCELAR}>Cancelar</button>
                    <button type="submit" disabled={enviando} className={BTN_AMARELO}>Salvar par</button>
                </div>
            </form>
        </Janela>
    );
}

function JanelaExcluir({ titulo, descricao, rotuloBotao, rota, onFechar }) {
    const [enviando, setEnviando] = useState(false);
    return (
        <Janela aberta onFechar={onFechar} titulo={titulo} descricao={descricao} largura="max-w-md">
            <div className="flex justify-end gap-2 pt-2">
                <button type="button" onClick={onFechar} className={BTN_CANCELAR}>Cancelar</button>
                <button
                    type="button"
                    disabled={enviando}
                    className={BTN_PERIGO}
                    onClick={() => router.delete(rota, {
                        preserveScroll: true,
                        onStart: () => setEnviando(true),
                        onFinish: () => setEnviando(false),
                        onSuccess: onFechar,
                    })}
                >
                    {rotuloBotao}
                </button>
            </div>
        </Janela>
    );
}

export default function EstruturaGeracao({ tipos = [], pares = [] }) {
    // Janela aberta: { tipo: 'tipo'|'par'|'excluir-tipo'|'excluir-par', alvo }
    const [janela, setJanela] = useState(null);
    const fechar = () => setJanela(null);
    const ordenados = [...tipos].sort((a, b) => (a.ordem ?? 0) - (b.ordem ?? 0));

    return (
        <AppLayout title="Tipos e pares das sugestões de ofertas">
            <div className="mx-auto max-w-5xl space-y-4 px-4 py-6 sm:px-6">
                <div>
                    <h1 className="font-display text-2xl font-semibold text-white">Tipos e pares das sugestões de ofertas</h1>
                    <p className="mt-1 text-[13px] text-white/40">
                        Valem para todas as empresas. A mudança aparece na próxima vez que alguém abrir Sugestões de ofertas. Ofertas já criadas não mudam.
                    </p>
                </div>

                <DevCard
                    icon={Tag}
                    title="Tipos de produto"
                    subtitle="Cada tipo tem palavras-chave para o sistema reconhecer o produto e as quantidades que entram em Combo e Combit."
                >
                    {ordenados.length > 0 && (
                        <div className="mb-3">
                            <button type="button" className={BTN_SEC} onClick={() => setJanela({ tipo: 'tipo', alvo: null })}>
                                <Plus size={14} /> Novo tipo
                            </button>
                        </div>
                    )}
                    {ordenados.length === 0 ? (
                        <Vazio texto="Nenhum tipo cadastrado" botao="Novo tipo" onClick={() => setJanela({ tipo: 'tipo', alvo: null })} />
                    ) : (
                        <div className="divide-y divide-white/[0.06]">
                            {ordenados.map((t) => {
                                const { visiveis, resto } = chipsDePalavras(t.palavras);
                                return (
                                    <div key={t.id} className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center">
                                        <div className="min-w-0 flex-1 space-y-1.5">
                                            <p className="text-[14px] font-semibold text-white">
                                                {t.nome} <span className="font-normal text-white/40">{t.plural}</span>
                                            </p>
                                            <div className="flex flex-wrap gap-1.5">
                                                {visiveis.map((p) => (
                                                    <span key={p} className="rounded-full bg-white/[0.06] px-2 text-[12px] text-white/60">{p}</span>
                                                ))}
                                                {resto > 0 && <span className="rounded-full bg-white/[0.06] px-2 text-[12px] text-white/60">+{resto}</span>}
                                            </div>
                                            <p className="text-[12px] text-white/40">{linhaDeQuantidades(t)}</p>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <button type="button" className={cn(BTN_SEC, 'max-sm:h-11')} onClick={() => setJanela({ tipo: 'tipo', alvo: t })}>Editar</button>
                                            <button type="button" className="text-[12px] text-red-300 hover:underline max-sm:h-11" onClick={() => setJanela({ tipo: 'excluir-tipo', alvo: t })}>Excluir</button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </DevCard>

                <DevCard
                    icon={Link2}
                    title="Pares de tipos"
                    subtitle="Só os pares desta lista geram Kit e Combit. Para o Combit, diga qual item se repete."
                >
                    {pares.length > 0 && (
                        <div className="mb-3">
                            <button type="button" className={BTN_SEC} onClick={() => setJanela({ tipo: 'par', alvo: null })}>
                                <Plus size={14} /> Novo par
                            </button>
                        </div>
                    )}
                    {pares.length === 0 ? (
                        <Vazio texto="Nenhum par cadastrado" botao="Novo par" onClick={() => setJanela({ tipo: 'par', alvo: null })} />
                    ) : (
                        <div className="divide-y divide-white/[0.06]">
                            {pares.map((p) => (
                                <div key={p.id} className="flex flex-col gap-3 py-3 sm:flex-row sm:items-center">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[14px] font-semibold text-white">{p.primeiro.nome} + {p.segundo.nome}</p>
                                        <p className="text-[12px] text-white/40">{resumoDoPar(p)}</p>
                                    </div>
                                    <div className="flex items-center gap-3">
                                        <button type="button" className={cn(BTN_SEC, 'max-sm:h-11')} onClick={() => setJanela({ tipo: 'par', alvo: p })}>Editar</button>
                                        <button type="button" className="text-[12px] text-red-300 hover:underline max-sm:h-11" onClick={() => setJanela({ tipo: 'excluir-par', alvo: p })}>Excluir</button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </DevCard>
            </div>

            {janela?.tipo === 'tipo' && <JanelaTipo key={janela.alvo?.id ?? 'novo'} alvo={janela.alvo} onFechar={fechar} />}
            {janela?.tipo === 'par' && <JanelaPar key={janela.alvo?.id ?? 'novo'} alvo={janela.alvo} tipos={ordenados} onFechar={fechar} />}
            {janela?.tipo === 'excluir-tipo' && (
                <JanelaExcluir
                    titulo={`Excluir o tipo ${janela.alvo.nome}?`}
                    descricao="Os pares que usam este tipo também serão removidos, e os produtos deste tipo voltam para Sem tipo. Ofertas já criadas não mudam."
                    rotuloBotao="Excluir tipo"
                    rota={route('dev.estrutura_geracao.tipos.excluir', janela.alvo.id)}
                    onFechar={fechar}
                />
            )}
            {janela?.tipo === 'excluir-par' && (
                <JanelaExcluir
                    titulo={`Excluir o par ${janela.alvo.primeiro.nome} + ${janela.alvo.segundo.nome}?`}
                    descricao="Kits e Combits novos deixam de ser sugeridos para este par. Ofertas já criadas não mudam."
                    rotuloBotao="Excluir par"
                    rota={route('dev.estrutura_geracao.pares.excluir', janela.alvo.id)}
                    onFechar={fechar}
                />
            )}
        </AppLayout>
    );
}
