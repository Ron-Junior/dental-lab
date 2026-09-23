<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceStep;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Service::factory()
            ->withSteps([
                [
                    'name' => 'Modelagem Digital',
                    'description' => 'Modelagem digital da peça.',
                ],
                [
                    'name' => 'Desenho Digital',
                    'description' => 'Modelagem do formatos e contornos anatômicos da peça.',
                ],
                [
                    'name' => 'Fresagem',
                    'description' => 'Corte da peça em máquina de precisão.',
                ],
                [
                    'name' => 'Maquiagem e caracterização',
                    'description' => 'Pintura da peça para imitar o dente natural.',
                ],
                [
                    'name' => 'Glaze',
                    'description' => 'Queima em forna para sela a superficie e conferir brilho do esmalte dentário',
                ],
                [
                    'name' => 'Tratamento Quimico',
                    'description' => 'A parte interna da faceta é jateada ou condicionada com ácido fluorídrico para que ela venha pronta para receber a colagem no consultório',
                ]
            ])
            ->create([
                'name' => 'Faceta',
            ]);

        Service::factory()
            ->withSteps([
                [
                    'Modelagem digital'
                ]
            ])
            ->create([
                'name' => 'Coroa',
            ]);
    }
}