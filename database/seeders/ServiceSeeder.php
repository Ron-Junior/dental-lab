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
        $digitalMake = ServiceStep::factory([
                'name' => 'Modelagem Digital',
                'description' => 'Modelagem digital da peça.',
        ])->create();

        $digitalDraw = ServiceStep::factory([
                'name' => 'Desenho Digital',
                'description' => 'Modelagem do formatos e contornos anatômicos da peça.',
        ])->create();

        $milling = ServiceStep::factory([ 
                'name' => 'Fresagem',
                'description' => 'Corte da peça em máquina de precisão.',
        ])->create();

        $makeup = ServiceStep::factory([
                'name' => 'Maquiagem e caracterização',
                'description' => 'Pintura da peça para imitar o dente natural.',
        ])->create();

        $glaze = ServiceStep::factory([
                'name' => 'Glaze',
                'description' => 'Queima em forna para sela a superficie e conferir brilho do esmalte dentário',
        ])->create();

        $chemicalTreatment = ServiceStep::factory([
                'name' => 'Tratamento Quimico',
                'description' => 'A parte interna da faceta é jateada ou condicionada com ácido fluorídrico para que ela venha pronta para receber a colagem no consultório',
        ])->create();

        Service::factory()
            ->hasAttached([$digitalMake, $digitalDraw, $milling, $makeup, $glaze, $chemicalTreatment], [
                ['order' => 1],
                ['order' => 2],
                ['order' => 3],
                ['order' => 4],
                ['order' => 5],
                ['order' => 6],
            ])
            ->create([
                'name' => 'Faceta',
            ]);
    }
}