import { useEffect, useState } from 'react';
import { Botao } from '@/Components/Portal/Estrutura/comum';
import Janela from '@/Components/Portal/Estrutura/Janela';
import { Checkbox } from '@/Components/ui/checkbox';

// ─── Revisão das categorias sugeridas em lote (D-06) ────────────────────────
//
// Nada é aceito sozinho: TODAS as caixas começam desmarcadas. Só as que a pessoa
// marcar vão para `onAceitar`, e o servidor ainda confere se é folha.

export default function JanelaSugestoesCategoria({ aberta, sugestoes = [], indisponivel = false, onAceitar, onFechar }) {
    const [marcadas, setMarcadas] = useState(() => new Set());

    useEffect(() => {
        if (aberta) setMarcadas(new Set());
    }, [aberta, sugestoes]);

    const comSugestao = sugestoes.filter((s) => s.sugestao);

    const alternar = (id) => setMarcadas((atual) => {
        const proximo = new Set(atual);
        if (proximo.has(id)) proximo.delete(id); else proximo.add(id);

        return proximo;
    });

    return (
        <Janela aberta={aberta} onFechar={onFechar} largura="max-w-3xl" titulo="Revisar categorias sugeridas">
            <div className="space-y-3 text-[13px] text-white/70" data-janela-sugestoes>
                {indisponivel && comSugestao.length === 0 && (
                    <p className="text-white/60">Nada encontrado ou o Mercado Livre está indisponível agora. Tente de novo mais tarde.</p>
                )}
                {comSugestao.length > 0 && (
                    <div className="flex gap-4 text-[12px]">
                        <button type="button" className="text-white/60 hover:text-white" data-acao="marcar-todas"
                            onClick={() => setMarcadas(new Set(comSugestao.map((s) => s.produto_id)))}>Marcar todas</button>
                        <button type="button" className="text-white/60 hover:text-white" data-acao="desmarcar-todas"
                            onClick={() => setMarcadas(new Set())}>Desmarcar</button>
                    </div>
                )}
                <ul className="max-h-[50vh] divide-y divide-white/[0.06] overflow-y-auto">
                    {sugestoes.map((s) => (
                        <li key={s.produto_id} className="flex items-start gap-3 py-2">
                            {s.sugestao ? (
                                <Checkbox checked={marcadas.has(s.produto_id)} onCheckedChange={() => alternar(s.produto_id)}
                                    aria-label={`Aceitar a categoria sugerida para ${s.nome}`} className="mt-1" />
                            ) : <span className="w-4" />}
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-[13px] text-white/85">{s.nome}</p>
                                {s.sugestao ? (
                                    <>
                                        <p className="text-[13px] font-semibold text-white/85">{s.sugestao.nome}</p>
                                        <p className="line-clamp-2 text-[12px] text-white/45">{s.sugestao.caminho_texto}</p>
                                    </>
                                ) : (
                                    <p className="text-[12px] text-white/45">Sem sugestão — escolha no produto</p>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
                <div className="flex justify-end gap-2 pt-1">
                    <Botao variante="fantasma" onClick={onFechar} data-acao="fechar-sugestoes">Fechar</Botao>
                    <Botao variante="secundario" disabled={marcadas.size === 0} data-acao="aceitar-marcadas"
                        onClick={() => onAceitar(sugestoes.filter((s) => s.sugestao && marcadas.has(s.produto_id)))}>
                        Aceitar marcadas
                    </Botao>
                </div>
            </div>
        </Janela>
    );
}
