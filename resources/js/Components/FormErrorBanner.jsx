// Banner de erros visível no topo de formulários — surface global pra erros
// de validação que ficariam escondidos quando o campo problemático não está
// renderizado (ex: erro em `usuario_id` quando o público escolhido é "todos").
//
// Uso: passar o objeto `errors` do `useForm` do Inertia. Se houver qualquer
// erro, renderiza uma lista clicável. Sumiço silencioso quando errors = {}.
import { AlertCircle } from 'lucide-react';

const FRIENDLY_LABELS = {
    name:                   'Nome',
    email:                  'E-mail',
    password:               'Senha',
    password_confirmation:  'Confirmação de senha',
    phone:                  'Telefone',
    titulo:                 'Título',
    mensagem:               'Mensagem',
    publico:                'Público',
    usuario_id:             'Usuário',
    setor_id:               'Setor',
    is_admin:               'Permissão admin',
    active:                 'Status ativo',
    vinculos:               'Vínculos (setor/cargo)',
};

const FRIENDLY_MESSAGES = {
    'validation.confirmed': 'A senha e a confirmação não coincidem.',
};

function friendlyLabel(key) {
    // Suporta keys nested do Laravel (ex: "vinculos.0.setor_id")
    const base = key.split('.')[0];
    return FRIENDLY_LABELS[base] || key;
}

function friendlyMessage(msg) {
    return FRIENDLY_MESSAGES[msg] || msg;
}

export default function FormErrorBanner({ errors }) {
    const entries = Object.entries(errors || {}).filter(([, msg]) => Boolean(msg));
    if (entries.length === 0) return null;

    return (
        <div className="mb-4 rounded border border-red-500/30 bg-red-500/10 p-3">
            <div className="flex items-start gap-2">
                <AlertCircle size={16} className="text-red-400 shrink-0 mt-0.5" />
                <div className="flex-1 min-w-0">
                    <p className="text-xs font-bold text-red-300 mb-1">
                        {entries.length === 1
                            ? 'Não foi possível salvar — corrija o erro abaixo:'
                            : `Não foi possível salvar — corrija os ${entries.length} erros abaixo:`}
                    </p>
                    <ul className="space-y-0.5">
                        {entries.map(([key, msg]) => (
                            <li key={key} className="text-[11px] text-red-300/90">
                                <span className="font-semibold">{friendlyLabel(key)}:</span>{' '}
                                {friendlyMessage(msg)}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
}
