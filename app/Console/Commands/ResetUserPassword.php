<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Application\Actions\ResetUserPasswordAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class ResetUserPassword extends Command
{
    protected $signature = 'sync-sus:user-reset-password
        {target-email : E-mail do usuário que receberá a senha temporária}
        {--actor= : E-mail do administrador responsável}';

    protected $description = 'Redefine uma senha local, encerra sessões e exige troca no próximo acesso';

    public function handle(ResetUserPasswordAction $resetUserPassword): int
    {
        $actorEmail = mb_strtolower(trim((string) ($this->option('actor') ?: $this->ask('E-mail do administrador'))));
        $targetEmail = mb_strtolower(trim((string) $this->argument('target-email')));
        $temporaryPassword = (string) $this->secret('Nova senha temporária');
        $confirmation = (string) $this->secret('Confirme a senha temporária');

        $validator = Validator::make(
            ['password' => $temporaryPassword, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::defaults()]],
        );

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $actor = User::query()->where('email', $actorEmail)->firstOrFail();
        $target = User::query()->where('email', $targetEmail)->firstOrFail();
        $resetUserPassword->execute($actor, $target, $temporaryPassword);

        $this->components->info('Senha redefinida. O usuário deverá trocá-la no próximo acesso.');

        return self::SUCCESS;
    }
}
