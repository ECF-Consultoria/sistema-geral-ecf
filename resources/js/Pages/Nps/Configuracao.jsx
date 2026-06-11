import AppLayout from '@/Layouts/AppLayout';
import { useForm, usePage } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import {
    Save, RotateCcw, RefreshCw, Mail, MessageSquare, Tag, Info,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Phase 32 — Plan 02 — Página /nps/configuracao (admin only via role:admin).
 *
 * Edita os 11 textos do fluxo NPS persistidos na chave `configuracoes.nps_textos`
 * (D-03) com **preview live** server-rendered do email (D-05).
 *
 * Arquitetura:
 *  - useForm() do Inertia controla os 11 campos (5 email + 6 perguntas).
 *  - Botão "Atualizar preview" faz POST ajax pra nps.configuracao.preview com
 *    os textos NÃO PERSISTIDOS — backend renderiza o Blade e devolve HTML.
 *  - HTML retornado é injetado num <iframe srcdoc> pra isolar o CSS do email
 *    do Tailwind do app.
 *  - Layout 2 colunas em desktop (form 60% / preview 40%); stack em mobile.
 *  - Tabs separam "Email" e "Perguntas" no form.
 *  - Painel lateral lista os placeholders aceitos com descrição (passados como
 *    prop `placeholders_doc` pelo controller).
 */
export default function NpsConfiguracao({ textos, defaults, placeholders_doc }) {
    const { csrf_token } = usePage().props;

    // Form Inertia com as 11 chaves carregadas do prop `textos`. Quando o admin
    // clica "Salvar", o useForm.put() envia o array todo — backend valida +
    // persiste como JSON em configuracoes.nps_textos.
    const { data, setData, put, processing, errors, isDirty } = useForm({
        email_assunto:               textos.email_assunto               || '',
        email_saudacao:              textos.email_saudacao              || '',
        email_corpo:                 textos.email_corpo                 || '',
        email_cta:                   textos.email_cta                   || '',
        email_assinatura:            textos.email_assinatura            || '',
        perg_estrategista:           textos.perg_estrategista           || '',
        perg_analista:               textos.perg_analista               || '',
        perg_empresa:                textos.perg_empresa                || '',
        perg_comentario_label:       textos.perg_comentario_label       || '',
        perg_comentario_placeholder: textos.perg_comentario_placeholder || '',
        perg_nome_label:             textos.perg_nome_label             || '',
    });

    // Estado do preview: HTML renderizado pelo backend + assunto renderizado.
    const [previewHtml, setPreviewHtml]       = useState(null);
    const [previewAssunto, setPreviewAssunto] = useState('');
    const [previewLoading, setPreviewLoading] = useState(false);
    const [previewError, setPreviewError]     = useState(null);

    /**
     * Faz POST ajax para o endpoint de preview com os 5 textos do email atuais
     * do form (não persistidos). Backend renderiza o Blade e devolve JSON com
     * o HTML completo do email + o assunto renderizado.
     *
     * Usa `fetch` direto (não router.post do Inertia) pra evitar o full reload
     * da página — queremos só atualizar o iframe.
     */
    const atualizarPreview = useCallback(async () => {
        setPreviewLoading(true);
        setPreviewError(null);
        try {
            const resp = await fetch(route('nps.configuracao.preview'), {
                method: 'POST',
                headers: {
                    'Content-Type':     'application/json',
                    'Accept':           'application/json',
                    'X-CSRF-TOKEN':     csrf_token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    email_assunto:    data.email_assunto,
                    email_saudacao:   data.email_saudacao,
                    email_corpo:      data.email_corpo,
                    email_cta:        data.email_cta,
                    email_assinatura: data.email_assinatura,
                }),
                credentials: 'same-origin',
            });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const json = await resp.json();
            setPreviewHtml(json.html || '');
            setPreviewAssunto(json.assunto || '');
        } catch (e) {
            setPreviewError(e.message || 'Falha ao gerar preview.');
        } finally {
            setPreviewLoading(false);
        }
    }, [data, csrf_token]);

    /**
     * Submete o form (PUT). Após sucesso, `back()` server-side dispara o flash
     * 'Textos NPS atualizados.' que o AppLayout exibe via toast.
     */
    const salvar = (e) => {
        e.preventDefault();
        put(route('nps.configuracao.update'), {
            preserveScroll: true,
        });
    };

    /**
     * Restaura os 11 campos para os defaults canônicos (D-03). Só atualiza o
     * estado do form — NÃO persiste. Admin ainda precisa clicar "Salvar" pra
     * confirmar a reversão. Mostra confirmação porque é destrutivo.
     */
    const restaurarPadrao = () => {
        if (!confirm('Restaurar todos os 11 textos para o padrão? Você ainda precisa clicar em "Salvar alterações" para confirmar.')) {
            return;
        }
        setData({
            email_assunto:               defaults.email_assunto,
            email_saudacao:              defaults.email_saudacao,
            email_corpo:                 defaults.email_corpo,
            email_cta:                   defaults.email_cta,
            email_assinatura:            defaults.email_assinatura,
            perg_estrategista:           defaults.perg_estrategista,
            perg_analista:               defaults.perg_analista,
            perg_empresa:                defaults.perg_empresa,
            perg_comentario_label:       defaults.perg_comentario_label,
            perg_comentario_placeholder: defaults.perg_comentario_placeholder,
            perg_nome_label:             defaults.perg_nome_label,
        });
    };

    // Helper de classes pra inputs/textareas dark-themed coerentes com o painel.
    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[100px] font-mono text-[12.5px] leading-relaxed');

    return (
        <AppLayout title="Configuração NPS">
            <div className="max-w-[1400px] mx-auto space-y-6">

                {/* Cabeçalho */}
                <div className="flex items-start justify-between gap-4 flex-wrap">
                    <div>
                        <h2 className="text-white font-display font-bold text-2xl tracking-tight">
                            Customização dos textos NPS
                        </h2>
                        <p className="text-white/50 text-sm mt-1 max-w-2xl">
                            Edita o assunto, corpo, CTA e assinatura do email mensal, além das perguntas e labels da
                            página de resposta. Use os placeholders listados ao lado para personalizar com nomes da
                            empresa, estrategista, analista e mês de referência.
                        </p>
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={restaurarPadrao}
                            disabled={processing}
                            className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                        >
                            <RotateCcw size={14} />
                            Restaurar padrão
                        </Button>
                        <Button
                            type="button"
                            onClick={salvar}
                            disabled={processing || !isDirty}
                            className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                        >
                            <Save size={14} />
                            {processing ? 'Salvando...' : 'Salvar alterações'}
                        </Button>
                    </div>
                </div>

                {/* Layout 2 colunas (desktop) / stack (mobile) */}
                <div className="grid grid-cols-1 lg:grid-cols-5 gap-6">

                    {/* ─── Coluna esquerda: form (60%) ────────────────────────── */}
                    <div className="lg:col-span-3 space-y-6">

                        <form onSubmit={salvar} className="space-y-4">
                            <div className="card-ecf rounded-2xl p-5">
                                <Tabs defaultValue="email" className="w-full">
                                    <TabsList className="grid grid-cols-2 w-full bg-white/[0.04] border border-white/[0.06]">
                                        <TabsTrigger
                                            value="email"
                                            className="data-[state=active]:bg-ecf-yellow/[0.12] data-[state=active]:text-ecf-yellow text-white/60"
                                        >
                                            <Mail size={14} className="mr-2" />
                                            Email mensal
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="perguntas"
                                            className="data-[state=active]:bg-ecf-yellow/[0.12] data-[state=active]:text-ecf-yellow text-white/60"
                                        >
                                            <MessageSquare size={14} className="mr-2" />
                                            Perguntas
                                        </TabsTrigger>
                                    </TabsList>

                                    {/* ─── Aba Email ──────────────────────────────────── */}
                                    <TabsContent value="email" className="space-y-5 mt-5">

                                        <CampoTexto
                                            label="Assunto do email"
                                            hint='Aparece como subject no inbox. Suporta {mes_referencia}.'
                                            value={data.email_assunto}
                                            onChange={(v) => setData('email_assunto', v)}
                                            error={errors.email_assunto}
                                            tipo="input"
                                            cls={inputCls}
                                        />

                                        <CampoTexto
                                            label="Saudação"
                                            hint='Linha de abertura — ex: "Olá!" ou "Bom dia, {nome_empresa}!"'
                                            value={data.email_saudacao}
                                            onChange={(v) => setData('email_saudacao', v)}
                                            error={errors.email_saudacao}
                                            tipo="textarea"
                                            cls={textareaCls}
                                        />

                                        <CampoTexto
                                            label="Corpo do email"
                                            hint="Texto principal. Quebras de linha viram parágrafos. Markdown não é processado — **negrito** aparece como está."
                                            value={data.email_corpo}
                                            onChange={(v) => setData('email_corpo', v)}
                                            error={errors.email_corpo}
                                            tipo="textarea"
                                            cls={cn(textareaCls, 'min-h-[180px]')}
                                        />

                                        <CampoTexto
                                            label="Texto do botão (CTA)"
                                            hint='Ex: "Responder pesquisa" — texto curto, sem placeholders.'
                                            value={data.email_cta}
                                            onChange={(v) => setData('email_cta', v)}
                                            error={errors.email_cta}
                                            tipo="input"
                                            cls={inputCls}
                                        />

                                        <CampoTexto
                                            label="Assinatura"
                                            hint='Bloco final do email — ex: "Obrigado,\nEquipe ECF".'
                                            value={data.email_assinatura}
                                            onChange={(v) => setData('email_assinatura', v)}
                                            error={errors.email_assinatura}
                                            tipo="textarea"
                                            cls={textareaCls}
                                        />

                                    </TabsContent>

                                    {/* ─── Aba Perguntas ──────────────────────────────── */}
                                    <TabsContent value="perguntas" className="space-y-5 mt-5">

                                        <CampoTexto
                                            label="Pergunta — Estrategista"
                                            hint='Aparece na página de resposta. Usa {nome_estrategista}.'
                                            value={data.perg_estrategista}
                                            onChange={(v) => setData('perg_estrategista', v)}
                                            error={errors.perg_estrategista}
                                            tipo="input"
                                            cls={inputCls}
                                        />

                                        <CampoTexto
                                            label="Pergunta — Analista"
                                            hint='Oculta automaticamente em mentoria pura. Usa {nome_analista}.'
                                            value={data.perg_analista}
                                            onChange={(v) => setData('perg_analista', v)}
                                            error={errors.perg_analista}
                                            tipo="input"
                                            cls={inputCls}
                                        />

                                        <CampoTexto
                                            label="Pergunta — Empresa"
                                            hint="Pergunta sobre a percepção geral da empresa em relação à ECF."
                                            value={data.perg_empresa}
                                            onChange={(v) => setData('perg_empresa', v)}
                                            error={errors.perg_empresa}
                                            tipo="input"
                                            cls={inputCls}
                                        />

                                        <CampoTexto
                                            label="Label do comentário"
                                            hint='Label do textarea opcional de feedback — ex: "Comentário (opcional)".'
                                            value={data.perg_comentario_label}
                                            onChange={(v) => setData('perg_comentario_label', v)}
                                            error={errors.perg_comentario_label}
                                            tipo="input"
                                            cls={inputCls}
                                        />

                                        <CampoTexto
                                            label="Placeholder do comentário"
                                            hint="Texto cinza que aparece dentro do textarea vazio (ajuda o cliente a saber o que escrever)."
                                            value={data.perg_comentario_placeholder}
                                            onChange={(v) => setData('perg_comentario_placeholder', v)}
                                            error={errors.perg_comentario_placeholder}
                                            tipo="textarea"
                                            cls={textareaCls}
                                        />

                                        <CampoTexto
                                            label="Label do nome"
                                            hint='Label do input de nome do respondente — ex: "Seu nome (opcional)".'
                                            value={data.perg_nome_label}
                                            onChange={(v) => setData('perg_nome_label', v)}
                                            error={errors.perg_nome_label}
                                            tipo="input"
                                            cls={inputCls}
                                        />

                                    </TabsContent>
                                </Tabs>
                            </div>

                            {/* Botões repetidos no rodapé do form pra conveniência em formulários longos */}
                            <div className="flex items-center justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={restaurarPadrao}
                                    disabled={processing}
                                    className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                                >
                                    <RotateCcw size={14} />
                                    Restaurar padrão
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || !isDirty}
                                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                                >
                                    <Save size={14} />
                                    {processing ? 'Salvando...' : 'Salvar alterações'}
                                </Button>
                            </div>
                        </form>

                        {/* Painel de placeholders disponíveis */}
                        <div className="card-ecf rounded-2xl p-5">
                            <div className="flex items-center gap-2 mb-3">
                                <Tag size={16} className="text-ecf-yellow" />
                                <h3 className="text-white font-semibold text-sm tracking-tight">
                                    Placeholders disponíveis
                                </h3>
                            </div>
                            <p className="text-white/40 text-xs mb-4 leading-relaxed">
                                Cole as chaves abaixo nos campos para personalizar dinamicamente. Cada empresa
                                receberá os valores reais na hora do disparo.
                            </p>
                            <ul className="space-y-2.5">
                                {placeholders_doc.map((p) => (
                                    <li key={p.chave} className="flex items-start gap-3 group">
                                        <code className="shrink-0 text-[12px] font-mono px-2 py-0.5 rounded bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/20">
                                            {p.chave}
                                        </code>
                                        <span className="text-white/60 text-xs leading-relaxed pt-0.5">
                                            {p.descricao}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>

                    </div>

                    {/* ─── Coluna direita: preview iframe (40%) ───────────────── */}
                    <div className="lg:col-span-2 space-y-4">
                        <div className="card-ecf rounded-2xl p-5 sticky top-4">
                            <div className="flex items-center justify-between gap-2 mb-4">
                                <div className="flex items-center gap-2 min-w-0">
                                    <Mail size={16} className="text-ecf-yellow shrink-0" />
                                    <h3 className="text-white font-semibold text-sm tracking-tight truncate">
                                        Preview do email
                                    </h3>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={atualizarPreview}
                                    disabled={previewLoading}
                                    className="bg-white/[0.06] text-white hover:bg-white/[0.10] border border-white/[0.08] shrink-0"
                                >
                                    <RefreshCw size={13} className={cn(previewLoading && 'animate-spin')} />
                                    {previewLoading ? 'Renderizando...' : 'Atualizar preview'}
                                </Button>
                            </div>

                            {/* Assunto renderizado — útil pra conferir o subject */}
                            {previewAssunto && (
                                <div className="mb-3 rounded-lg border border-white/[0.06] bg-white/[0.02] px-3 py-2">
                                    <p className="text-[10px] uppercase tracking-widest text-white/40 font-semibold">
                                        Assunto
                                    </p>
                                    <p className="text-white/85 text-[13px] mt-1 break-words">
                                        {previewAssunto}
                                    </p>
                                </div>
                            )}

                            {/* Erro do fetch (raro, mas útil pra diagnóstico) */}
                            {previewError && (
                                <div className="mb-3 rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-red-300 text-xs">
                                    {previewError}
                                </div>
                            )}

                            {/* Iframe srcdoc — isola CSS do email do app */}
                            {previewHtml ? (
                                <iframe
                                    srcDoc={previewHtml}
                                    title="Preview email NPS"
                                    className="w-full h-[600px] border border-white/[0.08] rounded-lg bg-white"
                                />
                            ) : (
                                <div className="flex flex-col items-center justify-center h-[600px] border border-dashed border-white/[0.08] rounded-lg bg-white/[0.01]">
                                    <Info size={32} className="text-white/20 mb-3" />
                                    <p className="text-white/40 text-sm">Clique em "Atualizar preview"</p>
                                    <p className="text-white/30 text-xs mt-1">para ver como o email vai ficar.</p>
                                </div>
                            )}

                            <p className="text-white/30 text-[11px] mt-3 leading-relaxed">
                                Preview usa vars de exemplo (Empresa Exemplo Ltda, Nathália, Igor, junho/2026).
                                Atualiza sob demanda — não recalcula automaticamente a cada tecla pra economizar render.
                            </p>
                        </div>
                    </div>

                </div>
            </div>
        </AppLayout>
    );
}

/**
 * Sub-componente local: um campo (Input ou Textarea) com Label + hint + erro.
 * Mantém o JSX dos 11 campos enxuto e consistente. Definido só nesta página
 * conforme convenção (PascalCase nomeado, helpers funcionais).
 */
function CampoTexto({ label, hint, value, onChange, error, tipo, cls }) {
    const Comp = tipo === 'textarea' ? Textarea : Input;
    return (
        <div className="space-y-1.5">
            <Label className="text-white/80 text-[13px] font-medium">{label}</Label>
            <Comp
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={cls}
            />
            {hint && (
                <p className="text-white/40 text-[11px] leading-relaxed">{hint}</p>
            )}
            {error && (
                <p className="text-red-400 text-[11px]">{error}</p>
            )}
        </div>
    );
}
