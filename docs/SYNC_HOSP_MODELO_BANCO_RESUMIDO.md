# Sync Hosp — Proposta Resumida de Arquitetura de Banco de Dados

**Banco:** MySQL 8+  
**Objetivo:** discutir a organização do banco do Sync Hosp antes da implementação.

---

# 1. Arquitetura proposta

A proposta é dividir os dados em:

```text
sync_hosp_core
    |
    |-- dados gerais compartilháveis
    |-- usuários
    |-- unidades
    |-- médicos/profissionais
    |-- vínculos com unidades
    |-- identidade geral de pacientes
    |
    +-----------------------------+
                                  |
                         bancos por unidade
                                  |
                  +---------------+---------------+
                  |               |               |
                  v               v               v
          sync_hosp_u001  sync_hosp_u002  sync_hosp_u003
```

A regra principal é:

> **Cadastros gerais ficam no Core. Dados clínicos e operacionais ficam no banco exclusivo de cada unidade.**

Assim:

- um médico pode atuar em várias unidades;
- um paciente pode ser atendido em várias unidades;
- cada unidade possui seu próprio prontuário e histórico;
- uma unidade não consulta automaticamente os dados clínicos de outra.

---

# 2. Banco Central — `sync_hosp_core`

O banco central deve armazenar apenas informações gerais.

## 2.1 `organizations`

Representa a organização ou rede responsável pelas unidades.

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
legal_name          VARCHAR(255)
trade_name          VARCHAR(255) NULL
cnpj                VARCHAR(20) NULL
status              VARCHAR(30)
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 2.2 `health_units`

Cadastro das unidades/hospitais.

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
organization_id     BIGINT UNSIGNED FK
code                VARCHAR(50)
name                VARCHAR(255)
cnes_code           VARCHAR(30) NULL
unit_type           VARCHAR(50) NULL
city                VARCHAR(120) NULL
state               CHAR(2) NULL
status              VARCHAR(30)
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

Relacionamento:

```text
organizations 1 ---- N health_units
```

---

## 2.3 `tenant_databases`

Indica qual banco MySQL pertence a cada unidade.

```text
id                  BIGINT UNSIGNED PK
health_unit_id      BIGINT UNSIGNED UNIQUE
database_name       VARCHAR(120)
host                VARCHAR(255)
port                SMALLINT UNSIGNED DEFAULT 3306
secret_reference    VARCHAR(255)
schema_version      VARCHAR(50) NULL
status              VARCHAR(30)
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

Observação:

```text
A senha do banco não deve ser armazenada diretamente nesta tabela.
```

---

# 3. Usuários

## 3.1 `users`

Login único para o sistema.

```text
id                      BIGINT UNSIGNED PK
public_id               CHAR(26) UNIQUE
name                    VARCHAR(255)
email                   VARCHAR(255) UNIQUE
password                VARCHAR(255)
active                  BOOLEAN DEFAULT TRUE
last_login_at           DATETIME NULL
created_at              TIMESTAMP
updated_at              TIMESTAMP
```

---

## 3.2 `user_health_unit`

Define quais unidades cada usuário pode acessar.

```text
id                  BIGINT UNSIGNED PK
user_id             BIGINT UNSIGNED FK
health_unit_id      BIGINT UNSIGNED FK
status              VARCHAR(30)
valid_from          DATE NULL
valid_until         DATE NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

Constraint:

```text
UNIQUE(user_id, health_unit_id)
```

---

# 4. Médicos e profissionais

O profissional deve existir apenas uma vez no Core.

## 4.1 `professionals`

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
user_id             BIGINT UNSIGNED NULL
full_name           VARCHAR(255)
profession_type     VARCHAR(50)
status              VARCHAR(30)
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

Exemplos de `profession_type`:

```text
doctor
nurse
technician
psychologist
receptionist
```

---

## 4.2 `professional_registrations`

Registro profissional.

```text
id                      BIGINT UNSIGNED PK
professional_id         BIGINT UNSIGNED FK
council_code            VARCHAR(20)
registration_number     VARCHAR(40)
state                   CHAR(2)
status                  VARCHAR(30)
created_at              TIMESTAMP
updated_at              TIMESTAMP
```

Exemplos:

```text
CRM / PB / 12345
COREN / PB / 123456
CRP / PB / 12/99999
```

---

## 4.3 `specialties`

```text
id              BIGINT UNSIGNED PK
public_id       CHAR(26) UNIQUE
name            VARCHAR(120)
code            VARCHAR(50) NULL
active          BOOLEAN
created_at      TIMESTAMP
updated_at      TIMESTAMP
```

---

## 4.4 `professional_specialty`

Relacionamento N:N.

```text
id                  BIGINT UNSIGNED PK
professional_id     BIGINT UNSIGNED FK
specialty_id        BIGINT UNSIGNED FK
is_primary          BOOLEAN DEFAULT FALSE
```

---

## 4.5 `professional_health_unit`

Permite que o mesmo profissional atue em várias unidades.

```text
id                  BIGINT UNSIGNED PK
professional_id     BIGINT UNSIGNED FK
health_unit_id      BIGINT UNSIGNED FK
local_code          VARCHAR(50) NULL
status              VARCHAR(30)
starts_at           DATE NULL
ends_at             DATE NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

Constraint:

```text
UNIQUE(professional_id, health_unit_id)
```

Exemplo:

```text
Dr. Carlos
    |
    +-- Hospital A
    +-- Hospital B
    +-- Clínica C
```

---

# 5. Pacientes no banco central

A proposta é manter no Core apenas a identidade geral do paciente.

Não colocar dados clínicos nesta tabela.

## 5.1 `patients`

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
full_name           VARCHAR(255)
social_name         VARCHAR(255) NULL
birth_date          DATE NULL
sex                 VARCHAR(30) NULL
status              VARCHAR(30)
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 5.2 `patient_identifiers`

CPF, CNS e outros identificadores.

```text
id                  BIGINT UNSIGNED PK
patient_id          BIGINT UNSIGNED FK
identifier_type     VARCHAR(30)
encrypted_value     TEXT NULL
fingerprint         CHAR(64)
is_primary          BOOLEAN DEFAULT FALSE
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

Exemplos:

```text
CPF
CNS
RG
PASSAPORTE
```

Recomendação:

```text
fingerprint = HMAC-SHA256(valor_normalizado)
```

Assim é possível localizar a identidade sem depender do valor aberto.

---

# 6. Dados opcionais no Core

Ainda precisamos decidir se estes dados serão globais ou locais.

## Opção A — compartilhados

```text
patient_contacts
patient_addresses
```

## Opção B — exclusivos de cada unidade

```text
patient_contacts
patient_addresses
```

### Minha recomendação

Manter no Core apenas o mínimo necessário.

Portanto:

```text
Core:
patients
patient_identifiers

Unidade:
patient_contacts
patient_addresses
```

Isso reduz compartilhamento de informações entre unidades.

---

# 7. Banco de cada unidade

Cada unidade utiliza o mesmo schema.

Exemplo:

```text
sync_hosp_u001
sync_hosp_u002
sync_hosp_u003
```

---

# 8. Estrutura física da unidade

## 8.1 `departments`

```text
id              BIGINT UNSIGNED PK
public_id       CHAR(26) UNIQUE
name            VARCHAR(120)
code            VARCHAR(50) NULL
active          BOOLEAN
created_at      TIMESTAMP
updated_at      TIMESTAMP
```

Exemplos:

```text
Recepção
Triagem
Clínica Médica
Pediatria
Laboratório
```

---

## 8.2 `rooms`

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
department_id       BIGINT UNSIGNED FK
name                VARCHAR(120)
room_type           VARCHAR(50) NULL
active              BOOLEAN
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 8.3 `service_points`

Guichês e pontos de atendimento.

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
department_id       BIGINT UNSIGNED FK
name                VARCHAR(120)
code                VARCHAR(50) NULL
active              BOOLEAN
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

# 9. Paciente dentro da unidade

## 9.1 `unit_patients`

Relaciona o paciente global com seu cadastro naquela unidade.

```text
id                      BIGINT UNSIGNED PK
public_id               CHAR(26) UNIQUE
global_patient_id       CHAR(26)
medical_record_number   VARCHAR(50)
status                  VARCHAR(30)
first_seen_at           DATETIME
last_seen_at            DATETIME NULL
created_at              TIMESTAMP
updated_at              TIMESTAMP
```

Constraint:

```text
UNIQUE(global_patient_id)
UNIQUE(medical_record_number)
```

Exemplo:

```text
Core:
PAT-0001 = João da Silva

Unidade A:
prontuário 000019

Unidade B:
prontuário 004821
```

É a mesma pessoa, mas com históricos locais separados.

---

## 9.2 `patient_contacts`

Caso contatos sejam locais:

```text
id                  BIGINT UNSIGNED PK
unit_patient_id     BIGINT UNSIGNED FK
type                VARCHAR(30)
value               VARCHAR(255)
is_primary          BOOLEAN
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 9.3 `patient_addresses`

```text
id                  BIGINT UNSIGNED PK
unit_patient_id     BIGINT UNSIGNED FK
street              VARCHAR(255)
number              VARCHAR(30) NULL
complement          VARCHAR(120) NULL
district            VARCHAR(120) NULL
city                VARCHAR(120)
state               CHAR(2)
postal_code         VARCHAR(12) NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 9.4 `patient_guardians`

```text
id                  BIGINT UNSIGNED PK
unit_patient_id     BIGINT UNSIGNED FK
name                VARCHAR(255)
relationship        VARCHAR(50)
phone               VARCHAR(30) NULL
document            VARCHAR(50) NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

# 10. Dados clínicos básicos

## 10.1 `patient_allergies`

```text
id                  BIGINT UNSIGNED PK
unit_patient_id     BIGINT UNSIGNED FK
allergen            VARCHAR(255)
reaction            VARCHAR(255) NULL
severity            VARCHAR(30) NULL
status              VARCHAR(30)
recorded_at         DATETIME
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 10.2 `patient_conditions`

```text
id                  BIGINT UNSIGNED PK
unit_patient_id     BIGINT UNSIGNED FK
condition_code      VARCHAR(30) NULL
description         VARCHAR(255)
status              VARCHAR(30)
diagnosed_at        DATE NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

# 11. Atendimento

## 11.1 `encounters`

Representa cada atendimento.

```text
id                          BIGINT UNSIGNED PK
public_id                   CHAR(26) UNIQUE
unit_patient_id             BIGINT UNSIGNED FK
encounter_number            VARCHAR(50) UNIQUE
entry_type                  VARCHAR(50)
arrival_method              VARCHAR(50) NULL
current_status              VARCHAR(50)
opened_at                   DATETIME
closed_at                   DATETIME NULL
created_by_global_user_id   CHAR(26)
lock_version                INT UNSIGNED DEFAULT 0
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

Relacionamento:

```text
unit_patients 1 ---- N encounters
```

---

## 11.2 `reception_records`

```text
id                  BIGINT UNSIGNED PK
encounter_id        BIGINT UNSIGNED FK UNIQUE
chief_complaint     TEXT NULL
notes               TEXT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 11.3 `encounter_status_history`

```text
id                      BIGINT UNSIGNED PK
encounter_id            BIGINT UNSIGNED FK
from_status             VARCHAR(50) NULL
to_status               VARCHAR(50)
changed_by_global_id    CHAR(26)
reason                  VARCHAR(255) NULL
changed_at              DATETIME
```

---

# 12. Filas

## 12.1 `queues`

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
department_id       BIGINT UNSIGNED FK
name                VARCHAR(120)
code                VARCHAR(50)
active              BOOLEAN
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 12.2 `queue_entries`

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
queue_id            BIGINT UNSIGNED FK
encounter_id        BIGINT UNSIGNED FK
ticket_number       VARCHAR(30)
priority            INT DEFAULT 0
status              VARCHAR(30)
entered_at          DATETIME
called_at           DATETIME NULL
started_at          DATETIME NULL
completed_at        DATETIME NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 12.3 `queue_calls`

```text
id                          BIGINT UNSIGNED PK
queue_entry_id              BIGINT UNSIGNED FK
service_point_id            BIGINT UNSIGNED FK NULL
called_by_global_user_id    CHAR(26)
called_at                   DATETIME
call_number                 INT UNSIGNED DEFAULT 1
```

---

# 13. Triagem

## 13.1 `triages`

```text
id                          BIGINT UNSIGNED PK
public_id                   CHAR(26) UNIQUE
encounter_id                BIGINT UNSIGNED FK UNIQUE
global_professional_id      CHAR(26)
risk_level                  VARCHAR(30) NULL
status                      VARCHAR(30)
started_at                  DATETIME
completed_at                DATETIME NULL
notes                       TEXT NULL
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

---

## 13.2 `vital_signs`

```text
id                  BIGINT UNSIGNED PK
triage_id           BIGINT UNSIGNED FK
temperature         DECIMAL(4,1) NULL
heart_rate          SMALLINT NULL
respiratory_rate    SMALLINT NULL
systolic_bp         SMALLINT NULL
diastolic_bp        SMALLINT NULL
oxygen_saturation   DECIMAL(5,2) NULL
weight_kg           DECIMAL(6,2) NULL
height_cm           DECIMAL(6,2) NULL
pain_scale          TINYINT NULL
measured_at         DATETIME
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

# 14. Atendimento médico

## 14.1 `medical_consultations`

```text
id                          BIGINT UNSIGNED PK
public_id                   CHAR(26) UNIQUE
encounter_id                BIGINT UNSIGNED FK UNIQUE
global_professional_id      CHAR(26)
status                      VARCHAR(30)
started_at                  DATETIME
completed_at                DATETIME NULL
clinical_summary            TEXT NULL
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

---

## 14.2 `diagnoses`

```text
id                          BIGINT UNSIGNED PK
medical_consultation_id     BIGINT UNSIGNED FK
code                        VARCHAR(20) NULL
description                 VARCHAR(255)
diagnosis_type              VARCHAR(30) NULL
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

---

## 14.3 `clinical_notes`

```text
id                          BIGINT UNSIGNED PK
medical_consultation_id     BIGINT UNSIGNED FK
global_professional_id      CHAR(26)
note_type                   VARCHAR(50)
content                     LONGTEXT
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

---

# 15. Prescrições

## 15.1 `prescriptions`

```text
id                          BIGINT UNSIGNED PK
public_id                   CHAR(26) UNIQUE
medical_consultation_id     BIGINT UNSIGNED FK
global_professional_id      CHAR(26)
status                      VARCHAR(30)
issued_at                   DATETIME
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

---

## 15.2 `prescription_items`

```text
id                  BIGINT UNSIGNED PK
prescription_id     BIGINT UNSIGNED FK
medication_name     VARCHAR(255)
dosage              VARCHAR(120) NULL
route               VARCHAR(80) NULL
frequency           VARCHAR(120) NULL
duration            VARCHAR(120) NULL
instructions        TEXT NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

# 16. Exames

## 16.1 `exam_orders`

```text
id                          BIGINT UNSIGNED PK
public_id                   CHAR(26) UNIQUE
medical_consultation_id     BIGINT UNSIGNED FK
global_professional_id      CHAR(26)
status                      VARCHAR(30)
ordered_at                  DATETIME
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

---

## 16.2 `exam_order_items`

```text
id                  BIGINT UNSIGNED PK
exam_order_id       BIGINT UNSIGNED FK
exam_code           VARCHAR(50) NULL
exam_name           VARCHAR(255)
status              VARCHAR(30)
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

## 16.3 `exam_results`

```text
id                  BIGINT UNSIGNED PK
exam_order_item_id  BIGINT UNSIGNED FK
result_text         LONGTEXT NULL
result_value        VARCHAR(255) NULL
reference_range     VARCHAR(255) NULL
released_at         DATETIME NULL
created_at          TIMESTAMP
updated_at          TIMESTAMP
```

---

# 17. Documentos

## 17.1 `documents`

```text
id                          BIGINT UNSIGNED PK
public_id                   CHAR(26) UNIQUE
unit_patient_id             BIGINT UNSIGNED FK
encounter_id                BIGINT UNSIGNED FK NULL
global_professional_id      CHAR(26) NULL
document_type               VARCHAR(50)
status                      VARCHAR(30)
current_version             INT UNSIGNED DEFAULT 1
storage_key                 VARCHAR(500) NULL
created_at                  TIMESTAMP
updated_at                  TIMESTAMP
```

Exemplos:

```text
prescription
certificate
report
discharge
exam_request
```

---

## 17.2 `document_versions`

```text
id                  BIGINT UNSIGNED PK
document_id         BIGINT UNSIGNED FK
version             INT UNSIGNED
storage_key         VARCHAR(500)
file_hash           VARCHAR(128)
created_at          TIMESTAMP
```

Constraint:

```text
UNIQUE(document_id, version)
```

---

# 18. Auditoria

## 18.1 Core — `security_audit_logs`

```text
id                  BIGINT UNSIGNED PK
public_id           CHAR(26) UNIQUE
actor_user_id       BIGINT UNSIGNED NULL
health_unit_id      BIGINT UNSIGNED NULL
event               VARCHAR(100)
ip_address          VARCHAR(45) NULL
metadata            JSON NULL
occurred_at         DATETIME
```

Exemplos:

```text
LOGIN_SUCCESS
LOGIN_FAILED
UNIT_SELECTED
UNIT_ACCESS_DENIED
ROLE_CHANGED
```

---

## 18.2 Unidade — `audit_logs`

```text
id                          BIGINT UNSIGNED PK
public_id                   CHAR(26) UNIQUE
actor_global_user_id        CHAR(26)
actor_global_professional_id CHAR(26) NULL
event                       VARCHAR(100)
subject_type                VARCHAR(100)
subject_public_id           CHAR(26)
metadata                    JSON NULL
occurred_at                 DATETIME
```

---

## 18.3 `patient_access_logs`

```text
id                      BIGINT UNSIGNED PK
unit_patient_id         BIGINT UNSIGNED FK
actor_global_user_id    CHAR(26)
action                  VARCHAR(50)
source                  VARCHAR(100) NULL
occurred_at             DATETIME
```

Exemplos:

```text
VIEW
PRINT
EXPORT
DOWNLOAD_DOCUMENT
```

---

# 19. Resumo visual das tabelas

## Banco Central

```text
sync_hosp_core

organizations
health_units
tenant_databases

users
user_health_unit

roles
permissions

professionals
professional_registrations
professional_health_unit
specialties
professional_specialty

patients
patient_identifiers

security_audit_logs
```

---

## Banco de cada unidade

```text
sync_hosp_uXXXX

departments
rooms
service_points

unit_patients
patient_contacts
patient_addresses
patient_guardians
patient_allergies
patient_conditions

encounters
reception_records
encounter_status_history

queues
queue_entries
queue_calls

triages
vital_signs

medical_consultations
diagnoses
clinical_notes

prescriptions
prescription_items

exam_orders
exam_order_items
exam_results

documents
document_versions

audit_logs
patient_access_logs
```

---

# 20. Relacionamentos principais

```text
CORE

Organization
    |
    +--- HealthUnit

User
    |
    +--- UserHealthUnit --- HealthUnit

Professional
    |
    +--- ProfessionalHealthUnit --- HealthUnit
    |
    +--- ProfessionalSpecialty --- Specialty

Patient
    |
    +--- PatientIdentifier
```

```text
TENANT

UnitPatient
    |
    +--- Encounter
            |
            +--- ReceptionRecord
            |
            +--- QueueEntry
            |
            +--- Triage
            |       |
            |       +--- VitalSigns
            |
            +--- MedicalConsultation
                    |
                    +--- Diagnosis
                    +--- ClinicalNote
                    +--- Prescription
                    |       |
                    |       +--- PrescriptionItem
                    |
                    +--- ExamOrder
                            |
                            +--- ExamOrderItem
                                    |
                                    +--- ExamResult
```

---

# 21. Decisões que ainda precisam ser discutidas

## 1. Contatos e endereço do paciente

Decidir:

```text
A) Core compartilhado
```

ou:

```text
B) cadastro independente por unidade
```

Minha recomendação:

```text
B
```

---

## 2. Alergias

Mesmo paciente em várias unidades.

Devemos decidir se:

```text
alergias são exclusivamente locais
```

ou se no futuro existirá uma forma explícita de compartilhar alertas clínicos.

Para a primeira versão:

```text
local por unidade
```

---

## 3. Catálogos clínicos

Precisamos decidir quais tabelas são globais:

```text
risk_levels
entry_types
arrival_methods
document_types
exam_catalog
medication_catalog
```

Uma possibilidade:

```text
Core = definição global
Tenant = configura se usa ou não
```

---

## 4. Roles

Precisamos definir se o papel é:

```text
global
```

ou:

```text
por unidade
```

Recomendação:

```text
roles/permissões globais
+
atribuição por unidade
```

---

## 5. Banco MySQL

Inicialmente podemos ter:

```text
mesmo servidor MySQL

sync_hosp_core
sync_hosp_u0001
sync_hosp_u0002
sync_hosp_u0003
```

A arquitetura continua permitindo no futuro:

```text
sync_hosp_u0003
```

ser movido para outro servidor MySQL sem mudar a modelagem.

---

# 22. Proposta final para discussão

A proposta de modelagem fica resumida em:

```text
                     SYNC HOSP

                     CORE MYSQL
                         |
        +----------------+----------------+
        |                |                |
      Users         Professionals      Patients
        |                |                |
      Units <-------------+                |
        |                                 |
        +---------- Tenant Resolver ------+
                         |
           +-------------+-------------+
           |             |             |
           v             v             v
      MySQL Unit A  MySQL Unit B  MySQL Unit C
           |             |             |
      prontuário A  prontuário B  prontuário C
```

### Banco Central

Responsável por:

```text
identidade
login
unidades
profissionais
vínculos
pacientes globais
```

### Banco da Unidade

Responsável por:

```text
prontuário
recepção
fila
triagem
atendimento médico
diagnóstico
prescrição
exame
documento
auditoria clínica
```

A principal vantagem é permitir que:

> **pessoas sejam reconhecidas globalmente sem tornar o prontuário global.**

Essa é a base recomendada para discutirmos a nova estrutura MySQL do Sync Hosp.
