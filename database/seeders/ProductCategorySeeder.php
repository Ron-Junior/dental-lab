<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCategorySeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $categories = [
            [
                'name' => 'Gessos e Revestimentos',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Resinas, Acrílicos e Monômeros',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Cerâmicas e Porcelanas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Blocos e Discos (CAD/CAM e 3D)',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Dentes de Estoque',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Metais e Ligas',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Ceras e Isolantes',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Brocas, Discos e Abrasivos',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('product_categories')->insertOrIgnore($categories);
    }
}
