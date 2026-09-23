<?php
    namespace App\Enums;
    
    enum Rules: string {
        case Owner = 'owner';
        case Lab = 'lab';
        case LabManager = 'lab_manager';
        case LabPartner = 'lab_partner';
        case Dentist = 'dentist';

        public function getName(): string {
            return match($this) {
                self::Owner => 'Proprietário',
                self::Lab => 'Laboratório',
                self::LabManager => 'Gerente de Laboratório',
            };
        }
    }

?>