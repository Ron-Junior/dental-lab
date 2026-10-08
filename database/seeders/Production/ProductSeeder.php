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
                    'name' => 'Gesso para Moldagem Tipo I',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Gesso Comum Tipo II Branco',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 10.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Gesso Pedra Tipo III Amarelo',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Gesso Pedra Tipo IV Rosa (Alta Dureza)',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 5.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Gesso Pedra Tipo V Verde (Alta Expansão)',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 5.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Revestimento Fosfatado para Fundição de Ligas',
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 5.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido Especial para Revestimento',
                    'unit_of_measure' => UnitMeasure::Liter,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Revestimento para Injeção de Dissilicato de Lítio',
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 3.00,
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
                    'name' => 'Resina Acrílica Autopolimerizável Rosa Veti',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina Acrílica Termopolimerizável Rosa',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 1000.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina Bisacrílica A2 para Provissórios',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido Monômero Autopolimerizável',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 250.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido Monômero Termopolimerizável',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina Foto para Padrão de Fundição (Pattern)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
            ],

            'Blocos, Discos e Cerâmicas (CAD/CAM e Convencional)' => [
                [
                    'name' => 'Bloco de Zircônia HT A2 (14mm)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 3.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Disco de Zircônia Multicamadas A3 (98mm x 18mm)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Pastilha de Dissilicato de Lítio HT A2 (Injeção)',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Disco de PMMA Transparente para Placa Miorrelaxante (98mm)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Disco de PMMA Rosa para Gengiva (98mm)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Pó de Cerâmica Dentine A2',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 50.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Pó de Cerâmica Esmalte E59',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 50.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido para Modelar Cerâmica',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 100.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Kit de Glaze e Maquiagem Cerâmica em Pasta',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
            ],

            'Impressão 3D (Fluxo Digital)' => [
                [
                    'name' => 'Resina 3D para Modelos Odontológicos Bege',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina 3D para Calcináveis/Fundição',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina 3D para Placa Miorrelaxante / Placa de Bruxismo',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Álcool Isopropílico 99.8% (Pós-Processamento 3D)',
                    'unit_of_measure' => UnitMeasure::Liter,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
            ],

            'Ligas Metálicas e Soldas' => [
                [
                    'name' => 'Liga Metálica Ni-Cr para Metalocerâmica',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 200.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Liga Metálica Co-Cr para PPR',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 200.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Solda Prata para Ligas Metálicas',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 20.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Fluxo para Solda Odontológica',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => false,
                ],
            ],

            'Dentes de Estoque (Acrílico e Resina)' => [
                [
                    'name' => 'Dentes Anteriores Superiores (A2 - Trilux / 3 Camadas)',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Dentes Anteriores Inferiores (A2 - Trilux / 3 Camadas)',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Dentes Posteriores Superiores/Inferiores (A3)',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
            ],

            'Ceras e Escultura' => [
                [
                    'name' => 'Cera para Escultura Vermelha',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Cera Utilitária Rosa em Lâminas 7/8',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 3.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Cera Periférica Azul para Moldagem',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 1.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Sprues/Canais de Fundição em Cera (3mm)',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
            ],

            'Silicones, Duplicação e Moldagem' => [
                [
                    'name' => 'Silicone de Condensação Laboratorial (Zetalabor/Pasta)',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Catalisador Indurazid para Silicone de Condensação',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 100.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Silicone de Adição para Duplicação de Modelos (Fluido)',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Gengiva Artificial Flexível para Modelo de Trabalho',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
            ],

            'Componentes de Implante e Análogos' => [
                [
                    'name' => 'Análogo de Implante HE (Haste Externa 3.75/4.0)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Análogo de Implante Morse (CM)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Ucla Calcinável com Base de Cobalto-Cromo (Co-Cr) Morse',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Ti-Base / Coifa de Titânio para Zircônia (CM)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
            ],

            'Acabamento, Polimento e Consumíveis Gerais' => [
                [
                    'name' => 'Pino de Troféu com Anel',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Disco de Corte Dourado para Peça Reta (38mm)',
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Broca de Tungstênio Maxicut Corte Grosso (PM)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Broca de Tungstênio Minicut Corte Fino (PM)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Fresas para Usinagem CAD/CAM (0.6mm / 1.0mm / 2.0mm)',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 3.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Pedra Pomes em Pó para Polimento de Acrílico',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 3.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Pasta para Polimento Universal de Cerâmica e Resina',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Escova de Feltro e Rodete para Polimento',
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Isolante Celofane para Gesso / Acrílico',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 500.00,
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