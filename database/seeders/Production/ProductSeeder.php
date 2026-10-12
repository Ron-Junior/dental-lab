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
                    'name' => 'Gesso para Moldagem Tipo I (Herodent)',
                    'anvisa_registration' => '10068870020',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Gesso Comum Tipo II Branco (Troféu - Dentsply)',
                    'anvisa_registration' => '10186370008',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 10.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Gesso Pedra Tipo III Amarelo (Herodent - Vigodent)',
                    'anvisa_registration' => '10068870020',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Gesso Pedra Tipo IV Rosa Durone (Dentsply)',
                    'anvisa_registration' => '10186370008',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 5.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Gesso Pedra Tipo V Verde Fujirock (GC)',
                    'anvisa_registration' => '80025680015',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 5.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Revestimento Fosfatado Bellavest SH (BEGO)',
                    'anvisa_registration' => '80025680018',
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 5.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido Especial BegoSol para Revestimento',
                    'anvisa_registration' => '80025680018',
                    'unit_of_measure' => UnitMeasure::Liter,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Revestimento IPS PressVEST Premium (Ivoclar)',
                    'anvisa_registration' => '80025680070',
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 3.00,
                    'has_batches' => true,
                ],
            ],

            'Resinas e Acrílicos' => [
                [
                    'name' => 'Resina Acrílica Autopolimerizável Incolor (Clássica)',
                    'anvisa_registration' => '10217210015',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina Acrílica Autopolimerizável Rosa Vipi Flash',
                    'anvisa_registration' => '10216000012',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina Acrílica Termopolimerizável Vipi Wave Rosa',
                    'anvisa_registration' => '10216000009',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 1000.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina Bisacrílica Systemp C&B A2 (Ivoclar)',
                    'anvisa_registration' => '80025680070',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido Monômero Autopolimerizável Jet (Clássica)',
                    'anvisa_registration' => '10217210015',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 250.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido Monômero Termopolimerizável Vipi Wave',
                    'anvisa_registration' => '10216000009',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina Foto Calcinável Pattern Resin LS (GC)',
                    'anvisa_registration' => '80025680015',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
            ],

            'Blocos, Discos e Cerâmicas (CAD/CAM e Convencional)' => [
                [
                    'name' => 'Bloco de Zircônia Ceramill Zolid HT A2 (Amann Girrbach)',
                    'anvisa_registration' => '80025680012',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 3.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Disco de Zircônia Katana Multicamadas A3 (Kuraray Noritake)',
                    'anvisa_registration' => '80117580760',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Pastilha Dissilicato de Lítio IPS e.max Press HT A2 (Ivoclar)',
                    'anvisa_registration' => '80025680070',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Disco de PMMA Transparente Telio CAD (Ivoclar)',
                    'anvisa_registration' => '80025680070',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Disco de PMMA Pink Gengiva Vipi Block',
                    'anvisa_registration' => '10216000009',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Pó de Cerâmica IPS Style Dentine A2 (Ivoclar)',
                    'anvisa_registration' => '80025680070',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 50.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Pó de Cerâmica Ceramco 3 Esmalte E59 (Dentsply)',
                    'anvisa_registration' => '10186370008',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 50.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Líquido para Modelar Cerâmica IPS Build-Up Liquid',
                    'anvisa_registration' => '80025680070',
                    'unit_of_measure' => UnitMeasure::Milliliter,
                    'min_stock' => 100.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Kit Glaze e Maquiagem IPS Ivocolor Shade Paste (Ivoclar)',
                    'anvisa_registration' => '80025680070',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
            ],

            'Impressão 3D (Fluxo Digital)' => [
                [
                    'name' => 'Resina 3D PriZma Model Beige (Smart Print)',
                    'anvisa_registration' => '80025680080',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina 3D PriZma Cast Calcinável',
                    'anvisa_registration' => '80025680080',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 500.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Resina 3D Cosmos Splint para Placa Miorrelaxante (Yller)',
                    'anvisa_registration' => '80025680088',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Álcool Isopropílico 99.8% (Pós-Processamento 3D)',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Liter,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
            ],

            'Ligas Metálicas e Soldas' => [
                [
                    'name' => 'Liga Metálica Talladium V (Ni-Cr para Metalocerâmica)',
                    'anvisa_registration' => '80025680018',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 200.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Liga Metálica Wironium plus (Co-Cr para PPR - BEGO)',
                    'anvisa_registration' => '80025680018',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 200.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Solda Prata Degussa para Ligas Odontológicas',
                    'anvisa_registration' => '80025680018',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 20.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Fluxo para Solda Odontológica Pasta Bego',
                    'anvisa_registration' => null,
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => false,
                ],
            ],

            'Dentes de Estoque (Acrílico e Resina)' => [
                [
                    'name' => 'Dentes Anteriores Superiores Trilux A2 (VIPI)',
                    'anvisa_registration' => '10216000005',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Dentes Anteriores Inferiores Trilux A2 (VIPI)',
                    'anvisa_registration' => '10216000005',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Dentes Posteriores Biotone A3 (Dentsply)',
                    'anvisa_registration' => '10186370008',
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 10.00,
                    'has_batches' => true,
                ],
            ],

            'Ceras e Escultura' => [
                [
                    'name' => 'Cera para Escultura GEO Classic Vermelha (Renfert)',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Cera Utilitária Rosa em Lâminas 7/8 (Horico/Polidental)',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 3.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Sprues/Canais de Fundição em Cera GEO (Renfert)',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
            ],

            'Silicones, Duplicação e Moldagem' => [
                [
                    'name' => 'Silicone de Condensação Zetalabor Pasta (Zhermack)',
                    'anvisa_registration' => '80025680025',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 2.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Catalisador Indurazid Gel (Zhermack)',
                    'anvisa_registration' => '80025680025',
                    'unit_of_measure' => UnitMeasure::Gram,
                    'min_stock' => 100.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Silicone de Adição para Duplicação Elite Double (Zhermack)',
                    'anvisa_registration' => '80025680025',
                    'unit_of_measure' => UnitMeasure::Kilogram,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
                [
                    'name' => 'Gengiva Artificial Flexível Gingifast Rigid (Zhermack)',
                    'anvisa_registration' => '80025680025',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => true,
                ],
            ],

            'Componentes de Implante e Análogos' => [
                [
                    'name' => 'Análogo de Implante HE 3.75/4.0 (Neodent)',
                    'anvisa_registration' => '10344420010',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Análogo de Implante Cone Morse CM (Neodent)',
                    'anvisa_registration' => '10344420010',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Ucla Calcinável com Base de Co-Cr Cone Morse (Neodent)',
                    'anvisa_registration' => '10344420010',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Ti-Base Titânio para Zircônia Cone Morse (Neodent)',
                    'anvisa_registration' => '10344420010',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
            ],

            'Acabamento, Polimento e Consumíveis Gerais' => [
                [
                    'name' => 'Pino de Troféu com Anel (Polidental)',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Box,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Disco de Corte Dourado para Peça Reta (Dentsply)',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Package,
                    'min_stock' => 5.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Broca de Tungstênio Maxicut Corte Grosso PM (Edenta)',
                    'anvisa_registration' => '80025680030',
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 2.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Fresa de Usinagem CAD/CAM Amann Girrbach 1.0mm',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 3.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Pasta para Polimento Universal Acrypol (Dentsply)',
                    'anvisa_registration' => null, // Isento
                    'unit_of_measure' => UnitMeasure::Unit,
                    'min_stock' => 1.00,
                    'has_batches' => false,
                ],
                [
                    'name' => 'Isolante Celofane Cel-Lac para Gesso e Acrílico (SS White)',
                    'anvisa_registration' => null, // Isento
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
