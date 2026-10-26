<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Console;

use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Requests\Services\Commission;
use App\Modules\Sessions\Models\Conversation;
use App\Modules\Sessions\Models\TrainingSession;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Illuminate\Console\Command;

/**
 * Cria uma aula de demonstração a começar agora, para testar o fluxo completo com dois telemóveis
 * (só fora de produção). Os dois utilizadores entram com o número e o código OTP (driver "log": ver o log).
 */
class DemoLessonCommand extends Command
{
    protected $signature = 'longa:demo:lesson
        {--student=+244900000001 : Telefone do aluno}
        {--trainer=+244900000002 : Telefone do formador}
        {--amount=25000 : Valor em Kz}
        {--cash : Pagamento em mão em vez de eletrónico}';

    protected $description = 'Cria uma aula de demonstração (aluno + formador) a começar agora, à espera de pagamento';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Não disponível em produção.');

            return self::FAILURE;
        }

        $skill = Skill::query()->firstOrFail();
        $student = $this->user((string) $this->option('student'), 'Aluno Demo', Role::Student);
        $trainerUser = $this->user((string) $this->option('trainer'), 'Formador Demo', Role::Trainer);
        $trainer = $trainerUser->trainerProfile ?? TrainerProfile::factory()->verified()->for($trainerUser)->create();
        $cash = (bool) $this->option('cash');
        $split = Commission::split((int) $this->option('amount'));

        $request = TrainingRequest::factory()->create([
            'student_id' => $student->id, 'skill_id' => $skill->id, 'amount' => $split['amount'],
            'payment_method' => $cash ? 'cash' : 'electronic', 'duration_minutes' => 60,
        ]);
        $session = TrainingSession::query()->create([
            'training_request_id' => $request->id, 'student_id' => $student->id, 'trainer_profile_id' => $trainer->id,
            'skill_id' => $skill->id, 'title' => 'Aula de demonstração', ...$split,
            'scheduled_at' => now()->addMinutes(5), 'duration_minutes' => 60, 'payment_method' => $cash ? 'cash' : 'electronic',
            'payment_due_at' => $cash ? null : now()->addMinutes((int) config('longa.session_rules.payment_window_minutes')),
            'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => -8.9167, 'longitude' => 13.1833,
        ]);
        $session->forceFill(['state' => $cash ? 'scheduled' : 'pending_payment'])->save();
        Conversation::query()->create(['training_session_id' => $session->id]);

        $this->info("Aula #{$session->id} criada (".($cash ? 'em mão' : 'pagar em 10 minutos').').');
        $this->table(['Papel', 'Telefone', 'Abrir'], [
            ['Aluno', $student->phone, url("/aulas/{$session->id}")],
            ['Formador', $trainerUser->phone, url("/aulas/{$session->id}")],
        ]);
        $this->line('Fluxo: aluno paga → formador "Iniciar aula" → "Terminar aula" → aluno "Escanear QR para confirmar".');

        return self::SUCCESS;
    }

    private function user(string $phone, string $name, Role $role): User
    {
        $user = User::query()->firstOrCreate(['phone' => $phone], ['name' => $name, 'phone_verified_at' => now(), 'onboarded_at' => now()]);
        $user->assignRole($role->value);

        return $user;
    }
}
