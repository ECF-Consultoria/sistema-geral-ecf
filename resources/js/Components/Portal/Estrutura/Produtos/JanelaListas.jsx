import { useState } from 'react';
import axios from 'axios';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { Botao, CLASSE_INPUT } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { cn } from '@/lib/utils';

// ─── Famílias e ambientes da empresa (D-05) ─────────────────────────────────
//
// As listas são geridas num lugar só. Renomear leva os produtos junto (eles
// apontam por id); excluir só vale para o que não está em uso — a regra é do
// servidor (que recusa com 422/404), aqui só se mostra. A janela atualiza o MESMO
// estado `listas` da página (`onListas`), sem criar outro.

const TIPOS = {
    familia: { rotulo: 'família', plural: 'Famílias', novo: 'Nova família', excluir: 'Excluir família', rota: 'familias' },
    ambiente: { rotulo: 'ambiente', plural: 'Ambientes', novo: 'Novo ambiente', excluir: 'Excluir ambiente', rota: 'ambientes' },
};

const mensagemDe = (e, padrao) => e.response?.data?.errors?.nome?.[0] ?? e.response?.data?.errors?.lista?.[0] ?? e.response?.data?.message ?? padrao;

function Aba({ tipo, itens, onListas, onRecarregar }) {
    const t = TIPOS[tipo];
    const [novo, setNovo] = useState('');
    const [editando, setEditando] = useState(null);     // { id, nome }
    const [excluindo, setExcluindo] = useState(null);   // id aguardando confirmação
    const [ocupado, setOcupado] = useState(false);
    const [erro, setErro] = useState(null);

    const rota = (acao, ...args) => route(`portal.auth.estrutura.produtos.${t.rota}.${acao}`, ...args);

    // Renomear/excluir mudam o que as linhas da grade mostram: a página recarrega produtos e listas.
    const recarregar = () => onRecarregar?.();

    const executar = async (chamada, aoTerminar) => {
        if (ocupado) return;
        setOcupado(true);
        setErro(null);
        try {
            const { data } = await chamada();
            if (data.listas) onListas(data.listas);
            aoTerminar?.();
        } catch (e) {
            setErro(mensagemDe(e, 'Não foi possível salvar agora. Tente de novo.'));
        } finally {
            setOcupado(false);
        }
    };

    const criar = () => {
        const nome = novo.trim();
        if (! nome) return;
        executar(() => axios.post(rota('criar'), { nome }), () => setNovo(''));
    };

    const renomear = () => {
        const nome = editando.nome.trim();
        if (! nome) return;
        executar(() => axios.put(rota('renomear', editando.id), { nome }), () => { setEditando(null); recarregar(); });
    };

    const excluir = (id) => executar(() => axios.delete(rota('excluir', id)), () => { setExcluindo(null); recarregar(); });

    return (
        <div className="space-y-3" data-aba-lista={tipo}>
            <div className="flex gap-2">
                <input value={novo} onChange={(e) => setNovo(e.target.value)} maxLength={80} placeholder={t.novo} aria-label={t.novo}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); criar(); } }}
                    className={cn(CLASSE_INPUT, 'h-11')} data-campo-novo />
                <Botao variante="secundario" onClick={criar} disabled={ocupado || ! novo.trim()} aria-label={`Adicionar ${t.rotulo}`} data-acao="adicionar-item">
                    <Plus size={14} />
                </Botao>
            </div>
            {erro && <p role="alert" className="text-[12px] text-amber-300" data-erro-lista>{erro}</p>}

            {itens.length === 0 && <p className="py-4 text-center text-[12px] text-white/45">{`Nenhum ${t.rotulo} ainda. Digite um nome acima para criar.`}</p>}

            <ul className="max-h-[50vh] divide-y divide-white/[0.06] overflow-y-auto">
                {itens.map((item) => {
                    const emUso = (item.em_uso ?? 0) > 0;

                    return (
                        <li key={item.id} className="space-y-1 py-2" data-item-lista>
                            {editando?.id === item.id ? (
                                <input autoFocus value={editando.nome} maxLength={80} aria-label={`Renomear ${item.nome}`}
                                    onChange={(e) => setEditando({ id: item.id, nome: e.target.value })}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') { e.preventDefault(); renomear(); }
                                        if (e.key === 'Escape') { e.preventDefault(); setEditando(null); setErro(null); }
                                    }}
                                    onBlur={() => { if (! ocupado) setEditando(null); }}
                                    className={cn(CLASSE_INPUT, 'h-9')} data-campo-renomear />
                            ) : (
                                <div className="flex items-center justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-[13px] text-white">{item.nome}</p>
                                        <p className="text-[12px] text-white/45">usada em {item.em_uso ?? 0} {(item.em_uso ?? 0) === 1 ? 'produto' : 'produtos'}</p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1">
                                        <button type="button" onClick={() => { setErro(null); setExcluindo(null); setEditando({ id: item.id, nome: item.nome }); }}
                                            aria-label={`Renomear ${item.nome}`} title="Renomear" data-acao="renomear-item"
                                            className="rounded-lg p-1.5 text-white/35 hover:bg-white/[0.06] hover:text-white">
                                            <Pencil size={14} />
                                        </button>
                                        <button type="button" onClick={() => setExcluindo(item.id)} disabled={emUso}
                                            aria-label={`Excluir ${item.nome}`}
                                            title={emUso ? `Em uso em ${item.em_uso} produtos. Troque nos produtos para poder excluir.` : 'Excluir'}
                                            data-acao="excluir-item"
                                            className="rounded-lg p-1.5 text-white/35 hover:bg-red-500/10 hover:text-red-300 disabled:pointer-events-none disabled:opacity-30">
                                            <Trash2 size={14} />
                                        </button>
                                    </div>
                                </div>
                            )}
                            {emUso && editando?.id !== item.id && (
                                <p className="text-[12px] text-white/45" data-em-uso>
                                    Em uso em {item.em_uso} produtos. Troque nos produtos para poder excluir.
                                </p>
                            )}
                            {! emUso && excluindo === item.id && (
                                <div className="flex flex-wrap items-center gap-2 text-[13px] text-white/70" data-confirmar-exclusao>
                                    <span>Excluir “{item.nome}”?</span>
                                    <Botao variante="perigo" onClick={() => excluir(item.id)} disabled={ocupado} data-acao="confirmar-excluir-item">{t.excluir}</Botao>
                                    <Botao variante="fantasma" onClick={() => setExcluindo(null)} disabled={ocupado} data-acao="manter-item">Manter</Botao>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

export default function JanelaListas({ aberta, onFechar, listas, onListas, onRecarregar }) {
    return (
        <Janela aberta={aberta} onFechar={onFechar} largura="max-w-lg" titulo="Famílias e ambientes">
            <Tabs defaultValue="familia" data-janela-listas>
                <TabsList className="w-full">
                    <TabsTrigger value="familia" className="flex-1">Famílias</TabsTrigger>
                    <TabsTrigger value="ambiente" className="flex-1">Ambientes</TabsTrigger>
                </TabsList>
                <TabsContent value="familia"><Aba tipo="familia" itens={listas?.familias ?? []} onListas={onListas} onRecarregar={onRecarregar} /></TabsContent>
                <TabsContent value="ambiente"><Aba tipo="ambiente" itens={listas?.ambientes ?? []} onListas={onListas} onRecarregar={onRecarregar} /></TabsContent>
            </Tabs>
        </Janela>
    );
}
