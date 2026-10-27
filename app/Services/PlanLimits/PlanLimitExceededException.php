<?php

namespace App\Services\PlanLimits;

use App\Enums\Rules;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanLimitExceededException extends HttpException
{
    public function __construct(public readonly ResourceUsage $usage)
    {
        parent::__construct(403, $this->teamMessage());
    }

    public function teamMessage(): string
    {
        return sprintf(
            'Seu laboratório atingiu o limite de %s do plano contratado. Faça upgrade do plano para continuar cadastrando.',
            $this->usage->resource->label(),
        );
    }

    public function dentistMessage(): string
    {
        return 'Não foi possível realizar a solicitação no momento. Entre em contato com o laboratório.';
    }

    public function messageFor(?User $user): string
    {
        $user?->loadMissing('rule');

        return $user?->rule?->name === Rules::Dentist
            ? $this->dentistMessage()
            : $this->teamMessage();
    }
}
