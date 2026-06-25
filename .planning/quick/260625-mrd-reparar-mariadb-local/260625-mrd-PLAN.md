---
phase: quick-260625-mrd
plan: 01
type: docs
wave: 1
depends_on: []
files_modified: []
autonomous: false
requirements: [quick-260625-mrd]

must_haves:
  truths:
    - "MariaDB local do XAMPP sobe e fica verde no XAMPP Control Panel"
    - "Após recovery, `php artisan migrate:status` lista migrations sem erro"
    - "Após recovery, `php artisan sugadores:ml-smoke --company=<id_bymobille> --days=30` roda e gera fixture JSON em storage/app/sugadores/ml-smoke/"
    - "Phase 38 reabre Plan 38-02 para `complete` com SUMMARY atualizado incluindo output real do smoke"
  artifacts: []
---

# Recovery do MariaDB local após corrupção do `aria_log_control`

**Quando:** 2026-06-25 durante Phase 38 (smoke ML)
**O que aconteceu:** durante tentativa de rodar smoke real contra a API ML usando o token do Bymobille, o MariaDB local entrou em loop de crash com "Bad file descriptor" no `aria_log_control` (típico handle órfão do Windows Defender). Tentativa de fix (renomear `aria_log_control` → `.bak` para forçar Aria a recriar) acabou corrompendo o catálogo de sistema (`mysql.db`): "Incorrect file format 'db'". Restauração do `.bak` + reboot não resolveram a corrupção em disco.

**Estado atual:**
- MariaDB local NÃO sobe
- Erro no Event Viewer: `Fatal error: Can't open and lock privilege tables: Incorrect file format 'db'`
- Dados em `ecf_admin` (InnoDB) **estão intactos** — corrupção é só nas system tables do MariaDB (Aria engine)
- Apache funciona normalmente
- Phase 38 Plan 38-02 fechado como `partially_complete` — código + 8 tests Http::fake verdes; smoke real adiado para depois deste recovery

## 3 caminhos de recovery (do mais simples ao mais agressivo)

### Opção 1 — `aria_chk --safe-recover` na system db (~5 min, médio risco)

MariaDB tem um utilitário de reparo para tabelas Aria. Tentar reparar a tabela `mysql.db` específica:

1. **Parar XAMPP MySQL** (Stop no Control Panel — pode já estar parado)

2. Rodar reparo:
   ```cmd
   cd C:\xampp\mysql\bin
   aria_chk.exe --safe-recover C:\xampp\mysql\data\mysql\db.MAI
   aria_chk.exe --safe-recover C:\xampp\mysql\data\mysql\db.MAD
   ```

3. Se reportar fix bem-sucedido, tentar reparar TODAS as Aria tables do system db:
   ```cmd
   for %f in (C:\xampp\mysql\data\mysql\*.MAI) do aria_chk.exe --safe-recover "%f"
   ```

4. Start MySQL no XAMPP Control Panel — checar se fica verde

Se sair verde, **pular pra seção "Verificar recovery"** abaixo.

### Opção 2 — Restore do diretório `mysql/` de um backup (~5 min, baixo risco se tem backup)

Se você tem qualquer backup de `C:\xampp\mysql\data\mysql\` (do git do XAMPP, de um install limpo do XAMPP em outra máquina, ou de um snapshot do Windows):

1. Parar MySQL
2. Renomear `C:\xampp\mysql\data\mysql` para `mysql_quebrado`
3. Restaurar o backup como `C:\xampp\mysql\data\mysql`
4. Start MySQL

Suas tabelas de aplicação (ecf_admin) continuam intactas — só os usuários/permissões do MariaDB voltam ao estado do backup. Provavelmente vai aceitar `root` sem senha (default XAMPP).

### Opção 3 — Reinstalar XAMPP preservando o `data/` (~15 min, baixo risco)

1. **Backup primeiro:** copia `C:\xampp\mysql\data\ecf_admin\` para fora (essa é a parte importante)
2. Baixa o instalador do XAMPP mesma versão (https://www.apachefriends.org/download.html)
3. Para Apache + MySQL no XAMPP Control Panel
4. Roda o instalador por cima — escolhe sobrescrever XAMPP, **NÃO** sobrescrever pasta `data/`
5. O instalador traz `data/mysql/` (system db) limpa
6. Start MySQL — deve subir

## Verificar recovery

Quando o MySQL ficar verde no XAMPP Control Panel:

```cmd
cd C:\xampp\htdocs\ecf_admin\ecf_admin

# 1. Confirmar que Laravel se conecta:
C:\xampp\php\php.exe artisan migrate:status

# 2. Resolver company_id do Bymobille:
C:\xampp\php\php.exe artisan tinker --execute="echo \App\Models\Company::where('name','like','%ymobi%')->whereHas('mlToken')->get(['id','name'])->toJson();"

# 3. Rodar o smoke real (substitui <ID> pelo que apareceu acima):
C:\xampp\php\php.exe artisan sugadores:ml-smoke --company=<ID> --days=30

# 4. Sanity check segurança (deve retornar 0):
findstr /i "access_token" storage\app\sugadores\ml-smoke\<ID>-*.json
```

## Após o smoke real rodar

1. Cola o output do smoke + caminho da fixture pra mim na próxima sessão
2. Eu reabro Plan 38-02, atualizo o SUMMARY com o output real, faço commit `docs(38-02): smoke real Bymobille verde — fecha plan`, marco `complete`
3. Phase 38 fica `complete` formalmente, Phase 39 desbloqueada
4. Esta quick task fecha com `complete` referenciando o commit acima

## Notas

- **Não tente** renomear `aria_log_control` de novo se der "Bad file descriptor" — foi isso que causou a corrupção desta vez
- A causa do "Bad file descriptor" original foi Windows Defender mantendo handle no arquivo durante o write do mysqld; **adicionar exclusão de `C:\xampp\mysql\data\` no Defender** previne reincidência
- Se Opção 1 (`aria_chk`) não resolver, vá direto para Opção 3 (reinstalar XAMPP) — é mais limpo do que ficar caçando arquivos corrompidos um a um
- Como infra dev, isto é uma tarefa de plataforma — não exige decisões de produto/arquitetura; pode delegar pra quem cuida do ambiente
