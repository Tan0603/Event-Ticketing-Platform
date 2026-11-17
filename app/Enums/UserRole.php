<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case DoorStaff = 'door_staff';
}
