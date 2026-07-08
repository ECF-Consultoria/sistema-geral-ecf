import { useForm, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { Save, Power, PowerOff, Info } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

/**
 * TemplateEditForm — Phase 70 Plan 05 v15.0.
 *
 * Form principal do template: nome, descrição, priority e flag
 * `envio_automatico_mensal`. Suporta 2 modos:
 *   - Criação: `template` = null → POST route('nps.configuracao.templates.store').
 *   - Edição:  `template` = obj  → PUT  route('nps.configuracao.templates.update').
 *
 * Além do save, expõe botão de alternar `active` (via PATCH toggle-active
 * do Plan 70-01). Guard duplo com o backend: se `is_default && active`,
 * o botão fica `disabled` porque desativar o seed quebra o fallback do
 * NpsTemplateService::resolveForCompany (Phase 69).
 *
 * Contrato de props:
 *   - template: NpsTemplate | null   (null = modo criação)
 *   - onSaved:  (savedId?: number) => void  (parent atualiza selectedId)
 *
 * Reset via useEffect quando `template` muda — evita form ficar com estado
 * stale quando o admin troca de template selecionado sem submeter.
 */
export default function TemplateEditForm({ template, onSaved }) {
    const isEditing = !!template;

    const { data, setData, put, post, processing, errors, isDirty, reset } = useForm({
        nome:                    template?.nome                    ?? '',
        descricao:               template?.descricao               ?? '',
        priority:                template?.priority                ?? 0,
        envio_automatico_mensal: template?.envio_automatico_mensal ?? true,
    });

    // Reset do form quando o template selecionado muda (evita bleed do state
    // de um template para outro). `useEffect` deps: id do template.
    useEffect(() => {
        reset();
        setData({
            nome:                    template?.nome                    ?? '',
            descricao:               template?.descricao               ?? '',
            priority:                template?.priority                ?? 0,
            envio_automatico_mensal: template?.envio_automatico_mensal ?? true,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [template?.id]);

    // Submit — decide entre criar (POST) ou editar (PUT) pelo modo.
    const salvar = (e) => {
        e.preventDefault();
        if (isEditing) {
            put(route('nps.configuracao.templates.update', template.id), {
                preserveScroll: true,
                onSuccess: () => onSaved && onSaved(template.id),
            });
        } else {
            post(route('nps.configuracao.templates.store'), {
                preserveScroll: true,
                onSuccess: () => onSaved && onSaved(),
            });
        }
    };

    // Toggle active — chamado via router (não useForm) porque é ação sem body.
    const alternarAtivo = () => {
        if (!template) return;
        router.patch(route('nps.configuracao.templates.toggle-active', template.id), {}, {
            preserveScroll: true,
            onSuccess: () => onSaved && onSaved(template.id),
        });
    };

    // Guard UI — quando o template é default E está ativo, desativar é bloqueado
    // pelo controller (abort 422). Preferimos deixar o botão `disabled` com hint
    // pra evitar bater no server e receber um flash de erro.
    const bloquearDesativacao = isEditing && template.is_default && template.active;

    // Helper de classes dark-themed pra inputs (padrão do projeto — ver
    // ConfiguracaoLegado.jsx).
    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[80px] font-sans text-[13px]');

    return (
        <form onSubmit={salvar} className="bg-ecf-card border border-white/[0.08] rounded-2xl p-6 space-y-5">
            {/* Header */}
            <div className="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h2 className="text-white font-semibold text-base tracking-tight">
                        {isEditing ? 'Editar template' : 'Novo template'}
                    </h2>
                    <p className="text-white/50 text-xs mt-0.5">
                        {isEditing
                            ? `ID #${template.id} — última atualização em ${template.updated_at ? new Date(template.updated_at).toLocaleDateString('pt-BR') : '-'}`
                            : 'Preencha nome e descrição para começar. Perguntas e serviços são adicionados após salvar.'}
                    </p>
                </div>
                {isEditing && template.is_default && (
                    <span className="inline-flex items-center gap-1 text-[10.5px] font-semibold uppercase tracking-wider px-2 py-1 rounded-full bg-ecf-yellow/[0.14] text-ecf-yellow border border-ecf-yellow/25">
                        Template padrão
                    </span>
                )}
            </div>

            {/* Grid 2 colunas em desktop: nome + priority */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="sm:col-span-2 space-y-1.5">
                    <Label className="text-white/80 text-[13px] font-medium">Nome</Label>
                    <Input
                        value={data.nome}
                        onChange={(e) => setData('nome', e.target.value)}
                        placeholder="Ex.: NPS Publicação — Foco em Anúncios"
                        className={inputCls}
                    />
                    {errors.nome && <p className="text-red-400 text-[11px] mt-1">{errors.nome}</p>}
                </div>
                <div className="space-y-1.5">
                    <Label className="text-white/80 text-[13px] font-medium">Priority</Label>
                    <Input
                        type="number"
                        value={data.priority}
                        onChange={(e) => setData('priority', parseInt(e.target.value || '0', 10))}
                        className={inputCls}
                    />
                    <p className="text-white/40 text-[11px] leading-relaxed">
                        Maior valor vence quando 2+ templates aplicam à mesma empresa.
                    </p>
                    {errors.priority && <p className="text-red-400 text-[11px]">{errors.priority}</p>}
                </div>
            </div>

            {/* Descrição */}
            <div className="space-y-1.5">
                <Label className="text-white/80 text-[13px] font-medium">Descrição</Label>
                <Textarea
                    value={data.descricao ?? ''}
                    onChange={(e) => setData('descricao', e.target.value)}
                    placeholder="Contexto interno: quando este template se aplica, qual serviço cobre etc."
                    className={textareaCls}
                    rows={3}
                />
                {errors.descricao && <p className="text-red-400 text-[11px]">{errors.descricao}</p>}
            </div>

            {/* Switch envio automático mensal (checkbox nativo dark-themed) */}
            <label className="flex items-start gap-3 cursor-pointer select-none">
                <input
                    type="checkbox"
                    checked={!!data.envio_automatico_mensal}
                    onChange={(e) => setData('envio_automatico_mensal', e.target.checked)}
                    className="mt-0.5 h-4 w-4 rounded border-white/20 bg-white/[0.05] text-ecf-yellow focus:ring-ecf-yellow/40 cursor-pointer"
                />
                <div>
                    <span className="text-white/85 text-[13px] font-medium block">
                        Enviar automaticamente todo mês
                    </span>
                    <span className="text-white/40 text-[11px] leading-relaxed block">
                        Quando ligado, o comando <code className="text-white/60">nps:disparar-mensal</code> inclui empresas cobertas por este template no envio agendado.
                    </span>
                </div>
            </label>
            {errors.envio_automatico_mensal && <p className="text-red-400 text-[11px]">{errors.envio_automatico_mensal}</p>}

            {/* Rodapé — botões */}
            <div className="flex items-center justify-between gap-2 pt-2 border-t border-white/[0.06]">
                {/* Esquerda: toggle active (só em modo edição) */}
                <div className="flex items-center gap-2 min-h-[36px]">
                    {isEditing && (
                        <>
                            <Button
                                type="button"
                                onClick={alternarAtivo}
                                disabled={bloquearDesativacao || processing}
                                title={bloquearDesativacao
                                    ? 'O template padrão não pode ser desativado.'
                                    : (template.active ? 'Desativar template' : 'Ativar template')}
                                className={cn(
                                    'font-semibold',
                                    template.active
                                        ? 'bg-red-500/10 text-red-300 border border-red-500/25 hover:bg-red-500/20'
                                        : 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/25 hover:bg-emerald-500/20',
                                )}
                            >
                                {template.active
                                    ? (<><PowerOff size={14} /> Desativar</>)
                                    : (<><Power size={14} /> Ativar</>)}
                            </Button>
                            {bloquearDesativacao && (
                                <span className="inline-flex items-center gap-1 text-[11px] text-white/40">
                                    <Info size={11} /> Template padrão sempre ativo
                                </span>
                            )}
                        </>
                    )}
                </div>

                {/* Direita: salvar */}
                <div className="flex items-center gap-2">
                    <Button
                        type="submit"
                        disabled={processing || (!isDirty && isEditing)}
                        className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                    >
                        <Save size={14} />
                        {processing
                            ? 'Salvando...'
                            : (isEditing ? 'Salvar alterações' : 'Criar template')}
                    </Button>
                </div>
            </div>
        </form>
    );
}
