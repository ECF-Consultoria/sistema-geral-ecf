// Hook PreToolUse (Bash) — aviso de coordenação Publicador (Fase 164) × Creative Engine (v24.0).
//
// Antes de um deploy (deploy*.sh) ou de um push para a main, pede confirmação mostrando o aviso
// que o ECF Dev deixou no CLAUDE.md. Fica mudo:
//   - quando o comando não é deploy nem push para a main;
//   - quando o marcador AVISO-COORDENACAO-164 já saiu do CLAUDE.md (o aviso foi combinado e apagado);
//   - para o próprio autor do aviso (git user.name "ECF Dev");
//   - em qualquer erro interno — um aviso nunca pode travar o trabalho de ninguém.
import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { join } from 'node:path';

const MARCADOR = 'AVISO-COORDENACAO-164';
const AUTOR = 'ECF Dev';

try {
    const entrada = JSON.parse(readFileSync(0, 'utf8') || '{}');
    const comando = String(entrada?.tool_input?.command ?? '');

    const ehDeploy = /\bdeploy(_parcial|_run)?\.sh\b/.test(comando);
    const ehPushMain = /\bgit\s+push\b/.test(comando) && /\bmain\b/.test(comando);
    if (!ehDeploy && !ehPushMain) process.exit(0);

    const raiz = process.env.CLAUDE_PROJECT_DIR || entrada?.cwd || process.cwd();
    const claudeMd = readFileSync(join(raiz, 'CLAUDE.md'), 'utf8');
    if (!claudeMd.includes(MARCADOR)) process.exit(0);

    const quem = execSync('git config user.name', { cwd: raiz, encoding: 'utf8' }).trim();
    if (quem === AUTOR) process.exit(0);

    const aviso = [
        'Aviso do ECF Dev (03/10) antes do deploy/push — Publicador × Creative Engine:',
        '• /mlb/anuncios agora é o Publicador interno (Fase 164, já em produção). A aba "Individual" abre o Publicador;',
        '  o assistente antigo (AnunciarML) e o painel "Criativos por IA" continuam, mas fora da entrada.',
        '• Próximo passo do ECF Dev (Fase 165): levar os criativos para o card de Fotos do Publicador, só ACRESCENTANDO',
        '  (pub_rascunho_id anulável nas tabelas de criativo, 2º caminho no CreativeContextBuilder, endpoints novos).',
        '• Antes de mexer em CreativeContextBuilder/ProductTruthBuilder/CreativePermissao, combine a ordem com o ECF Dev.',
        '• Depois do deploy.sh: `php artisan queue:restart` (o deploy.sh só reinicia ecf-worker:*; high e creative ficam velhos).',
        'Texto completo no CLAUDE.md, seção "Aviso de coordenação". Combinado? Apague o bloco do CLAUDE.md e este aviso some.',
    ].join('\n');

    process.stdout.write(JSON.stringify({
        systemMessage: aviso,
        hookSpecificOutput: {
            hookEventName: 'PreToolUse',
            permissionDecision: 'ask',
            permissionDecisionReason: aviso,
        },
    }));
    process.exit(0);
} catch {
    process.exit(0);
}
