# Demonstração local com SQLite

Este modo existe para visualizar o SYNC SUS sem instalar MySQL. Ele só pode ser executado quando
`APP_ENV` não é `production`, usa dados inteiramente sintéticos e não deve receber dados reais.

## Ambiente já preparado

O workspace local está configurado com:

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=sqlite
SYNC_SUS_SEED_DEMO=true
SYNC_SUS_REQUIRE_HTTPS=false
```

O banco está em `database/database.sqlite`. Para iniciar:

```bash
php artisan serve
```

Acesse `http://127.0.0.1:8000`.

## Credenciais

Todos os usuários demonstrativos usam a senha `Demo#SyncSUS2026`.
O administrador global usa o código `ADMIN`. Os demais usuários usam o código da organização
`URGENCIA-CENTRAL`.

| Perfil | E-mail | O que permite visualizar |
|---|---|---|
| Administrador | `admin@syncsus.local` | Todos os módulos |
| Recepção | `recepcao@syncsus.local` | Pacientes, recepção e filas |
| Triagem | `triagem@syncsus.local` | Fila e classificação de risco |
| Médico | `medico@syncsus.local` | Atendimento e documentos |
| Gestor | `gestor@syncsus.local` | Dashboard e relatórios |
| Auditor | `auditoria@syncsus.local` | Relatórios e trilha de auditoria |

Essas credenciais são exclusivas do ambiente local. Antes de qualquer implantação real, desative o
modo de demonstração e crie credenciais próprias.

## Dados disponíveis

O seeder cria:

- 6 usuários, um para cada perfil operacional;
- 10 pacientes sintéticos, com contatos, endereços e alguns antecedentes;
- 8 atendimentos cobrindo espera da triagem, triagem em andamento, espera médica, consulta em
  andamento, observação, alta e transferência;
- senhas nas filas, chamadas recentes e conteúdo para o painel público;
- classificações de risco verde, amarelo, laranja e vermelho;
- sinais vitais, avaliações de triagem, consultas, diagnósticos e uma prescrição ilustrativa;
- uma orientação de alta em PDF, com versão, hash e código de verificação;
- acessos ao prontuário e eventos para a trilha de auditoria;
- dados para os indicadores e relatórios do plantão.

## Recriar o banco

O comando abaixo apaga somente o banco configurado no ambiente atual e o recria:

```bash
php artisan migrate:fresh --seed
```

Use-o apenas no ambiente local de demonstração. O seeder também pode ser executado novamente sem
duplicar os cenários:

```bash
php artisan db:seed
```

Para voltar a um ambiente sem dados fictícios, defina `SYNC_SUS_SEED_DEMO=false`, limpe a
configuração e faça as migrações com o seeder administrativo apropriado.
