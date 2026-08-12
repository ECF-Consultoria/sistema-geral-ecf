import { useState } from 'react';
import { ChevronDown, ChevronUp, Trash2, Zap, Info } from 'lucide-react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Checkbox } from '@/Components/ui/checkbox';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import { SEM_VALOR } from './sentinelaSemValor';

// Catálogo fechado de DONOS (D-14) — só três valores fixos ('cliente' /
// 'interno' / 'sistema'). Por isso o campo é um segmentado de botões, nunca
// um `Select`: errar a escolha de dono aqui é o erro mais caro do formulário
// inteiro (é de quem o painel operacional cobra o SLA).
const DONOS_CORES = {
    cliente: 'border-sky-500/40 bg-sky-500/10 text-sky-300',
    interno: 'border-violet-500/40 bg-violet-500/10 text-violet-300',
    sistema: 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow/70',
};

// Sugestão de `chave` a partir do `título` — só usada enquanto o admin não
// editar a chave manualmente (ver `_chaveManual` no estado do passo).
function slugify(texto) {
    // Range Unicode U+0300..U+036F = marcas diacriticas combinantes; NFD
    // separa a letra base do acento, e este replace descarta so o acento.
    const marcasDiacriticas = new RegExp('[\\u0300-\\u036f]', 'g');
    return (texto || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(marcasDiacriticas, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');
}

/**
 * PassoEditor — card expansível de um passo do template (Tela 2, 135-UI-SPEC).
 * 9 campos: titulo, chave, dono, setor_id, depende_de, sla_dias, auto_fonte,
 * condicao, obrigatorio. `auto_fonte` é sempre um Select alimentado pelo
 * catálogo fechado do backend — nenhum campo de texto livre ao lado (D-09).
 */
export default function PassoEditor({
    passo,
    indice,
    todosPassos,
    catalogo_auto_fonte,
    catalogo_condicoes,
    catalogo_donos,
    setores,
    errors,
    onChange,
    onRemover,
    onMoverCima,
    onMoverBaixo,
    podeSubir,
    podeDescer,
}) {
    // Passos novos nascem abertos; a decisão de recolher é só do admin.
    const [expandido, setExpandido] = useState(true);

    const erroDoPasso = (campo) => errors?.[`passos.${indice}.${campo}`];

    const patch = (parcial) => onChange(indice, parcial);

    const alterarTitulo = (novoTitulo) => {
        const proxima = { titulo: novoTitulo };
        if (!passo._chaveManual) {
            proxima.chave = slugify(novoTitulo);
        }
        patch(proxima);
    };

    const alterarChave = (novaChave) => {
        patch({ chave: novaChave, _chaveManual: true });
    };

    const entradaAutoFonte = (catalogo_auto_fonte || []).find((c) => c.chave === passo.auto_fonte);
    const labelDono = (catalogo_donos || []).find((d) => d.chave === passo.dono)?.label || passo.dono;

    const outrosPassos = (todosPassos || []).filter((_, i) => i !== indice);
    const dependenciasAtuais = passo.depende_de || [];

    const alternarDependencia = (chaveAlvo) => {
        const jaTem = dependenciasAtuais.includes(chaveAlvo);
        patch({
            depende_de: jaTem
                ? dependenciasAtuais.filter((c) => c !== chaveAlvo)
                : [...dependenciasAtuais, chaveAlvo],
        });
    };

    return (
        <div className="rounded-xl border border-white/[0.08] bg-ecf-card overflow-hidden">
            {/* Cabeçalho — sempre visível, alterna a expansão do card */}
            <div className="flex items-center gap-3 p-4">
                <button
                    type="button"
                    onClick={() => setExpandido((v) => !v)}
                    className="flex-1 flex items-center gap-3 text-left min-w-0"
                >
                    <span
                        className={cn(
                            'inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-semibold shrink-0',
                            DONOS_CORES[passo.dono] || 'border-white/10 text-white/50',
                        )}
                    >
                        {labelDono}
                    </span>
                    <span className="text-white text-sm font-medium truncate">
                        {passo.titulo || 'Novo passo'}
                    </span>
                    {passo.auto_fonte && passo.auto_fonte !== SEM_VALOR && (
                        <Zap
                            size={13}
                            className="text-ecf-yellow shrink-0"
                            aria-label="Passo verificado automaticamente pelo sistema"
                            title="Passo verificado automaticamente pelo sistema"
                        />
                    )}
                </button>

                <div className="flex items-center gap-1 shrink-0">
                    <button
                        type="button"
                        disabled={!podeSubir}
                        onClick={onMoverCima}
                        className="p-1.5 rounded text-white/40 hover:text-white disabled:opacity-20 disabled:hover:text-white/40"
                        aria-label="Mover passo para cima"
                    >
                        <ChevronUp size={15} />
                    </button>
                    <button
                        type="button"
                        disabled={!podeDescer}
                        onClick={onMoverBaixo}
                        className="p-1.5 rounded text-white/40 hover:text-white disabled:opacity-20 disabled:hover:text-white/40"
                        aria-label="Mover passo para baixo"
                    >
                        <ChevronDown size={15} />
                    </button>
                    {/* Remover é reversível até publicar (D-07) — sem modal de confirmação */}
                    <button
                        type="button"
                        onClick={onRemover}
                        className="p-1.5 rounded text-white/40 hover:text-red-400"
                        aria-label="Remover passo"
                    >
                        <Trash2 size={15} />
                    </button>
                </div>
            </div>

            {expandido && (
                <div className="border-t border-white/[0.06] p-4 space-y-4">
                    {/* titulo */}
                    <div className="space-y-1">
                        <Label className="text-[11px] font-semibold text-white/60">Título</Label>
                        <Input
                            value={passo.titulo || ''}
                            onChange={(e) => alterarTitulo(e.target.value)}
                            placeholder="Ex.: Acesso colaborador ML"
                        />
                        {erroDoPasso('titulo') && <p className="text-red-400 text-xs">{erroDoPasso('titulo')}</p>}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {/* chave */}
                        <div className="space-y-1">
                            <Label className="text-[11px] font-semibold text-white/60">Chave</Label>
                            <Input
                                value={passo.chave || ''}
                                onChange={(e) => alterarChave(e.target.value)}
                                placeholder="ex_chave_do_passo"
                            />
                            {erroDoPasso('chave') && <p className="text-red-400 text-xs">{erroDoPasso('chave')}</p>}
                        </div>

                        {/* sla_dias */}
                        <div className="space-y-1">
                            <Label className="text-[11px] font-semibold text-white/60">SLA (dias)</Label>
                            <Input
                                type="number"
                                min={1}
                                max={365}
                                value={passo.sla_dias ?? ''}
                                onChange={(e) => patch({ sla_dias: e.target.value })}
                                placeholder="Ex.: 3"
                            />
                            {erroDoPasso('sla_dias') && <p className="text-red-400 text-xs">{erroDoPasso('sla_dias')}</p>}
                        </div>
                    </div>

                    {/* dono — segmentado de 3 botões, nunca Select (D-14) */}
                    <div className="space-y-1">
                        <Label className="text-[11px] font-semibold text-white/60">Dono</Label>
                        <div className="flex gap-2">
                            {(catalogo_donos || []).map((opcao) => (
                                <button
                                    key={opcao.chave}
                                    type="button"
                                    onClick={() => patch({ dono: opcao.chave })}
                                    className={cn(
                                        'flex-1 rounded-lg border px-3 py-2 text-[13px] font-medium transition-colors',
                                        passo.dono === opcao.chave
                                            ? DONOS_CORES[opcao.chave]
                                            : 'border-white/10 bg-white/[0.02] text-white/50 hover:bg-white/[0.05]',
                                    )}
                                >
                                    {opcao.label}
                                </button>
                            ))}
                        </div>
                        {erroDoPasso('dono') && <p className="text-red-400 text-xs">{erroDoPasso('dono')}</p>}
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {/* setor_id — Select nullable, sentinela SEM_VALOR */}
                        <div className="space-y-1">
                            <Label className="text-[11px] font-semibold text-white/60">Setor (opcional)</Label>
                            <Select
                                value={passo.setor_id ?? SEM_VALOR}
                                onValueChange={(v) => patch({ setor_id: v })}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={SEM_VALOR}>Nenhum</SelectItem>
                                    {(setores || []).map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>
                                            {s.nome}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* condicao — catálogo fechado (D-12), sentinela SEM_VALOR por padrão */}
                        <div className="space-y-1">
                            <Label className="text-[11px] font-semibold text-white/60">Condição (opcional)</Label>
                            <Select
                                value={passo.condicao ?? SEM_VALOR}
                                onValueChange={(v) => patch({ condicao: v })}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={SEM_VALOR}>Sempre nasce</SelectItem>
                                    {(catalogo_condicoes || []).map((c) => (
                                        <SelectItem key={c.chave} value={c.chave}>
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/*
                        auto_fonte — catálogo fechado (D-09). Sempre um Select, nunca um
                        Input/Textarea ao lado: a AUSÊNCIA de campo de texto livre é o que
                        comunica ao admin que isto é fechado, não uma anotação livre.
                    */}
                    <div className="space-y-1">
                        <Label className="text-[11px] font-semibold text-white/60">Fonte automática (opcional)</Label>
                        <Select
                            value={passo.auto_fonte ?? SEM_VALOR}
                            onValueChange={(v) => patch({ auto_fonte: v })}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SEM_VALOR}>Nenhum — conclusão manual</SelectItem>
                                {(catalogo_auto_fonte || []).map((c) => (
                                    <SelectItem key={c.chave} value={c.chave}>
                                        {c.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {entradaAutoFonte && (
                            <p className="text-white/50 text-xs flex items-start gap-1.5 mt-1">
                                <Info size={12} className="shrink-0 mt-0.5" />
                                <span>
                                    {entradaAutoFonte.ajuda}
                                    {entradaAutoFonte.assincrono && (
                                        <span className="text-amber-300">
                                            {' '}Roda em segundo plano (job) — pode levar alguns minutos para fechar.
                                        </span>
                                    )}
                                </span>
                            </p>
                        )}
                    </div>

                    {/* depende_de — multi-select por chips das chaves dos outros passos */}
                    <div className="space-y-1">
                        <Label className="text-[11px] font-semibold text-white/60">Depende de</Label>
                        {outrosPassos.length === 0 ? (
                            <p className="text-white/30 text-xs italic">Nenhum outro passo neste template ainda.</p>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {outrosPassos.map((p) => {
                                    const marcado = !!p.chave && dependenciasAtuais.includes(p.chave);
                                    return (
                                        <button
                                            key={p._key || p.chave}
                                            type="button"
                                            disabled={!p.chave}
                                            onClick={() => alternarDependencia(p.chave)}
                                            className={cn(
                                                'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors disabled:opacity-30',
                                                marcado
                                                    ? 'border-ecf-yellow/40 bg-ecf-yellow/10 text-ecf-yellow'
                                                    : 'border-white/10 bg-white/[0.02] text-white/50 hover:bg-white/[0.05]',
                                            )}
                                        >
                                            {p.titulo || p.chave || '(sem chave ainda)'}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {/*
                            Copy exata do Copywriting Contract — mostrada sempre que o backend
                            devolve erro neste campo, independente do texto exato que ele mande
                            (o servidor manda a mensagem de ciclo; o banner geral no topo da
                            página é quem mostra o caminho por extenso).
                        */}
                        {erroDoPasso('depende_de') && (
                            <p className="text-red-400 text-xs">Isto criaria um ciclo de dependência.</p>
                        )}
                    </div>

                    {/* obrigatorio */}
                    <div className="flex items-center gap-2">
                        <Checkbox
                            checked={!!passo.obrigatorio}
                            onCheckedChange={(v) => patch({ obrigatorio: !!v })}
                            id={`onboarding-passo-obrigatorio-${indice}`}
                        />
                        <Label
                            htmlFor={`onboarding-passo-obrigatorio-${indice}`}
                            className="text-[13px] text-white/70 cursor-pointer"
                        >
                            Passo obrigatório
                        </Label>
                    </div>
                </div>
            )}
        </div>
    );
}
