import { ArrowRight, Pencil } from 'lucide-react';
import { resumoDoEnvio } from '@/lib/precificacaoEnvio';
import { rotuloDaPendencia, textoDeQuantasCriadas } from '@/lib/sugestoesCriadas';
import { cn } from '@/lib/utils';

// ─── Aba "Criadas": o que já foi aceito ou montado (13/10/2026) ─────────────
//
// O pedido: "em Planejamento deveriam ficar os produtos que planejamos… aceitou, ele
// fica ali". A sugestão aceita sumia da tela; quem montava um combo não tinha onde
// vê-lo de novo. Aqui ficam os combos, kits e combits que existem, por tipo, com do
// que cada um é feito, o envio (o pacote é a soma das caixas) e como está o preço —
// os mesmos dados da Precificação — e os atalhos para o preço e para nome e composição.

const FOCO = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40';
const ATALHO = 'inline-flex h-9 items-center gap-1.5 rounded-lg border border-white/[0.10] bg-white/[0.03] px-3 text-[12.5px] font-medium text-white/85 hover:bg-white/[0.07]';
const TOM_DA_PENDENCIA = { ok: 'bg-emerald-500/10 text-emerald-300', falta: 'bg-amber-500/10 text-amber-300', neutro: 'bg-white/[0.06] text-white/60' };
const TOM_DO_ENVIO = { ok: 'text-white/55', neutro: 'text-white/60', alerta: 'text-amber-300' };

function Criada({ item }) {
    const envio = resumoDoEnvio(item.envio);
    const preco = rotuloDaPendencia(item);

    return (
        <li className="rounded-xl border border-white/[0.08] bg-white/[0.02] px-4 py-3" data-criada={item.id} data-fase={item.fase}>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0">
                    <p className="flex flex-wrap items-baseline gap-x-2">
                        <span className="break-words font-mono text-[13px] font-semibold text-white">{item.sku}</span>
                        <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-semibold', TOM_DA_PENDENCIA[preco.tom])} data-preco={preco.chave}>{preco.texto}</span>
                    </p>
                    {item.nome && <p className="mt-0.5 line-clamp-2 text-[13px] text-white/65" title={item.nome}>{item.nome}</p>}
                    <p className="mt-1.5 flex flex-wrap gap-1.5" data-composicao>
                        {(item.composicao ?? []).map((c) => (
                            <span key={c.id} title={c.nome ?? undefined} className="rounded-md border border-white/[0.08] bg-white/[0.03] px-1.5 py-0.5 text-[11.5px] text-white/70">
                                {c.quantidade}× {c.sku ?? 'produto removido'}
                            </span>
                        ))}
                    </p>
                    {envio && (
                        <p className="mt-1.5 text-[11.5px] leading-snug text-white/45" data-envio={envio.chave} title={envio.dica ?? undefined}>
                            <span className={cn('font-semibold', TOM_DO_ENVIO[envio.tom])}>{envio.rotulo}</span>
                            {envio.pacote && <> · {envio.pacote}</>}
                            {envio.motivo && envio.tom === 'alerta' && <span className="block text-amber-200/70">{envio.motivo}</span>}
                        </p>
                    )}
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                    <a href={route('portal.auth.estrutura.mapeamento', { abrir: item.id })} className={cn(ATALHO, FOCO)} data-acao="editar-criada"
                        title="Mudar o nome, o código ou do que ele é feito">
                        <Pencil size={13} aria-hidden="true" /> Nome e composição
                    </a>
                    <a href={route('portal.auth.estrutura.precificacao', { q: item.sku })} className={cn(ATALHO, FOCO)} data-acao="precificar-criada">
                        Preço <ArrowRight size={13} aria-hidden="true" />
                    </a>
                </div>
            </div>
        </li>
    );
}

export default function PainelCriadas({ criadas }) {
    const grupos = Array.isArray(criadas?.grupos) ? criadas.grupos : [];

    if (grupos.length === 0) {
        return (
            <div className="mt-6 rounded-2xl border border-white/[0.08] bg-white/[0.02] px-6 py-10 text-center" data-criadas-vazio>
                <p className="text-[15px] font-semibold text-white">Nada criado ainda</p>
                <p className="mx-auto mt-1 max-w-md text-[13px] text-white/60">
                    Os combos, kits e combits que você aceitar em Pendentes ou montar à mão ficam aqui.
                </p>
            </div>
        );
    }

    return (
        <div className="mt-4 space-y-6" data-criadas>
            <p className="text-[12.5px] text-white/55" data-criadas-total>{textoDeQuantasCriadas(criadas)}</p>
            {grupos.map((g) => (
                <section key={g.fase} data-grupo-criadas={g.fase}>
                    <h2 className="mb-2 flex items-baseline gap-2 text-[14px] font-semibold text-white">
                        {g.rotulo} <span className="text-[12px] font-normal text-white/45">{g.itens.length}</span>
                    </h2>
                    <ul className="space-y-2">
                        {g.itens.map((item) => <Criada key={item.id} item={item} />)}
                    </ul>
                </section>
            ))}
        </div>
    );
}
