<?php

namespace Database\Seeders;

use App\Enums\Rules;
use App\Models\Dentist;
use App\Models\DentistRequest;
use App\Models\RequestService;
use App\Models\Rule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class DentistRequestSeeder extends Seeder
{

    public function run(): void
    {
        Model::setEventDispatcher(app('events'));
        $dentistRule = Rule::firstOrCreate(['name' => Rules::Dentist->value]);

        $user = User::factory()
            ->for($dentistRule)
            ->create([
                'name' => 'Dentista João',
                'email' => 'joao@dentista.com',
            ]);

        $dentist = Dentist::factory()
            ->for($user)
            ->create();

        $serviceOne = Service::has('serviceSteps')->inRandomOrder()->first();
        $serviceTwo = Service::has('serviceSteps')->inRandomOrder()->first();
        DentistRequest::factory()
            ->for($dentist)
            ->afterCreating(function (DentistRequest $dentistRequest) use ($serviceOne) {
                // Ao chamar create() aqui, GARANTIMOS que o booted() do RequestService vai rodar
                RequestService::create([
                    'dentist_request_id' => $dentistRequest->id,
                    'service_id'         => $serviceOne->id,
                    'unit_price'         => $serviceOne->price,
                    'quantity'           => fake()->numberBetween(1, 10),
                ]);
            })
            ->create();

        // Cria a Segunda Requisição e os Serviços dentro do afterCreating
        DentistRequest::factory()
            ->for($dentist)
            ->afterCreating(function (DentistRequest $dentistRequest) use ($serviceTwo) {
                RequestService::create([
                    'dentist_request_id' => $dentistRequest->id,
                    'service_id'         => $serviceTwo->id,
                    'unit_price'         => $serviceTwo->price,
                    'quantity'           => fake()->numberBetween(1, 10),
                ]);
            })
            ->create();
    }
}
