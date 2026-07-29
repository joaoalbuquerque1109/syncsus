# Cadastros assistenciais

Os cadastros ampliados ficam disponíveis no menu **Administração** para usuários com a permissão
`administration.manage`.

## Profissionais de saúde

O perfil profissional é separado da conta de acesso. Assim, um profissional pode existir sem
login, e uma conta pode ser vinculada posteriormente. O cadastro mantém:

- código institucional, categoria, nome civil/social e CPF;
- documento de identidade, contatos, endereço, código IBGE e CNES;
- um ou mais conselhos profissionais, com número, UF, emissão e validade;
- especialidades, especialidade principal, RQE e data do registro;
- unidades de saúde em que o profissional está autorizado.

O registro principal e o RQE são usados na identificação do atendimento e na assinatura dos PDFs
clínicos. O acesso ao sistema continua sendo controlado pelos papéis e unidades da conta vinculada.

## Pacientes e histórico clínico

O cadastro administrativo aceita CPF, CNS, RG e passaporte, dados demográficos, naturalidade e
código IBGE, até quatro telefones, responsável legal e responsável financeiro. Observações
administrativas não devem conter dados clínicos.

A ficha do paciente possui um resumo longitudinal próprio para:

- alergias e reações;
- condições de saúde e CID/CIAP informado;
- medicamentos de uso contínuo;
- tabagismo, uso de álcool e outras substâncias.

Somente administrador, profissional de triagem e médico recebem
`patients.clinical_history`. Cada alteração clínica gera evento de auditoria.

## Usuários, unidades e catálogos

Em **Usuários e acessos**, o administrador define papéis, unidades autorizadas, unidade padrão,
estado da conta e uma senha temporária com troca obrigatória. Em **Cadastros**, mantém unidades,
especialidades, formas de chegada e tipos de entrada sem editar diretamente o banco.

Para atualizar uma instalação SQLite existente:

```bash
php artisan migrate --force
php artisan db:seed --force
```

O segundo comando é idempotente para os dados fictícios e não duplica atendimentos.
