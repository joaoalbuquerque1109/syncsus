<?php

declare(strict_types=1);

namespace App\Modules\Documents\Domain\Enums;

enum ClinicalDocumentType: string
{
    case MedicalCertificate = 'medical_certificate';
    case AttendanceDeclaration = 'attendance_declaration';
    case CompanionDeclaration = 'companion_declaration';
    case Prescription = 'prescription';
    case ExamRequest = 'exam_request';
    case Referral = 'referral';
    case MedicalReport = 'medical_report';
    case DischargeGuidance = 'discharge_guidance';
    case EncounterSummary = 'encounter_summary';

    public function label(): string
    {
        return match ($this) {
            self::MedicalCertificate => 'Atestado médico',
            self::AttendanceDeclaration => 'Declaração de comparecimento',
            self::CompanionDeclaration => 'Declaração de acompanhante',
            self::Prescription => 'Receita',
            self::ExamRequest => 'Solicitação de exames',
            self::Referral => 'Encaminhamento',
            self::MedicalReport => 'Relatório médico',
            self::DischargeGuidance => 'Orientações de alta',
            self::EncounterSummary => 'Resumo do atendimento',
        };
    }

    public function isSourceManaged(): bool
    {
        return in_array($this, [
            self::MedicalCertificate,
            self::Prescription,
            self::ExamRequest,
            self::Referral,
        ], true);
    }

    /** @return list<self> */
    public static function manuallyIssued(): array
    {
        return [
            self::AttendanceDeclaration,
            self::CompanionDeclaration,
            self::MedicalReport,
            self::DischargeGuidance,
            self::EncounterSummary,
        ];
    }
}
