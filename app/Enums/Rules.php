<?php
    namespace App\Enums;
    
    enum Rules: string {
        case Owner = 'owner';
        case Client = 'client';
        case ClientManager = 'client_manager';

        public function getName(): string {
            return match($this) {
                self::Owner => 'Proprietário',
                self::Client => 'Cliente',
                self::ClientManager => 'Gerente de Cliente',
            };
        }
    }

?>