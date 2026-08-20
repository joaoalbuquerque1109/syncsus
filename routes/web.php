<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Modules\Administration\Presentation\Http\Controllers\CatalogManagementController;
use App\Modules\Administration\Presentation\Http\Controllers\TenantProvisioningController;
use App\Modules\Administration\Presentation\Http\Controllers\UserManagementController;
use App\Modules\Audit\Presentation\Http\Controllers\AuditTrailController;
use App\Modules\Documents\Presentation\Http\Controllers\ClinicalDocumentController;
use App\Modules\Documents\Presentation\Http\Controllers\MedicalCertificateController;
use App\Modules\Documents\Presentation\Http\Controllers\SourceClinicalDocumentController;
use App\Modules\Identity\Presentation\Http\Controllers\ActiveHealthUnitController;
use App\Modules\Identity\Presentation\Http\Controllers\AuthenticatedSessionController;
use App\Modules\Identity\Presentation\Http\Controllers\EmployeeRegistrationController;
use App\Modules\Identity\Presentation\Http\Controllers\PasswordController;
use App\Modules\Laboratory\Presentation\Http\Controllers\ExamGroupManagementController;
use App\Modules\Laboratory\Presentation\Http\Controllers\LaboratoryOrderController;
use App\Modules\Laboratory\Presentation\Http\Controllers\SynclabIntegrationController;
use App\Modules\Medical\Presentation\Http\Controllers\DiagnosisCodeSearchController;
use App\Modules\Medical\Presentation\Http\Controllers\LaboratoryExamSearchController;
use App\Modules\Medical\Presentation\Http\Controllers\MedicalConsultationController;
use App\Modules\Medical\Presentation\Http\Controllers\SusProcedureSearchController;
use App\Modules\Operations\Presentation\Http\Controllers\OperationsController;
use App\Modules\Patients\Presentation\Http\Controllers\PatientClinicalHistoryController;
use App\Modules\Patients\Presentation\Http\Controllers\PatientController;
use App\Modules\Patients\Presentation\Http\Controllers\ProvisionalPatientController;
use App\Modules\Professionals\Presentation\Http\Controllers\HealthProfessionalController;
use App\Modules\Queues\Presentation\Http\Controllers\FlowConfigurationController;
use App\Modules\Queues\Presentation\Http\Controllers\PublicPanelController;
use App\Modules\Queues\Presentation\Http\Controllers\QueueController;
use App\Modules\Queues\Presentation\Http\Controllers\QueueEntryController;
use App\Modules\Reception\Presentation\Http\Controllers\ReceptionController;
use App\Modules\Reports\Presentation\Http\Controllers\ReportController;
use App\Modules\Triage\Presentation\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::middleware('throttle:public-panels')->group(function (): void {
    Route::get('/panels/{panel}', [PublicPanelController::class, 'show'])->name('panels.show');
    Route::get('/panels/{panel}/state', [PublicPanelController::class, 'state'])->name('panels.state');
    Route::post('/panels/{panel}/heartbeat', [PublicPanelController::class, 'heartbeat'])->name('panels.heartbeat');
});
Route::get('/document-verification/{verificationCode}', [ClinicalDocumentController::class, 'verify'])
    ->middleware('throttle:document-verification')
    ->name('documents.verify');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/register', [EmployeeRegistrationController::class, 'create'])->name('register');
    Route::post('/register', [EmployeeRegistrationController::class, 'store'])
        ->middleware('throttle:employee-registration')
        ->name('register.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password/change', [PasswordController::class, 'update'])->name('password.update');

    Route::middleware('password.changed')->prefix('administration/tenants')
        ->name('administration.tenants.')
        ->group(function (): void {
            Route::get('/', [TenantProvisioningController::class, 'index'])->name('index');
            Route::post('/', [TenantProvisioningController::class, 'store'])->name('store');
        });

    Route::middleware(['password.changed', 'active.unit'])->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/dashboard/state', [DashboardController::class, 'state'])->name('dashboard.state');
        Route::get('/dashboard/metrics', [DashboardController::class, 'metrics'])->name('dashboard.metrics');
        Route::get('/dashboard/active-encounters', [DashboardController::class, 'activeEncounters'])->name('dashboard.active-encounters');
        Route::put('/active-health-unit', [ActiveHealthUnitController::class, 'update'])
            ->name('active-health-unit.update');
        Route::prefix('patients')->name('patients.')->group(function (): void {
            Route::get('/', [PatientController::class, 'index'])->middleware('permission:patients.view')->name('index');
            Route::get('/search', [PatientController::class, 'search'])->middleware('permission:patients.view')->name('search');
            Route::get('/create', [PatientController::class, 'create'])->middleware('permission:patients.create')->name('create');
            Route::post('/', [PatientController::class, 'store'])->name('store');
            Route::get('/provisional/create', [ProvisionalPatientController::class, 'create'])->middleware('permission:patients.create')->name('provisional.create');
            Route::post('/provisional', [ProvisionalPatientController::class, 'store'])->name('provisional.store');
            Route::get('/{patient}', [PatientController::class, 'show'])->middleware('permission:patients.view')->name('show');
            Route::get('/{patient}/edit', [PatientController::class, 'edit'])->middleware('permission:patients.update')->name('edit');
            Route::put('/{patient}', [PatientController::class, 'update'])->name('update');
            Route::middleware('permission:patients.clinical_history')->group(function (): void {
                Route::post('/{patient}/clinical-history/allergies', [PatientClinicalHistoryController::class, 'storeAllergy'])->name('clinical-history.allergies.store');
                Route::post('/{patient}/clinical-history/conditions', [PatientClinicalHistoryController::class, 'storeCondition'])->name('clinical-history.conditions.store');
                Route::post('/{patient}/clinical-history/medications', [PatientClinicalHistoryController::class, 'storeMedication'])->name('clinical-history.medications.store');
                Route::put('/{patient}/clinical-history/social', [PatientClinicalHistoryController::class, 'saveSocialHistory'])->name('clinical-history.social.update');
            });
        });

        Route::prefix('reception')->name('reception.')->middleware('permission:encounters.open')->group(function (): void {
            Route::get('/open', [ReceptionController::class, 'create'])->name('create');
            Route::post('/open', [ReceptionController::class, 'store'])->name('store');
            Route::post('/draft/patient', [ReceptionController::class, 'draftForPatient'])->name('draft.patient');
            Route::post('/draft/provisional', [ReceptionController::class, 'draftForProvisionalPatient'])->name('draft.provisional');
            Route::post('/draft/resume', [ReceptionController::class, 'resumeDraft'])->name('draft.resume');
            Route::get('/receipt/{encounter}', [ReceptionController::class, 'receipt'])->name('receipt');
        });
        Route::post('/reception/encounters/{encounter}/cancel', [ReceptionController::class, 'cancel'])
            ->name('reception.cancel');

        Route::get('/queues', [QueueController::class, 'index'])->middleware('permission:queues.view')->name('queues.index');
        Route::get('/laboratory/exams/search', LaboratoryExamSearchController::class)
            ->middleware('permission:laboratory.orders.create')
            ->name('laboratory.exams.search');
        Route::prefix('laboratory/orders')->name('laboratory.orders.')->group(function (): void {
            Route::get('/', [LaboratoryOrderController::class, 'index'])->middleware('permission:laboratory.orders.view')->name('index');
            Route::get('/{order}', [LaboratoryOrderController::class, 'show'])->middleware('permission:laboratory.orders.view')->name('show');
            Route::post('/{order}/cancel', [LaboratoryOrderController::class, 'cancel'])->middleware('permission:laboratory.orders.cancel')->name('cancel');
            Route::post('/{order}/retry', [LaboratoryOrderController::class, 'retry'])->middleware('permission:administration.manage')->name('retry');
        });
        Route::get('/queues/{queue}/entries', [QueueController::class, 'entries'])->middleware('permission:queues.view')->name('queues.entries');
        Route::prefix('queue-entries/{entry}')->name('queue-entries.')->group(function (): void {
            Route::post('/call', [QueueEntryController::class, 'call'])->middleware('permission:queues.call')->name('call');
            Route::post('/recall', [QueueEntryController::class, 'recall'])->middleware('permission:queues.call')->name('recall');
            Route::post('/start-service', [QueueEntryController::class, 'start'])->middleware('permission:queues.call')->name('start');
            Route::post('/mark-absent', [QueueEntryController::class, 'absent'])->middleware('permission:queues.call')->name('absent');
            Route::post('/return', [QueueEntryController::class, 'returnToQueue'])->middleware('permission:queues.call')->name('return');
            Route::post('/transfer', [QueueEntryController::class, 'transfer'])->middleware('permission:queues.transfer')->name('transfer');
        });

        Route::prefix('administration/flow')->name('administration.flow.')->middleware('permission:administration.manage')->group(function (): void {
            Route::get('/', [FlowConfigurationController::class, 'index'])->name('index');
            Route::post('/departments', [FlowConfigurationController::class, 'storeDepartment'])->name('departments.store');
            Route::post('/rooms', [FlowConfigurationController::class, 'storeRoom'])->name('rooms.store');
            Route::post('/service-points', [FlowConfigurationController::class, 'storeServicePoint'])->name('service-points.store');
            Route::post('/queues', [FlowConfigurationController::class, 'storeQueue'])->name('queues.store');
            Route::post('/panels', [FlowConfigurationController::class, 'storePanel'])->name('panels.store');
            Route::put('/queues/{queue}', [FlowConfigurationController::class, 'updateQueue'])->name('queues.update');
            Route::put('/panels/{panel}', [FlowConfigurationController::class, 'updatePanel'])->name('panels.update');
        });
        Route::get('/administration/operations', OperationsController::class)
            ->middleware('permission:administration.manage')
            ->name('administration.operations');
        Route::prefix('administration/integrations/synclab')
            ->name('administration.synclab.')
            ->middleware('permission:administration.manage')
            ->group(function (): void {
                Route::get('/', [SynclabIntegrationController::class, 'edit'])->name('edit');
                Route::put('/', [SynclabIntegrationController::class, 'update'])->name('update');
                Route::post('/result-token', [SynclabIntegrationController::class, 'rotateResultToken'])
                    ->name('result-token.rotate');
            });
        Route::prefix('administration/professionals')
            ->name('administration.professionals.')
            ->middleware('permission:administration.manage')
            ->group(function (): void {
                Route::get('/', [HealthProfessionalController::class, 'index'])->name('index');
                Route::get('/create', [HealthProfessionalController::class, 'create'])->name('create');
                Route::post('/', [HealthProfessionalController::class, 'store'])->name('store');
                Route::get('/{professional}/edit', [HealthProfessionalController::class, 'edit'])->name('edit');
                Route::put('/{professional}', [HealthProfessionalController::class, 'update'])->name('update');
            });
        Route::prefix('administration/users')
            ->name('administration.users.')
            ->middleware('permission:administration.manage')
            ->group(function (): void {
                Route::get('/', [UserManagementController::class, 'index'])->name('index');
                Route::post('/', [UserManagementController::class, 'store'])->name('store');
                Route::put('/{managedUser:public_id}', [UserManagementController::class, 'update'])->name('update');
            });
        Route::prefix('administration/catalogs')
            ->name('administration.catalogs.')
            ->middleware('permission:administration.manage')
            ->group(function (): void {
                Route::get('/', [CatalogManagementController::class, 'index'])->name('index');
                Route::post('/{catalog}', [CatalogManagementController::class, 'store'])->name('store');
                Route::put('/{catalog}/{record}', [CatalogManagementController::class, 'update'])->name('update');
            });
        Route::prefix('administration/exam-groups')
            ->name('administration.exam-groups.')
            ->middleware('permission:administration.manage')
            ->group(function (): void {
                Route::get('/search-exams', [ExamGroupManagementController::class, 'searchExams'])
                    ->name('search-exams');
                Route::get('/', [ExamGroupManagementController::class, 'index'])->name('index');
                Route::post('/', [ExamGroupManagementController::class, 'store'])->name('store');
                Route::put('/{examGroup}', [ExamGroupManagementController::class, 'update'])->name('update');
            });

        Route::prefix('triage')->name('triage.')->group(function (): void {
            Route::get('/queue', [TriageController::class, 'queue'])->middleware('permission:triage.view')->name('queue');
            Route::post('/queue-entries/{entry}/start', [TriageController::class, 'start'])->middleware('permission:triage.start')->name('start');
            Route::get('/{triage}', [TriageController::class, 'show'])->middleware('permission:triage.view')->name('show');
            Route::put('/{triage}/draft', [TriageController::class, 'draft'])->name('draft');
            Route::post('/{triage}/vital-signs', [TriageController::class, 'vitalSigns'])->name('vital-signs');
            Route::post('/{triage}/complete', [TriageController::class, 'complete'])->name('complete');
            Route::post('/{triage}/addendum', [TriageController::class, 'addendum'])->name('addendum');
        });

        Route::prefix('medical')->name('medical.')->group(function (): void {
            Route::get('/queue', [MedicalConsultationController::class, 'queue'])->middleware('permission:medical.view')->name('queue');
            Route::get('/cid-codes/search', DiagnosisCodeSearchController::class)->middleware('permission:medical.view')->name('cid-codes.search');
            Route::get('/sus-procedures/search', SusProcedureSearchController::class)->middleware('permission:medical.view')->name('sus-procedures.search');
            Route::get('/laboratory-exams/search', LaboratoryExamSearchController::class)->middleware('permission:medical.view')->name('laboratory-exams.search');
            Route::post('/queue-entries/{entry}/start', [MedicalConsultationController::class, 'start'])->middleware('permission:medical.start')->name('start');
            Route::get('/consultations/{consultation}', [MedicalConsultationController::class, 'show'])->middleware('permission:medical.view')->name('show');
            Route::put('/consultations/{consultation}/draft', [MedicalConsultationController::class, 'draft'])->name('draft');
            Route::post('/consultations/{consultation}/diagnoses', [MedicalConsultationController::class, 'diagnosis'])->name('diagnoses');
            Route::post('/consultations/{consultation}/prescriptions', [MedicalConsultationController::class, 'prescription'])->name('prescriptions');
            Route::post('/consultations/{consultation}/exam-orders', [MedicalConsultationController::class, 'examOrder'])->name('exam-orders');
            Route::post('/consultations/{consultation}/clinical-notes', [MedicalConsultationController::class, 'clinicalNote'])->name('clinical-notes');
            Route::post('/consultations/{consultation}/referrals', [MedicalConsultationController::class, 'referral'])->name('referrals');
            Route::post('/consultations/{consultation}/documents', [ClinicalDocumentController::class, 'store'])->name('documents');
            Route::post('/consultations/{consultation}/medical-certificates', [MedicalCertificateController::class, 'store'])->name('medical-certificates.store');
            Route::post('/consultations/{consultation}/prescriptions/{prescription}/document', [SourceClinicalDocumentController::class, 'prescription'])->middleware('permission:medical.issue_documents')->name('prescriptions.document');
            Route::post('/consultations/{consultation}/exam-orders/{order}/document', [SourceClinicalDocumentController::class, 'examOrder'])->middleware('permission:medical.issue_documents')->name('exam-orders.document');
            Route::post('/consultations/{consultation}/referrals/{referral}/document', [SourceClinicalDocumentController::class, 'referral'])->middleware('permission:medical.issue_documents')->name('referrals.document');
            Route::post('/consultations/{consultation}/complete', [MedicalConsultationController::class, 'complete'])->name('complete');
            Route::post('/consultations/{consultation}/addendum', [MedicalConsultationController::class, 'addendum'])->name('addendum');
            Route::post('/consultations/{consultation}/diagnoses/{diagnosis}/void', [MedicalConsultationController::class, 'voidDiagnosis'])->name('diagnoses.void');
            Route::post('/consultations/{consultation}/prescriptions/{prescription}/cancel', [MedicalConsultationController::class, 'cancelPrescription'])->name('prescriptions.cancel');
            Route::post('/consultations/{consultation}/clinical-notes/{note}/void', [MedicalConsultationController::class, 'voidClinicalNote'])->name('clinical-notes.void');
        });

        Route::prefix('documents')->name('documents.')->middleware('permission:medical.issue_documents')->group(function (): void {
            Route::get('/{document}', [ClinicalDocumentController::class, 'show'])->name('show');
            Route::get('/{document}/pdf', [ClinicalDocumentController::class, 'download'])->name('pdf');
            Route::post('/{document}/versions', [ClinicalDocumentController::class, 'newVersion'])->name('versions');
            Route::post('/{document}/void', [ClinicalDocumentController::class, 'void'])->name('void');
        });

        Route::prefix('reports')->name('reports.')->middleware('permission:reports.view')->group(function (): void {
            Route::get('/', [ReportController::class, 'index'])->name('index');
            Route::get('/encounters.csv', [ReportController::class, 'csv'])->middleware('throttle:exports')->name('csv');
            Route::get('/encounters.pdf', [ReportController::class, 'pdf'])->middleware('throttle:exports')->name('pdf');
        });

        Route::get('/audit', AuditTrailController::class)
            ->middleware('permission:audit.view')
            ->name('audit.index');
    });
});
