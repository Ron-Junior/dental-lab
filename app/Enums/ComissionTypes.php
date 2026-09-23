<?php
    namespace App\Enums;
    
    enum ComissionTypes: string {
        case Percentage = 'percentage';
        case Fixed = 'fixed';

        public function getName(): string {
            return match($this) {
                self::Percentage => 'Porcentagem (%)',
                self::Fixed => 'Valor Fixo',
            };
        }
    }

?>