{{--
    Modal global de "Transferir / Cancelar / Não encontrado" atendimento.
    Renderizado uma única vez no layout compartilhado; qualquer botão em
    qualquer tela dispara $store.encounterActionModal.openFor(context).
--}}
<template x-teleport="body">
    <div
        x-show="$store.encounterActionModal.open"
        x-cloak
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/60 p-4 py-10"
        @keydown.escape.window="$store.encounterActionModal.close()"
    >
        <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl" @click.outside="$store.encounterActionModal.close()">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-extrabold text-slate-900" x-text="$store.encounterActionModal.context.title"></h2>
                <button type="button" class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" @click="$store.encounterActionModal.close()" aria-label="Fechar">✕</button>
            </div>
            <div class="space-y-4 p-5" x-data="{ m: $store.encounterActionModal }">
                <p x-show="m.error" x-text="m.error" class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700"></p>

                <template x-if="m.mode === 'menu'">
                    <div class="space-y-2">
                        <button type="button" x-show="m.context.canTransfer" x-cloak @click="m.mode = 'transfer'" class="w-full rounded-lg border border-slate-300 px-4 py-3 text-left text-sm font-bold hover:bg-slate-50">
                            Transferir para outra fila
                        </button>
                        <button type="button" x-show="m.context.canCancel" x-cloak @click="m.mode = 'not_found'" class="w-full rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-left text-sm font-bold text-amber-800 hover:bg-amber-100">
                            Paciente não encontrado
                        </button>
                        <button type="button" x-show="m.context.canCancel && !m.context.isAdmin" x-cloak @click="m.mode = 'cancel'" class="w-full rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-left text-sm font-bold text-red-800 hover:bg-red-100">
                            Cancelar atendimento
                        </button>
                        <button type="button" x-show="m.context.canCancel && m.context.isAdmin" x-cloak @click="m.mode = 'cancel'" class="w-full rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-left text-sm font-bold text-red-800 hover:bg-red-100">
                            Encerrar atendimento
                        </button>
                        <p class="text-xs text-slate-500" x-show="!m.context.canTransfer && !m.context.canCancel">Nenhuma ação disponível para este atendimento.</p>
                    </div>
                </template>

                <template x-if="m.mode === 'transfer'">
                    <div class="space-y-3">
                        <div>
                            <label class="field-label" for="action-modal-destination">Fila de destino</label>
                            <select id="action-modal-destination" class="field-control" x-model="m.destinationQueue">
                                <option value="">Selecione</option>
                                <template x-for="queue in m.context.destinationQueues" :key="queue.id">
                                    <option :value="queue.id" x-text="queue.name"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="action-modal-reason-transfer">Motivo</label>
                            <textarea id="action-modal-reason-transfer" class="field-control" rows="3" x-model="m.reason"></textarea>
                        </div>
                        <div class="flex justify-between gap-3 pt-2">
                            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold" @click="m.mode = 'menu'">Voltar</button>
                            <button type="button" class="rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60" :disabled="m.submitting" @click="m.submitTransfer()">Confirmar transferência</button>
                        </div>
                    </div>
                </template>

                <template x-if="m.mode === 'not_found'">
                    <div class="space-y-3">
                        <p class="text-sm text-slate-600">O atendimento será encerrado e o paciente precisará ser recepcionado novamente.</p>
                        <div>
                            <label class="field-label" for="action-modal-reason-nf">Motivo</label>
                            <textarea id="action-modal-reason-nf" class="field-control" rows="3" x-model="m.reason" placeholder="Ex.: paciente não se apresentou após a chamada."></textarea>
                        </div>
                        <div class="flex justify-between gap-3 pt-2">
                            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold" @click="m.mode = 'menu'">Voltar</button>
                            <button type="button" class="rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60" :disabled="m.submitting" @click="m.submitNotFound()">Confirmar</button>
                        </div>
                    </div>
                </template>

                <template x-if="m.mode === 'cancel'">
                    <div class="space-y-3">
                        <p class="text-sm font-semibold text-red-700" x-show="m.context.isAdmin">Isto encerra o atendimento imediatamente, mesmo incompleto.</p>
                        <p class="text-sm text-slate-600" x-show="!m.context.isAdmin">O atendimento sai das filas ativas e fica registrado na auditoria.</p>
                        <div>
                            <label class="field-label" for="action-modal-reason-cancel">Motivo</label>
                            <textarea id="action-modal-reason-cancel" class="field-control" rows="3" x-model="m.reason"></textarea>
                        </div>
                        <div class="flex justify-between gap-3 pt-2">
                            <button type="button" class="rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-bold" @click="m.mode = 'menu'">Voltar</button>
                            <button type="button" class="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-bold text-white disabled:opacity-60" :disabled="m.submitting" @click="m.submitCancel()" x-text="m.context.isAdmin ? 'Encerrar atendimento' : 'Cancelar atendimento'"></button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
