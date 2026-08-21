import { Fragment, useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/Components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { IMaskInput } from 'react-imask';
import { ArrowLeft, Building2, AlertTriangle, Send, UserCog, Ban, RefreshCcw, ChevronDown, ChevronRight, Unlock } from 'lucide-react';
import { cn, formatDate } from '@/lib/utils';
import { classeContratoComPreparo, rotuloContratoComPreparo, formatarHaDias, PREPARANDO_AVISO, MONTAGEM_TRAVADA_AVISO } from '@/lib/contratoStatus';

// D-11 (herdada da Fase 130, absorvida no plano 131-06) — causas de
// ContratosPresosService que merecem a faixa de destaque vermelha ANTES de
// confirmar a liberação manual. Espelhado à mão em JS, mesma convenção do
// resto do projeto (não há enum compartilhado entre PHP e JS).
const CAUSAS_DE_DESTAQUE = ['recusado_pelo_cliente', 'prazo_expirado', 'cancelado', 'erro_tecnico'];

const CAUSA_DESTAQUE_TEXTO = {
    recusado_pelo_cliente: 'Este contrato foi RECUSADO pelo cliente. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
    prazo_expirado:        'O PRAZO deste contrato EXPIROU sem todas as assinaturas. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
    cancelado:              'Este contrato foi CANCELADO. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
    erro_tecnico:            'Houve um ERRO TÉCNICO na integração com a Clicksign — ninguém recusou nada. Você ainda pode liberar a empresa, mas a liberação fica registrada com o seu nome e o motivo.',
};

/**
 * Admin/ContratoDetalhe.jsx — Fase 131 Planos 04 (D-01/D-03/D-11, ADM-01/02,
 * UI-02), 05 (CLICK-07/CLICK-09/CLICK-10, D-05/D-13/D-14, UI-04/UI-06) e 06
 * (D-10 — absorve a liberação manual da Fase 130).
 *
 * A tela de detalhe da empresa (D-01): página cheia (não painel lateral, não
 * edição inline) onde o Administrativo completa o que o Comercial deixou
 * pela metade (ADM-01/ADM-02), dispara a geração do contrato (UI-02) e — o
 * que os planos seguintes acrescentam — age sobre um contrato já enviado
 * exatamente como a API da Clicksign permite, nem mais, nem menos, e libera
 * manualmente uma empresa quando a rede de segurança da Fase 130 não
 * resolveu sozinha.
 *
 * ⛔ O que esta tela NÃO oferece, e por quê (medido em 2026-08-14,
 * `CLICKSIGN-SANDBOX-EMPIRICO.md` §15): não existe correção de e-mail de
 * quem assina (PATCH/PUT devolvem 404) e não existe cancelamento do que já
 * foi enviado pela API (DELETE→403, POST/cancel→404, PATCH status→400).
 * "Ajustar" abre direto a explicação (RAMO B, D-14) e
 * "Registrar cancelamento" REGISTRA autor+motivo+data e instrui a concluir
 * no painel — nunca promete cancelar de fato (D-13).
 */
export default function ContratoDetalhe({
    company,
    contratos_servico = [],
    faltantes = [],
    configuracao_ecf_faltante = [],
    pode_gerar_contrato = false,
    motivo_bloqueio = null,
    painel_clicksign_url = null,
    motivos_manuais = {},
    contratos = [],
}) {
    const { flash } = usePage().props;

    const cadastroForm = useForm({
        cnpj:               company.cnpj ?? '',
        email_cliente:      company.email_cliente ?? '',
        nome_contato:       company.nome_contato ?? '',
        // Quick 260819-guy — razão social/endereço são por EMPRESA.
        razao_social:       company.razao_social ?? '',
        // Quick 260821-cq0 — endereço volta a ser 5 campos separados: o
        // contrato de Gestão do jurídico precisa de rua, bairro, cidade,
        // estado e CEP como variáveis próprias.
        endereco:           company.endereco ?? '',
        bairro:             company.bairro ?? '',
        cidade:             company.cidade ?? '',
        estado:             company.estado ?? '',
        cep:                company.cep ?? '',
        contratos_servico:  contratos_servico.map((cs) => ({
            id:                     cs.id,
            data_contratacao:       cs.data_contratacao ?? '',
            data_vencimento:        cs.data_vencimento ?? '',
            // Quick 260819-guy — data da 1ª parcela e dia do mês do
            // vencimento das demais são por SERVIÇO.
            data_primeira_parcela:  cs.data_primeira_parcela ?? '',
            dia_vencimento:         cs.dia_vencimento ?? '',
        })),
    });

    const salvarCadastro = (e) => {
        e.preventDefault();
        cadastroForm.patch(route('admin.contratos.cadastro', company.id), { preserveScroll: true });
    };

    const atualizarDataServico = (id, campo, valor) => {
        cadastroForm.setData(
            'contratos_servico',
            cadastroForm.data.contratos_servico.map((cs) => (cs.id === id ? { ...cs, [campo]: valor } : cs)),
        );
    };

    const gerarForm = useForm({});
    const gerarContrato = () => {
        gerarForm.post(route('admin.contratos.gerar', company.id), { preserveScroll: true });
    };

    // Texto ao lado do botão desabilitado quando NÃO é falta de dado mínimo
    // (esses já aparecem na lista de `faltantes`) — sem jargão.
    const MOTIVO_BLOQUEIO_TEXTO = {
        ja_em_andamento:      'Já existe um contrato em andamento para um dos serviços desta empresa.',
        aguardando_comercial: 'Ainda há uma pendência comercial nesta empresa antes de gerar o contrato.',
        isento:               'Nenhum serviço desta empresa passa por contrato.',
        // Fase 132 Plano 01 (D-07) — este caso não usa MOTIVO_BLOQUEIO_TEXTO
        // no bloco de tela (tem bloco próprio, ver abaixo), mas a chave fica
        // aqui documentada por completude.
        emissao_congelada:    'A emissão de contratos está pausada no momento.',
    };

    // ─── CLICK-07 — Reenviar aviso ──────────────────────────────────────
    // "Por alguns segundos" após o clique — evita repetir o 429 em sequência
    // (UI-SPEC). Sem contagem regressiva exata exigida.
    const [signatarioReenviando, setSignatarioReenviando] = useState(null);
    const [signatariosRecemReenviados, setSignatariosRecemReenviados] = useState(() => new Set());

    function reenviarAviso(contratoId, signatarioId) {
        setSignatarioReenviando(signatarioId);
        router.post(route('admin.contratos.reenviar', [contratoId, signatarioId]), {}, {
            preserveScroll: true,
            onFinish: () => {
                setSignatarioReenviando(null);
                setSignatariosRecemReenviados((atual) => new Set(atual).add(signatarioId));
                setTimeout(() => {
                    setSignatariosRecemReenviados((atual) => {
                        const proximo = new Set(atual);
                        proximo.delete(signatarioId);
                        return proximo;
                    });
                }, 8000);
            },
        });
    }

    // ─── CLICK-09/UI-04 — Ajustar quem assina (RAMO B, D-14) ────────────
    // Não existe bifurcação: o botão "Ajustar" abre direto a explicação de
    // que não dá para só corrigir o e-mail — o CTA primário leva ao MESMO
    // fluxo de registrar cancelamento (D-06/D-07/D-14).
    const [ajustarContratoId, setAjustarContratoId] = useState(null);

    // ─── CLICK-10/D-13 — Registrar cancelamento (registra aqui, cancela no painel) ─
    const cancelarForm = useForm({ motivo: '' });
    const [cancelarContratoId, setCancelarContratoId] = useState(null);

    function abrirCancelamento(contratoId) {
        cancelarForm.reset();
        cancelarForm.clearErrors();
        setAjustarContratoId(null);
        setCancelarContratoId(contratoId);
    }

    function confirmarCancelamento(e) {
        e.preventDefault();
        cancelarForm.post(route('admin.contratos.cancelamento', cancelarContratoId), {
            preserveScroll: true,
            onSuccess: () => {
                setCancelarContratoId(null);
                // O rótulo promete registrar E ir para a Clicksign — só abre
                // a aba DEPOIS que a resposta volta, e só se a URL do painel
                // veio configurada. Rótulo sem destino é exatamente o que
                // esta fase recusou.
                if (painel_clicksign_url) {
                    window.open(painel_clicksign_url, '_blank', 'noopener');
                }
            },
        });
    }

    // ─── D-05 — Estado `erro`: assume a falha e oferece a saída ─────────
    // Quick 260819-guy (Tarefa 7 item 1) — "já tentou antes" e a mensagem de
    // erro agora vêm PRONTOS do backend (`c.ja_tentou_antes`/
    // `c.erro_mensagem`, calculados em `ContratoAdminController::show()`),
    // não mais de um `useState({})` local que zerava a cada reload. O que
    // continua local é só o toggle de abrir/fechar "Ver detalhes técnicos"
    // — estado de exibição, não de dado.
    const [detalhesTecnicosAbertos, setDetalhesTecnicosAbertos] = useState({});

    // ─── D-10 — Liberar manualmente (absorve a Fase 130, preserva D-11) ──
    // Reusa literalmente o texto já validado em
    // Admin/ContratosLiberacaoManual.jsx (tela removida por este plano).
    const liberarForm = useForm({
        company_id:              company.id,
        servico_id:              '',
        contrato_assinatura_id:  '',
        motivo_slug:             '',
        motivo_detalhe:          '',
    });
    const [liberarContratoId, setLiberarContratoId] = useState(null);

    function abrirLiberacao(contrato) {
        liberarForm.clearErrors();
        liberarForm.setData({
            company_id:             company.id,
            servico_id:             contrato.servico_id,
            contrato_assinatura_id: contrato.id,
            motivo_slug:            '',
            motivo_detalhe:         '',
        });
        setAjustarContratoId(null);
        setLiberarContratoId(contrato.id);
    }

    function confirmarLiberacao(e) {
        e.preventDefault();
        liberarForm.post(route('admin.contratos.liberacao-manual'), {
            preserveScroll: true,
            onSuccess: () => setLiberarContratoId(null),
        });
    }

    const contratoParaLiberar        = contratos.find((c) => c.id === liberarContratoId) ?? null;
    const mostrarDestaqueLiberacao   = contratoParaLiberar && CAUSAS_DE_DESTAQUE.includes(contratoParaLiberar.causa);
    const podeConfirmarLiberacao     = Boolean(liberarForm.data.motivo_slug)
        && liberarForm.data.motivo_detalhe.trim().length >= 5
        && !liberarForm.processing;

    return (
        <AppLayout title={`Adm · Contrato — ${company.name}`}>
            <main className="p-6">
                <div className="space-y-6 max-w-4xl">
                    {/* Cabeçalho */}
                    <div>
                        <Link
                            href={route('admin.contratos.index')}
                            className="inline-flex items-center gap-1 text-[12px] text-white/40 hover:text-white/70 mb-2"
                        >
                            <ArrowLeft size={12} /> Voltar para Contratos
                        </Link>
                        <h1 className="text-xl font-semibold font-display text-white flex items-center gap-2">
                            <Building2 size={20} className="text-ecf-yellow" />
                            {company.name}
                        </h1>
                    </div>

                    {flash?.success && (
                        <div className="rounded-xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3 text-[13px] text-emerald-300">
                            {flash.success}
                        </div>
                    )}
                    {flash?.error && (
                        <div className="rounded-xl border border-red-500/20 bg-red-500/10 px-4 py-3 text-[13px] text-red-300">
                            {flash.error}
                        </div>
                    )}
                    {/* CLICK-07 — 429 é resposta ESPERADA da Clicksign, nunca erro:
                        canal neutro/âmbar, nunca vermelho. */}
                    {flash?.aviso && (
                        <div className="rounded-xl border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-[13px] text-amber-300">
                            <p className="font-semibold">Aguarde um pouco antes de reenviar</p>
                            <p className="mt-0.5 text-amber-300/80">{flash.aviso}</p>
                        </div>
                    )}

                    {/* Ponto focal da tela (131-UI-SPEC.md): quando há pendência, o
                        bloco de "falta completar" + botão desabilitado, adjacentes
                        (D-03). Quando não há, o botão ativo sozinho — nunca os dois
                        ao mesmo tempo. */}
                    {!pode_gerar_contrato && motivo_bloqueio === 'emissao_congelada' ? (
                        // Fase 132 Plano 01 (D-07) — bloco PRÓPRIO: com a
                        // emissão congelada, ninguém gera contrato para
                        // empresa nenhuma. O bloco "Falta completar antes de
                        // gerar o contrato" seria falso aqui — não falta nada
                        // no cadastro desta empresa.
                        <Card>
                            <CardContent className="p-4 space-y-3">
                                <h2 className="text-white/85 text-[15px] font-semibold">A emissão de contratos está pausada</h2>
                                <p className="text-[13px] text-white/60">
                                    É temporário e proposital — vale para todas as empresas, não é problema desta
                                    empresa nem do que foi preenchido. Nada do que já foi preenchido se perde.
                                </p>
                                <p className="text-[13px] text-white/50">
                                    Fale com o time técnico e tente de novo quando for avisado.
                                </p>
                                <Button disabled className="opacity-40 cursor-not-allowed">
                                    Gerar contrato
                                </Button>
                            </CardContent>
                        </Card>
                    ) : !pode_gerar_contrato ? (
                        <Card>
                            <CardContent className="p-4 space-y-3">
                                <h2 className="text-white/85 text-[15px] font-semibold">Falta completar antes de gerar o contrato</h2>
                                {faltantes.length > 0 && (
                                    <ul className="space-y-1.5">
                                        {faltantes.map((item, idx) => (
                                            <li key={idx} className="text-[13px] text-white/60 flex items-center gap-2">
                                                <span className="h-1.5 w-1.5 rounded-full bg-white/30 shrink-0" />
                                                {item.rotulo}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {motivo_bloqueio && MOTIVO_BLOQUEIO_TEXTO[motivo_bloqueio] && (
                                    <p className="text-[13px] text-white/50">{MOTIVO_BLOQUEIO_TEXTO[motivo_bloqueio]}</p>
                                )}
                                <Button disabled className="opacity-40 cursor-not-allowed">
                                    Gerar contrato
                                </Button>
                            </CardContent>
                        </Card>
                    ) : (
                        <div>
                            <Button
                                type="button"
                                onClick={gerarContrato}
                                disabled={gerarForm.processing}
                                className="bg-ecf-yellow text-black hover:bg-ecf-yellow/90"
                            >
                                {gerarForm.processing ? 'Gerando…' : 'Gerar contrato'}
                            </Button>
                        </div>
                    )}

                    {/* Formulário de completar cadastro (ADM-01) */}
                    <Card>
                        <CardContent className="p-4">
                            <h2 className="text-white/85 text-[15px] font-semibold mb-3">Cadastro da empresa</h2>
                            <form onSubmit={salvarCadastro} className="space-y-4">
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>CNPJ</Label>
                                        {/* Mascara de CNPJ com IMaskInput — mesmo padrao ja usado em
                                            Companies/Index.jsx e Comercial/NovaEmpresa.jsx (Fase 34,
                                            D-08); nao inventar um segundo jeito de mascarar CNPJ.

                                            A mascara aqui e FRICCAO DE UX, nunca controle: quem valida
                                            o digito verificador e o servidor — `CnpjValido` no
                                            `atualizarCadastro()` e `Cnpj::valido()` na regra 2 de
                                            `ContratoDadosMinimosService::faltantes()`. Mesma disciplina
                                            do T-131-04-03 (o `disabled` do botao no client tambem nao e
                                            controle). Colar um CNPJ invalido continua sendo recusado.

                                            As classes replicam `Components/ui/input.jsx` porque
                                            IMaskInput renderiza um `<input>` cru, sem o wrapper do
                                            componente — sem isso o campo destoa do "E-mail do cliente"
                                            ao lado. */}
                                        <IMaskInput
                                            mask="00.000.000/0000-00"
                                            value={cadastroForm.data.cnpj ?? ''}
                                            onAccept={(value) => cadastroForm.setData('cnpj', value)}
                                            placeholder="00.000.000/0001-00"
                                            className={cn(
                                                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
                                                'focus:border-ecf-yellow/40'
                                            )}
                                        />
                                        {cadastroForm.errors.cnpj && <p className="text-red-400 text-[11px]">{cadastroForm.errors.cnpj}</p>}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>E-mail do cliente</Label>
                                        <Input
                                            type="email"
                                            value={cadastroForm.data.email_cliente}
                                            onChange={(e) => cadastroForm.setData('email_cliente', e.target.value)}
                                            className="focus:border-ecf-yellow/40"
                                        />
                                        {cadastroForm.errors.email_cliente && (
                                            <p className="text-red-400 text-[11px]">{cadastroForm.errors.email_cliente}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="space-y-1.5">
                                    <Label>Nome de quem assina pela empresa</Label>
                                    <Input
                                        value={cadastroForm.data.nome_contato}
                                        onChange={(e) => cadastroForm.setData('nome_contato', e.target.value)}
                                        placeholder="Nome completo — nome e sobrenome"
                                        className="focus:border-ecf-yellow/40"
                                    />
                                    {cadastroForm.errors.nome_contato && (
                                        <p className="text-red-400 text-[11px]">{cadastroForm.errors.nome_contato}</p>
                                    )}
                                </div>

                                <div className="space-y-1.5">
                                    <Label>Razão social</Label>
                                    <Input
                                        value={cadastroForm.data.razao_social}
                                        onChange={(e) => cadastroForm.setData('razao_social', e.target.value)}
                                        placeholder="Nome jurídico completo — pode ser diferente do nome usado no dia a dia"
                                        className="focus:border-ecf-yellow/40"
                                    />
                                    {cadastroForm.errors.razao_social && (
                                        <p className="text-red-400 text-[11px]">{cadastroForm.errors.razao_social}</p>
                                    )}
                                </div>

                                {/* Quick 260821-cq0 — endereço volta a ser 5 campos separados
                                    (rua, bairro, cidade, estado, CEP): o contrato de Gestão do
                                    jurídico monta "com sede {{endereco}}, {{bairro}},
                                    {{cidade}}/{{estado}} - CEP: {{cep}}" com cada pedaço numa
                                    variável própria. Sem jargão — nada de "logradouro" cru na
                                    tela. */}
                                <div className="space-y-1.5">
                                    <Label>Rua e número</Label>
                                    <Input
                                        value={cadastroForm.data.endereco}
                                        onChange={(e) => cadastroForm.setData('endereco', e.target.value)}
                                        placeholder="Rua das Indústrias, 500"
                                        className="focus:border-ecf-yellow/40"
                                    />
                                    {cadastroForm.errors.endereco && (
                                        <p className="text-red-400 text-[11px]">{cadastroForm.errors.endereco}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>Bairro</Label>
                                        <Input
                                            value={cadastroForm.data.bairro}
                                            onChange={(e) => cadastroForm.setData('bairro', e.target.value)}
                                            placeholder="Distrito Industrial"
                                            className="focus:border-ecf-yellow/40"
                                        />
                                        {cadastroForm.errors.bairro && (
                                            <p className="text-red-400 text-[11px]">{cadastroForm.errors.bairro}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>CEP</Label>
                                        <Input
                                            value={cadastroForm.data.cep}
                                            onChange={(e) => cadastroForm.setData('cep', e.target.value)}
                                            placeholder="89219-500"
                                            className="focus:border-ecf-yellow/40"
                                        />
                                        {cadastroForm.errors.cep && (
                                            <p className="text-red-400 text-[11px]">{cadastroForm.errors.cep}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-1.5">
                                        <Label>Cidade</Label>
                                        <Input
                                            value={cadastroForm.data.cidade}
                                            onChange={(e) => cadastroForm.setData('cidade', e.target.value)}
                                            placeholder="Joinville"
                                            className="focus:border-ecf-yellow/40"
                                        />
                                        {cadastroForm.errors.cidade && (
                                            <p className="text-red-400 text-[11px]">{cadastroForm.errors.cidade}</p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label>Estado</Label>
                                        <Input
                                            value={cadastroForm.data.estado}
                                            onChange={(e) => cadastroForm.setData('estado', e.target.value)}
                                            placeholder="SC"
                                            className="focus:border-ecf-yellow/40"
                                        />
                                        {cadastroForm.errors.estado && (
                                            <p className="text-red-400 text-[11px]">{cadastroForm.errors.estado}</p>
                                        )}
                                    </div>
                                </div>

                                {contratos_servico.length > 0 && (
                                    <div className="space-y-3 pt-3 border-t border-white/[0.06]">
                                        <p className="text-[11px] text-white/40 uppercase tracking-wide">Datas por serviço</p>
                                        {contratos_servico.map((cs) => {
                                            const item = cadastroForm.data.contratos_servico.find((x) => x.id === cs.id) ?? {
                                                data_contratacao: '',
                                                data_vencimento: '',
                                                data_primeira_parcela: '',
                                                dia_vencimento: '',
                                            };
                                            return (
                                                <div key={cs.id} className="space-y-2">
                                                    <div className="text-[13px] text-white/70">{cs.servico_nome}</div>
                                                    <div className="grid grid-cols-2 gap-3 items-end">
                                                        <div className="space-y-1.5">
                                                            <Label className="text-[11px]">Data de início</Label>
                                                            <Input
                                                                type="date"
                                                                value={item.data_contratacao}
                                                                onChange={(e) => atualizarDataServico(cs.id, 'data_contratacao', e.target.value)}
                                                                className="focus:border-ecf-yellow/40"
                                                            />
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label className="text-[11px]">Data de término</Label>
                                                            <Input
                                                                type="date"
                                                                value={item.data_vencimento}
                                                                onChange={(e) => atualizarDataServico(cs.id, 'data_vencimento', e.target.value)}
                                                                className="focus:border-ecf-yellow/40"
                                                            />
                                                        </div>
                                                    </div>
                                                    {/* Quick 260819-guy — data da 1ª parcela e dia do vencimento
                                                        das demais, por serviço, ao lado das datas de vigência. */}
                                                    <div className="grid grid-cols-2 gap-3 items-end">
                                                        <div className="space-y-1.5">
                                                            <Label className="text-[11px]">Data da 1ª parcela</Label>
                                                            <Input
                                                                type="date"
                                                                value={item.data_primeira_parcela}
                                                                onChange={(e) => atualizarDataServico(cs.id, 'data_primeira_parcela', e.target.value)}
                                                                className="focus:border-ecf-yellow/40"
                                                            />
                                                        </div>
                                                        <div className="space-y-1.5">
                                                            <Label className="text-[11px]">Dia do vencimento das demais (dia do mês, 1 a 31)</Label>
                                                            <Input
                                                                type="number"
                                                                min={1}
                                                                max={31}
                                                                value={item.dia_vencimento}
                                                                onChange={(e) => atualizarDataServico(cs.id, 'dia_vencimento', e.target.value)}
                                                                className="focus:border-ecf-yellow/40"
                                                            />
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}

                                <Button type="submit" variant="outline" disabled={cadastroForm.processing}>
                                    {cadastroForm.processing ? 'Salvando…' : 'Salvar cadastro'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Configuração interna da ECF — bloco SEPARADO, nunca misturado
                        com a lista de pendências da empresa acima (.env, não dado
                        da empresa). */}
                    {configuracao_ecf_faltante.length > 0 && (
                        <Card className="border-rose-500/20">
                            <CardContent className="p-4 space-y-2">
                                <div className="flex items-center gap-2">
                                    <AlertTriangle size={14} className="text-rose-400" />
                                    <h2 className="text-rose-300 text-[15px] font-semibold">Configuração interna da ECF pendente</h2>
                                </div>
                                <p className="text-[12px] text-white/50">
                                    Isto não é responsabilidade do cliente — um administrador precisa resolver.
                                </p>
                                <ul className="space-y-1">
                                    {configuracao_ecf_faltante.map((item, idx) => (
                                        <li key={idx} className="text-[13px] text-white/60">{item}</li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    )}

                    {/* Lista dos contratos desta empresa. */}
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Serviço</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Situação</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Parado há</TableHead>
                                        <TableHead className="text-[11px] uppercase tracking-wide">Ações</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {contratos.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-center py-8 text-[13px] text-white/40">
                                                Nenhum contrato administrativo registrado ainda para esta empresa.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {contratos.map((c) => {
                                        const podeAgir = c.status === 'aguardando_assinaturas';
                                        const podeCancelar = (c.status === 'rascunho' || c.status === 'aguardando_assinaturas')
                                            && !c.cancelamento_solicitado_em;
                                        const signatariosPendentes = podeAgir
                                            ? (c.signatarios || []).filter((s) => s.situacao === 'pendente')
                                            : [];
                                        // Quick 260819-guy (Tarefa 7 item 1) — vem pronto do backend.
                                        const jaTentouAntes = Boolean(c.ja_tentou_antes);
                                        // Quick 260819-guy (Tarefa 7 item 3) — contrato pronto, mas
                                        // ainda não enviado pelo painel da Clicksign (D-02, não é falha).
                                        //
                                        // Quick 260820-my3 (Tarefa 2) — precisa excluir `montagem_travada`
                                        // também, não só `preparando`: sem isto, um rascunho com envelope
                                        // NULO que já passou da janela (preparando=false, travado=true)
                                        // caía aqui e mostrava "já foi montado, falta enviar" — exatamente
                                        // o bug do incidente de produção, só que nesta tela em vez da lista.
                                        const faltaEnviarPelaClicksign = c.status === 'rascunho' && !c.preparando && !c.montagem_travada;

                                        return (
                                            <Fragment key={c.id}>
                                                <TableRow>
                                                    <TableCell className="text-[13px] text-white/85 align-top">{c.servico_nome}</TableCell>
                                                    <TableCell className="align-top">
                                                        <span className={cn('inline-flex items-center text-[10px] font-semibold px-1.5 py-0.5 rounded-full border', classeContratoComPreparo(c.status, c.preparando, c.montagem_travada))}>
                                                            {rotuloContratoComPreparo(c.status, c.preparando, c.montagem_travada)}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell className="text-[13px] text-white/50 align-top">{formatarHaDias(c.dias_parado)}</TableCell>
                                                    <TableCell className="align-top">
                                                        <div className="space-y-2 min-w-[220px]">
                                                            {signatariosPendentes.map((s) => {
                                                                const reenviando = signatarioReenviando === s.id;
                                                                const recemReenviado = signatariosRecemReenviados.has(s.id);
                                                                return (
                                                                    <div key={s.id} className="flex items-center justify-between gap-2 text-[12px]">
                                                                        <span className="text-white/60 truncate">{s.nome}</span>
                                                                        <div className="flex items-center gap-1 shrink-0">
                                                                            <button
                                                                                type="button"
                                                                                title="Reenviar aviso"
                                                                                disabled={reenviando || recemReenviado}
                                                                                onClick={() => reenviarAviso(c.id, s.id)}
                                                                                className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-white/50 hover:text-white/80 hover:bg-white/[0.06] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                                                            >
                                                                                <Send size={11} />
                                                                                {reenviando ? 'Reenviando…' : 'Reenviar aviso'}
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                title="Ajustar"
                                                                                onClick={() => setAjustarContratoId(c.id)}
                                                                                className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-white/50 hover:text-white/80 hover:bg-white/[0.06] transition-colors"
                                                                            >
                                                                                <UserCog size={11} />
                                                                                Ajustar
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                );
                                                            })}
                                                            {podeCancelar && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => abrirCancelamento(c.id)}
                                                                    className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-red-400/70 hover:text-red-300 hover:bg-red-500/[0.08] text-[12px] transition-colors"
                                                                >
                                                                    <Ban size={11} />
                                                                    Registrar cancelamento
                                                                </button>
                                                            )}
                                                            {c.status === 'erro' && (
                                                                <button
                                                                    type="button"
                                                                    disabled={gerarForm.processing}
                                                                    onClick={gerarContrato}
                                                                    className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-ecf-yellow/80 hover:text-ecf-yellow hover:bg-ecf-yellow/[0.06] text-[12px] disabled:opacity-40 transition-colors"
                                                                >
                                                                    <RefreshCcw size={11} />
                                                                    {gerarForm.processing ? 'Gerando…' : 'Tentar novamente'}
                                                                </button>
                                                            )}
                                                            {/* D-10 — sempre disponível quando ainda não há liberação
                                                                registrada, qualquer que seja o status do contrato. */}
                                                            {!c.ja_liberado && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => abrirLiberacao(c)}
                                                                    className="inline-flex items-center gap-1 px-1.5 py-1 rounded-md text-ecf-yellow/70 hover:text-ecf-yellow hover:bg-ecf-yellow/[0.08] text-[12px] transition-colors"
                                                                >
                                                                    <Unlock size={11} />
                                                                    Liberar manualmente
                                                                </button>
                                                            )}
                                                            {signatariosPendentes.length === 0 && !podeCancelar && c.status !== 'erro' && c.ja_liberado && (
                                                                <span className="text-[13px] text-white/20">—</span>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>

                                                {/* Quick 260816-d72 (UI-06/D-05) — aviso de ESPERA, não erro: mesma
                                                    família do aviso de 429 do reenvio (CLICKSIGN-SANDBOX-EMPIRICO.md
                                                    §14/§16). Contrato acabou de ser pedido e pode estar na fila do
                                                    bucket de 1 envelope/min; some sozinho quando `c.preparando`
                                                    voltar a `false` (janela de 5min em
                                                    ContratoAssinatura::estaPreparando()) ou quando o job terminar. */}
                                                {c.preparando && (
                                                    <TableRow key={`${c.id}-preparando`}>
                                                        <TableCell colSpan={4} className="py-2 border-t-0">
                                                            <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3 py-2 text-[12px] text-amber-300/90">
                                                                {PREPARANDO_AVISO}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}

                                                {/* Quick 260820-my3 (Tarefa 2) — irmão do aviso de "preparando"
                                                    acima, mesma família visual mas laranja (mais alarmante):
                                                    a mesma condição PASSOU da janela sem terminar. Incidente
                                                    real (2026-08-20): contrato pedido, nada criado, e a tela
                                                    antiga dizia "Falta enviar", mandando procurar na Clicksign
                                                    onde não havia nada. Copy sem jargão — diz que foi pedido,
                                                    não ficou pronto, não adianta procurar na Clicksign, e o
                                                    time técnico precisa olhar. */}
                                                {c.montagem_travada && (
                                                    <TableRow key={`${c.id}-montagem-travada`}>
                                                        <TableCell colSpan={4} className="py-2 border-t-0">
                                                            <div className="rounded-lg border border-orange-500/20 bg-orange-500/[0.06] px-3 py-2 text-[12px] text-orange-300/90">
                                                                {MONTAGEM_TRAVADA_AVISO}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}

                                                {/* Quick 260819-guy (Tarefa 7 item 3) — o sistema para no rascunho
                                                    DE PROPÓSITO (D-02 da Fase 127-05,
                                                    `GerarContratoAssinaturaJob`, "a ativação acontece FORA do
                                                    sistema"), mas nada na tela dizia isso nem linkava para o
                                                    painel — o único link para a Clicksign era o do fluxo de
                                                    cancelamento. Âmbar (ação pendente), nunca vermelho — isto
                                                    não é uma falha. */}
                                                {faltaEnviarPelaClicksign && (
                                                    <TableRow key={`${c.id}-falta-enviar`}>
                                                        <TableCell colSpan={4} className="py-2 border-t-0">
                                                            <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3 py-2 text-[12px] text-amber-300/90">
                                                                Este contrato já foi montado e está pronto — falta enviar para o cliente
                                                                assinar, pelo painel da Clicksign.
                                                                {painel_clicksign_url && (
                                                                    <>
                                                                        {' '}
                                                                        <a
                                                                            href={painel_clicksign_url}
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            className="underline hover:text-amber-200"
                                                                        >
                                                                            Abrir o painel da Clicksign
                                                                        </a>
                                                                    </>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}

                                                {/* Aviso persistente de cancelamento solicitado (D-13) — âmbar,
                                                    "aguardando algo externo", nunca vermelho de erro. Some
                                                    sozinho quando a confirmação da Clicksign fechar o estado. */}
                                                {c.cancelamento_solicitado_em && (
                                                    <TableRow key={`${c.id}-cancelamento-solicitado`}>
                                                        <TableCell colSpan={4} className="py-2 border-t-0">
                                                            <div className="rounded-lg border border-amber-500/20 bg-amber-500/[0.06] px-3 py-2 text-[12px] text-amber-300/90">
                                                                Cancelamento pedido por {c.cancelamento_solicitado_por_nome || 'alguém do time'} em{' '}
                                                                {formatDate(c.cancelamento_solicitado_em)} — ainda não concluído no painel da Clicksign.
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}

                                                {/* D-05 — Estado `erro`: assume a falha e oferece a saída.
                                                    Quick 260819-guy (Tarefa 7 item 1) — "já tentou antes" vem
                                                    PRONTO do backend (não mais de um contador de sessão que
                                                    zerava a cada reload), e o motivo real do erro
                                                    (`c.erro_mensagem`, já podado de PII antes de gravar) fica
                                                    disponível SEMPRE, não só depois da 2ª falha. */}
                                                {c.status === 'erro' && (
                                                    <TableRow key={`${c.id}-erro`}>
                                                        <TableCell colSpan={4} className="py-2 border-t-0">
                                                            <div className="rounded-lg border border-rose-500/20 bg-rose-500/[0.05] px-3 py-2.5 text-[12px] space-y-1.5">
                                                                {!jaTentouAntes ? (
                                                                    <>
                                                                        <p className="text-rose-300 font-semibold">Não deu para enviar este contrato</p>
                                                                        <p className="text-white/50">
                                                                            O problema foi aqui do nosso lado, não com o cliente. Tente novamente — na
                                                                            maioria das vezes resolve.
                                                                        </p>
                                                                    </>
                                                                ) : (
                                                                    <>
                                                                        <p className="text-rose-300 font-semibold">Continua sem enviar</p>
                                                                        <p className="text-white/50">
                                                                            Tentamos de novo e não deu certo. Avise o time técnico para verificar — pode
                                                                            levar um tempinho para resolver.
                                                                        </p>
                                                                    </>
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => setDetalhesTecnicosAbertos((atual) => ({ ...atual, [c.id]: !atual[c.id] }))}
                                                                    className="inline-flex items-center gap-1 text-white/40 hover:text-white/70"
                                                                >
                                                                    {detalhesTecnicosAbertos[c.id] ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
                                                                    Ver detalhes técnicos
                                                                </button>
                                                                {detalhesTecnicosAbertos[c.id] && (
                                                                    <p className="text-white/35 font-mono text-[11px] break-words">
                                                                        {c.erro_mensagem || 'Sem detalhes técnicos registrados para esta tentativa.'}
                                                                    </p>
                                                                )}
                                                            </div>
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </main>

            {/* RAMO B (D-06/D-07/D-14) — não existe bifurcação: corrigir o
                e-mail e trocar a pessoa colapsam no mesmo caminho. */}
            <Dialog open={ajustarContratoId !== null} onOpenChange={(v) => { if (!v) setAjustarContratoId(null); }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Não dá para só corrigir o e-mail</DialogTitle>
                    </DialogHeader>
                    <p className="text-[13px] text-white/60">
                        Depois que o contrato é enviado, não é possível trocar o e-mail de quem assina. Se
                        foi só um erro de digitação, cancele este contrato e gere um novo com o e-mail
                        certo.
                    </p>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setAjustarContratoId(null)}>
                            Voltar
                        </Button>
                        <Button
                            type="button"
                            className="bg-red-600 text-white hover:bg-red-600/90"
                            onClick={() => abrirCancelamento(ajustarContratoId)}
                        >
                            Registrar cancelamento
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* CLICK-10/D-13 — registra aqui, cancela no painel. */}
            <Dialog open={cancelarContratoId !== null} onOpenChange={(v) => { if (!v) setCancelarContratoId(null); }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Registrar cancelamento deste contrato</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={confirmarCancelamento} className="space-y-3">
                        <p className="text-[13px] text-white/60">
                            O cancelamento em si precisa ser feito no painel da Clicksign — o sistema não
                            consegue cancelar sozinho. Aqui você registra quem pediu e por quê, para ficar
                            no histórico.
                        </p>
                        <div className="space-y-1.5">
                            <Label>Motivo do cancelamento</Label>
                            <Textarea
                                rows={4}
                                placeholder="Explique por que este contrato está sendo cancelado."
                                value={cancelarForm.data.motivo}
                                onChange={(e) => cancelarForm.setData('motivo', e.target.value)}
                            />
                            {cancelarForm.errors.motivo && (
                                <p className="text-red-400 text-[11px]">{cancelarForm.errors.motivo}</p>
                            )}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setCancelarContratoId(null)}>
                                Voltar
                            </Button>
                            <Button
                                type="submit"
                                disabled={cancelarForm.processing}
                                className="bg-red-600 text-white hover:bg-red-600/90"
                            >
                                {cancelarForm.processing
                                    ? 'Registrando…'
                                    : (painel_clicksign_url ? 'Registrar e ir para a Clicksign' : 'Registrar cancelamento')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* D-10 — Liberar manualmente (absorve a Fase 130, preserva D-11). */}
            <Dialog open={liberarContratoId !== null} onOpenChange={(v) => { if (!v) setLiberarContratoId(null); }}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Liberar manualmente</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={confirmarLiberacao} className="space-y-3">
                        {/* D-11 — faixa de destaque obrigatória antes do botão de confirmar. */}
                        {mostrarDestaqueLiberacao && (
                            <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg border border-red-500/30 bg-red-500/[0.06] text-red-300 text-[13px] font-semibold">
                                <AlertTriangle size={16} className="shrink-0 mt-0.5" />
                                <span>{CAUSA_DESTAQUE_TEXTO[contratoParaLiberar.causa]}</span>
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label>Motivo</Label>
                            <select
                                value={liberarForm.data.motivo_slug}
                                onChange={(e) => liberarForm.setData('motivo_slug', e.target.value)}
                                className="w-full h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 transition-colors"
                            >
                                <option value="">Selecione um motivo...</option>
                                {Object.entries(motivos_manuais || {}).map(([slug, rotulo]) => (
                                    <option key={slug} value={slug}>{rotulo}</option>
                                ))}
                            </select>
                            {liberarForm.errors.motivo_slug && (
                                <p className="text-red-400 text-[11px]">{liberarForm.errors.motivo_slug}</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Detalhe (obrigatório)</Label>
                            <Textarea
                                rows={3}
                                placeholder="Explique o que aconteceu, mesmo que seja um resumo curto."
                                value={liberarForm.data.motivo_detalhe}
                                onChange={(e) => liberarForm.setData('motivo_detalhe', e.target.value)}
                            />
                            {liberarForm.errors.motivo_detalhe && (
                                <p className="text-red-400 text-[11px]">{liberarForm.errors.motivo_detalhe}</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setLiberarContratoId(null)}>
                                Voltar
                            </Button>
                            <Button
                                type="submit"
                                disabled={!podeConfirmarLiberacao}
                                className="bg-ecf-yellow text-black hover:bg-ecf-yellow/90"
                            >
                                {liberarForm.processing ? 'Liberando…' : 'Confirmar liberação'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
