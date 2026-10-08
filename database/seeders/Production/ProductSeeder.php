<?php

namespace Database\Seeders\Production;

use App\Enums\UnitMeasure;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $categoriesWithProducts = [
            'Gessos e Revestimentos' => [
                [
                    'name' => 'Gesso Pedra Tipo IV Amarelo',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 5.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Revestimento para Fundição',
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
            ],
            'Resinas e Acrílicos' => [
                [
                    'name' => 'Resina Acrílica Autopolimerizável Incolor',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido Monômero Autopolimerizável',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 250.00,
                    'has_batches' => true,
                ],
            ],
            'Blocos e Cerâmicas' => [
                [
                    'name' => 'Bloco de Zircônia HT A2',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 3.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Pó de Cerâmica Dente A2',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 50.00,
                    'has_batches' => true,
                ],
            ],
            'Consumíveis Gerais' => [
                [
                    'name' => 'Pino de Troféu com Anel',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
            ],
        ];

        foreach ($categoriesWithProducts as $categoryName => $products) {
            $category = ProductCategory::firstOrCreate([
                'name' => $categoryName,
            ]);

            foreach ($products as $productData) {
                Product::create(
                    array_merge($productData, [
                        'category_id' => $category->id,
                        'current_stock' => 0.00,
                        'is_active' => true,
                    ])
                );
            }
        }
    }
}