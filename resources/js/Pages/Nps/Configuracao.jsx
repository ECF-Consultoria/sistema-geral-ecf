import AppLayout from '@/Layouts/AppLayout';
import { useForm, usePage, router } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/Components/ui/tabs';
import {
    Save, RotateCcw, RefreshCw, Mail, MessageSquare, Tag, Info,
    ListChecks, Plus, X, Trash2, ArrowUp, ArrowDown, Pencil, Check,
} from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Phase 32 — Plan 02 — Página /nps/configuracao (admin only via role:admin).
 * Phase 33 — Plan 02 — Adicionada 3ª aba "Perguntas extras" (CRUD inline).
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
 *  - Tabs separam "Email", "Perguntas fixas" e "Perguntas extras" no form.
 *  - 3ª aba "Perguntas extras" (Phase 33) ocupa LARGURA INTEIRA — não tem preview
 *    de email correspondente (CRUD de perguntas customizadas que vão para o
 *    Respond.jsx do cliente).
 *  - Painel lateral lista os placeholders aceitos com descrição (passados como
 *    prop `placeholders_doc` pelo controller).
 */

// ─── Constantes Phase 33 ─────────────────────────────────────────────────
// Em sync manual com App\Models\NpsPerguntaCustomizada::TIPOS (padrão do projeto
// para enums espelhados entre PHP e JS — ver Sugadores.STATUS_LABELS).
const TIPOS_PERGUNTA = [
    { value: 'escala_1_5', label: 'Escala 1 a 5' },
    { value: 'texto',      label: 'Texto livre' },
    { value: 'sim_nao',    label: 'Sim / Não' },
    { value: 'multipla',   label: 'Múltipla escolha' },
];

const TIPO_LABELS = TIPOS_PERGUNTA.reduce((acc, t) => { acc[t.value] = t.label; return acc; }, {});

const FORM_NOVA_DEFAULT = {
    texto:       '',
    tipo:        'escala_1_5',
    opcoes:      [],
    obrigatorio: false,
    ativa:       true,
};

export default function NpsConfiguracao({ textos, defaults, placeholders_doc, perguntas_extras = [] }) {
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

    // ─── Phase 33: state CRUD perguntas extras ───────────────────────────
    // `abaAtiva` controla qual aba está ativa para podermos colapsar o split
    // preview-form quando estamos na 3ª aba (extras ocupa full-width).
    const [abaAtiva, setAbaAtiva] = useState('email');
    // Form de criação (colapsado por default).
    const [criando, setCriando]     = useState(false);
    const [formNova, setFormNova]   = useState(FORM_NOVA_DEFAULT);
    // Edição inline: id da pergunta em edição + clone do form preenchido.
    const [editandoId, setEditandoId]     = useState(null);
    const [formEditando, setFormEditando] = useState(null);
    // Flag para mostrar mensagens de validação no form de criar/editar.
    const [erroLocal, setErroLocal] = useState(null);

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

    // ─── Phase 33: helpers CRUD ──────────────────────────────────────────

    /** Valida shape do form (criar ou editar). Retorna string de erro ou null. */
    const validarForm = (f) => {
        if (!f.texto || !f.texto.trim()) return 'Informe o texto da pergunta.';
        if (f.tipo === 'multipla') {
            const opcoesLimpas = (f.opcoes || []).map(o => (o || '').trim()).filter(o => o.length > 0);
            if (opcoesLimpas.length < 2) return 'Pergunta de múltipla escolha precisa de pelo menos 2 opções não-vazias.';
        }
        return null;
    };

    /** Limpa o form de nova pergunta e fecha. */
    const resetFormNova = () => {
        setFormNova(FORM_NOVA_DEFAULT);
        setCriando(false);
        setErroLocal(null);
    };

    /** Submete criação. Backend silenciosamente ignora opcoes fora de multipla. */
    const salvarNova = () => {
        const err = validarForm(formNova);
        if (err) { setErroLocal(err); return; }
        // Sanitiza opções vazias antes de enviar (defesa client-side).
        const payload = {
            ...formNova,
            opcoes: formNova.tipo === 'multipla'
                ? formNova.opcoes.map(o => (o || '').trim()).filter(o => o.length > 0)
                : [],
        };
        router.post(route('nps.configuracao.perguntas.criar'), payload, {
            preserveScroll: true,
            onSuccess: () => resetFormNova(),
        });
    };

    /** Abre edição inline com clone dos dados da pergunta. */
    const iniciarEdicao = (p) => {
        setEditandoId(p.id);
        setFormEditando({
            texto:       p.texto || '',
            tipo:        p.tipo,
            opcoes:      Array.isArray(p.opcoes) ? [...p.opcoes] : [],
            obrigatorio: !!p.obrigatorio,
            ativa:       !!p.ativa,
        });
        setErroLocal(null);
        // Se o form de criar estiver aberto, fecha para evitar 2 forms simultâneos.
        if (criando) setCriando(false);
    };

    const cancelarEdicao = () => {
        setEditandoId(null);
        setFormEditando(null);
        setErroLocal(null);
    };

    const salvarEdicao = () => {
        const err = validarForm(formEditando);
        if (err) { setErroLocal(err); return; }
        const payload = {
            ...formEditando,
            opcoes: formEditando.tipo === 'multipla'
                ? formEditando.opcoes.map(o => (o || '').trim()).filter(o => o.length > 0)
                : [],
        };
        router.put(route('nps.configuracao.perguntas.atualizar', editandoId), payload, {
            preserveScroll: true,
            onSuccess: () => cancelarEdicao(),
        });
    };

    /** Toggle do switch "ativa" inline na lista (sem abrir form). */
    const toggleAtiva = (p) => {
        router.put(route('nps.configuracao.perguntas.atualizar', p.id), { ativa: !p.ativa }, {
            preserveScroll: true,
        });
    };

    const mover = (p, direcao) => {
        router.post(route('nps.configuracao.perguntas.mover', p.id), { direcao }, {
            preserveScroll: true,
        });
    };

    const excluir = (p) => {
        if (!confirm(`Excluir a pergunta "${p.texto}"?`)) return;
        router.delete(route('nps.configuracao.perguntas.excluir', p.id), {
            preserveScroll: true,
        });
    };

    // Helpers de manipulação de opções (compartilhados entre form novo e edição).
    const adicionarOpcao = (state, set) => set({ ...state, opcoes: [...(state.opcoes || []), ''] });
    const atualizarOpcao = (state, set, idx, v) => set({
        ...state,
        opcoes: (state.opcoes || []).map((o, i) => i === idx ? v : o),
    });
    const removerOpcao   = (state, set, idx) => set({
        ...state,
        opcoes: (state.opcoes || []).filter((_, i) => i !== idx),
    });

    // Helper de classes pra inputs/textareas dark-themed coerentes com o painel.
    const inputCls    = 'bg-white/[0.03] border-white/[0.08] text-white placeholder:text-white/30 focus-visible:ring-ecf-yellow/30';
    const textareaCls = cn(inputCls, 'min-h-[100px] font-mono text-[12.5px] leading-relaxed');

    // Na aba "extras" o layout ocupa width inteiro; nas outras 2 mantém split.
    const isExtras = abaAtiva === 'extras';
    const totalAtivas = perguntas_extras.filter(p => p.ativa).length;

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
                    {/* Botões "Salvar" do topo só fazem sentido nas abas de textos (form Inertia).
                        Na aba "Perguntas extras" o CRUD é por pergunta, então ocultamos para evitar
                        confusão (cada pergunta tem seus próprios botões inline). */}
                    {!isExtras && (
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
                    )}
                </div>

                {/* Layout adapta: nas abas Email/Perguntas fixas usa split 60/40 com preview;
                    na aba "Perguntas extras" usa coluna única full-width (não há preview de email
                    para perguntas customizadas). */}
                <div className={cn('grid grid-cols-1 gap-6', !isExtras && 'lg:grid-cols-5')}>

                    {/* ─── Coluna principal (form/CRUD) ───────────────────────── */}
                    <div className={cn('space-y-6', !isExtras && 'lg:col-span-3')}>

                        <form onSubmit={salvar} className="space-y-4">
                            <div className="card-ecf rounded-2xl p-5">
                                <Tabs
                                    value={abaAtiva}
                                    onValueChange={setAbaAtiva}
                                    className="w-full"
                                >
                                    <TabsList className="grid grid-cols-3 w-full bg-white/[0.04] border border-white/[0.06]">
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
                                            Perguntas fixas
                                        </TabsTrigger>
                                        <TabsTrigger
                                            value="extras"
                                            className="data-[state=active]:bg-ecf-yellow/[0.12] data-[state=active]:text-ecf-yellow text-white/60"
                                        >
                                            <ListChecks size={14} className="mr-2" />
                                            Perguntas extras
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

                                    {/* ─── Aba Perguntas fixas ────────────────────────── */}
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

                                    {/* ─── Aba Perguntas extras (Phase 33) ────────────── */}
                                    <TabsContent value="extras" className="space-y-5 mt-5">
                                        <div className="space-y-1">
                                            <p className="text-white/70 text-sm leading-relaxed">
                                                Perguntas customizadas que aparecem ao cliente <strong>após</strong> as 3
                                                perguntas fixas, antes do comentário livre. Mantenha as obrigatórias no
                                                mínimo para não cansar o respondente.
                                            </p>
                                            <p className="text-white/40 text-xs">
                                                {perguntas_extras.length} cadastrada{perguntas_extras.length === 1 ? '' : 's'}
                                                {' '}— {totalAtivas} ativa{totalAtivas === 1 ? '' : 's'}.
                                            </p>
                                        </div>

                                        {/* Botão / form de criação no topo */}
                                        {!criando ? (
                                            <Button
                                                type="button"
                                                onClick={() => { cancelarEdicao(); setCriando(true); setErroLocal(null); }}
                                                className="bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/30 hover:bg-ecf-yellow/[0.18] font-semibold"
                                            >
                                                <Plus size={14} />
                                                Nova pergunta
                                            </Button>
                                        ) : (
                                            <FormPergunta
                                                titulo="Nova pergunta extra"
                                                state={formNova}
                                                setState={setFormNova}
                                                onSalvar={salvarNova}
                                                onCancelar={resetFormNova}
                                                erroLocal={erroLocal}
                                                inputCls={inputCls}
                                                textareaCls={textareaCls}
                                                adicionarOpcao={adicionarOpcao}
                                                atualizarOpcao={atualizarOpcao}
                                                removerOpcao={removerOpcao}
                                            />
                                        )}

                                        {/* Lista de perguntas existentes */}
                                        <div className="space-y-3">
                                            <h3 className="text-white/80 font-semibold text-[13px] uppercase tracking-wider">
                                                Perguntas cadastradas ({perguntas_extras.length})
                                            </h3>

                                            {perguntas_extras.length === 0 && (
                                                <div className="rounded-xl border border-dashed border-white/[0.08] bg-white/[0.01] p-6 text-center">
                                                    <ListChecks size={28} className="text-white/20 mx-auto mb-2" />
                                                    <p className="text-white/50 text-sm">
                                                        Nenhuma pergunta extra cadastrada. Crie a primeira acima.
                                                    </p>
                                                </div>
                                            )}

                                            {perguntas_extras.map((p, idx) => {
                                                const emEdicao = editandoId === p.id;
                                                if (emEdicao) {
                                                    return (
                                                        <FormPergunta
                                                            key={p.id}
                                                            titulo={`Editar pergunta #${p.id}`}
                                                            state={formEditando}
                                                            setState={setFormEditando}
                                                            onSalvar={salvarEdicao}
                                                            onCancelar={cancelarEdicao}
                                                            erroLocal={erroLocal}
                                                            inputCls={inputCls}
                                                            textareaCls={textareaCls}
                                                            adicionarOpcao={adicionarOpcao}
                                                            atualizarOpcao={atualizarOpcao}
                                                            removerOpcao={removerOpcao}
                                                        />
                                                    );
                                                }
                                                return (
                                                    <CardPergunta
                                                        key={p.id}
                                                        pergunta={p}
                                                        primeiro={idx === 0}
                                                        ultimo={idx === perguntas_extras.length - 1}
                                                        onMoverUp={() => mover(p, 'up')}
                                                        onMoverDown={() => mover(p, 'down')}
                                                        onToggleAtiva={() => toggleAtiva(p)}
                                                        onEditar={() => iniciarEdicao(p)}
                                                        onExcluir={() => excluir(p)}
                                                    />
                                                );
                                            })}
                                        </div>
                                    </TabsContent>
                                </Tabs>
                            </div>

                            {/* Botões repetidos no rodapé do form pra conveniência em formulários longos.
                                Ocultos na aba "extras" porque o CRUD é por-pergunta (cada pergunta tem
                                botões próprios). */}
                            {!isExtras && (
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
                            )}
                        </form>

                        {/* Painel de placeholders disponíveis — só faz sentido nas abas de textos
                            (Email/Perguntas fixas). Na aba "extras" não há placeholders dinâmicos. */}
                        {!isExtras && (
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
                        )}

                    </div>

                    {/* ─── Coluna direita: preview iframe (40%) — só nas abas de email/fixas ─── */}
                    {!isExtras && (
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
                    )}

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

// ═══════════════════════════════════════════════════════════════════════
// Phase 33 — Sub-componentes da aba "Perguntas extras"
// ═══════════════════════════════════════════════════════════════════════

/**
 * Switch toggle estilizado (sem dependência do @radix-ui/react-switch — não
 * temos esse pacote instalado). Implementa o visual de switch via div + button.
 * Acessível via teclado (Space/Enter triggam onChange).
 */
function ToggleSwitch({ checked, onChange, label, descricao }) {
    return (
        <div className="flex items-start gap-3">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border transition-colors',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-ecf-yellow/40',
                    checked
                        ? 'bg-ecf-yellow border-ecf-yellow'
                        : 'bg-white/[0.06] border-white/[0.12]',
                )}
            >
                <span
                    className={cn(
                        'inline-block h-4 w-4 rounded-full shadow-sm transition-transform',
                        checked ? 'translate-x-6 bg-[#050507]' : 'translate-x-1 bg-white/70',
                    )}
                />
            </button>
            {(label || descricao) && (
                <div className="space-y-0.5">
                    {label && (
                        <span
                            onClick={() => onChange(!checked)}
                            className="text-white/85 text-[13px] font-medium cursor-pointer select-none block"
                        >
                            {label}
                        </span>
                    )}
                    {descricao && (
                        <span className="text-white/40 text-[11px] leading-relaxed block">
                            {descricao}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * Native select estilizado dark-theme — segue o mesmo padrão de Sugadores/Index.jsx.
 * Evita a complexidade do Select shadcn (Radix portal) para um form simples interno.
 */
function NativeSelect({ value, onChange, options, className }) {
    return (
        <select
            value={value || ''}
            onChange={e => onChange(e.target.value)}
            className={cn(
                'h-10 pl-3 pr-8 rounded-md border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/85 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer w-full',
                className,
            )}
        >
            {options.map(o => (
                <option key={o.value} value={o.value} className="bg-[#0f1116] text-white">
                    {o.label}
                </option>
            ))}
        </select>
    );
}

/**
 * Form de criar OU editar pergunta extra. Compartilhado entre os dois fluxos
 * via props `state`/`setState`/`onSalvar`/`onCancelar`. Renderiza:
 *  - input texto
 *  - select tipo (4 opções)
 *  - bloco condicional de opções (só se tipo=multipla)
 *  - 2 toggles (obrigatorio, ativa)
 *  - botões Salvar/Cancelar + erro local
 */
function FormPergunta({
    titulo, state, setState, onSalvar, onCancelar, erroLocal,
    inputCls, textareaCls,
    adicionarOpcao, atualizarOpcao, removerOpcao,
}) {
    if (!state) return null;

    return (
        <div className="rounded-xl border border-ecf-yellow/20 bg-ecf-yellow/[0.04] p-5 space-y-4">
            <div className="flex items-center justify-between">
                <h4 className="text-ecf-yellow font-semibold text-sm tracking-tight">{titulo}</h4>
            </div>

            <div className="space-y-1.5">
                <Label className="text-white/80 text-[13px] font-medium">Texto da pergunta</Label>
                <Textarea
                    value={state.texto}
                    onChange={(e) => setState({ ...state, texto: e.target.value })}
                    placeholder="Ex.: Como você avaliaria nosso atendimento neste mês?"
                    className={cn(textareaCls, 'min-h-[70px] font-sans text-[13px]')}
                />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                    <Label className="text-white/80 text-[13px] font-medium">Tipo de resposta</Label>
                    <NativeSelect
                        value={state.tipo}
                        onChange={(v) => setState({
                            ...state,
                            tipo: v,
                            // Limpa opções ao trocar para fora de multipla, e zera para multipla nova.
                            opcoes: v === 'multipla' ? (state.opcoes && state.opcoes.length > 0 ? state.opcoes : ['', '']) : [],
                        })}
                        options={TIPOS_PERGUNTA}
                    />
                    <p className="text-white/40 text-[11px] leading-relaxed">
                        Define como o cliente responde a pergunta.
                    </p>
                </div>
                <div className="space-y-2 pt-1">
                    <ToggleSwitch
                        checked={state.obrigatorio}
                        onChange={(v) => setState({ ...state, obrigatorio: v })}
                        label="Resposta obrigatória"
                        descricao="Bloqueia o envio do formulário se em branco."
                    />
                    <ToggleSwitch
                        checked={state.ativa}
                        onChange={(v) => setState({ ...state, ativa: v })}
                        label="Pergunta ativa"
                        descricao="Quando desligada, não aparece em novas surveys."
                    />
                </div>
            </div>

            {/* Bloco de opções — só renderiza quando tipo === 'multipla' */}
            {state.tipo === 'multipla' && (
                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <Label className="text-white/80 text-[13px] font-medium">
                            Opções de resposta <span className="text-white/40 font-normal">(mínimo 2)</span>
                        </Label>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => adicionarOpcao(state, setState)}
                            className="bg-white/[0.06] text-white/80 hover:bg-white/[0.10] border border-white/[0.08]"
                        >
                            <Plus size={12} />
                            Adicionar opção
                        </Button>
                    </div>
                    <div className="space-y-2">
                        {(state.opcoes || []).length === 0 && (
                            <p className="text-white/40 text-[12px] italic">
                                Nenhuma opção adicionada — clique em "Adicionar opção".
                            </p>
                        )}
                        {(state.opcoes || []).map((opc, idx) => (
                            <div key={idx} className="flex items-center gap-2">
                                <span className="text-white/40 text-[12px] font-mono w-5 shrink-0">{idx + 1}.</span>
                                <Input
                                    value={opc}
                                    onChange={(e) => atualizarOpcao(state, setState, idx, e.target.value)}
                                    placeholder={`Opção ${idx + 1}`}
                                    className={inputCls}
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() => removerOpcao(state, setState, idx)}
                                    className="border-white/[0.08] bg-white/[0.03] text-white/60 hover:bg-red-500/10 hover:text-red-300 hover:border-red-500/30 shrink-0"
                                    title="Remover opção"
                                >
                                    <X size={14} />
                                </Button>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Mensagem de erro local (validação client) ou erro flash do backend */}
            {erroLocal && (
                <div className="rounded-lg border border-red-500/30 bg-red-950/40 px-3 py-2 text-red-300 text-[12px]">
                    {erroLocal}
                </div>
            )}

            <div className="flex items-center justify-end gap-2 pt-1">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancelar}
                    className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    onClick={onSalvar}
                    className="bg-ecf-yellow text-[#050507] hover:bg-ecf-yellow/90 font-semibold"
                >
                    <Check size={14} />
                    Salvar
                </Button>
            </div>
        </div>
    );
}

/**
 * Card de uma pergunta na lista (modo visualização). Layout:
 *  [↑]   |  texto + badges (tipo, obrig, ativa)   | [Switch ativa] [Editar] [Excluir]
 *  [↓]   |  (se multipla) chips com as opções     |
 */
function CardPergunta({
    pergunta, primeiro, ultimo,
    onMoverUp, onMoverDown, onToggleAtiva, onEditar, onExcluir,
}) {
    const tipoLabel = TIPO_LABELS[pergunta.tipo] || pergunta.tipo;
    const opcoes = Array.isArray(pergunta.opcoes) ? pergunta.opcoes : [];

    return (
        <div className={cn(
            'rounded-xl border bg-white/[0.02] p-4 transition-colors',
            pergunta.ativa
                ? 'border-white/[0.08] hover:border-white/[0.14]'
                : 'border-white/[0.05] opacity-60 hover:opacity-80',
        )}>
            <div className="flex items-start gap-3">

                {/* Coluna esquerda: setas de ordenação empilhadas */}
                <div className="flex flex-col gap-0.5 shrink-0 pt-0.5">
                    <button
                        type="button"
                        onClick={onMoverUp}
                        disabled={primeiro}
                        title="Mover para cima"
                        className={cn(
                            'h-6 w-6 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                            primeiro && 'opacity-30 cursor-not-allowed hover:bg-transparent hover:text-white/40',
                        )}
                    >
                        <ArrowUp size={14} />
                    </button>
                    <button
                        type="button"
                        onClick={onMoverDown}
                        disabled={ultimo}
                        title="Mover para baixo"
                        className={cn(
                            'h-6 w-6 rounded flex items-center justify-center text-white/40 hover:bg-white/[0.06] hover:text-white/80',
                            ultimo && 'opacity-30 cursor-not-allowed hover:bg-transparent hover:text-white/40',
                        )}
                    >
                        <ArrowDown size={14} />
                    </button>
                </div>

                {/* Coluna central: texto + badges + (chips se multipla) */}
                <div className="flex-1 min-w-0 space-y-2">
                    <p className="text-white/90 text-[13.5px] leading-snug font-medium break-words">
                        {pergunta.texto}
                    </p>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="inline-flex items-center text-[10.5px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-ecf-yellow/[0.12] text-ecf-yellow border border-ecf-yellow/20">
                            {tipoLabel}
                        </span>
                        {pergunta.obrigatorio && (
                            <span className="inline-flex items-center text-[10.5px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-red-500/[0.12] text-red-300 border border-red-500/20">
                                Obrigatória
                            </span>
                        )}
                        <span className={cn(
                            'inline-flex items-center text-[10.5px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full border',
                            pergunta.ativa
                                ? 'bg-green-500/[0.12] text-green-300 border-green-500/20'
                                : 'bg-white/[0.04] text-white/40 border-white/[0.08]',
                        )}>
                            {pergunta.ativa ? 'Ativa' : 'Desativada'}
                        </span>
                        <span className="text-white/30 text-[10.5px]">
                            ordem {pergunta.ordem}
                        </span>
                    </div>
                    {pergunta.tipo === 'multipla' && opcoes.length > 0 && (
                        <div className="flex flex-wrap gap-1.5 pt-0.5">
                            {opcoes.map((opc, i) => (
                                <span
                                    key={i}
                                    className="inline-flex items-center text-[11px] px-2 py-0.5 rounded bg-white/[0.04] text-white/60 border border-white/[0.06]"
                                >
                                    {opc}
                                </span>
                            ))}
                        </div>
                    )}
                </div>

                {/* Coluna direita: toggle ativa + ações */}
                <div className="flex items-center gap-2 shrink-0">
                    <div className="hidden sm:block" title={pergunta.ativa ? 'Desativar pergunta' : 'Ativar pergunta'}>
                        <ToggleSwitch
                            checked={pergunta.ativa}
                            onChange={onToggleAtiva}
                        />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onEditar}
                        className="border-white/[0.08] bg-white/[0.03] text-white/70 hover:bg-white/[0.08] hover:text-white"
                    >
                        <Pencil size={13} />
                        Editar
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onExcluir}
                        className="border-red-500/20 bg-red-500/[0.04] text-red-300 hover:bg-red-500/[0.12] hover:text-red-200 hover:border-red-500/40"
                        title="Excluir pergunta"
                    >
                        <Trash2 size={13} />
                    </Button>
                </div>
            </div>
        </div>
    );
}
